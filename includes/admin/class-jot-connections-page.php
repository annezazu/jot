<?php
/**
 * Jot → Connections admin page.
 *
 * Two responsibilities:
 *   1. Site-wide OAuth app credential management (manage_options).
 *   2. Per-user connect/disconnect against those configured apps (edit_posts).
 *
 * Actual OAuth redirect + callback handling live in the service classes and
 * are dispatched from jot.php::jot_dispatch_oauth_callback().
 *
 * @package Jot
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class Jot_Connections_Page {

	public const MENU_SLUG       = 'jot-connections';
	public const APPS_OPTION_KEY = 'jot_oauth_apps';

	/**
	 * Register admin-post handlers. Must be called on a hook that fires for
	 * admin-post.php requests (e.g. `init` or `admin_init`), not `admin_menu` —
	 * admin_menu does NOT fire on admin-post.php, and if form submissions
	 * target admin-post.php the handler callback must already be attached.
	 */
	public static function boot(): void {
		$instance = new self();
		add_action( 'admin_post_jot_save_oauth_apps', array( $instance, 'handle_save_apps' ) );
		add_action( 'admin_post_jot_disconnect_service', array( $instance, 'handle_disconnect' ) );
	}

	public function register(): void {
		add_menu_page(
			__( 'Jot', 'jot' ),
			__( 'Jot', 'jot' ),
			'edit_posts',
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-edit-large',
			71
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Connections', 'jot' ),
			__( 'Connections', 'jot' ),
			'edit_posts',
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	public function handle_save_apps(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'jot' ) );
		}
		check_admin_referer( 'jot_save_oauth_apps' );

		$existing = get_option( self::APPS_OPTION_KEY, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$submitted = isset( $_POST['jot_apps'] ) && is_array( $_POST['jot_apps'] )
			? wp_unslash( $_POST['jot_apps'] )
			: array();

		foreach ( jot_services() as $service ) {
			$id           = $service->id();
			$client_id    = isset( $submitted[ $id ]['client_id'] ) ? sanitize_text_field( (string) $submitted[ $id ]['client_id'] ) : '';
			$client_secret_input = isset( $submitted[ $id ]['client_secret'] ) ? (string) $submitted[ $id ]['client_secret'] : '';
			$client_secret       = sanitize_text_field( $client_secret_input );

			$existing[ $id ] = array(
				'client_id'     => $client_id,
				'client_secret' => $client_secret !== '' ? $client_secret : ( $existing[ $id ]['client_secret'] ?? '' ),
			);
		}

		update_option( self::APPS_OPTION_KEY, $existing, false );
		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) );
		exit;
	}

	public function handle_disconnect(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'jot' ) );
		}
		$service_id = isset( $_REQUEST['service'] ) ? sanitize_key( (string) wp_unslash( $_REQUEST['service'] ) ) : '';
		check_admin_referer( 'jot-disconnect-' . $service_id );

		$services = jot_services();
		if ( isset( $services[ $service_id ] ) ) {
			$services[ $service_id ]->disconnect( get_current_user_id() );
		}
		wp_safe_redirect( add_query_arg( 'disconnected', $service_id, admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) );
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$apps = get_option( self::APPS_OPTION_KEY, array() );
		if ( ! is_array( $apps ) ) {
			$apps = array();
		}
		$is_admin = current_user_can( 'manage_options' );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Jot Connections', 'jot' ); ?></h1>

			<?php if ( ! empty( $_GET['connected'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html( sprintf( /* translators: %s: service id */ __( 'Connected: %s', 'jot' ), sanitize_key( (string) wp_unslash( $_GET['connected'] ) ) ) ); ?></p>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $_GET['disconnected'] ) ) : ?>
				<div class="notice notice-info is-dismissible">
					<p><?php echo esc_html( sprintf( /* translators: %s: service id */ __( 'Disconnected: %s', 'jot' ), sanitize_key( (string) wp_unslash( $_GET['disconnected'] ) ) ) ); ?></p>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Your connections', 'jot' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Connections are per-user. Your tokens are never shared with other users on this site.', 'jot' ); ?></p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Service', 'jot' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'jot' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Action', 'jot' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( jot_services() as $service ) :
						$id         = $service->id();
						$status     = $service->status( get_current_user_id() );
						$configured = ! empty( $apps[ $id ]['client_id'] ) && ! empty( $apps[ $id ]['client_secret'] );
						$connected  = ! empty( $status['connected'] );
						?>
						<tr>
							<td><strong><?php echo esc_html( $service->label() ); ?></strong></td>
							<td>
								<?php if ( $connected ) : ?>
									<span class="jot-status jot-status--connected">
										<?php
										/* translators: %s: username on the remote service */
										printf( esc_html__( 'Connected as %s', 'jot' ), esc_html( $status['user'] ?? '' ) );
										?>
									</span>
								<?php elseif ( ! $configured ) : ?>
									<span class="jot-status jot-status--disconnected"><?php esc_html_e( 'App not configured', 'jot' ); ?></span>
								<?php else : ?>
									<span class="jot-status jot-status--disconnected"><?php esc_html_e( 'Not connected', 'jot' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $connected ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
										<?php wp_nonce_field( 'jot-disconnect-' . $id ); ?>
										<input type="hidden" name="action" value="jot_disconnect_service" />
										<input type="hidden" name="service" value="<?php echo esc_attr( $id ); ?>" />
										<button type="submit" class="button"><?php esc_html_e( 'Disconnect', 'jot' ); ?></button>
									</form>
								<?php else : ?>
									<?php
									$connect_url = wp_nonce_url(
										add_query_arg(
											array(
												'page'             => self::MENU_SLUG,
												'jot_oauth_start'  => $id,
											),
											admin_url( 'admin.php' )
										),
										'jot-connect-' . $id
									);
									?>
									<a
										class="button button-primary"
										href="<?php echo esc_url( $connect_url ); ?>"
										<?php if ( ! $configured ) echo 'aria-disabled="true" style="pointer-events:none;opacity:.5"'; ?>
									>
										<?php esc_html_e( 'Connect', 'jot' ); ?>
									</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $is_admin ) : ?>
				<hr style="margin:32px 0;" />
				<h2><?php esc_html_e( 'OAuth app credentials', 'jot' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Administrators only. Register an OAuth app with each service and enter its client credentials here. These credentials are shared by every user on this site — each user then connects their own account.', 'jot' ); ?>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="jot_save_oauth_apps" />
					<?php wp_nonce_field( 'jot_save_oauth_apps' ); ?>

					<table class="form-table" role="presentation">
						<tbody>
							<?php foreach ( jot_services() as $service ) :
								$id       = $service->id();
								$current  = isset( $apps[ $id ] ) && is_array( $apps[ $id ] ) ? $apps[ $id ] : array();
								$callback = $service->callback_url();
								?>
								<tr>
									<th scope="row">
										<?php echo esc_html( $service->label() ); ?>
										<p class="description">
											<?php esc_html_e( 'Callback URL:', 'jot' ); ?>
											<code><?php echo esc_html( $callback ); ?></code>
										</p>
									</th>
									<td>
										<p>
											<label>
												<?php esc_html_e( 'Client ID', 'jot' ); ?><br />
												<input
													type="text"
													class="regular-text"
													name="jot_apps[<?php echo esc_attr( $id ); ?>][client_id]"
													value="<?php echo esc_attr( (string) ( $current['client_id'] ?? '' ) ); ?>"
													autocomplete="off"
												/>
											</label>
										</p>
										<p>
											<label>
												<?php esc_html_e( 'Client Secret', 'jot' ); ?><br />
												<input
													type="password"
													class="regular-text"
													name="jot_apps[<?php echo esc_attr( $id ); ?>][client_secret]"
													value=""
													placeholder="<?php echo ! empty( $current['client_secret'] ) ? esc_attr__( '(saved — leave blank to keep)', 'jot' ) : ''; ?>"
													autocomplete="off"
												/>
											</label>
										</p>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<?php submit_button( __( 'Save credentials', 'jot' ) ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
