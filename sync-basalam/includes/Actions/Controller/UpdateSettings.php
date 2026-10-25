<?php

namespace SyncBasalam\Actions\Controller;

use SyncBasalam\Admin\Settings;
use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Admin\Settings\SettingsPageHandler;

defined('ABSPATH') || exit;
class UpdateSettings extends ActionController
{
    public function __invoke()
    {
        if (wp_doing_ajax()) {
            return $this->saveAjax();
        }

        try {
            Settings::saveSettings();
        } catch (\Exception $e) {
            wp_die('Error saving settings: ' . esc_html($e->getMessage()));
        }
    }

    private function saveAjax()
    {
        $submitted = $_POST['sync_basalam_settings'] ?? null;
        if (!is_array($submitted) || !$submitted || array_filter($submitted, 'is_array')
            || isset($_POST['get_token']) || isset($_POST['sync_basalam_toggle_product'])) {
            return wp_send_json_error(['message' => 'تنظیمات ارسالی نامعتبر است.'], 400);
        }

        // Only settings displayed by these forms may be echoed back to the browser.
        $editable = array_fill_keys(SettingsConfig::EDITABLE_SETTINGS, true);
        if (array_diff_key($submitted, $editable)) {
            return wp_send_json_error(['message' => 'تنظیمات ارسالی نامعتبر است.'], 400);
        }

        try {
            $before = Settings::getSettings();
            if (!Settings::saveSettings()) {
                SettingsPageHandler::pullValidationError();
                return wp_send_json_error(['message' => SettingsConfig::CUSTOM_PRODUCT_UPDATE_REQUIRED_MESSAGE], 422);
            }
            $after = Settings::getSettings();
            $expected = Settings::sanitizeSettings(array_map('sanitize_text_field', wp_unslash($submitted)));
            foreach (array_keys($submitted) as $key) {
                if ($this->comparableValue($key, $after[$key]) !== $this->comparableValue($key, $expected[$key])) {
                    throw new \RuntimeException('Settings were not persisted.');
                }
            }
        } catch (\Exception $e) {
            return wp_send_json_error(['message' => 'ذخیره تنظیمات انجام نشد. لطفاً دوباره تلاش کنید.'], 500);
        }

        $productSettingsChanged = false;
        foreach (SettingsConfig::PRODUCT_SETTINGS as $key) {
            if ($this->comparableValue($key, $before[$key]) !== $this->comparableValue($key, $after[$key])) {
                $productSettingsChanged = true;
                break;
            }
        }

        return wp_send_json_success([
            'message' => 'تنظیمات با موفقیت ذخیره شد.',
            'product_settings_changed' => $productSettingsChanged,
            'settings' => array_intersect_key($after, $submitted),
        ]);
    }

    private function comparableValue(string $key, $value): string
    {
        if (in_array($key, array_merge(SettingsConfig::CUSTOM_PRODUCT_UPDATE_FIELDS, [
            SettingsConfig::PRODUCT_ATTRIBUTE_SUFFIX_ENABLED,
            SettingsConfig::ADD_ATTR_TO_DESC_PRODUCT,
            SettingsConfig::ADD_SHORT_DESC_TO_DESC_PRODUCT,
            SettingsConfig::TASKS_PER_MINUTE_AUTO,
        ]), true)) {
            return in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true) ? '1' : '0';
        }

        return (string) $value;
    }
}
