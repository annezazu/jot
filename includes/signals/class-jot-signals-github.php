<?php
/**
 * GitHub signal aggregator.
 *
 * Turns raw GitHub event objects into one human-readable sentence per repo
 * touched in the window. The output is used directly (no AI) or as AI context.
 *
 * @package Jot
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class Jot_Signals_Github {

	/**
	 * @param array<int, array<string, mixed>> $events
	 */
	public static function aggregate( array $events ): string {
		$by_repo = array();
		foreach ( $events as $event ) {
			$repo = (string) ( $event['repo'] ?? '' );
			if ( $repo === '' ) {
				continue;
			}
			if ( ! isset( $by_repo[ $repo ] ) ) {
				$by_repo[ $repo ] = array(
					'commits' => 0,
					'prs'     => 0,
					'stars'   => 0,
					'days'    => array(),
				);
			}
			$day = gmdate( 'Y-m-d', (int) ( $event['at'] ?? time() ) );
			$by_repo[ $repo ]['days'][ $day ] = true;

			switch ( (string) ( $event['type'] ?? '' ) ) {
				case 'PushEvent':
					$commits = isset( $event['payload']['commits'] ) && is_array( $event['payload']['commits'] )
						? count( $event['payload']['commits'] )
						: 1;
					$by_repo[ $repo ]['commits'] += (int) $commits;
					break;
				case 'PullRequestEvent':
					$by_repo[ $repo ]['prs'] += 1;
					break;
				case 'WatchEvent':
					$by_repo[ $repo ]['stars'] += 1;
					break;
			}
		}

		if ( empty( $by_repo ) ) {
			return '';
		}

		uasort(
			$by_repo,
			static fn ( array $a, array $b ): int => ( $b['commits'] + $b['prs'] ) <=> ( $a['commits'] + $a['prs'] )
		);

		$top_repo   = (string) array_key_first( $by_repo );
		$top_stats  = $by_repo[ $top_repo ];
		$repo_short = self::short_repo_name( $top_repo );
		$day_count  = count( $top_stats['days'] );

		$parts = array();
		if ( $top_stats['commits'] > 0 ) {
			$parts[] = sprintf(
				/* translators: 1: commit count, 2: day count, 3: repo name. */
				_n(
					'%1$d commit across %2$d day to %3$s',
					'%1$d commits across %2$d days to %3$s',
					$top_stats['commits'],
					'jot'
				),
				$top_stats['commits'],
				max( 1, $day_count ),
				$repo_short
			);
		}
		if ( $top_stats['prs'] > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: pull request count. */
				_n( '%d pull request', '%d pull requests', $top_stats['prs'], 'jot' ),
				$top_stats['prs']
			);
		}
		if ( $top_stats['stars'] > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: starred repo count. */
				_n( '%d starred repo', '%d starred repos', $top_stats['stars'], 'jot' ),
				$top_stats['stars']
			);
		}

		$others = count( $by_repo ) - 1;
		$trailer = $others > 0
			? ' ' . sprintf(
				/* translators: %d: other-repo count */
				_n( '(plus activity in %d other repo)', '(plus activity in %d other repos)', $others, 'jot' ),
				$others
			)
			: '';

		return implode( ', ', $parts ) . '.' . $trailer;
	}

	private static function short_repo_name( string $full ): string {
		$parts = explode( '/', $full );
		return end( $parts ) ?: $full;
	}
}
