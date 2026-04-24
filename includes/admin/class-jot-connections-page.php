<?php
/**
 * Jot → Connections admin page (Phase 1 stub).
 *
 * Registers the top-level "Jot" menu and the Connections subpage. Actual OAuth
 * flows land in Phase 2. This stub lists the v1 services as disabled rows so
 * users can see what's planned.
 *
 * @package Jot
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class Jot_Connections_Page {

	public const MENU_SLUG = 'jot-connections';

	public function register(): void {
		add_menu_page(
			__( 'Jot', 'jot' ),
			__( 'Jot', 'jot' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-edit-large',
			71
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Connections', 'jot' ),
			__( 'Connections', 'jot' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$services = array(
			'github'   => __( 'GitHub', 'jot' ),
			'mastodon' => __( 'Mastodon', 'jot' ),
			'bluesky'  => __( 'Bluesky', 'jot' ),
			'strava'   => __( 'Strava', 'jot' ),
		);

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Jot Connections', 'jot' ); ?></h1>
			<p><?php esc_html_e( 'Connect the services whose activity should generate post ideas. Connections are per-user and are not shared with other users on this site.', 'jot' ); ?></p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Service', 'jot' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'jot' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Action', 'jot' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $services as $id => $label ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $label ); ?></strong></td>
							<td><span class="jot-status jot-status--disconnected"><?php esc_html_e( 'Not connected', 'jot' ); ?></span></td>
							<td>
								<button type="button" class="button" disabled>
									<?php esc_html_e( 'Connect (coming in Phase 2)', 'jot' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
