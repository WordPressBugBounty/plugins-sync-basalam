<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Admin_Onboarding
{
    
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

    
    public function sync_basalam_process_onboarding_form()
    {
            wp_redirect(sync_basalam_Admin_Settings::get_static_settings('url_req_token'));
            exit;
    }

    
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
                'title' => 'دریافت دسترسی از باسلام',
                'content' => function () {
                    ob_start();
                    require sync_basalam_configure()->template_path('admin/onboarding/step2.php');
                    return ob_get_clean();
                }
            ],
            3 => [
                'title' => 'تکمیل فرایند',
                'content' => function () {
                    ob_start();
                    require sync_basalam_configure()->template_path('admin/onboarding/step3.php');
                    return ob_get_clean();
                }
            ]
        ];
    }
}
