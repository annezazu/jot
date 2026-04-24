<?php
/**
 * Jot → Settings admin page (Phase 1 stub).
 *
 * Registers the submenu and a single "Voice" section via the Settings API so
 * the save path is wired even before Phase 3 (AI) needs it. Per-service toggles
 * and dismiss-TTL controls are intentionally deferred.
 *
 * @package Jot
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class Jot_Settings_Page {

	public const MENU_SLUG  = 'jot-settings';
	public const OPTION_KEY = 'jot_settings';

	public function register(): void {
		add_submenu_page(
			Jot_Connections_Page::MENU_SLUG,
			__( 'Jot Settings', 'jot' ),
			__( 'Settings', 'jot' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' )
		);

		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function register_settings(): void {
		register_setting(
			'jot_settings_group',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(
					'voice_hint'       => '',
					'dismiss_ttl_days' => 7,
				),
			)
		);

		add_settings_section(
			'jot_voice',
			__( 'Voice', 'jot' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Optional free-text description of your writing voice. Used as fallback when the Gutenberg content-guidelines experiment is not installed.', 'jot' ) . '</p>';
			},
			self::MENU_SLUG
		);

		add_settings_field(
			'voice_hint',
			__( 'Voice / style notes', 'jot' ),
			array( $this, 'render_voice_field' ),
			self::MENU_SLUG,
			'jot_voice'
		);
	}

	/**
	 * @param mixed $input
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ): array {
		$existing = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$clean = $existing;

		if ( is_array( $input ) ) {
			if ( isset( $input['voice_hint'] ) ) {
				$clean['voice_hint'] = sanitize_textarea_field( (string) $input['voice_hint'] );
			}
			if ( isset( $input['dismiss_ttl_days'] ) ) {
				$clean['dismiss_ttl_days'] = max( 1, min( 90, (int) $input['dismiss_ttl_days'] ) );
			}
		}

		return $clean;
	}

	public function render_voice_field(): void {
		$settings = get_option( self::OPTION_KEY, array() );
		$value    = is_array( $settings ) && isset( $settings['voice_hint'] ) ? (string) $settings['voice_hint'] : '';
		?>
		<textarea
			id="jot-voice-hint"
			name="<?php echo esc_attr( self::OPTION_KEY ); ?>[voice_hint]"
			rows="4"
			class="large-text"
			placeholder="<?php esc_attr_e( 'e.g. Casual, frontend perf, short posts.', 'jot' ); ?>"
		><?php echo esc_textarea( $value ); ?></textarea>
		<?php
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Jot Settings', 'jot' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'jot_settings_group' );
				do_settings_sections( self::MENU_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
