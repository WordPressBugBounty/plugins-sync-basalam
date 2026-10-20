<?php

namespace SyncBasalam\SalamGate\Services;

use SyncBasalam\SalamGate\ClientFactory;
use SyncBasalam\SalamGate\Contracts\ParcelViewNormalizer;
use SyncBasalam\SalamGate\SalamGateClient;
use SyncBasalam\SalamGate\SalamGateException;

defined('ABSPATH') || exit;

/**
 * عملیات مرسوله: پرداخت، بازیابی وضعیت پس از پرداخت، پیگیری و لیبل.
 *
 * قرارداد pay: پاسخ invoice-rich است و tracking_code مستقیم ندارد؛
 * کد رهگیری فقط از لیست با market صریح و تطبیق شناسه به دست می‌آید.
 * اگر مرسوله در پاسخ لیست پیدا نشود، نتیجه null است — «نامعلوم»، نه موفقیت/شکست قطعی.
 */
class ParcelService
{
    public const LABEL_TEMPLATES = ['invoice', 'invoice_new', 'summary', 'label_10x15', 'barcode'];

    /**
     * پرداخت مرسوله؛ خطا پس از ارسال درخواست لزوماً به معنی عدم کسر هزینه نیست.
     *
     * @return array body پاسخ pay — {invoice, items, transactions, parcels, delivery_node}
     */
    public function pay(int $parcelId, int $method, array $baseline = []): array
    {
        $cancellation = get_option('salam_gate_parcel_cancel_' . $parcelId, null);
        if (is_array($cancellation) && in_array($cancellation['state'] ?? '', ['pending', 'review', 'retryable', 'confirmed'], true)) {
            throw new SalamGateException(($cancellation['state'] ?? '') === 'confirmed'
                ? 'این مرسوله لغو شده است؛ پرداخت دوباره آن امکان‌پذیر نیست.'
                : 'درخواست لغو این مرسوله را بررسی کنید؛ پرداخت تازه انجام نمی‌شود.', 422);
        }
        if (($baseline['market'] ?? '') === 'website' && self::positiveId($baseline['id'] ?? null) !== $parcelId) {
            throw new SalamGateException('شناسه مرسوله برای پرداخت تأیید نشد؛ وضعیت ارسال را دوباره بررسی کنید.', 422);
        }
        $edit = get_option('salam_gate_parcel_edit_' . $parcelId, null);
        if (is_array($edit) && ($edit['state'] ?? '') !== 'selection_required') {
            throw new SalamGateException('این پیش‌نویس جایگزین شده یا نتیجه ویرایش آن در حال بررسی است؛ پرداخت انجام نشد.', 422, [
                ['location' => 'parcel', 'message' => 'parcel-draft-unavailable'],
            ]);
        }
        $outcomeKey = self::paymentOutcomeKey($parcelId);
        $previous = get_option($outcomeKey, null);
        if (is_array($previous) && ($previous['state'] ?? '') === 'paid') {
            throw new SalamGateException('این مرسوله قبلاً پرداخت شده است.', 422, [
                ['location' => 'parcel', 'message' => 'این مرسوله قبلاً پرداخت شده است.'],
            ]);
        }

        $websiteBlock = WebsitePaymentGuard::blockForParcel($baseline);
        if ($websiteBlock !== null) {
            throw new SalamGateException($websiteBlock['message'], 422, [
                ['location' => 'payment.identity', 'message' => $websiteBlock['state'] === 'paid' ? 'already-paid' : 'website-payment-unavailable'],
            ]);
        }
        WebsiteShippingEligibility::assertForParcel($baseline);
        $identityKeys = WebsitePaymentGuard::keysForParcel($baseline);
        if ($identityKeys !== []) { WebsitePaymentGuard::rememberIdentity($baseline); }

        // درج بدون overwrite روی نام یکتا؛ درخواست دوم قبل از پاسخ اول هم POST نمی‌کند.
        $attempt = ['state' => 'pending', 'attempt_id' => bin2hex(random_bytes(16)), 'started_at' => time(),
            'parcel_id' => $parcelId, 'website_identity_keys' => $identityKeys,
            'market' => is_string($baseline['market'] ?? null) ? $baseline['market'] : '',
            'baseline_known' => self::positiveId($baseline['id'] ?? null) === $parcelId,
            'baseline_external_id' => self::positiveId($baseline['external_id'] ?? null),
            'baseline_invoice_id' => self::positiveId($baseline['invoice_id'] ?? null),
            'baseline_invoice_created_at' => $baseline['invoice_created_at'] ?? null,
            'baseline_parcel_updated_at' => $baseline['updated_at'] ?? null];
        // Different SG IDs for the same website order must contend on the SAME durable claim.
        // Partial acquisition is released only before POST, using this exact attempt value.
        $claimed = [];
        foreach (array_merge($identityKeys, [$outcomeKey]) as $key) {
            if (self::claimPaymentOutcome($key, $attempt)) { $claimed[] = $key; continue; }
            foreach ($claimed as $ownedKey) { self::replacePaymentOutcome($ownedKey, $attempt, null); }
            $conflict = get_option($key, null);
            if (is_array($conflict) && ($conflict['state'] ?? '') === 'paid') {
                throw new SalamGateException('هزینه ارسال این سفارش قبلاً پرداخت شده است؛ پرداخت دوباره انجام نمی‌شود.', 422,
                    [['location' => 'payment.identity', 'message' => 'already-paid']]);
            }
            throw new SalamGateException(
                'پرداخت قبلی در حال بررسی است؛ برای دریافت تأیید، وضعیت را بررسی کنید. پرداخت را تکرار نکنید.',
                422,
                [['location' => 'payment.identity', 'message' => 'payment-in-progress']],
                SalamGateException::KIND_VALIDATION
            );
        }

        $dispatched = false;
        try {
            WebsiteShippingEligibility::assertForParcel($baseline);
            $dispatched = true;
            $response = ClientFactory::make()->post(sprintf(SalamGateClient::PATH_PARCEL_PAY, $parcelId), ['method' => $method]);
            $body = is_array($response['body'] ?? null) ? $response['body'] : [];
            $invoiceId = self::positiveId($body['invoice']['id'] ?? null);
            if ($invoiceId === null) {
                throw new SalamGateException(
                    'پاسخ پرداخت سلام‌گیت معتبر نیست؛ نتیجه پرداخت را بررسی کنید و دوباره پرداخت نکنید.',
                    (int) ($response['code'] ?? 200),
                    [],
                    SalamGateException::KIND_UNEXPECTED_RESPONSE
                );
            }

            foreach ($claimed as $key) {
                self::replacePaymentOutcome($key, $attempt, ['state' => 'paid', 'invoice_id' => $invoiceId] + $attempt);
            }

            return $body;
        } catch (SalamGateException $exception) {
            $noCharge = !$dispatched || $exception->isDefinitelyNotDispatched()
                || PaymentRejection::isConfirmedPreCharge($exception, $parcelId, $method, $baseline);
            $claimsReleased = true;
            foreach ($claimed as $key) {
                $changed = self::replacePaymentOutcome($key, $attempt, $noCharge ? null : ['state' => 'unknown'] + $attempt);
                if ($noCharge && !$changed && get_option($key, null) !== null) { $claimsReleased = false; }
            }
            if ($noCharge && $claimsReleased) { throw $exception; }
            // A validation/permission error can happen after payhub charged the user.
            // Keep the same unknown contract at every caller; never expose retry from HTTP status alone.
            throw new SalamGateException('پرداخت در حال بررسی است؛ وضعیت را بررسی کنید و پرداخت را تکرار نکنید.',
                $exception->getStatus(), $exception->getErrors(), SalamGateException::KIND_UNEXPECTED_RESPONSE);
        } catch (\Throwable $exception) {
            foreach ($claimed as $key) { self::replacePaymentOutcome($key, $attempt, ['state' => 'unknown'] + $attempt); }
            throw new SalamGateException('تأیید پرداخت کامل دریافت نشد؛ وضعیت را بررسی کنید و پرداخت را تکرار نکنید.',
                200, [], SalamGateException::KIND_UNEXPECTED_RESPONSE);
        }
    }

    /** شناسه API سراسری است؛ تعویض حساب یا چرخش توکن نباید محافظ پرداخت را حذف کند. */
    public static function paymentOutcomeKey(int $parcelId): string
    {
        return 'salam_gate_parcel_payment_' . $parcelId;
    }

    /** شناسه پاسخ API نباید با cast از آرایه، bool یا رشته ناقص ساخته شود. */
    public static function positiveId($value): ?int
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }

    /** Basalam financial evidence belongs to one native child, not merely an SG ID. */
    public static function matchesPaymentIdentity(array $parcel, array $evidence): bool
    {
        $market = $parcel['market'] ?? null;
        if (!is_string($market) || (isset($evidence['market']) && $evidence['market'] !== ''
            && $evidence['market'] !== $market)) { return false; }
        if ($market !== 'basalam') { return true; }
        $native = self::positiveId($evidence['baseline_external_id'] ?? null);
        // Old ambiguous native outcomes stay locked: do not infer their child from a new response.
        return $native !== null && self::positiveId($parcel['external_id'] ?? null) === $native;
    }

    /** An owner-verified receipt may settle only the same durable financial identity. */
    public static function recordReceiptOutcome(array $parcel, ?int $invoiceId = null): bool
    {
        $id = self::positiveId($parcel['id'] ?? null);
        if ($id === null || !\SyncBasalam\SalamGate\Contracts\ParcelStatuses::hasReceipt((int) ($parcel['status_id'] ?? 0))) {
            return false;
        }
        $native = self::positiveId($parcel['external_id'] ?? null);
        if (($parcel['market'] ?? '') === 'basalam' && ($native === null || ($parcel['invoice_status'] ?? null) !== 2
            || !is_string($parcel['tracking_code'] ?? null) || trim($parcel['tracking_code']) === '')) { return false; }
        $key = self::paymentOutcomeKey($id);
        $previous = get_option($key, null);
        if ($previous !== null && (!is_array($previous) || !self::matchesPaymentIdentity($parcel, $previous))) { return false; }
        $next = ['state' => 'paid'] + (is_array($previous) ? $previous : []) + [
            'market' => $parcel['market'] ?? '', 'baseline_external_id' => $native,
        ];
        $invoiceId = $invoiceId ?? self::positiveId($parcel['invoice_id'] ?? null);
        if ($invoiceId !== null && $invoiceId > 0 && self::positiveId($next['invoice_id'] ?? null) === null) {
            $next['invoice_id'] = $invoiceId;
        }
        $settled = $previous === null ? self::claimPaymentOutcome($key, $next)
            : ($previous === $next || self::replacePaymentOutcome($key, $previous, $next));
        if ($settled && ($parcel['market'] ?? '') === 'website') {
            // Do not clear/settle a sibling's uncertain attempt from this parcel's receipt.
            $identityKeys = is_array($previous['website_identity_keys'] ?? null) ? $previous['website_identity_keys'] : [];
            try { $identityKeys = array_unique(array_merge($identityKeys, WebsitePaymentGuard::keysForParcel($parcel))); }
            catch (SalamGateException $exception) { /* Legacy unlinked receipt remains protected by its SG ID. */ }
            foreach ($identityKeys as $identityKey) {
                if (!is_string($identityKey) || !preg_match('/^salam_gate_website_payment_(?:external|order)_[1-9][0-9]*$/D', $identityKey)) { continue; }
                $existing = get_option($identityKey, null);
                $paid = ['state' => 'paid', 'parcel_id' => $id] + $next;
                if ($existing === null) { self::claimPaymentOutcome($identityKey, $paid); }
                elseif (is_array($existing) && self::positiveId($existing['parcel_id'] ?? null) === $id) {
                    self::replacePaymentOutcome($identityKey, $existing, ['state' => 'paid'] + $existing);
                }
            }
        }
        return $settled;
    }

    private static function claimPaymentOutcome(string $key, array $attempt): bool
    {
        global $wpdb;

        // add_option از ON DUPLICATE KEY UPDATE استفاده می‌کند و برای قفل مالی کافی نیست.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic claim; table identifier is supplied by WordPress.
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO `{$wpdb->options}` (option_name, option_value, autoload) VALUES (%s, %s, %s)",
            $key,
            serialize($attempt),
            'no'
        ));

        wp_cache_delete($key, 'options');
        wp_cache_delete('notoptions', 'options');

        return (int) $inserted === 1;
    }

    /** A late worker must never overwrite/delete a successor's financial guard. */
    private static function replacePaymentOutcome(string $key, array $previous, ?array $next): bool
    {
        global $wpdb;
        $sql = $next === null
            ? $wpdb->prepare("DELETE FROM `{$wpdb->options}` WHERE option_name = %s AND BINARY option_value = BINARY %s", $key, serialize($previous))
            : $wpdb->prepare("UPDATE `{$wpdb->options}` SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s", serialize($next), $key, serialize($previous));
        $changed = $wpdb->query($sql);
        wp_cache_delete($key, 'options');
        wp_cache_delete('notoptions', 'options');
        return (int) $changed === 1;
    }

    /**
     * Called under the shared parcel workflow lease, with a freshly owner-verified list item.
     * A FAILED invoice predating the attempt is NEVER proof of this attempt's failure.
     * Missing legacy attempt evidence remains uncertain instead of authorizing another charge.
     */
    public static function reconcileFailedPayment(array $parcel): bool
    {
        $id = self::positiveId($parcel['id'] ?? null);
        if ($id === null) return false;
        $key = self::paymentOutcomeKey($id);
        $previous = get_option($key, null);
        $invoiceId = self::positiveId($parcel['invoice_id'] ?? null);
        if (!is_array($previous) || !in_array($previous['state'] ?? '', ['pending', 'unknown'], true)
            || ($previous['baseline_known'] ?? false) !== true || empty($previous['attempt_id'])
            || ($previous['market'] ?? '') !== ($parcel['market'] ?? null)
            || !self::matchesPaymentIdentity($parcel, $previous)
            || ($parcel['status_id'] ?? null) !== \SyncBasalam\SalamGate\Contracts\ParcelStatuses::NEW
            || ($parcel['invoice_status'] ?? null) !== 3 || $invoiceId === null
            || !empty($parcel['tracking_code'])
            || $invoiceId <= (self::positiveId($previous['baseline_invoice_id'] ?? null) ?? 0)
        ) return false;
        $created = self::invoiceTimestamp($parcel['invoice_created_at'] ?? null);
        $started = $previous['started_at'] ?? null;
        $baselineUpdated = self::invoiceTimestamp($previous['baseline_parcel_updated_at'] ?? null);
        // A newer invoice ID alone is not attempt correlation: it may have been
        // created by another client before our POST. Compare with our attempt
        // only when the service includes an explicit timezone. The live service
        // currently returns naive timestamps; NEVER guess UTC/Tehran for those.
        // Strict > also rejects ambiguous timestamps within the attempt's second.
        if (!is_int($started) || $created === null || $baselineUpdated === null || $created <= $started
            || !self::hasExplicitTimezone($parcel['invoice_created_at'] ?? null) || $created < $baselineUpdated
            || !self::sameTimestampClock($parcel['invoice_created_at'], $previous['baseline_parcel_updated_at'])) return false;
        $baselineCreated = self::invoiceTimestamp($previous['baseline_invoice_created_at'] ?? null);
        if ($baselineCreated !== null && ($created < $baselineCreated
            || !self::sameTimestampClock($parcel['invoice_created_at'], $previous['baseline_invoice_created_at']))) return false;
        // Retain minimal terminal evidence so another tab can safely clear its
        // browser-only unknown flag after the exact server marker is released.
        update_option('salam_gate_parcel_payment_failure_' . $id, [
            'attempt_id' => $previous['attempt_id'], 'invoice_id' => $invoiceId,
            'market' => $parcel['market'],
            'baseline_external_id' => self::positiveId($parcel['external_id'] ?? null),
        ], false);
        if (!self::replacePaymentOutcome($key, $previous, null)) { return false; }
        // A failed invoice for another SG ID cannot release this order's financial claim.
        foreach ((array) ($previous['website_identity_keys'] ?? []) as $identityKey) {
            if (!is_string($identityKey) || !preg_match('/^salam_gate_website_payment_(?:external|order)_[1-9][0-9]*$/D', $identityKey)) { continue; }
            $existing = get_option($identityKey, null);
            if (is_array($existing) && in_array($existing['state'] ?? '', ['pending', 'unknown'], true)
                && ($existing['attempt_id'] ?? null) === $previous['attempt_id']
                && self::positiveId($existing['parcel_id'] ?? null) === $id) {
                self::replacePaymentOutcome($identityKey, $existing, null);
            }
        }
        return true;
    }

    public static function paymentFailureConfirmed(array $parcel): bool
    {
        $id = self::positiveId($parcel['id'] ?? null);
        if ($id === null || get_option(self::paymentOutcomeKey($id), null) !== null
            || ($parcel['status_id'] ?? null) !== 7 || ($parcel['invoice_status'] ?? null) !== 3
            || !empty($parcel['tracking_code'])) return false;
        $proof = get_option('salam_gate_parcel_payment_failure_' . $id, null);
        return is_array($proof) && !empty($proof['attempt_id'])
            && self::positiveId($proof['invoice_id'] ?? null) === self::positiveId($parcel['invoice_id'] ?? null)
            && ($proof['market'] ?? null) === ($parcel['market'] ?? null)
            && self::matchesPaymentIdentity($parcel, $proof);
    }

    private static function sameTimestampClock(string $left, string $right): bool
    {
        return self::hasExplicitTimezone($left) === self::hasExplicitTimezone($right);
    }

    private static function hasExplicitTimezone($value): bool
    {
        return is_string($value) && preg_match('/(?:Z|[+-]\d{2}:\d{2})$/D', $value) === 1;
    }

    private static function invoiceTimestamp($raw): ?int
    {
        if (!is_string($raw) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?$/D', $raw)) return null;
        try {
            $date = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
            $errors = \DateTimeImmutable::getLastErrors();
            if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) return null;
            return $date->getTimestamp();
        }
        catch (\Exception $exception) { return null; }
    }

    /** قطع ارتباط، خطای سرور یا پاسخ خراب بعد از نوشتن، شکست قطعی نیست. */
    public static function isResultUnknown(SalamGateException $exception): bool
    {
        return in_array($exception->getKind(), [
            SalamGateException::KIND_TRANSPORT,
            SalamGateException::KIND_SERVER,
            SalamGateException::KIND_UNEXPECTED_RESPONSE,
        ], true);
    }

    public function getDetail(int $parcelId): array
    {
        $response = ClientFactory::make()->get('/v1/parcels/' . $parcelId);

        return is_array($response['body'] ?? null) ? $response['body'] : [];
    }

    /**
     * Recover an owner-scoped draft by its original identity using the public list/detail API.
     * GET /markets/{market}/parcels/{external_id} is admin-only and is never used here.
     * A filtered list is not identity evidence: every returned row and the detail are verified.
     * @return array{parcel:array,detail:array}|null
     */
    public function findByExternalId(string $market, int $externalId, ?int $expectedId = null): ?array
    {
        if ($market !== 'basalam' || $externalId < 1 || ($expectedId !== null && $expectedId < 1)) {
            throw new SalamGateException('شناسه مرسوله باسلام برای بازیابی معتبر نیست.', 422);
        }
        $response = ClientFactory::make()->get(SalamGateClient::PATH_PARCELS, [
            'market_name' => [$market], 'external_ids' => [$externalId], 'per_page' => 30,
        ]);
        $body = $response['body'] ?? null;
        $invalid = static fn (): SalamGateException => new SalamGateException(
            'اطلاعات مرسوله موجود باسلام تأیید نشد؛ دوباره وضعیت ارسال را بررسی کنید. ثبت و پرداخت انجام نشد.',
            200, [], SalamGateException::KIND_UNEXPECTED_RESPONSE
        );
        if (!is_array($body) || !is_array($body['data'] ?? null) || count($body['data']) > 1
            || (isset($body['next_cursor']) && $body['next_cursor'] !== '')) { throw $invalid(); }
        if ($body['data'] === []) {
            if ($expectedId !== null) { throw $invalid(); }
            return null;
        }
        $item = reset($body['data']);
        $id = is_array($item) ? self::positiveId($item['id'] ?? null) : null;
        if ($id === null || ($item['market']['value'] ?? null) !== $market
            || self::positiveId($item['external_id'] ?? null) !== $externalId
            || ($expectedId !== null && $id !== $expectedId)
            || !is_int($item['status']['id'] ?? null)
            || !\SyncBasalam\SalamGate\Contracts\ParcelStatuses::isValid($item['status']['id'])
            || (isset($item['tracking_code']) && !is_string($item['tracking_code']))
            || (isset($item['invoice']) && (!is_array($item['invoice']) || self::positiveId($item['invoice']['status'] ?? null) === null))) {
            throw $invalid();
        }
        $detail = $this->getDetail($id);
        $weight = self::positiveId($detail['weight'] ?? null);
        $value = self::positiveId($detail['value'] ?? null);
        $box = self::positiveId($detail['box_type']['id'] ?? null);
        if (self::positiveId($detail['id'] ?? null) !== $id || ($detail['status']['id'] ?? null) !== $item['status']['id']
            || $weight === null || $weight < 10 || $weight > 30000
            || $value === null || $value < 10000 || $value > 10000000000 || $box === null
            || !is_bool($detail['is_liquid'] ?? null) || !is_bool($detail['is_fragile'] ?? null)
            || !is_array($detail['shipping_info']['receiver'] ?? null)
            || !is_array($detail['items'] ?? null) || $detail['items'] === []
            || (array_key_exists('external_id', $detail) && self::positiveId($detail['external_id']) !== $externalId)
            || (array_key_exists('market', $detail) && ($detail['market']['value'] ?? null) !== $market)) {
            throw $invalid();
        }
        if ($box === 37) {
            foreach (['width', 'length', 'height'] as $dimension) {
                if (self::positiveId($detail['dimensions'][$dimension] ?? null) === null) { throw $invalid(); }
            }
        }
        return ['parcel' => ParcelViewNormalizer::fromListItem($item), 'detail' => $detail];
    }

    /**
     * رکورد لیستِ مرسوله بعد از پرداخت — با market صریح (default سرویس basalam را حذف می‌کند)
     * و تطبیق دقیق شناسه (نه data[0]).
     *
     * @return array|null خروجی ParcelViewNormalizer::fromListItem یا null = وضعیت نامعلوم
     */
    public function fetchAfterPay(int $parcelId, string $market, ?int $expectedExternalId = null, bool $allowMissingWebsiteExternalId = false): ?array
    {
        $response = ClientFactory::make()->get(SalamGateClient::PATH_PARCELS, [
            'ids'         => [$parcelId],
            'market_name' => [$market],
            'per_page'    => 30,
        ]);

        $body = $response['body'] ?? null;
        if (!is_array($body) || !is_array($body['data'] ?? null)) {
            throw new SalamGateException('پاسخ فهرست مرسولات معتبر نیست.', 200, [], SalamGateException::KIND_UNEXPECTED_RESPONSE);
        }
        $matched = null;
        foreach ($body['data'] as $item) {
            if (!is_array($item) || self::positiveId($item['id'] ?? null) !== $parcelId) {
                continue;
            }
            // Query filters are not identity evidence by themselves: verify the returned market too.
            if (($item['market']['value'] ?? null) !== $market) {
                continue;
            }
            // Opt-in compatibility for printing old Woo receipts only. These
            // owner-scoped website records legitimately have null/absent
            // external_id. Never waive a present mismatch, malformed value or
            // a Basalam child identity; other consumers remain strict by default.
            $missingWebsiteReferenceAllowed = $allowMissingWebsiteExternalId
                && $market === 'website' && ($item['external_id'] ?? null) === null;
            if ($matched !== null || !is_int($item['status']['id'] ?? null)
                || ($market === 'basalam' && self::positiveId($item['external_id'] ?? null) === null)
                || ($expectedExternalId !== null && self::positiveId($item['external_id'] ?? null) !== $expectedExternalId
                    && !$missingWebsiteReferenceAllowed)
                || !\SyncBasalam\SalamGate\Contracts\ParcelStatuses::isValid($item['status']['id'])
                || (isset($item['tracking_code']) && !is_string($item['tracking_code']))
                || (isset($item['invoice']) && (!is_array($item['invoice'])
                    || self::positiveId($item['invoice']['status'] ?? null) === null))
            ) {
                throw new SalamGateException('هویت یا وضعیت مرسوله در پاسخ معتبر نیست.', 200, [], SalamGateException::KIND_UNEXPECTED_RESPONSE);
            }
            $matched = $item;
        }

        return $matched !== null ? ParcelViewNormalizer::fromListItem($matched) : null;
    }

    /**
     * رویدادهای تغییر وضعیت مرسوله — نرمال‌شده برای نمایش.
     *
     * @return array<int, array{state: string, description: ?string, status_title: string, occurred_at: ?string, occurred_at_display: string}>
     */
    public function getTrack(int $parcelId): array
    {
        $response = ClientFactory::make()->get(sprintf(SalamGateClient::PATH_PARCEL_TRACK, $parcelId));

        return self::normalizeTrackEvents(is_array($response['body'] ?? null) ? $response['body'] : []);
    }

    /**
     * ساخت درخواست لیبل — پاسخ نرمال‌شده:
     * ['status'=>'completed','url'=>..] یا ['status'=>'requested','request_id'=>..,'retry_after'=>..]
     * یا ['status'=>'failed','message'=>..]
     */
    public function getLabelUrl(array $parcelIds, string $template): array
    {
        // A single paid parcel uses exactly the same provider contract as a batch.
        // Do not coerce malformed IDs to a different parcel through intval().
        $ids = [];
        foreach ($parcelIds as $id) {
            if ((!is_int($id) && !(is_string($id) && preg_match('/^[1-9][0-9]*$/D', $id)))
                || ($id = self::positiveId($id)) === null || $id > 2147483647) {
                throw new SalamGateException('شناسه مرسوله برای چاپ برچسب معتبر نیست.', 422);
            }
            $ids[] = $id;
        }
        $ids = array_values(array_unique($ids));
        if ($ids === [] || count($ids) > 100 || !in_array($template, self::LABEL_TEMPLATES, true)) {
            throw new SalamGateException('مرسوله یا قالب برچسب را به‌درستی انتخاب کنید.', 422);
        }
        $response = ClientFactory::make()->post(
            SalamGateClient::PATH_GENERATE_LABEL,
            ['parcel_ids' => $ids],
            ['template' => $template, 'async' => 'true']
        );

        return self::normalizeLabelResponse(is_array($response['body'] ?? null) ? $response['body'] : []);
    }

    /**
     * پیگیری درخواست لیبل (بدون request_id = آخرین درخواست).
     */
    public function getLabelStatus(int $requestId = 0): array
    {
        if ($requestId < 0 || $requestId > 2147483647) {
            throw new SalamGateException('شناسه درخواست برچسب معتبر نیست.', 422);
        }
        $query = $requestId > 0 ? ['request_id' => $requestId] : [];
        $response = ClientFactory::make()->get(SalamGateClient::PATH_GENERATE_LABEL, $query);

        if ($requestId === 0 && ($response['no_label_request'] ?? false) === true) {
            return ['status' => 'not_found', 'request_id' => null];
        }

        $result = self::normalizeLabelResponse(is_array($response['body'] ?? null) ? $response['body'] : []);
        if ($requestId > 0 && $result['request_id'] !== $requestId) {
            throw new SalamGateException('پاسخ وضعیت متعلق به این درخواست برچسب نیست؛ دوباره بررسی کنید.', 200, [], SalamGateException::KIND_UNEXPECTED_RESPONSE);
        }
        return $result;
    }

    /**
     * مقصد ثابت رهگیری پست؛ هرگز توکن یا شناسه سفارش را به این لینک اضافه نکنید.
     */
    public static function postTrackingUrl(string $trackingCode): string
    {
        return 'https://tracking.post.ir/?id=' . rawurlencode($trackingCode);
    }

    /**
     * پاسخ track ممکن است {events:[..]}، مپ لیست‌ها یا لیست خام باشد — همه نرمال می‌شوند.
     */
    public static function normalizeTrackEvents(array $body): array
    {
        $events = [];

        if (isset($body['events']) && is_array($body['events'])) {
            $events = $body['events'];
        } elseif ($body !== [] && array_keys($body) === range(0, count($body) - 1)) {
            $events = $body;
        } else {
            foreach ($body as $group) {
                if (is_array($group)) {
                    foreach ($group as $event) {
                        if (is_array($event)) {
                            $events[] = $event;
                        }
                    }
                }
            }
        }

        $normalized = [];
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $occurredAt = isset($event['occurred_at']) ? (string) $event['occurred_at'] : null;
            $normalized[] = [
                'state'                => (string) ($event['state'] ?? ''),
                'description'          => isset($event['description']) && $event['description'] !== null ? (string) $event['description'] : null,
                'status_title'         => (string) ($event['status']['title'] ?? ''),
                'occurred_at'          => $occurredAt,
                'occurred_at_display'  => self::formatDate($occurredAt),
            ];
        }

        return $normalized;
    }

    /**
     * LabelRequestResponse → ساختار UI.
     */
    public static function normalizeLabelResponse(array $body): array
    {
        $status = $body['status'] ?? null;
        $requestId = self::positiveId($body['id'] ?? null);
        $invalid = static fn (): SalamGateException => new SalamGateException(
            'پاسخ ساخت برچسب کامل یا معتبر نیست؛ وضعیت همین درخواست را دوباره بررسی کنید.',
            200, [], SalamGateException::KIND_UNEXPECTED_RESPONSE
        );
        if ($requestId === null || !is_string($status)
            || !in_array($status, ['requested', 'in_progress', 'completed', 'failed'], true)) {
            throw $invalid();
        }
        $url = $body['url'] ?? null;

        if ($status === 'completed') {
            // The provider deliberately omits the URL after expire_at. This is
            // terminal, not another queued job to poll forever.
            if ($url === null || $url === '') {
                $expires = is_string($body['expire_at'] ?? null) ? strtotime($body['expire_at']) : false;
                if ($expires === false || $expires > time()) { throw $invalid(); }
                return ['status' => 'expired', 'request_id' => $requestId,
                    'message' => 'مهلت دریافت فایل قبلی تمام شده است؛ برای این مرسوله برچسب تازه بسازید.'];
            }
            $parts = is_string($url) ? parse_url($url) : false;
            if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['https', 'http'], true)
                || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
                || filter_var($url, FILTER_VALIDATE_URL) === false) { throw $invalid(); }
            return [
                'status'     => 'completed',
                'url'        => $url,
                'file_id'    => isset($body['file_id']) ? (int) $body['file_id'] : null,
                'expire_at'  => $body['expire_at'] ?? null,
                'request_id' => $requestId,
            ];
        }

        if ($status === 'failed') {
            return [
                'status'     => 'failed',
                'message'    => 'ساخت لیبل ناموفق بود؛ دوباره تلاش کنید.',
                'request_id' => $requestId,
            ];
        }

        return [
            'status'      => 'requested',
            'request_id'  => $requestId,
            'retry_after' => min(60, max(2, self::positiveId($body['retry_after_seconds'] ?? null) ?? 5)),
            'status_raw'  => $status,
        ];
    }

    private static function formatDate(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return '—';
        }

        $timestamp = strtotime($iso);

        return $timestamp !== false ? date_i18n('Y/m/d H:i', $timestamp) : $iso;
    }
}
