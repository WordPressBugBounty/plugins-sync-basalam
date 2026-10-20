<?php

namespace SyncBasalam\SalamGate\Admin {
    // Render the real template without an account, WordPress DB or network.
    final class AccountStateRouter {
        public const STATE_ACTIVE = 'active';
        public static function resolveForPage(): array { return ['state' => 'active', 'shops' => [], 'message' => '']; }
    }
}

namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    define('ABSPATH', __DIR__ . '/wordpress/');
    require_once __DIR__ . '/wordpress-compat.php';
    require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

    use SyncBasalam\SalamGate\Admin\Ajax\ParcelsListActions;
    use SyncBasalam\SalamGate\ClientFactory;
    use SyncBasalam\SalamGate\SalamGateClient;
    use SyncBasalam\SalamGate\SalamGateException;
    use SyncBasalam\SalamGate\Services\ParcelService;
    use SyncBasalam\SalamGate\Settings\SalamGateSettings;

    $checks = 0;
    $allowed = true;
    function printCheck(bool $ok, string $message): void { global $checks; if (!$ok) throw new \RuntimeException($message); ++$checks; }
    function current_user_can($cap) { return $GLOBALS['allowed'] && $cap === 'manage_woocommerce'; }
    function wp_verify_nonce($nonce, $action) { return $nonce === 'synthetic' && $action === 'basalam_pro_nonce'; }
    function wp_unslash($value) { return $value; }
    function sanitize_text_field($value) { return trim(strip_tags($value)); }
    function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)); }
    function get_option($key, $default = false) { return $default; }
    function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
    function esc_html($value) { return esc_attr($value); }
    function esc_url($value) { return esc_attr($value); }
    function admin_url($path = '') { return 'http://example.test/wp-admin/' . $path; }
    function selected($a, $b) { if ($a === $b) echo 'selected'; }
    function checked($a, $b) { if ($a === $b) echo 'checked'; }
    function disabled($a, $b = true) { if ($a === $b) echo 'disabled'; }
    function add_query_arg($args, $url) { return $url . '&' . http_build_query($args); }
    function wp_json_encode($value) { return json_encode($value, JSON_UNESCAPED_UNICODE); }
    class PrintJsonExit extends \RuntimeException {
        public bool $success;
        public array $data;
        public function __construct(bool $success, array $data) { parent::__construct(); $this->success = $success; $this->data = $data; }
    }
    function wp_send_json_success($data, $status = 200) { throw new PrintJsonExit(true, $data); }
    function wp_send_json_error($data, $status = 200) { throw new PrintJsonExit(false, $data); }
    function invokePrint(string $method, array $post): PrintJsonExit {
        $_POST = $post;
        try { ParcelsListActions::$method(); } catch (PrintJsonExit $result) { return $result; }
        throw new \RuntimeException('Expected JSON result');
    }

    ob_start();
    include dirname(__DIR__, 2) . '/templates/SalamGate/parcels-page.php';
    $html = (string) ob_get_clean();
    if (in_array('--render', $argv, true)) { echo $html; exit; }
    printCheck(strpos($html, 'data-sgp-print-toggle') !== false && strpos($html, '>چاپ گروهی</span>') !== false, 'Generic batch print button');
    printCheck(strpos($html, 'data-sgp-print-kind="label"') !== false && strpos($html, 'data-sgp-print-kind="barcode"') !== false, 'Both choices share the print dialog');
    printCheck(strpos($html, 'value="barcode"') === false, 'Barcode is not a postal-label size or default');
    printCheck(!in_array('barcode', SalamGateSettings::LABEL_TEMPLATES, true), 'Keep saved label defaults unchanged');

    $client = new class extends SalamGateClient {
        public array $calls = [];
        public array $rows = [];
        public function get(string $path, array $query = []): array {
            $this->calls[] = ['GET', $path, $query];
            if ($path === self::PATH_GENERATE_LABEL) return ['body' => ['id' => 91, 'status' => 'completed', 'template' => 'barcode', 'url' => 'https://example.test/barcodes.pdf']];
            if ($path !== self::PATH_PARCELS) throw new \RuntimeException('Unexpected API read');
            return ['body' => ['data' => $this->rows, 'next_cursor' => null]];
        }
        public function post(string $path, array $body = [], array $query = []): array {
            if ($path !== self::PATH_GENERATE_LABEL) throw new \RuntimeException('Payment, registration and other mutations are forbidden');
            $this->calls[] = ['POST', $path, $body, $query];
            return ['body' => ['id' => 91, 'status' => 'requested', 'retry_after_seconds' => 2]];
        }
    };
    ClientFactory::$resolver = static fn () => $client;
    $paid = ['id' => 101, 'status' => ['id' => 8], 'invoice' => ['status' => 2], 'tracking_code' => 'synthetic-tracking', 'updated_at' => '2026-10-20T10:00:00+03:30'];
    $client->rows = [$paid];
    foreach (ParcelService::LABEL_TEMPLATES as $template) {
        $client->calls = [];
        $result = invokePrint('bulkLabel', ['_wpnonce' => 'synthetic', 'parcel_ids' => ['101', '101'], 'template' => $template]);
        printCheck($result->success && $result->data['request_id'] === 91, 'Accept existing label formats and barcode');
        printCheck($client->calls[1][0] === 'POST' && $client->calls[1][1] === '/v1/parcels/generate-label'
            && $client->calls[1][2] === ['parcel_ids' => [101]] && $client->calls[1][3]['template'] === $template, 'Exact provider endpoint, body and template (deduplicated IDs)');
    }
    $client->calls = [];
    $client->rows = [$paid, array_replace($paid, ['id' => 102, 'invoice' => ['status' => 1]]), array_replace($paid, ['id' => 103, 'tracking_code' => ''])];
    $result = invokePrint('bulkLabelByDate', ['_wpnonce' => 'synthetic', 'from_jalali' => '1405/07/28', 'to_jalali' => '1405/07/28', 'template' => 'barcode', 'market_names' => ['website']]);
    printCheck($result->success, 'Barcode date-range flow succeeds');
    printCheck($client->calls[1][2] === ['parcel_ids' => [101]] && $client->calls[1][3]['template'] === 'barcode', 'Date printing excludes unpaid and missing-tracking parcels');
    printCheck($client->calls[0][2]['market_name'] === ['website'], 'Preserve market scope for date printing');
    foreach ([['invoice' => ['status' => 1]], ['tracking_code' => ''], ['status' => ['id' => 44]], ['status' => ['id' => 7]]] as $invalid) {
        $client->calls = [];
        $client->rows = [array_replace($paid, $invalid)];
        $result = invokePrint('bulkLabel', ['_wpnonce' => 'synthetic', 'parcel_ids' => [101], 'template' => 'barcode']);
        printCheck(!$result->success && $result->data['request_dispatched'] === false && count($client->calls) === 1, 'Barcode keeps authoritative paid/tracking/cancellation guards');
    }
    $client->rows = [];
    $result = invokePrint('bulkLabel', ['_wpnonce' => 'synthetic', 'parcel_ids' => [101], 'template' => 'barcode']);
    printCheck(!$result->success && $result->data['request_dispatched'] === false, 'Unowned parcel cannot print');
    foreach ([[], ['0'], ['1oops'], range(1, 101)] as $ids) {
        $client->calls = [];
        $result = invokePrint('bulkLabel', ['_wpnonce' => 'synthetic', 'parcel_ids' => $ids, 'template' => 'barcode']);
        printCheck(!$result->success && $client->calls === [], 'Invalid/empty/oversized selections do not call API');
    }
    foreach ([['nonce' => 'wrong', 'allowed' => true], ['nonce' => 'synthetic', 'allowed' => false]] as $case) {
        $allowed = $case['allowed']; $client->calls = [];
        $result = invokePrint('bulkLabel', ['_wpnonce' => $case['nonce'], 'parcel_ids' => [101], 'template' => 'barcode']);
        printCheck(!$result->success && $client->calls === [], 'Permission and nonce are mandatory');
    }
    $allowed = true;
    $client->calls = [];
    $result = invokePrint('labelPoll', ['_wpnonce' => 'synthetic', 'request_id' => '91']);
    printCheck($result->success && $result->data['status'] === 'completed' && $result->data['url'] === 'https://example.test/barcodes.pdf', 'Completed barcode PDF uses existing polling contract');
    printCheck($client->calls === [['GET', '/v1/parcels/generate-label', ['request_id' => 91]]], 'Poll the exact job, no new generation or payment');
    echo "SalamGate bulk printing: {$checks} checks passed\n";
}
