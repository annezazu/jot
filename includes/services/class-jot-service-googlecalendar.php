<?php
/**
 * Google Calendar service adapter.
 *
 * OAuth2 with Google. We force `access_type=offline` and `prompt=consent`
 * to reliably receive a refresh token on the first authorize — Google only
 * returns one on the very first consent unless `prompt=consent` is sent.
 *
 * Docs:
 *   https://developers.google.com/identity/protocols/oauth2/web-server
 *   https://developers.google.com/calendar/api/v3/reference/events/list
 *
 * @package Jot
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class Jot_Service_GoogleCalendar extends Jot_Service_OAuth2 {

	public function __construct() {
		$this->authorize_url    = 'https://accounts.google.com/o/oauth2/v2/auth';
		$this->access_token_url = 'https://oauth2.googleapis.com/token';
		$this->scope            = 'openid email profile https://www.googleapis.com/auth/calendar.readonly';
		$this->auth_header_type = 'Bearer';

		add_filter( 'jot_googlecalendar_authorize_params', array( $this, 'tune_authorize_params' ) );
	}

	/**
	 * @param array<string, string> $params
	 * @return array<string, string>
	 */
	public function tune_authorize_params( array $params ): array {
		$params['access_type']            = 'offline';
		$params['prompt']                 = 'consent';
		$params['include_granted_scopes'] = 'true';
		return $params;
	}

	public function id(): string {
		return 'googlecalendar';
	}

	public function label(): string {
		return __( 'Google Calendar', 'jot' );
	}

	protected function fetch_connection_meta( string $access_token ): array {
		$response = wp_remote_get(
			'https://www.googleapis.com/oauth2/v3/userinfo',
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

		$name  = (string) ( $body['name'] ?? '' );
		$email = (string) ( $body['email'] ?? '' );
		return array(
			'user_id'  => (string) ( $body['sub'] ?? '' ),
			'username' => $name !== '' ? $name : $email,
			'name'     => $name,
			'email'    => $email,
		);
	}

	public function fetch_recent( int $since, int $user_id ): array {
		$url = add_query_arg(
			array(
				'timeMin'      => gmdate( 'c', $since ),
				'timeMax'      => gmdate( 'c', time() ),
				'singleEvents' => 'true',
				'orderBy'      => 'startTime',
				'maxResults'   => 250,
			),
			'https://www.googleapis.com/calendar/v3/calendars/primary/events'
		);

		$response = $this->authed_get( $url, $user_id );
		if ( is_wp_error( $response ) || ! is_array( $response ) || empty( $response['items'] ) || ! is_array( $response['items'] ) ) {
			return array();
		}

		$out = array();
		foreach ( $response['items'] as $event ) {
			if ( ! is_array( $event ) || empty( $event['start'] ) || ! is_array( $event['start'] ) ) {
				continue;
			}

			$start_raw = (string) ( $event['start']['dateTime'] ?? $event['start']['date'] ?? '' );
			$start     = $start_raw !== '' ? strtotime( $start_raw ) : false;
			if ( $start === false ) {
				continue;
			}

			$end_raw      = (string) ( $event['end']['dateTime'] ?? $event['end']['date'] ?? '' );
			$end          = $end_raw !== '' ? strtotime( $end_raw ) : false;
			$all_day      = empty( $event['start']['dateTime'] );
			$attendees    = is_array( $event['attendees'] ?? null ) ? count( $event['attendees'] ) : 0;

			$out[] = array(
				'summary'   => (string) ( $event['summary'] ?? '' ),
				'at'        => $start,
				'end'       => $end === false ? 0 : $end,
				'all_day'   => $all_day,
				'attendees' => $attendees,
				'status'    => (string) ( $event['status'] ?? '' ),
			);
		}
		return $out;
	}
}
