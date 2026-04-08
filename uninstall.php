<?php
/**
 * Uninstall script for We Spam Econo.
 *
 * This file runs when the plugin is deleted via the WordPress admin.
 * It removes all plugin data including:
 * - Custom database table (wp_wse_blocklist)
 * - Scheduled cron events
 * - Legacy wp_options entries (blacklist_keys, disallowed_keys, etc.)
 *
 * Note: We delete blacklist_keys/disallowed_keys because this plugin never uses
 * them - all data is stored in the custom table. If these options exist at
 * uninstall time, they're either legacy data or orphaned entries.
 *
 * @package We_Spam_Econo
 */

// Security check: exit if uninstall not called from WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop the custom table.
$table_name = $wpdb->prefix . 'wse_blocklist';
$wpdb->query( "DROP TABLE IF EXISTS $table_name" );

// Clear the scheduled cron events.
$update_timestamp = wp_next_scheduled( 'wse_scheduled_update' );
if ( $update_timestamp ) {
	wp_unschedule_event( $update_timestamp, 'wse_scheduled_update' );
}

$optimize_timestamp = wp_next_scheduled( 'wse_scheduled_optimize' );
if ( $optimize_timestamp ) {
	wp_unschedule_event( $optimize_timestamp, 'wse_scheduled_optimize' );
}

// Delete legacy transient (from older versions).
delete_transient( 'wse_update_process' );

// Delete legacy options (these should already be migrated/deleted, but clean up just in case).
delete_option( 'blacklist_local' );
delete_option( 'blacklist_exclude' );

// Delete the core WordPress blocklist options.
// We clean these up because this plugin completely bypasses wp_options storage.
// If these exist, they're either:
// 1. Legacy data from before v2.0 migration.
// 2. Manually added entries that are now unused.
// 3. Data from another plugin (unlikely since these are core WP options).
delete_option( 'blacklist_keys' );
delete_option( 'disallowed_keys' );
