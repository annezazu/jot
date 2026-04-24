<?php
/**
 * Spotify service adapter.
 *
 * OAuth2 Authorization Code flow. Access tokens last 1h; refresh tokens are
 * long-lived. Scope `user-read-recently-played` is the minimum we need for
 * the last ~50 listening events.
 *
 * Docs:
 *   https://developer.spotify.com/documentation/web-api/tutorials/code-flow
 *   https://developer.spotify.com/documentation/web-api/reference/get-recently-played
 *
 * @package Jot
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class Jot_Service_Spotify extends Jot_Service_OAuth2 {

	public function __construct() {
		$this->authorize_url    = 'https://accounts.spotify.com/authorize';
		$this->access_token_url = 'https://accounts.spotify.com/api/token';
		$this->scope            = 'user-read-recently-played user-read-email';
		$this->auth_header_type = 'Bearer';
	}

	public function id(): string {
		return 'spotify';
	}

	public function label(): string {
		return __( 'Spotify', 'jot' );
	}

	protected function fetch_connection_meta( string $access_token ): array {
		$response = wp_remote_get(
			'https://api.spotify.com/v1/me',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => 'application/json',
					'User-Agent'    => 'Jot/' . JOT_VERSION . '; ' . home_url(),
				),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return array();
		}

		$display = (string) ( $body['display_name'] ?? '' );
		$handle  = (string) ( $body['id'] ?? '' );
		return array(
			'user_id'  => $handle,
			'username' => $display !== '' ? $display : $handle,
			'name'     => $display,
		);
	}

	public function fetch_recent( int $since, int $user_id ): array {
		// Spotify's recently-played "after" is a Unix timestamp in *milliseconds*.
		$url = add_query_arg(
			array(
				'limit' => 50,
				'after' => $since * 1000,
			),
			'https://api.spotify.com/v1/me/player/recently-played'
		);

		$response = $this->authed_get( $url, $user_id );
		if ( is_wp_error( $response ) || ! is_array( $response ) || empty( $response['items'] ) || ! is_array( $response['items'] ) ) {
			return array();
		}

		$out = array();
		foreach ( $response['items'] as $item ) {
			if ( ! is_array( $item ) || empty( $item['played_at'] ) || empty( $item['track'] ) || ! is_array( $item['track'] ) ) {
				continue;
			}
			$track   = $item['track'];
			$artists = array();
			foreach ( (array) ( $track['artists'] ?? array() ) as $artist ) {
				if ( is_array( $artist ) && ! empty( $artist['name'] ) ) {
					$artists[] = (string) $artist['name'];
				}
			}
			$played_at = strtotime( (string) $item['played_at'] );
			if ( $played_at === false ) {
				continue;
			}
			$out[] = array(
				'track'       => (string) ( $track['name'] ?? '' ),
				'artists'     => $artists,
				'album'       => (string) ( $track['album']['name'] ?? '' ),
				'duration_ms' => (int) ( $track['duration_ms'] ?? 0 ),
				'at'          => $played_at,
			);
		}
		return $out;
	}
}
