<?php
/**
 * Bluesky service adapter.
 *
 * Uses an app password (Bluesky Settings → Privacy and security → App
 * passwords). We exchange the handle + app password for a session via
 * `com.atproto.server.createSession`, store the accessJwt + refreshJwt,
 * and refresh on 401 via `com.atproto.server.refreshSession`.
 *
 * Docs:
 *   https://docs.bsky.app/docs/advanced-guides/app-passwords
 *   https://docs.bsky.app/docs/api/com-atproto-server-create-session
 *   https://docs.bsky.app/docs/api/app-bsky-feed-get-author-feed
 *
 * @package Jot
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class Jot_Service_Bluesky extends Jot_Service_Credentials {

	private const DEFAULT_SERVICE = 'https://bsky.social';

	public function id(): string {
		return 'bluesky';
	}

	public function label(): string {
		return __( 'Bluesky', 'jot' );
	}

	public function credential_fields(): array {
		return array(
			array(
				'key'         => 'handle',
				'label'       => __( 'Handle', 'jot' ),
				'type'        => 'text',
				'placeholder' => 'you.bsky.social',
				'help'        => __( 'Your Bluesky handle, without the leading @.', 'jot' ),
			),
			array(
				'key'         => 'app_password',
				'label'       => __( 'App password', 'jot' ),
				'type'        => 'password',
				'help'        => __( 'Create one at bsky.app → Settings → Privacy and security → App passwords. Do not use your account password.', 'jot' ),
			),
		);
	}

	public function authenticate( array $fields, int $user_id ) {
		$handle       = sanitize_text_field( (string) ( $fields['handle'] ?? '' ) );
		$app_password = trim( (string) ( $fields['app_password'] ?? '' ) );
		$handle       = ltrim( $handle, '@' );

		if ( $handle === '' || $app_password === '' ) {
			return new WP_Error( 'jot_bluesky_missing_fields', __( 'Handle and app password are both required.', 'jot' ) );
		}

		$response = wp_remote_post(
			self::DEFAULT_SERVICE . '/xrpc/com.atproto.server.createSession',
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
					'User-Agent'   => 'Jot/' . JOT_VERSION . '; ' . home_url(),
				),
				'body'    => wp_json_encode(
					array(
						'identifier' => $handle,
						'password'   => $app_password,
					)
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $body ) || empty( $body['accessJwt'] ) ) {
			$message = is_array( $body ) && ! empty( $body['message'] ) ? (string) $body['message'] : __( 'Bluesky rejected those credentials.', 'jot' );
			return new WP_Error( 'jot_bluesky_auth_failed', $message );
		}

		$this->store_token(
			$user_id,
			(string) $body['accessJwt'],
			array(
				'refresh_token' => (string) ( $body['refreshJwt'] ?? '' ),
				'did'           => (string) ( $body['did'] ?? '' ),
				'username'      => (string) ( $body['handle'] ?? $handle ),
				'service'       => self::DEFAULT_SERVICE,
			)
		);
		return true;
	}

	public function fetch_recent( int $since, int $user_id ): array {
		$token = $this->get_token( $user_id );
		if ( ! $token ) {
			return array();
		}
		$did = (string) ( $token['meta']['did'] ?? '' );
		if ( $did === '' ) {
			return array();
		}

		$url = add_query_arg(
			array(
				'actor' => $did,
				'limit' => 50,
			),
			self::DEFAULT_SERVICE . '/xrpc/app.bsky.feed.getAuthorFeed'
		);

		$response = $this->authed_get( $url, $user_id, $token );
		if ( is_wp_error( $response ) || ! is_array( $response ) || empty( $response['feed'] ) || ! is_array( $response['feed'] ) ) {
			return array();
		}

		$out = array();
		foreach ( $response['feed'] as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['post'] ) || ! is_array( $entry['post'] ) ) {
				continue;
			}
			$post   = $entry['post'];
			$record = is_array( $post['record'] ?? null ) ? $post['record'] : array();
			$at_raw = (string) ( $record['createdAt'] ?? $post['indexedAt'] ?? '' );
			$at     = $at_raw !== '' ? strtotime( $at_raw ) : false;
			if ( $at === false || $at < $since ) {
				continue;
			}

			$kind = 'post';
			if ( isset( $entry['reason']['$type'] ) && $entry['reason']['$type'] === 'app.bsky.feed.defs#reasonRepost' ) {
				$kind = 'repost';
			} elseif ( ! empty( $record['reply'] ) ) {
				$kind = 'reply';
			}

			$out[] = array(
				'kind'    => $kind,
				'text'    => (string) ( $record['text'] ?? '' ),
				'likes'   => (int) ( $post['likeCount'] ?? 0 ),
				'reposts' => (int) ( $post['repostCount'] ?? 0 ),
				'replies' => (int) ( $post['replyCount'] ?? 0 ),
				'at'      => $at,
			);
		}
		return $out;
	}

	/**
	 * Authenticated GET that refreshes the session on 401 and retries once.
	 *
	 * @param array{access_token:string, meta:array<string,mixed>} $token
	 * @return array<mixed>|WP_Error
	 */
	private function authed_get( string $url, int $user_id, array $token ) {
		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token['access_token'],
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

		if ( $status === 401 ) {
			$refreshed = $this->refresh_session( $user_id, $token );
			if ( is_wp_error( $refreshed ) ) {
				return $refreshed;
			}
			return $this->authed_get( $url, $user_id, $refreshed );
		}

		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error( 'jot_bluesky_http_' . $status, (string) wp_remote_retrieve_body( $response ) );
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * @param array{access_token:string, meta:array<string,mixed>} $token
	 * @return array{access_token:string, meta:array<string,mixed>}|WP_Error
	 */
	private function refresh_session( int $user_id, array $token ) {
		$refresh = (string) ( $token['meta']['refresh_token'] ?? '' );
		if ( $refresh === '' ) {
			return new WP_Error( 'jot_bluesky_no_refresh', __( 'Bluesky session expired. Reconnect with your app password.', 'jot' ) );
		}

		$response = wp_remote_post(
			self::DEFAULT_SERVICE . '/xrpc/com.atproto.server.refreshSession',
			array(
				'headers' => array(
					// refreshSession authenticates with the refresh JWT, not the access JWT.
					'Authorization' => 'Bearer ' . $refresh,
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
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $body ) || empty( $body['accessJwt'] ) ) {
			return new WP_Error( 'jot_bluesky_refresh_failed', __( 'Bluesky refused to refresh the session. Reconnect.', 'jot' ) );
		}

		$meta = $token['meta'];
		$meta['refresh_token'] = (string) ( $body['refreshJwt'] ?? $refresh );
		$this->store_token( $user_id, (string) $body['accessJwt'], $meta );
		return array(
			'access_token' => (string) $body['accessJwt'],
			'meta'         => $meta,
		);
	}
}
