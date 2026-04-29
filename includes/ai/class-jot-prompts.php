<?php
/**
 * Jot prompt templates.
 *
 * All prompts are plain natural language, written to be provider-agnostic.
 * They do not reference any specific model family. Temperature is tuned per
 * tier at the Jot_Ai call site, not here.
 *
 * @package Jot
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class Jot_Prompts {

	public const TIER_SPARK   = 'spark';
	public const TIER_OUTLINE = 'outline';

	/**
	 * Prompt for generating 3–5 post-angle suggestion cards.
	 *
	 * @param array<int, array{label:string,digest:string}> $digests
	 * @param array<int, string>                            $recent_titles
	 */
	public static function cards( array $digests, array $recent_titles ): string {
		$digest_lines = array();
		$service_ids  = array();
		foreach ( $digests as $d ) {
			$digest_lines[] = '- [' . $d['service'] . '] ' . $d['label'] . ': ' . $d['digest'];
			$service_ids[]  = (string) $d['service'];
		}
		$digest_block = $digest_lines ? implode( "\n", $digest_lines ) : '(no recent activity)';
		$service_list = $service_ids ? implode( ', ', array_map( static fn ( string $s ): string => '"' . $s . '"', $service_ids ) ) : '(none)';

		$title_lines = array_slice( array_map( static fn ( string $t ): string => '- ' . $t, $recent_titles ), 0, 20 );
		$titles_block = $title_lines ? implode( "\n", $title_lines ) : '(no recent posts)';

		return <<<PROMPT
You suggest blog post angles to a writer based on their recent activity across connected services. You scaffold ideas; you do not write finished posts.

The writer's recent post titles (most recent first):
{$titles_block}

The writer's activity in the last week (each line is prefixed with its service ID in brackets):
{$digest_block}

Available service IDs: {$service_list}

Suggest 3 to 5 distinct post angles this writer could write about. Prefer angles that reflect specific details from the activity over generic topics. Avoid repeating or closely mirroring recent post titles. Feel free to weave multiple services together when an angle naturally spans them.

For each angle, produce:
- title: under 60 characters
- rationale: one sentence in plain language explaining why this angle is worth writing right now, referencing the specific activity
- angle_key: a short lowercase kebab-case slug (max 40 chars)
- sources: an array of service IDs from the list above that this angle draws from. Use only IDs from the list. Include every service that meaningfully informs the angle.

Return ONLY a JSON array of objects with keys: title, rationale, angle_key, sources.
PROMPT;
	}

	/**
	 * @param array{title:string, rationale:string, digest:string, service:string, label:string} $card
	 */
	public static function tier( string $tier, array $card ): string {
		$shared = <<<SHARED
You are helping a writer develop a blog post.

Proposed angle: {$card['title']}
Why this angle: {$card['rationale']}
Source activity ({$card['label']}): {$card['digest']}

SHARED;

		return match ( $tier ) {
			self::TIER_SPARK => $shared . <<<'SPARK'
Write a short Quick Spark for this angle:
- One catchy title (under 60 chars)
- A one- or two-sentence summary that captures the point of the post

Return ONLY a JSON object: { "title": "...", "body": "..." }
The body should be plain prose (no markdown).
SPARK,

			self::TIER_OUTLINE => $shared . <<<'OUTLINE'
Write an Outline for this angle:
- One title (under 60 chars)
- 3 to 5 section headings (each under 80 chars)
- For each heading, one short sentence describing the intent of that section

Return ONLY a JSON object:
{
  "title": "...",
  "sections": [
    { "heading": "...", "intent": "..." },
    ...
  ]
}
OUTLINE,

			default => $shared,
		};
	}

	/**
	 * JSON schemas for each tier, passed to wp-ai-client's as_json_response().
	 *
	 * @return array<string, mixed>
	 */
	public static function schema( string $tier ): array {
		return match ( $tier ) {
			self::TIER_SPARK => array(
				'type'       => 'object',
				'properties' => array(
					'title' => array( 'type' => 'string' ),
					'body'  => array( 'type' => 'string' ),
				),
				'required'   => array( 'title', 'body' ),
			),
			self::TIER_OUTLINE => array(
				'type'       => 'object',
				'properties' => array(
					'title'    => array( 'type' => 'string' ),
					'sections' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'heading' => array( 'type' => 'string' ),
								'intent'  => array( 'type' => 'string' ),
							),
							'required'   => array( 'heading', 'intent' ),
						),
					),
				),
				'required'   => array( 'title', 'sections' ),
			),
			default => array( 'type' => 'object' ),
		};
	}

	public static function cards_schema(): array {
		return array(
			'type'  => 'array',
			'items' => array(
				'type'       => 'object',
				'properties' => array(
					'title'     => array( 'type' => 'string' ),
					'rationale' => array( 'type' => 'string' ),
					'angle_key' => array( 'type' => 'string' ),
				),
				'required'   => array( 'title', 'rationale', 'angle_key' ),
			),
		);
	}
}
