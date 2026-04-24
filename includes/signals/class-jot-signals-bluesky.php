<?php
/**
 * Bluesky signal aggregator.
 *
 * Summarizes recent author-feed activity into a one-liner:
 * "5 posts, 2 replies, 1 repost across 3 days; best-received: 12 likes / 4 reposts."
 *
 * @package Jot
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class Jot_Signals_Bluesky {

	/**
	 * @param array<int, array<string, mixed>> $entries
	 */
	public static function aggregate( array $entries ): string {
		if ( empty( $entries ) ) {
			return '';
		}

		$counts    = array( 'post' => 0, 'reply' => 0, 'repost' => 0 );
		$days      = array();
		$best      = null;

		foreach ( $entries as $e ) {
			if ( ! is_array( $e ) ) {
				continue;
			}
			$kind = (string) ( $e['kind'] ?? 'post' );
			if ( ! isset( $counts[ $kind ] ) ) {
				$kind = 'post';
			}
			$counts[ $kind ]++;

			$at = (int) ( $e['at'] ?? 0 );
			if ( $at > 0 ) {
				$days[ gmdate( 'Y-m-d', $at ) ] = true;
			}

			$engagement = (int) ( $e['likes'] ?? 0 ) + (int) ( $e['reposts'] ?? 0 );
			if ( $kind !== 'repost' && ( $best === null || $engagement > ( $best['score'] ?? -1 ) ) ) {
				$best = array(
					'score'   => $engagement,
					'likes'   => (int) ( $e['likes'] ?? 0 ),
					'reposts' => (int) ( $e['reposts'] ?? 0 ),
				);
			}
		}

		$total = array_sum( $counts );
		if ( $total === 0 ) {
			return '';
		}

		$segments = array();
		if ( $counts['post'] > 0 ) {
			/* translators: %d: number of posts */
			$segments[] = sprintf( _n( '%d post', '%d posts', $counts['post'], 'jot' ), $counts['post'] );
		}
		if ( $counts['reply'] > 0 ) {
			/* translators: %d: number of replies */
			$segments[] = sprintf( _n( '%d reply', '%d replies', $counts['reply'], 'jot' ), $counts['reply'] );
		}
		if ( $counts['repost'] > 0 ) {
			/* translators: %d: number of reposts */
			$segments[] = sprintf( _n( '%d repost', '%d reposts', $counts['repost'], 'jot' ), $counts['repost'] );
		}

		$sentence = implode( ', ', $segments );

		$day_count = count( $days );
		if ( $day_count > 1 ) {
			$sentence .= ' ' . sprintf(
				/* translators: %d: number of distinct days */
				_n( 'across %d day', 'across %d days', $day_count, 'jot' ),
				$day_count
			);
		}

		if ( $best !== null && $best['score'] > 0 ) {
			$sentence .= '; ' . sprintf(
				/* translators: 1: likes, 2: reposts */
				__( 'best-received: %1$d likes / %2$d reposts', 'jot' ),
				$best['likes'],
				$best['reposts']
			);
		}

		return $sentence . '.';
	}
}
