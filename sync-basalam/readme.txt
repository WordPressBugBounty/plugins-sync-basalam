=== ووسلام - همگام سازی ووکامرس و باسلام ===
Contributors: hamsalam  
Tags: woocommerce, basalam, woosalam, integration
Requires at least: 6.0  
Tested up to: 7.0
Requires PHP: 7.4 
Stable tag: 1.10.39
License: GPL-2.0-or-later  
License URI: https://www.gnu.org/licenses/gpl-2.0.html  

اتصال و همگام سازی ووکامرس و باسلام و اضافه کردن و بروزرسانی محصولات از ووکامرس به باسلام و همگام سازی اتوماتیک آن و مدیریت سفارشات باسلام در ووکامرس

== توضیحات فارسی ==

افزونه‌ی **ووسلام (همگام‌سازی ووکامرس و باسلام)** امکان همگام‌سازی دوطرفه بین فروشگاه ووکامرس شما و بازار باسلام را فراهم می‌کند.

با استفاده از این افزونه می‌توانید:
- محصولات ووکامرس را به غرفه باسلام اضافه و مدیریت کنید  
- سفارش‌های ثبت‌شده در باسلام را به صورت خودکار در ووکامرس دریافت و مدیریت کنید  
- قیمت و موجودی کالاها را به صورت لحظه‌ای همگام سازی کنید  
- سفارش‌های فروشگاه، باسلام و مبدأهای مستقل را با سلام‌گیت برای ارسال پستی آماده و هزینه ارسال را پرداخت کنید

== پرداخت با سلام‌پی / Salam Pay ==

درگاه سلام‌پی از «ووکامرس ← پیکربندی ← پرداخت‌ها ← سلام‌پی» در دسترس است. برای فعال‌سازی، کلید اتصال دریافتی از سلام‌پی را در تنظیمات وارد کنید. پرداخت معمولی و بلوکی ووکامرس، واحدهای ریال و تومان، نوار معرفی و نشان محصول، استعلام پرداخت و بازگشت وجه کامل و جزئی پشتیبانی می‌شوند.

تنظیمات، شناسه درگاه و callback نسخه مستقل حفظ شده‌اند. اگر افزونه مستقل سلام‌پی فعال باشد، همان افزونه مسئول پرداخت است؛ برای استفاده از نسخه داخلی ووسلام، نسخه مستقل را غیرفعال کنید و آن را حذف نکنید، چون حذف‌کننده نسخه مستقل تنظیمات مشترک را پاک می‌کند. دریافت پول و بازگشت وجه به کلید معتبر فروشنده نیاز دارند.

بازگشت وجه سلام‌پی غیرهمزمان است: ابتدا درخواست ثبت و سپس نتیجه استعلام می‌شود؛ ووکامرس فقط پس از تأیید نهایی، بازگشت وجه و موجودی انتخاب‌شده را ثبت می‌کند. پیام «در انتظار تأیید» به معنی ثبت درخواست است؛ آن را دوباره ارسال نکنید. وضعیت در جزئیات سفارش و عملیات «بررسی پرداخت و بازگشت وجه سلام‌پی» قابل پیگیری است. درخواست خودکار بازگشت مانده وجه هنگام لغو سفارش، تنظیم جداگانه دارد و پیش‌فرض خاموش است. پیگیری خودکار به اجرای WP-Cron وابسته است.

Salam Pay checkout is bundled with WooSalam, with classic/Blocks checkout, HPOS-compatible order storage, authenticated inquiry, amount checks, partial/full asynchronous refunds and optional storefront badges. Existing gateway settings and callback URLs are retained. The standalone plugin remains the sole owner while active; deactivate it to use the bundled gateway. Do not uninstall the standalone plugin after handover: its uninstaller deletes the shared settings. Refund acceptance is pending, not settlement; WooCommerce refunds are recorded after provider confirmation. Production transactions require a merchant client secret and live acceptance testing. See docs/salam-pay.md for the integration contract and reproducible local verification.

== ارسال مرسوله با سلام‌گیت / SalamGate shipping ==

سلام‌گیت از منوی «ووسلام ← ارسال مرسوله» و صفحه سفارش‌های ووکامرس، بدون نیاز به فعال‌بودن افزونه پرو، در دسترس است. ثبت و فعال‌سازی حساب، انتخاب آدرس فرستنده، ماشین‌حساب، مراکز تحویل، ارسال تکی و گروهی، ادامه پرداخت، چاپ لیبل و لغو مجاز مرسوله غیرباسلامی پشتیبانی می‌شوند. نیازمندی‌های این بخش همان نیازمندی‌های اصلی ووسلام است و ووکامرس باید فعال باشد.

هزینه ارسال سفارش باسلامی با روش ۱۹ از همان سفارش اصلی کسر می‌شود؛ مبدأهای دیگر از تراز غرفه یا کیف پول باسلام، روش ۲۰/۲۱، استفاده می‌کنند. گروه مختلط، چند پرداخت مستقل و متوالی است، نه یک تراکنش مشترک. فروشنده وزن نهایی را مرور و تأیید می‌کند؛ دریافت نرخ به‌تنهایی پرداخت نیست. نتیجه قطع‌شده مالی نیاز به بررسی دارد و خودکار تکرار نمی‌شود. رسید معتبر شرط چاپ است؛ لغو پستی، سفارش ووکامرس یا پرداخت خریدار را لغو نمی‌کند.

اگر پروِ دارای سلام‌گیت هم فعال باشد، همان پرو تنها مسئول این بخش می‌ماند و اجرای تکراری انجام نمی‌شود. با غیرفعال‌شدن پرو، نسخه عادی از همان نگاشت‌ها و سوابق موجود استفاده می‌کند؛ اطلاعات مرسوله و محافظ مالی پاک یا جابه‌جا نمی‌شوند. اتصال از حساب عادی ووسلام انجام می‌شود و برای اعطای مجوزهای جدید سلام‌گیت ممکن است اتصال مجدد و تأیید دسترسی کلاینت عادی از سمت باسلام لازم باشد؛ داشتن توکن به‌تنهایی اعطای این مجوزها را تضمین نمی‌کند.

SalamGate is available in ordinary WooSalam without Pro. It supports account onboarding, sender settings, estimates, delivery centers, single/mixed bulk shipping, reviewed payment, eligible PDF labels and guarded non-Basalam cancellation. Shipping follows WooSalam's base requirements and needs active WooCommerce. Legacy Pro remains the sole shipping owner while active, avoiding duplicate handlers. Existing shipment mappings and financial guards are preserved when ownership changes.

Native Basalam shipping uses payment method 19 from its original order; other origins use vendor balance 20 or wallet 21. Bulk payments are sequential, not one atomic debit. Lost financial responses require read-only reconciliation, never automatic replay. Real payment/activation/refund needs deliberate authorization; automated tests are not proof of a live financial result. Current base OAuth renewal has no operational shared-client refresh adapter; reconnect remains the fallback. The ordinary OAuth client/callback are not replaced with Pro credentials.

Postal tracking is offered only through the dedicated tracking tab, which submits a validated 24-digit barcode to https://tracking.post.ir/. Shipping service selection by buyers at customer checkout is not provided. A cancelled source order cannot start fresh shipping; a voided postal receipt cannot print a valid label.

- 💬 [گروه پشتیبانی تلگرام](https://t.me/woosalam_group)
- 📧 [ایمیل پشتیبانی](mailto:info.hamsalam.ir@gmail.com)
- 🌐 [راهنمای نصب و استفاده](https://hamsalam.ir/wp/help)

== Description ==

sync basalam provides seamless two-way integration between your WooCommerce store and [Basalam.com](https://basalam.com) — one of the largest online marketplaces in Iran.

With this plugin, you can:
- Sync WooCommerce products to your Basalam vendor panel
- Automatically receive and update orders from Basalam in WooCommerce
- Keep product prices, inventory, and stock levels in sync in real-time
- Prepare, pay for and print eligible SalamGate shipments from the store's order screen or shipping menu, without requiring Pro

**Note:**  
This plugin connects to Basalam’s external APIs. Authenticated synchronization and shipping use the account configured by the administrator. Public geography/packaging references and an explicitly requested postage estimate can use public SalamGate endpoints without vendor credentials.

== External Services ==

This plugin communicates with several external APIs provided and hosted by Basalam to enable synchronization between WooCommerce and Basalam.

**External APIs used:**

- `developers.basalam.com`  
- `core.basalam.com`  
- `order-processing.basalam.com`  
- `categorydetection.basalam.com`  
- `revision.basalam.com`  
- `integration.basalam.com`  
- `uploadio.basalam.com`  
- `salam-gate.basalam.com` — shipping account, parcel, quote, payment and label APIs
- `tracking.post.ir` — browser navigation from the postal tracking tab, with the entered postal barcode

**Purpose:**  
These APIs are used for:
- Syncing product information: title, description, price, inventory, images, etc.  
- Creating and updating orders from Basalam  
- Predicting product categories and validating uploaded images  
- Authenticating vendors and managing account credentials  
- Registering/reviewing postal parcels, selecting shipping rates, paying postage, generating authorized labels and requesting eligible cancellation

**Data Sent (authenticated operations):**
- Product data: title, price, stock, description, categories, images (on sync)  
- Order data: order ID, status, invoice number (on order updates)  
- Site metadata: vendor ID, domain, webhook URLs (during setup or manual sync)
- Shipping data when the seller prepares a parcel: recipient name, phone, postal address/code, city, goods description, declared value, packaged weight/dimensions and the required order/native-parcel identifiers. Selected quote/funding information is sent when the seller explicitly confirms the financial action.

Public reference requests contain no recipient details. Calculator input consists of route city IDs, package weight/value, packaging and service parameters. The tracking form sends only its entered barcode to the official postal website.

**Data Received:**
- Orders placed by customers on Basalam  
- Product inventory updates  
- Vendor authentication tokens and sync status
- Shipping account/shop state, rates and cost breakdown, parcel/invoice status, postal barcode, delivery-center data and authorized PDF label links

**Security Measures:**  
- All communications occur over secure HTTPS connections  
- Authenticated shipping requires account ownership, local permissions and a valid nonce; public references/calculator are separate
- Shipping status can be checked by the configured read-only background synchronization; financial actions are never silently performed by that scheduler
- No shared OAuth client secret is distributed; uncertain creation/payment/cancellation records are retained to prevent blind retries

**Terms and Privacy:**

This plugin depends on Basalam’s platform and services. By using the plugin, you agree to their terms and privacy policy:

- [Basalam Terms of Service](https://basalam.com/about/terms-of-use)
- [Basalam Privacy Information](https://basalam.com/about/terms-of-use)

== Installation ==

1. Upload the plugin to `/wp-content/plugins/sync-basalam/`.
2. Activate the plugin via the WordPress admin panel.
3. After activation, you will be redirected to the onboarding page.
4. Authenticate via your Basalam vendor account and configure plugin settings.

== Support ==

If you need help or have questions, please contact us via:

- 💬 [Support Telegram Group](https://t.me/woosalam_group)
- 📧 [Email](mailto:info.hamsalam.ir@gmail.com)
- 🌐 [Help Center](https://hamsalam.ir/wp/help)


== Frequently Asked Questions ==

= What data does this plugin share? =  
Product/order synchronization shares the data needed for the configured Basalam account. Preparing a postal shipment also shares the recipient and package information required by SalamGate, as described under External Services.

= Is customer data transmitted? =  
For a postal shipment explicitly prepared by the seller, the recipient and package information necessary for shipping is sent to SalamGate. The whole customer database is not exported. Public calculator/reference requests do not require recipient names, addresses or phones. Using the tracking tab sends the entered postal barcode to tracking.post.ir.

= Can I choose which products get synced? =  
Yes. You have full control over which products are synced via the plugin’s settings.

= Is my data safe? =  
API calls use HTTPS. Authenticated operations use the configured account and checked permissions; public reference/calculator requests are described above. Review the recipient/package data and final amount before confirming a shipment or payment.

== Screenshots ==
1. تنظیمات اولیه افزونه در وردپرس

== Changelog ==

= 1.10.39 =
* Save product, advanced and debug settings without reloading the page and confirm successful saves with a toast.
* Offer a full product update after saved product settings change, with an option to do it later.
* Show the attribute-name mapping save button beside the inputs only when both names are filled.
* Use concise success toasts when product creation or updates are stopped.
* Preserve shared settings, checkbox values and advanced-form change tracking after AJAX saves.
* Improve SalamGate permission recovery guidance, back off denied account checks, confirm deactivation and correct the settings help video link.

= 1.10.38 =
* Continue existing-product updates with fresh price and stock when optional preparation or full-field updates fail; report partial success explicitly and preserve failures of required fields.
* Send stock zero for unavailable variable products without eligible priced variations, preserving existing variation mappings.
* Retain one follow-up for edits made while a product is syncing, coalesce pending edits, and keep full updates separate from price/stock-only retries.
* Retry queue jobs by conditionally updating their existing row, avoiding lost work on database write failures and non-transactional tables.
* Check every variation and batch result, including partial failures, incomplete HTTP 202 responses and failed SKU retries.
* Persist temporary batch-item failures as independent price/stock jobs using fresh WooCommerce data; retain page continuation and wait for pending or processing children.
* Resume queue attempts after circuit-breaker cooldown, recover expired half-open probes and allow restricted fallbacks after client validation errors.
* Verify product connections from the product list, including rows replaced by quick edit, while distinguishing unchecked connections from failed checks.
* Clarify full versus single-product queues, show actual cancellation counts and waiting reasons, and avoid duplicate form action parameters.
* Count all successful HTTP 2xx responses in request statistics.

= 1.10.37 =
* Stop current product creation, full updates and quick updates without changing automatic synchronization settings or periodic schedules.
* Retain cancelled-parent and retry protection while allowing independent future automatic, scheduled and manual work.

= 1.10.36 =
* Execute queue recovery directly in WordPress cron instead of repeating a failed loopback dispatch.
* Add an authenticated admin worker that keeps the connection open and continues product and order jobs when background loopbacks are blocked.
* Allow one second for nonblocking dispatch to connect, and retain private dispatch/start diagnostics without credentials or payloads.
* Preserve the shared queue lock, task rate, retries and cancellation across all execution paths.
* Keep temporary photo-upload failures retryable so a DNS or network interruption does not discard the product creation task.

= 1.10.33 =
* Retry product updates rejected with HTTP 400 or 422 once using only price and stock, including single products, variations, batches and SKU follow-ups.
* Rebuild missing price and stock in custom mode without changing the saved update settings.
* Store the latest variation identifiers after fallback and propagate failed variation or batch updates correctly.
* Add real HTTP/store simulation coverage and make shared test fixtures independent of execution order.

* Remove independent SalamGate runtime restrictions and use list validation compatible with the base plugin requirements.
* Recover order-list connection failures through Order Processing v3 in the importer and SalamGate, preserving filters, cursors and authentication.
* Retry OpenAPI DNS/connection failures once through documented Core routes, using normal DNS and verified TLS.
* Add Core fallback for batch updates and Webhook service fallback for webhook list/create/update/delete operations.
* Send mapped requests directly to fallback for 48 hours after ten OpenAPI cURL 6/7 failures within ten minutes.
* Reconcile missing webhooks on activation for connected stores, preserving subscriptions belonging to other stores.
* Add grouped postal label and barcode printing in SalamGate.
* Continue background queue batches and schedule recovery for delayed or interrupted processing.
* Automatically install scoped LiteSpeed background-worker protection and remove worker-health admin alerts.
* Distinguish Apache/LSAPI from LiteSpeed using server evidence instead of the PHP SAPI alone.
* Isolate WordPress admin notices from SalamGate styles and load dashboard reports independently from account state.
* Clarify SalamGate report/account errors, allow 45 seconds for account checks and silence routine synchronization INFO logs.
* Remove repeated product-update status polling and the redundant Salam Pay settings link from the plugin list.
* Add queue/webserver/fallback regression coverage, live verification of all 14 mapped HTTP operations and host-based threshold/expiry checks.

= 1.10.32 =
* Bundle Salam Pay with settings available directly under WooCommerce payments, without an extra WooSalam submenu.
* Fix SalamGate shipping being disabled on PHP 8.2 by correcting the module's minimum PHP requirement from 8.3 to 8.2.

= 1.10.30 =
* Add the complete SalamGate shipping workflow to ordinary WooSalam, including onboarding, shipping estimates, reviewed payment, eligible labels and tracking.
* Keep Basalam product names and links out of formatted order item details used by SMS plugins.
* Display Basalam item details separately in the order admin, including on existing orders.
* Avoid false interrupted-worker/LiteSpeed warnings when Apache exposes the LSAPI SAPI.
* Match the documented LiteSpeed noabort rule to the Woosalam async queue action and clear stale protection alerts after explicit queue cancellation.

= 1.10.29 =
* Fixed queues remaining blocked when a background dispatch lock loses its expiry.
* Store background lock deadlines in their values to keep dispatch bounded.
* Run the shutdown dispatcher without calling fastcgi_finish_request().
* Show admin notices for interrupted background workers and missing LiteSpeed noabort protection.
* Load shared admin styles and fonts on the Pro Digikala importer page.
