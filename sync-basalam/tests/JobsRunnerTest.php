<?php

namespace SyncBasalam\Tests;

use PHPUnit\Framework\TestCase;
use SyncBasalam\JobsRunner;

class JobsRunnerTest extends TestCase
{
    private const ASYNC_ACTION = 'sync_basalam_run_jobs_async';
    private const DISPATCH_LOCK = 'sync_basalam_jobs_runner_async_dispatch_lock';
    private const IDLE_PROBE_LOCK = 'sync_basalam_jobs_runner_idle_probe_lock';
    private const RECOVERY_HOOK = 'sync_basalam_jobs_runner_recovery';

    private $originalRequest;
    private $originalCookie;
    private $originalWpdb;
    private $hadOriginalWpdb;
    private $originalOptions;
    private $hadOriginalOptions;

    protected function setUp(): void
    {
        $this->originalRequest = $_REQUEST;
        $this->originalCookie = $_COOKIE;
        $this->hadOriginalWpdb = array_key_exists('wpdb', $GLOBALS);
        $this->originalWpdb = $GLOBALS['wpdb'] ?? null;
        $this->hadOriginalOptions = array_key_exists('sync_basalam_test_options', $GLOBALS);
        $this->originalOptions = $GLOBALS['sync_basalam_test_options'] ?? null;

        $_REQUEST = [];
        $_COOKIE = [];
        $GLOBALS['sync_basalam_test_options'] = [];

        $GLOBALS['sync_basalam_jobs_runner_test_state'] = [
            'actions' => [],
            'did_actions' => [],
            'current_filter' => '',
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

        if ($this->hadOriginalWpdb) {
            $GLOBALS['wpdb'] = $this->originalWpdb;
        } else {
            unset($GLOBALS['wpdb']);
        }

        unset($GLOBALS['sync_basalam_jobs_runner_test_state']);
        if ($this->hadOriginalOptions) {
            $GLOBALS['sync_basalam_test_options'] = $this->originalOptions;
        } else {
            unset($GLOBALS['sync_basalam_test_options']);
        }
    }

    public function testRegistersDispatcherOnShutdownAndNeverOnInit(): void
    {
        $runner = $this->newRunner();
        $actions = $this->state()['actions'];

        self::assertArrayHasKey('shutdown', $actions);
        self::assertSame([$runner, 'maybeDispatchAsyncRequest'], $actions['shutdown'][0]['callback']);
        self::assertSame(2, $actions['shutdown'][0]['priority']);
        self::assertSame(1, $actions['shutdown'][0]['accepted_args']);
        self::assertArrayNotHasKey('init', $actions);
        self::assertSame([$runner, 'recoverAsyncQueue'], $actions[self::RECOVERY_HOOK][0]['callback']);
        self::assertArrayNotHasKey('admin_notices', $actions);
        self::assertSame([$runner, 'inspectWorkerHealth'], $actions['current_screen'][0]['callback']);

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

    public function testDispatchLeaseIsWrittenOnlyWhenQueueHasWork(): void
    {
        $jobManager = new FakeJobManager([true]);
        $runner = $this->newRunner($jobManager);

        $_COOKIE = ['wordpress_test_cookie' => 'cookie-value'];
        $before = time();
        $runner->maybeDispatchAsyncRequest();

        self::assertSame(
            ['get_transient', 'get_transient', 'has_pending_jobs', 'get_transient', 'set_transient', 'remote_post'],
            $this->state()['events']
        );
        self::assertSame([120], $jobManager->timeouts);
        $writes = $this->state()['transient_writes'];
        self::assertCount(1, $writes);
        self::assertSame(self::DISPATCH_LOCK, $writes[0]['name']);
        self::assertSame(25, $writes[0]['expiration']);
        self::assertGreaterThanOrEqual($before + 25, $writes[0]['value']);
        self::assertLessThanOrEqual(time() + 25, $writes[0]['value']);

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

    public function testShutdownRepairsDatabaseWithoutFinishingFastCgiResponse(): void
    {
        $GLOBALS['sync_basalam_jobs_runner_test_state']['current_filter'] = 'shutdown';
        $GLOBALS['wpdb'] = new FakeWpdb();

        $jobManager = new FakeJobManager([false]);
        $runner = $this->newRunner($jobManager);

        $runner->maybeDispatchAsyncRequest();

        self::assertSame(
            [
                'db_flush',
                'db_check_connection',
                'get_transient',
                'get_transient',
                'has_pending_jobs',
                'set_transient',
            ],
            $this->state()['events']
        );
        self::assertSame([false], $GLOBALS['wpdb']->allowBailValues);
    }

    public function testEmptyQueueReservesOnlyShortIdleProbeLease(): void
    {
        $jobManager = new FakeJobManager([false]);
        $runner = $this->newRunner($jobManager);

        $before = time();
        $runner->maybeDispatchAsyncRequest();

        self::assertSame(['get_transient', 'get_transient', 'has_pending_jobs', 'set_transient'], $this->state()['events']);
        self::assertSame([120], $jobManager->timeouts);
        $writes = $this->state()['transient_writes'];
        self::assertCount(1, $writes);
        self::assertSame(self::IDLE_PROBE_LOCK, $writes[0]['name']);
        self::assertSame(5, $writes[0]['expiration']);
        self::assertGreaterThanOrEqual($before + 5, $writes[0]['value']);
        self::assertLessThanOrEqual(time() + 5, $writes[0]['value']);
        self::assertSame([], $this->state()['remote_requests']);
    }

    public function testNewJobBypassesIdleProbeLeaseAndDispatchesImmediately(): void
    {
        $jobManager = new FakeJobManager([false, true]);
        $runner = $this->newRunner($jobManager);

        $runner->maybeDispatchAsyncRequest();
        self::assertCount(1, $this->state()['transient_writes']);

        $GLOBALS['sync_basalam_jobs_runner_test_state']['current_filter'] = 'sync_basalam_job_created';
        $runner->maybeDispatchAsyncRequest();

        self::assertSame([120, 120], $jobManager->timeouts);
        self::assertSame(self::DISPATCH_LOCK, $this->state()['transient_writes'][1]['name']);
        self::assertCount(1, $this->state()['remote_requests']);
    }

    public function testExistingLockSkipsQueueCheckAndDispatch(): void
    {
        $GLOBALS['sync_basalam_jobs_runner_test_state']['transients'][self::DISPATCH_LOCK] = time() + 25;
        $jobManager = new FakeJobManager([true]);
        $runner = $this->newRunner($jobManager);

        $runner->maybeDispatchAsyncRequest();

        self::assertSame(['get_transient', 'get_transient'], $this->state()['events']);
        self::assertSame([], $jobManager->timeouts);
        self::assertSame([], $this->state()['transient_writes']);
        self::assertSame([], $this->state()['remote_requests']);
    }

    /** @dataProvider inactiveLeaseCases */
    public function testExpiredOrOrphanedLeaseResumesDispatch(
        string $name,
        string $format,
        int $leaseOffset,
        ?int $timeoutOffset
    ): void {
        $this->setLease($name, $format, $leaseOffset, $timeoutOffset);
        $jobManager = new FakeJobManager([true]);
        $runner = $this->newRunner($jobManager);

        $runner->maybeDispatchAsyncRequest();

        self::assertSame([120], $jobManager->timeouts);
        self::assertCount(1, $this->state()['remote_requests']);
        self::assertSame(self::DISPATCH_LOCK, $this->state()['transient_writes'][0]['name']);
        self::assertGreaterThan(time(), $this->state()['transient_writes'][0]['value']);
    }

    public function inactiveLeaseCases(): array
    {
        $cases = [];
        foreach ([self::DISPATCH_LOCK, self::IDLE_PROBE_LOCK] as $name) {
            $cases[$name . ': legacy without timeout'] = [$name, 'legacy', 0, null];
            $cases[$name . ': legacy string without timeout'] = [$name, 'legacy_string', 0, null];
            $cases[$name . ': legacy with expired timeout'] = [$name, 'legacy', 0, -1];
            $cases[$name . ': expired deadline without timeout'] = [$name, 'deadline', -1, null];
            $cases[$name . ': expired stored deadline without timeout'] = [$name, 'deadline_string', -1, null];
            $cases[$name . ': expired deadline with future timeout'] = [$name, 'deadline', -1, 999];
            $cases[$name . ': deadline expires at current second'] = [$name, 'deadline', 0, null];
        }
        return $cases;
    }

    /** @dataProvider activeLeaseCases */
    public function testActiveLeasePreservesDispatchThrottle(
        string $name,
        string $format,
        ?int $timeoutOffset,
        bool $externalCache
    ): void {
        $this->setLease($name, $format, 25, $timeoutOffset);
        $GLOBALS['sync_basalam_jobs_runner_test_state']['external_object_cache'] = $externalCache;
        $jobManager = new FakeJobManager([true]);
        $runner = $this->newRunner($jobManager);

        $runner->maybeDispatchAsyncRequest();

        self::assertSame([], $jobManager->timeouts);
        self::assertSame([], $this->state()['transient_writes']);
        self::assertSame([], $this->state()['remote_requests']);
    }

    public function activeLeaseCases(): array
    {
        $cases = [];
        foreach ([self::DISPATCH_LOCK, self::IDLE_PROBE_LOCK] as $name) {
            $cases[$name . ': legacy with valid timeout'] = [$name, 'legacy', 25, false];
            $cases[$name . ': legacy string with valid timeout'] = [$name, 'legacy_string', 25, false];
            $cases[$name . ': deadline without timeout'] = [$name, 'deadline', null, false];
            $cases[$name . ': stored deadline without timeout'] = [$name, 'deadline_string', null, false];
            $cases[$name . ': cached legacy without database timeout'] = [$name, 'legacy', null, true];
        }
        return $cases;
    }

    public function testLosingTimeoutDoesNotReleaseAnActiveDispatchLease(): void
    {
        $jobManager = new FakeJobManager([true, true]);
        $runner = $this->newRunner($jobManager);
        $runner->maybeDispatchAsyncRequest();
        unset($GLOBALS['sync_basalam_test_options']['_transient_timeout_' . self::DISPATCH_LOCK]);

        $runner->maybeDispatchAsyncRequest();

        self::assertSame([120], $jobManager->timeouts);
        self::assertCount(1, $this->state()['remote_requests']);
    }

    public function testDispatchRechecksLeaseAfterProbingQueue(): void
    {
        $jobManager = new FakeJobManager([true]);
        $jobManager->onProbe = static function (): void {
            $GLOBALS['sync_basalam_jobs_runner_test_state']['transients'][self::DISPATCH_LOCK] = time() + 25;
        };
        $runner = $this->newRunner($jobManager);

        $runner->maybeDispatchAsyncRequest();

        self::assertSame([120], $jobManager->timeouts);
        self::assertSame([], $this->state()['remote_requests']);
        self::assertSame([], $this->state()['transient_writes']);
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

    public function testNewJobSchedulesRecoveryEvenWhenDispatchLeaseIsActive(): void
    {
        $GLOBALS['sync_basalam_jobs_runner_test_state']['current_filter'] = 'sync_basalam_job_created';
        $GLOBALS['sync_basalam_jobs_runner_test_state']['transients'][self::DISPATCH_LOCK] = time() + 25;
        $runner = $this->newRunner();
        $runner->maybeDispatchAsyncRequest();
        $scheduled = $this->state()['scheduled'][self::RECOVERY_HOOK];
        self::assertGreaterThanOrEqual(time() + 59, $scheduled);
        $runner->maybeDispatchAsyncRequest();
        self::assertSame($scheduled, $this->state()['scheduled'][self::RECOVERY_HOOK]);
        self::assertSame([], $this->state()['remote_requests']);
    }

    public function testRecoveryStopsForEmptyQueue(): void
    {
        $manager = new FakeJobManager();
        $runner = $this->newRunner($manager);
        $runner->recoverAsyncQueue();
        self::assertEmpty($this->state()['scheduled'] ?? []);
        self::assertSame([], $this->state()['remote_requests']);
    }

    public function testRecoveryRearmsDelayedWorkWithoutDispatchingAnEmptyWorker(): void
    {
        $manager = new FakeJobManager([false]);
        $manager->jobCount = 1;
        $runner = $this->newRunner($manager);
        $runner->recoverAsyncQueue();
        self::assertArrayHasKey(self::RECOVERY_HOOK, $this->state()['scheduled']);
        self::assertSame([], $this->state()['remote_requests']);
    }

    public function testRecoveryDispatchesEligibleWork(): void
    {
        $manager = new FakeJobManager([true]);
        $manager->jobCount = 1;
        $runner = $this->newRunner($manager);
        $runner->recoverAsyncQueue();
        self::assertCount(1, $this->state()['remote_requests']);
        self::assertArrayHasKey(self::RECOVERY_HOOK, $this->state()['scheduled']);
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

    private function setLease(string $name, string $format, int $leaseOffset, ?int $timeoutOffset): void
    {
        $value = in_array($format, ['deadline', 'deadline_string'], true) ? time() + $leaseOffset : 1;
        if (in_array($format, ['legacy_string', 'deadline_string'], true)) $value = (string) $value;
        $GLOBALS['sync_basalam_jobs_runner_test_state']['transients'][$name] = $value;
        if ($timeoutOffset !== null) {
            $GLOBALS['sync_basalam_test_options']['_transient_timeout_' . $name] = (string) (time() + $timeoutOffset);
        }
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
    public $jobCount = 0;
    public $timeouts = [];
    public $onProbe;

    private $pendingResults;

    public function __construct(array $pendingResults = [])
    {
        $this->pendingResults = $pendingResults;
    }

    public function hasPendingOrStaleProcessingJobs(int $timeout): bool
    {
        $GLOBALS['sync_basalam_jobs_runner_test_state']['events'][] = 'has_pending_jobs';
        $this->timeouts[] = $timeout;
        if ($this->onProbe) ($this->onProbe)();

        return (bool) array_shift($this->pendingResults);
    }

    public function getCountJobs(array $where): int
    {
        return $this->jobCount;
    }
}

class FakeWpdb
{
    public $allowBailValues = [];

    public function flush(): void
    {
        $GLOBALS['sync_basalam_jobs_runner_test_state']['events'][] = 'db_flush';
    }

    public function check_connection($allowBail = true): bool
    {
        $GLOBALS['sync_basalam_jobs_runner_test_state']['events'][] = 'db_check_connection';
        $this->allowBailValues[] = $allowBail;

        return true;
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
