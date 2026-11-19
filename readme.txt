=== Drift: Ticker ===
Contributors: driftcreativesystems
Tags: statistics, downloads, plugin stats, wordpress.org, dashboard
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Daily download, install, rating and support stats for your plugins on WordPress.org.

== Description ==

Drift: Ticker takes a snapshot of your WordPress.org plugins once a day and keeps the history, so you can see downloads per day, active installs, ratings and open support threads over time.

* Tracks any number of plugin slugs.
* One snapshot per plugin per day, stored in its own database table.
* Shows plugins that haven't been approved yet as pending, and marks them live when WordPress.org lists them.
* Refresh now button on the settings screen.
* Failed checks never overwrite good data. They're listed in a log on the settings screen.
* Alerts to a Make webhook, Slack or email: plugin goes live, new rating (flagged under 4 stars), new support thread, download milestones, active installs changes and new versions, plus a weekly summary on Monday mornings. Each alert is sent once.

Notes on the numbers:

* WordPress.org shows active installs in bands (10+, 100+, 1,000+…), not exact counts.
* Downloads include updates, not just new installs.

= Showing the numbers =

* `[drift_ticker slug="your-plugin" stat="downloaded"]` prints one number. Other stats: `active_installs`, `stars`, `num_ratings`, `open_threads`, `last_7`, `last_30`, `version`.
* `[drift_ticker_strip slug="your-plugin"]` prints "1,240 downloads · 100+ active installs · ★ 4.9 (12)" and a download button.
* Plugins that aren't on WordPress.org yet print nothing.
* Pages read the stored numbers only. Nothing is fetched while visitors browse, and page caches are cleared after the numbers change.

= External services =

This plugin connects to the WordPress.org Plugins API (api.wordpress.org) to read public statistics for the plugin slugs you choose to track. It sends only the plugin slug and the list of fields wanted, once a day by WP-Cron and when an administrator clicks Refresh now. Nothing about your site's visitors is sent, and no requests are made when visitors load your pages.

WordPress.org privacy policy: [wordpress.org/about/privacy](https://wordpress.org/about/privacy/).

Alerts are off until you add a destination. If you add a Make or Slack webhook URL, the plugin sends alerts there (plugin slug and name, the numbers that changed, a link and a timestamp) when an event happens and once a week. Nothing about your site's visitors is included.

* Make: [terms](https://www.make.com/en/terms-and-conditions) and [privacy notice](https://www.make.com/en/privacy-notice).
* Slack: [terms](https://slack.com/terms-of-service) and [privacy policy](https://slack.com/trust/privacy/privacy-policy).

Email alerts are sent through your site's own mail setup (`wp_mail()`).

= Privacy =

Drift: Ticker doesn't set cookies, track visitors or collect data about them.

= Copyright =

Copyright © 2026 Drift Creative Systems Ltd

Drift Creative Systems Ltd. Registered in England and Wales, company number 17548220. Registered office: 167-169 Great Portland Street, 5th Floor, London W1W 5PF

== Installation ==

1. Upload the plugin and activate it.
2. Go to Settings → Drift: Ticker.
3. Add the slugs to track, one per line, then click Save Changes and Refresh now.

== Frequently Asked Questions ==

= When does the snapshot run? =

Once a day at about 03:15 UK time, using WP-Cron. If your site uses a real server cron instead, it runs whenever that cron calls wp-cron.php.

= Why is my plugin shown as pending? =

WordPress.org doesn't list it yet, usually because it's still in review. Ticker keeps checking every day and marks it live as soon as it appears.

== Changelog ==

= 0.1.0 =
* First version: daily snapshots, pending and live detection, Refresh now, extra slugs and fetch log.

== Upgrade Notice ==

= 0.1.0 =
First version.
