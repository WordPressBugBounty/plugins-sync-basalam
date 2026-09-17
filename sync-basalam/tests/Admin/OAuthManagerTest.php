<?php

namespace {
    if (!function_exists('get_current_user_id')) {
        function get_current_user_id()
        {
            return $GLOBALS['sync_basalam_oauth_test_user_id'] ?? 0;
        }
    }

    if (!function_exists('wp_generate_password')) {
        function wp_generate_password($length = 12, $specialChars = true, $extraSpecialChars = false)
        {
            return str_repeat('a', (int) $length);
        }
    }

    if (!function_exists('wp_salt')) {
        function wp_salt($scheme = 'auth')
        {
            return 'oauth-test-salt-' . $scheme;
        }
    }

    if (!function_exists('is_ssl')) {
        function is_ssl()
        {
            return true;
        }
    }
}

namespace SyncBasalam\Tests\Admin {
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;
    use SyncBasalam\Admin\Settings\OAuthManager;

    require_once dirname(__DIR__, 2) . '/includes/Admin/Settings/OAuthManager.php';

    /**
     * Cookie headers must be exercised before PHPUnit writes progress output.
     *
     * @runTestsInSeparateProcesses
     * @preserveGlobalState disabled
     */
    class OAuthManagerTest extends TestCase
    {
        protected function setUp(): void
        {
            $_COOKIE = [];
            $GLOBALS['sync_basalam_oauth_test_user_id'] = 42;
            $GLOBALS['sync_basalam_jobs_runner_test_state'] = [
                'events' => [],
            ];
        }

        protected function tearDown(): void
        {
            $_COOKIE = [];
            unset($GLOBALS['sync_basalam_oauth_test_user_id']);
        }

        public function testOauthProofDoesNotDependOnTransientStorage(): void
        {
            self::assertNotFalse(OAuthManager::issueOauthState());
            self::assertArrayHasKey(OAuthManager::OAUTH_STATE_COOKIE, $_COOKIE);
            self::assertNotContains('set_transient', $GLOBALS['sync_basalam_jobs_runner_test_state']['events']);

            self::assertTrue($this->verifyOauthState());
            self::assertArrayNotHasKey(OAuthManager::OAUTH_STATE_COOKIE, $_COOKIE);
        }

        public function testOauthProofIsSingleUse(): void
        {
            self::assertNotFalse(OAuthManager::issueOauthState());
            self::assertTrue($this->verifyOauthState());
            self::assertFalse($this->verifyOauthState());
        }

        public function testTamperedOauthProofIsRejectedAndConsumed(): void
        {
            self::assertNotFalse(OAuthManager::issueOauthState());
            $_COOKIE[OAuthManager::OAUTH_STATE_COOKIE] .= 'tampered';

            self::assertFalse($this->verifyOauthState());
            self::assertArrayNotHasKey(OAuthManager::OAUTH_STATE_COOKIE, $_COOKIE);
        }

        public function testOauthProofIsBoundToTheStartingAdministrator(): void
        {
            self::assertNotFalse(OAuthManager::issueOauthState());
            $GLOBALS['sync_basalam_oauth_test_user_id'] = 99;

            self::assertFalse($this->verifyOauthState());
        }

        public function testExpiredOauthProofIsRejected(): void
        {
            $_COOKIE[OAuthManager::OAUTH_STATE_COOKIE] = $this->buildOauthStateCookieValue(
                'expired-state',
                42,
                time() - 1
            );

            self::assertFalse($this->verifyOauthState());
        }

        private function buildOauthStateCookieValue(string $state, int $userId, int $expiresAt): string
        {
            $method = new ReflectionMethod(OAuthManager::class, 'buildOauthStateCookieValue');
            $method->setAccessible(true);

            return (string) $method->invoke(null, $state, $userId, $expiresAt);
        }

        private function verifyOauthState(): bool
        {
            $method = new ReflectionMethod(OAuthManager::class, 'verifyOauthState');
            $method->setAccessible(true);

            return (bool) $method->invoke(null);
        }
    }
}
