<?php
/**
 * YouTube service adapter.
 *
 * OAuth2 with Google. We read the user's "Liked videos" playlist (reserved
 * id "LL") as the activity signal — recently-liked videos are a good proxy
 * for what the user has been watching and reacting to.
 *
 * Docs:
 *   https://developers.google.com/youtube/v3/guides/auth/server-side-web-apps
 *   https://developers.google.com/youtube/v3/docs/playlistItems/list
 *
 * @package Jot
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class Jot_Service_Youtube extends Jot_Service_OAuth2 {

	public function __construct() {
		$this->authorize_url    = 'https://accounts.google.com/o/oauth2/v2/auth';
		$this->access_token_url = 'https://oauth2.googleapis.com/token';
		$this->scope            = 'openid email profile https://www.googleapis.com/auth/youtube.readonly';
		$this->auth_header_type = 'Bearer';

		add_filter( 'jot_youtube_authorize_params', array( $this, 'tune_authorize_params' ) );
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
		return 'youtube';
	}

	public function label(): string {
		return __( 'YouTube', 'jot' );
	}

	protected function fetch_connection_meta( string $access_token ): array {
		$response = wp_remote_get(
			'https://www.googleapis.com/youtube/v3/channels?part=snippet&mine=true',
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
		if ( ! is_array( $body ) || empty( $body['items'] ) || ! is_array( $body['items'] ) ) {
			return array();
		}

		$channel = $body['items'][0];
		$snippet = is_array( $channel['snippet'] ?? null ) ? $channel['snippet'] : array();
		$title   = (string) ( $snippet['title'] ?? '' );

		return array(
			'user_id'    => (string) ( $channel['id'] ?? '' ),
			'username'   => $title,
			'name'       => $title,
			'channel_id' => (string) ( $channel['id'] ?? '' ),
		);
	}

	public function fetch_recent( int $since, int $user_id ): array {
		$url = add_query_arg(
			array(
				'part'       => 'snippet,contentDetails',
				'playlistId' => 'LL',
				'maxResults' => 50,
			),
			'https://www.googleapis.com/youtube/v3/playlistItems'
		);

		$response = $this->authed_get( $url, $user_id );
		if ( is_wp_error( $response ) || ! is_array( $response ) || empty( $response['items'] ) || ! is_array( $response['items'] ) ) {
			return array();
		}

		$out = array();
		foreach ( $response['items'] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$snippet = is_array( $item['snippet'] ?? null ) ? $item['snippet'] : array();
			$added   = strtotime( (string) ( $snippet['publishedAt'] ?? '' ) );
			if ( $added === false || $added < $since ) {
				continue;
			}
			$resource = is_array( $snippet['resourceId'] ?? null ) ? $snippet['resourceId'] : array();
			$out[] = array(
				'title'   => (string) ( $snippet['title'] ?? '' ),
				'channel' => (string) ( $snippet['videoOwnerChannelTitle'] ?? '' ),
				'video'   => (string) ( $resource['videoId'] ?? '' ),
				'at'      => $added,
			);
		}
		return $out;
	}
}
