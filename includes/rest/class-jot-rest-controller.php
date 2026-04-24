<?php
/**
 * Jot REST API controller.
 *
 * Namespace: jot/v1
 *   POST /refresh           — trigger manual refresh (debounced)
 *   POST /draft             — create a WP draft from a digest entry
 *
 * Dismiss and AI tier endpoints land in Phase 3 alongside suggestion cards.
 *
 * @package Jot
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class Jot_Rest_Controller {

	public const NAMESPACE = 'jot/v1';

	public static function boot(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/refresh',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'refresh' ),
				'permission_callback' => array( __CLASS__, 'can_use' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/draft',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_draft' ),
				'permission_callback' => array( __CLASS__, 'can_use' ),
				'args'                => array(
					'angle_key' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	public static function can_use(): bool {
		return current_user_can( 'edit_posts' );
	}

	public static function refresh( WP_REST_Request $request ): WP_REST_Response {
		$result = Jot_Cron::manual_refresh( get_current_user_id() );
		if ( empty( $result['ok'] ) ) {
			return new WP_REST_Response(
				array(
					'ok'           => false,
					'error'        => 'rate_limited',
					'retry_after'  => isset( $result['locked_until'] ) ? max( 0, (int) $result['locked_until'] - time() ) : Jot_Cron::MANUAL_DEBOUNCE,
				),
				429
			);
		}
		return new WP_REST_Response(
			array(
				'ok'           => true,
				'digests'      => $result['digests'] ?? array(),
				'last_refresh' => (int) get_user_meta( get_current_user_id(), Jot_Cron::USER_LAST_REFRESH, true ),
			),
			200
		);
	}

	public static function create_draft( WP_REST_Request $request ): WP_REST_Response {
		$angle_key = (string) $request->get_param( 'angle_key' );
		$user_id   = get_current_user_id();

		$digests = (array) get_user_meta( $user_id, Jot_Cron::USER_DIGESTS_META, true );
		$match   = null;
		foreach ( $digests as $entry ) {
			if ( isset( $entry['angle_key'] ) && $entry['angle_key'] === $angle_key ) {
				$match = $entry;
				break;
			}
		}

		if ( ! $match ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'unknown_angle' ), 404 );
		}

		$title   = sprintf( /* translators: %s: service label */ __( 'From %s: a post idea', 'jot' ), (string) ( $match['label'] ?? '' ) );
		$body    = "<!-- wp:paragraph -->\n<p>" . esc_html( (string) $match['digest'] ) . "</p>\n<!-- /wp:paragraph -->";

		$post_id = wp_insert_post(
			array(
				'post_status'  => 'draft',
				'post_author'  => $user_id,
				'post_title'   => $title,
				'post_content' => $body,
				'meta_input'   => array(
					'_jot_source'  => $angle_key,
					'_jot_tier'    => 'quick_draft',
					'_jot_signals' => (string) $match['digest'],
				),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => $post_id->get_error_message() ), 500 );
		}

		return new WP_REST_Response(
			array(
				'ok'       => true,
				'post_id'  => (int) $post_id,
				'edit_url' => get_edit_post_link( (int) $post_id, 'raw' ),
			),
			201
		);
	}
}
