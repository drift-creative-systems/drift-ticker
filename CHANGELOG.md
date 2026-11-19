# Changelog

All notable changes to this project are documented here. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- Plugin skeleton: header, duplicate-install guard, constants, `uninstall.php` (multisite aware), `readme.txt`, release workflow.
- `{prefix}drift_ticker_snapshots` table (unique on slug + date) and `{prefix}drift_ticker_backfill` table, created with dbDelta on activation and re-checked on `admin_init` when `DRIFT_TICKER_DB_VERSION` changes.
- "WordPress.org slug" field (`drift_wporg_slug`) on apps, registered in code in its own sidebar field group, with slug validation.
- Daily snapshot of every tracked slug from the WordPress.org plugin info API (1.2), via WP-Cron at 03:15 Europe/London. Responses are trimmed, cached for 6 hours, and failures leave the last good row in place and are logged.
- Pending / live / closed state per slug, and a `drift_ticker_went_live` action on the first good response.
- `Drift_Ticker_Source` interface and `drift_ticker_source` filter, so a Freemius source can be added later.
- Settings → Drift: Ticker in the Drift card style with the "D" mark: tracked plugins table, Refresh now (AJAX, nonce + capability), extra slugs setting, fetch log, and the copyright line in the admin footer.
- Test harness (WordPress stubs + SQLite) with PHP tests, plus a live run against api.wordpress.org.
- Tracked plugins table: last 7 and 30 days (marked "since" when data starts inside the period), installs band, rating out of 5, open support threads, WordPress.org last updated date, last check time.
- Daily downloads chart per plugin: dependency-free SVG bars for 30 or 90 days, weekly totals for 12 months, version-change markers, tooltips on hover and arrow keys, and a table view.
- `drift_ticker_daily_series()`, `drift_ticker_summary()` and label helpers in `includes/stats.php`. Days before tracking began use the backfill table when it has data.
- CSV export of every snapshot (all plugins or one), with daily downloads, star counts and open threads.
- Refresh now updates the charts as well as the table.
- Chart logic tests (`node tests/chart.test.js`).
- `drift_ticker_get( $slug, $stat )` and `drift_ticker_get_suite( $suite, $stat )` (sums, count, weighted rating; `suite="wp"` is the theme's WP Suite), with `drift_ticker_format()`.
- `[drift_ticker]` and `[drift_ticker_strip]` shortcodes. Plugins that aren't live print nothing.
- `drift_ticker_strip()` ("1,240 downloads · 100+ active installs · ★ 4.9 (12)") and `drift_ticker_button()` ("Download free from WordPress.org") for the app hero and cards. Theme placement is manual (README → Front end).
- `assets/ticker.css`, loaded only while a slug is live; uses the theme's tokens and `.btn` classes.
- Page cache purge (SG Optimizer) after a snapshot run that changed downloads, installs or ratings.
- Go-live: the app's `drift_status` becomes `wporg` (or `live` until the theme has `wporg`), and a `drift_ticker_event` "live" event is raised when a pending slug is approved.
- One-off history backfill (up to 730 days) from the WordPress.org stats endpoint, only after the info API confirms the slug, checked against the all-time total, retried up to three times.
- `Drift_Ticker_History_Source` interface for sources that can return past daily downloads.
- Alerts to a Make webhook, Slack webhook and/or email: plugin live, new rating (per star, flagged under 4 stars), new support thread, download milestone, active installs band change, new version.
- Weekly summary every Monday at 08:30 Europe/London: downloads this week vs last, totals and open threads per plugin.
- `{prefix}drift_ticker_events` table (schema version 2): each alert is recorded by key and never sent twice; failed channels are retried up to three times.
- Alert settings, "Send a test alert" and a "Recent alerts" list on the settings screen.
- readme.txt discloses the optional Make and Slack connections.
