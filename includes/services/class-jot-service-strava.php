<?php
/**
 * Strava service adapter.
 *
 * OAuth2 with short-lived access tokens (6h) and refresh tokens. Scope
 * `activity:read` gives us the authenticated athlete's non-private activities.
 *
 * Docs:
 *   https://developers.strava.com/docs/authentication/
 *   https://developers.strava.com/docs/reference/#api-Activities
 *
 * @package Jot
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class Jot_Service_Strava extends Jot_Service_OAuth2 {

	public function __construct() {
		$this->authorize_url    = 'https://www.strava.com/oauth/authorize';
		$this->access_token_url = 'https://www.strava.com/oauth/token';
		$this->scope            = 'read,activity:read';
		$this->auth_header_type = 'Bearer';
	}

	public function id(): string {
		return 'strava';
	}

	public function label(): string {
		return __( 'Strava', 'jot' );
	}

	protected function fetch_connection_meta( string $access_token ): array {
		$response = wp_remote_get(
			'https://www.strava.com/api/v3/athlete',
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

		$display_name = trim( (string) ( $body['firstname'] ?? '' ) . ' ' . (string) ( $body['lastname'] ?? '' ) );
		return array(
			'user_id'  => isset( $body['id'] ) ? (int) $body['id'] : 0,
			'username' => $display_name !== '' ? $display_name : (string) ( $body['username'] ?? '' ),
			'name'     => $display_name,
		);
	}

	public function fetch_recent( int $since, int $user_id ): array {
		$url = add_query_arg(
			array(
				'after'    => $since,
				'per_page' => 60,
			),
			'https://www.strava.com/api/v3/athlete/activities'
		);

		$activities = $this->authed_get( $url, $user_id );
		if ( is_wp_error( $activities ) || ! is_array( $activities ) ) {
			return array();
		}

		$out = array();
		foreach ( $activities as $a ) {
			if ( ! is_array( $a ) || empty( $a['type'] ) || empty( $a['start_date'] ) ) {
				continue;
			}
			$out[] = array(
				'type'              => (string) $a['type'],
				'name'              => (string) ( $a['name'] ?? '' ),
				'distance_m'        => (float) ( $a['distance'] ?? 0 ),
				'moving_time_s'     => (int) ( $a['moving_time'] ?? 0 ),
				'total_elevation_m' => (float) ( $a['total_elevation_gain'] ?? 0 ),
				'at'                => (int) strtotime( (string) $a['start_date'] ),
			);
		}
		return $out;
	}
}
