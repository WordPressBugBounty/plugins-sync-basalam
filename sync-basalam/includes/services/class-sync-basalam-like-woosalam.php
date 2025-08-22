<?php
if (! defined('ABSPATH')) exit;

class Sync_Basalam_Like_Woosalam
{
    private ?string $token;
    private string $likeUrl;
    private string $getLikeUrl;
    private sync_basalam_External_API_Service $apiService;

    public function __construct()
    {
        $this->token = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::TOKEN);
        $this->likeUrl = sync_basalam_Admin_Settings::get_static_settings('url_like_woo_on_basalam');
        $this->getLikeUrl = sync_basalam_Admin_Settings::get_static_settings('get_like_status_url_from_basalam');
        $this->apiService = new sync_basalam_External_API_Service();
    }

    public function like()
    {
        $headers = [
            'authorization' => $this->token
        ];

        if (!$this->hasAlreadyLiked()) {
            $response = $this->apiService->send_post_request($this->likeUrl, [], $headers);

            if ($response['status_code'] == 401) {
                $this->renderErrorNotice('لطفا ابتدا دسترسی‌های لازم باسلام را دریافت نمایید.');
                return;
            }
        }

        update_option('sync_basalam_like', true);
    }

    private function hasAlreadyLiked()
    {
        $headers = [
            'authorization' => $this->token
        ];

        $response = $this->apiService->send_get_request($this->getLikeUrl, $headers);

        return isset($response['data']['liked']) && $response['data']['liked'] === true;
    }

    private function renderErrorNotice(string $message)
    {
        echo sprintf(
            '<div class="notice notice-error" style="display: flex; gap:10px;">
                <p class="basalam-p">%s</p>
            </div>',
            esc_html($message)
        );
    }
}
