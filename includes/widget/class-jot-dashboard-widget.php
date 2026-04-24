<?php
/**
 * Jot Dashboard Widget.
 *
 * Render path:
 *  - No connections → single-CTA empty state.
 *  - Connections but no activity → "no recent activity" empty state.
 *  - Otherwise → one card per suggestion. Cards derive from either AI
 *    suggestion-card meta (preferred) or raw digest meta (fallback). Tier
 *    buttons appear on every card when an AI provider is configured; a single
 *    Quick draft appears on every card when AI is not configured.
 *
 * @package Jot
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class Jot_Dashboard_Widget {

	public const WIDGET_ID      = 'jot_dashboard_widget';
	public const MAX_CARDS_SHOWN = 3;

	public function register(): void {
		wp_add_dashboard_widget(
			self::WIDGET_ID,
			__( 'Jot', 'jot' ),
			array( $this, 'render' ),
			null,
			null,
			'normal',
			'high'
		);
	}

	public function render(): void {
		$user_id         = get_current_user_id();
		$connections     = jot_get_connected_services( $user_id );
		$settings_url    = admin_url( 'admin.php?page=jot-settings' );
		$connections_url = admin_url( 'admin.php?page=jot-connections' );
		$last_refresh    = (int) get_user_meta( $user_id, Jot_Cron::USER_LAST_REFRESH, true );
		$ai_available    = class_exists( 'Jot_Ai' ) && Jot_Ai::is_available();
		$ai_error        = (string) get_user_meta( $user_id, Jot_Cron::USER_AI_ERROR_META, true );

		$cards   = $this->build_card_list( $user_id );
		$drafts  = $this->get_jot_drafts();

		?>
		<div class="jot-widget"
			aria-labelledby="jot-widget-heading"
			data-rest-root="<?php echo esc_attr( esc_url_raw( rest_url( Jot_Rest_Controller::NAMESPACE . '/' ) ) ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
		>
			<div class="jot-widget__header">
				<h3 id="jot-widget-heading" class="screen-reader-text"><?php esc_html_e( 'Jot suggestions', 'jot' ); ?></h3>
				<a
					class="jot-widget__settings"
					href="<?php echo esc_url( $settings_url ); ?>"
					aria-label="<?php esc_attr_e( 'Jot settings', 'jot' ); ?>"
				>
					<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
				</a>
			</div>

			<section class="jot-widget__section jot-widget__suggestions" aria-live="polite">
				<h4><?php esc_html_e( 'Suggestions', 'jot' ); ?></h4>

				<?php $this->render_suggestions( $user_id, $connections, $cards, $ai_available, $ai_error, $connections_url ); ?>
			</section>

			<section class="jot-widget__section jot-widget__drafts">
				<h4><?php esc_html_e( 'Your drafts from Jot', 'jot' ); ?></h4>
				<?php if ( empty( $drafts ) ) : ?>
					<p class="jot-widget__muted"><?php esc_html_e( 'Jot drafts appear here once you create them.', 'jot' ); ?></p>
				<?php else : ?>
					<ul class="jot-widget__drafts-list">
						<?php foreach ( $drafts as $draft ) :
							$ts   = (int) get_post_timestamp( $draft );
							$tier = (string) get_post_meta( $draft->ID, '_jot_tier', true );
							?>
							<li class="jot-widget__draft">
								<a class="jot-widget__draft-title" href="<?php echo esc_url( get_edit_post_link( $draft->ID ) ); ?>">
									<?php echo esc_html( get_the_title( $draft ) ?: __( '(no title)', 'jot' ) ); ?>
								</a>
								<span class="jot-widget__draft-meta">
									<?php if ( $tier !== '' && $tier !== 'quick_draft' ) : ?>
										<span class="jot-widget__tier-pill"><?php echo esc_html( $this->tier_label( $tier ) ); ?></span>
									<?php endif; ?>
									<span class="jot-widget__muted" title="<?php echo esc_attr( wp_date( 'c', $ts ) ); ?>">
										<?php
										/* translators: %s: human time diff e.g. "2 hours". */
										printf( esc_html__( '%s ago', 'jot' ), esc_html( human_time_diff( $ts, time() ) ) );
										?>
									</span>
								</span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</section>

			<?php $this->render_debug_panel( $user_id ); ?>

			<footer class="jot-widget__footer">
				<span class="jot-widget__muted jot-widget__refreshed"
					<?php if ( $last_refresh > 0 ) : ?>
						title="<?php echo esc_attr( wp_date( 'c', $last_refresh ) ); ?>"
					<?php endif; ?>
				>
					<?php
					if ( $last_refresh > 0 ) {
						/* translators: %s: human time diff e.g. "2 hours" */
						printf( esc_html__( 'Refreshed %s ago', 'jot' ), esc_html( human_time_diff( $last_refresh, time() ) ) );
					} else {
						esc_html_e( 'Not yet refreshed.', 'jot' );
					}
					?>
				</span>
				<?php if ( ! empty( $connections ) ) : ?>
					<button type="button" class="button button-small jot-widget__refresh">
						<?php esc_html_e( 'Refresh', 'jot' ); ?>
					</button>
				<?php endif; ?>
			</footer>
		</div>
		<?php
	}

	/**
	 * @param array<int, array{id:string,label:string,user:string}> $connections
	 * @param array<int, array<string, mixed>>                      $cards
	 */
	private function render_suggestions( int $user_id, array $connections, array $cards, bool $ai_available, string $ai_error, string $connections_url ): void {
		if ( empty( $connections ) ) {
			?>
			<div class="jot-widget__empty">
				<p><?php esc_html_e( "Connect a service and Jot will suggest post ideas from your activity.", 'jot' ); ?></p>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( $connections_url ); ?>">
						<?php esc_html_e( 'Connect a service', 'jot' ); ?>
					</a>
				</p>
			</div>
			<?php
			return;
		}

		if ( empty( $cards ) ) {
			?>
			<div class="jot-widget__empty">
				<p><?php esc_html_e( 'Nothing new in your activity yet. Try Refresh, or come back tomorrow.', 'jot' ); ?></p>
			</div>
			<?php
			return;
		}

		if ( $ai_error !== '' ) {
			?>
			<div class="jot-widget__notice jot-widget__notice--warning" role="status">
				<strong><?php esc_html_e( 'AI is unavailable.', 'jot' ); ?></strong>
				<?php esc_html_e( 'Showing raw activity digests below. Open the AI debug panel for details.', 'jot' ); ?>
			</div>
			<?php
		} elseif ( ! $ai_available ) {
			?>
			<p class="jot-widget__muted"><?php esc_html_e( 'Connect an AI provider for titled suggestions and full drafts.', 'jot' ); ?></p>
			<?php
		}

		echo '<ul class="jot-widget__digests">';
		foreach ( $cards as $card ) {
			$this->render_card( $card, $ai_available && $ai_error === '' );
		}
		echo '</ul>';
	}

	/**
	 * @param array<string, mixed> $card
	 */
	private function render_card( array $card, bool $tier_buttons ): void {
		$angle_key = (string) ( $card['angle_key'] ?? '' );
		$label     = (string) ( $card['label'] ?? '' );
		$title     = (string) ( $card['title'] ?? '' );
		$body      = (string) ( $card['rationale'] ?? $card['digest'] ?? '' );
		?>
		<li class="jot-widget__card" data-angle-key="<?php echo esc_attr( $angle_key ); ?>">
			<button
				type="button"
				class="jot-widget__dismiss"
				aria-label="<?php echo esc_attr( sprintf( /* translators: %s: card title */ __( 'Dismiss: %s', 'jot' ), $title !== '' ? $title : $label ) ); ?>"
			>×</button>
			<div class="jot-widget__card-title">
				<?php if ( $label !== '' ) : ?>
					<span class="jot-widget__card-badge"><?php echo esc_html( $label ); ?></span>
				<?php endif; ?>
				<?php if ( $title !== '' ) : ?>
					<strong><?php echo esc_html( $title ); ?></strong>
				<?php endif; ?>
			</div>
			<p class="jot-widget__card-digest"><?php echo esc_html( $body ); ?></p>
			<div class="jot-widget__card-actions">
				<?php if ( $tier_buttons ) : ?>
					<button type="button" class="button jot-widget__tier" data-tier="spark"><?php esc_html_e( 'Quick spark', 'jot' ); ?></button>
					<button type="button" class="button jot-widget__tier" data-tier="outline"><?php esc_html_e( 'Outline', 'jot' ); ?></button>
					<button type="button" class="button button-primary jot-widget__tier" data-tier="full"><?php esc_html_e( 'Full draft', 'jot' ); ?></button>
				<?php else : ?>
					<button type="button" class="button button-primary jot-widget__quick-draft">
						<?php esc_html_e( 'Quick draft', 'jot' ); ?>
					</button>
				<?php endif; ?>
			</div>
			<p class="jot-widget__card-error" role="alert" hidden></p>
		</li>
		<?php
	}

	private function render_debug_panel( int $user_id ): void {
		if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'Jot_Ai' ) ) {
			return;
		}
		$debug = jot_get_user_array( $user_id, Jot_Ai::DEBUG_META );
		if ( empty( $debug ) ) {
			return;
		}
		?>
		<details class="jot-widget__debug">
			<summary><?php esc_html_e( 'AI debug (admin only)', 'jot' ); ?></summary>
			<p class="jot-widget__muted">
				<?php if ( ! empty( $debug['error'] ) ) : ?>
					<strong><?php esc_html_e( 'Error:', 'jot' ); ?></strong>
					<?php echo esc_html( (string) $debug['error'] ); ?>
				<?php else : ?>
					<strong><?php esc_html_e( 'Last raw response:', 'jot' ); ?></strong>
				<?php endif; ?>
			</p>
			<?php if ( ! empty( $debug['raw'] ) ) : ?>
				<pre class="jot-widget__debug-pre"><?php echo esc_html( (string) $debug['raw'] ); ?></pre>
			<?php endif; ?>
		</details>
		<?php
	}

	/**
	 * Build the unified card list. Prefer AI cards; fall back to digests.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function build_card_list( int $user_id ): array {
		$cards   = jot_get_user_array( $user_id, Jot_Cron::USER_CARDS_META );
		$digests = jot_get_user_array( $user_id, Jot_Cron::USER_DIGESTS_META );

		$source = ! empty( $cards ) ? $cards : $digests;
		// Digest entries are card-shaped enough: angle_key, label, digest. The
		// render pass uses `digest` when `rationale` is missing and leaves `title`
		// empty so only the service badge shows.
		return array_slice( $source, 0, self::MAX_CARDS_SHOWN );
	}

	private function tier_label( string $tier ): string {
		return match ( $tier ) {
			'spark'   => __( 'Spark', 'jot' ),
			'outline' => __( 'Outline', 'jot' ),
			'full'    => __( 'Full draft', 'jot' ),
			default   => $tier,
		};
	}

	/**
	 * @return WP_Post[]
	 */
	private function get_jot_drafts(): array {
		$query = new WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => 'draft',
				'author'         => get_current_user_id(),
				'posts_per_page' => 5,
				'meta_key'       => '_jot_source',
				'date_query'     => array(
					array( 'after' => '30 days ago' ),
				),
				'no_found_rows'  => true,
			)
		);

		return $query->posts;
	}
}
