<div align="center">

# 📤 GF External Entry Export

### Share Gravity Forms data securely — without giving away admin access

[![WordPress](https://img.shields.io/badge/WordPress-5.8+-blue.svg)](https://wordpress.org/)
[![Gravity Forms](https://img.shields.io/badge/Gravity%20Forms-2.5+-orange.svg)](https://www.gravityforms.com/)
[![PHP](https://img.shields.io/badge/PHP-7.4+-purple.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPL--2.0+-green.svg)](LICENSE)

</div>

---

## 🎯 What Does This Plugin Do?

**In one sentence:** Generate secure, time-limited download links so external partners can download form entries as CSV — without logging into WordPress.

```
┌─────────────────┐     Generate Link     ┌─────────────────┐     Click Link     ┌─────────────────┐
│   Admin User    │ ──────────────────▶  │  Secure URL     │ ──────────────────▶ │  External User  │
│  (WordPress)    │                       │  (Time-Limited) │                     │  (Any Browser)  │
└─────────────────┘                       └─────────────────┘                     └─────────────────┘
                                                   │
                                                   ▼
                                          📊 CSV Downloads
                                          (Selected Fields Only)
```

---

## 🤔 Why Use This?

| ❌ Without This Plugin | ✅ With This Plugin |
|------------------------|---------------------|
| Give vendors WordPress admin access | Vendors get a simple download link |
| Manually export & email CSV files | Self-service, automated downloads |
| Full data exposure risk | Only selected fields are shared |
| No audit trail | Every download is logged |
| Complex API setup needed | Zero technical knowledge required |

---

## 📋 Table of Contents

1. [Installation](#-installation)
2. [Quick Start Guide](#-quick-start-guide-5-minutes)
3. [For External Users](#-for-external-users-share-this-section)
4. [Configuration Options](#-configuration-options)
5. [FAQ](#-frequently-asked-questions)
6. [Troubleshooting](#-troubleshooting)
7. [For Developers](#-for-developers)

---

## 📦 Installation

### Option 1: Manual Upload (Easiest)

1. **Download** this plugin folder
2. **Upload** to `/wp-content/plugins/gf-external-entry-export/`
3. **Activate** in WordPress → Plugins
4. **Done!** Look for "External Export" in your Forms menu

### Option 2: Via Composer

```bash
composer require izzygld/gf-external-entry-export
```

### Requirements

| Requirement | Minimum Version |
|-------------|-----------------|
| WordPress | 5.8+ |
| PHP | 7.4+ |
| Gravity Forms | 2.5+ |

---

## 🚀 Quick Start Guide (5 Minutes)

### Step 1: Enable Export for Your Form

> **Where:** Forms → [Select Your Form] → Settings → External Export

1. ✅ Check **"Allow generating external export links"**
2. ✅ Select which fields can be exported (only these will appear in CSV)
3. 💾 Click **Save Settings**

```
💡 TIP: Only enable fields that are safe to share externally.
        Sensitive fields like passwords should NOT be selected.
```

### Step 2: Generate a Download Link

> **Where:** Forms → External Export Links

1. Select your form from the dropdown
2. Pick the fields to include in this export
3. Choose link expiration:
   - 🕐 1 Hour (most secure)
   - 🕕 6 Hours
   - 📅 24 Hours (recommended)
   - 📆 7 Days
   - 📆 30 Days
   - ♾️ Never expires
4. *(Optional)* Set date range filter
5. Click **"Generate Export Link"**
6. 📋 **Copy the URL** — that's it!

### Step 3: Share the Link

Send the URL to your external partner via:
- 📧 Email
- 💬 Slack/Teams
- 📱 Text message
- 🔗 Any way you communicate!

---

## 👥 For External Users (Share This Section)

### How to Download Your Data

**You've received a download link. Here's what to do:**

1. **Click the link** (or paste it into your browser)
2. **CSV file downloads automatically**
3. **Open in Excel, Google Sheets, or any spreadsheet app**

That's it! No login required.

### What to Expect

| ✅ You CAN | ❌ You CANNOT |
|-----------|---------------|
| Download the CSV file | Access other data |
| Open in any spreadsheet app | Log into WordPress |
| Download multiple times (until limit) | Modify any data |
| Share the link (if allowed) | See fields not selected by admin |

### Link Not Working?

The link may have:
- ⏰ **Expired** — Ask the admin for a new link
- 🔢 **Hit download limit** — Ask for a new link
- 🚫 **Been revoked** — Contact the admin

---

## ⚙️ Configuration Options

### Global Settings

> **Where:** Forms → Settings → External Export

| Setting | What It Does | Recommended |
|---------|--------------|-------------|
| **Default Expiration** | How long links stay valid | 24 Hours |
| **Max Downloads** | Times a link can be used | 10 |
| **Access Logging** | Track all downloads | ✅ Enabled |
| **Secret Key** | Encryption key (auto-generated) | Leave as-is |
| **IP Allowlist** | Restrict to specific IPs | Optional |

### Per-Form Settings

> **Where:** Forms → [Form Name] → Settings → External Export

| Setting | What It Does |
|---------|--------------|
| **Enable Export** | Turn on/off for this form |
| **Exportable Fields** | Which fields appear in CSV |
| **Entry Metadata** | Include ID, date, status, IP |
| **Default Status** | Active, spam, trash, or all |
| **Date Filtering** | Allow date range selection |

---

## ❓ Frequently Asked Questions

<details>
<summary><strong>Is this secure?</strong></summary>

Yes! Links use cryptographic signing (HMAC-SHA256). External users:
- Cannot guess valid URLs
- Cannot access other forms
- Cannot modify any data
- Can only download what you've allowed

</details>

<details>
<summary><strong>Can I revoke a link after sharing it?</strong></summary>

Yes! Go to **Forms → External Export Links**, find the link in the table, and click **Revoke**. It stops working immediately.

</details>

<details>
<summary><strong>What format is the export?</strong></summary>

CSV (Comma-Separated Values). Opens in Excel, Google Sheets, Numbers, or any spreadsheet software.

</details>

<details>
<summary><strong>Can external users edit the data?</strong></summary>

No. They can only download. The link is read-only.

</details>

<details>
<summary><strong>How do I know if someone downloaded the file?</strong></summary>

Enable **Access Logging** in settings. Every download is logged with timestamp and IP address.

</details>

<details>
<summary><strong>Can I limit who downloads?</strong></summary>

Yes! Use the **IP Allowlist** to restrict downloads to specific IP addresses.

</details>

<details>
<summary><strong>What happens when a link expires?</strong></summary>

The external user sees an error message. Generate a new link if needed.

</details>

<details>
<summary><strong>Can I include/exclude specific entries?</strong></summary>

You can filter by:
- Date range (start/end date)
- Entry status (active, spam, trash)

Individual entry selection is planned for v2.

</details>

---

## 🔧 Troubleshooting

### "Link not working" / 403 Error

| Cause | Solution |
|-------|----------|
| Link expired | Generate a new link |
| Download limit reached | Generate a new link |
| Link was revoked | Generate a new link |
| IP not in allowlist | Add user's IP or disable allowlist |

### "Form not showing in dropdown"

1. Go to Forms → [Your Form] → Settings → External Export
2. Enable "Allow generating external export links"
3. Save settings

### "No fields available to select"

1. Go to form settings → External Export
2. Select which fields should be exportable
3. Save settings

### CSV opens with weird characters

The CSV includes a UTF-8 BOM for Excel compatibility. If you see strange characters:
- In Excel: Open via Data → From Text/CSV
- In Google Sheets: Should work automatically

---

## 👨‍💻 For Developers

<details>
<summary><strong>REST API Endpoints</strong></summary>

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/gf-eee/v1/export` | GET | Token | Public download |
| `/gf-eee/v1/preview` | GET | Admin | Entry count |
| `/gf-eee/v1/form-fields/{id}` | GET | Admin | Field list |
| `/gf-eee/v1/links` | GET | Admin | Active links |

</details>

<details>
<summary><strong>Available Hooks</strong></summary>

```php
// Modify search criteria
add_filter( 'gf_eee_search_criteria', function( $criteria, $filters ) {
    return $criteria;
}, 10, 2 );

// Modify field map
add_filter( 'gf_eee_field_map', function( $field_map, $form, $fields ) {
    return $field_map;
}, 10, 3 );

// Transform field values
add_filter( 'gf_eee_field_value', function( $value, $entry, $field, $form ) {
    return $value;
}, 10, 4 );

// Modify final CSV
add_filter( 'gf_eee_csv_content', function( $csv, $entries, $form ) {
    return $csv;
}, 10, 3 );
```

</details>

<details>
<summary><strong>Generate Link Programmatically</strong></summary>

```php
$addon = gf_external_entry_export();

$result = $addon->token_handler->generate_token([
    'form_id'     => 1,
    'fields'      => ['field_1', 'field_2'],
    'description' => 'Export for Vendor ABC',
    'filters'     => [
        'start_date' => '2024-01-01',
        'end_date'   => '2024-12-31',
        'status'     => 'active',
    ],
], 24); // Hours until expiration

// $result = [
//     'token_id'   => 'abc123...',
//     'url'        => 'https://...',
//     'expires_at' => '2024-01-02 12:00:00',
//     'form_id'    => 1,
// ]
```

</details>

<details>
<summary><strong>Database Tables</strong></summary>

**Tokens Table:** `{prefix}_gf_eee_tokens`
- Token ID, hash, form ID
- Fields, filters (JSON)
- Expiration, download counts
- Revocation status

**Logs Table:** `{prefix}_gf_eee_access_logs`
- Access timestamp
- IP, user agent
- Success/failure status

Both tables are removed on plugin uninstall.

</details>

---

## 🔒 Security Details

| Feature | Implementation |
|---------|----------------|
| Token signing | HMAC-SHA256 |
| Token format | Base64(token_id \| form_id \| signature) |
| Expiration | Server-side validation |
| CSV injection | Prefix dangerous chars with `'` |
| Input sanitization | WordPress sanitization functions |

---

## 📄 License

GPL-2.0-or-later — Free to use, modify, and distribute.

---

## 🙋 Support

Having issues? Check the [Troubleshooting](#-troubleshooting) section or open an issue on GitHub.

---

<div align="center">

**Made with ❤️ for the Gravity Forms community**

</div>
- PHP 7.4+
- Gravity Forms 2.5+

## License

GPL-2.0-or-later

## Support

This addon is provided as-is for educational and demonstration purposes. For production use, consider additional security hardening based on your specific requirements.

## Changelog

### 1.0.1
- **Security**: Fixed rate limiting to properly sanitize and validate `REMOTE_ADDR` server variable
- **Code Quality**: Added PHPCS inline comments for legitimate prepared SQL queries using safe table name interpolation
- **UX**: Added admin notice and auto-deactivation when Gravity Forms is not active

### 1.0.0
- Initial release
- Core token generation and validation
- CSV export with field selection
- Admin UI for link management
- REST API endpoints
- Access logging
- IP allowlist support
