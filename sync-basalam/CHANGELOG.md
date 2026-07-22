# Changelog

<details>

<summary>1.10.3 - 2026-07-22</summary>

### Changed / Improved
- "Price increase" became "price change" everywhere (code, markup, CSS classes): negative values are supported, so e.g. `-10` lowers the final Basalam price by 10% (percentages are capped at 35% increase and 35% decrease; values outside the -100..100 range are fixed Toman amounts)
- The global setting key was renamed from `increase_price_value` to `price_change_value`, and the per-product meta from `_sync_basalam_increase_price_value` to `_sync_basalam_price_change_value`. Old values are not migrated — re-enter the price change in the settings
- The category-commission mode is now stored as `commission` instead of `-1`, so it no longer collides with a real negative percentage

### Added
- A "custom price change" field in the Basalam product settings tab and in the products list bulk edit, so a single product or a selection of products can override the global value (or switch to category commission)

</details>

<details>

<summary>1.10.2 - 2026-07-19</summary>

### Changed / Improved
- Migrated product photo and video uploads from the legacy direct upload endpoint to Uploadio's presigned media flow
- Added SHA-256 metadata, direct staging upload, completion handling, and status polling so product payloads receive the final Basalam media ID and URL
- Ensured temporary remote media files are removed after both successful and failed uploads
- Streamed remote media downloads and multipart uploads with size-aware limits and timeouts to support large product videos without buffering the entire file in PHP memory

</details>

<details>

<summary>1.10.1 - 2026-07-13</summary>

### Fixed
- When Basalam rejects a product create/update with `422` because the description contains forbidden content (social-network names, page/channel mentions, etc.), the plugin now extracts the flagged phrases — highlighted inside `<em>...</em>` in the response `snippet` — strips them from the description, and automatically retries (up to 3 times). Previously the sanitizer looked for a non-existent `value` field, so nothing was removed and the retry never helped

</details>

<details>

<summary>1.10.0 - 2026-07-11</summary>

### Added
- Merged the "Woosalam Plus" add-on into the core plugin as a built-in "Financial Management" section: view store balance, active settlement requests, settlement history, and submit settlement requests to wallet or bank account
- Added the `customer.identity.read` scope to the Basalam OAuth connection request (required to fetch the user's bank account list in the settlement flow)

### Note
- Users who connected to Basalam before this release must reconnect ("Connect to Basalam") once so the new scope is granted before the bank-account settlement feature works

</details>

<details>

<summary>1.9.2 - 2026-07-08</summary>

### Security
- Fixed a CSRF vulnerability in the Basalam OAuth callback (`basalam-save-token`) that could let a forged request overwrite the stored connection credentials; the callback now requires a single-use, time-limited authorization that the admin actively started, and the "connect to Basalam" links go through a nonce-protected request
- Hardened output escaping across admin pages, templates and exception messages (esc_html/esc_attr)
- Switched internal redirects to wp_safe_redirect and documented direct custom-table queries

### Changed / Improved
- Tested up to WordPress 7.0

</details>

<details>

<summary>1.9.1 - 2026-06-30</summary>

### Fixed
- show check-orders button and vertically center its content on mobile/tablet

</details>

<details>

<summary>1.9.0 - 2026-06-21</summary>

### Added
- add gold product type with purity and weight attributes
- add preparation days management for product categories

### Fixed
- decrease min pkg weight

</details>

<details>

<summary>1.8.8 - 2026-05-18</summary>

### Fixed
- fix City/state mapping with persian shiping woo
- increase count of photo limit


</details>

<details>

<summary>1.8.7 - 2026-05-16</summary>

### Added
- Product video upload support (VideoService, VideoField, VideoSourceResolver)
- Circuit breaker alert notifications
- replace toast with alert()
- Limit on increase precent(max 35%)
- Migration 1.8.7

### Fixed
- Improved file upload, lock manager, and API response handling

</details>

<details>

<summary>1.8.5,1.8.6 - 2026-05-10</summary>

### Fixed
- deduplictaion orders
- fix stuck-processing

</details>

<details>


<details>

<summary>1.8.4 - 2026-05-03</summary>

### Fixed
- Circuit Breaker mechanism

</details>

<details>

<summary>1.8.3 - 2026-05-03</summary>

### Added
- Added bulk edit support for wholesale settings, product type, and custom price increase
- Added per-product custom price increase support
- Added an information API endpoint to improve AI agent support in `admin.hamsalam.ir`

### Changed / Improved
- Added automatic order creation in status change events when the order does not already exist
- Clear saved token on `401 Unauthorized` responses from `*.basalam.com` requests
- Show an error when required Woosalam domains are blocked
- Prevent task execution when required Woosalam domains are blocked

### Fixed
- Fixed user-reported issues

</details>

<details>

<summary>1.8.2 - 2026-04-04</summary>

### Added
- Photo and weight validator for products
- Filter hook for contact-us section visibility
- Prefix and suffix to product title for category detection

### Fixed
- Fetch current vendor info without cache
</details>

<details>

<details>

<summary>1.8.1 - 2026-03-07</summary>

### Added
- basalam Chat widget

</details>

<details>

<summary>1.8.0 - 2026-03-05</summary>

### Changed / Improved
- Introduced an internal DI container (`ContainerInterface`, `Container`, `ServiceProviderInterface`) and centralized service provider wiring.
- Added `syncBasalamContainer()` and migrated `syncBasalamPlugin()` / `syncBasalamSettings()` to container-backed access.
- Migrated core hotspots from direct instantiation to container resolution:
  - `ApiServiceManager`
  - `ProductOperations`
  - `JobManager`
  - class-level settings access
- Refactored job subsystem wiring for constructor-based dependencies (`AbstractJobType`, job types, `JobRegistry`, `JobsRunner`, `DiscountTaskScheduler`).
- Updated action and registrar edges to resolve handlers/listeners via container.
- Removed legacy singleton-style accessors in core services and standardized on container-first resolution.
- add unInstall
- Added dashboard data retrieval in tickets
- Added `vendor_id` suffix to product `postmeta`
- Added selectable stock priority for variable products
- Added a Circuit Breaker mechanism



### Added
- Container-backed settings access via `SettingsContainer`.

</details>

<details>

<summary>1.7.9 - 2026-02-25</summary>

### Changed / Improved
- Added plugin version to all admin CSS/JS enqueue calls for proper cache busting

</details>


<details>

<summary>1.7.8 - 2026-02-21</summary>

### Added
- retry system for jobs
- Added announcements section
- Added file upload capability in tickets
- Added discount reduction percentage setting
- Added ability to fetch and sync Basalam orders up to the last 30 days
- Added customer name prefix/suffix settings for Basalam orders
- Added onboarding section to Woosalam

### Changed / Improved

- Remove Product Operation type
- change fetch unsync orders structure
- Switched product list fetching from paginate-based API to cursor-based API
- Skip webhook creation when the site domain is localhost
- Made Woosalam addonable/extensible
- Categorized plugin settings

### Fix
- Fixed ticket links issues
- Fixed duplicated products issue
- Fixed null value being sent to Basalam on duplicated product disconnect/update flow

</details>

<details>

<summary>1.7.7 - 2026-02-07</summary>

### Changed / Improved

- Enhanced Performance and Security in Ticket Logics
- Don't send null value in update product at basalam

</details>


<details>

<summary>1.7.6 - 2026-02-01</summary>

### Fix

- Remove Unused Migration

</details>

<details>

<summary>1.7.5 - 2026-01-26</summary>

### Added

- Added Ticketing feature

</details>

<details>

<summary>1.7.4 - 2026-01-6</summary>

### Fixed

- Fix Bugs

</details>

<details>

<summary>1.7.3 - 2025-12-27</summary>

### Fixed

- Fix Bugs

</details>

<details>

<summary>1.7.2 - 2025-12-24</summary>

### Fixed

- Compatibity beetwin HPOS and Legay System
- Fix webhook handling and authorization in WebhookService

</details>

<details>

<summary>1.7.1 - 2025-12-23</summary>

### Fixed

- Add Missed Files

</details>


<details>

<summary>1.7.0 - 2025-12-20</summary>

### Added

- Added PSR-4 autoloading structure for better code organization

### Changed / Improved

- Enhanced plugin code structure and performance optimizations
- Added detailed order information display on orders page
- Improved category ID retrieval with reverse order output

### Fixed

- Fixed commission calculation issues
- Fixed order fetching problems
- Fixed Persian WooCommerce Shipping plugin compatibility issues

</details>

<details>
<summary>1.6.1 - 2025-12-6</summary>

### Changed / Improved
- Handle cursor for get order lists

### Fixed
- don't send request to core.basalam.com
- add reduce/increase stock action for woosalam order statuses

</details>

<details>
<summary>1.6.0 - 2025-12-1</summary>
### Changed / Improved
- Added automatic shipping method detection and auto-saving of the shipping name and cost in WooCommerce orders

</details>

<details>
<summary>1.5.9 - 2025-11-27</summary>

### Fixed

- Add missing default settings to database and return default for single missing setting
- Handle customer names without spaces
- Correct Persian WooCommerce path

### Refactored

- Simplify check if Persian WooCommerce Shipping plugin is active

</details>

<details>
<summary>1.5.8 - 2025-11-18</summary>

### Changed / Improved

- Improved batch product update functionality
- Enhanced complete product update process

</details>

<details>
<summary>1.5.7 - 2025-11-17</summary>

### Added

- Added compatibility with Tappin
- Added support for currency units irht and irhr

### Changed / Improved

- Implemented automatic price rounding for Basalam compatibility
- Changed product enqueue structure from offset-based to ID-based
- Made WooCommerce order creation process atomic

### Fixed

- Fixed job manager functionality and proper task execution

</details>

<details>
<summary>1.5.5 - 2025-11-15</summary>

### Changed / Improved

- Optimized fetch creatable products query using direct SQL for better performance
- Improved API service manager to properly decode JSON responses
- Enhanced update job detection logic to avoid false positive job status checks

### Fixed

- Fixed API response handling by properly decoding JSON data
- Removed commented-out legacy code from plugin initialization
- Fixed quick update job status detection in admin dashboard
- Resolved duplicate job checking that was causing incorrect "in progress" states

</details>

<details>
<summary>1.5.4 - 2025-11-6</summary>

### Added

- Migration system for version 1.5.4

### Changed / Improved

- Enhanced onboarding styles and UI improvements
- Improved category fetching process with better error handling
- Enhanced file uploader service functionality
- Optimized product update process

### Fixed

- Fixed error handling in product update service
- Removed deprecated photo ban status service
- Fixed category mapping page display and functionality
- Improved product meta box data handling
- Fixed issues in get product data service

</details>

<details>
<summary>1.5.3 - 2025-10-25</summary>

### Fixed

- Fixed some bugs

</details>

<details>
<summary>1.5.0 - 2025-10-04</summary>

### Added

- Key-value storage system for plugin settings
- Option to select sale price display style (strikethrough or sale price only)
- Automatic log file cleanup (weekly)
- Manual and automatic task execution rate control (tasks per minute)

### Changed / Improved

- Removed WP Cron dependency for better performance
- Enhanced task scheduling system

### Fixed

- Removed invalid variations from product data
- Fixed category attributes synchronization issue
- Fixed sale price validation before applying
- Resolved migration 1.4.0 issues

</details>

<details>
<summary>1.4.0 - 2025-09-12</summary>

### Added

- Manual product category mapping
- Strikethrough pricing for products
- Add products from Digikala (Pro version)
- Create a free store (Pro version)

### Changed / Improved

- Plugin structure optimization

### Fixed

- Fixed reported issues

</details>

<details>
<summary>1.3.0 - 2025-07-23</summary>

### Added

- Option to choose operation execution method (AJAX and Queue)
- Manual order fetching
- Cancel orders
- Fetch order changes from Basalam and apply them to WooCommerce orders

### Fixed

- Fixed reported issues

</details>

<details>
<summary>1.2.0 - 2025-06-27</summary>

### Added

- Bulk product addition
- Bulk product update
- Auto order confirmation

### Fixed

- Fixed issues reported in previous version

</details>

<details>
<summary>1.1.0 - 2025-02-13</summary>

### Added

- Automatic fetching and creation of Basalam orders in WooCommerce
- Manage orders from WooCommerce (confirm order, request delay, ship order)
- Advanced settings for easier use

### Fixed

- Fixed some issues from previous versions

</details>

<details>
<summary>1.0.0 - 2024-12-31</summary>

### Added

- Add and update products individually and in bulk
- Automatic product synchronization
- Settings and logs page

</details>
