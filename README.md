# Gravity Forms Advanced Implementations

Custom add-ons for [Gravity Forms](https://www.gravityforms.com/) that extend its functionality beyond the core plugin.

---

## Vision

Gravity Forms is powerful out of the box — but real-world workflows demand more. This repository is a collection of purpose-built add-ons that solve gaps the core plugin doesn't cover: advanced field types, form utilities, and intelligent form generation. Each add-on is designed to be **production-ready**, **narrowly scoped**, and **easy to demo** so it can stand on its own as a showcase of what's possible with Gravity Forms.

---

## Add-ons

### In This Repo

| Add-on | Description |
|--------|-------------|
| [GF Form Importer](gfaddons/gf-form-importer/) | Import Gravity Forms form configurations from external sources. |
| [GF List Column Required](gfaddons/gf-list-column-required/) | Make individual columns required in Gravity Forms List fields. |

### Standalone Repos

| Add-on | Description | Status |
|--------|-------------|--------|
| [Izzygld Entry Export for Gravity Forms](https://github.com/izzygld/izzygld-entry-export-for-gravity-forms) | Generate secure, time-limited CSV export links for external users — no WordPress admin access required. | Submitted to WordPress.org |

---

## Getting Started

### Requirements

| Dependency | Minimum Version | Link |
|---|---|---|
| Gravity Forms | **2.9.29+** | [Latest Release](https://www.gravityforms.com/gravity-forms-changelog/) |
| WordPress | 5.8+ | [wordpress.org](https://wordpress.org/) |
| PHP | 7.4+ | |

### Installation

1. Make sure Gravity Forms is installed and activated.
2. Clone this repository or download the add-on folder you need:
   ```bash
   git clone https://github.com/izzygld/Gravity-Forms-Advanced-Implementations.git
   ```
3. Copy the desired add-on folder into your `wp-content/plugins/` directory.
4. Activate the plugin from **Plugins → Installed Plugins** in WordPress admin.
5. Configure the add-on under **Forms → Settings** in the Gravity Forms menu.

---

## Roadmap

| # | Add-on | Status | Description |
|---|--------|--------|-------------|
| 1 | **Izzygld Entry Export for Gravity Forms** | ✅ Submitted to WP.org | Moved to [its own repo](https://github.com/izzygld/izzygld-entry-export-for-gravity-forms). |
| 2 | **GF Signature Field** | 📋 Planned | Custom signature-capture field following clean field-extension patterns. |
| 3 | **PDF / Website → Form Converter** | 💡 Concept | Automatically convert an existing PDF or web page into a Gravity Forms form. |

---

## Repository Structure

```
gfaddons/
  gf-form-importer/            # Form configuration importer
  gf-list-column-required/     # Required columns for List fields
```

---

## Contributing

Contributions, bug reports, and feature ideas are welcome. Please open an issue or pull request on the [GitHub repository](https://github.com/izzygld/Gravity-Forms-Advanced-Implementations).

---

## License

GPL-2.0-or-later
