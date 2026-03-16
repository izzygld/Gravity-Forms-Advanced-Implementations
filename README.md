# Gravity Forms Advanced Implementations

Custom add-ons for [Gravity Forms](https://www.gravityforms.com/) that extend its functionality beyond the core plugin.

---

## Vision

Gravity Forms is powerful out of the box — but real-world workflows demand more. This repository is a collection of purpose-built add-ons that solve gaps the core plugin doesn't cover: secure external data sharing, advanced field types, and intelligent form generation. Each add-on is designed to be **production-ready**, **narrowly scoped**, and **easy to demo** so it can stand on its own as a showcase of what's possible with Gravity Forms.

---

## Key Features

- **Secure External Sharing** — Give outside partners access to form data without exposing WordPress admin.
- **Field Extensions** — New field types that follow Gravity Forms' own architecture patterns.
- **Minimal Footprint** — Each add-on is self-contained with no unnecessary dependencies.
- **Audit & Safety Controls** — Built-in expiration, revocation, field allowlists, and download logging where applicable.
- **Developer-Friendly** — Clean code, clear structure, and documented patterns for extending further.

---

## Getting Started

### Requirements

| Dependency | Minimum Version | Link |
|---|---|---|
| Gravity Forms | **2.9.29** (2026-03-10) | [Latest Release](https://www.gravityforms.com/gravity-forms-changelog/) |
| WordPress | 5.8+ | [wordpress.org](https://wordpress.org/) |
| PHP | 7.4+ | |

### Installation

1. Make sure Gravity Forms **2.9.29+** is installed and activated.
2. Clone this repository or download the add-on folder you need:
   ```bash
   git clone https://github.com/izzygld/Gravity-Forms-Advanced-Implementations.git
   ```
3. Copy the desired add-on folder (e.g. `gfaddons/gf-external-entry-export/`) into your `wp-content/plugins/` directory.
4. Activate the plugin from **Plugins → Installed Plugins** in WordPress admin.
5. Configure the add-on under **Forms → Settings** in the Gravity Forms menu.

---

## Add-ons

### 1. GF External Entry Export

Generate secure, time-limited download links so external partners can export form entries as CSV — without needing WordPress admin access.

[Read more →](gfaddons/gf-external-entry-export/README.md)

---

## Roadmap

| # | Add-on | Status | Description |
|---|--------|--------|-------------|
| 1 | **GF External Entry Export** | 🔧 In Progress | Secure export URLs for non-admin users. Admins generate expiring, revocable links scoped to selected fields; external users download CSV with no WordPress login required. |
| 2 | **GF Signature Field** | 📋 Planned | Custom signature-capture field following clean field-extension patterns (aligned with `GF_Field_Address`) — a solid foundation before any future DocuSign-style integration. |
| 3 | **PDF / Website → Form Converter** | 💡 Concept | Automatically convert an existing PDF or web page into a Gravity Forms form. Proof-of-concept planned for a later phase. |

---

## Documentation

> **Coming soon.** Full usage guides, configuration references, and developer docs for each add-on will be published here as they reach stable release.

For now, each add-on includes its own README with setup and usage instructions:

- [GF External Entry Export — User Guide](gfaddons/gf-external-entry-export/EXTERNAL-USER-GUIDE.md)
- [GF External Entry Export — README](gfaddons/gf-external-entry-export/README.md)

---

## Contributing

Contributions, bug reports, and feature ideas are welcome. Please open an issue or pull request on the [GitHub repository](https://github.com/izzygld/Gravity-Forms-Advanced-Implementations).

---

## Repository Structure

```
gfaddons/
  gf-external-entry-export/   # Secure external CSV export links
```

---

## License

GPL-2.0-or-later
