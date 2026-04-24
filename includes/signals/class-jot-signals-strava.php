<?php
/**
 * Strava signal aggregator.
 *
 * Groups recent activities by sport type and emits a one-sentence digest like
 * "4 runs totaling 32 km over 4 days, plus a 48 km ride."
 *
 * @package Jot
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class Jot_Signals_Strava {

	/**
	 * @param array<int, array<string, mixed>> $activities
	 */
	public static function aggregate( array $activities ): string {
		if ( empty( $activities ) ) {
			return '';
		}

		$by_type = array();
		$days    = array();
		foreach ( $activities as $a ) {
			$type = (string) ( $a['type'] ?? '' );
			if ( $type === '' ) {
				continue;
			}
			if ( ! isset( $by_type[ $type ] ) ) {
				$by_type[ $type ] = array(
					'count'        => 0,
					'distance_m'   => 0.0,
					'elevation_m'  => 0.0,
					'moving_s'     => 0,
				);
			}
			$by_type[ $type ]['count']++;
			$by_type[ $type ]['distance_m']  += (float) ( $a['distance_m'] ?? 0 );
			$by_type[ $type ]['elevation_m'] += (float) ( $a['total_elevation_m'] ?? 0 );
			$by_type[ $type ]['moving_s']    += (int) ( $a['moving_time_s'] ?? 0 );
			$days[ gmdate( 'Y-m-d', (int) ( $a['at'] ?? time() ) ) ] = true;
		}

		if ( empty( $by_type ) ) {
			return '';
		}

		uasort(
			$by_type,
			static fn ( array $a, array $b ): int => $b['count'] <=> $a['count']
		);

		$parts = array();
		$first = true;
		foreach ( $by_type as $type => $stats ) {
			$parts[] = self::describe( $type, $stats, $first );
			$first   = false;
		}

		$day_count = count( $days );
		$trailer   = $day_count > 1
			? ' ' . sprintf(
				/* translators: %d: number of distinct days */
				_n( 'across %d day.', 'across %d days.', $day_count, 'jot' ),
				$day_count
			)
			: '.';

		return implode( ', ', $parts ) . $trailer;
	}

	/**
	 * @param array{count:int,distance_m:float,elevation_m:float,moving_s:int} $stats
	 */
	private static function describe( string $type, array $stats, bool $is_first ): string {
		$label = self::type_label( $type, $stats['count'] );
		$count = $stats['count'];
		$km    = $stats['distance_m'] / 1000;

		if ( $km >= 0.5 ) {
			if ( $count > 1 ) {
				/* translators: 1: activity count, 2: activity label (e.g. "runs"), 3: distance in km */
				return sprintf( __( '%1$d %2$s totaling %3$s km', 'jot' ), $count, $label, self::format_km( $km ) );
			}
			/* translators: 1: a/an article, 2: distance in km, 3: activity label (e.g. "run") */
			return sprintf( __( '%1$s %2$s km %3$s', 'jot' ), $is_first ? 'a' : 'and a', self::format_km( $km ), $label );
		}

		/* translators: 1: activity count, 2: activity label (e.g. "workouts") */
		return sprintf( __( '%1$d %2$s', 'jot' ), $count, $label );
	}

	private static function type_label( string $type, int $count ): string {
		$map = array(
			'Run'         => array( 'run', 'runs' ),
			'Ride'        => array( 'ride', 'rides' ),
			'VirtualRide' => array( 'indoor ride', 'indoor rides' ),
			'Swim'        => array( 'swim', 'swims' ),
			'Walk'        => array( 'walk', 'walks' ),
			'Hike'        => array( 'hike', 'hikes' ),
			'Workout'     => array( 'workout', 'workouts' ),
			'WeightTraining' => array( 'strength session', 'strength sessions' ),
			'Yoga'        => array( 'yoga session', 'yoga sessions' ),
		);
		$pair = $map[ $type ] ?? array( strtolower( $type ), strtolower( $type ) . 's' );
		return $count === 1 ? $pair[0] : $pair[1];
	}

	private static function format_km( float $km ): string {
		return $km >= 10 ? number_format_i18n( $km, 0 ) : number_format_i18n( $km, 1 );
	}
}
