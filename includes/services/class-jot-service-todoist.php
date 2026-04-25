<?php
/**
 * Todoist service adapter.
 *
 * OAuth2 with a long-lived access token (no refresh token). We fetch the
 * user's recently completed tasks via the Sync API.
 *
 * Docs:
 *   https://developer.todoist.com/guides/#authorization
 *   https://developer.todoist.com/sync/v9/#get-all-completed-items
 *
 * @package Jot
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class Jot_Service_Todoist extends Jot_Service_OAuth2 {

	public function __construct() {
		$this->authorize_url    = 'https://todoist.com/oauth/authorize';
		$this->access_token_url = 'https://todoist.com/oauth/access_token';
		$this->scope            = 'data:read';
		$this->auth_header_type = 'Bearer';
	}

	public function id(): string {
		return 'todoist';
	}

	public function label(): string {
		return __( 'Todoist', 'jot' );
	}

	protected function fetch_connection_meta( string $access_token ): array {
		// Todoist has no dedicated "me" endpoint; the Sync API returns the user
		// object when we request the "user" resource with sync_token=*.
		$response = wp_remote_post(
			'https://api.todoist.com/sync/v9/sync',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => 'application/json',
					'User-Agent'    => 'Jot/' . JOT_VERSION . '; ' . home_url(),
				),
				'body'    => array(
					'sync_token'     => '*',
					'resource_types' => '["user"]',
				),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['user'] ) || ! is_array( $body['user'] ) ) {
			return array();
		}

		$user = $body['user'];
		$name = trim( (string) ( $user['full_name'] ?? '' ) );
		return array(
			'user_id'  => isset( $user['id'] ) ? (string) $user['id'] : '',
			'username' => $name !== '' ? $name : (string) ( $user['email'] ?? '' ),
			'name'     => $name,
			'email'    => (string) ( $user['email'] ?? '' ),
		);
	}

	public function fetch_recent( int $since, int $user_id ): array {
		// Todoist Sync API endpoints — including completed/get_all — require POST,
		// not GET. A GET silently 4xx's, which our base authed_get converts to a
		// WP_Error and we treat as "no events" → invisible service. Use a direct
		// POST with the form-encoded body Todoist expects.
		$response = $this->authed_post(
			'https://api.todoist.com/sync/v9/completed/get_all',
			array(
				'since' => gmdate( 'Y-m-d\TH:i:s', $since ),
				'limit' => 200,
			),
			$user_id
		);
		if ( is_wp_error( $response ) || ! is_array( $response ) || empty( $response['items'] ) || ! is_array( $response['items'] ) ) {
			return array();
		}

		$projects = array();
		if ( ! empty( $response['projects'] ) && is_array( $response['projects'] ) ) {
			foreach ( $response['projects'] as $pid => $project ) {
				if ( is_array( $project ) && ! empty( $project['name'] ) ) {
					$projects[ (string) $pid ] = (string) $project['name'];
				}
			}
		}

		$out = array();
		foreach ( $response['items'] as $item ) {
			if ( ! is_array( $item ) || empty( $item['completed_at'] ) ) {
				continue;
			}
			$completed_at = strtotime( (string) $item['completed_at'] );
			if ( $completed_at === false ) {
				continue;
			}
			$project_id = isset( $item['project_id'] ) ? (string) $item['project_id'] : '';
			$out[] = array(
				'content' => (string) ( $item['content'] ?? '' ),
				'project' => $projects[ $project_id ] ?? '',
				'at'      => $completed_at,
			);
		}
		return $out;
	}

	/**
	 * Authenticated POST against the Todoist Sync API.
	 *
	 * @param array<string, scalar> $body
	 * @return array<mixed>|WP_Error
	 */
	private function authed_post( string $url, array $body, int $user_id ) {
		$token = $this->get_token( $user_id );
		if ( ! $token ) {
			return new WP_Error( 'jot_not_connected', __( 'Not connected.', 'jot' ) );
		}

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token['access_token'],
					'Accept'        => 'application/json',
					'User-Agent'    => 'Jot/' . JOT_VERSION . '; ' . home_url(),
				),
				'body'    => $body,
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
}
