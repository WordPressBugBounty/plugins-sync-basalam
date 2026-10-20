# فهرست APIها و فالبک افزونه — ۱۴۰۵/۰۷/۲۸

این فهرست از کد فعلی و بستهٔ اصلاح‌شدهٔ ۱.۱۰.۳۴ استخراج شده است؛ بررسی کد است، نه ادعای تست زندهٔ همهٔ APIهای این فهرست. منظور از فالبک، تغییر خودکار مقصد به سرور دیگر است؛ تکرار صف، استفاده از کش، تمدید توکن و کرون در این شمارش فالبک نیستند.

در حالت عادی، فالبک پس از خطای `http_request_failed` با cURL 6 یا 7 و پیش از دنبال‌شدن ریدایرکت اجرا می‌شود، آن هم فقط یک بار. با ثبت ۱۰ خطای واجد شرایط در بازهٔ لغزان ۱۰ دقیقه، تمام درخواست‌های دارای نگاشت فالبک برای ۴۸ ساعت مستقیم به مقصد جایگزین می‌روند؛ سپس دوباره OpenAPI امتحان می‌شود. شمارش بین مسیرهای محصول، سفارش و وبهوک مشترک است، موفقیت میان درخواست‌ها آن را پاک نمی‌کند و مهلت با ترافیک بعدی تمدید نمی‌شود. خطاهای HTTP، TLS، timeout و قطع مبهم اتصال باعث تکرار درخواست نوشتنی روی سرور دیگر نمی‌شوند. DNS عادی و TLS معتبر، روش، بدنه، هدرها و query حفظ می‌شوند. این سیاست جدید در بستهٔ اصلاح فعال‌سازی روی سایت نصب و فایل‌های آن تطبیق داده شد؛ آستانه و انقضای ۴۸ ساعته در تست محلی بررسی شده‌اند.

مقادیر داخل `{}` متغیر هستند. برای فهرست‌ها queryهای صفحه‌بندی، cursor، تاریخ و فیلترها به URL اضافه می‌شوند؛ تفاوت query به معنی API جداگانه نیست.

## دارای فالبک

| عملیات | روش | URL اصلی | فالبک |
| --- | --- | --- | --- |
| خواندن / بروزرسانی محصول | GET / PATCH | `https://openapi.basalam.com/v1/products/{product_id}` | `https://core.basalam.com/v4/products/{product_id}` |
| ایجاد محصول | POST | `https://openapi.basalam.com/v1/vendors/{vendor_id}/products` | `https://core.basalam.com/v4/vendors/{vendor_id}/products` |
| بروزرسانی گروهی محصولات | PATCH | `https://openapi.basalam.com/v1/vendors/{vendor_id}/products/batch-updates?continue_on_error=true` | `https://core.basalam.com/v4/vendors/{vendor_id}/products?continue_on_error=true` |
| دسته‌بندی‌ها | GET | `https://openapi.basalam.com/v1/categories` | `https://core.basalam.com/v3/categories` |
| ویژگی‌های دسته‌بندی | GET | `https://openapi.basalam.com/v1/categories/{category_id}/attributes?exclude_multi_selects=true` | `https://core.basalam.com/v3/categories/{category_id}/attributes?exclude_multi_selects=true` |
| اطلاعات غرفه | GET | `https://openapi.basalam.com/v1/vendors/{vendor_id}` | `https://core.basalam.com/v3/vendors/{vendor_id}` |
| ایجاد / حذف تخفیف | POST / DELETE | `https://openapi.basalam.com/v1/vendors/{vendor_id}/discounts` | `https://core.basalam.com/v3/vendors/{vendor_id}/discounts` |
| فهرست سفارش‌ها | GET | `https://openapi.basalam.com/v1/vendor-parcels` | `https://order-processing.basalam.com/v3/vendor-parcels` |
| فهرست / ایجاد وبهوک | GET / POST | `https://openapi.basalam.com/v1/webhooks` | `https://webhook.basalam.com/v1/webhooks` |
| ویرایش / حذف وبهوک | PATCH / DELETE | `https://openapi.basalam.com/v1/webhooks/{webhook_id}` | `https://webhook.basalam.com/v1/webhooks/{webhook_id}` |

## Core؛ بدون فالبک

| عملیات | روش | URL اصلی | فالبک |
| --- | --- | --- | --- |
| بروزرسانی تنوع محصول | PATCH | `https://core.basalam.com/v4/products/{product_id}/variations/{variation_id}` | ندارد |
| فهرست محصولات | GET | `https://core.basalam.com/v4/products` | ندارد |
| کارمزد دسته‌بندی | GET | `https://core.basalam.com/api_v2/commission/get_percent` | ندارد |
| کارمزد محصول | GET | `https://core.basalam.com/api_v2/commission/products/{product_id}/percent` | ندارد |
| دسته‌بندی و زمان آماده‌سازی | GET | `https://core.basalam.com/v3/categories` | ندارد |
| هویت کاربر؛ اتصال و فعال‌سازی سلام‌گیت | GET | `https://core.basalam.com/v3/users/me` | ندارد |

## Order Processing؛ بدون فالبک

| عملیات | روش | URL اصلی | فالبک |
| --- | --- | --- | --- |
| جزئیات سفارش | GET | `https://order-processing.basalam.com/v2/vendors/{vendor_id}/orders/{invoice_id}` | ندارد |
| جزئیات مرسوله | GET | `https://order-processing.basalam.com/v3/vendor-parcels/{parcel_id}` | ندارد |
| تأیید سفارش | POST | `https://order-processing.basalam.com/v1/vendor/set-preparation-order` | ندارد |
| لغو سفارش | POST | `https://order-processing.basalam.com/v1/vendor/set-cancel` | ندارد |
| درخواست لغو سفارش | POST | `https://order-processing.basalam.com/v1/vendor/order/{order_id}/cancel-request` | ندارد |
| درخواست تأخیر | POST | `https://order-processing.basalam.com/v1/vendor/orders/{order_id}/new-agreement` | ندارد |
| ثبت ارسال و کد رهگیری | POST | `https://order-processing.basalam.com/v2/vendor/set-posted-order` | ندارد |
| تنظیم تأیید خودکار | PUT | `https://order-processing.basalam.com/v1/vendor/automation-config` | ندارد |

## مالی؛ بدون فالبک

| عملیات | روش | URL اصلی | فالبک |
| --- | --- | --- | --- |
| موجودی حساب | GET | `https://accounting.basalam.com/financial/v1/client/transaction/balance` | ندارد |
| درخواست‌های تسویه و تاریخچه | GET | `https://accounting.basalam.com/financial/v1/client/balance-settlement` | ندارد |
| ایجاد تسویه | POST | `https://accounting.basalam.com/financial/v2/client/balance-settlement` | ندارد |
| حساب‌های بانکی | GET | `https://identity.basalam.com/v1/users/bank-accounts` | ندارد |
| موجودی غرفه در سلام‌گیت | GET | `https://services.basalam.com/web/v1/accounting/financial/client/transaction/balance` | ندارد |
| موجودی کیف پول در سلام‌گیت | GET | `https://services.basalam.com/web/v1/wallet/public/balance/settlement` | ندارد |

## رسانه و سایر سرویس‌های باسلام؛ بدون فالبک

| عملیات | روش | URL اصلی | فالبک |
| --- | --- | --- | --- |
| شروع آپلود عکس / ویدیو | POST | `https://uploadio.basalam.com/v3/media/upload-request` | ندارد |
| تکمیل آپلود | POST | `https://uploadio.basalam.com/v3/media/complete` | ندارد |
| وضعیت آپلود | GET | `https://uploadio.basalam.com/v3/media/status/{file_id}` | ندارد |
| پیشنهاد دسته‌بندی | GET | `https://categorydetection.basalam.com/category_detection/api_v2.0/predict/?title={title}` | ندارد |
| ثبت نظر افزونه | POST | `https://apps-api.basalam.com/v1/apps/13/reviews` | ندارد |

## همسلام؛ بدون فالبک

| عملیات | روش | URL اصلی | فالبک |
| --- | --- | --- | --- |
| تنظیمات اتصال OAuth | GET | `https://api.hamsalam.ir/api/v1/basalam-proxy/wp-oauth-data` | ندارد |
| نسخه و اجبار بروزرسانی | GET | `https://api.hamsalam.ir/api/v1/wp-sites/version-detail?site_url={site_url}&current_version={version}` | ندارد |
| اعلان‌ها | GET | `https://api.hamsalam.ir/api/v1/announcements` | ندارد |
| فهرست کسب‌وکارها | GET | `https://api.hamsalam.ir/api/v1/all-businesses` | ندارد |
| دریافت توکن همسلام | POST | `https://api.hamsalam.ir/api/v1/auth/basalam/get-token` | ندارد |
| فهرست / ایجاد تیکت | GET / POST | `https://api.hamsalam.ir/api/v1/tickets` | ندارد |
| موضوع‌های تیکت | GET | `https://api.hamsalam.ir/api/v1/tickets/subjects` | ندارد |
| جزئیات تیکت | GET | `https://api.hamsalam.ir/api/v1/tickets/{ticket_id}` | ندارد |
| پاسخ تیکت | POST | `https://api.hamsalam.ir/api/v1/tickets/{ticket_id}/ticket-items` | ندارد |
| ثبت دسترسی سایت در تیکت | PUT | `https://api.hamsalam.ir/api/v1/businesses/{business_id}/site-access` | ندارد |
| آپلود رسانهٔ تیکت | POST | `https://api.hamsalam.ir/api/v1/media` | ندارد |
| اعتبارسنجی توکن وضعیت | GET | `https://api.hamsalam.ir/api/v1/woo-plugin/validate-token?token={token}` | ندارد |

## سلام‌گیت؛ بدون فالبک

| عملیات | روش | URL اصلی | فالبک |
| --- | --- | --- | --- |
| استان‌ها و شهرها | GET | `https://salam-gate.basalam.com/v1/locations` | ندارد |
| نوع بسته‌بندی | GET | `https://salam-gate.basalam.com/v1/parcels/box-types` | ندارد |
| محاسبه هزینه ارسال | POST | `https://salam-gate.basalam.com/v1/base/calculator` | ندارد |
| پروفایل | GET | `https://salam-gate.basalam.com/v1/users/profile` | ندارد |
| آمار کاربر | GET | `https://salam-gate.basalam.com/v1/users/{user_id}/stats` | ندارد |
| اطلاعات شرکت پستی | GET | `https://salam-gate.basalam.com/v1/providers/{provider_id}` | ندارد |
| مناطق شرکت پستی | GET | `https://salam-gate.basalam.com/v1/providers/{provider_id}/locations` | ندارد |
| مراکز تحویل | GET | `https://salam-gate.basalam.com/v1/providers/{provider_id}/nodes` | ندارد |
| دسته‌های پستی | GET | `https://salam-gate.basalam.com/v1/providers/{provider_id}/categories` | ندارد |
| غرفه‌های پستی | GET | `https://salam-gate.basalam.com/v1/shops` | ندارد |
| ثبت غرفهٔ پستی | POST | `https://salam-gate.basalam.com/v1/shops/register` | ندارد |
| ویرایش غرفهٔ پستی | PATCH | `https://salam-gate.basalam.com/v1/shops/{shop_id}` | ندارد |
| فعال‌سازی غرفهٔ پستی | POST | `https://salam-gate.basalam.com/v1/shops/{shop_id}/activate` | ندارد |
| غیرفعال‌سازی غرفهٔ پستی | POST | `https://salam-gate.basalam.com/v1/shops/{shop_id}/deactivate` | ندارد |
| فهرست نشانی‌ها | GET | `https://salam-gate.basalam.com/v1/addresses` | ندارد |
| ویرایش نشانی | PATCH | `https://salam-gate.basalam.com/v1/addresses/{address_id}` | ندارد |
| ایجاد / بروزرسانی مرسولهٔ بازار | POST | `https://salam-gate.basalam.com/v1/markets/{market_name}/parcels` | ندارد |
| فهرست مرسوله‌ها | GET | `https://salam-gate.basalam.com/v1/parcels` | ندارد |
| جزئیات مرسوله | GET | `https://salam-gate.basalam.com/v1/parcels/{parcel_id}` | ندارد |
| دریافت / انتخاب نرخ ارسال | GET / PUT | `https://salam-gate.basalam.com/v1/parcels/{parcel_id}/quotes` | ندارد |
| پرداخت مرسوله | POST | `https://salam-gate.basalam.com/v1/parcels/{parcel_id}/pay` | ندارد |
| لغو مرسوله | POST | `https://salam-gate.basalam.com/v1/parcels/{parcel_id}/cancel` | ندارد |
| رهگیری مرسوله | GET | `https://salam-gate.basalam.com/v1/parcels/{parcel_id}/track` | ندارد |
| ساخت / وضعیت برچسب | POST / GET | `https://salam-gate.basalam.com/v1/parcels/generate-label` | ندارد |

## سلام‌پی؛ بدون فالبک

| عملیات | روش | URL اصلی | فالبک |
| --- | --- | --- | --- |
| ایجاد نشست پرداخت | POST | `https://salam-pay-api.basalam.com/public-api/v1/sessions` | ندارد |
| استعلام پرداخت | GET | `https://salam-pay-api.basalam.com/public-api/v1/payments/references/{reference}` | ندارد |
| درخواست بازپرداخت | POST | `https://salam-pay-api.basalam.com/public-api/v1/refunds` | ندارد |
| وضعیت بازپرداخت | GET | `https://salam-pay-api.basalam.com/public-api/v1/refunds/{reference}/status` | ندارد |

## مسیرهای تعریف‌شده اما بدون فراخوانی فعال در کد PHP

این‌ها را با APIهایی که اکنون از افزونه درخواست می‌گیرند یکی حساب نکنید. برخی فقط در نقشهٔ قرارداد یا ثابت‌ها ثبت شده‌اند. همگی بدون فالبک تغییر میزبان هستند.

| روش / کاربرد | URL کامل | وضعیت |
| --- | --- | --- |
| PATCH پروفایل | `https://salam-gate.basalam.com/v1/users/profile` | فقط قرارداد |
| GET رهگیری عمومی | `https://salam-gate.basalam.com/v1/tracking/{tracking_code}` | ثابت / قرارداد؛ رابط فعلی به سایت پست می‌رود |
| GET فاکتورها | `https://salam-gate.basalam.com/v1/invoices` | ثابت / قرارداد |
| GET جزئیات فاکتور | `https://salam-gate.basalam.com/v1/invoices/{invoice_id}` | فقط قرارداد |
| GET فایل فاکتور | `https://salam-gate.basalam.com/v1/invoices/{invoice_id}/factor` | فقط قرارداد |
| GET مرسوله بر اساس شناسهٔ خارجی | `https://salam-gate.basalam.com/v1/markets/{market_name}/parcels/{external_id}` | ثابت / قرارداد مدیریتی؛ جریان عادی از شناسهٔ مرسوله استفاده می‌کند |
| POST آمادهٔ جمع‌آوری | `https://salam-gate.basalam.com/v1/parcels/ready-to-collect` | فقط قرارداد؛ نیازمند client-secret سرویس |
| POST استعلام پرداخت بازار | `https://salam-gate.basalam.com/v1/markets/{market_name}/parcels/{external_id}/payments` | فقط قرارداد؛ سمت سرویس |
| مسیر کلی پرداخت‌ها | `https://salam-gate.basalam.com/v1/payments/...` | صرفاً توضیح محدودیت قرارداد، endpoint مشخص قابل استفاده نیست |

## وبهوک‌های خارج از مسیر عادی افزونه

این مسیرها در `WebhookService` فراخوانی نمی‌شوند و نگاشت فالبک ندارند؛ ایجاد وبهوک عادی با `register_me=true` عضویت حساب را هم ایجاد می‌کند. درخواست مستقیم به خود `webhook.basalam.com` نیز فالبک بازگشتی ندارد.

| URL OpenAPI | فالبک | وضعیت در افزونه |
| --- | --- | --- |
| `https://openapi.basalam.com/v1/customers/webhooks` | ندارد | فقط در عیب‌یابی زنده، مستقیماً از `https://webhook.basalam.com/v1/customers/webhooks` برای تأیید عضویت استفاده شد |
| `https://openapi.basalam.com/v1/webhooks/customers` | ندارد | در مسیر عادی فراخوانی نمی‌شود |
| `https://openapi.basalam.com/v1/webhooks/events` | ندارد | در مسیر عادی فراخوانی نمی‌شود |
| `https://openapi.basalam.com/v1/webhooks/{webhook_id}/logs` | ندارد | در مسیر عادی فراخوانی نمی‌شود |

## تغییرمسیر مرورگر، درخواست محلی و URLهای پویا

| کاربرد | URL / الگو | فالبک میزبان |
| --- | --- | --- |
| ورود OAuth در مرورگر | `https://basalam.com/accounts/sso?client_id={client_id}&scope={scope}&redirect_uri={redirect_uri}&state={state}` | ندارد |
| مقصد پیش‌فرض OAuth؛ از افزونه POST نمی‌شود | `https://api.hamsalam.ir/api/v1/basalam-proxy/wp-get-token` | ندارد؛ مقصد ممکن است از تنظیمات OAuth دریافتی یا فیلتر عوض شود |
| انتقال به پرداخت سلام‌پی | `https://salampay.basalam.com/payment-interface/{key}` | ندارد |
| تست شبکهٔ Debug | `https://basalam.com` | ندارد |
| ارسال کار پس‌زمینه | `https://mottasell.com/wp-admin/admin-ajax.php?action=sync_basalam_run_jobs_async` | ندارد؛ بازیابی کرون مستقل است |
| صف قدیمی و AJAX رابط | `https://mottasell.com/wp-admin/admin-ajax.php?action={action}` | ندارد |
| وبهوک ورودی سفارش | `https://mottasell.com/wp-json/sync-basalam/v1/order-manager` | endpoint دریافت است، فالبک خروجی موضوعیت ندارد |
| ارسال باینری فایل، POST | URL دقیق در `staging.url` پاسخ Uploadio | ندارد؛ URL ثابت در پروژه نیست |
| دریافت فایل برای آپلود، GET | URL فایل ورودی معتبر | ندارد؛ URL ثابت در پروژه نیست |
| دریافت تصویر برچسب، GET | URL برگردانده‌شده توسط سلام‌گیت | ندارد؛ URL ثابت در پروژه نیست |

## ویجت چت جاوااسکریپت

ویجت از `AbstractApiService` یا `OpenApiConnectionFallback` عبور نمی‌کند. آدرس‌های زیر پیش‌فرض‌های loader هستند، نه فالبک. تنظیمات `window.dalanWidgetConfig` می‌توانند آن‌ها را عوض کنند. هیچ سازوکار سوئیچ خودکار به میزبان دوم در loader و interceptor HTTP این bundle مشاهده نشد.

| سرویس | URL پیش‌فرض تنظیم‌شده | فالبک |
| --- | --- | --- |
| core | `https://gateway.hamsalam.ir/core` | ندارد |
| conversation | `https://gateway.hamsalam.ir/conversation` | ندارد |
| auth | `https://gateway.hamsalam.ir/auth` | ندارد |
| review | `https://gateway.hamsalam.ir/review` | ندارد |
| story | `https://gateway.hamsalam.ir/story` | ندارد |
| userActivity | `https://gateway.hamsalam.ir/user-activity` | ندارد |
| featureFlag | `https://gateway.hamsalam.ir/feature-flag` | ندارد |
| orderProcessing | `https://gateway.hamsalam.ir/order-processing` | ندارد |
| uploadio | `https://gateway.hamsalam.ir/uploadio` | ندارد |
| automation | `https://gateway.hamsalam.ir/automation` | ندارد |
| voucher | `https://gateway.hamsalam.ir/voucher` | ندارد |
| notification | `https://gateway.hamsalam.ir/notification` | ندارد |
| appsApi | `https://gateway.hamsalam.ir/apps-api` | ندارد |
| chat | `https://gateway.hamsalam.ir/chat` | ندارد |
| orderTest2 | `https://gateway.hamsalam.ir/order-test2` | ندارد |

### مسیرهای HTTP شناسایی‌شده در bundle چت

این جدول حاصل بررسی ایستای فراخوانی‌های bundle است و ثابت نمی‌کند همهٔ آن‌ها در هر نمایش ویجت اجرا می‌شوند. پارامترهای تک‌حرفی bundle با `{...}` نمایش داده شده‌اند. سرویس‌های `order`، `orderProcess` و `voucherService` در bundle مصرف می‌شوند، ولی loader پیش‌فرض این کلیدهای دقیق را تعریف نکرده است؛ بنابراین برای آن‌ها URL مطلقِ اجرا از همین فایل‌ها قابل تعیین نیست. در این موارد فقط مسیر را می‌دهیم تا URL ساختگی گزارش نشود. کلیدهای `orderProcessing` و `voucher` loader نام متفاوت دارند.

| روش | URL / مسیر شناسایی‌شده | فالبک |
| --- | --- | --- |
| DELETE | `https://gateway.hamsalam.ir/automation/api/api_v1.0/trigger/product_reminders` | ندارد |
| GET | `https://gateway.hamsalam.ir/automation/api/api_v1.0/trigger/product_reminders?product_id={n}` | ندارد |
| POST | `https://gateway.hamsalam.ir/automation/api/api_v1.0/trigger/product_reminders` | ندارد |
| GET | `Fl: /v1/user/orders/parcel/posted?vendor_id={n}&order_ids={e}&limit={t}&offset={r} (base URL در loader تعریف نشده)` | ندارد |
| GET | `Fl: /v1/vendor/customer/{n}/orders?limit={e}&offset={t}&status=sent (base URL در loader تعریف نشده)` | ندارد |
| GET | `Fl: /v1/vendor/customer/{n}/orders?order_ids={e}&limit={t}&offset={r} (base URL در loader تعریف نشده)` | ندارد |
| GET | `Fl: v1/{n}/agreements/{e} (base URL در loader تعریف نشده)` | ندارد |
| POST | `Fl: /v1/user/agreements/{n}/determine-agreement (base URL در loader تعریف نشده)` | ندارد |
| POST | `Fl: /v1/user/set-satisfied (base URL در loader تعریف نشده)` | ندارد |
| PUT | `Fl: /v1/vendor/orders/{n}/new-agreement (base URL در loader تعریف نشده)` | ندارد |
| GET | `https://gateway.hamsalam.ir/apps-api/v1/apps` | ندارد |
| GET | `https://gateway.hamsalam.ir/feature-flag/api_v1.0/flags/chat_config?parse-data=true` | ندارد |
| GET | `https://gateway.hamsalam.ir/feature-flag/api_v1.0/flags/extensions_widget_configs?parse-data=true` | ندارد |
| GET | `https://gateway.hamsalam.ir/feature-flag/api_v2.0/vendors/{n}/environments/{mn.growthbookApiKey}/safe_call_status` | ندارد |
| GET | `https://gateway.hamsalam.ir/story/api_v2.0/story_data/{n}` | ندارد |
| GET | `https://gateway.hamsalam.ir/review/api_v2.0/{n}/unreviewed{t}` | ندارد |
| GET | `https://gateway.hamsalam.ir/review/api_v2.0/item/reviews?ids={n}` | ندارد |
| GET | `https://gateway.hamsalam.ir/review/api_v1.0/item/unreview?vendor_id={n}&customer_id={e}&limit={t}&offset={r}` | ندارد |
| POST | `https://gateway.hamsalam.ir/review/api_v1.0/review` | ندارد |
| POST | `https://gateway.hamsalam.ir/uploadio/api_v1.0/store-file` | ندارد |
| GET | `XK: /v2/factors/{n} (base URL در loader تعریف نشده)` | ندارد |
| GET | `YK: /v2/coupon (base URL در loader تعریف نشده)` | ندارد |
| GET | `https://gateway.hamsalam.ir/user-activity/api_v1.0/user-last-activity/{n}` | ندارد |
| POST | `https://gateway.hamsalam.ir/user-activity/api_v1.0/user-last-activity/get-activities` | ندارد |
| GET | `https://gateway.hamsalam.ir/core/api_v2/user` | ندارد |
| GET | `https://gateway.hamsalam.ir/core/api_v2/user/get-by-ids/?user_ids={n}` | ندارد |
| GET | `https://gateway.hamsalam.ir/core/api_v2/vendor/list?vendor_ids={n}` | ندارد |
| GET | `https://gateway.hamsalam.ir/core/api_v2/vendor/products` | ندارد |
| GET | `https://gateway.hamsalam.ir/core/api_v2/product/{n}` | ندارد |
| GET | `https://gateway.hamsalam.ir/chat/v2/stickers یا https://gateway.hamsalam.ir/conversation/v2/stickers` | ندارد |
| GET | `https://gateway.hamsalam.ir/chat/v2/user/websocket/auth/token یا https://gateway.hamsalam.ir/conversation/v2/user/websocket/auth/token` | ندارد |
| POST | `https://gateway.hamsalam.ir/chat/v2/chat/confirmation یا https://gateway.hamsalam.ir/conversation/v2/chat/confirmation` | ندارد |
| DELETE | `https://gateway.hamsalam.ir/conversation/v3/chats` | ندارد |
| DELETE | `https://gateway.hamsalam.ir/conversation/v3/messages` | ندارد |
| GET | `https://gateway.hamsalam.ir/conversation/lookup/{n}` | ندارد |
| GET | `https://gateway.hamsalam.ir/conversation/v3/chats` | ندارد |
| GET | `https://gateway.hamsalam.ir/conversation/v3/chats/public` | ندارد |
| GET | `https://gateway.hamsalam.ir/conversation/v3/messages` | ندارد |
| GET | `https://gateway.hamsalam.ir/conversation/v3/messages/public` | ندارد |
| GET | `https://gateway.hamsalam.ir/conversation/v3/messages/unseen_count` | ندارد |
| PATCH | `https://gateway.hamsalam.ir/conversation/v3/chats/{e}` | ندارد |
| PATCH | `https://gateway.hamsalam.ir/conversation/v3/messages` | ندارد |
| POST | `https://gateway.hamsalam.ir/conversation/v2/broadcast` | ندارد |
| POST | `https://gateway.hamsalam.ir/conversation/v3/chats` | ندارد |
| POST | `https://gateway.hamsalam.ir/conversation/v3/messages` | ندارد |
| POST | `https://gateway.hamsalam.ir/conversation/v3/messages/forward` | ندارد |
| POST | `https://gateway.hamsalam.ir/conversation/v3/messages/reply_markup/callback` | ندارد |
| POST | `https://gateway.hamsalam.ir/conversation/v3/messages/seen` | ندارد |
| POST | `https://gateway.hamsalam.ir/conversation/v3/messages/seen_all` | ندارد |
| POST | `https://gateway.hamsalam.ir/conversation/v3/search/contact` | ندارد |
| POST | `https://gateway.hamsalam.ir/conversation/v3/search/message` | ندارد |
| POST | `https://gateway.hamsalam.ir/conversation/v3/users/call/phone` | ندارد |
| GET | `https://gateway.hamsalam.ir/auth/light/whoami` | ندارد |
| DELETE | `https://gateway.hamsalam.ir/notification/api_v1.0/user/notification-setting/chat` | ندارد |
| GET | `https://gateway.hamsalam.ir/notification/api_v1.0/user/notification-setting/chat` | ندارد |
| GET | `https://gateway.hamsalam.ir/notification/api_v1.0/user/notifications/` | ندارد |
| GET | `https://gateway.hamsalam.ir/notification/api_v1.0/user/notifications/un_read_count` | ندارد |
| POST | `https://gateway.hamsalam.ir/notification/api_v1.0/user/notifications/read_all` | ندارد |
| POST | `https://gateway.hamsalam.ir/conversation/v3/channels/{channel_identifier}/left` | ندارد |
| POST | `https://gateway.hamsalam.ir/conversation/v3/channels/{channel_identifier}/join` | ندارد |
| WebSocket | `wss://wsocket.basalam.com/connection/websocket` | میزبان دوم ندارد؛ reconnect با همین سرویس جداست |

منابع کد: `includes/Config/Endpoints.php`، `includes/Services/Api/OpenApiConnectionFallback.php`، مصرف‌کننده‌های `includes/Services/`، `includes/SalamGate/`، `includes/SalamPay/ApiClient.php`، `assets/chat/widget-loader.js` و `assets/chat/chat-app-bundle.js`. برای تشخیص استفادهٔ واقعی، call site بررسی شده و به توضیحات ثابت‌ها بسنده نشده است؛ مثلاً automation سفارش با PUT و دریافت توکن همسلام با POST اجرا می‌شوند.
