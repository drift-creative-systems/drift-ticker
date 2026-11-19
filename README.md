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
| 4 | Front-end function, shortcode, hero/card strip | Done (theme placement is manual, see below) |
| 5 | Go-live detection and history backfill | Done (theme `wporg` status is manual, see below) |
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

## Front end

`includes/frontend.php`. Reads the database only: no API calls on page load, no cookies, no tracking. A plugin that isn't live (pending, closed, or no snapshot yet) prints **nothing**, never a zero or "N/A".

### Functions

| Function | Returns |
|---|---|
| `drift_ticker_get( $slug, $stat )` | Raw value, or `null`. Stats: `downloaded`, `active_installs` (band floor), `rating` (0–100), `stars` (0–5, one decimal), `num_ratings`, `support_threads`, `open_threads`, `last_7`, `last_30`, `version`, `last_updated` (UTC), `url`, `name` |
| `drift_ticker_get_suite( $suite, $stat )` | Across a suite: sums (`downloaded`, `active_installs`, `num_ratings`, threads, `last_7`, `last_30`), `count` (live plugins), `rating` / `stars` (weighted by number of ratings). `$suite` is a theme suite key, `wp` (alias of `agency`, filter `drift_ticker_suite_aliases`) or `all` |
| `drift_ticker_format( $stat, $value )` | Display string: `number_format_i18n()`, installs as "100+", stars "4.9", dates DD/MM/YYYY; `''` for null |
| `drift_ticker_strip( $app_id_or_slug, $args )` | "1,240 downloads · 100+ active installs · ★ 4.9 (12)" plus the WordPress.org button. `context` `hero` (default, with button) or `card` (no button: the card is already a link). Installs under 10 and "no ratings" are left out |
| `drift_ticker_button( $app_id_or_slug, $class, $text )` | Just the "Download free from WordPress.org" link. Default class `btn btn--accent` (theme buttons) |
| `drift_ticker_is_live( $slug )` | Live with a snapshot |

### Shortcodes

```text
[drift_ticker slug="drift-wipe" stat="downloaded"]      → 1,240
[drift_ticker slug="drift-wipe" stat="active_installs"] → 100+
[drift_ticker suite="wp" stat="downloaded"]             → 18,000
[drift_ticker suite="wp" stat="count"]                  → 8
[drift_ticker_strip slug="drift-wipe" button="no"]      → the strip (no slug = the current app)
```

### Theme placement (drift-create)

Ticker doesn't change the theme. To show the strip, add these by hand:

`single-drift_app.php`, after the `app__status` paragraph and before `<p class="btns">`:

```php
<?php
if ( function_exists( 'drift_ticker_strip' ) ) {
	echo drift_ticker_strip( get_the_ID(), array( 'button' => false ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the plugin.
}
?>
```

and inside `<p class="btns">`, before the demo link, so the download is the first button in the row:

```php
<?php echo function_exists( 'drift_ticker_button' ) ? drift_ticker_button( get_the_ID() ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the plugin. ?>
```

With that in place, a live free plugin has two accent buttons (download and "Book a demo"). Consider making the theme's CTA `btn--line` when the status is `wporg` (step 5).

`parts/app-card.php`, after the platform tags:

```php
<?php echo function_exists( 'drift_ticker_strip' ) ? drift_ticker_strip( $app->ID, array( 'context' => 'card' ) ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the plugin. ?>
```

### Styles

`assets/ticker.css` (about 1 KB) loads on the front end only while at least one slug is live. It uses the theme's `--muted` / `--fg` tokens and middle-dot separators drawn in CSS. Return `false` from `drift_ticker_enqueue_styles` to style it in the theme instead. Classes: `.drift-ticker-strip`, `--hero` / `--card`, `__stats`, `__action`, `.drift-ticker-value--{stat}`.

### Page cache (SG Optimizer)

The numbers are in the cached HTML. After a snapshot run that changed downloads, installs or ratings for any plugin, Ticker purges the whole page cache once (`sg_cachepress_purge_everything()`, or `sg_cachepress_purge_cache()` on older versions). The stats show on app pages, cards on home and suite pages, "More from Drift" on every app page, and wherever the shortcodes are used, so purging single URLs would miss pages. Other caches: hook `drift_ticker_purge_cache`.

## Go-live and backfill

`includes/golive.php`.

**Go-live** (`drift_ticker_went_live`, the first good response for a slug):

- The app's `drift_status` is set to `wporg` if the theme's `drift_app_statuses()` has it, otherwise `live` (Available now). The theme's `drift_app_meta()` turns unknown keys into `soon`, so Ticker never writes a key the theme doesn't know. "Coming soon", "In development" and "Demo available" are replaced; `live` or `wporg` set by hand is left alone. Written with ACF `update_field( 'field_drift_app_status', … )` when the field is loaded, post meta otherwise. Logged in the fetch log.
- The WordPress.org URL isn't stored: it's always `https://wordpress.org/plugins/{slug}/` (`drift_ticker_wporg_url()`, `drift_ticker_get( $slug, 'url' )`, `drift_ticker_button()`). `drift_demo` isn't used: the theme shows it as "View demo".
- **pending → live** (just approved) raises `do_action( 'drift_ticker_event', 'live', $slug, $values )`, which step 6 sends as the "Drift: Wipe is live on WordPress.org" alert. **unknown → live** (already live when first tracked) updates the status but raises no event.
- The page cache is purged at the end of the run.

**Backfill** (once per slug, after a saved snapshot):

- Only after the info API has confirmed the slug, because `stats/plugin/1.0/downloads.php` makes up numbers for slugs that don't exist. Pending slugs never call it.
- Up to 730 days, stored in `{prefix}drift_ticker_backfill` as daily counts (not totals), up to the day before the first snapshot. The charts and 7/30-day totals use it for days before tracking began.
- Rejected (and logged) if it adds up to more than the confirmed all-time total plus 10%.
- On failure it's retried on the next runs, three attempts at most. State keys `backfilled` (UTC datetime) and `backfill_attempts`.
- The fetch is on `Drift_Ticker_History_Source`, which the WordPress.org source implements; a source without history skips it.

### Theme change for the `wporg` status (drift-create, on `develop`)

Until this is in, Ticker sets `live`. `inc/apps.php`:

```php
function drift_app_statuses(): array {
	return [
		'live'        => 'Available now',
		'wporg'       => 'Free on WordPress.org',
		'demo'        => 'Demo available',
		'development' => 'In development',
		'soon'        => 'Coming soon',
	];
}

function drift_app_is_available( string $status ): bool {
	return in_array( $status, [ 'live', 'wporg', 'demo' ], true );
}
```

`assets/site.css`, lines 155, 156 and 230: add `.pill--wporg` next to `.pill--live` in all three selectors. Also add `"wporg": "Free on WordPress.org"` to the `drift_status` choices in `acf-json/group_drift_app_details.json` (fallback copy; bump `modified`). Then bump the theme version.

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
| `drift_ticker_event` | action | `( $event, $slug, $values )`. Alert-worthy events; step 6 sends them. `live` in step 5 |
| `drift_ticker_live_status` | filter | `drift_status` value written at go-live. Default `wporg` when the theme has it, else `live` |
| `drift_ticker_suite_aliases` | filter | `alias => suite key` for `suite="…"`. Default `wp => agency` |
| `drift_ticker_strip_html` | filter | `( $html, $slug, $args )` |
| `drift_ticker_enqueue_styles` | filter | `false` to skip `assets/ticker.css` |
| `drift_ticker_purge_cache` | action | After a run that changed what visitors see. For caches other than SG Optimizer |

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
| `includes/golive.php` | App status at go-live, `live` event, history backfill |
| `includes/frontend.php` | `drift_ticker_get()`, suite totals, strip, button, shortcodes, page cache purge |
| `assets/ticker.css` | Front-end strip styles |
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
