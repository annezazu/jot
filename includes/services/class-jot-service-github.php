<?php
/**
 * GitHub service adapter.
 *
 * Fetches recent events for the authenticated user (pushes, pull requests, stars)
 * via GitHub's REST API v3.
 *
 * @package Jot
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class Jot_Service_Github extends Jot_Service_OAuth2 {

	public function __construct() {
		$this->authorize_url    = 'https://github.com/login/oauth/authorize';
		$this->access_token_url = 'https://github.com/login/oauth/access_token';
		$this->scope            = 'read:user';
		$this->auth_header_type = 'token';
	}

	public function id(): string {
		return 'github';
	}

	public function label(): string {
		return __( 'GitHub', 'jot' );
	}

	protected function fetch_connection_meta( string $access_token ): array {
		$response = wp_remote_get(
			'https://api.github.com/user',
			array(
				'headers' => array(
					'Authorization' => 'token ' . $access_token,
					'Accept'        => 'application/vnd.github+json',
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

		return array(
			'user_id'  => isset( $body['id'] ) ? (int) $body['id'] : 0,
			'username' => isset( $body['login'] ) ? (string) $body['login'] : '',
			'name'     => isset( $body['name'] ) ? (string) $body['name'] : '',
		);
	}

	public function fetch_recent( int $since, int $user_id ): array {
		$token = $this->get_token( $user_id );
		if ( ! $token ) {
			return array();
		}
		$username = (string) ( $token['meta']['username'] ?? '' );
		if ( $username === '' ) {
			return array();
		}

		$events = $this->authed_get( 'https://api.github.com/users/' . rawurlencode( $username ) . '/events?per_page=100', $user_id );
		if ( is_wp_error( $events ) || ! is_array( $events ) ) {
			return array();
		}

		$filtered = array();
		foreach ( $events as $event ) {
			if ( ! is_array( $event ) || empty( $event['type'] ) || empty( $event['created_at'] ) ) {
				continue;
			}
			$created = strtotime( (string) $event['created_at'] );
			if ( $created === false || $created < $since ) {
				continue;
			}
			if ( ! in_array( $event['type'], array( 'PushEvent', 'PullRequestEvent', 'WatchEvent' ), true ) ) {
				continue;
			}
			$filtered[] = array(
				'type'    => (string) $event['type'],
				'repo'    => isset( $event['repo']['name'] ) ? (string) $event['repo']['name'] : '',
				'payload' => isset( $event['payload'] ) && is_array( $event['payload'] ) ? $event['payload'] : array(),
				'at'      => $created,
			);
		}
		return $filtered;
	}
}
