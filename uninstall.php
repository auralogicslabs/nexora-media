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
	'nxm_enable_queue',
	'nxm_auto_process_queue',
	'nxm_processing_paused',
	'nxm_enable_webp',
	'nxm_enable_avif',
	'nxm_enable_adaptive',
	'nxm_enable_lazyload',
	'nxm_strip_exif',
	'nxm_enable_css_cache',
	'nxm_enable_dom_rewrite',
	'nxm_max_width',
	'nxm_quality',
	'nxm_responsive_widths',
	'nxm_wizard_complete',
	'nxm_version',

	// Runtime / queue state
	'nxm_process_queue',
	'nxm_current_queue_total',
	'nxm_total_queued',
	'nxm_total_processed',
	'nxm_total_failed',
	'nxm_queue_errors',
	'nxm_recent_errors',
	'nxm_last_run_at',

	// Engine bridge + admin context
	'nxm_engine_media_changed_at',
	'nxm_native_delivery_engine_notified_version',
	'nxm_css_cache_invalidated_at',

	// Install identity (used to detect fresh installs in the React app)
	'nxm_install_id',
];

foreach ( $options as $option ) {
	delete_option( $option );
}

// ─── Transients ────────────────────────────────────────────
delete_transient( 'nxm_queue_lock' );
delete_transient( 'nxm_queue_current' );

// ─── Scheduled events ──────────────────────────────────────
wp_clear_scheduled_hook( 'nxm_process_queue_event' );

// ─── Per-user wizard / onboarding flags ────────────────────
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot uninstall cleanup.
$wpdb->delete( $wpdb->usermeta, [ 'meta_key' => 'nxm_onboarding_complete' ] );

// ─── Per-attachment Nexora meta ────────────────────────────
$attachment_meta_keys = [
	'_nxm_variants',
	'_nxm_status',
	'_nxm_delivery_disabled',
	'_nxm_frontend_synced_at',
	'_nxm_skip_until',
	'_nxm_failure_count',
];
foreach ( $attachment_meta_keys as $meta_key ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot uninstall cleanup.
	$wpdb->delete( $wpdb->postmeta, [ 'meta_key' => $meta_key ] );
}

// ─── Generated CSS cache (filesystem) ──────────────────────
$upload_dir = wp_upload_dir();
$nxm_dir    = trailingslashit( $upload_dir['basedir'] ) . 'nexora-media';
if ( is_dir( $nxm_dir ) ) {
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	global $wp_filesystem;
	if ( WP_Filesystem() && $wp_filesystem instanceof WP_Filesystem_Base ) {
		$css_dir = trailingslashit( $nxm_dir ) . 'css';
		if ( $wp_filesystem->is_dir( $css_dir ) ) {
			foreach ( (array) glob( trailingslashit( $css_dir ) . '*.css' ) as $file ) {
				if ( is_file( $file ) ) {
					wp_delete_file( $file );
				}
			}
			$wp_filesystem->rmdir( $css_dir );
		}
		$wp_filesystem->rmdir( $nxm_dir );
	}
}
