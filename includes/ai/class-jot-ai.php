<?php
/**
 * wp-ai-client wrapper.
 *
 * Provider-agnostic. Any AI provider configured via WordPress 7.0's Connectors
 * API (or the wp-ai-client plugin on older cores) will work. We do not tune to
 * any specific model family.
 *
 * @package Jot
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class Jot_Ai {

	public static function is_available(): bool {
		// jot_ai_is_available() (in jot.php) checks the Connectors API. Here we
		// also require the wp-ai-client entry point to exist.
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return false;
		}
		return jot_ai_is_available();
	}

	/**
	 * Generate suggestion cards from digests + recent post titles.
	 *
	 * @param array<int, array{label:string,digest:string,service:string,angle_key:string}> $digests
	 * @param array<int, string>                                                            $recent_titles
	 * @return array<int, array{title:string,rationale:string,angle_key:string,service:string,label:string,digest:string}>|WP_Error
	 */
	public static function generate_cards( array $digests, array $recent_titles, string $voice_hint ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'jot_ai_unavailable', __( 'No AI provider is configured.', 'jot' ) );
		}
		if ( empty( $digests ) ) {
			return array();
		}

		$prompt = Jot_Prompts::cards( $digests, $recent_titles, $voice_hint );
		$raw    = wp_ai_client_prompt( $prompt )
			->using_temperature( 0.7 )
			->as_json_response( Jot_Prompts::cards_schema() )
			->generate_text();

		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$decoded = json_decode( (string) $raw, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'jot_ai_parse', __( 'AI returned malformed JSON.', 'jot' ) );
		}

		$by_digest = array();
		foreach ( $digests as $d ) {
			$by_digest[] = $d;
		}

		$cards = array();
		foreach ( $decoded as $i => $item ) {
			if ( ! is_array( $item ) || empty( $item['title'] ) || empty( $item['angle_key'] ) ) {
				continue;
			}
			// Pair cards with the originating digest (best effort: round-robin).
			$origin = $by_digest[ $i % max( 1, count( $by_digest ) ) ];
			$cards[] = array(
				'title'     => sanitize_text_field( (string) $item['title'] ),
				'rationale' => sanitize_textarea_field( (string) ( $item['rationale'] ?? '' ) ),
				'angle_key' => sanitize_title( (string) $item['angle_key'] ),
				'service'   => $origin['service'],
				'label'     => $origin['label'],
				'digest'    => $origin['digest'],
			);
		}

		return $cards;
	}

	/**
	 * Generate the tier-specific output for one card.
	 *
	 * @param array{title:string,rationale:string,digest:string,service:string,label:string} $card
	 * @return array{title:string, body_blocks:string}|WP_Error
	 */
	public static function generate_tier( string $tier, array $card, string $voice_hint ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'jot_ai_unavailable', __( 'No AI provider is configured.', 'jot' ) );
		}

		$temperature = match ( $tier ) {
			Jot_Prompts::TIER_SPARK   => 0.6,
			Jot_Prompts::TIER_OUTLINE => 0.5,
			Jot_Prompts::TIER_FULL    => 0.7,
			default                   => 0.5,
		};

		$prompt = Jot_Prompts::tier( $tier, $card, $voice_hint );
		$raw    = wp_ai_client_prompt( $prompt )
			->using_temperature( $temperature )
			->as_json_response( Jot_Prompts::schema( $tier ) )
			->generate_text();

		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$data = json_decode( (string) $raw, true );
		if ( ! is_array( $data ) || empty( $data['title'] ) ) {
			return new WP_Error( 'jot_ai_parse', __( 'AI returned malformed JSON.', 'jot' ) );
		}

		return array(
			'title'       => sanitize_text_field( (string) $data['title'] ),
			'body_blocks' => self::blockify( $tier, $data ),
		);
	}

	/**
	 * Turn the tier JSON shape into Gutenberg block markup.
	 *
	 * @param array<string, mixed> $data
	 */
	private static function blockify( string $tier, array $data ): string {
		if ( $tier === Jot_Prompts::TIER_SPARK ) {
			$body = wp_kses_post( (string) ( $data['body'] ?? '' ) );
			return "<!-- wp:paragraph -->\n<p>" . $body . "</p>\n<!-- /wp:paragraph -->";
		}

		if ( $tier === Jot_Prompts::TIER_OUTLINE ) {
			$out = '';
			foreach ( (array) ( $data['sections'] ?? array() ) as $section ) {
				$heading = wp_kses_post( (string) ( $section['heading'] ?? '' ) );
				$intent  = wp_kses_post( (string) ( $section['intent'] ?? '' ) );
				$out    .= "<!-- wp:heading {\"level\":2} -->\n<h2>" . $heading . "</h2>\n<!-- /wp:heading -->\n\n";
				if ( $intent !== '' ) {
					$out .= "<!-- wp:paragraph -->\n<p><em>" . $intent . '</em></p>' . "\n<!-- /wp:paragraph -->\n\n";
				}
			}
			return rtrim( $out );
		}

		if ( $tier === Jot_Prompts::TIER_FULL ) {
			$out = '';
			$intro = trim( (string) ( $data['intro'] ?? '' ) );
			if ( $intro !== '' ) {
				foreach ( preg_split( "/\n{2,}/", $intro ) ?: array() as $para ) {
					$out .= "<!-- wp:paragraph -->\n<p>" . wp_kses_post( $para ) . "</p>\n<!-- /wp:paragraph -->\n\n";
				}
			}
			foreach ( (array) ( $data['sections'] ?? array() ) as $section ) {
				$heading = wp_kses_post( (string) ( $section['heading'] ?? '' ) );
				$body    = trim( (string) ( $section['body'] ?? '' ) );
				$out    .= "<!-- wp:heading {\"level\":2} -->\n<h2>" . $heading . "</h2>\n<!-- /wp:heading -->\n\n";
				foreach ( preg_split( "/\n{2,}/", $body ) ?: array() as $para ) {
					if ( $para === '' ) {
						continue;
					}
					$out .= "<!-- wp:paragraph -->\n<p>" . wp_kses_post( $para ) . "</p>\n<!-- /wp:paragraph -->\n\n";
				}
			}
			$close = trim( (string) ( $data['close'] ?? '' ) );
			if ( $close !== '' ) {
				foreach ( preg_split( "/\n{2,}/", $close ) ?: array() as $para ) {
					$out .= "<!-- wp:paragraph -->\n<p>" . wp_kses_post( $para ) . "</p>\n<!-- /wp:paragraph -->\n\n";
				}
			}
			return rtrim( $out );
		}

		return '';
	}
}
