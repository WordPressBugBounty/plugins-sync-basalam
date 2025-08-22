<?php
if (! defined('ABSPATH')) exit;
class Sync_basalam_Text_CLeaner
{
    static function convert_html_to_plain_text($data)
    {
        $data = preg_replace('/\[[^\]]+\]/', '', $data);

        $data = wp_strip_all_tags($data);

        $data = preg_replace('/\bhttps?:\/\/\S+/i', '', $data);

        $data = preg_replace('/\.(jpg|jpeg|png|gif|mp4|webm|avi|mov)\b/i', '', $data);

        $data = str_replace('&nbsp;', '', $data);

        return trim($data);
    }
}
