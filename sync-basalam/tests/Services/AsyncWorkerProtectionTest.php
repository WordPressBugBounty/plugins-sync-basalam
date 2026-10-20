<?php

namespace SyncBasalam\Tests;

use PHPUnit\Framework\TestCase;
use SyncBasalam\Services\AsyncWorkerProtection;

class AsyncWorkerProtectionTest extends TestCase
{
    private string $directory;
    private array $server;
    private array $options;
    private ProtectionFixture $installer;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->options = $GLOBALS['sync_basalam_test_options'] ?? [];
        $GLOBALS['sync_basalam_test_options'] = [];
        $_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed';
        $this->directory = sys_get_temp_dir() . '/sync-basalam-htaccess-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->installer = new ProtectionFixture($this->directory);
    }

    protected function tearDown(): void
    {
        chmod($this->directory, 0755);
        foreach (glob($this->directory . '/{,.}*', GLOB_BRACE) as $file) {
            if (in_array(basename($file), ['.', '..'], true)) continue;
            if (!is_link($file)) chmod($file, 0644);
            unlink($file);
        }
        rmdir($this->directory);
        $_SERVER = $this->server;
        $GLOBALS['sync_basalam_test_options'] = $this->options;
    }

    private function path(): string { return $this->directory . '/.htaccess'; }

    private function status(): string
    {
        return get_option(AsyncWorkerProtection::OPTION_KEY)['status'];
    }

    public function testPrependsScopedRulesAndPreservesEveryOriginalByteAndFileMode(): void
    {
        $original = "# BEGIN LSCACHE\r\n# cache config\r\n# END LSCACHE\r\n# BEGIN WordPress\r\nRewriteEngine On\r\n# END WordPress";
        file_put_contents($this->path(), $original);
        chmod($this->path(), 0640);

        ob_start();
        $this->installer->ensureInstalled();
        self::assertSame('', ob_get_clean());

        self::assertSame(AsyncWorkerProtection::rules() . "\n" . $original, file_get_contents($this->path()));
        self::assertSame(0640, fileperms($this->path()) & 0777);
        self::assertSame('installed', $this->status());
        $backup = get_option(AsyncWorkerProtection::BACKUP_OPTION_KEY);
        self::assertSame($original, base64_decode($backup['content_base64'], true));
        self::assertTrue($backup['existed']);
        self::assertSame(realpath($this->path()), $backup['path']);
    }

    public function testRepeatedChecksDoNotDuplicateTheBlockOrReplaceTheBackup(): void
    {
        file_put_contents($this->path(), '# original');
        $this->installer->ensureInstalled();
        $backup = get_option(AsyncWorkerProtection::BACKUP_OPTION_KEY);
        // Force an ordinary periodic recheck, rather than only the throttle path.
        unset($GLOBALS['sync_basalam_test_options'][AsyncWorkerProtection::OPTION_KEY]);
        $this->installer->ensureInstalled();
        self::assertSame(1, substr_count(file_get_contents($this->path()), '# BEGIN SyncBasalam Async'));
        self::assertSame($backup, get_option(AsyncWorkerProtection::BACKUP_OPTION_KEY));
        self::assertSame('installed', $this->status());
    }

    public function testMigratesThePreviouslyDeployedBlockWithoutTouchingFollowingRules(): void
    {
        $original = "# BEGIN SyncBasalam Async\r\n<IfModule LiteSpeed>\r\n# old queue rule\r\n</IfModule>\r\n# END SyncBasalam Async\r\n# BEGIN WordPress\r\n# END WordPress\r\n";
        file_put_contents($this->path(), $original);
        $this->installer->ensureInstalled();
        self::assertSame(AsyncWorkerProtection::rules() . "\n# BEGIN WordPress\r\n# END WordPress\r\n", file_get_contents($this->path()));
        self::assertSame($original, base64_decode(get_option(AsyncWorkerProtection::BACKUP_OPTION_KEY)['content_base64']));
    }

    public function testCanCreateAMissingHtaccessWithARestorableBackup(): void
    {
        $this->installer->ensureInstalled();
        self::assertSame(AsyncWorkerProtection::rules() . "\n", file_get_contents($this->path()));
        self::assertFalse(get_option(AsyncWorkerProtection::BACKUP_OPTION_KEY)['existed']);
        self::assertSame('', get_option(AsyncWorkerProtection::BACKUP_OPTION_KEY)['content_base64']);
    }

    public function testMovesAnExistingTopLevelBlockBeforeOtherRules(): void
    {
        $other = "# BEGIN LSCACHE\n<IfModule LiteSpeed>\n# cache config\n</IfModule>\n# END LSCACHE\n";
        file_put_contents($this->path(), $other . "# BEGIN SyncBasalam Async\n# old rule\n# END SyncBasalam Async\n");
        $this->installer->ensureInstalled();
        self::assertSame(AsyncWorkerProtection::rules() . "\n" . $other, file_get_contents($this->path()));
    }

    /** @dataProvider otherWebservers */
    public function testDoesNotChangeOtherWebservers(string $server): void
    {
        $_SERVER['SERVER_SOFTWARE'] = $server;
        file_put_contents($this->path(), '# original');
        $this->installer->ensureInstalled();
        self::assertSame('# original', file_get_contents($this->path()));
        self::assertFalse(get_option(AsyncWorkerProtection::OPTION_KEY));
        self::assertFalse(get_option(AsyncWorkerProtection::BACKUP_OPTION_KEY));
    }

    public function otherWebservers(): array
    {
        return [['Apache/2.4.62'], ['nginx/1.29'], ['Caddy']];
    }

    public function testAnUnprivilegedContextDoesNotWriteAnyConfigurationOrState(): void
    {
        $this->installer->allowed = false;
        $this->installer->ensureInstalled();
        self::assertFileDoesNotExist($this->path());
        self::assertSame([], $GLOBALS['sync_basalam_test_options']);
    }

    public function testAnUnresolvedHomePathCannotWriteAtTheFilesystemRoot(): void
    {
        (new ProtectionFixture('/'))->ensureInstalled();
        self::assertSame('unavailable', $this->status());
        self::assertFalse(get_option(AsyncWorkerProtection::BACKUP_OPTION_KEY));
    }

    public function testReadOnlyFileIsLeftIntactAndTheFailureRemainsInternal(): void
    {
        file_put_contents($this->path(), '# original');
        chmod($this->path(), 0444);
        clearstatcache();
        if (is_writable($this->path())) self::markTestSkipped('Runtime bypasses filesystem permissions.');
        ob_start();
        $this->installer->ensureInstalled();
        self::assertSame('', ob_get_clean());
        self::assertSame('# original', file_get_contents($this->path()));
        self::assertSame('unwritable', $this->status());
        self::assertFalse(get_option(AsyncWorkerProtection::BACKUP_OPTION_KEY));
    }

    /** @dataProvider ambiguousMarkers */
    public function testAmbiguousMarkersAreNotRewritten(string $original): void
    {
        file_put_contents($this->path(), $original);
        $this->installer->ensureInstalled();
        self::assertSame($original, file_get_contents($this->path()));
        self::assertSame('invalid_markers', $this->status());
    }

    public function ambiguousMarkers(): array
    {
        return [
            ["# BEGIN SyncBasalam Async\n# no end\n"],
            ["# END SyncBasalam Async\n"],
            ["<IfModule mod_rewrite.c>\n# BEGIN SyncBasalam Async\n# END SyncBasalam Async\n</IfModule>\n"],
            ["# BEGIN SyncBasalam Async\n# END SyncBasalam Async\n# BEGIN SyncBasalam Async\n# END SyncBasalam Async\n"],
        ];
    }

    public function testSymlinkIsNotFollowedOrReplaced(): void
    {
        $target = $this->directory . '/target';
        file_put_contents($target, '# original');
        symlink($target, $this->path());
        $this->installer->ensureInstalled();
        self::assertTrue(is_link($this->path()));
        self::assertSame('# original', file_get_contents($target));
        self::assertSame('unsupported_file', $this->status());
    }

    public function testOverlargeFilesAreNotRewritten(): void
    {
        $original = str_repeat('#', 1048577);
        file_put_contents($this->path(), $original);
        $this->installer->ensureInstalled();
        self::assertSame($original, file_get_contents($this->path()));
        self::assertSame('unreadable', $this->status());
    }

    /** @dataProvider unsupportedEncodings */
    public function testUnsupportedEncodingIsNotRewritten(string $original): void
    {
        file_put_contents($this->path(), $original);
        $this->installer->ensureInstalled();
        self::assertSame($original, file_get_contents($this->path()));
        self::assertSame('unsupported_encoding', $this->status());
    }

    public function unsupportedEncodings(): array
    {
        return [["\xEF\xBB\xBF# config"], ["\0# config"]];
    }

    public function testOnlyTheManagedHtaccessRemainsAfterAWrite(): void
    {
        $this->installer->ensureInstalled();
        self::assertSame(['.htaccess'], array_values(array_diff(scandir($this->directory), ['.', '..'])));
    }

    public function testReactivationRepairsARemovedRuleEvenWithinTheCheckInterval(): void
    {
        $this->installer->ensureInstalled();
        file_put_contents($this->path(), '# changed while inactive');
        $this->installer->activate();
        self::assertSame(AsyncWorkerProtection::rules() . "\n# changed while inactive", file_get_contents($this->path()));
    }
}

class ProtectionFixture extends AsyncWorkerProtection
{
    public bool $allowed = true;
    private string $home;

    public function __construct(string $home) { $this->home = $home; }
    protected function homePath(): string { return $this->home; }
    protected function mayConfigure(): bool { return $this->allowed; }
}
