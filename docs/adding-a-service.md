# Adding a service to Jot

This walks through adding a new service adapter (e.g. LinkedIn, Readwise, a custom internal API) so it shows up in Jot → Connections and participates in the daily refresh.

A service comes in three pieces:

1. **Service adapter** (`includes/services/class-jot-service-{id}.php`) — knows the OAuth details and API for one platform. Extends `Jot_Service_OAuth2` (or `Jot_Service` directly if you need non-OAuth2 auth).
2. **Signal aggregator** (`includes/signals/class-jot-signals-{id}.php`) — turns raw events into one human-readable digest sentence. Has a single static method: `aggregate( array $events ): string`.
3. **Registry entry** (in `jot.php::jot_services()`) — add your service class to the array.

## 1. Service adapter

Create `includes/services/class-jot-service-foo.php`. Minimum viable shape:

```php
<?php
declare( strict_types=1 );
defined( 'ABSPATH' ) || exit;

class Jot_Service_Foo extends Jot_Service_OAuth2 {

    public function __construct() {
        $this->authorize_url    = 'https://example.com/oauth/authorize';
        $this->access_token_url = 'https://example.com/oauth/token';
        $this->scope            = 'read:activity';
        $this->auth_header_type = 'Bearer'; // or 'token' for GitHub-style
    }

    public function id(): string    { return 'foo'; }
    public function label(): string { return __( 'Foo', 'jot' ); }

    protected function fetch_connection_meta( string $access_token ): array {
        // Call /me equivalent; return ['username' => '...', 'name' => '...']
    }

    public function fetch_recent( int $since, int $user_id ): array {
        $events = $this->authed_get( 'https://api.example.com/events', $user_id );
        if ( is_wp_error( $events ) || ! is_array( $events ) ) {
            return array();
        }
        // Normalize each event into a ['type', 'at' (unix), ...] shape.
        return $events;
    }
}
```

### Notes on OAuth2 quirks

The base handles the standard **authorize → code → access_token** flow, refresh tokens, and `state` CSRF protection. Two filters let you handle service-specific quirks without subclassing more:

* `jot_{service}_authorize_params` — mutate the authorize params (e.g. add `approval_prompt=auto` for Strava).
* `jot_{service}_authorize_url` — mutate the full authorize URL after `http_build_query()` (e.g. to preserve literal commas in a `scope` value — Strava rejects `%2C`).

## 2. Signal aggregator

Create `includes/signals/class-jot-signals-foo.php`:

```php
<?php
declare( strict_types=1 );
defined( 'ABSPATH' ) || exit;

class Jot_Signals_Foo {
    public static function aggregate( array $events ): string {
        if ( empty( $events ) ) { return ''; }
        // Produce one sentence. Group by meaningful unit
        // (repo, activity type, channel, etc.).
        return sprintf(
            _n( '%d event in the last week.', '%d events in the last week.', count( $events ), 'jot' ),
            count( $events )
        );
    }
}
```

Keep the sentence compact — under ~150 characters is a good target. This is what the user sees *and* what the AI is given as context.

## 3. Register the service

In `jot.php`, inside `jot_services()`:

```php
foreach ( array( 'Jot_Service_Github', 'Jot_Service_Strava', 'Jot_Service_Foo' ) as $class ) {
    ...
}
```

## 4. Housekeeping

* Add `jot_oauth_tokens_foo` to `uninstall.php`'s `$user_meta_keys`.
* If your service needs a new default in `jot_activate()`'s `jot_settings.services` map, add it.
* Add a Connections row — the Connections page auto-discovers everything registered in `jot_services()`, so if steps 1–3 are done, the row appears.

## 5. Test

1. Register an OAuth app on the service side. Use the callback URL shown in Jot → Connections for your service.
2. Paste the Client ID / Secret into the admin section of Jot → Connections.
3. Click Connect on your service's row; approve on the service side.
4. Click Refresh in the dashboard widget. You should see a digest card for your service.
5. (With AI configured) After refresh, your service's digest should feature in the AI's suggestion cards via the `sources` array.

## Conventions

* **Don't fetch on render.** All remote calls happen in the cron path (daily) or via the debounced manual refresh — never on dashboard load.
* **Return WP_Error, don't throw.** Every adapter method that can fail should return a WP_Error with a helpful message. The base class surfaces these in the admin AI-debug panel.
* **Normalize timestamps to Unix seconds.** Jot's cron windows work in Unix time.
* **Per-user, always.** Tokens live in user meta. App credentials live in a site-wide option.
