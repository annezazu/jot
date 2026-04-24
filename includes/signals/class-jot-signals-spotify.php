<?php
/**
 * Spotify signal aggregator.
 *
 * Boils a window of listening history into a one-liner like
 * "23 tracks across 14 artists; most-played: Big Thief (4), Waxahatchee (3)."
 *
 * @package Jot
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class Jot_Signals_Spotify {

	/**
	 * @param array<int, array<string, mixed>> $plays
	 */
	public static function aggregate( array $plays ): string {
		if ( empty( $plays ) ) {
			return '';
		}

		$track_count = 0;
		$by_artist   = array();
		$days        = array();

		foreach ( $plays as $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$track_count++;
			$artists = (array) ( $p['artists'] ?? array() );
			foreach ( $artists as $artist ) {
				$artist = (string) $artist;
				if ( $artist === '' ) {
					continue;
				}
				$by_artist[ $artist ] = ( $by_artist[ $artist ] ?? 0 ) + 1;
			}
			$at = (int) ( $p['at'] ?? 0 );
			if ( $at > 0 ) {
				$days[ gmdate( 'Y-m-d', $at ) ] = true;
			}
		}

		if ( $track_count === 0 ) {
			return '';
		}

		arsort( $by_artist );
		$artist_count = count( $by_artist );
		$top          = array_slice( $by_artist, 0, 3, true );

		$top_parts = array();
		foreach ( $top as $name => $count ) {
			$top_parts[] = sprintf( '%s (%d)', $name, $count );
		}

		$parts = array();
		$parts[] = sprintf(
			/* translators: 1: track count, 2: artist count */
			_n( '%1$d track across %2$d artist', '%1$d tracks across %2$d artists', $track_count, 'jot' ),
			$track_count,
			$artist_count
		);

		if ( ! empty( $top_parts ) ) {
			$parts[] = sprintf(
				/* translators: %s: comma-separated list of top artists with play counts */
				__( 'most-played: %s', 'jot' ),
				implode( ', ', $top_parts )
			);
		}

		$day_count = count( $days );
		$sentence  = implode( '; ', $parts );
		if ( $day_count > 1 ) {
			$sentence .= ' ' . sprintf(
				/* translators: %d: number of distinct days */
				_n( '(across %d day).', '(across %d days).', $day_count, 'jot' ),
				$day_count
			);
		} else {
			$sentence .= '.';
		}

		return $sentence;
	}
}
