<?php
/**
 * Jot personal-data exporter + eraser hooks.
 *
 * Integrates with WordPress's Tools → Export Personal Data / Erase Personal
 * Data screens so a site admin can fulfill data-subject requests using Jot's
 * per-user state.
 *
 * What we expose to the exporter:
 *   - Connected service metadata (service label + remote username + connected-at).
 *     Raw access tokens are NOT exported — they're credentials, not user-authored data.
 *   - Activity digests generated for the user.
 *   - AI suggestion cards generated for the user.
 *   - Jot-authored post metadata (_jot_source, _jot_tier, _jot_signals).
 *
 * What the eraser removes:
 *   - Every jot_* user-meta entry (tokens included — OAuth connection is revoked).
 *   - Jot-specific post meta on drafts. The drafts themselves are user-authored
 *     posts and are not deleted by Jot's eraser; WP core's own post eraser
 *     handles author posts if requested separately.
 *
 * @package Jot
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class Jot_Privacy {

	public const EXPORTER_ID = 'jot';
	public const ERASER_ID   = 'jot';

	public static function boot(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	/**
	 * @param array<string, array<string, mixed>> $exporters
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters[ self::EXPORTER_ID ] = array(
			'exporter_friendly_name' => __( 'Jot', 'jot' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * @param array<string, array<string, mixed>> $erasers
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_eraser( array $erasers ): array {
		$erasers[ self::ERASER_ID ] = array(
			'eraser_friendly_name' => __( 'Jot', 'jot' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * @return array{data:array<int, array<string,mixed>>, done:bool}
	 */
	public static function export( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array( 'data' => array(), 'done' => true );
		}
		$user_id = (int) $user->ID;
		$items   = array();

		// Connections (service + username + stored_at; no tokens).
		foreach ( self::user_meta_keys_prefixed( $user_id, 'jot_oauth_tokens_' ) as $meta_key => $value ) {
			$service_id = substr( $meta_key, strlen( 'jot_oauth_tokens_' ) );
			$username   = is_array( $value ) && isset( $value['meta']['username'] ) ? (string) $value['meta']['username'] : '';
			$stored_at  = is_array( $value ) && isset( $value['stored_at'] ) ? (int) $value['stored_at'] : 0;

			$data = array(
				array( 'name' => __( 'Service', 'jot' ), 'value' => $service_id ),
			);
			if ( $username !== '' ) {
				$data[] = array( 'name' => __( 'Remote username', 'jot' ), 'value' => $username );
			}
			if ( $stored_at > 0 ) {
				$data[] = array( 'name' => __( 'Connected at', 'jot' ), 'value' => wp_date( 'c', $stored_at ) );
			}
			$items[] = array(
				'group_id'    => 'jot-connections',
				'group_label' => __( 'Jot — Connections', 'jot' ),
				'item_id'     => 'jot-connection-' . $service_id,
				'data'        => $data,
			);
		}

		// Cached digests.
		$digests = jot_get_user_array( $user_id, Jot_Cron::USER_DIGESTS_META );
		foreach ( $digests as $i => $digest ) {
			if ( ! is_array( $digest ) ) {
				continue;
			}
			$items[] = array(
				'group_id'    => 'jot-digests',
				'group_label' => __( 'Jot — Activity digests', 'jot' ),
				'item_id'     => 'jot-digest-' . (int) $i,
				'data'        => array(
					array( 'name' => __( 'Service', 'jot' ), 'value' => (string) ( $digest['service'] ?? '' ) ),
					array( 'name' => __( 'Digest', 'jot' ), 'value' => (string) ( $digest['digest'] ?? '' ) ),
				),
			);
		}

		// AI suggestion cards.
		$cards = jot_get_user_array( $user_id, Jot_Cron::USER_CARDS_META );
		foreach ( $cards as $i => $card ) {
			if ( ! is_array( $card ) ) {
				continue;
			}
			$items[] = array(
				'group_id'    => 'jot-cards',
				'group_label' => __( 'Jot — Suggestion cards', 'jot' ),
				'item_id'     => 'jot-card-' . (int) $i,
				'data'        => array(
					array( 'name' => __( 'Title', 'jot' ),     'value' => (string) ( $card['title'] ?? '' ) ),
					array( 'name' => __( 'Rationale', 'jot' ), 'value' => (string) ( $card['rationale'] ?? '' ) ),
					array( 'name' => __( 'Sources', 'jot' ),   'value' => implode( ', ', (array) ( $card['labels'] ?? array() ) ) ),
				),
			);
		}

		// Jot-authored post meta on user's drafts.
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'author'         => $user_id,
				'posts_per_page' => 50,
				'meta_key'       => '_jot_source',
				'no_found_rows'  => true,
			)
		);
		foreach ( $posts as $post ) {
			$items[] = array(
				'group_id'    => 'jot-posts',
				'group_label' => __( 'Jot — Drafts authored from Jot', 'jot' ),
				'item_id'     => 'jot-post-' . (int) $post->ID,
				'data'        => array(
					array( 'name' => __( 'Post', 'jot' ),    'value' => get_edit_post_link( $post, 'raw' ) ),
					array( 'name' => __( 'Source', 'jot' ),  'value' => (string) get_post_meta( $post->ID, '_jot_source', true ) ),
					array( 'name' => __( 'Tier', 'jot' ),    'value' => (string) get_post_meta( $post->ID, '_jot_tier', true ) ),
					array( 'name' => __( 'Signals', 'jot' ), 'value' => (string) get_post_meta( $post->ID, '_jot_signals', true ) ),
				),
			);
		}

		return array( 'data' => $items, 'done' => true );
	}

	/**
	 * @return array{items_removed:int, items_retained:int, messages:array<int,string>, done:bool}
	 */
	public static function erase( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'items_removed'  => 0,
				'items_retained' => 0,
				'messages'       => array(),
				'done'           => true,
			);
		}
		$user_id = (int) $user->ID;
		$removed = 0;

		foreach ( self::jot_user_meta_keys() as $key ) {
			if ( metadata_exists( 'user', $user_id, $key ) ) {
				delete_user_meta( $user_id, $key );
				$removed++;
			}
		}

		// Also clear any per-service OAuth token keys we don't know about upfront
		// (future services).
		foreach ( self::user_meta_keys_prefixed( $user_id, 'jot_oauth_tokens_' ) as $meta_key => $_value ) {
			delete_user_meta( $user_id, $meta_key );
			$removed++;
		}

		// Strip Jot-specific post meta from user's posts. The posts themselves
		// are user-authored; core's post eraser handles those if asked.
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'author'         => $user_id,
				'posts_per_page' => -1,
				'meta_key'       => '_jot_source',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		foreach ( $posts as $post_id ) {
			foreach ( array( '_jot_source', '_jot_tier', '_jot_signals' ) as $meta ) {
				if ( metadata_exists( 'post', $post_id, $meta ) ) {
					delete_post_meta( $post_id, $meta );
					$removed++;
				}
			}
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * @return array<int, string>
	 */
	private static function jot_user_meta_keys(): array {
		return array(
			Jot_Cron::USER_DIGESTS_META,
			Jot_Cron::USER_CARDS_META,
			Jot_Cron::USER_LAST_REFRESH,
			Jot_Cron::USER_AI_ERROR_META,
			Jot_Ai::DEBUG_META,
			Jot_Cron::USER_ACTED_ON_META,
			Jot_Cron::USER_DISMISSED_META,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function user_meta_keys_prefixed( int $user_id, string $prefix ): array {
		global $wpdb;
		$like = $wpdb->esc_like( $prefix ) . '%';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s",
				$user_id,
				$like
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['meta_key'] ] = maybe_unserialize( $row['meta_value'] );
		}
		return $out;
	}
}
