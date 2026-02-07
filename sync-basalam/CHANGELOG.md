# Changelog

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
