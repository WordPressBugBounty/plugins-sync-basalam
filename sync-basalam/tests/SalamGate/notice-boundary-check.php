<?php

/** Offline regression: WordPress notices must be outside SalamGate's CSS boundary. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ABSPATH', __DIR__ . '/wordpress/');
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

function get_option($key, $default = false) {
    return $key === 'woosalam_salam_gate_site_enabled' ? 0 : $default;
}
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_html($value) { return esc_attr($value); }
function esc_url($value) { return esc_attr($value); }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function wp_unslash($value) { return $value; }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)); }
function sanitize_text_field($value) { return trim(strip_tags($value)); }

function renderNoticePage(string $view): string {
    $data = ['state' => 'disabled', 'view' => $view, 'shops' => [], 'message' => ''];
    ob_start();
    include dirname(__DIR__, 2) . '/templates/SalamGate/'
        . ($view === 'parcels' ? 'parcels-page.php' : 'page-router.php');
    return (string) ob_get_clean();
}

$checks = 0;
function noticeCheck(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}

foreach (['', 'send', 'settings', 'activation', 'tracking', 'calculator', 'post-offices', 'parcels'] as $view) {
    $document = new DOMDocument();
    $previousErrors = libxml_use_internal_errors(true);
    $document->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body>'
        . renderNoticePage($view) . '</body></html>');
    libxml_clear_errors();
    libxml_use_internal_errors($previousErrors);
    $xpath = new DOMXPath($document);
    $hasPageClass = 'contains(concat(" ", normalize-space(@class), " "), " salam-gate-page ")';
    $hasAnchorClass = 'contains(concat(" ", normalize-space(@class), " "), " wp-header-end ")';
    $anchors = $xpath->query('//*[' . $hasAnchorClass . ']');
    noticeCheck($anchors->length === 1, 'Exactly one WordPress notice anchor on ' . $view);
    $anchor = $anchors->item(0);
    noticeCheck($xpath->query('ancestor::*[' . $hasPageClass . ']', $anchor)->length === 0,
        'WordPress notice anchor cannot inherit SalamGate styles on ' . $view);
    noticeCheck($xpath->query('following-sibling::*[' . $hasPageClass . ']', $anchor)->length === 1,
        'The notice anchor must precede the intact SalamGate shell on ' . $view);

    // Mirror wp-admin/js/common.js: notices are inserted after .wp-header-end.
    $notice = $document->createElement('div');
    $notice->setAttribute('class', 'notice notice-warning');
    $button = $document->createElement('button', 'Synthetic notice action');
    $button->setAttribute('class', 'button button-primary');
    $notice->appendChild($button);
    $anchor->parentNode->insertBefore($notice, $anchor->nextSibling);
    noticeCheck($xpath->query('ancestor::*[' . $hasPageClass . ']', $button)->length === 0,
        'Relocated notice buttons must retain their original styling on ' . $view);
    noticeCheck($xpath->query('//*[' . $hasPageClass . ']//h1')->length === 1,
        'Keep the SalamGate page title inside its styled shell on ' . $view);
}

echo "SalamGate notice boundary: {$checks} checks passed\n";
