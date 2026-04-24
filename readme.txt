=== Jot ===
Contributors: annemccarthy
Tags: dashboard, ideas, drafts, writing, ai
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A dashboard widget that turns your activity on connected services into post-idea suggestions. Works without AI; even better with one.

== Description ==

Jot watches what you do on the services you connect — GitHub commits, Strava activities — and pulls that activity into your WordPress dashboard as post-idea suggestions. You always choose what becomes a real post.

Shaped after WordPress's built-in Quick Draft, Jot shows a compact list of suggestions plus your recent Jot-generated drafts, all without leaving /wp-admin.

= Two modes =

**Without AI.** Jot shows raw activity digests per service — "7 commits across 3 days to rust-analyzer", "3 runs totaling 24 km across 4 days". One click turns a digest into a WordPress draft pre-populated with that context.

**With AI** (via WordPress 7.0's Connectors API or the wp-ai-client plugin on older cores). Jot composes titled suggestion cards that weave multiple services together, and every card offers three output tiers:

* **Quick spark** — title plus a 1–2 sentence summary.
* **Outline** — title plus 3–5 section headings with intent.
* **Full draft** — title plus a complete first-draft post in blocks.

Every tier is a one-click action; the writer stays in control.

= Principles =

* **Respect the writer.** AI provides scaffolding, not finished work. Nothing is auto-published.
* **Predictable cost.** One daily AI call per user. Manual refresh debounced.
* **Graceful degradation.** Jot remains useful without AI.
* **Provider-agnostic.** Works with any AI provider the Connectors API surfaces.

= v1 services =

* **GitHub** — commits, pull requests, starred repos.
* **Strava** — activities (runs, rides, swims, etc.).
* **Mastodon** — coming soon.
* **Bluesky** — coming soon.

== Installation ==

1. Upload the `jot` folder to `/wp-content/plugins/`.
2. Activate Jot in the Plugins admin screen.
3. Visit **Jot → Connections**, paste your OAuth app credentials for each service you want to use, then click **Connect** to authorize your own account.
4. (Optional) In **Settings → AI Credentials**, configure an AI provider.

== Frequently Asked Questions ==

= Does Jot ever publish posts automatically? =

No. Every draft is a user-initiated click. Jot never calls `wp_publish_post()` or similar. The three tier buttons create drafts only.

= Is Jot per-user or site-wide? =

Service connections are per-user: your GitHub and Strava tokens are never shared with other users on the same site. OAuth *app* credentials (Client ID / Secret) are site-wide and set by an administrator once.

= What data leaves my site? =

* When a cron or manual refresh runs, Jot calls the connected services' public APIs with your user token to fetch recent activity.
* When AI is configured and a refresh runs, Jot sends the aggregated activity digests plus your last 20 post titles (plus your optional voice/style hint from Settings) to the configured AI provider to generate suggestion cards.
* Clicking **Quick spark**, **Outline**, or **Full draft** sends the card's context to the AI provider to generate the post.
* No other data leaves your site.

= Do you store my OAuth tokens? =

Yes, in your user meta — scoped to your user account, not shared site-wide. Tokens are used only to fetch your own activity from the connected services. Disconnecting or uninstalling removes them.

= Does Jot work without an AI provider? =

Yes. Each service's activity renders as a plain-English digest and you can turn any digest into a WordPress draft in one click.

= Which WordPress versions are supported? =

Jot targets WordPress 7.0+ (AI Connectors API) and PHP 8.1+. Older WordPress versions can use Jot via the `wp-ai-client` plugin for AI features; the non-AI flow works anywhere.

= How is my data handled for GDPR / data-subject requests? =

Jot registers personal-data exporter and eraser hooks so administrators can fulfill requests via **Tools → Export Personal Data** and **Tools → Erase Personal Data**. The exporter includes connections, activity digests, suggestion cards, and Jot-specific post meta. The eraser removes all per-user Jot meta and strips Jot meta from posts; Jot does not delete the posts themselves.

== Privacy ==

Jot is designed to keep your data under your control:

* All connection state is per-user.
* No activity or suggestion data is shared between users on the same site.
* No analytics, telemetry, or third-party trackers are bundled.
* Data sent to AI providers is limited to what's needed to generate a card or tier: per-service digest text, recent post titles, and the optional voice hint.

For a full accounting, see the FAQ above.

== Changelog ==

= 0.1.0 =
* Initial release: dashboard widget, GitHub + Strava adapters, AI integration with three tiers, XHR refresh, dismiss / acted-on suppression, privacy exporter + eraser hooks.
