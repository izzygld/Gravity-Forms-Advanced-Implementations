# Changelog

All notable changes to the **Izzygld Entry Export for Gravity Forms** addon will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

- **Changed**: based on wordpress revierwers team (`eda2615` - %Y->- (HEAD -> main, origin/main))
- **Changed**: Refactor token handler class for improved readability and consistency (`6d03766` - %Y->- (HEAD -> main, origin/main))
- **Changed**: Update .gitignore and README with plugin status and badge (`ecb8e29` - %Y->- (HEAD -> main, origin/main))
- **Added**: Add user documentation, submission checklist, and enhance export handler with security improvements (`e29a6fe` - %Y->- (HEAD -> main, origin/main))
- **Changed**: Merge remote changes: combine changelog entries for WP.org submission prep (`e346116` - %Y->- (HEAD -> main, origin/main))
### Added
- WordPress.org standard `readme.txt` for plugin repository submission
- Directory listing protection (`index.php` files in all directories)
- Languages folder structure for translations
- Automated changelog update workflow

### Changed
- Improved output escaping for active links count display
- Updated author info in composer.json
- Updated README to reference CHANGELOG.md

<!-- New entries will be automatically added here by GitHub Actions -->

## [1.0.2] - 2026-04-07

### Changed
- Refactored code with casual better conventions and clearer instructions for future maintenance
- All functionality remains identical to 1.0.1

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
