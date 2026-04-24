<?php
/**
 * Credentials-based service adapter.
 *
 * Some services don't use OAuth — the user pastes a handle + app password (or
 * API key) instead. This base hooks into the Connections UI by redirecting
 * the normal "Connect" button to a credential entry form rather than an
 * OAuth authorize URL; the form POSTs back to `jot_save_credentials`, which
 * delegates to `authenticate()` on the service.
 *
 * Stored token shape matches Jot_Service (access_token + meta + stored_at),
 * so downstream code (`status`, `get_token`) just works.
 *
 * @package Jot
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

abstract class Jot_Service_Credentials extends Jot_Service {

	/**
	 * Form definition for the credential entry page.
	 *
	 * @return array<int, array{key:string, label:string, type:string, help?:string, placeholder?:string}>
	 */
	abstract public function credential_fields(): array;

	/**
	 * Exchange the submitted credentials for an access token and persist it.
	 *
	 * @param array<string, string> $fields  Raw submitted values (already unslashed, not yet sanitized).
	 * @param int                   $user_id Current user id.
	 * @return true|WP_Error
	 */
	abstract public function authenticate( array $fields, int $user_id );

	/**
	 * "Connect" redirects here, which in turn bounces to the Connections page
	 * with a query param that causes the credential form to render.
	 */
	public function connect(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to connect services.', 'jot' ) );
		}
		check_admin_referer( 'jot-connect-' . $this->id() );

		$url = add_query_arg(
			array(
				'page'             => 'jot-connections',
				'jot_credentials'  => $this->id(),
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * No OAuth callback for credentials-based services.
	 */
	public function handle_callback(): void {
		wp_safe_redirect( admin_url( 'admin.php?page=jot-connections' ) );
		exit;
	}

	public function status( int $user_id ): array {
		$token = $this->get_token( $user_id );
		if ( ! $token ) {
			return array( 'connected' => false );
		}
		$username = (string) ( $token['meta']['username'] ?? '' );
		return array(
			'connected' => true,
			'user'      => $username,
		);
	}
}
