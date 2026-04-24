<?php
/**
 * OAuth2 Authorization Code flow base class.
 *
 * Thin modern port of Keyring's OAuth2 service (https://github.com/beaulebens/keyring).
 * Per-user tokens stored via Jot_Service; app credentials (client_id / client_secret)
 * are site-wide and stored in the `jot_oauth_apps` option keyed by service id.
 *
 * @package Jot
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

abstract class Jot_Service_OAuth2 extends Jot_Service {

	protected string $authorize_url    = '';
	protected string $access_token_url = '';
	protected string $scope            = '';

	/**
	 * Header name used when calling the service API. GitHub wants "token", many
	 * services want "Bearer". Set per service.
	 */
	protected string $auth_header_type = 'Bearer';

	public function connect(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to connect services.', 'jot' ) );
		}
		check_admin_referer( 'jot-connect-' . $this->id() );

		$credentials = $this->get_app_credentials();
		if ( ! $credentials ) {
			wp_die( esc_html__( 'This service has not been configured yet. A site administrator must enter the OAuth app credentials first.', 'jot' ) );
		}

		$state = wp_generate_password( 32, false, false );
		set_transient( $this->state_transient_key( $state ), get_current_user_id(), 10 * MINUTE_IN_SECONDS );

		$params = array(
			'response_type' => 'code',
			'client_id'     => $credentials['client_id'],
			'redirect_uri'  => $this->callback_url(),
			'state'         => $state,
		);
		if ( $this->scope !== '' ) {
			$params['scope'] = $this->scope;
		}
		$params = apply_filters( 'jot_' . $this->id() . '_authorize_params', $params, $this );

		wp_redirect( $this->authorize_url . '?' . http_build_query( $params ) );
		exit;
	}

	public function handle_callback(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'You must be logged in to complete this connection.', 'jot' ) );
		}

		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['state'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['code'] ) ) : '';

		if ( $state === '' || $code === '' ) {
			wp_die( esc_html__( 'Missing state or authorization code.', 'jot' ) );
		}

		$expected_user = get_transient( $this->state_transient_key( $state ) );
		if ( ! $expected_user || (int) $expected_user !== get_current_user_id() ) {
			wp_die( esc_html__( 'Invalid or expired authorization state.', 'jot' ) );
		}
		delete_transient( $this->state_transient_key( $state ) );

		$credentials = $this->get_app_credentials();
		if ( ! $credentials ) {
			wp_die( esc_html__( 'This service has not been configured.', 'jot' ) );
		}

		$response = wp_remote_post(
			$this->access_token_url,
			array(
				'headers' => array( 'Accept' => 'application/json' ),
				'body'    => array(
					'client_id'     => $credentials['client_id'],
					'client_secret' => $credentials['client_secret'],
					'code'          => $code,
					'grant_type'    => 'authorization_code',
					'redirect_uri'  => $this->callback_url(),
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_die( esc_html( $response->get_error_message() ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			wp_die( esc_html__( 'Token exchange failed.', 'jot' ) );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['access_token'] ) ) {
			wp_die( esc_html__( 'Token response was malformed.', 'jot' ) );
		}

		$meta = $this->fetch_connection_meta( (string) $body['access_token'] );
		if ( ! empty( $body['refresh_token'] ) ) {
			$meta['refresh_token'] = (string) $body['refresh_token'];
		}
		if ( ! empty( $body['expires_at'] ) ) {
			$meta['expires_at'] = (int) $body['expires_at'];
		} elseif ( ! empty( $body['expires_in'] ) ) {
			$meta['expires_at'] = time() + (int) $body['expires_in'];
		}
		$this->store_token( get_current_user_id(), (string) $body['access_token'], $meta );

		wp_safe_redirect( add_query_arg( 'connected', $this->id(), admin_url( 'admin.php?page=jot-connections' ) ) );
		exit;
	}

	public function status( int $user_id ): array {
		$token = $this->get_token( $user_id );
		if ( ! $token ) {
			return array( 'connected' => false );
		}
		$username = $token['meta']['username'] ?? '';
		return array(
			'connected' => true,
			'user'      => (string) $username,
		);
	}

	/**
	 * Per-service hook to fetch identity info after the token exchange (to show
	 * "Connected as @foo" in the UI). Return an array of meta to store alongside
	 * the access token.
	 *
	 * @return array<string, mixed>
	 */
	abstract protected function fetch_connection_meta( string $access_token ): array;

	/**
	 * Perform an authenticated GET against the service API.
	 *
	 * @return array<mixed>|WP_Error
	 */
	protected function authed_get( string $url, int $user_id ) {
		$token = $this->get_token( $user_id );
		if ( ! $token ) {
			return new WP_Error( 'jot_not_connected', __( 'Not connected.', 'jot' ) );
		}

		$expires_at = (int) ( $token['meta']['expires_at'] ?? 0 );
		if ( $expires_at > 0 && $expires_at <= time() + 60 ) {
			$refreshed = $this->refresh_access_token( $user_id, $token );
			if ( is_wp_error( $refreshed ) ) {
				return $refreshed;
			}
			$token = $refreshed;
		}

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => $this->auth_header_type . ' ' . $token['access_token'],
					'Accept'        => 'application/json',
					'User-Agent'    => 'Jot/' . JOT_VERSION . '; ' . home_url(),
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error( 'jot_http_' . $status, (string) wp_remote_retrieve_body( $response ) );
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Exchange a refresh_token for a new access_token. Stores and returns the new token record.
	 *
	 * @param array{access_token:string, meta:array<string,mixed>} $token
	 * @return array{access_token:string, meta:array<string,mixed>}|WP_Error
	 */
	protected function refresh_access_token( int $user_id, array $token ) {
		$refresh = (string) ( $token['meta']['refresh_token'] ?? '' );
		if ( $refresh === '' ) {
			return new WP_Error( 'jot_no_refresh_token', __( 'Access token expired and no refresh token is available. Reconnect the service.', 'jot' ) );
		}

		$credentials = $this->get_app_credentials();
		if ( ! $credentials ) {
			return new WP_Error( 'jot_app_not_configured', __( 'Service app credentials missing.', 'jot' ) );
		}

		$response = wp_remote_post(
			$this->access_token_url,
			array(
				'headers' => array( 'Accept' => 'application/json' ),
				'body'    => array(
					'client_id'     => $credentials['client_id'],
					'client_secret' => $credentials['client_secret'],
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error( 'jot_refresh_failed', (string) wp_remote_retrieve_body( $response ) );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['access_token'] ) ) {
			return new WP_Error( 'jot_refresh_malformed', __( 'Refresh response was malformed.', 'jot' ) );
		}

		$meta = $token['meta'];
		if ( ! empty( $body['refresh_token'] ) ) {
			$meta['refresh_token'] = (string) $body['refresh_token'];
		}
		if ( ! empty( $body['expires_at'] ) ) {
			$meta['expires_at'] = (int) $body['expires_at'];
		} elseif ( ! empty( $body['expires_in'] ) ) {
			$meta['expires_at'] = time() + (int) $body['expires_in'];
		}

		$this->store_token( $user_id, (string) $body['access_token'], $meta );
		return array(
			'access_token' => (string) $body['access_token'],
			'meta'         => $meta,
		);
	}

	/**
	 * @return array{client_id:string, client_secret:string}|null
	 */
	protected function get_app_credentials(): ?array {
		$apps = get_option( 'jot_oauth_apps', array() );
		if ( ! is_array( $apps ) || empty( $apps[ $this->id() ]['client_id'] ) || empty( $apps[ $this->id() ]['client_secret'] ) ) {
			return null;
		}
		return array(
			'client_id'     => (string) $apps[ $this->id() ]['client_id'],
			'client_secret' => (string) $apps[ $this->id() ]['client_secret'],
		);
	}

	private function state_transient_key( string $state ): string {
		return 'jot_oauth_state_' . $this->id() . '_' . $state;
	}
}
