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

	public static function is_available( ?int $user_id = null ): bool {
		// Composite: a usable text-gen model in the registry AND the user's
		// per-user toggle (defaults to on). Pass an explicit user_id from
		// cron / REST contexts where get_current_user_id() may be 0.
		return jot_ai_provider_registered() && jot_ai_user_enabled( $user_id );
	}

	/**
	 * Generate suggestion cards from digests + recent post titles.
	 *
	 * @param array<int, array{label:string,digest:string,service:string,angle_key:string}> $digests
	 * @param array<int, string>                                                            $recent_titles
	 * @return array<int, array{title:string,rationale:string,angle_key:string,service:string,label:string,digest:string}>|WP_Error
	 */
	public static function generate_cards( array $digests, array $recent_titles ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'jot_ai_unavailable', __( 'No AI provider is configured.', 'jot' ) );
		}
		if ( empty( $digests ) ) {
			return array();
		}

		$prompt = Jot_Prompts::cards( $digests, $recent_titles );
		// Intentionally simple: plain generate_text() works across all providers.
		// Some providers (e.g. Gemini) don't advertise the structured-output
		// capability that as_json_response() requires, causing "No models found"
		// errors. We ask for JSON in the prompt and parse it ourselves.
		$raw = wp_ai_client_prompt( $prompt )->generate_text();

		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$decoded = self::extract_cards_list( self::decode_json_lenient( (string) $raw ) );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'jot_ai_parse', __( 'AI returned malformed JSON.', 'jot' ) );
		}

		// Build a map of service_id => digest entry so we can resolve labels +
		// assemble the source digests for each card without round-robin fakery.
		$by_service = array();
		foreach ( $digests as $d ) {
			$by_service[ (string) $d['service'] ] = $d;
		}

		$cards = array();
		foreach ( $decoded as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$title     = sanitize_text_field( (string) ( $item['title'] ?? '' ) );
			$rationale = sanitize_textarea_field( (string) ( $item['rationale'] ?? '' ) );
			$angle_key = sanitize_title( (string) ( $item['angle_key'] ?? '' ) );

			// Skip cards where required fields are empty AFTER sanitization — these
			// render as invisible cards in the widget and produce silent clicks.
			if ( $title === '' || $angle_key === '' ) {
				continue;
			}

			// Resolve the card's sources. Honor the AI's claim when it matches a
			// real connected service; otherwise fall back to all services (better
			// than a lie about a single service).
			$claimed = is_array( $item['sources'] ?? null )
				? array_values( array_filter( array_map( 'strval', $item['sources'] ) ) )
				: array();
			$services = array();
			$labels   = array();
			$digests_used = array();
			foreach ( $claimed as $service_id ) {
				if ( isset( $by_service[ $service_id ] ) && ! in_array( $service_id, $services, true ) ) {
					$services[]    = $service_id;
					$labels[]      = (string) $by_service[ $service_id ]['label'];
					$digests_used[] = (string) $by_service[ $service_id ]['digest'];
				}
			}
			if ( empty( $services ) ) {
				// Fallback: claim every connected service so we don't fib about the origin.
				foreach ( $by_service as $id => $entry ) {
					$services[]     = $id;
					$labels[]       = (string) $entry['label'];
					$digests_used[] = (string) $entry['digest'];
				}
			}

			$cards[] = array(
				'title'     => $title,
				'rationale' => $rationale,
				'angle_key' => $angle_key,
				'services'  => $services,
				'labels'    => $labels,
				// `service` + `label` retained for backward compatibility with widget
				// and REST lookups that pre-date the sources field.
				'service'   => $services[0],
				'label'     => $labels[0],
				'digest'    => implode( "\n", $digests_used ),
			);
		}

		return $cards;
	}

	/**
	 * Accept both `[...]` and wrapped shapes like `{"cards":[...]}` or
	 * `{"suggestions":[...]}` that some providers return even with schema hints.
	 *
	 * @param mixed $decoded
	 * @return array<int, mixed>|null
	 */
	private static function extract_cards_list( $decoded ): ?array {
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		// Sequential array already.
		if ( array_is_list( $decoded ) ) {
			return $decoded;
		}
		// Wrapped. Pick the first list-valued property.
		foreach ( $decoded as $value ) {
			if ( is_array( $value ) && array_is_list( $value ) ) {
				return $value;
			}
		}
		return null;
	}

	/**
	 * Parse JSON from a model response that may include prose preamble or
	 * ```json``` code fences.
	 *
	 * @return array<mixed>|null
	 */
	private static function decode_json_lenient( string $text ): ?array {
		$text = trim( $text );
		if ( $text === '' ) {
			return null;
		}
		// Strip common markdown code fences.
		$text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
		$text = preg_replace( '/\s*```\s*$/', '', (string) $text );
		$text = trim( (string) $text );

		$decoded = json_decode( $text, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}
		// Last resort: extract the first {...} or [...] substring.
		if ( preg_match( '/(\{.*\}|\[.*\])/s', $text, $m ) ) {
			$decoded = json_decode( $m[1], true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return null;
	}

	/**
	 * Generate the tier-specific output for one card.
	 *
	 * @param array{title:string,rationale:string,digest:string,service:string,label:string} $card
	 * @return array{title:string, body_blocks:string}|WP_Error
	 */
	public static function generate_tier( string $tier, array $card ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'jot_ai_unavailable', __( 'No AI provider is configured.', 'jot' ) );
		}

		$prompt = Jot_Prompts::tier( $tier, $card );
		$raw    = wp_ai_client_prompt( $prompt )->generate_text();

		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$data = self::decode_json_lenient( (string) $raw );
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

		return '';
	}
}
