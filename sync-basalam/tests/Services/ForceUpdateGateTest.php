<?php

namespace SyncBasalam\Tests\Services;

use PHPUnit\Framework\TestCase;
use SyncBasalam\Services\ForceUpdateGate;

class ForceUpdateGateTest extends TestCase
{
    public function testDoesNotBlockWhenForceUpdateIsDisabled(): void
    {
        self::assertFalse(ForceUpdateGate::shouldBlock(false, '1.10.15', '1.10.15'));
    }

    public function testBlocksTheVersionThatReceivedTheForceUpdateFlag(): void
    {
        self::assertTrue(ForceUpdateGate::shouldBlock(true, '1.10.15', '1.10.15'));
    }

    public function testAllowsANewerPackageToRecoverFromTheStoredFlag(): void
    {
        self::assertFalse(ForceUpdateGate::shouldBlock(true, '1.10.15', '1.10.16'));
    }

    public function testIgnoresUnknownHotfixVersionReportedAsForcedByTheApi(): void
    {
        self::assertFalse(ForceUpdateGate::shouldHonorRemoteFlag(true, '1.10.16', '1.10.14'));
    }

    public function testHonorsForceUpdateForAnOutdatedPublishedVersion(): void
    {
        self::assertTrue(ForceUpdateGate::shouldHonorRemoteFlag(true, '1.10.13', '1.10.14'));
    }
}
