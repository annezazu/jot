<?php
/**
 * Todoist signal aggregator.
 *
 * Summarizes recently completed tasks with counts per project, e.g.
 * "17 tasks completed across 4 projects; busiest: Inbox (9), Work (5)."
 *
 * @package Jot
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class Jot_Signals_Todoist {

	/**
	 * @param array<int, array<string, mixed>> $tasks
	 */
	public static function aggregate( array $tasks ): string {
		if ( empty( $tasks ) ) {
			return '';
		}

		$total       = 0;
		$by_project  = array();
		$days        = array();
		$kind        = 'completed';

		foreach ( $tasks as $t ) {
			if ( ! is_array( $t ) ) {
				continue;
			}
			$total++;
			$kind    = (string) ( $t['kind'] ?? $kind );
			$project = (string) ( $t['project'] ?? '' );
			if ( $project === '' ) {
				$project = __( 'Untitled project', 'jot' );
			}
			$by_project[ $project ] = ( $by_project[ $project ] ?? 0 ) + 1;

			$at = (int) ( $t['at'] ?? 0 );
			if ( $at > 0 ) {
				$days[ gmdate( 'Y-m-d', $at ) ] = true;
			}
		}

		if ( $total === 0 ) {
			return '';
		}

		arsort( $by_project );
		$project_count = count( $by_project );
		$top           = array_slice( $by_project, 0, 2, true );

		$top_parts = array();
		foreach ( $top as $name => $count ) {
			$top_parts[] = sprintf( '%s (%d)', $name, $count );
		}

		$parts = array();
		if ( $kind === 'active' ) {
			$parts[] = sprintf(
				/* translators: 1: active task count, 2: project count */
				_n( '%1$d active task across %2$d project', '%1$d active tasks across %2$d projects', $total, 'jot' ),
				$total,
				$project_count
			);
		} else {
			$parts[] = sprintf(
				/* translators: 1: completed task count, 2: project count */
				_n( '%1$d task completed across %2$d project', '%1$d tasks completed across %2$d projects', $total, 'jot' ),
				$total,
				$project_count
			);
		}

		if ( ! empty( $top_parts ) ) {
			$parts[] = sprintf(
				/* translators: %s: comma-separated list of busiest projects */
				__( 'busiest: %s', 'jot' ),
				implode( ', ', $top_parts )
			);
		}

		$sentence = implode( '; ', $parts );

		// Day spread is meaningful for "completed" (when tasks were checked off);
		// for "active" the timestamps are creation dates, which is misleading.
		if ( $kind === 'completed' ) {
			$day_count = count( $days );
			if ( $day_count > 1 ) {
				$sentence .= ' ' . sprintf(
					/* translators: %d: number of distinct days */
					_n( '(across %d day).', '(across %d days).', $day_count, 'jot' ),
					$day_count
				);
				return $sentence;
			}
		}

		return $sentence . '.';
	}
}
