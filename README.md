![Drift Creative Systems](.github/banner.jpg)

# Drift: Ticker

Internal plugin by **Drift Creative Systems**. Takes a daily snapshot of each Drift plugin on WordPress.org (downloads, active installs, rating, support threads, version), keeps the history in its own table and, in later steps, shows the numbers on driftcreativesystems.co.uk and sends alerts.

- Not on WordPress.org. `readme.txt` is kept in .org format in case it's released later.
- Licence: GPL-2.0-or-later
- Requires WordPress 6.0+, PHP 7.4+. ACF Pro for the app field (optional: the slug is plain post meta).
- Works with the `drift-create` theme's `drift_app` post type.

## Status

| Step | | |
|---|---|---|
| 1 | Plugin skeleton, tables, WordPress.org slug field | Done |
| 2 | Daily snapshot cron, "Refresh now" | Done |
| 3 | Admin table, charts and CSV export | Done |
| 4 | Front-end function, shortcode, hero/card strip | |
| 5 | Go-live detection and history backfill | |
| 6 | Alerts and weekly summary | |
| 7 | Stats in page modules | |

## How it works

- **Tracked plugins** = every `drift_app` (published, draft, pending, future or private) with a `drift_wporg_slug`, in menu order, plus the extra slugs on Settings → Drift: Ticker. `drift_ticker_tracked()` returns `slug => app ID` (0 for extra slugs).
- **Daily snapshot** (`drift_ticker_daily_snapshot`, WP-Cron, daily from 03:15 Europe/London) calls `drift_ticker_run_snapshot()`. For each slug it fetches `https://api.wordpress.org/plugins/info/1.2/?action=plugin_information`, asking for `downloaded`, `active_installs`, `ratings`, `support_threads` and `support_threads_resolved` and turning off `sections`, `screenshots` and the other heavy fields. Responses are cached in a transient for 6 hours (`drift_ticker_wporg_{md5(slug)}`); errors aren't cached.
- **One row per slug per day** in `{prefix}drift_ticker_snapshots`, unique on (`slug`, `snap_date`). A second run on the same London day updates the row.
- **Not found** (HTTP 404, `"Plugin not found."`) marks the slug `pending`, with no row. The first good response marks it `live` and fires `drift_ticker_went_live`. A live slug that later comes back "not found" stays live and the error is logged (it's more likely an API blip than a removal). `"error":"closed"` marks it `closed`.
- **Failures** (timeout, bad JSON, 5xx, DB write error) leave the last good row in place, set `last_error` on the slug and add a line to the fetch log on the settings screen.
- **Daily downloads** are worked out on read: `drift_ticker_get_rows( $slug, $days )` adds `downloads_day` = this row's total minus the previous row's, never negative. A dip in the total isn't counted twice when it recovers. A missed day puts the whole gap on the next row.
- **Refresh now** (admin-only AJAX `drift_ticker_refresh`, nonce + capability) runs the same snapshot but skips the 6-hour cache. A lock (`drift_ticker_lock` option, stale after 10 minutes) stops overlapping runs.
- Per-slug state (`pending` / `live` / `closed`, live since, last check, last error, name) is in the `drift_ticker_state` option.

### Admin screen

Settings → Drift: Ticker (`options-general.php?page=drift-ticker`).

- **Tracked plugins table:** status (with last check and last error), total downloads, last 7 and 30 days, active installs band ("Fewer than 10" below 10), rating out of 5 with the number of ratings, open threads (threads − resolved, of the total), version, and the plugin's last update on WordPress.org. A 7/30-day figure says "since DD/MM/YYYY" when the data starts inside the period.
- **Daily downloads:** one chart per plugin with a snapshot, drawn by `assets/chart.js` (plain SVG, no build, no library). One period control above all charts: 30 days, 90 days (daily bars) or 12 months (weekly totals, 52 bars ending today, because daily bars would be under 2px). Dashed lines mark days the version changed. Tooltip on hover, and on keyboard focus with the arrow keys (read out through a live region); a "Show as a table" view under each chart. The period is remembered per browser in `localStorage`.
- `drift_ticker_daily_series( $slug, $days )` (`includes/stats.php`) gives one entry per day: snapshot difference where there is one, the backfill otherwise, `null` for no data (never 0). The chart gets 365 days in `data-series` as `[ date, downloads|null, new version|"" ]`.
- **Export CSV** (`admin-post.php?action=drift_ticker_export`, nonce + capability; `&slug=` for one plugin): every snapshot row with daily downloads, star counts and open threads. Dates DD/MM/YYYY, UTF-8 with BOM, formula-like text escaped.
- Refresh now swaps in the new table and charts without a page reload.

### Real server cron

The snapshot is a normal WP-Cron event, so a server cron works as-is. In `wp-config.php` set `define( 'DISABLE_WP_CRON', true );`, then in SiteGround → Devs → Cron Jobs run every 15 minutes:

```sh
wget -q -O - "https://driftcreativesystems.co.uk/wp-cron.php?doing_wp_cron" >/dev/null 2>&1
```

### Data caveats

- WordPress.org shows active installs in bands (10+, 100+, 1,000+…), not exact counts.
- The download count includes updates, not just new installs.

## Database

`{prefix}drift_ticker_snapshots`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint unsigned | |
| `slug` | varchar(200) | |
| `snap_date` | date | Europe/London day |
| `downloaded` | bigint unsigned | All-time total |
| `active_installs` | bigint unsigned | Band floor, e.g. 100 for "100+" |
| `rating` | tinyint unsigned | 0–100 |
| `num_ratings` | int unsigned | |
| `ratings_json` | text | `{"5":11,"4":1,"3":0,"2":0,"1":0}` |
| `support_threads` | int unsigned | Threads in the last two months (WordPress.org's window) |
| `support_threads_resolved` | int unsigned | |
| `version` | varchar(40) | |
| `last_updated` | datetime | UTC |
| `created_at` | datetime | UTC |

`{prefix}drift_ticker_backfill` (`slug`, `stat_date`, `downloads`): daily counts from the unofficial stats endpoint, loaded once at go-live (step 5). Kept separate because they're per-day counts, not totals.

## Options

| Option | Contents |
|---|---|
| `drift_ticker_extra_slugs` | Extra slugs to track |
| `drift_ticker_state` | Per-slug state |
| `drift_ticker_log` | Last 50 fetch errors |
| `drift_ticker_last_run` | Timestamp of the last run |
| `drift_ticker_lock` | Run lock |
| `drift_ticker_db_version` | Schema version |

All deleted, with both tables and the cached responses, in `uninstall.php` (on every site in a multisite network). The `drift_wporg_slug` post meta is left on the apps.

## Hooks

| Hook | Type | Purpose |
|---|---|---|
| `drift_ticker_capability` | filter | Capability for the screen and Refresh now. Default `manage_options` |
| `drift_ticker_tracked` | filter | `slug => app ID` list to snapshot |
| `drift_ticker_source` | filter | `( Drift_Ticker_Source $source, $slug )`. Return another source (e.g. Freemius) for a slug |
| `drift_ticker_went_live` | action | `( $slug, $data, $app_id, $previous_status )`. First good response. `$previous_status` is `pending` when it's just been approved, `unknown` the first time an already-live slug is checked |
| `drift_ticker_snapshot_saved` | action | `( $slug, $data, $previous_row, $app_id )` after each saved row |
| `drift_ticker_snapshot_complete` | action | `( $results )`: `slug => saved / pending / error message` |

## Files

| File | |
|---|---|
| `drift-ticker.php` | Header, duplicate-install guard, constants |
| `includes/install.php` | Tables (dbDelta), activation, deactivation, upgrades |
| `includes/settings.php` | Capability, tracked slugs, state, log, date helpers |
| `includes/fields.php` | ACF "WordPress.org slug" field on apps (sidebar) |
| `includes/sources/` | `Drift_Ticker_Source` interface and the WordPress.org source |
| `includes/snapshots.php` | Run, save and read snapshots |
| `includes/stats.php` | Daily series, 7/30-day totals, labels (database only; used by the front end later) |
| `includes/cron.php` | WP-Cron schedule |
| `includes/admin.php` | Settings → Drift: Ticker, table, chart cards, Refresh now |
| `includes/export.php` | CSV export |
| `assets/chart.js` | SVG bar chart |
| `assets/admin.js` | Refresh now, period control |
| `tests/` | Harness (WordPress stubs + SQLite), PHP tests, chart logic tests |

## Development

- Coding standard: `phpcs.xml.dist` (WordPress, prefixes `drift_ticker` / `DRIFT_TICKER` / `Drift_Ticker`).
- Tests: `php -d extension=pdo_sqlite tests/run.php`. Add `--live` to also snapshot `akismet`, `hello-dolly` and `drift-wipe` from the real API. If PHP's curl has no CA bundle, set `DT_CA_BUNDLE` to one (e.g. Git's `usr/ssl/certs/ca-bundle.crt`). Chart logic: `node tests/chart.test.js`.

## Releasing

1. Bump the version in **three** places: the `Version:` header, `DRIFT_TICKER_VERSION` and `Stable tag:` in `readme.txt`. Add a changelog entry to both `readme.txt` and `CHANGELOG.md`.
2. Merge `develop` → `main`, then publish a GitHub release tagged `vX.Y.Z`.
3. `.github/workflows/release.yml` checks the three versions match, builds `drift-ticker.zip` from `.distignore` and attaches it to the release. Upload it on the site (Plugins → Add New → Upload).

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).

Copyright © 2026 Drift Creative Systems Ltd

Drift Creative Systems Ltd. Registered in England and Wales, company number 17548220. Registered office: 167-169 Great Portland Street, 5th Floor, London W1W 5PF
