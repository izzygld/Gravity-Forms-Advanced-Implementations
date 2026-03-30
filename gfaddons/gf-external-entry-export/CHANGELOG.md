# Changelog

All notable changes to the **GF External Entry Export** addon will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

<!-- New entries will be automatically added here by GitHub Actions -->

## [1.0.1] - 2026-03-30

### Security
- Fixed rate limiting to properly sanitize and validate `REMOTE_ADDR` server variable

### Changed
- Added PHPCS inline comments for legitimate prepared SQL queries using safe table name interpolation

### Added
- Admin notice and auto-deactivation when Gravity Forms is not active

## [1.0.0] - 2026-03-28

### Added
- Initial release
- Core token generation and validation
- CSV export with field selection
- Admin UI for link management
- REST API endpoints
- Access logging
- IP allowlist support
