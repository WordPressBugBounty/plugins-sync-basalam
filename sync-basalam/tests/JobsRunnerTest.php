<?php

namespace SyncBasalam\Tests;

use PHPUnit\Framework\TestCase;
use SyncBasalam\JobsRunner;

class JobsRunnerTest extends TestCase
{
    private const ASYNC_ACTION = 'sync_basalam_run_jobs_async';
    private const DISPATCH_LOCK = 'sync_basalam_jobs_runner_async_dispatch_lock';

    private $originalRequest;
    private $originalCookie;

    protected function setUp(): void
    {
        $this->originalRequest = $_REQUEST;
        $this->originalCookie = $_COOKIE;

        $_REQUEST = [];
        $_COOKIE = [];

        $GLOBALS['sync_basalam_jobs_runner_test_state'] = [
            'actions' => [],
            'did_actions' => [],
            'doing_ajax' => false,
            'transients' => [],
            'transient_reads' => [],
            'transient_writes' => [],
            'filter_values' => [],
            'events' => [],
            'remote_requests' => [],
        ];
    }

    protected function tearDown(): void
    {
        $_REQUEST = $this->originalRequest;
        $_COOKIE = $this->originalCookie;

        unset($GLOBALS['sync_basalam_jobs_runner_test_state']);
    }

    public function testRegistersDispatcherOnInitAndNeverOnShutdown(): void
    {
        $runner = $this->newRunner();
        $actions = $this->state()['actions'];

        self::assertArrayHasKey('init', $actions);
        self::assertSame([$runner, 'maybeDispatchAsyncRequest'], $actions['init'][0]['callback']);
        self::assertSame(10, $actions['init'][0]['priority']);
        self::assertSame(0, $actions['init'][0]['accepted_args']);
        self::assertArrayNotHasKey('shutdown', $actions);

        self::assertSame(
            [$runner, 'maybeDispatchAsyncRequest'],
            $actions['sync_basalam_job_created'][0]['callback']
        );
        self::assertSame(0, $actions['sync_basalam_job_created'][0]['accepted_args']);
        self::assertSame([$runner, 'handleAsyncRequest'], $actions['wp_ajax_' . self::ASYNC_ACTION][0]['callback']);
        self::assertSame(
            [$runner, 'handleAsyncRequest'],
            $actions['wp_ajax_nopriv_' . self::ASYNC_ACTION][0]['callback']
        );
    }

    public function testEligibleQueueIsConfirmedBeforeWritingLockAndDispatching(): void
    {
        $jobManager = new FakeJobManager([true]);
        $runner = $this->newRunner($jobManager);

        $_COOKIE = ['wordpress_test_cookie' => 'cookie-value'];
        $runner->maybeDispatchAsyncRequest();

        self::assertSame(
            ['get_transient', 'has_pending_jobs', 'set_transient', 'remote_post'],
            $this->state()['events']
        );
        self::assertSame([120], $jobManager->timeouts);
        self::assertSame(
            [[
                'name' => self::DISPATCH_LOCK,
                'value' => 1,
                'expiration' => 25,
            ]],
            $this->state()['transient_writes']
        );

        $requests = $this->state()['remote_requests'];
        self::assertCount(1, $requests);
        self::assertSame(
            'https://example.test/wp-admin/admin-ajax.php?action=' . self::ASYNC_ACTION,
            $requests[0]['url']
        );
        self::assertSame(0.01, $requests[0]['args']['timeout']);
        self::assertFalse($requests[0]['args']['blocking']);
        self::assertSame(self::ASYNC_ACTION, $requests[0]['args']['body']['action']);
        self::assertSame('test-nonce-for-' . self::ASYNC_ACTION, $requests[0]['args']['body']['nonce']);
        self::assertSame($_COOKIE, $requests[0]['args']['cookies']);
    }

    public function testEmptyQueueDoesNotWriteLockOrDispatch(): void
    {
        $jobManager = new FakeJobManager([false]);
        $runner = $this->newRunner($jobManager);

        $runner->maybeDispatchAsyncRequest();

        self::assertSame(['get_transient', 'has_pending_jobs'], $this->state()['events']);
        self::assertSame([120], $jobManager->timeouts);
        self::assertSame([], $this->state()['transient_writes']);
        self::assertSame([], $this->state()['remote_requests']);
    }

    public function testJobCreatedAfterAnEmptyInitCanStillDispatch(): void
    {
        $jobManager = new FakeJobManager([false, true]);
        $runner = $this->newRunner($jobManager);

        // The init probe sees no work and must not reserve the dispatch lock.
        $runner->maybeDispatchAsyncRequest();
        self::assertSame([], $this->state()['transient_writes']);

        // A job created later in the same request must still wake the runner.
        $runner->maybeDispatchAsyncRequest();

        self::assertSame([120, 120], $jobManager->timeouts);
        self::assertCount(1, $this->state()['transient_writes']);
        self::assertCount(1, $this->state()['remote_requests']);
    }

    public function testExistingLockSkipsQueueCheckAndDispatch(): void
    {
        $GLOBALS['sync_basalam_jobs_runner_test_state']['transients'][self::DISPATCH_LOCK] = 1;
        $jobManager = new FakeJobManager([true]);
        $runner = $this->newRunner($jobManager);

        $runner->maybeDispatchAsyncRequest();

        self::assertSame(['get_transient'], $this->state()['events']);
        self::assertSame([], $jobManager->timeouts);
        self::assertSame([], $this->state()['transient_writes']);
        self::assertSame([], $this->state()['remote_requests']);
    }

    public function testSelfAsyncRequestReturnsBeforeQueueAndLockChecks(): void
    {
        $GLOBALS['sync_basalam_jobs_runner_test_state']['doing_ajax'] = true;
        $_REQUEST['action'] = self::ASYNC_ACTION;

        $jobManager = new FakeJobManager([true]);
        $httpBlockService = new FakeHttpBlockService(false);
        $runner = $this->newRunner($jobManager, $httpBlockService);

        $runner->maybeDispatchAsyncRequest();

        self::assertSame(0, $httpBlockService->calls);
        $this->assertNoQueueLockOrDispatchActivity($jobManager);
    }

    public function testHttpBlockReturnsBeforeQueueAndLockChecks(): void
    {
        $jobManager = new FakeJobManager([true]);
        $httpBlockService = new FakeHttpBlockService(true);
        $runner = $this->newRunner($jobManager, $httpBlockService);

        $runner->maybeDispatchAsyncRequest();

        self::assertSame(1, $httpBlockService->calls);
        $this->assertNoQueueLockOrDispatchActivity($jobManager);
    }

    public function testShutdownGuardPreventsEveryDispatchSideEffect(): void
    {
        $GLOBALS['sync_basalam_jobs_runner_test_state']['did_actions']['shutdown'] = 1;

        $jobManager = new FakeJobManager([true]);
        $httpBlockService = new FakeHttpBlockService(false);
        $runner = $this->newRunner($jobManager, $httpBlockService);

        $runner->maybeDispatchAsyncRequest();

        self::assertSame(0, $httpBlockService->calls);
        $this->assertNoQueueLockOrDispatchActivity($jobManager);
    }

    public function testAsyncBatchExitsImmediatelyWhenAnotherRunnerOwnsTheGlobalLock(): void
    {
        $jobManager = new FakeJobManager([true]);
        $jobExecutor = new FakeJobExecutor(false);
        $runner = $this->newRunner($jobManager, null, $jobExecutor);

        self::assertSame(0, $this->invokeRunAsyncBatch($runner));
        self::assertSame(1, $jobExecutor->acquireCalls);
        self::assertSame(0, $jobExecutor->releaseCalls);
        self::assertSame([], $jobManager->timeouts);
    }

    public function testAsyncBatchReleasesTheGlobalLockWhenTheQueueIsEmpty(): void
    {
        $jobManager = new FakeJobManager([false]);
        $jobExecutor = new FakeJobExecutor(true);
        $runner = $this->newRunner($jobManager, null, $jobExecutor);

        self::assertSame(0, $this->invokeRunAsyncBatch($runner));
        self::assertSame(1, $jobExecutor->acquireCalls);
        self::assertSame(1, $jobExecutor->releaseCalls);
        self::assertSame([120], $jobManager->timeouts);
    }

    private function newRunner(
        ?FakeJobManager $jobManager = null,
        ?FakeHttpBlockService $httpBlockService = null,
        $jobExecutor = null
    ): JobsRunner {
        return new JobsRunner(
            $jobManager ?? new FakeJobManager(),
            $jobExecutor ?? new FakeJobExecutor(),
            new \stdClass(),
            $httpBlockService ?? new FakeHttpBlockService(false)
        );
    }

    private function invokeRunAsyncBatch(JobsRunner $runner): int
    {
        $method = new \ReflectionMethod($runner, 'runAsyncBatch');
        $method->setAccessible(true);

        return $method->invoke($runner);
    }

    private function assertNoQueueLockOrDispatchActivity(FakeJobManager $jobManager): void
    {
        self::assertSame([], $jobManager->timeouts);
        self::assertSame([], $this->state()['transient_reads']);
        self::assertSame([], $this->state()['transient_writes']);
        self::assertSame([], $this->state()['remote_requests']);
    }

    private function state(): array
    {
        return $GLOBALS['sync_basalam_jobs_runner_test_state'];
    }
}

class FakeJobManager
{
    public $timeouts = [];

    private $pendingResults;

    public function __construct(array $pendingResults = [])
    {
        $this->pendingResults = $pendingResults;
    }

    public function hasPendingOrStaleProcessingJobs(int $timeout): bool
    {
        $GLOBALS['sync_basalam_jobs_runner_test_state']['events'][] = 'has_pending_jobs';
        $this->timeouts[] = $timeout;

        return (bool) array_shift($this->pendingResults);
    }
}

class FakeHttpBlockService
{
    public $calls = 0;

    private $blocked;

    public function __construct(bool $blocked)
    {
        $this->blocked = $blocked;
    }

    public function SyncBasalamHttpBlock()
    {
        $this->calls++;

        return $this->blocked;
    }
}

class FakeJobExecutor
{
    public $acquireCalls = 0;
    public $releaseCalls = 0;

    private $canAcquire;

    public function __construct(bool $canAcquire = true)
    {
        $this->canAcquire = $canAcquire;
    }

    public function acquireGlobalJobsLock(int $timeout = 0): bool
    {
        $this->acquireCalls++;

        return $this->canAcquire;
    }

    public function releaseGlobalJobsLock(): bool
    {
        $this->releaseCalls++;

        return true;
    }
}
