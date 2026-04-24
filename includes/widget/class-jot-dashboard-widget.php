<?php
/**
 * Jot Dashboard Widget.
 *
 * Phase 1: renders an empty state when no services are connected, plus the
 * "Your drafts from Jot" list beneath. No AI calls, no REST, no external API.
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
		$settings_url    = admin_url( 'admin.php?page=jot-settings' );
		$connections_url = admin_url( 'admin.php?page=jot-connections' );
		$drafts          = $this->get_jot_drafts();

		?>
		<div class="jot-widget" aria-labelledby="jot-widget-heading">
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
				<div class="jot-widget__empty">
					<p>
						<?php esc_html_e( 'No suggestions yet. Connect a service to start receiving post ideas drawn from your activity.', 'jot' ); ?>
					</p>
					<p>
						<a class="button button-primary" href="<?php echo esc_url( $connections_url ); ?>">
							<?php esc_html_e( 'Connect a service', 'jot' ); ?>
						</a>
					</p>
				</div>
			</section>

			<section class="jot-widget__section jot-widget__drafts">
				<h4><?php esc_html_e( 'Your drafts from Jot', 'jot' ); ?></h4>
				<?php if ( empty( $drafts ) ) : ?>
					<p class="jot-widget__muted">
						<?php esc_html_e( 'Drafts created from Jot suggestions will appear here.', 'jot' ); ?>
					</p>
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

			<footer class="jot-widget__footer" aria-live="polite">
				<span class="jot-widget__muted">
					<?php esc_html_e( 'Not yet refreshed.', 'jot' ); ?>
				</span>
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
