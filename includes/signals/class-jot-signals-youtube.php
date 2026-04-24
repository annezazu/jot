<?php
/**
 * YouTube signal aggregator.
 *
 * Summarizes recently liked videos with counts and top channels, e.g.
 * "9 videos liked across 6 channels; most from: Veritasium (3), Kurzgesagt (2)."
 *
 * @package Jot
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class Jot_Signals_Youtube {

	/**
	 * @param array<int, array<string, mixed>> $likes
	 */
	public static function aggregate( array $likes ): string {
		if ( empty( $likes ) ) {
			return '';
		}

		$total       = 0;
		$by_channel  = array();
		$titles      = array();

		foreach ( $likes as $l ) {
			if ( ! is_array( $l ) ) {
				continue;
			}
			$total++;
			$channel = trim( (string) ( $l['channel'] ?? '' ) );
			if ( $channel !== '' ) {
				$by_channel[ $channel ] = ( $by_channel[ $channel ] ?? 0 ) + 1;
			}
			$title = trim( (string) ( $l['title'] ?? '' ) );
			if ( $title !== '' && count( $titles ) < 2 ) {
				$titles[] = $title;
			}
		}

		if ( $total === 0 ) {
			return '';
		}

		arsort( $by_channel );
		$channel_count = count( $by_channel );
		$top           = array_slice( $by_channel, 0, 2, true );

		$parts   = array();
		$parts[] = sprintf(
			/* translators: 1: video count, 2: channel count */
			_n( '%1$d video liked across %2$d channel', '%1$d videos liked across %2$d channels', $total, 'jot' ),
			$total,
			$channel_count
		);

		if ( ! empty( $top ) ) {
			$top_parts = array();
			foreach ( $top as $name => $count ) {
				$top_parts[] = sprintf( '%s (%d)', $name, $count );
			}
			$parts[] = sprintf(
				/* translators: %s: comma-separated list of channels with like counts */
				__( 'most from: %s', 'jot' ),
				implode( ', ', $top_parts )
			);
		}

		$sentence = implode( '; ', $parts );

		if ( ! empty( $titles ) ) {
			$sentence .= '; ' . sprintf(
				/* translators: %s: comma-separated list of video titles */
				__( 'including: %s', 'jot' ),
				implode( ', ', $titles )
			);
		}

		return $sentence . '.';
	}
}
