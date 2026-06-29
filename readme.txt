=== SkyHS - Sell Domain, Cpanel Hosting and Subscription using WooCommerce ===
Contributors: siteskyline
Tags: hosting, woocommerce, domain, whm, cpanel
Requires at least: 6.9
Tested up to: 6.9
Stable tag: 1.0.6
Requires PHP: 7.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A comprehensive web hosting management and domain registration solution for WordPress using WooCommerce.

== Description ==

A comprehensive web hosting management solution for WordPress, built on top of WooCommerce. It provides a complete system for selling, managing, and supporting hosting plans and domain registrations through WordPress with built-in subscription management.

== Features ==

### Hosting Management
* **WHM/cPanel Integration:** Automatically provision, suspend, and manage hosting accounts directly through WHM/cPanel.
* **AJAX cPanel Account Search:** Find and select cPanel accounts instantly with autocomplete search — no more scrolling through long dropdowns.
* **Server Management:** Easily define and manage multiple servers.
* **Automated Suspension:** Automatically suspend or unsuspend hosting accounts based on customer payment status.
* **Automated Backup Manager:** Scheduled, secure backups of critical hosting data with configurable storage integration.

### Domain Registration & Management
* **eNom Integration:** Native API integration for real-time domain registration and transfers.
* **Domain Sync:** Automated domain synchronization with local caching and paginated owner lookup.
* **Domain Search:** Built-in domain availability checker so customers can find their perfect domain before purchasing.
* **Public Domain Lookup:** Allow non-logged-in users to check domain availability before signing up.
* **Domain Transfer:** Allow customers to transfer existing domains from other registrars with EPP authorization code support. Transfer includes 1-year renewal.
* **DNS Management:** Empower customers to manage their DNS records directly from their dashboard.

### Email Campaign Management
* **Email Campaign Module** — Create, edit, and send targeted email campaigns directly from the admin panel with queue management and scheduling.
* **Campaign Confirmation Modal** — Confirmation prompt before sending to prevent accidental sends.
* **Smart Deduplication** — Automatically deduplicates campaign targets by user, ensuring each customer receives the email once.
* **Flexible Targeting** — Target customers by subscription status, product, or custom selection.

### Built-in Subscription & Billing Engine
* **No Extra Plugins Needed:** Includes a fully independent recurring billing system—no need for expensive third-party subscription plugins.
* **Flexible Renewals:** Allow customers to pay invoices manually, renew plans early, or disable auto-payment from their dashboard.
* **Smart Payment Retries:** Automatically retry failed subscription payments using customizable retry rules.
* **Subscription Switching:** Let customers upgrade or downgrade their hosting plans with full grouped product support and automatic proration.
* **On-Hold Grace Period:** Place subscriptions on-hold with a visible countdown and automatic termination after the grace period expires.
* **Deletion Warning Emails:** Send customizable scheduled email alerts before subscription data is permanently deleted.
* **Invoice History View:** Customers can browse their complete invoice history directly from the subscription management area.
* **Coupons & Free Trials:** Support for recurring discounts and packages with a zero-dollar initial setup fee.
* **Drip Downloads:** Schedule downloadable content delivery over time with configurable drip settings.

### Seamless WooCommerce Integration
* **Custom Product Types:** Sell hosting packages and domains like any standard WooCommerce product.
* **Product Management UI:** Dedicated interface to manage hosting products with add, edit, and delete functionality.
* **AJAX-Powered Product Search:** Quickly find and assign hosting products and owners with autocomplete search fields.
* **Server-Side Pagination & Filtering:** Product listing and dashboard tables use AJAX pagination for faster, smoother browsing.
* **Optimized Checkout:** Custom cart and checkout experiences tailored specifically for domains and recurring subscriptions.
* **Flexible Payment Gateways:** Let customers change their payment methods mid-subscription, including PayPal handler support.
* **Role Management:** Granular role-based access control with a dedicated settings panel.

### Subscription Management Enhancements
* **Inline Subscription Editing** — Edit subscription details (product, amount, billing period, dates, status) directly from admin via AJAX-powered modal, accessible from Subscriptions, Hosting, WP Sites, and Domains pages.
* **Product Filtering & Sorting** — Filter subscriptions by product and sort by date, customer name, product name, or next payment date.
* **Hosting Without cPanel** — Create hosting records without requiring a cPanel account, with a new "Create hosting only — no cPanel" option.

### Advanced Client Portal
* **Dedicated Client Dashboard:** A redesigned, modern dashboard with enhanced status indicators, grid layout, icon-based navigation, and dynamic header titles for clients to manage their hosting and domains.
* **Guest Dashboard Access:** Configurable guest access with custom welcome branding, navigation UI, and promotional buttons for pre-registration users.
* **WooCommerce Account Integration:** Seamlessly adds hosting and domain management panels into the standard WooCommerce "My Account" area.
* **Account Collaborators:** Allow account owners to grant secure access to their team members or web developers without sharing passwords.
* **Customizable Branding:** Set your own dashboard logo, site name, and welcome branding to match your brand identity.
* **Dashboard Menu Builder:** Drag-and-drop menu editor to customize client dashboard navigation with custom endpoints and icons.
* **Profile Dropdown Menu:** Modern user dropdown with quick access to account settings, logout, and return-to-site URL.
* **Custom Header Navigation Menu:** Add configurable navigation links to the dashboard header with flexible styling options.
* **Dashboard UI Refinements:** Improved IP and Nameserver display styling for a cleaner overview.

### WordPress Site Management
* **Automated WordPress Provisioning:** Fully automated WordPress installation on WHM/cPanel — creates addon domains, MySQL databases, installs WP core, and configures security hardening rules.
* **Custom Admin Credentials:** Set your own WordPress admin username, password, and email during provisioning.
* **Animated Provisioning Dashboard:** Real-time progress indicators showing each step of the WordPress installation process.
* **Recommended Plugin Installation:** Automatically install and activate plugins from WordPress.org during site provisioning.
* **Server IP & Custom Nameservers:** Display server IP and custom nameserver details in provisioning emails for DNS configuration.
* **WP Sites Admin Panel:** Dedicated admin interface with search, pagination, status filters, and bulk management of provisioned sites.
* **Add WordPress Site Form:** Quickly create new WP sites from admin with product, owner, and subscription linking.
* **Subscription Parent Order Links:** Parent order IDs are linked directly to their edit pages for easy order lookup.

### Logging & Debugging
* **Toggleable WooCommerce Logging** — Log hosting, domain, server, WordPress, and subscription failures to WooCommerce logs with an easy on/off toggle in settings.

### Security & Automation
* **Auto-SSL Certificate Generation:** Automatically triggers cPanel AutoSSL for every new WordPress installation.
* **HTTPS Security Hardening:** Injects rewrite rules, blocks PHP in uploads, prevents author enumeration, disables xmlrpc.php, and sets security headers.
* **Persistent Activity Log:** Tracks all subscription events (provisioning, renewals, suspensions) with a searchable admin log viewer.
* **Automated Data Backups:** Scheduled backups with secure off-site storage to protect against data loss.

== Installation ==

1. Upload the plugin folder to the /wp-content/plugins/ directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Configure the plugin settings under the Hosting Solution menu.

== External services ==

This plugin connects to eNom (www.enom.com) to provide domain registration and management services.

* **What is sent:** When registering or managing a domain, your account credentials and the domain contact information are sent to eNom.
* **Why it's used:** This service is required for the core functionality of the plugin: domain search, registration, and DNS management.
* **Conditions:** The data is processed according to eNom's terms and conditions and privacy policy.

This service is provided by "eNom":
* [eNom Terms and Conditions](https://www.enom.com/reseller/legal-policy-agreements/certified-reseller-terms-and-conditions/)
* [eNom Privacy Policy](https://www.enom.com/reseller/legal-policy-agreements/privacy-policy/)

== Screenshots ==

1. **Admin Dashboard:** A central hub providing a quick overview of servers, hosting accounts, and registered domains.
2. **Hosting Account List:** Manage all customer hosting accounts, including subscription status and linked domains.
3. **Server Management:** A list of all connected WHM servers within the WordPress admin.
4. **Domain Management:** A comprehensive list of all registered domains and their active status.
5. **eNom Settings:** Configure nameservers, API credentials, and pricing markups for domain registrations.
6. **General Settings:** Configure core plugin options including Test Mode and the Client Dashboard page.
7. **Billing Settings:** Manage grace periods, renewal reminders, and failed payment thresholds.
8. **Email Notifications:** Toggle various automated customer emails for provisioning, suspension, and renewals.
9. **Invoice Settings:** Customize company details and footer text for generated invoices.
10. **Email Templates:** A full HTML editor for customizing the look and feel of automated system emails.
11. **Export Tool:** Export all plugin data (Users, Servers, Orders, Subscriptions) to a UUID-based JSON file.
12. **Import Tool:** Seamlessly migrate data from another SkyHS installation using the import utility.
13. **Guest Welcome Page:** A custom welcome screen for non-logged-in users with direct navigation to hosting plans and domain searches.
14. **Hosting Plans List:** Displays all configured monthly and yearly hosting plans to prospective customers.
15. **Hosting Plan Detail:** Detailed card view of individual hosting plans showing pricing and key features.
16. **Client Dashboard Overview:** The modern front-end dashboard homepage where authenticated clients see an overview of their active services.
17. **Client Hosting Accounts:** A dedicated panel for clients to manage their purchased hosting plans and access support/control options.
18. **Client Domains List:** Allows clients to view their registered domains and manage DNS settings.
19. **Register New Domain:** A real-time search interface for clients to search and check domain availability.
20. **Transfer Domain:** An interface for customers to initiate domain transfers from other registrars using EPP codes.
21. **Client Subscription Management:** A detailed dashboard view for clients to manage their recurring hosting and domain subscriptions.
22. **Admin Subscription List:** A central admin view for store managers to monitor and manage all recurring subscriptions.


== Changelog ==

= 1.0.6 =
* **Email Campaign Module** — Full email campaign management with CRUD, queue, scheduling, and send confirmation modal
* **Smart Target Deduplication** — Each customer receives campaign emails only once, no duplicates
* **Inline Subscription Editing** — Edit subscription details from any admin page (Subscriptions, Hosting, WP Sites, Domains) via AJAX modal
* **Product Filtering & Sorting** — Filter subscriptions by product and sort by multiple criteria on the subscription dashboard
* **Hosting Without cPanel** — New "Create hosting only" option for hosting records without cPanel integration
* **Toggleable WooCommerce Logging** — Log failures across hosting, domain, server, WordPress, and subscriptions with a settings toggle
* **Refactored Subscription Modal** — Extracted into shared component accessible from Hosting, Domains, and WP Sites pages

= 1.0.5 =
* **AJAX-Powered Dropdowns** — cPanel account, hosting products, and owner dropdowns replaced with autocomplete search fields
* **Server-Side AJAX Pagination** — Dashboard tables and product listing migrated to AJAX pagination and filtering
* **On-Hold Subscription Grace Period** — Subscriptions can be placed on-hold with configurable grace period countdown and auto-termination
* **Deletion Warning Emails** — Customizable scheduled email notifications before subscription data deletion
* **Automated Backup Manager** — New backup system for automated data backups with secure storage integration
* **Invoice History View** — Interactive invoice history accessible from subscription management in admin and dashboard
* **Custom Header Navigation Menu** — Configurable header navigation menu with settings and styling options
* **WordPress Site Admin Form** — Add WordPress Site panel with product, owner, and subscription linking
* **Subscription Parent Order Links** — Parent order IDs now link directly to their edit pages
* **Subscription Switching Improvements** — Fixed broken subscription switching with proper parent order linking and WP site deployment on switch
* **Dashboard UI Refinements** — Improved IP and Nameserver display styling
* **Various bug fixes and performance enhancements**

= 1.0.4 =
* **Automated WordPress Site Provisioning** — Fully automated WordPress installation on WHM/cPanel with addon domain creation, MySQL database setup, WP core installation, security hardening, and AutoSSL
* **Custom WP Admin Credentials** — Set your own admin username, password, and email during provisioning
* **Animated Provisioning Dashboard** — Real-time progress indicators showing each step of the WordPress installation
* **Recommended Plugin Installation** — Automatically install and activate selected plugins during provisioning
* **Server IP & Custom Nameservers** — Per-server IP and custom nameserver configuration shown in provisioning emails
* **WP Sites Admin Panel** — Redesigned admin interface with search, pagination, status filters, and delete actions
* **Dashboard Menu Builder** — Drag-and-drop menu editor to customize client dashboard navigation with custom endpoints and icons
* **Profile Dropdown Menu** — Modern user dropdown with account settings, logout, and configurable return-to-site link
* **Cart Billing Cycle Display** — Shows "/month" or "/year" on subscription product prices in cart and extends WooCommerce Store API
* **Next Payment Date Filtering** — Filter subscriptions by upcoming (7/30/90 days) or overdue next payment dates
* **Persistent Activity Log** — Searchable admin log tracking all subscription events with date range and type filters
* **Stripe Early Renewal Support** — Generate Stripe Payment Intents for early subscription renewals
* **Configurable Promotional Button** — Add a custom CTA button to the guest dashboard welcome section
* **Guest WordPress Site Creation** — Allow guests to purchase and provision WordPress sites without logging in
* **Subscription ID Column** — Show subscription ID with colored status badge in WP Sites admin table
* **Conditional Domain UI** — Hide domain registration UI across the dashboard when domains are disabled in settings

= 1.0.3 =
* Domain Transfer feature — customers can now transfer existing domains from other registrars directly from their dashboard
* Redesigned client hosting dashboard with enhanced status indicators, grid layout, icon-based navigation, and dynamic header titles
* Customizable branding settings for dashboard logo, site name, and welcome branding
* Guest dashboard access with custom welcome branding and navigation UI for pre-registration users
* Public domain availability lookup — non-logged-in users can check domain availability before signing up
* Product management UI with dedicated add, edit, and delete functionality
* Optimized checkout flow with add-to-cart redirects to checkout
* Improved subscription card UI and layout management
* Various bug fixes and performance enhancements

= 1.0.2 =
* Subscription switching with grouped product support, configuration settings, and frontend toggle buttons
* Early renewal functionality with dedicated settings and management
* Manual renewal and disable auto-payment options on the client dashboard
* PayPal payment method change handler
* Drip downloads, zero initial payment, and independent subscription processing settings
* Role Manager tab in settings with refined dashboard access capabilities
* Security improvements with input sanitization and WordPress coding standard compliance
* Various bug fixes and performance enhancements

= 1.0.1 =
* Streamlined admin experience with custom Server and Product management screens
* Edit hosting products directly from a dedicated interface
* Automated review reminders and quick plugin rating links
* New hosting manager interface with broader subscription product compatibility
* Centralized domain management page with AJAX-powered real-time registration
* Enom domain sync module with local caching for faster domain lookups
* Pagination and automated owner lookup on synced domains
* Overall performance improvements and sanitization enhancements

= 1.0.0 =
* Initial release
