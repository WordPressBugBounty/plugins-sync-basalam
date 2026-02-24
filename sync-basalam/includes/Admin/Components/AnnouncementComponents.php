<?php

namespace SyncBasalam\Admin\Components;

defined('ABSPATH') || exit;

class AnnouncementComponents
{
    public static function renderPanel(): void
    {
?>
        <div id="sync-basalam-announcement-root" class="sync-basalam-announcement-root" dir="rtl">
            <button type="button" id="sync-basalam-announcement-trigger" class="sync-basalam-announcement-trigger" aria-label="اعلان‌های ووسلام">
                <span class="dashicons dashicons-bell"></span>
                <span id="sync-basalam-announcement-counter" class="sync-basalam-announcement-counter">0</span>
            </button>

            <aside id="sync-basalam-announcement-panel" class="sync-basalam-announcement-panel" aria-hidden="true">
                <header class="sync-basalam-announcement-header">
                    <div>
                        <h3 class="sync-basalam-announcement-title basalam-h" style="text-align: right;">اخبار ووسلام</h3>
                        <p class="sync-basalam-announcement-subtitle">جدیدترین اطلاعیه‌ها و بروزرسانی‌ها</p>
                    </div>
                    <button type="button" id="sync-basalam-announcement-close" class="sync-basalam-announcement-close" aria-label="بستن اعلان‌ها">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                </header>

                <div id="sync-basalam-announcement-list" class="sync-basalam-announcement-list"></div>

                <footer class="sync-basalam-announcement-footer">
                    <button type="button" id="sync-basalam-announcement-prev" class="sync-basalam-announcement-nav">قبلی</button>
                    <span id="sync-basalam-announcement-page" class="sync-basalam-announcement-page">1 / 1</span>
                    <button type="button" id="sync-basalam-announcement-next" class="sync-basalam-announcement-nav">بعدی</button>
                </footer>
            </aside>

            <div id="sync-basalam-announcement-overlay" class="sync-basalam-announcement-overlay"></div>
        </div>
<?php
    }
}

