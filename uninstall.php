<?php
/**
 * Runs when the plugin is deleted from WordPress.
 * Data is only removed if "Delete all data on uninstall" is turned on in Settings.
 *
 * @package LeadSift
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$leadsift_settings = get_option( 'leadsift_settings', array() );

if ( ! empty( $leadsift_settings['delete_on_uninstall'] ) ) {
	global $wpdb;
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}leadsift_inquiries" ); // phpcs:ignore
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}leadsift_ai_log" ); // phpcs:ignore

	$leadsift_page = (int) get_option( 'leadsift_dashboard_page_id' );
	if ( $leadsift_page ) {
		wp_delete_post( $leadsift_page, true );
	}

	foreach ( array( 'leadsift_settings', 'leadsift_learned_senders', 'leadsift_version', 'leadsift_db_version', 'leadsift_dashboard_page_id', 'leadsift_business_scanned_at', 'leadsift_form_maps', 'leadsift_import_state', 'leadsift_portal_users', 'leadsift_portal_tokens' ) as $leadsift_option ) {
		delete_option( $leadsift_option );
	}
}

wp_clear_scheduled_hook( 'leadsift_daily_cleanup' );
wp_unschedule_hook( 'leadsift_ai_check' );
wp_unschedule_hook( 'leadsift_import_run' );
