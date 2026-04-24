=== Jot ===
Contributors: annemccarthy
Tags: dashboard, ideas, drafts, writing, ai
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A dashboard widget that surfaces post-idea suggestions drawn from your activity on connected services.

== Description ==

Jot adds a dashboard widget that suggests blog post angles based on your activity on services like GitHub, Mastodon, Bluesky, and Strava. Without AI it shows aggregated digests; with an AI provider connected through the WordPress 7.0 Connectors API it produces titled suggestion cards with three output tiers: quick spark, outline, or full draft.

Jot never auto-publishes. Every draft is a user-initiated click.

== Installation ==

1. Upload the `jot` folder to `/wp-content/plugins/`.
2. Activate Jot in the Plugins admin screen.
3. Visit Jot → Connections to link a service (coming in Phase 2).

== Changelog ==

= 0.1.0 =
* Initial scaffold: plugin bootstrap, dashboard widget shell, Connections and Settings admin pages.
