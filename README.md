# Jot

A WordPress dashboard widget that surfaces post-idea suggestions drawn from your activity on connected services (GitHub, Mastodon, Bluesky, Strava). Works without AI; becomes meaningfully more useful when an AI provider is connected via the WordPress 7.0 Connectors API.

> **Status: Phase 1 scaffold.** The plugin activates cleanly, registers the dashboard widget in an empty state, and wires both admin pages (Connections, Settings). Service connections, signal aggregation, daily cron, AI integration, and REST endpoints arrive in subsequent phases — see [`docs/plans/2026-04-23-jot-design.md`](docs/plans/2026-04-23-jot-design.md).

## Installation (testing)

1. Clone into `wp-content/plugins/jot` of a WordPress 7.0+ site (PHP 8.1+).
2. Activate **Jot** from **Plugins**.
3. Visit your dashboard — the **Jot** widget appears with an empty-state prompt to connect a service. The **Jot** menu in the admin sidebar exposes Connections and Settings.

Until Phase 2 lands, no external APIs are called and no data leaves your site.

## What ships in this phase

- Plugin bootstrap (`jot.php`) with activation, deactivation, and uninstall lifecycle
- Dashboard widget (`Jot_Dashboard_Widget`) gated on `edit_posts`
- Connections admin page stub listing the four v1 services
- Settings admin page with Settings-API-backed voice/style field
- PSR-style autoloader for `Jot_*` classes across `includes/`
- Internationalization-ready (`jot` text domain, all user-facing strings wrapped)

## v1 service list

GitHub, Mastodon, Bluesky, Strava. See the design plan for rationale and phased delivery.

## Roadmap

See [`docs/plans/2026-04-23-jot-design.md`](docs/plans/2026-04-23-jot-design.md) for the full architecture and phased plan.

## License

GPL-2.0-or-later, matching WordPress core.
