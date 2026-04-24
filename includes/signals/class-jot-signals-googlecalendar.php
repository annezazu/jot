<?php
/**
 * Google Calendar signal aggregator.
 *
 * Summarizes recent events into meeting count, total hours, and a small
 * sample of titles, e.g. "12 events over 5 days (≈7.5h in meetings);
 * notable: Roadmap sync, 1:1 with Dana, Design review."
 *
 * @package Jot
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class Jot_Signals_GoogleCalendar {

	/**
	 * @param array<int, array<string, mixed>> $events
	 */
	public static function aggregate( array $events ): string {
		if ( empty( $events ) ) {
			return '';
		}

		$count        = 0;
		$meeting_secs = 0;
		$days         = array();
		$titles       = array();

		foreach ( $events as $e ) {
			if ( ! is_array( $e ) ) {
				continue;
			}
			if ( ( $e['status'] ?? '' ) === 'cancelled' ) {
				continue;
			}
			$count++;

			$at = (int) ( $e['at'] ?? 0 );
			if ( $at > 0 ) {
				$days[ gmdate( 'Y-m-d', $at ) ] = true;
			}

			$end     = (int) ( $e['end'] ?? 0 );
			$all_day = ! empty( $e['all_day'] );
			if ( ! $all_day && $end > $at && $at > 0 ) {
				$meeting_secs += ( $end - $at );
			}

			$summary = trim( (string) ( $e['summary'] ?? '' ) );
			if ( $summary !== '' && count( $titles ) < 3 ) {
				$titles[] = $summary;
			}
		}

		if ( $count === 0 ) {
			return '';
		}

		$day_count = max( 1, count( $days ) );
		$hours     = $meeting_secs / 3600;

		$parts   = array();
		$parts[] = sprintf(
			/* translators: 1: event count, 2: day count */
			_n( '%1$d event over %2$d day', '%1$d events over %2$d days', $count, 'jot' ),
			$count,
			$day_count
		);

		if ( $hours >= 0.5 ) {
			$parts[] = sprintf(
				/* translators: %s: hours in meetings, formatted */
				__( '≈%sh in meetings', 'jot' ),
				$hours >= 10 ? number_format_i18n( $hours, 0 ) : number_format_i18n( $hours, 1 )
			);
		}

		$sentence = implode( ' ', array( $parts[0], '(' . implode( '; ', array_slice( $parts, 1 ) ) . ')' ) );
		if ( count( $parts ) === 1 ) {
			$sentence = $parts[0];
		}

		if ( ! empty( $titles ) ) {
			$sentence .= '; ' . sprintf(
				/* translators: %s: comma-separated list of notable event titles */
				__( 'notable: %s', 'jot' ),
				implode( ', ', $titles )
			);
		}

		return $sentence . '.';
	}
}
