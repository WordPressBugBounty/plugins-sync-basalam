<?php

namespace SyncBasalam\Tests\Services;

use PHPUnit\Framework\TestCase;
use SyncBasalam\Services\VendorSyncPolicy;

class VendorSyncPolicyTest extends TestCase
{
    public function testActiveVendorAllowsNormalSync(): void
    {
        $now = 2_000_000;
        $state = VendorSyncPolicy::stateFromVendorInfo(
            [],
            ['is_active' => true, 'status' => ['value' => 2987, 'name' => 'فعال']],
            123,
            $now
        );

        self::assertTrue($state['is_active']);
        self::assertNull($state['inactive_since']);
        self::assertSame(VendorSyncPolicy::MODE_ACTIVE, VendorSyncPolicy::resolveMode($state, $now));
    }

    public function testDisabledVendorStartsOneContinuousInactivePeriod(): void
    {
        $firstCheck = 2_000_000;
        $state = VendorSyncPolicy::stateFromVendorInfo(
            [],
            ['is_active' => false, 'status' => ['id' => 2988, 'name' => 'غیرفعال']],
            123,
            $firstCheck
        );

        $secondCheck = $firstCheck + DAY_IN_SECONDS;
        $state = VendorSyncPolicy::stateFromVendorInfo(
            $state,
            ['is_active' => false, 'status' => ['value' => 2988, 'name' => 'غیرفعال']],
            123,
            $secondCheck
        );

        self::assertFalse($state['is_active']);
        self::assertSame($firstCheck, $state['inactive_since']);
        self::assertSame(
            VendorSyncPolicy::MODE_INACTIVE_LIMITED,
            VendorSyncPolicy::resolveMode($state, $firstCheck + (21 * DAY_IN_SECONDS) - 1)
        );
    }

    public function testExactlyTwentyOneDaysSuspendsAllProductSync(): void
    {
        $inactiveSince = 2_000_000;
        $state = ['is_active' => false, 'inactive_since' => $inactiveSince];

        self::assertSame(
            VendorSyncPolicy::MODE_INACTIVE_LIMITED,
            VendorSyncPolicy::resolveMode($state, $inactiveSince + (21 * DAY_IN_SECONDS) - 1)
        );
        self::assertSame(
            VendorSyncPolicy::MODE_INACTIVE_SUSPENDED,
            VendorSyncPolicy::resolveMode($state, $inactiveSince + (21 * DAY_IN_SECONDS))
        );
    }

    /**
     * @dataProvider unavailableVendorInfoProvider
     */
    public function testUnavailableVendorSignalsAreRecognized(array $vendorInfo): void
    {
        $state = VendorSyncPolicy::stateFromVendorInfo([], $vendorInfo, 123, 2_000_000);

        self::assertFalse($state['is_active']);
        self::assertSame(2_000_000, $state['inactive_since']);
        self::assertSame(
            VendorSyncPolicy::MODE_INACTIVE_LIMITED,
            VendorSyncPolicy::resolveMode($state, 2_000_000)
        );
    }

    public function unavailableVendorInfoProvider(): array
    {
        return [
            'disabled status code wins over a contradictory active flag' => [
                ['is_active' => true, 'status' => ['value' => 2988, 'name' => 'فعال']],
            ],
            'closed status code' => [
                ['status' => ['id' => 3199, 'name' => 'بسته شده']],
            ],
            'Persian inactive status name' => [
                ['status' => ['name' => 'غیرفعال توسط پشتیبانی']],
            ],
            'English closed status title' => [
                ['status' => ['title' => 'Closed by support']],
            ],
            'string false active flag' => [
                ['is_active' => 'false', 'status' => ['name' => 'Unknown']],
            ],
        ];
    }

    public function testReactivationClearsInactiveTimestamp(): void
    {
        $state = VendorSyncPolicy::stateFromVendorInfo(
            [
                'vendor_id' => 123,
                'is_active' => false,
                'inactive_since' => 1_000_000,
            ],
            ['is_active' => true, 'status' => ['value' => 2987, 'name' => 'فعال']],
            123,
            3_000_000
        );

        self::assertTrue($state['is_active']);
        self::assertNull($state['inactive_since']);
    }

    public function testChangingVendorStartsANewInactivePeriod(): void
    {
        $state = VendorSyncPolicy::stateFromVendorInfo(
            [
                'vendor_id' => 123,
                'is_active' => false,
                'inactive_since' => 1_000_000,
            ],
            ['is_active' => false, 'status' => ['value' => 2988, 'name' => 'غیرفعال']],
            456,
            3_000_000
        );

        self::assertSame(456, $state['vendor_id']);
        self::assertSame(3_000_000, $state['inactive_since']);
    }

    public function testActiveModePreservesTheCompleteUpdatePayload(): void
    {
        $payload = [
            'id' => 11,
            'name' => 'Product name',
            'description' => 'Description',
            'primary_price' => 120_000,
            'stock' => 4,
            'photos' => [1, 2],
        ];

        self::assertSame(
            $payload,
            VendorSyncPolicy::restrictUpdatePayloadForMode($payload, VendorSyncPolicy::MODE_ACTIVE)
        );
    }

    public function testLimitedModeKeepsOnlyPriceAndStockFields(): void
    {
        $payload = [
            'id' => 11,
            'type' => 'variable',
            'name' => 'Product name',
            'description' => 'Description',
            'primary_price' => 120_000,
            'stock' => 0,
            'photos' => [1, 2],
            'variants' => [
                [
                    'id' => 22,
                    'primary_price' => 90_000,
                    'stock' => 3,
                    'properties' => [['value' => 'red']],
                ],
                [
                    'id' => null,
                    'primary_price' => 80_000,
                    'stock' => 2,
                    'properties' => [['value' => 'blue']],
                ],
            ],
        ];

        $restricted = VendorSyncPolicy::restrictUpdatePayloadForMode(
            $payload,
            VendorSyncPolicy::MODE_INACTIVE_LIMITED
        );

        self::assertSame(['id', 'type', 'primary_price', 'stock', 'variants'], array_keys($restricted));
        self::assertCount(1, $restricted['variants']);
        self::assertSame(['id', 'primary_price', 'stock'], array_keys($restricted['variants'][0]));
        self::assertSame(0, $restricted['stock']);
    }

    public function testSuspendedModeProducesNoMutationPayload(): void
    {
        self::assertSame([], VendorSyncPolicy::restrictUpdatePayloadForMode(
            ['id' => 11, 'stock' => 2],
            VendorSyncPolicy::MODE_INACTIVE_SUSPENDED
        ));
    }

    public function testLimitedModeDropsEveryDisconnectedVariation(): void
    {
        $restricted = VendorSyncPolicy::restrictUpdatePayloadForMode(
            [
                'id' => 11,
                'type' => 'variable',
                'name' => 'Product name',
                'variants' => [
                    ['id' => null, 'primary_price' => 80_000, 'stock' => 2],
                    ['primary_price' => 90_000, 'stock' => 3],
                ],
            ],
            VendorSyncPolicy::MODE_INACTIVE_LIMITED
        );

        self::assertSame(['id' => 11, 'type' => 'variable'], $restricted);
    }

    public function testStatusRefreshBecomesDueAtTwentyFourHours(): void
    {
        $checkedAt = 2_000_000;
        $state = ['vendor_id' => 123, 'checked_at' => $checkedAt];

        self::assertFalse(VendorSyncPolicy::isRefreshDue($state, 123, $checkedAt + DAY_IN_SECONDS - 1));
        self::assertTrue(VendorSyncPolicy::isRefreshDue($state, 123, $checkedAt + DAY_IN_SECONDS));
        self::assertTrue(VendorSyncPolicy::isRefreshDue($state, 456, $checkedAt + 10));
        self::assertTrue(VendorSyncPolicy::isRefreshDue($state, 123, $checkedAt - 1));
        self::assertTrue(VendorSyncPolicy::isRefreshDue(['vendor_id' => 123], 123, $checkedAt));
    }
}
