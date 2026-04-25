<?php
/**
 * Todoist service adapter.
 *
 * OAuth2 with a long-lived access token (no refresh token). All requests
 * go to Todoist's unified /api/v1/ endpoints — the older /sync/v9/* and
 * /rest/v2/* prefixes were retired and now return HTTP 410.
 *
 * For Premium accounts we ask /api/v1/sync/completed/get_all for the
 * completed-tasks history. For Free accounts that endpoint returns an
 * error, so we fall back to /api/v1/tasks (active tasks) — a different
 * but still useful "what's on your plate" signal.
 *
 * Docs:
 *   https://developer.todoist.com/guides/#authorization
 *   https://developer.todoist.com/api/v1/
 *
 * @package Jot
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class Jot_Service_Todoist extends Jot_Service_OAuth2 {

	public const TRACE_META = 'jot_todoist_trace';

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
			'https://api.todoist.com/api/v1/sync',
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
		$trace = array( 'at' => time(), 'attempts' => array() );

		// Try the completed-tasks endpoint first (Premium-only; Free accounts get
		// an error and fall through to active tasks).
		$completed = $this->authed_post(
			'https://api.todoist.com/api/v1/sync/completed/get_all',
			array(
				'since' => gmdate( 'Y-m-d\TH:i:s', $since ),
				'limit' => 200,
			),
			$user_id,
			$trace
		);
		if ( is_array( $completed ) && ! empty( $completed['items'] ) && is_array( $completed['items'] ) ) {
			update_user_meta( $user_id, self::TRACE_META, $trace );
			return $this->shape_completed( $completed );
		}

		$active = $this->fetch_active( $user_id, $trace );
		update_user_meta( $user_id, self::TRACE_META, $trace );
		return $active;
	}

	/**
	 * @param array<string, mixed> $response
	 * @return array<int, array<string, mixed>>
	 */
	private function shape_completed( array $response ): array {
		$projects = array();
		if ( ! empty( $response['projects'] ) && is_array( $response['projects'] ) ) {
			foreach ( $response['projects'] as $pid => $project ) {
				if ( is_array( $project ) && ! empty( $project['name'] ) ) {
					$projects[ (string) $pid ] = (string) $project['name'];
				}
			}
		}

		$out = array();
		foreach ( (array) $response['items'] as $item ) {
			if ( ! is_array( $item ) || empty( $item['completed_at'] ) ) {
				continue;
			}
			$completed_at = strtotime( (string) $item['completed_at'] );
			if ( $completed_at === false ) {
				continue;
			}
			$project_id = isset( $item['project_id'] ) ? (string) $item['project_id'] : '';
			$out[] = array(
				'kind'    => 'completed',
				'content' => (string) ( $item['content'] ?? '' ),
				'project' => $projects[ $project_id ] ?? '',
				'at'      => $completed_at,
			);
		}
		return $out;
	}

	/**
	 * Fallback for Free-tier accounts: list active tasks via REST v2. Returns
	 * task snapshots with `kind=active` so the aggregator can switch wording.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fetch_active( int $user_id, array &$trace ): array {
		$tasks_raw = $this->traced_get( 'https://api.todoist.com/api/v1/tasks', $user_id, $trace );
		if ( is_wp_error( $tasks_raw ) ) {
			return array();
		}
		// /api/v1/ endpoints return either a flat array (REST-style) or a
		// paginated envelope { "results": [...], "next_cursor": ... }.
		$tasks = self::unwrap_list( $tasks_raw );
		if ( empty( $tasks ) ) {
			return array();
		}

		$projects_raw = $this->traced_get( 'https://api.todoist.com/api/v1/projects', $user_id, $trace );
		$projects     = array();
		foreach ( self::unwrap_list( $projects_raw ) as $project ) {
			if ( is_array( $project ) && ! empty( $project['id'] ) && ! empty( $project['name'] ) ) {
				$projects[ (string) $project['id'] ] = (string) $project['name'];
			}
		}

		$out = array();
		foreach ( $tasks as $task ) {
			if ( ! is_array( $task ) ) {
				continue;
			}
			$created   = strtotime( (string) ( $task['created_at'] ?? '' ) );
			$project_id = isset( $task['project_id'] ) ? (string) $task['project_id'] : '';
			$out[] = array(
				'kind'    => 'active',
				'content' => (string) ( $task['content'] ?? '' ),
				'project' => $projects[ $project_id ] ?? '',
				'at'      => $created !== false ? $created : time(),
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
	private function authed_post( string $url, array $body, int $user_id, ?array &$trace = null ) {
		$token = $this->get_token( $user_id );
		if ( ! $token ) {
			$this->add_trace( $trace, 'POST', $url, 0, 'not_connected', '' );
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
			$this->add_trace( $trace, 'POST', $url, 0, $response->get_error_message(), '' );
			return $response;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body_excerpt = substr( (string) wp_remote_retrieve_body( $response ), 0, 500 );
		$this->add_trace( $trace, 'POST', $url, $status, '', $body_excerpt );

		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error( 'jot_http_' . $status, (string) wp_remote_retrieve_body( $response ) );
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * GET wrapper that records the response into the trace ref.
	 *
	 * @return array<mixed>|WP_Error
	 */
	private function traced_get( string $url, int $user_id, array &$trace ) {
		$token = $this->get_token( $user_id );
		if ( ! $token ) {
			$this->add_trace( $trace, 'GET', $url, 0, 'not_connected', '' );
			return new WP_Error( 'jot_not_connected', __( 'Not connected.', 'jot' ) );
		}

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
			$this->add_trace( $trace, 'GET', $url, 0, $response->get_error_message(), '' );
			return $response;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body_excerpt = substr( (string) wp_remote_retrieve_body( $response ), 0, 500 );
		$this->add_trace( $trace, 'GET', $url, $status, '', $body_excerpt );

		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error( 'jot_http_' . $status, (string) wp_remote_retrieve_body( $response ) );
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Accept either a flat array or a paginated `{ "results": [...] }` envelope.
	 *
	 * @param mixed $response
	 * @return array<int, mixed>
	 */
	private static function unwrap_list( $response ): array {
		if ( ! is_array( $response ) ) {
			return array();
		}
		if ( isset( $response['results'] ) && is_array( $response['results'] ) ) {
			return $response['results'];
		}
		return $response;
	}

	private function add_trace( ?array &$trace, string $method, string $url, int $status, string $error, string $body_excerpt ): void {
		if ( $trace === null ) {
			return;
		}
		$trace['attempts'][] = array(
			'method' => $method,
			'url'    => $url,
			'status' => $status,
			'error'  => $error,
			'body'   => $body_excerpt,
		);
	}
}
