<?php
/**
 * Plugin Name:       Jot
 * Plugin URI:        https://github.com/annemccarthy/jot
 * Description:       A dashboard widget that surfaces post-idea suggestions drawn from your activity on connected services. Works without AI; becomes more useful with an AI provider connected.
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            Anne McCarthy
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       jot
 * Domain Path:       /languages
 *
 * @package Jot
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'JOT_VERSION', '0.1.0' );
define( 'JOT_PLUGIN_FILE', __FILE__ );
define( 'JOT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'JOT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		if ( strpos( $class, 'Jot_' ) !== 0 ) {
			return;
		}

		$file = 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';

		$candidates = array(
			JOT_PLUGIN_DIR . 'includes/widget/' . $file,
			JOT_PLUGIN_DIR . 'includes/admin/' . $file,
			JOT_PLUGIN_DIR . 'includes/cron/' . $file,
			JOT_PLUGIN_DIR . 'includes/rest/' . $file,
			JOT_PLUGIN_DIR . 'includes/signals/' . $file,
			JOT_PLUGIN_DIR . 'includes/ai/' . $file,
			JOT_PLUGIN_DIR . 'includes/services/' . $file,
			JOT_PLUGIN_DIR . 'includes/oauth/' . $file,
		);

		foreach ( $candidates as $path ) {
			if ( is_readable( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
);

register_activation_hook( __FILE__, 'jot_activate' );
register_deactivation_hook( __FILE__, 'jot_deactivate' );

/**
 * Activation: set a default options snapshot so other code can trust its shape.
 */
function jot_activate(): void {
	if ( get_option( 'jot_settings' ) === false ) {
		add_option(
			'jot_settings',
			array(
				'voice_hint'       => '',
				'dismiss_ttl_days' => 7,
				'services'         => array(
					'github'   => array( 'enabled' => false ),
					'mastodon' => array( 'enabled' => false ),
					'bluesky'  => array( 'enabled' => false ),
					'strava'   => array( 'enabled' => false ),
				),
			)
		);
	}
}

/**
 * Deactivation: clear scheduled crons. Persistent options are kept until uninstall.
 */
function jot_deactivate(): void {
	$timestamp = wp_next_scheduled( 'jot_daily_refresh' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'jot_daily_refresh' );
	}
}

add_action( 'plugins_loaded', 'jot_load_textdomain' );
function jot_load_textdomain(): void {
	load_plugin_textdomain( 'jot', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

add_action( 'plugins_loaded', 'jot_boot_subsystems' );
function jot_boot_subsystems(): void {
	if ( class_exists( 'Jot_Cron' ) ) {
		Jot_Cron::boot();
	}
	if ( class_exists( 'Jot_Rest_Controller' ) ) {
		Jot_Rest_Controller::boot();
	}
	if ( class_exists( 'Jot_Connections_Page' ) ) {
		Jot_Connections_Page::boot();
	}
}

add_action( 'wp_dashboard_setup', 'jot_register_dashboard_widget' );
function jot_register_dashboard_widget(): void {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	$widget = new Jot_Dashboard_Widget();
	$widget->register();
}

add_action( 'admin_menu', 'jot_register_admin_pages' );
function jot_register_admin_pages(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$connections = new Jot_Connections_Page();
	$connections->register();

	$settings = new Jot_Settings_Page();
	$settings->register();
}

/**
 * Read an array-valued user meta entry safely.
 *
 * Plain `(array) get_user_meta( $id, $key, true )` is a trap: when the meta
 * does not exist, get_user_meta returns '' (empty string), and `(array) ''`
 * yields a single-element array containing the empty string — not an empty
 * array. Callers then mistake "no meta" for "one item" and render garbage.
 *
 * @return array<mixed>
 */
function jot_get_user_array( int $user_id, string $key ): array {
	$stored = get_user_meta( $user_id, $key, true );
	return is_array( $stored ) ? $stored : array();
}

/**
 * Service registry.
 *
 * @return array<string, Jot_Service>
 */
function jot_services(): array {
	static $services = null;
	if ( $services !== null ) {
		return $services;
	}
	$services = array();
	foreach ( array( 'Jot_Service_Github', 'Jot_Service_Strava' ) as $class ) {
		if ( class_exists( $class ) ) {
			/** @var Jot_Service $instance */
			$instance                   = new $class();
			$services[ $instance->id() ] = $instance;
		}
	}
	return $services;
}

/**
 * Services the current user has connected.
 *
 * @return array<int, array{id:string,label:string,user:string}>
 */
function jot_get_connected_services( ?int $user_id = null ): array {
	$user_id = $user_id ?? get_current_user_id();
	if ( $user_id === 0 ) {
		return array();
	}
	$out = array();
	foreach ( jot_services() as $service ) {
		$status = $service->status( $user_id );
		if ( ! empty( $status['connected'] ) ) {
			$out[] = array(
				'id'    => $service->id(),
				'label' => $service->label(),
				'user'  => (string) ( $status['user'] ?? '' ),
			);
		}
	}
	return $out;
}

/**
 * Is an AI provider configured via the WP 7.0 Connectors API?
 */
function jot_ai_is_available(): bool {
	if ( ! function_exists( 'wp_get_connectors' ) ) {
		return false;
	}
	foreach ( (array) wp_get_connectors() as $connector ) {
		if ( ( $connector['type'] ?? '' ) === 'ai_provider' ) {
			return true;
		}
	}
	return false;
}

/**
 * Dispatch OAuth callbacks for any registered service.
 */
add_action( 'admin_init', 'jot_dispatch_oauth_callback' );
function jot_dispatch_oauth_callback(): void {
	if ( empty( $_GET['page'] ) || $_GET['page'] !== 'jot-connections' ) {
		return;
	}
	if ( empty( $_GET['jot_oauth_callback'] ) && empty( $_GET['jot_oauth_start'] ) ) {
		return;
	}
	if ( ! is_user_logged_in() ) {
		return;
	}

	$services = jot_services();

	if ( ! empty( $_GET['jot_oauth_start'] ) ) {
		$id = sanitize_key( (string) wp_unslash( $_GET['jot_oauth_start'] ) );
		if ( isset( $services[ $id ] ) ) {
			$services[ $id ]->connect();
		}
		return;
	}

	$id = sanitize_key( (string) wp_unslash( $_GET['jot_oauth_callback'] ) );
	if ( isset( $services[ $id ] ) ) {
		$services[ $id ]->handle_callback();
	}
}

add_action( 'admin_enqueue_scripts', 'jot_enqueue_widget_assets' );
function jot_enqueue_widget_assets( string $hook ): void {
	if ( $hook !== 'index.php' ) {
		return;
	}

	wp_enqueue_style(
		'jot-widget',
		JOT_PLUGIN_URL . 'assets/jot-widget.css',
		array(),
		JOT_VERSION
	);

	wp_enqueue_script(
		'jot-widget',
		JOT_PLUGIN_URL . 'assets/jot-widget.js',
		array( 'wp-i18n' ),
		JOT_VERSION,
		true
	);
}
