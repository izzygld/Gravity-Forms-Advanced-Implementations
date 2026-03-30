=== GF External Entry Export ===
Contributors: izzygld
Donate link: https://github.com/izzygld
Tags: gravity forms, export, csv, external, secure download
Requires at least: 5.8
Tested up to: 6.7
Stable tag: 1.0.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate secure, time-limited download links for Gravity Forms entries — allow external users to download CSV exports without WordPress admin access.

== Description ==

**GF External Entry Export** enables WordPress administrators to share Gravity Forms entry data securely with external partners, vendors, or clients — without giving them WordPress login credentials.

= Key Features =

* **Secure Token-Based Links** - Generate cryptographically signed URLs using HMAC-SHA256
* **Time-Limited Access** - Set expiration from 1 hour to 30 days (or never)
* **Field Selection** - Choose exactly which form fields to include in exports
* **Download Limits** - Restrict how many times a link can be used
* **IP Allowlisting** - Optionally restrict downloads to specific IP addresses
* **Access Logging** - Track every download with timestamp and IP
* **One-Click Revocation** - Instantly disable any active link
* **Date Range Filtering** - Export entries within specific date ranges
* **Optional Authentication** - Add username/password protection to links

= Use Cases =

* Share form submissions with vendors without WordPress access
* Provide clients with self-service data downloads
* Automate data sharing with external systems
* Create time-limited reports for stakeholders

= Requirements =

* WordPress 5.8 or higher
* PHP 7.4 or higher
* Gravity Forms 2.5 or higher (required)

= Privacy & Security =

This plugin:
* Does NOT send any data to external servers
* Does NOT include tracking or analytics
* Stores all data in your WordPress database
* Uses industry-standard HMAC-SHA256 for token signing
* Implements rate limiting to prevent brute-force attacks

== Installation ==

1. Upload the `gf-external-entry-export` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Ensure Gravity Forms is installed and activated
4. Navigate to Forms → Settings → External Export to configure global settings

= Quick Start =

1. Go to Forms → [Your Form] → Settings → External Export
2. Enable "Allow generating external export links"
3. Select which fields can be exported
4. Save settings
5. Go to Forms → External Export Links
6. Select your form and fields, set expiration
7. Click "Generate Export Link"
8. Share the URL with your external partner

== Frequently Asked Questions ==

= Does this plugin require Gravity Forms? =

Yes, Gravity Forms 2.5 or higher is required. The plugin will show an error and deactivate itself if Gravity Forms is not active.

= Is this secure? =

Yes. Links use HMAC-SHA256 cryptographic signing. External users cannot guess valid URLs, access other forms, or modify any data. Rate limiting prevents brute-force attacks.

= Can I revoke a link after sharing it? =

Yes. Go to Forms → External Export Links, find the link, and click "Revoke". It stops working immediately.

= What format is the export? =

CSV (Comma-Separated Values), compatible with Excel, Google Sheets, Numbers, and other spreadsheet applications.

= Can external users edit the data? =

No. The download links are read-only. External users can only download the CSV file.

= How do I track downloads? =

Enable "Access Logging" in the global settings. Every download is logged with timestamp and IP address. View logs in Forms → External Export Links.

= Can I limit who downloads? =

Yes. Use the "IP Allowlist" setting to restrict downloads to specific IP addresses. You can also add username/password protection to links.

= What happens when a link expires? =

The external user sees an error message. Generate a new link if continued access is needed.

= Can I filter which entries are exported? =

Yes. You can filter by date range (start/end date) and entry status (active, spam, trash).

== Screenshots ==

1. Generate Export Link interface showing form selection and options
2. Admin settings page with global configuration options
3. Active links management table with revoke option
4. Per-form settings to enable export and select fields

== Changelog ==

= 1.0.1 =
* Security: Fixed rate limiting to properly sanitize REMOTE_ADDR server variable
* Improved: Added PHPCS inline comments for legitimate prepared SQL queries
* Added: Admin notice and auto-deactivation when Gravity Forms is not active

= 1.0.0 =
* Initial release
* Core token generation and validation with HMAC-SHA256
* CSV export with field selection
* Admin UI for link management
* REST API endpoints
* Access logging
* IP allowlist support
* Download limits and expiration
* Link revocation

== Upgrade Notice ==

= 1.0.1 =
Security improvement for rate limiting. Recommended update for all users.

= 1.0.0 =
Initial release.

== Developer Documentation ==

= Hooks & Filters =

**Modify search criteria:**
`add_filter( 'gf_eee_search_criteria', function( $criteria, $filters ) {
    return $criteria;
}, 10, 2 );`

**Modify field map:**
`add_filter( 'gf_eee_field_map', function( $field_map, $form, $fields ) {
    return $field_map;
}, 10, 3 );`

**Transform field values:**
`add_filter( 'gf_eee_field_value', function( $value, $entry, $field, $form ) {
    return $value;
}, 10, 4 );`

**Modify final CSV:**
`add_filter( 'gf_eee_csv_content', function( $csv, $entries, $form ) {
    return $csv;
}, 10, 3 );`

= REST API Endpoints =

* `GET /gf-eee/v1/export` - Public download endpoint (token auth)
* `GET /gf-eee/v1/preview` - Entry count preview (admin auth)
* `GET /gf-eee/v1/form-fields/{id}` - Get form fields (admin auth)
* `GET /gf-eee/v1/links` - List active links (admin auth)

= Generate Link Programmatically =

`$addon = gf_external_entry_export();
$result = $addon->token_handler->generate_token([
    'form_id'     => 1,
    'fields'      => ['field_1', 'field_2'],
    'description' => 'Export for Vendor ABC',
    'filters'     => [
        'start_date' => '2024-01-01',
        'end_date'   => '2024-12-31',
        'status'     => 'active',
    ],
], 24); // Hours until expiration`
