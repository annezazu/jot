<?php
/**
 * Signals coordinator.
 *
 * Given a user id and a time window, produces a digest per connected service.
 * Each digest is a compact, human-readable string (used directly when no AI
 * provider is configured; passed as context to the AI in Phase 3).
 *
 * @package Jot
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class Jot_Signals {

	/**
	 * @return array<int, array{ service:string, label:string, digest:string, angle_key:string }>
	 */
	public static function build_for_user( int $user_id, int $since ): array {
		$digests = array();

		foreach ( jot_get_connected_services( $user_id ) as $conn ) {
			$service_id = $conn['id'];
			$aggregator = self::aggregator_for( $service_id );
			if ( ! $aggregator ) {
				continue;
			}

			$services = jot_services();
			if ( ! isset( $services[ $service_id ] ) ) {
				continue;
			}

			$events = $services[ $service_id ]->fetch_recent( $since, $user_id );
			if ( empty( $events ) ) {
				continue;
			}

			$digest = $aggregator::aggregate( $events );
			if ( $digest === '' ) {
				continue;
			}

			$digests[] = array(
				'service'   => $service_id,
				'label'     => $conn['label'],
				'digest'    => $digest,
				'angle_key' => $service_id . '-' . substr( md5( $digest ), 0, 10 ),
			);
		}

		return $digests;
	}

	private static function aggregator_for( string $service_id ): ?string {
		$class = 'Jot_Signals_' . ucfirst( $service_id );
		return class_exists( $class ) ? $class : null;
	}
}
