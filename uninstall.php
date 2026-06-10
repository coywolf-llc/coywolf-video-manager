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
	'coywolf_cvm_posters',
	'coywolf_cvm_descriptions',
	'coywolf_cvm_list_keys',
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

// Per-video caption schema caches (one option per video UID).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'coywolf_cvm_captions_' ) . '%' ) );

// Mirrored caption VTT files in the uploads folder.
$coywolf_cvm_uploads = wp_upload_dir( null, false );
if ( ! empty( $coywolf_cvm_uploads['basedir'] ) ) {
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	WP_Filesystem();
	global $wp_filesystem;
	if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
		$wp_filesystem->delete( trailingslashit( $coywolf_cvm_uploads['basedir'] ) . 'coywolf-video-manager', true );
	}
}

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

// Pending caption refresh events (one per video UID).
wp_unschedule_hook( 'coywolf_cvm_captions_refresh' );

// Per-post embed index.
delete_post_meta_by_key( 'coywolf_cvm_videos' );

// Per-user sticky list filters.
delete_metadata( 'user', 0, 'coywolf_cvm_search', '', true );
delete_metadata( 'user', 0, 'coywolf_cvm_filter', '', true );
