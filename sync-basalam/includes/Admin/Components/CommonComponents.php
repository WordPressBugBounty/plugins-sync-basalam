<?php

namespace SyncBasalam\Admin\Components;

defined('ABSPATH') || exit;

class CommonComponents
{
    public static function renderIcon($icon)
    {
        return sprintf(
            '<span class="dashicons %s basalom-icon-medium"></span>',
            esc_attr($icon)
        );
    }

    public static function renderInfoPopup($content, $unique_id = '')
    {
        $info_icon_url = syncBasalamPlugin()->assetsUrl() . "/icons/info-black.svg";
        $modal_id = 'basalam-info-modal-' . $unique_id;

        return sprintf(
            '<div class="basalam-info-trigger" data-modal-id="%s">'
                . '<img src="%s" alt="اطلاعات" class="basalam-info-icon" title="برای مشاهده توضیحات کلیک کنید">'
                . '</div>'
                . '<div id="%s" class="basalam-info-modal basalam-modal-display-none">'
                . '<div class="basalam-info-modal-overlay"></div>'
                . '<div class="basalam-info-modal-content">'
                . '<div class="basalam-info-modal-header">'
                . '<h3 class="basalam-modal-header-text">راهنما</h3>'
                . '<span class="basalam-info-modal-close">&times;</span>'
                . '</div>'
                . '<div class="basalam-info-modal-body">%s</div>'
                . '</div>'
                . '</div>',
            esc_attr($modal_id),
            esc_url($info_icon_url),
            esc_attr($modal_id),
            esc_html($content)
        );
    }

    public static function renderLabelWithTooltip($label_text, $tooltip_content, $position = 'top')
    {
        $unique_id = sanitize_title($label_text);

        return sprintf(
            '<div class="basalam-label-container">'
                . '<label class="basalam-label">'
                . '<span class="basalam-label-text">%s</span>'
                . '%s'
                . '</label>'
                . '</div>',
            esc_html($label_text),
            self::renderInfoPopup($tooltip_content, $unique_id)
        );
    }

    public static function renderBtn($text, $name, $product_id, $nonce_name)
    {
        $nonce = wp_create_nonce($nonce_name);
        echo '<button class="basalam-button basalam-action-button basalam-p basalam-a"
            data-url="' . esc_url(admin_url('admin-post.php')) . '"
            data-action="' . esc_attr($name) . '"
            data-product-id="' . esc_attr($product_id) . '"
            data-_wpnonce="' . esc_attr($nonce) . '">' . esc_html($text) . '</button>';
    }

    public static function renderLink($text, $link)
    {
        echo '<a href="' . esc_url($link) . '" target="_blank" class="basalam-button basalam-btn basalam-p basalam-a">' . esc_html($text) . '</a>';
    }

    public static function renderUnauthorizedError()
    {
        echo '<div class="basalam-container">
            <div class="basalam-error-message">
                <p class="basalam-p">دسترسی شما صحیح نیست، فرایند دریافت دسترسی را مجددا انجام دهید و در صورت مشکل با پشتیبانی ارتباط برقرار کنید.</p>
            </div>
        </div>';
    }

    public static function renderFaqByCategory($categories)
    {
        foreach ($categories as $category) {
            $faqs = \SyncBasalam\Admin\Faq::getFaqByCategory($category);
            if (empty($faqs)) {
                continue;
            }
            echo '<div class="basalam-faq-category" data-category="' . esc_attr($category) . '">';
            foreach ($faqs as $faq) {
                echo '
                <div class="basalam-faq-item">
                    <div class="basalam-faq-question">
                        <h3>' . esc_html($faq['question']) . '</h3>
                        <span class="basalam-faq-toggle">+</span>
                    </div>
                    <div class="basalam-faq-answer">
                        <p>' . esc_html($faq['answer']) . '</p>
                    </div>
                </div>
            ';
            }
            echo '</div>';
        }
    }
}
