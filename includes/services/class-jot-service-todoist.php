<?php
/**
 * Todoist service adapter.
 *
 * OAuth2 with a long-lived access token (no refresh token). All requests
 * go to Todoist's unified /api/v1/ endpoints — the older /sync/v9/* and
 * /rest/v2/* prefixes were retired and now return HTTP 410.
 *
 * For Premium accounts we ask /api/v1/tasks/completed for the
 * completed-tasks history. For Free accounts that endpoint returns an
 * error, so we fall back to /api/v1/tasks (active tasks) — a different
 * but still useful "what's on your plate" signal.
 *
 * Docs:
 *   https://developer.todoist.com/api/v1/
 *
 * @package Jot
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class Jot_Service_Todoist extends Jot_Service_OAuth2 {

	public const TRACE_META = 'jot_todoist_trace';

	public function __construct() {
		// Canonical hosts per the v1 docs; the bare todoist.com aliases redirect,
		// but the redirect-on-POST behavior on the token exchange has been flaky.
		$this->authorize_url    = 'https://app.todoist.com/oauth/authorize';
		$this->access_token_url = 'https://api.todoist.com/oauth/access_token';
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
		$response = wp_remote_get(
			'https://api.todoist.com/api/v1/user',
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

		$user = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $user ) ) {
			return array();
		}

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

		// Try completed first (Premium accounts get a richer signal). Path is
		// /api/v1/tasks/completed with `since` as ISO 8601. Falls through to
		// the active-tasks list on any non-2xx or empty result.
		$completed_url = add_query_arg(
			array(
				'since' => gmdate( 'Y-m-d\TH:i:s', $since ),
				'limit' => 200,
			),
			'https://api.todoist.com/api/v1/tasks/completed'
		);
		$completed_raw = $this->traced_get( $completed_url, $user_id, $trace );
		$completed     = is_wp_error( $completed_raw ) ? array() : self::unwrap_list( $completed_raw );

		if ( ! empty( $completed ) ) {
			$out = $this->shape_tasks( $completed, $this->fetch_projects( $user_id, $trace ), 'completed' );
			update_user_meta( $user_id, self::TRACE_META, $trace );
			return $out;
		}

		$active_raw = $this->traced_get( 'https://api.todoist.com/api/v1/tasks', $user_id, $trace );
		$active     = is_wp_error( $active_raw ) ? array() : self::unwrap_list( $active_raw );
		if ( empty( $active ) ) {
			update_user_meta( $user_id, self::TRACE_META, $trace );
			return array();
		}
		$out = $this->shape_tasks( $active, $this->fetch_projects( $user_id, $trace ), 'active' );
		update_user_meta( $user_id, self::TRACE_META, $trace );
		return $out;
	}

	/**
	 * @return array<string, string> project_id => name
	 */
	private function fetch_projects( int $user_id, array &$trace ): array {
		$raw      = $this->traced_get( 'https://api.todoist.com/api/v1/projects', $user_id, $trace );
		$projects = array();
		if ( ! is_wp_error( $raw ) ) {
			foreach ( self::unwrap_list( $raw ) as $project ) {
				if ( is_array( $project ) && ! empty( $project['id'] ) && ! empty( $project['name'] ) ) {
					$projects[ (string) $project['id'] ] = (string) $project['name'];
				}
			}
		}
		return $projects;
	}

	/**
	 * Shape a v1 task list (active or completed) into Jot's event records.
	 *
	 * @param array<int, mixed>      $tasks
	 * @param array<string, string>  $projects
	 * @return array<int, array<string, mixed>>
	 */
	private function shape_tasks( array $tasks, array $projects, string $kind ): array {
		$out = array();
		foreach ( $tasks as $task ) {
			if ( ! is_array( $task ) ) {
				continue;
			}
			$ts_field = $kind === 'completed' ? ( $task['completed_at'] ?? '' ) : ( $task['created_at'] ?? '' );
			$ts       = strtotime( (string) $ts_field );
			if ( $kind === 'completed' && $ts === false ) {
				continue;
			}
			$project_id = isset( $task['project_id'] ) ? (string) $task['project_id'] : '';
			$out[]      = array(
				'kind'    => $kind,
				'content' => (string) ( $task['content'] ?? '' ),
				'project' => $projects[ $project_id ] ?? '',
				'at'      => $ts !== false ? $ts : time(),
			);
		}
		return $out;
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
