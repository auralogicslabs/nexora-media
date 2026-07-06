<?php
/**
 * Nexora Media uninstall cleanup.
 *
 * Generated media variants (WebP/AVIF sidecars next to original images) are
 * intentionally not deleted on uninstall because site owners may still
 * reference them from CDN caches, static mirrors, or custom templates.
 *
 * Plugin-owned runtime state — options, transients, scheduled cron events,
 * user-meta wizard flags, per-attachment Nexora meta, and the generated CSS
 * cache directory — IS removed.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// ─── Options ────────────────────────────────────────────────
$options = [
	// Core settings
	'nxmedia_enable_queue',
	'nxmedia_auto_process_queue',
	'nxmedia_processing_paused',
	'nxmedia_enable_webp',
	'nxmedia_enable_avif',
	'nxmedia_enable_adaptive',
	'nxmedia_enable_lazyload',
	'nxmedia_strip_exif',
	'nxmedia_enable_css_cache',
	'nxmedia_enable_dom_rewrite',
	'nxmedia_max_width',
	'nxmedia_quality',
	'nxmedia_responsive_widths',
	'nxmedia_wizard_complete',
	'nxmedia_version',

	// Runtime / queue state
	'nxmedia_process_queue',
	'nxmedia_current_queue_total',
	'nxmedia_total_queued',
	'nxmedia_total_processed',
	'nxmedia_total_failed',
	'nxmedia_queue_errors',
	'nxmedia_recent_errors',
	'nxmedia_last_run_at',

	// Engine bridge + admin context
	'nxmedia_engine_media_changed_at',
	'nxmedia_native_delivery_engine_notified_version',
	'nxmedia_css_cache_invalidated_at',

	// Install identity (used to detect fresh installs in the React app)
	'nxmedia_install_id',

	// Prefix-migration flag
	'nxmedia_prefix_migrated',
];

foreach ( $options as $option ) {
	delete_option( $option );
}

// Defensive sweep: remove any surviving legacy "nxm_" options from installs
// that predate the prefix migration (in case the migration never ran).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot uninstall cleanup of the plugin's own legacy option keys.
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE 'nxm\_%' AND option_name NOT LIKE 'nxmedia\_%'"
);

// ─── Transients ────────────────────────────────────────────
delete_transient( 'nxmedia_queue_lock' );
delete_transient( 'nxmedia_queue_current' );

// ─── Scheduled events ──────────────────────────────────────
wp_clear_scheduled_hook( 'nxmedia_process_queue_event' );

// ─── Per-user wizard / onboarding flags ────────────────────
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot uninstall cleanup.
$wpdb->delete( $wpdb->usermeta, [ 'meta_key' => 'nxmedia_onboarding_complete' ] );

// ─── Per-attachment Nexora meta ────────────────────────────
$attachment_meta_keys = [
	'_nxmedia_variants',
	'_nxmedia_status',
	'_nxmedia_delivery_disabled',
	'_nxmedia_frontend_synced_at',
	'_nxmedia_skip_until',
	'_nxmedia_failure_count',
];
foreach ( $attachment_meta_keys as $meta_key ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot uninstall cleanup.
	$wpdb->delete( $wpdb->postmeta, [ 'meta_key' => $meta_key ] );
}

// ─── Generated CSS cache (filesystem) ──────────────────────
$upload_dir = wp_upload_dir();
$nxmedia_dir    = trailingslashit( $upload_dir['basedir'] ) . 'nexora-media';
if ( is_dir( $nxmedia_dir ) ) {
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	global $wp_filesystem;
	if ( WP_Filesystem() && $wp_filesystem instanceof WP_Filesystem_Base ) {
		$css_dir = trailingslashit( $nxmedia_dir ) . 'css';
		if ( $wp_filesystem->is_dir( $css_dir ) ) {
			foreach ( (array) glob( trailingslashit( $css_dir ) . '*.css' ) as $file ) {
				if ( is_file( $file ) ) {
					wp_delete_file( $file );
				}
			}
			$wp_filesystem->rmdir( $css_dir );
		}
		$wp_filesystem->rmdir( $nxmedia_dir );
	}
}
