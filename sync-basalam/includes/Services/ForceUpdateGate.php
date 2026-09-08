<?php

namespace SyncBasalam\Services;

defined('ABSPATH') || exit;

class ForceUpdateGate
{
    public static function shouldBlock($forceUpdate, string $installedVersion, string $runningVersion): bool
    {
        if (!$forceUpdate) return false;

        // A newer plugin package must be allowed to boot so it can re-check
        // the remote force-update status and run its migrations. Otherwise the
        // flag set by the old version permanently prevents the update from
        // taking effect even though WordPress reports the plugin as active.
        return version_compare($installedVersion ?: '0.0.0', $runningVersion, '>=');
    }

    public static function shouldHonorRemoteFlag(
        $forceUpdate,
        string $runningVersion,
        ?string $latestPublishedVersion
    ): bool {
        if (!$forceUpdate) return false;

        // The version API currently treats versions it does not know about as
        // forced updates. A hotfix newer than the latest published package is
        // not outdated and must not disable itself because it has no download
        // record yet.
        if ($latestPublishedVersion && version_compare($runningVersion, $latestPublishedVersion, '>')) {
            return false;
        }

        return true;
    }
}
