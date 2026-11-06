# ANI-SMS

Independent WordPress SMS plugin under development, based on ANI-SMS-SPEC.md 2.1.

## Current checkpoint

Version 0.1.0-dev: bootstrap, compatibility checks, module registry and Iranian mobile normalization. This is not release 1.0. No SMS sending, OTP endpoints or production authentication are available. The admin page intentionally reports this state rather than displaying nonfunctional settings.

Requirements selected for development: WordPress 6.8+, PHP 8.3+. Multisite is not supported in this checkpoint. No WooCommerce or Elementor dependency.

Plugin source is `wp-content/plugins/ani-sms/`. Install only that directory into a disposable WordPress test site. Do not deploy the repository's existing database dump or config. Do not activate on production at this stage.

## Development checks

Run `php tests/ani-sms/run.php` from the repository root. These isolated tests make no external requests. WordPress integration, PHPCS, full authentication and UI checks are still pending; see `docs/PROGRESS.md`.

Existing WordPress files and `Plugin-sms-ir/` are preserved. Only the new plugin directory will be packaged in a later milestone. Original ANI-SMS code is licensed GPL-2.0-or-later; existing upstream files retain their licenses.
