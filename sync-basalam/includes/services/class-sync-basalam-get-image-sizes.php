<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Get_Image_Sizes
{
    public function get_image_sizes(array $images)
    {
        foreach ($images as $image) {
            $url = $image['url'];

            $upload_dir = wp_upload_dir();
            $base_url = $upload_dir['baseurl'];
            $base_dir = $upload_dir['basedir'];

            if (strpos($url, $base_url) !== false) {
                $relative_path = str_replace($base_url, '', $url);
                $file_path = $base_dir . $relative_path;

                if (file_exists($file_path)) {
                    $filesize = filesize($file_path);
                    $size_in_kb = round($filesize / 1024, 2);
                }
            }
        }
    }
}
