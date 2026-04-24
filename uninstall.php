<?php
/**
 * Jot uninstall: remove every option, user meta, and scheduled event created by the plugin.
 *
 * @package Jot
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$options = array(
	'jot_settings',
	'jot_suggestion_cards',
	'jot_last_refresh',
);

foreach ( $options as $option ) {
	delete_option( $option );
	delete_site_option( $option );
}

delete_transient( 'jot_digests' );

$user_meta_keys = array(
	'jot_user_acted_on',
	'jot_user_dismissed',
	'jot_oauth_tokens_github',
	'jot_oauth_tokens_mastodon',
	'jot_oauth_tokens_bluesky',
	'jot_oauth_tokens_strava',
);

global $wpdb;
foreach ( $user_meta_keys as $key ) {
	$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => $key ) );
}

$timestamp = wp_next_scheduled( 'jot_daily_refresh' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'jot_daily_refresh' );
}
