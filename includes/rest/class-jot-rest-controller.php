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
					'tier'      => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/dismiss',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'dismiss' ),
				'permission_callback' => array( __CLASS__, 'can_use' ),
				'args'                => array(
					'angle_key' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/render',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'render' ),
				'permission_callback' => array( __CLASS__, 'can_use' ),
			)
		);
	}

	public static function render(): WP_REST_Response {
		ob_start();
		( new Jot_Dashboard_Widget() )->render();
		return new WP_REST_Response(
			array(
				'ok'   => true,
				'html' => (string) ob_get_clean(),
			),
			200
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
		$tier      = (string) ( $request->get_param( 'tier' ) ?? '' );
		$user_id   = get_current_user_id();

		$cards   = jot_get_user_array( $user_id, Jot_Cron::USER_CARDS_META );
		$digests = jot_get_user_array( $user_id, Jot_Cron::USER_DIGESTS_META );

		$card = null;
		foreach ( $cards as $entry ) {
			if ( isset( $entry['angle_key'] ) && $entry['angle_key'] === $angle_key ) {
				$card = $entry;
				break;
			}
		}
		if ( ! $card ) {
			foreach ( $digests as $entry ) {
				if ( isset( $entry['angle_key'] ) && $entry['angle_key'] === $angle_key ) {
					$card = $entry + array( 'title' => '', 'rationale' => '' );
					break;
				}
			}
		}
		if ( ! $card ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'unknown_angle' ), 404 );
		}

		// Resolve tier + body.
		$title = '';
		$body  = '';
		$used_tier = 'quick_draft';

		if ( in_array( $tier, array( Jot_Prompts::TIER_SPARK, Jot_Prompts::TIER_OUTLINE, Jot_Prompts::TIER_FULL ), true )
			&& class_exists( 'Jot_Ai' ) && Jot_Ai::is_available() ) {

			$settings = get_option( 'jot_settings', array() );
			$voice    = is_array( $settings ) && isset( $settings['voice_hint'] ) ? (string) $settings['voice_hint'] : '';

			$result = Jot_Ai::generate_tier( $tier, $card, $voice );
			if ( is_wp_error( $result ) ) {
				return new WP_REST_Response( array( 'ok' => false, 'error' => $result->get_error_message() ), 502 );
			}
			$title     = $result['title'];
			$body      = $result['body_blocks'];
			$used_tier = $tier;
		} else {
			$title = $card['title'] !== ''
				? (string) $card['title']
				: sprintf( /* translators: %s: service label */ __( 'From %s: a post idea', 'jot' ), (string) ( $card['label'] ?? '' ) );
			$body  = "<!-- wp:paragraph -->\n<p>" . esc_html( (string) $card['digest'] ) . "</p>\n<!-- /wp:paragraph -->";
		}

		$post_id = wp_insert_post(
			array(
				'post_status'  => 'draft',
				'post_author'  => $user_id,
				'post_title'   => $title,
				'post_content' => $body,
				'meta_input'   => array(
					'_jot_source'  => $angle_key,
					'_jot_tier'    => $used_tier,
					'_jot_signals' => (string) ( $card['digest'] ?? '' ),
				),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => $post_id->get_error_message() ), 500 );
		}

		// Record on acted-on list so the next cron run doesn't re-suggest it.
		$acted_on = jot_get_user_array( $user_id, Jot_Cron::USER_ACTED_ON_META );
		if ( ! in_array( $angle_key, $acted_on, true ) ) {
			$acted_on[] = $angle_key;
			update_user_meta( $user_id, Jot_Cron::USER_ACTED_ON_META, $acted_on );
		}

		return new WP_REST_Response(
			array(
				'ok'       => true,
				'post_id'  => (int) $post_id,
				'tier'     => $used_tier,
				'edit_url' => get_edit_post_link( (int) $post_id, 'raw' ),
			),
			201
		);
	}

	public static function dismiss( WP_REST_Request $request ): WP_REST_Response {
		$angle_key = (string) $request->get_param( 'angle_key' );
		$user_id   = get_current_user_id();
		$dismissed = jot_get_user_array( $user_id, Jot_Cron::USER_DISMISSED_META );
		$dismissed[ $angle_key ] = time() + Jot_Cron::DISMISS_TTL;
		update_user_meta( $user_id, Jot_Cron::USER_DISMISSED_META, $dismissed );
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}
}
