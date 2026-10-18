<?php

namespace SyncBasalam\SalamGate\Services;

use SyncBasalam\SalamGate\ClientFactory;
use SyncBasalam\SalamGate\Utils\Digits;
use SyncBasalam\SalamGate\Utils\JalaliDate;

defined('ABSPATH') || exit;

/** Fresh, read-only identity fields; never persist a copy of the user's identity. */
final class ActivationIdentityService
{
    public const URL = 'https://core.basalam.com/v3/users/me';

    public function get(array $profile): array
    {
        $user = is_array($profile['user'] ?? null) ? $profile['user'] : [];
        $result = [
            'mobile' => $user['mobile'] ?? null,
            'national_code' => $user['national_code'] ?? null,
            'national_code_serial' => $user['national_card_serial'] ?? null,
            'full_name' => null,
            'birth_date' => null,
        ];
        $token = ClientFactory::make()->token();
        $identity = ConnectionCache::identityHash();
        $response = wp_remote_request(self::URL, [
            'method' => 'GET', 'timeout' => 20, 'redirection' => 0, 'sslverify' => true,
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
        ]);
        ConnectionCache::assertCurrentIdentity($identity);
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $body = json_decode((string) wp_remote_retrieve_body($response), true);
            if (is_array($body) && filter_var($body['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                && (!isset($user['id']) || (int) $user['id'] === (int) $body['id'])) {
                foreach (['mobile', 'national_code'] as $field) {
                    if (is_string($body[$field] ?? null) && trim($body[$field]) !== '') $result[$field] = $body[$field];
                }
                $result['full_name'] = is_string($body['name'] ?? null) ? trim($body['name']) : null;
                $birthday = $body['birthday'] ?? null;
                if (is_string($birthday) && preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:$|T| )/', $birthday, $parts)
                    && (int) $parts[1] >= 1900 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                    $result['birth_date'] = JalaliDate::toJalali((int) $parts[1], (int) $parts[2], (int) $parts[3]);
                }
            }
        }
        $result['mobile'] = is_string($result['mobile']) ? Digits::normalizeMobile(Digits::toEnglish($result['mobile'])) : null;
        $code = is_string($result['national_code']) ? Digits::toEnglish(trim($result['national_code'])) : '';
        $result['national_code'] = preg_match('/^\d{10}$/D', $code) ? $code : null;
        return $result;
    }
}
