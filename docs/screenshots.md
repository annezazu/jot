# Screenshots for the WP.org listing

Capture these and drop them into `assets/` at the repo root (or `.wordpress-org/` if deploying via [10up/action-wordpress-plugin-deploy](https://github.com/10up/action-wordpress-plugin-deploy)). File names must match the `screenshot-N.png` convention — the order here is the order they appear on the plugin's WP.org listing.

All screenshots should be captured from a WordPress 7.0+ install with enough seeded activity to show cards (not the empty state). Render them at **1280×960** — WP.org crops to fit but preserves aspect.

## Required shots

1. **`screenshot-1.png` — The widget with AI suggestion cards.** Dashboard open, Jot widget expanded, three AI-generated cards with multi-source badges and tier buttons visible. This is the hero shot.
2. **`screenshot-2.png` — The three output tiers after a click.** Widget with one card that's been converted to a "Draft created → Edit" link, plus the "Your drafts from Jot" list showing a draft with a tier pill (Spark / Outline / Full draft).
3. **`screenshot-3.png` — Digest fallback (no AI).** Widget with GitHub / Strava digest cards and a single Quick draft button per card — proving the plugin is useful without AI.
4. **`screenshot-4.png` — Jot → Connections admin page.** Connections table with at least one connected service + the OAuth app credentials section visible below.
5. **`screenshot-5.png` — Jot → Settings.** Voice / style field visible.

## Optional extras

6. **`screenshot-6.png` — A full AI-generated draft in the Gutenberg editor.** Shows a Full draft tier output opened in the block editor with real block markup (intro, H2 sections, close paragraph).
7. **`screenshot-7.png` — Privacy export output.** Tools → Export Personal Data run, showing the downloaded report with Jot sections populated (redact the email address).

## Tips

- Use the **Twenty Twenty-Six** theme on the admin side so screenshots match current WordPress.
- Turn on `Screen Options → Disable all dashboard widgets except Jot` so the widget isn't lost between other postboxes.
- Redact any real repo names, OAuth client IDs, or personal data before uploading.
- Export at 2x DPI for crispness on retina displays, then downscale to 1280×960 if needed.
