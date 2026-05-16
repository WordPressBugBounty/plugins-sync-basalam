<?php

namespace SyncBasalam\Admin\Product\elements\SingleProduct;

use SyncBasalam\Services\Products\VideoSourceResolver;
use SyncBasalam\Utilities\ProductMetaKey;

defined('ABSPATH') || exit;

class VideoField
{
    private const META_BOX_ID = 'sync_basalam_product_video_box';

    public static function shouldRender(): bool
    {
        return VideoSourceResolver::isUsingPluginBox();
    }

    public static function registerMetaBox(): void
    {
        if (!self::shouldRender()) {
            return;
        }

        add_meta_box(
            self::META_BOX_ID,
            'ویدیو محصول (باسلام)',
            [self::class, 'render'],
            'product',
            'side',
            'low'
        );
    }

    public static function render(): void
    {
        if (!self::shouldRender()) {
            return;
        }

        wp_enqueue_media();

        $productId = (int) get_the_ID();
        $metaKey = ProductMetaKey::basalamProductVideo();
        $attachmentId = (int) get_post_meta($productId, $metaKey, true);
        $previewUrl = $attachmentId ? wp_get_attachment_url($attachmentId) : '';

        wp_nonce_field('sync_basalam_save_video_field_action', '_sync_basalam_video_field_nonce');

        echo '<div class="basalam-video-field">';
        echo '<p style="color:#666;font-size:12px;margin:0 0 8px;">یک ویدیو از کتابخانه رسانه‌ها انتخاب کنید تا هنگام همگام سازی با باسلام ارسال شود.</p>';

        echo '<input type="hidden" id="basalam_product_video_id" name="' . esc_attr($metaKey) . '" value="' . esc_attr((string) $attachmentId) . '">';

        echo '<div id="basalam_product_video_preview" style="margin-bottom:8px;">';
        if ($previewUrl) {
            echo '<video src="' . esc_url($previewUrl) . '" controls style="max-width:100%;height:auto;display:block;"></video>';
        }
        echo '</div>';

        echo '<button type="button" class="button button-secondary" id="basalam_product_video_select">';
        echo $attachmentId ? 'تغییر ویدیو' : 'انتخاب ویدیو';
        echo '</button> ';

        echo '<button type="button" class="button-link delete" id="basalam_product_video_remove" style="' . ($attachmentId ? '' : 'display:none;') . '">حذف ویدیو</button>';

        echo '</div>';

        self::renderInlineScript();
    }

    public static function save(int $postId): void
    {
        if (
            !isset($_POST['_sync_basalam_video_field_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_sync_basalam_video_field_nonce'])), 'sync_basalam_save_video_field_action')
        ) {
            return;
        }

        if (!VideoSourceResolver::isUsingPluginBox()) {
            return;
        }

        $metaKey = ProductMetaKey::basalamProductVideo();
        $value = isset($_POST[$metaKey]) ? absint(wp_unslash($_POST[$metaKey])) : 0;

        if ($value > 0) {
            update_post_meta($postId, $metaKey, $value);
        } else {
            delete_post_meta($postId, $metaKey);
        }
    }

    private static function renderInlineScript(): void
    {
        ?>
        <script>
        (function ($) {
            $(function () {
                var frame;
                var $idInput = $('#basalam_product_video_id');
                var $preview = $('#basalam_product_video_preview');
                var $removeBtn = $('#basalam_product_video_remove');
                var $selectBtn = $('#basalam_product_video_select');

                $selectBtn.on('click', function (e) {
                    e.preventDefault();

                    if (frame) {
                        frame.open();
                        return;
                    }

                    frame = wp.media({
                        title: 'انتخاب ویدیو محصول',
                        button: { text: 'استفاده از این ویدیو' },
                        library: { type: 'video' },
                        multiple: false
                    });

                    frame.on('select', function () {
                        var attachment = frame.state().get('selection').first().toJSON();
                        $idInput.val(attachment.id);
                        $preview.html('<video src="' + attachment.url + '" controls style="max-width:100%;height:auto;display:block;"></video>');
                        $removeBtn.show();
                        $selectBtn.text('تغییر ویدیو');
                    });

                    frame.open();
                });

                $removeBtn.on('click', function (e) {
                    e.preventDefault();
                    $idInput.val('');
                    $preview.empty();
                    $removeBtn.hide();
                    $selectBtn.text('انتخاب ویدیو');
                });
            });
        })(jQuery);
        </script>
        <?php
    }
}
