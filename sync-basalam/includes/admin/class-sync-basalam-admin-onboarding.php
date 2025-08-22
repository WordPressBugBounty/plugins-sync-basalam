<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Admin_Onboarding
{
    // Get current onboarding step
    public function sync_basalam_get_current_step()
    {
        if (isset($_POST['sync_basalam_onboarding_nonce']) && !check_admin_referer('sync_basalam_onboarding_action', 'sync_basalam_onboarding_nonce')) {
            die('دسترسی مجاز نیست.');
        }
        return isset($_GET['step']) ? intval(sanitize_text_field(wp_unslash($_GET['step']))) : 1;
    }

    public function sync_basalam_render_onboarding_page()
    {
        $current_step = $this->sync_basalam_get_current_step();
        $steps = $this->sync_basalam_get_onboarding_steps();
        $total_steps = count($steps);

        if ($current_step === $total_steps) {
            update_option('sync_basalam_onboarding_completed', true);
        }

        require_once sync_basalam_configure()->template_path('/admin/onboarding/template-onboarding-page.php');
    }

    // Process onboarding forms
    public function sync_basalam_process_onboarding_form()
    {
        if (isset($_POST['webhook_id'])) {
            // Nonce verification
            $nonce = isset($_POST['sync_basalam_webhook_nonce']) ? sanitize_text_field(wp_unslash($_POST['sync_basalam_webhook_nonce'])) : '';
            if (!wp_verify_nonce($nonce, 'sync_basalam_save_webhook_action')) {
                wp_die('درخواست نامعتبر است.');
            }

            // Sanitize webhook_id input
            $webhook_id = sanitize_text_field(wp_unslash($_POST['webhook_id']));

            if (empty($webhook_id)) {
                wp_die('لطفاً یک شناسه وب‌هوک وارد کنید.');
            }

            // Save webhook ID to settings
            $data = [
                sync_basalam_Admin_Settings::WEBHOOK_ID => $webhook_id,
            ];

            sync_basalam_Admin_Settings::update_settings($data);
            wp_redirect(sync_basalam_Admin_Settings::get_static_settings('url_req_token'));
            exit;
        }
    }

    public function handle_delete_webhook()
    {
        // Nonce verification
        $nonce = isset($_POST['sync_basalam_delete_webhook_nonce']) ? sanitize_text_field(wp_unslash($_POST['sync_basalam_delete_webhook_nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'sync_basalam_delete_webhook_action')) {
            wp_die('درخواست نامعتبر است.');
        }

        // Clear webhook and token settings
        $data = [
            sync_basalam_Admin_Settings::WEBHOOK_ID => '',
            sync_basalam_Admin_Settings::REFRESH_TOKEN => '',
            sync_basalam_Admin_Settings::TOKEN => '',
        ];
        sync_basalam_Admin_Settings::update_settings($data);

        // Sanitize and redirect to the appropriate page
        $redirect = !empty($_POST['redirect_to']) ? esc_url_raw(wp_unslash($_POST['redirect_to'])) : admin_url();
        wp_redirect($redirect);
        exit;
    }


    // Define onboarding steps
    public function sync_basalam_get_onboarding_steps()
    {
        return [
            1 => [
                'title' => 'به ووسلام خوش آمدید',
                'content' => function () {
                    ob_start();
                    require sync_basalam_configure()->template_path('admin/onboarding/step1.php');
                    sync_basalam_Admin_Settings::get_oauth_data();
                    return ob_get_clean();
                }
            ],
            2 => [
                'title' => 'دریافت وب هوک',
                'content' => function () {
                    ob_start();
                    require sync_basalam_configure()->template_path('admin/onboarding/step2.php');
                    return ob_get_clean();
                }
            ],
            3 => [
                'title' => 'دریافت دسترسی از باسلام',
                'content' => function () {
                    ob_start();
                    require sync_basalam_configure()->template_path('admin/onboarding/step3.php');
                    return ob_get_clean();
                }
            ],
            4 => [
                'title' => 'تکمیل فرایند',
                'content' => function () {
                    ob_start();
                    require sync_basalam_configure()->template_path('admin/onboarding/step4.php');
                    return ob_get_clean();
                }
            ]
        ];
    }
}
