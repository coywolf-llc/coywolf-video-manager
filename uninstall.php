<?php
/**
 * Uninstall cleanup for Coywolf Video Manager.
 *
 * Runs when the plugin is deleted from the Plugins screen. Removes the options,
 * custom tables, transients, capability, scheduled event, and postmeta the
 * plugin creates. Videos themselves live on Cloudflare and are never touched —
 * removing remote media on uninstall is left to the operator.
 *
 * @package CoywolfVideoManager
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Options.
$coywolf_cvm_options = array(
	'coywolf_cvm_api_token',
	'coywolf_cvm_account_id',
	'coywolf_cvm_settings',
	'coywolf_cvm_customer_code',
	'coywolf_cvm_signing_key',
	'coywolf_cvm_like_salt',
	'coywolf_cvm_version',
);
foreach ( $coywolf_cvm_options as $coywolf_cvm_option ) {
	delete_option( $coywolf_cvm_option );
}

// Custom tables.
$coywolf_cvm_tables = array(
	$wpdb->prefix . 'coywolf_cvm_stats',
	$wpdb->prefix . 'coywolf_cvm_likes',
	$wpdb->prefix . 'coywolf_cvm_usage',
);
foreach ( $coywolf_cvm_tables as $coywolf_cvm_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $coywolf_cvm_table ) );
}

// Self-updater caches.
delete_site_transient( 'coywolf_cvm_gh_release' );
delete_site_transient( 'coywolf_cvm_gh_release_neg' );
delete_site_transient( 'coywolf_cvm_gh_release_err' );

// Plugin transients (list cache keyed by args hash, per-session play throttles).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_coywolf_cvm_' ) . '%', $wpdb->esc_like( '_transient_timeout_coywolf_cvm_' ) . '%' ) );

// Strip the access capability from every role.
$coywolf_cvm_roles = wp_roles();
if ( $coywolf_cvm_roles instanceof WP_Roles ) {
	foreach ( array_keys( $coywolf_cvm_roles->roles ) as $coywolf_cvm_role_slug ) {
		$coywolf_cvm_role = get_role( $coywolf_cvm_role_slug );
		if ( $coywolf_cvm_role && $coywolf_cvm_role->has_cap( 'coywolf_cvm_manage' ) ) {
			$coywolf_cvm_role->remove_cap( 'coywolf_cvm_manage' );
		}
	}
}

// Scheduled reconcile event.
$coywolf_cvm_ts = wp_next_scheduled( 'coywolf_cvm_reconcile' );
if ( $coywolf_cvm_ts ) {
	wp_unschedule_event( $coywolf_cvm_ts, 'coywolf_cvm_reconcile' );
}

// Per-post embed index.
delete_post_meta_by_key( 'coywolf_cvm_videos' );
