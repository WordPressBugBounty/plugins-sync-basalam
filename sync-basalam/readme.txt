=== ووسلام - همگام سازی ووکامرس و باسلام ===
Contributors: hamsalam  
Tags: woocommerce, basalam, woosalam, integration
Requires at least: 6.0  
Tested up to: 6.8
Requires PHP: 7.4 
Stable tag: 1.5.0
License: GPL-2.0-or-later  
License URI: https://www.gnu.org/licenses/gpl-2.0.html  

اتصال و همگام سازی ووکامرس و باسلام و اضافه کردن و به روزرسانی محصولات از ووکامرس به باسلام و همگام سازی اتوماتیک آن و مدیریت سفارشات باسلام در ووکامرس

== توضیحات فارسی ==

افزونه‌ی **ووسلام (همگام‌سازی ووکامرس و باسلام)** امکان همگام‌سازی دوطرفه بین فروشگاه ووکامرس شما و بازار باسلام را فراهم می‌کند.

با استفاده از این افزونه می‌توانید:
- محصولات ووکامرس را به غرفه باسلام اضافه و مدیریت کنید  
- سفارش‌های ثبت‌شده در باسلام را به صورت خودکار در ووکامرس دریافت و مدیریت کنید  
- قیمت و موجودی کالاها را به صورت لحظه‌ای همگام سازی کنید  

- 💬 [گروه پشتیبانی تلگرام](https://t.me/woosalam_group)
- 📧 [ایمیل پشتیبانی](mailto:info.hamsalam.ir@gmail.com)
- 🌐 [راهنمای نصب و استفاده](https://wp.hamsalam.ir/help)

== Description ==

sync basalam provides seamless two-way integration between your WooCommerce store and [Basalam.com](https://basalam.com) — one of the largest online marketplaces in Iran.

With this plugin, you can:
- Sync WooCommerce products to your Basalam vendor panel
- Automatically receive and update orders from Basalam in WooCommerce
- Keep product prices, inventory, and stock levels in sync in real-time

**Note:**  
This plugin connects to Basalam’s external APIs. No data is sent or received until the site administrator configures their vendor credentials.

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

**Purpose:**  
These APIs are used for:
- Syncing product information: title, description, price, inventory, images, etc.  
- Creating and updating orders from Basalam  
- Predicting product categories and validating uploaded images  
- Authenticating vendors and managing account credentials  

**Data Sent (only after authentication):**
- Product data: title, price, stock, description, categories, images (on sync)  
- Order data: order ID, status, invoice number (on order updates)  
- Site metadata: vendor ID, domain, webhook URLs (during setup or manual sync)

**Data Received:**
- Orders placed by customers on Basalam  
- Product inventory updates  
- Vendor authentication tokens and sync status

**Security Measures:**  
- All communications occur over secure HTTPS connections  
- No external communication happens until valid API credentials are entered  
- Data transfer is strictly limited to syncing functions initiated by the site administrator

**Terms and Privacy:**

This plugin depends on Basalam’s platform and services. By using the plugin, you agree to their terms and privacy policy:

- [Basalam Terms of Service](https://wp.hamsalam.ir/terms)  
- [Basalam Privacy Policy](https://wp.hamsalam.ir/privacy)

== Installation ==

1. Upload the plugin to `/wp-content/plugins/sync-basalam/`.
2. Activate the plugin via the WordPress admin panel.
3. After activation, you will be redirected to the onboarding page.
4. Authenticate via your Basalam vendor account and configure plugin settings.

== Support ==

If you need help or have questions, please contact us via:

- 💬 [Support Telegram Group](https://t.me/woosalam_group)
- 📧 [Email](mailto:info.hamsalam.ir@gmail.com)
- 🌐 [Help Center](https://wp.hamsalam.ir/help)


== Frequently Asked Questions ==

= What data does this plugin share? =  
Only the data necessary for syncing products and orders with your Basalam vendor account is shared. No unnecessary or private user data is transmitted.

= Is customer data transmitted? =  
No. Customer data from your WooCommerce store is **not** sent to any external API.

= Can I choose which products get synced? =  
Yes. You have full control over which products are synced via the plugin’s settings.

= Is my data safe? =  
Yes. All API calls use secure HTTPS connections. No data is sent until you explicitly authorize API access.

== Screenshots ==
1. تنظیمات اولیه افزونه در وردپرس