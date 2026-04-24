<?php
/**
 * Jot Dashboard Widget.
 *
 * Renders:
 *   - Empty state if the user hasn't connected any service yet.
 *   - Otherwise the latest cached digests with a [Quick draft] button each.
 *   - "Your drafts from Jot" — posts created via /jot/v1/draft.
 *
 * All REST interactions live in assets/jot-widget.js.
 *
 * @package Jot
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class Jot_Dashboard_Widget {

	public const WIDGET_ID = 'jot_dashboard_widget';

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
		$user_id            = get_current_user_id();
		$connections        = jot_get_connected_services( $user_id );
		$settings_url       = admin_url( 'admin.php?page=jot-settings' );
		$connections_url    = admin_url( 'admin.php?page=jot-connections' );
		$drafts             = $this->get_jot_drafts();
		$cards              = (array) get_user_meta( $user_id, Jot_Cron::USER_CARDS_META, true );
		$digests            = (array) get_user_meta( $user_id, Jot_Cron::USER_DIGESTS_META, true );
		$last_refresh       = (int) get_user_meta( $user_id, Jot_Cron::USER_LAST_REFRESH, true );
		$ai_available       = class_exists( 'Jot_Ai' ) && Jot_Ai::is_available();

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

			<section class="jot-widget__section jot-widget__suggestions">
				<h4><?php esc_html_e( 'Suggestions', 'jot' ); ?></h4>

				<?php if ( empty( $connections ) ) : ?>
					<div class="jot-widget__empty">
						<p><?php esc_html_e( 'No suggestions yet. Connect a service to start receiving post ideas drawn from your activity.', 'jot' ); ?></p>
						<p>
							<a class="button button-primary" href="<?php echo esc_url( $connections_url ); ?>">
								<?php esc_html_e( 'Connect a service', 'jot' ); ?>
							</a>
						</p>
					</div>
				<?php elseif ( ! empty( $cards ) ) : ?>
					<ul class="jot-widget__digests">
						<?php foreach ( $cards as $card ) : ?>
							<li class="jot-widget__card" data-angle-key="<?php echo esc_attr( (string) ( $card['angle_key'] ?? '' ) ); ?>">
								<button type="button" class="jot-widget__dismiss" aria-label="<?php esc_attr_e( 'Dismiss this suggestion', 'jot' ); ?>">×</button>
								<div class="jot-widget__card-title">
									<span class="jot-widget__card-badge"><?php echo esc_html( (string) ( $card['label'] ?? '' ) ); ?></span>
									<strong><?php echo esc_html( (string) ( $card['title'] ?? '' ) ); ?></strong>
								</div>
								<p class="jot-widget__card-digest"><?php echo esc_html( (string) ( $card['rationale'] ?? '' ) ); ?></p>
								<div class="jot-widget__card-actions">
									<button type="button" class="button jot-widget__tier" data-tier="spark"><?php esc_html_e( 'Quick spark', 'jot' ); ?></button>
									<button type="button" class="button jot-widget__tier" data-tier="outline"><?php esc_html_e( 'Outline', 'jot' ); ?></button>
									<button type="button" class="button button-primary jot-widget__tier" data-tier="full"><?php esc_html_e( 'Full draft', 'jot' ); ?></button>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php elseif ( empty( $digests ) ) : ?>
					<div class="jot-widget__empty">
						<p><?php esc_html_e( 'No recent activity yet. Refresh to check now, or wait for the next daily update.', 'jot' ); ?></p>
					</div>
				<?php else : ?>
					<?php if ( ! $ai_available ) : ?>
						<p class="jot-widget__muted"><?php esc_html_e( 'Connect an AI provider to get titled suggestions instead of raw digests.', 'jot' ); ?></p>
					<?php endif; ?>
					<ul class="jot-widget__digests">
						<?php foreach ( $digests as $entry ) : ?>
							<li class="jot-widget__card" data-angle-key="<?php echo esc_attr( (string) ( $entry['angle_key'] ?? '' ) ); ?>">
								<div class="jot-widget__card-title">
									<strong><?php echo esc_html( (string) ( $entry['label'] ?? '' ) ); ?></strong>
								</div>
								<p class="jot-widget__card-digest"><?php echo esc_html( (string) ( $entry['digest'] ?? '' ) ); ?></p>
								<div class="jot-widget__card-actions">
									<button type="button" class="button button-primary jot-widget__quick-draft">
										<?php esc_html_e( 'Quick draft', 'jot' ); ?>
									</button>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</section>

			<section class="jot-widget__section jot-widget__drafts">
				<h4><?php esc_html_e( 'Your drafts from Jot', 'jot' ); ?></h4>
				<?php if ( empty( $drafts ) ) : ?>
					<p class="jot-widget__muted"><?php esc_html_e( 'Drafts created from Jot suggestions will appear here.', 'jot' ); ?></p>
				<?php else : ?>
					<ul class="jot-widget__drafts-list">
						<?php foreach ( $drafts as $draft ) : ?>
							<li>
								<a href="<?php echo esc_url( get_edit_post_link( $draft->ID ) ); ?>">
									<?php echo esc_html( get_the_title( $draft ) ?: __( '(no title)', 'jot' ) ); ?>
								</a>
								<span class="jot-widget__muted">
									<?php
									/* translators: %s: human time diff e.g. "2 hours". */
									printf(
										esc_html__( '— %s ago', 'jot' ),
										esc_html( human_time_diff( (int) get_post_timestamp( $draft ), time() ) )
									);
									?>
								</span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</section>

			<?php if ( current_user_can( 'manage_options' ) && class_exists( 'Jot_Ai' ) ) :
				$debug = (array) get_user_meta( $user_id, Jot_Ai::DEBUG_META, true );
				if ( ! empty( $debug ) ) :
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
							<pre style="white-space:pre-wrap;max-height:200px;overflow:auto;font-size:11px;background:#f6f7f7;padding:8px;"><?php echo esc_html( (string) $debug['raw'] ); ?></pre>
						<?php endif; ?>
					</details>
				<?php endif; ?>
			<?php endif; ?>

			<footer class="jot-widget__footer" aria-live="polite">
				<span class="jot-widget__muted jot-widget__refreshed">
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
