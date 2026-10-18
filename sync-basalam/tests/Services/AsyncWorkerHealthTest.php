<?php

namespace SyncBasalam\Tests;

use PHPUnit\Framework\TestCase;
use SyncBasalam\Services\AsyncWorkerHealth;

class AsyncWorkerHealthTest extends TestCase
{
    private $options;
    private $server;
    private $environment;

    protected function setUp(): void
    {
        $this->options = $GLOBALS['sync_basalam_test_options'] ?? [];
        $this->server = $_SERVER;
        $this->environment = [getenv('noabort'), getenv('REDIRECT_noabort')];
        $GLOBALS['sync_basalam_test_options'] = [];
        unset($_SERVER['noabort'], $_SERVER['REDIRECT_noabort']);
        putenv('noabort');
        putenv('REDIRECT_noabort');
    }

    protected function tearDown(): void
    {
        $GLOBALS['sync_basalam_test_options'] = $this->options;
        $_SERVER = $this->server;
        foreach (['noabort', 'REDIRECT_noabort'] as $index => $name) {
            $value = $this->environment[$index];
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    public function testOtherPhpRuntimesDoNotGetALiteSpeedWarning(): void
    {
        $health = new RuntimeWorkerHealth(false);
        $health->observeAsyncEnvironment();
        self::assertNull($health->getNotice());
    }

    public function testMissingProtectionPersistsAcrossRequestsAndFastReturns(): void
    {
        $health = new RuntimeWorkerHealth(true);
        $health->observeAsyncEnvironment();
        $health->recordJobReturned((object) ['id' => 10], 0.5);
        $notice = (new RuntimeWorkerHealth(true))->getNotice();
        self::assertSame('notice-warning', $notice['class']);
        self::assertStringContainsString('LiteSpeed Cache', $notice['guidance']);
    }

    /** @dataProvider protectionSources */
    public function testEffectiveProtectionIsRecognized(string $source, string $name): void
    {
        if ($source === 'server') $_SERVER[$name] = '1';
        else putenv($name . '=1');
        $health = new RuntimeWorkerHealth(true);
        $health->observeAsyncEnvironment();
        self::assertNull($health->getNotice());
    }

    public function protectionSources(): array
    {
        return [['server', 'noabort'], ['server', 'REDIRECT_noabort'], ['env', 'noabort'], ['env', 'REDIRECT_noabort']];
    }

    public function testZeroDoesNotCountAsProtection(): void
    {
        $_SERVER['noabort'] = '0';
        $health = new RuntimeWorkerHealth(true);
        $health->observeAsyncEnvironment();
        self::assertNotNull($health->getNotice());
    }

    public function testStaleInterruptionPersistsUntilAProtectedJobActuallyReturns(): void
    {
        $health = new RuntimeWorkerHealth(true);
        $health->observeAsyncEnvironment();
        $job = (object) ['id' => 23, 'started_at' => time() - 121, 'payload' => 'private'];
        $health->recordInterruptedJob($job);
        self::assertSame('notice-error', $health->getNotice()['class']);
        $health->recordJobReturned($job, 0.1);
        self::assertSame('notice-error', $health->getNotice()['class']);

        $_SERVER['noabort'] = '1';
        $nextRequest = new RuntimeWorkerHealth(true);
        $nextRequest->observeAsyncEnvironment();
        self::assertNotNull($nextRequest->getNotice(), 'Empty or contended batches must not clear interruption evidence.');
        $nextRequest->recordJobReturned($job, 7.5);
        self::assertNull($nextRequest->getNotice());
        $state = get_option(AsyncWorkerHealth::OPTION_KEY);
        self::assertSame(23, $state['last_returned_job_id']);
        self::assertArrayNotHasKey('payload', $state);
    }

    public function testNonLiteSpeedInterruptionGivesGenericHostingAdvice(): void
    {
        $health = new RuntimeWorkerHealth(false);
        $health->recordInterruptedJob((object) ['id' => 1, 'started_at' => time() - 121]);
        self::assertStringNotContainsString('noabort', $health->getNotice()['guidance']);
        $health->observeAsyncEnvironment();
        $health->recordJobReturned((object) ['id' => 2], 1.0);
        self::assertNull($health->getNotice());
    }

    public function testExplicitCancellationClearsInterruption(): void
    {
        $health = new RuntimeWorkerHealth(false);
        $health->recordInterruptedJob((object) ['id' => 24, 'started_at' => time() - 121]);
        self::assertNotNull($health->getNotice());

        $health->clearInterruption();

        self::assertNull($health->getNotice());
    }

    public function testCorruptOptionDoesNotProduceAnAlert(): void
    {
        update_option(AsyncWorkerHealth::OPTION_KEY, 'invalid');
        self::assertNull((new RuntimeWorkerHealth(false))->getNotice());
    }
}

class RuntimeWorkerHealth extends AsyncWorkerHealth
{
    private bool $liteSpeed;

    public function __construct(bool $liteSpeed) { $this->liteSpeed = $liteSpeed; }
    protected function isLiteSpeed(): bool { return $this->liteSpeed; }
}
