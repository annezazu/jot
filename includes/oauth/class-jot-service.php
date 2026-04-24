<?php
/**
 * Abstract base for Jot service adapters.
 *
 * Subclasses know the API and OAuth particulars for one service each.
 * Token storage uses per-user meta keyed by `jot_oauth_tokens_{service_id}`.
 *
 * No code outside `includes/services/` and `includes/oauth/` should access
 * OAuth state directly — use the `jot_*` helper functions in `jot.php` instead.
 *
 * @package Jot
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

abstract class Jot_Service {

	abstract public function id(): string;
	abstract public function label(): string;

	/**
	 * Kick off the OAuth authorize redirect for the current user.
	 */
	abstract public function connect(): void;

	/**
	 * Handle the OAuth callback and exchange code for a token.
	 */
	abstract public function handle_callback(): void;

	/**
	 * @return array{ connected: bool, user?: string }
	 */
	abstract public function status( int $user_id ): array;

	/**
	 * Fetch raw events since the given Unix timestamp.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	abstract public function fetch_recent( int $since, int $user_id ): array;

	public function disconnect( int $user_id ): void {
		delete_user_meta( $user_id, $this->token_meta_key() );
	}

	/**
	 * @return array{access_token:string, meta:array<string,mixed>}|null
	 */
	protected function get_token( int $user_id ): ?array {
		$stored = get_user_meta( $user_id, $this->token_meta_key(), true );
		if ( ! is_array( $stored ) || empty( $stored['access_token'] ) ) {
			return null;
		}
		return $stored;
	}

	/**
	 * @param array<string, mixed> $meta
	 */
	protected function store_token( int $user_id, string $access_token, array $meta = array() ): void {
		update_user_meta(
			$user_id,
			$this->token_meta_key(),
			array(
				'access_token' => $access_token,
				'meta'         => $meta,
				'stored_at'    => time(),
			)
		);
	}

	protected function token_meta_key(): string {
		return 'jot_oauth_tokens_' . $this->id();
	}

	/**
	 * Callback URL for this service's OAuth redirect.
	 */
	public function callback_url(): string {
		return add_query_arg(
			array(
				'page'                => 'jot-connections',
				'jot_oauth_callback'  => $this->id(),
			),
			admin_url( 'admin.php' )
		);
	}
}
