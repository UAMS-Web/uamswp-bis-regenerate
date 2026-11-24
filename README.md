# UAMSWP BIS Regenerate

Regenerates [Better Image Sizes](https://wordpress.org/plugins/better-image-sizes/) output for one image.

Better Image Sizes writes resized copies to `uploads/[sites/N/]bis-images/{ID}/`, and the `uams-2020` theme's `image_sizer()` caches each resized URL in a transient (`image_sizer_{md5}`) and queues missing sizes for a cron job. Clearing the folder alone leaves the theme serving cached URLs to files that no longer exist. This plugin deletes both, so the image goes back into the resize queue the next time a page that uses it is viewed.

## Use

- **Tools > Regenerate BIS image:** enter one attachment ID or several, separated by commas or spaces. A checkbox also clears every cached empty result on the site (resizes that failed and are being served blank).
- **Media Library, list view:** the "Regenerate sizes + cache" row link, and the same bulk action.
- **Attachment details** (media modal and edit screen): a "Regenerate sizes + cache" button, with the image's ID.

It also clears an image's cached entries when Better Image Sizes' own "Regenerate BIS images" link is used, and when the attachment is deleted.

Users with `manage_options` or `regenerate_images` can run it, the same rule Better Image Sizes uses. It works on the current site of a multisite network.

### How an image's entries are found

The transient key is an md5 of the ID and dimensions, so the ID cannot be read from it. The plugin matches `_transient_image_sizer_%` rows in `wp_options` whose cached URL contains `/bis-images/{ID}/`, and removes each with `delete_transient()`, which also clears the timeout row and any object cache. A failed resize caches an empty URL, which cannot be tied to an ID; the Tools page checkbox clears those site-wide.

A page cache or CDN can keep serving old image URLs until it is purged.

## Development

Requires PHP 8.4 for development (`config.platform.php` is 8.4.27); the plugin itself supports PHP 8.2 and later.

```bash
composer install
composer setup:integration       # the Integration suite's own Composer setup (PHPUnit 9.6, wp-phpunit)

composer test:unit               # Pest 5 + Brain Monkey, no WordPress
composer test:pint               # formatting
composer test:refactor           # Rector dry run
composer stan                    # PHPStan, level max
composer test:phpcs              # WordPressCS security sniffs
```

The Integration suite boots a real WordPress through `wp-phpunit` against a database it **drops and rebuilds on every run**. Create one that nothing else uses:

```bash
mysql -u root -e 'CREATE DATABASE bis_regenerate_tests'
```

With this checkout inside a WordPress install (`wp-content/plugins/uamswp-bis-regenerate/`), the suite uses that install's core:

```bash
composer test:integration
WP_MULTISITE=1 composer test:integration
```

Anywhere else, point `WP_TESTS_ABSPATH` at a core copy. `WP_TESTS_DB_NAME`, `WP_TESTS_DB_USER`, `WP_TESTS_DB_PASSWORD` and `WP_TESTS_DB_HOST` override the defaults in `tests/Integration/wp-tests-config.php`. The bootstrap refuses to run when the test database is the one the surrounding install uses.

## CI

`.github/workflows/ci.yml` runs on every pull request and on pushes to `main`: Composer validate, Pint, Rector, PHPStan, phpcs, a PHP 8.2 floor lint of the shipped files, the download-archive check, the Unit suite, and the Integration suite against MySQL 8.0 on WordPress 7.1, single site and multisite. The `CI` job needs all of them and is the one check a ruleset should require.

## Packaging

`.gitattributes` is a keep-list: a GitHub download (which WP Pusher installs) holds only `uamswp-bis-regenerate.php` and `includes/`. A new runtime folder needs its `-export-ignore` line in the same change; `node scripts/check-download-archive.mjs` fails otherwise.
