<?php

namespace SyncBasalam\Tests;

use PHPUnit\Framework\TestCase;
use SyncBasalam\Services\AsyncWorkerHealth;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AsyncWorkerServerDetectionTest extends TestCase
{
    protected function setUp(): void
    {
        // Exercise the real detector with the SAPI reported by Apache/LSAPI hosts.
        define('SyncBasalam\\Services\\PHP_SAPI', 'litespeed');
        $GLOBALS['sync_basalam_test_options'] = [];
        foreach (['noabort', 'REDIRECT_noabort', 'SYNCBASALAM_LITESPEED', 'REDIRECT_SYNCBASALAM_LITESPEED', 'SYNCBASALAM_NOT_LITESPEED', 'REDIRECT_SYNCBASALAM_NOT_LITESPEED'] as $name) {
            unset($_SERVER[$name]);
            putenv($name);
        }
        $_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed';
        putenv('SERVER_SOFTWARE');
    }

    /** @dataProvider serverDetectionSources */
    public function testApacheMarkerClearsTheFalseWarning(string $source, string $name): void
    {
        $health = new AsyncWorkerHealth();
        $health->observeAsyncEnvironment();
        self::assertSame('notice-warning', $health->getNotice()['class']);

        if ($source === 'server') $_SERVER[$name] = '1';
        else putenv($name . '=1');

        $apacheRequest = new AsyncWorkerHealth();
        $apacheRequest->observeAsyncEnvironment();
        self::assertNull($apacheRequest->getNotice());
        self::assertFalse(get_option(AsyncWorkerHealth::OPTION_KEY)['protection_missing']);

        $apacheRequest->recordInterruptedJob((object) ['id' => 12, 'started_at' => time() - 121]);
        self::assertSame('notice-error', $apacheRequest->getNotice()['class']);
        self::assertStringNotContainsString('noabort', $apacheRequest->getNotice()['guidance']);
        self::assertFalse(get_option(AsyncWorkerHealth::OPTION_KEY)['litespeed']);
    }

    public function serverDetectionSources(): array
    {
        return [
            ['server', 'SYNCBASALAM_NOT_LITESPEED'],
            ['server', 'REDIRECT_SYNCBASALAM_NOT_LITESPEED'],
            ['env', 'SYNCBASALAM_NOT_LITESPEED'],
            ['env', 'REDIRECT_SYNCBASALAM_NOT_LITESPEED'],
        ];
    }

    /** @dataProvider invalidMarkerValues */
    public function testOtherMarkerValuesDoNotDisableLiteSpeedChecks(string $value): void
    {
        foreach (['SYNCBASALAM_NOT_LITESPEED', 'REDIRECT_SYNCBASALAM_NOT_LITESPEED'] as $name) {
            $_SERVER[$name] = $value;
            putenv($name . '=' . $value);
        }
        $health = new AsyncWorkerHealth();
        $health->observeAsyncEnvironment();
        self::assertSame('notice-warning', $health->getNotice()['class']);
    }

    public function invalidMarkerValues(): array
    {
        return [[''], ['0'], ['true'], ['01']];
    }

    public function testLiteSpeedStillRequiresProtectionWhenTheMarkerIsAbsent(): void
    {
        $health = new AsyncWorkerHealth();
        $health->observeAsyncEnvironment();
        $health->recordJobReturned((object) ['id' => 13], 0.5);
        self::assertSame('notice-warning', $health->getNotice()['class']);

        $_SERVER['noabort'] = '1';
        $protectedRequest = new AsyncWorkerHealth();
        $protectedRequest->observeAsyncEnvironment();
        self::assertNull($protectedRequest->getNotice());
        $protectedRequest->recordJobReturned((object) ['id' => 14], 1.0);
        self::assertTrue(get_option(AsyncWorkerHealth::OPTION_KEY)['last_returned_protected']);
    }

    public function testApacheServerSignatureOverridesLsapiSapiWithoutMarker(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.62 (Unix)';

        $health = new AsyncWorkerHealth();
        $health->observeAsyncEnvironment();

        self::assertNull($health->getNotice());
        self::assertFalse(get_option(AsyncWorkerHealth::OPTION_KEY)['protection_missing']);

        $job = (object) ['id' => 41, 'started_at' => time() - 121];
        $health->recordInterruptedJob($job);
        self::assertSame('notice-error', $health->getNotice()['class']);
        $health->recordJobReturned($job, 0.4);
        self::assertNull($health->getNotice());
    }

    public function testLiteSpeedServerSignatureStillUsesNoabortCheck(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed';

        $health = new AsyncWorkerHealth();
        $health->observeAsyncEnvironment();

        self::assertSame('notice-warning', $health->getNotice()['class']);
    }

    public function testLsapiAloneIsUnknownAndDoesNotCreateAFalseLiteSpeedDiagnosis(): void
    {
        unset($_SERVER['SERVER_SOFTWARE']);
        self::assertNull((new \SyncBasalam\Services\AsyncWorkerEnvironment())->isLiteSpeed());
        self::assertTrue((new \SyncBasalam\Services\AsyncWorkerEnvironment())->shouldInstallProbe());
        $health = new AsyncWorkerHealth();
        $health->observeAsyncEnvironment();
        self::assertNull($health->getNotice());
        $health->recordJobReturned((object) ['id' => 42], 0.1);
        self::assertNull(get_option(AsyncWorkerHealth::OPTION_KEY)['last_returned_protected']);
    }

    /** @dataProvider otherServerSignatures */
    public function testOtherServersUsingLsapiAreNotLiteSpeed(string $signature): void
    {
        $_SERVER['SERVER_SOFTWARE'] = $signature;
        self::assertFalse((new \SyncBasalam\Services\AsyncWorkerEnvironment())->isLiteSpeed());
    }

    public function otherServerSignatures(): array
    {
        return [['Apache/2.4.62'], ['nginx/1.29.1'], ['Caddy'], ['Microsoft-IIS/10.0']];
    }

    public function testActualModuleMarkerCanResolveAMaskedServerSignature(): void
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache';
        $_SERVER['REDIRECT_SYNCBASALAM_LITESPEED'] = '1';
        self::assertTrue((new \SyncBasalam\Services\AsyncWorkerEnvironment())->isLiteSpeed());
    }

    public function testConflictingModuleMarkersRemainUnknown(): void
    {
        $_SERVER['SYNCBASALAM_LITESPEED'] = '1';
        $_SERVER['SYNCBASALAM_NOT_LITESPEED'] = '1';
        self::assertNull((new \SyncBasalam\Services\AsyncWorkerEnvironment())->isLiteSpeed());
    }

    public function testClientHeadersCannotSupplyServerEvidence(): void
    {
        unset($_SERVER['SERVER_SOFTWARE']);
        $_SERVER['HTTP_SERVER_SOFTWARE'] = 'LiteSpeed';
        $_SERVER['HTTP_SYNCBASALAM_LITESPEED'] = '1';
        self::assertNull((new \SyncBasalam\Services\AsyncWorkerEnvironment())->isLiteSpeed());
    }
}
