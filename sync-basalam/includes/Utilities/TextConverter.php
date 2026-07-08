<?php

namespace SyncBasalam\Utilities;

defined('ABSPATH') || exit;
class TextConverter
{
    public static function convertHtmlToPlainText($data)
    {
        $data = preg_replace('/\[[^\]]+\]/', '', $data);

        $data = wp_strip_all_tags($data);

        $data = html_entity_decode($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $data = preg_replace('/\bhttps?:\/\/\S+/i', '', $data);

        $data = preg_replace('/\.(jpg|jpeg|png|gif|mp4|webm|avi|mov)\b/i', '', $data);

        $data = str_replace(["\xC2\xA0", '&nbsp;'], '', $data);

        return trim($data);
    }
}
