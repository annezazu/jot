# Jot — Design Brief

**Status:** Design validated 2026-04-23, ready for implementation kickoff
**Working name:** Jot
**Distribution intent:** Public WordPress.org plugin (eventually)

---

## What Jot is

Jot is a WordPress plugin that adds a single dashboard widget which surfaces post-idea suggestions drawn from the user's activity on connected services (e.g. GitHub, Mastodon, Bluesky). It works without AI — showing aggregated activity digests as raw signals — and becomes meaningfully more useful when an AI provider is connected, unlocking topic angles, outlines, and full first drafts.

The dashboard widget is shaped after WordPress's built-in **Quick Draft**: a feed of suggestions on top, a list of "Your drafts from Jot" beneath, with the writer always in control of what becomes a real post.

## What Jot is not

- **Not a content mill.** Jot never auto-publishes. It does not generate full posts unless the user explicitly clicks the "Full draft" tier.
- **Not a Keyring replacement.** We fork *thinly* — only the OAuth1/OAuth2 base classes for the curated service set we ship.
- **Not an AI provider manager.** WordPress 7.0's Connectors API + `wp-ai-client` handle that for us. Jot reads from `wp_get_connectors()` to know whether AI is available.
- **Not a Gutenberg editor integration in v1.** A post-editor sidebar that suggests angles mid-write is a natural v2; out of scope for now.
- **Not Ideas Inbox.** A separate plugin in this same parent directory (`ideas-dashboard-widget/`, "Ideas Inbox") covers manual idea jotting. Jot is intentionally distinct for now (kept separate per author's call). See [Related work](#related-work) for the relationship.

## Design principles

1. **Respect the writer.** AI provides scaffolding, not finished work. The three output tiers (spark / outline / draft) are the writer's choice on every click, not ours.
2. **Predictable token cost.** A daily cron generates one batch of suggestions per day. Manual refresh is on-demand and debounced. No per-page-load AI calls.
3. **Future-proof the OAuth piece.** When core gains an OAuth surface in the Connectors API (likely WP 7.1+), Jot's OAuth implementation should be swappable behind an internal abstraction without breaking stored connections, the widget, or the cron.
4. **Graceful degradation.** Without AI, the widget is still useful (raw service digests + manual "Quick draft" action). AI unlocks more, never gates the basics.

---

## Architecture

### Plugin file layout

```
jot/
├── jot.php                              # Plugin bootstrap, activation/deactivation hooks
├── uninstall.php                        # Per-user data cleanup
├── readme.txt                           # WP.org-style readme
├── readme.md                            # GitHub-style readme
├── includes/
│   ├── oauth/                           # Thin Keyring fork
│   │   ├── class-jot-service.php        # Abstract base
│   │   ├── class-jot-service-oauth1.php # OAuth1 base (from Keyring)
│   │   └── class-jot-service-oauth2.php # OAuth2 base (from Keyring)
│   ├── services/                        # Per-service adapters
│   │   ├── class-jot-service-github.php
│   │   ├── class-jot-service-mastodon.php
│   │   ├── class-jot-service-bluesky.php
│   │   └── class-jot-service-strava.php
│   ├── signals/                         # Per-service raw → aggregate
│   │   ├── class-jot-signals.php        # Coordinator
│   │   ├── class-jot-signals-github.php
│   │   ├── class-jot-signals-mastodon.php
│   │   ├── class-jot-signals-bluesky.php
│   │   └── class-jot-signals-strava.php
│   ├── ai/
│   │   ├── class-jot-ai.php             # wp-ai-client wrapper
│   │   └── class-jot-prompts.php        # Tier-specific prompt templates
│   ├── widget/
│   │   └── class-jot-dashboard-widget.php
│   ├── cron/
│   │   └── class-jot-cron.php           # Daily refresh scheduler
│   ├── rest/
│   │   └── class-jot-rest-controller.php
│   └── admin/
│       ├── class-jot-connections-page.php
│       └── class-jot-settings-page.php
├── assets/
│   ├── jot-widget.css
│   └── jot-widget.js
├── languages/
└── tests/
    ├── unit/
    └── integration/
```

### Component boundaries

The plugin layers responsibilities so that future replacement of any one piece (especially OAuth, when core gains it) is a localized change.

1. **Service adapters** (`includes/services/`) know OAuth and the API for one platform each. They expose methods like `is_connected()`, `fetch_recent( $since )`. They do not know about signals, AI, or the widget.
2. **Signal aggregators** (`includes/signals/`) consume service adapters and emit compact, human-readable digests per service (e.g. "7 commits across 3 days to `rust-analyzer`, mostly in the completions engine"). They do not call AI.
3. **AI layer** (`includes/ai/`) consumes aggregated digests + recent post titles → produces suggestion cards (proactively, daily) or tier-specific output (on click). Talks only to `wp-ai-client`. Knows nothing about OAuth.
4. **Widget** (`includes/widget/`) renders cards and the drafts list, wires click → REST. Knows nothing about OAuth or prompt internals.
5. **Cron** (`includes/cron/`) orchestrates: pull each service → aggregate → (if AI connected) generate cards → cache → record timestamp.
6. **REST controller** (`includes/rest/`) is the only entry point for widget actions (refresh, generate, dismiss).

### The OAuth-swap abstraction

The widget, cron, and REST layers must only access service connections through these internal helpers. **No code outside `includes/services/` and `includes/oauth/` should touch OAuth state directly.**

```php
jot_get_connected_services(): array       // [ ['id'=>'github', 'label'=>'GitHub', 'user'=>'…'], … ]
jot_fetch_signals( string $service_id ): array
jot_ai_is_available(): bool               // reads wp_get_connectors() for AI providers
```

When core lands an OAuth-enabled Connectors API, the implementations of the first two move; nothing above them changes.

---

## Data flow

### Daily refresh (the hot path)

```
[WP Cron event: jot_daily_refresh — runs once / 24h]
    │
    ├─ for each service in jot_get_connected_services():
    │     Service adapter → fetch raw events (last 24h–7d window per service)
    │     Signal aggregator → compact digest per service
    │
    ├─ Persist digests          → transient `jot_digests`           (TTL 25h)
    │
    ├─ if jot_ai_is_available():
    │     Build prompt from { digests, last ~20 post titles, voice_hint }
    │     wp-ai-client → 3–5 suggestion cards
    │     Filter out cards whose angle_key is in acted_on or dismissed lists
    │     Persist cards         → option `jot_suggestion_cards`     (survives WP cache purges)
    │
    └─ Persist last_refresh timestamp → option `jot_last_refresh`
```

### Widget render

```
[User opens /wp-admin]
    │
    ├─ Read jot_suggestion_cards (or jot_digests if no AI provider connected)
    ├─ Render Suggestions feed (top)
    ├─ Render "Your drafts from Jot" (bottom):
    │     query posts where _jot_source meta exists, status = draft, last 30 days
    └─ Render footer: "Refreshed 4h ago · [Refresh]"
```

### Click → action (the tier choice)

```
[User clicks Quick spark | Outline | Full draft on a card]
    │
    ├─ POST /wp-json/jot/v1/draft  { card_id, tier }
    │
    ├─ If AI needed (always, for any tier on a Jot card):
    │     Build tier-specific prompt
    │     wp-ai-client → title + body
    │
    ├─ wp_insert_post(
    │     post_status  = 'draft',
    │     post_title   = …,
    │     post_content = …                  (paragraph block for spark; H2 outline for outline; full block content for full draft),
    │     meta_input   = [
    │         '_jot_source'    => $card_id,
    │         '_jot_tier'      => $tier,
    │         '_jot_signals'   => $card_origin_digest,
    │     ]
    │   )
    │
    ├─ Append card's angle_key to acted_on list (suppresses future regeneration)
    └─ Return { edit_url } → widget reveals "Draft created → Edit"
```

### Click → action without AI

When `jot_ai_is_available()` is false, suggestion cards are replaced by raw digest items. Each item exposes a single **[Quick draft]** button that creates a draft pre-populated with the digest text. No AI call.

### Manual refresh

Same flow as the daily cron but user-initiated from the widget footer. **Debounced to once per 5 minutes per user** (transient lock keyed by user ID) to prevent runaway token consumption.

### Dismiss

Client-side: optimistically hide the card, POST to `/jot/v1/dismiss { card_id }`. Server appends the card's `angle_key` to a per-user suppression list (option) with a 7-day TTL on each entry. Suppressed angles are filtered out of the next daily generation.

---

## User-facing surface

### Dashboard widget

```
┌─ Jot ──────────────────────────────────────── ⚙ ─┐
│  Suggestions                                       │
│  ┌──────────────────────────────────────────────┐  │
│  │ [GH] Your week in rust-analyzer              │  │
│  │ 7 commits across 3 days, mostly in the       │  │
│  │ completions engine.                          │  │
│  │ [Quick spark] [Outline] [Full draft] [×]     │  │
│  └──────────────────────────────────────────────┘  │
│  ┌──────────────────────────────────────────────┐  │
│  │ [🐘] Thread on WP block patterns…           │  │
│  └──────────────────────────────────────────────┘  │
│                                                    │
│  Your drafts from Jot                              │
│  • Your week in rust-analyzer — 2h ago        ↗   │
│  • On patterns vs. templates — yesterday      ↗   │
│                                                    │
│  Refreshed 4h ago · [Refresh]                      │
└────────────────────────────────────────────────────┘
```

- Gear icon → Jot settings page.
- Suggestion card = service icon, title, 1-sentence rationale (the underlying signal in plain English), three tier buttons, dismiss `×`.
- After click, the card collapses to a "Draft created → Edit" link instead of disappearing entirely (so the user has visual confirmation).
- Without AI: cards are replaced by raw digest items with a single **[Quick draft]** action.

### Admin pages

1. **Settings → Connectors** — WordPress core (WP 7.0+). User connects an AI provider here. **Jot does no UI work for this.** Jot reads from `wp_get_connectors()` to detect availability.
2. **Jot → Connections** — Jot's own page, Keyring-style. Connect/disconnect GitHub, Mastodon, Bluesky, etc. OAuth redirect/callback flows live here. Designed so it can be retired if core grows OAuth support.
3. **Jot → Settings** — Optional voice/style field (used as fallback when the Gutenberg content-guidelines experiment is not installed; see [References](#references)), dismiss-TTL setting, per-service event toggles (e.g. include starred repos? include reply toots?).

### Capabilities

- Widget visible to users with `edit_posts` (Contributor and up).
- Connecting/disconnecting services and changing settings: `manage_options`.
- All connections are per-user, never shared across the site (matches Ideas Inbox's per-user model).

### Accessibility

- Cards must be keyboard-navigable; tier buttons must be reachable in tab order.
- Dismiss `×` needs an `aria-label`.
- Refresh footer must announce the new state via `aria-live="polite"` after a manual refresh.
- All copy must pass through `__()`, `_x()`, etc. — full i18n from day one.

---

## AI integration

### Provider detection

```php
function jot_ai_is_available(): bool {
    if ( ! function_exists( 'wp_get_connectors' ) ) {
        return false;
    }
    foreach ( wp_get_connectors() as $connector ) {
        if ( ( $connector['type'] ?? '' ) === 'ai_provider' && jot_connector_has_credentials( $connector ) ) {
            return true;
        }
    }
    return false;
}
```

`jot_connector_has_credentials()` should consult `wp-ai-client`'s helpers (e.g. `has_ai_credentials()` filter family — see WordPress/ai issues #336, #337) rather than reading the option directly. This is more robust to env-var and constant-based credential sourcing.

### The three tiers

| Tier | Cost | Output |
|------|------|--------|
| **Quick spark** | ~150 input + ~100 output tokens | Title + 1–2 sentence summary as a single paragraph block. |
| **Outline** | ~250 input + ~300 output tokens | Title + 3–5 H2 headings with one line of intent per section. |
| **Full draft** | ~500 input + ~1200 output tokens | Title + complete post (intro, sections, close) as block content. |

All three start from the same card context: `{ digest_text, recent_post_titles, voice_hint, tier }`. Prompt templates live in `includes/ai/class-jot-prompts.php`.

### Voice context priority

When constructing the AI prompt, the "voice" hint is sourced in this order:

1. **Site content guidelines** (if the Gutenberg content-guidelines experiment is installed and exposes a public API). See [References](#references).
2. **Jot Settings → optional voice/style field** (user-supplied free text, e.g. "Casual, frontend perf, short posts").
3. **Recent post titles only** (last ~20). No voice text — the titles are the only signal.

### Prompt shape (sketch)

```
SYSTEM: You suggest blog post angles to a writer based on their recent activity
        on connected services. You scaffold ideas; you do not write polished
        posts unless explicitly asked. Respect the writer's voice.

USER: My recent post titles (most recent first):
      - {title 1}
      - {title 2}
      - …

      My voice/style notes: {voice_hint or "(none provided)"}

      My activity in the last week:
      - GitHub: {github_digest}
      - Mastodon: {mastodon_digest}
      - …

      Suggest 3–5 distinct post angles I could write about. For each, give:
        title (under 60 chars), one-sentence rationale, angle_key (kebab-case slug).
      Return JSON: [{ title, rationale, angle_key }, …]
```

For tier-specific click prompts, the prompt narrows to a single card and asks for just the spark/outline/draft.

### Prompt safety

- All AI output goes through `wp_kses_post` before storage and rendering.
- Block content from "Full draft" must validate against the block parser; on parse failure, fall back to wrapping the raw text in a single paragraph block.
- AI output never replaces user input, never modifies an already-saved draft.

---

## Data model & storage

| Key | Type | Purpose | Lifetime |
|-----|------|---------|----------|
| `jot_digests` | transient | Per-service aggregated digests from the latest cron run | 25h (1h overlap with cron) |
| `jot_suggestion_cards` | option | AI-generated cards for the current day | Until next cron or manual refresh |
| `jot_last_refresh` | option | Unix timestamp of last successful refresh | Permanent |
| `jot_user_acted_on` | user meta | Per-user list of `angle_key`s the user has drafted from | Permanent (cleared on uninstall) |
| `jot_user_dismissed` | user meta | Per-user `[ angle_key => expires_at ]` | 7 days per entry |
| `jot_oauth_tokens_{service}` | user meta | Per-user OAuth tokens (encrypted at rest if `WP_ENCRYPTION_KEY` available) | Until user disconnects |
| `jot_settings` | option | Voice hint, dismiss TTL, per-service toggles | Permanent |
| Post meta `_jot_source` | post meta | The `card_id` that produced this draft | Permanent |
| Post meta `_jot_tier` | post meta | `spark` / `outline` / `full` | Permanent |
| Post meta `_jot_signals` | post meta | The digest text used as context (for transparency / "why was this suggested") | Permanent |

`uninstall.php` must remove every `jot_*` option, every `jot_user_*` user meta entry, every OAuth token, and unschedule the cron.

---

## Public-plugin considerations

### Internationalization

- Text domain: `jot`.
- Every user-facing string wrapped in `__()`, `_e()`, `_x()`, `_n()` etc.
- `languages/` folder with a `jot.pot` template generated via `wp i18n make-pot`.

### Security

- All REST endpoints require `current_user_can( 'edit_posts' )` and a valid `wp_rest` nonce.
- Connection management endpoints require `manage_options`.
- OAuth callback URLs validate the `state` parameter to prevent CSRF.
- OAuth tokens stored encrypted when `WP_ENCRYPTION_KEY` (or whatever the WP 7.0+ standard is — verify at implementation time) is available.
- All AI output sanitized via `wp_kses_post` before rendering or saving.
- Manual refresh debounced to once per 5 minutes per user via transient lock.

### Privacy

- Connection state is per-user. No connection or signal data is shared between users on the same site.
- Document in `readme.txt` exactly what data leaves the site (which signals are sent to which AI provider). This belongs in the privacy notice.
- Add a `wp_privacy_personal_data_exporters` and `wp_privacy_personal_data_erasers` hook so users can export/erase their Jot data via WordPress's built-in privacy tools.

### Performance

- The widget render is a read from cached options/transients only — no API calls, no AI calls. Should add < 50ms to dashboard load.
- The cron job is the only place where external API calls happen on a schedule. It should run with `WP_CRON_LOCK_TIMEOUT` respected so a slow run doesn't double-fire.
- Manual refresh runs synchronously (user is waiting). Cron run is async via WP Cron.

---

## v1 service list (confirmed 2026-04-23)

- **GitHub** — pushed commits, opened/merged PRs, starred repos. OAuth2.
- **Mastodon** — own posts, replies, favourites. OAuth2 (per-instance app registration; Keyring's pattern handles this).
- **Bluesky** — own posts, likes. OAuth2 (newer; verify current API stability).
- **Strava** — activities (type, distance, elevation), kudos/comments on own activities. OAuth2. Unlocks fitness, travel, and training-focused WP bloggers.

Explicitly **out of v1:**

- **X/Twitter** — API access is hostile and expensive for individual developers in 2026. Defer.
- **Facebook / Instagram** — graph API restrictions make personal-account integration impractical. Defer.
- **LinkedIn** — possible but their OAuth app review for content scopes is a process; defer.
- **Pocket** — service shut down in 2025.
- **Readwise / RSS** — considered and dropped for v1 (RSS has no OAuth and is better as a signal-only adapter in a later phase; Readwise's audience is smaller and paid-only).

---

## Implementation phases

Suggested order for an agent picking this up. Each phase ends in something usable.

### Phase 1 — Plugin scaffold + dashboard widget shell

- `jot.php` bootstrap, activation/deactivation hooks, uninstall.php
- Dashboard widget that renders an empty state ("Connect a service to see suggestions")
- Settings page stub (just the menu, no fields yet)
- Connections page stub
- Verify `manage_options` and `edit_posts` gating

**Deliverable:** plugin activates cleanly, widget appears, all admin pages exist.

### Phase 2 — One service end-to-end (GitHub)

- Port Keyring's OAuth2 base classes
- `class-jot-service-github.php` with full OAuth handshake
- `class-jot-signals-github.php` producing one human-readable digest from real API data
- Daily cron writes the digest to a transient
- Widget renders raw digests (no AI yet) with a single `[Quick draft]` action
- REST endpoint creates the draft

**Deliverable:** install plugin, connect GitHub, see "Your week in {repo}" digest tomorrow, click Quick draft, get a real WP draft.

### Phase 3 — AI integration

- `class-jot-ai.php` wraps `wp-ai-client`, exposes `is_available()` and `generate( $tier, $card )`
- `jot_ai_is_available()` reads from `wp_get_connectors()`
- Cron generates suggestion cards when AI is available
- Three-tier buttons replace the single Quick draft button on cards
- Acted-on / dismissed suppression lists wired in

**Deliverable:** with an AI provider connected via Settings → Connectors, the widget shows AI-titled cards instead of raw digests, three tiers work, dismissed cards stay dismissed.

### Phase 4 — Remaining v1 services

- Mastodon adapter + signals
- Bluesky adapter + signals
- Strava adapter + signals
- Per-service event toggles in Settings

**Deliverable:** widget mixes signals from multiple services in the daily batch.

### Phase 5 — Polish for public release

- Full i18n pass + `.pot` generation
- Accessibility audit
- Privacy exporter/eraser hooks
- `readme.txt` for WP.org with FAQ, screenshots, privacy notice
- Documentation for adding a new service (so contributors can add e.g. LinkedIn later)

**Deliverable:** ready for WP.org submission.

---

## Open items

These were either deferred during design or need confirmation before kickoff.

- **OAuth token encryption strategy** — WP 7.0's exact story for at-rest encryption is in flux (see WordPress/ai #441). Verify the recommended pattern at implementation time.
- **Gutenberg content-guidelines API surface** — the experiment exists (Make WP AI post 2026-02-03) but the public read API for plugins isn't documented yet. Treat as future integration; ship Phase 5 with the optional voice field as the only voice source.
- **Token-budget defaults per tier** — numbers in the AI integration table are estimates; tune against real provider responses in Phase 3.
- **Card count per refresh** — currently "3–5 distinct angles." May want to be configurable in Settings.
- **Thumbs feedback loop** — explicitly deferred to v2 (per design discussion).

---

## Related work

- **Ideas Inbox** (`/Users/annemccarthy/automattic/ideas-dashboard-widget/`, repo `kellychoffman/ideas-dashboard-widget`) — a smaller existing plugin in this same parent directory. Same "ideas → drafts" spine; no service connections, no AI. **Per author's call (2026-04-23), Jot and Ideas Inbox are kept as separate plugins for now.** A future composition path (Jot AI cards drop into Ideas Inbox's inbox) remains open.
- **Keyring** (`https://github.com/beaulebens/keyring`) — original OAuth plugin we are thin-forking.
- **wp-ai-client** (`https://github.com/WordPress/wp-ai-client`) — the AI abstraction we use, integrates automatically with WP 7.0's Connectors API.

---

## References

- **WP 7.0 Connectors API introduction** — https://make.wordpress.org/core/2026/03/18/introducing-the-connectors-api-in-wordpress-7-0/ (API-key only at ship; OAuth deferred)
- **Gutenberg content guidelines experiment** — https://make.wordpress.org/ai/2026/02/03/content-guidelines-a-gutenberg-experiment/ (future voice context source)
- **#wp-ai-client-7-0 Slack thread on OAuth** — https://a8c.slack.com/archives/C0AGM829URF/p1772711756904759 (confirms OAuth is "wish list" for WP 7.1+, separate plugin work in flight)
- **WordPress/ai issues #336, #337** — non-API-key credential check fallback patterns
- **WordPress/ai issues #441, #467** — connector approval / permission model (forthcoming; relevant to Phase 5 polish)
