<?php
/**
 * Jot daily refresh cron.
 *
 * Runs once every 24h. For each user with at least one connected service,
 * builds the signal digests for the last 7 days and caches them as per-user
 * transients. AI suggestion-card generation lands in Phase 3.
 *
 * @package Jot
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class Jot_Cron {

	public const EVENT                 = 'jot_daily_refresh';
	public const USER_DIGESTS_META     = 'jot_digests';
	public const USER_CARDS_META       = 'jot_suggestion_cards';
	public const USER_LAST_REFRESH     = 'jot_last_refresh';
	public const USER_ACTED_ON_META    = 'jot_user_acted_on';
	public const USER_DISMISSED_META   = 'jot_user_dismissed';
	public const MANUAL_LOCK_TRANSIENT = 'jot_manual_refresh_lock_';

	public const WINDOW_SECONDS    = 7 * DAY_IN_SECONDS;
	public const MANUAL_DEBOUNCE   = 5 * MINUTE_IN_SECONDS;
	public const DISMISS_TTL       = 7 * DAY_IN_SECONDS;
	public const RECENT_TITLE_LIMIT = 20;

	public static function boot(): void {
		add_action( self::EVENT, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::EVENT ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::EVENT );
		}
	}

	/**
	 * Runs in no-user context. Iterates users who have any Jot OAuth token.
	 */
	public static function run(): void {
		$user_ids = self::users_with_connections();
		foreach ( $user_ids as $user_id ) {
			self::refresh_for_user( $user_id );
		}
	}

	/**
	 * @return array{ ok:bool, digests?:array, locked_until?:int }
	 */
	public static function manual_refresh( int $user_id ): array {
		$lock_key = self::MANUAL_LOCK_TRANSIENT . $user_id;
		$locked   = get_transient( $lock_key );
		if ( $locked ) {
			return array(
				'ok'           => false,
				'locked_until' => (int) $locked,
			);
		}
		set_transient( $lock_key, time() + self::MANUAL_DEBOUNCE, self::MANUAL_DEBOUNCE );

		$digests = self::refresh_for_user( $user_id );
		return array(
			'ok'      => true,
			'digests' => $digests,
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function refresh_for_user( int $user_id ): array {
		$since   = time() - self::WINDOW_SECONDS;
		$digests = Jot_Signals::build_for_user( $user_id, $since );

		update_user_meta( $user_id, self::USER_DIGESTS_META, $digests );

		if ( class_exists( 'Jot_Ai' ) && Jot_Ai::is_available() && ! empty( $digests ) ) {
			$cards = Jot_Ai::generate_cards(
				$digests,
				self::recent_post_titles( $user_id ),
				self::voice_hint()
			);
			if ( is_array( $cards ) ) {
				$cards = self::filter_suppressed( $user_id, $cards );
				update_user_meta( $user_id, self::USER_CARDS_META, $cards );
			} else {
				// AI call errored: clear any stale cards so the widget falls back
				// to digests instead of rendering yesterday's cards.
				delete_user_meta( $user_id, self::USER_CARDS_META );
			}
		} else {
			delete_user_meta( $user_id, self::USER_CARDS_META );
		}

		update_user_meta( $user_id, self::USER_LAST_REFRESH, time() );
		return $digests;
	}

	/**
	 * @return array<int, string>
	 */
	private static function recent_post_titles( int $user_id ): array {
		$query = new WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'author'         => $user_id,
				'posts_per_page' => self::RECENT_TITLE_LIMIT,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'fields'         => 'ids',
			)
		);
		return array_values( array_filter( array_map( 'get_the_title', $query->posts ) ) );
	}

	private static function voice_hint(): string {
		$settings = get_option( 'jot_settings', array() );
		return is_array( $settings ) && isset( $settings['voice_hint'] ) ? (string) $settings['voice_hint'] : '';
	}

	/**
	 * Remove cards whose angle_key is on the acted-on list or currently dismissed.
	 *
	 * @param array<int, array<string, mixed>> $cards
	 * @return array<int, array<string, mixed>>
	 */
	private static function filter_suppressed( int $user_id, array $cards ): array {
		$acted_on  = (array) get_user_meta( $user_id, self::USER_ACTED_ON_META, true );
		$dismissed = (array) get_user_meta( $user_id, self::USER_DISMISSED_META, true );

		$now             = time();
		$live_dismissed  = array();
		foreach ( $dismissed as $angle_key => $expires_at ) {
			if ( (int) $expires_at > $now ) {
				$live_dismissed[ (string) $angle_key ] = (int) $expires_at;
			}
		}
		if ( $live_dismissed !== $dismissed ) {
			update_user_meta( $user_id, self::USER_DISMISSED_META, $live_dismissed );
		}

		return array_values(
			array_filter(
				$cards,
				static fn ( array $c ): bool => ! in_array( $c['angle_key'] ?? '', $acted_on, true )
					&& ! isset( $live_dismissed[ $c['angle_key'] ?? '' ] )
			)
		);
	}

	/**
	 * @return array<int, int>
	 */
	private static function users_with_connections(): array {
		global $wpdb;
		$like = $wpdb->esc_like( 'jot_oauth_tokens_' ) . '%';
		/** @var array<int, string> $ids */
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
				$like
			)
		);
		return array_map( 'intval', $ids );
	}
}
