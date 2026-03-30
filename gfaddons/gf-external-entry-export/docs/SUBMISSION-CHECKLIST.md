# WordPress.org Plugin Submission Checklist

## GF External Entry Export - Submission Guide

This document outlines the steps to submit the plugin to the WordPress.org plugin repository.

---

## Pre-Submission Checklist

### Required Files ✅

- [x] `readme.txt` - WordPress.org standard format
- [x] `gf-external-entry-export.php` - Main plugin file with proper headers
- [x] `index.php` files in all directories (security)
- [x] GPL-2.0-or-later license declared

### Plugin Header Requirements ✅

```php
Plugin Name: GF External Entry Export
Plugin URI: https://github.com/izzygld/gf-external-entry-export
Version: 1.0.1
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: gf-external-entry-export
Domain Path: /languages
```

### Security Requirements ✅

- [x] All user inputs sanitized (`sanitize_text_field`, `absint`, etc.)
- [x] All outputs escaped (`esc_html`, `esc_attr`, `esc_url`, etc.)
- [x] Nonce verification on all forms/AJAX
- [x] Capability checks (`current_user_can`)
- [x] Direct file access prevention (`defined('ABSPATH')`)
- [x] SQL injection prevention (`$wpdb->prepare`)
- [x] No external tracking/analytics
- [x] No obfuscated code

### Guideline Compliance ✅

- [x] Unique function/class prefixes (`GF_EEE_*`, `gf_eee_*`)
- [x] No hardcoded external URLs
- [x] Proper WordPress API usage
- [x] Internationalization ready
- [x] Clean uninstall (removes all data)

---

## Submission Steps

### Step 1: Run Plugin Check

1. Install the [Plugin Check plugin](https://wordpress.org/plugins/plugin-check/)
2. Go to **Tools → Plugin Check**
3. Select "GF External Entry Export"
4. Run all checks
5. Resolve any errors (warnings may be acceptable)

### Step 2: Create ZIP Package

```bash
cd /path/to/gfaddons
zip -r gf-external-entry-export.zip gf-external-entry-export \
  -x "*.DS_Store" \
  -x "*/.git/*" \
  -x "*/node_modules/*" \
  -x "*composer.lock" \
  -x "*/docs/*"
```

### Step 3: Submit to WordPress.org

1. Go to: https://wordpress.org/plugins/developers/add/
2. Log in with your WordPress.org account
3. Confirm the checkboxes:
   - [x] I have read the Frequently Asked Questions
   - [x] This plugin complies with all Plugin Developer Guidelines
   - [x] I have permission to upload this plugin
   - [x] Plugin and libraries are GPL-compatible
   - [x] Tested with Plugin Check plugin
4. Upload the ZIP file
5. Wait for review (typically 1-7 days)

### Step 4: After Approval

1. You'll receive SVN access details via email
2. Set up your local SVN checkout
3. For updates, commit to trunk and tag releases

---

## Common Review Issues to Avoid

| Issue | Status |
|-------|--------|
| Missing sanitization/escaping | ✅ Fixed |
| External HTTP calls without disclosure | ✅ None present |
| Hardcoded paths or URLs | ✅ None present |
| Missing readme.txt | ✅ Added |
| Generic function names | ✅ All prefixed |
| Including development files | ⚠️ Exclude in ZIP |

---

## Files to Exclude from Distribution

These files should NOT be included in the WordPress.org SVN:

- `.DS_Store`
- `.git/`
- `composer.json` (optional, some include it)
- `composer.lock`
- `docs/` folder (contains SHOWCASE.md, EXTERNAL-USER-GUIDE.md, SUBMISSION-CHECKLIST.md)

---

## Version Checklist

Before each release, verify versions match:

| Location | Current |
|----------|---------|
| `gf-external-entry-export.php` header | 1.0.1 |
| `GF_EXTERNAL_ENTRY_EXPORT_VERSION` constant | 1.0.1 |
| `readme.txt` Stable tag | 1.0.1 |
| `composer.json` version | 1.0.1 |
| `CHANGELOG.md` latest entry | 1.0.1 |

---

## Post-Submission

After approval:

1. **Add Screenshots** - Upload to `/assets/` in SVN:
   - `screenshot-1.png` (or .jpg)
   - `screenshot-2.png`
   - etc.

2. **Add Banner Images** (optional):
   - `banner-772x250.png`
   - `banner-1544x500.png` (hi-DPI)

3. **Add Icon** (optional):
   - `icon-128x128.png`
   - `icon-256x256.png` (hi-DPI)

---

## Resources

- [Plugin Developer Handbook](https://developer.wordpress.org/plugins/)
- [Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
- [Plugin Check Plugin](https://wordpress.org/plugins/plugin-check/)
- [readme.txt Validator](https://wordpress.org/plugins/developers/readme-validator/)
- [SVN Book](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/)

---

*Last updated: March 30, 2026*
