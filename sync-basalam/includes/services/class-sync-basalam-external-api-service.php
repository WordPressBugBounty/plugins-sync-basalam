<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_External_API_Service
{
    private array $headers;

    public function __construct()
    {
        $this->headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'user-agent' => 'Wp-Basalam',
            'referer' => get_site_url(),
        ];
    }

    public function send_post_request($url, $data, $headers = [])
    {
        $headers = array_merge($this->headers, $headers);

        $response = wp_remote_post($url, array(

            'body'    => $data,
            'headers' => $headers,
        ));

        if (is_wp_error($response)) {
            $error_code = $response->get_error_code();
            $error_message = $response->get_error_message();

            if ($error_code === 'timeout') {
                sync_basalam_Logger::error("ارسال درخواست به آدرس $url به دلیل timeout ناموفق بود ، این خطا به علت اختلالات api های باسلام یا هاست سایت شماست.");
            } else {
                sync_basalam_Logger::error("درخواست API برای آدرس $url با خطا مواجه شد. پاسخ: $error_message");
            }

            return [
                'body' => null,
                'status_code' => 500
            ];
        }

        if (is_wp_error($response)) {
            sync_basalam_Logger::error("درخواست API برای آدرس " . $url . " با خطا مواجه شد. پاسخ: " . $response->get_error_message());
            return [
                'body' => null,
                'status_code' => 500
            ];
        }

        $body = wp_remote_retrieve_body($response);
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code == 401) {
            $data = [
                sync_basalam_Admin_Settings::TOKEN => '',
                sync_basalam_Admin_Settings::REFRESH_TOKEN => '',
            ];
            sync_basalam_Admin_Settings::update_settings($data);
            sync_basalam_QueueManager::cancel_all_tasks_group('sync_basalam_plugin_create_product');
            sync_basalam_QueueManager::cancel_all_tasks_group('sync_basalam_plugin_update_product');
            sync_basalam_QueueManager::cancel_all_tasks_group('sync_basalam_plugin_connect_auto_product');
        }

        return [
            'body' => json_decode($body, true),
            'status_code' => $status_code
        ];
    }

    public function send_get_request($url, $headers = [], $max_retries = 3)
    {
        $headers = array_merge($this->headers, $headers);
        $attempt = 0;
        $response = null;

        while ($attempt < $max_retries) {
            $response = wp_remote_get($url, array(
                'timeout'   => 30,
                'headers'   => $headers,
            ));

            if (!is_wp_error($response)) {
                break;
            }

            $error_code = $response->get_error_code();
            $error_message = $response->get_error_message();

            if (
                $error_code === 'http_request_failed' &&
                (strpos($error_message, 'cURL error 28') !== false ||
                    strpos($error_message, 'Operation timed out') !== false ||
                    strpos($error_message, 'Connection timed out') !== false)
            ) {
                $attempt++;
                if ($attempt < $max_retries) {
                    sync_basalam_Logger::debug("تلاش $attempt از $max_retries برای درخواست به $url به دلیل timeout ناموفق بود. در حال تلاش مجدد...");
                    sleep(2 * $attempt);
                } else {
                    sync_basalam_Logger::error("درخواست به $url پس از $max_retries تلاش به دلیل timeout ناموفق بود.");
                    return [
                        'data' => null,
                        'status_code' => 500,
                        'timeout_error' => true
                    ];
                }
            } else {
                sync_basalam_Logger::error("درخواست API برای آدرس " . $url . " با خطا مواجه شد. پاسخ: " . $error_message);
                return false;
            }
        }

        $body = wp_remote_retrieve_body($response);
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code == 401) {
            $data = [
                sync_basalam_Admin_Settings::TOKEN => '',
                sync_basalam_Admin_Settings::REFRESH_TOKEN => '',
            ];
            sync_basalam_Admin_Settings::update_settings($data);
            sync_basalam_QueueManager::cancel_all_tasks_group('sync_basalam_plugin_create_product');
            sync_basalam_QueueManager::cancel_all_tasks_group('sync_basalam_plugin_update_product');
            sync_basalam_QueueManager::cancel_all_tasks_group('sync_basalam_plugin_connect_auto_product');
        }

        return [
            'data' => json_decode($body, true),
            'status_code' => $status_code,
        ];
    }

    public function send_patch_request($url, $data, $headers = [])
    {
        $headers = array_merge($this->headers, $headers);
        $response = wp_remote_request($url, array(
            'method' => 'PATCH',

            'body'      => $data,
            'headers'   => $headers,
        ));

        if (is_wp_error($response)) {
            sync_basalam_Logger::error("درخواست API برای آدرس " . $url . " با خطا مواجه شد. پاسخ: " . $response->get_error_message());
            return [
                'body' => null,
                'status_code' => 500
            ];
        }

        $body = wp_remote_retrieve_body($response);
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code == 401) {
            $data = [
                sync_basalam_Admin_Settings::TOKEN => '',
                sync_basalam_Admin_Settings::REFRESH_TOKEN => '',
            ];
            sync_basalam_Admin_Settings::update_settings($data);
            sync_basalam_QueueManager::cancel_all_tasks_group('sync_basalam_plugin_create_product');
            sync_basalam_QueueManager::cancel_all_tasks_group('sync_basalam_plugin_update_product');
            sync_basalam_QueueManager::cancel_all_tasks_group('sync_basalam_plugin_connect_auto_product');
        }
        return [
            'body' => json_decode($body, true),
            'status_code' => $status_code
        ];
    }

    public function send_put_request($url, $data, $headers = [])
    {
        $headers = array_merge($this->headers, $headers);
        $response = wp_remote_request($url, array(
            'method' => 'PUT',

            'body'      => $data,
            'headers'   => $headers,
        ));

        if (is_wp_error($response)) {
            sync_basalam_Logger::error("درخواست API برای آدرس " . $url . " با خطا مواجه شد. پاسخ: " . $response->get_error_message());
            return [
                'body' => null,
                'status_code' => 500
            ];
        }

        $body = wp_remote_retrieve_body($response);
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code == 401) {
            $data = [
                sync_basalam_Admin_Settings::TOKEN => '',
                sync_basalam_Admin_Settings::REFRESH_TOKEN => '',
            ];
            sync_basalam_Admin_Settings::update_settings($data);
            sync_basalam_QueueManager::cancel_all_tasks_group('sync_basalam_plugin_create_product');
            sync_basalam_QueueManager::cancel_all_tasks_group('sync_basalam_plugin_update_product');
            sync_basalam_QueueManager::cancel_all_tasks_group('sync_basalam_plugin_connect_auto_product');
        }
        return [
            'body' => json_decode($body, true),
            'status_code' => $status_code
        ];
    }

    public function send_delete_request($url, $headers = [], $data = null)
    {
        $headers = array_merge($this->headers, $headers);
        $response = wp_remote_request($url, array(
            'method' => 'DELETE',

            'headers'   => $headers,
            'body'      => $data,
        ));

        if (is_wp_error($response)) {
            sync_basalam_Logger::error("درخواست API برای آدرس " . $url . " با خطا مواجه شد. پاسخ: " . $response->get_error_message());
            return [
                'body' => null,
                'status_code' => 500
            ];
        }

        $body = wp_remote_retrieve_body($response);
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code == 401) {
            $data = [
                sync_basalam_Admin_Settings::TOKEN => '',
                sync_basalam_Admin_Settings::REFRESH_TOKEN => '',
            ];
            sync_basalam_Admin_Settings::update_settings($data);
            sync_basalam_QueueManager::cancel_all_tasks_group('sync_basalam_plugin_create_product');
            sync_basalam_QueueManager::cancel_all_tasks_group('sync_basalam_plugin_update_product');
            sync_basalam_QueueManager::cancel_all_tasks_group('sync_basalam_plugin_connect_auto_product');
        }
        return [
            'body' => json_decode($body, true),
            'status_code' => $status_code
        ];
    }
    public function upload_file_request($url, $local_file, $data = [], $headers = [])
    {
        if (!file_exists($local_file)) {
            sync_basalam_Logger::error("فایل وجود ندارد: " . $local_file);
            return false;
        } elseif (!is_readable($local_file)) {
            sync_basalam_Logger::error("فایل قابل خواندن نیست: " . $local_file);
            return false;
        } else {
            $file_content = file_get_contents($local_file);
            if ($file_content === false) {
                sync_basalam_Logger::error("file_get_contents شکست خورد: " . $local_file);
                return false;
            }
        }

        $boundary = wp_generate_password(24);

        $payload = $this->make_payload_upload_file_request($data, $local_file, $boundary);

        $headers  = array_merge(
            $headers,
            array(
                'content-type' => 'multipart/form-data; boundary=' . $boundary,
                'user-agent' => 'Wp-Basalam',
                'Accept' => 'application/json',
                'referer' => get_site_url(),
            )
        );

        $response = wp_remote_post(
            $url,
            array(

                'headers'    => $headers,
                'body'       => $payload,
            )
        );

        if (is_wp_error($response)) {
            sync_basalam_Logger::error("درخواست API برای آدرس " . $url  . " با خطا مواجه شد. پاسخ: " . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code == 401) {
            $data = [
                sync_basalam_Admin_Settings::TOKEN => '',
                sync_basalam_Admin_Settings::REFRESH_TOKEN => '',
            ];
            sync_basalam_Admin_Settings::update_settings($data);
            sync_basalam_QueueManager::cancel_all_tasks_group('sync_basalam_plugin_create_product');
            sync_basalam_QueueManager::cancel_all_tasks_group('sync_basalam_plugin_update_product');
            sync_basalam_QueueManager::cancel_all_tasks_group('sync_basalam_plugin_connect_auto_product');
        }

        return [
            'body' => json_decode($body, true),
            'status_code' => $status_code
        ];
    }

    private function make_payload_upload_file_request($data, $local_file, $boundary)
    {
        $payload = '';
        $eol = "\r\n";

        foreach ($data as $name => $value) {
            $payload .= '--' . $boundary . $eol;
            $payload .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol;
            $payload .= $value . $eol;
        }

        if ($local_file) {
            $filename = basename($local_file);
            $file_content = file_get_contents($local_file);

            $filetype_info = wp_check_filetype($local_file);
            $mime_type = $filetype_info['type'] ?? 'application/octet-stream';


            $payload .= '--' . $boundary . $eol;
            $payload .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . $eol;
            $payload .= 'Content-Type: ' . $mime_type . $eol;
            $payload .= 'Content-Transfer-Encoding: binary' . $eol . $eol;
            $payload .= $file_content . $eol;
        }

        $payload .= '--' . $boundary . '--' . $eol;

        return $payload;
    }
}
