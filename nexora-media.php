<?php
/**
 * Nexora Media
 *
 * Auralogics Labs Media Delivery Intelligence Platform.
 * Asynchronous image optimization, adaptive WebP delivery, and intelligent responsive scaling.
 *
 * @package           NexoraMedia
 * @author            Auralogics Labs
 * @license           GPL-2.0-or-later
 * @link              https://auralogicslabs.com
 *
 * Plugin Name:       Nexora Media – Image Optimization
 * Plugin URI:        https://auralogicslabs.com/products/nexora-media
 * Description:       Safe image optimization for WordPress — WebP variants, background queue, adaptive delivery, and Nexora Engine SSG bridge.
 * Version:           1.0.1
 * Author:            Auralogics Labs
 * Author URI:        https://auralogicslabs.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       nexora-media
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Tested up to:      7.0
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Global Constants
if ( ! defined( 'NXMEDIA_VERSION' ) ) {
    define( 'NXMEDIA_VERSION', '1.0.1' );
}
if ( ! defined( 'NXMEDIA_FILE' ) ) {
    define( 'NXMEDIA_FILE', __FILE__ );
}
if ( ! defined( 'NXMEDIA_DIR' ) ) {
    define( 'NXMEDIA_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'NXMEDIA_URL' ) ) {
    define( 'NXMEDIA_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'NXMEDIA_BASENAME' ) ) {
    define( 'NXMEDIA_BASENAME', plugin_basename( __FILE__ ) );
}

// PSR-4 Autoloader
spl_autoload_register( function ( $class ) {
    if ( strpos( $class, 'NXMEDIA_' ) !== 0 ) {
        return;
    }

    $path = strtolower( str_replace( '_', '-', $class ) );

    if ( $class === 'NXMEDIA_Engine' ) {
        // The encoder engine interface lives in the engines/ subfolder.
        $file = NXMEDIA_DIR . 'includes/engines/interface-nxmedia-engine.php';
    } else {
        // Try the engines/ subfolder first (encoder engines like NXMEDIA_Engine_GD),
        // then fall back to the flat includes/ folder. This keeps classes whose
        // name merely starts with "Engine" (e.g. NXMEDIA_Engine_Bridge) working —
        // they live in includes/, not engines/.
        $file = NXMEDIA_DIR . 'includes/engines/class-' . $path . '.php';
        if ( ! file_exists( $file ) ) {
            $file = NXMEDIA_DIR . 'includes/class-' . $path . '.php';
        }
    }

    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

register_activation_hook( __FILE__, function() {
    add_option( 'nxmedia_enable_queue', 1, '', false );
    add_option( 'nxmedia_auto_process_queue', 0, '', false );
    add_option( 'nxmedia_processing_paused', 1, '', false );
    add_option( 'nxmedia_enable_webp', 1, '', false );
    add_option( 'nxmedia_enable_avif', 0, '', false );
    add_option( 'nxmedia_enable_adaptive', 0, '', false );
    add_option( 'nxmedia_enable_lazyload', 1, '', false );
    add_option( 'nxmedia_strip_exif', 1, '', false );
    add_option( 'nxmedia_enable_css_cache', 0, '', false );
    add_option( 'nxmedia_enable_dom_rewrite', 0, '', false );
    add_option( 'nxmedia_max_width', 2560, '', false );
    add_option( 'nxmedia_quality', 82, '', false );
    add_option( 'nxmedia_responsive_widths', '320,640,960,1600', '', false );
    add_option( 'nxmedia_wizard_complete', 0, '', false );
    add_option( 'nxmedia_version', NXMEDIA_VERSION, '', false );
} );

register_deactivation_hook( __FILE__, function() {
    wp_clear_scheduled_hook( 'nxmedia_process_queue_event' );
    delete_transient( 'nxmedia_queue_lock' );
} );

// Initialize Plugin
/**
 * One-time migration of stored data from the legacy "nxm" prefix to "nxmedia".
 *
 * Only runs once (guarded by the nxmedia_prefix_migrated flag). Copies each old
 * option / user-meta / post-meta key to its new name and deletes the old key.
 * Safe to call on every request — it no-ops after the first successful run and
 * skips any key that was never set.
 */
function nxmedia_migrate_prefix() {
    if ( get_option( 'nxmedia_prefix_migrated' ) ) {
        return;
    }

    global $wpdb;

    // ── Options: nxm_* → nxmedia_* ───────────────────────────────────────────
    // Discover every surviving nxm_ option by name so we catch legacy keys even
    // if they aren't referenced in current code, without hardcoding a huge list.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $old_options = $wpdb->get_col(
        "SELECT option_name FROM {$wpdb->options}
         WHERE option_name LIKE 'nxm\_%' AND option_name NOT LIKE 'nxmedia\_%'"
    );
    foreach ( (array) $old_options as $old_name ) {
        $new_name = 'nxmedia_' . substr( $old_name, strlen( 'nxm_' ) );
        if ( false === get_option( $new_name, false ) ) {
            $value = get_option( $old_name );
            add_option( $new_name, $value, '', false );
        }
        delete_option( $old_name );
    }

    // ── User meta: nxm_* → nxmedia_* (e.g. nxm_onboarding_complete) ──────────
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $old_user_meta = $wpdb->get_col(
        "SELECT DISTINCT meta_key FROM {$wpdb->usermeta}
         WHERE meta_key LIKE 'nxm\_%' AND meta_key NOT LIKE 'nxmedia\_%'"
    );
    foreach ( (array) $old_user_meta as $old_key ) {
        $new_key = 'nxmedia_' . substr( $old_key, strlen( 'nxm_' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->usermeta} SET meta_key = %s WHERE meta_key = %s",
                $new_key,
                $old_key
            )
        );
    }

    // ── Post meta: _nxm_* → _nxmedia_* (delivery flags, variants, status) ────
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $old_post_meta = $wpdb->get_col(
        "SELECT DISTINCT meta_key FROM {$wpdb->postmeta}
         WHERE meta_key LIKE '\_nxm\_%' AND meta_key NOT LIKE '\_nxmedia\_%'"
    );
    foreach ( (array) $old_post_meta as $old_key ) {
        $new_key = '_nxmedia_' . substr( $old_key, strlen( '_nxm_' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->postmeta} SET meta_key = %s WHERE meta_key = %s",
                $new_key,
                $old_key
            )
        );
    }

    // ── Ephemeral leftovers: drop stale transients + reschedule cron ─────────
    delete_transient( 'nxm_queue_lock' );
    delete_transient( 'nxm_queue_current' );
    if ( wp_next_scheduled( 'nxm_process_queue_event' ) ) {
        wp_clear_scheduled_hook( 'nxm_process_queue_event' );
    }

    update_option( 'nxmedia_prefix_migrated', 1, '', false );
}

add_action( 'plugins_loaded', function() {
    // One-time prefix migration: the plugin's data prefix changed from the
    // too-short "nxm_" to "nxmedia_" (options, user meta, transients) and
    // "_nxm_" to "_nxmedia_" (post meta). Copy any surviving old keys to the new
    // ones so existing installs keep their settings, then remove the old keys.
    // Runs before the version-gated migrations below, which read nxmedia_version.
    nxmedia_migrate_prefix();

    $stored_version = (string) get_option( 'nxmedia_version', '' );
    if ( NXMEDIA_VERSION !== $stored_version ) {
        if ( $stored_version && version_compare( $stored_version, '1.0.21', '<' ) ) {
            // CSS URL rewriting is now opt-in because mirrored SSG pages can
            // safely keep original plugin/theme stylesheet URLs without waiting
            // for generated media CSS files to be copied into the static mirror.
            update_option( 'nxmedia_enable_css_cache', 0, false );
            delete_transient( 'nxmedia_queue_lock' );
        }
        if ( $stored_version && version_compare( $stored_version, '1.0.22', '<' ) ) {
            update_option( 'nxmedia_enable_adaptive', 0, false );
            update_option( 'nxmedia_enable_queue', 0, false );
            update_option( 'nxmedia_auto_process_queue', 0, false );
            update_option( 'nxmedia_processing_paused', 1, false );
            update_option( 'nxmedia_current_queue_total', 0, false );
            delete_option( 'nxmedia_process_queue' );
            delete_transient( 'nxmedia_queue_lock' );
            wp_clear_scheduled_hook( 'nxmedia_process_queue_event' );
        }
        if ( $stored_version && version_compare( $stored_version, '1.0.27', '<' ) ) {
            update_option( 'nxmedia_enable_adaptive', 0, false );
            update_option( 'nxmedia_enable_avif', 0, false );
            update_option( 'nxmedia_enable_css_cache', 0, false );
            update_option( 'nxmedia_enable_dom_rewrite', 0, false );
        }
        if ( $stored_version && version_compare( $stored_version, '1.0.51', '<' ) ) {
            update_option( 'nxmedia_auto_process_queue', 0, false );
            update_option( 'nxmedia_processing_paused', 1, false );
            update_option( 'nxmedia_current_queue_total', 0, false );
            delete_option( 'nxmedia_process_queue' );
            delete_transient( 'nxmedia_queue_lock' );
            wp_clear_scheduled_hook( 'nxmedia_process_queue_event' );
        }
        update_option( 'nxmedia_version', NXMEDIA_VERSION, false );
        update_option( 'nxmedia_css_cache_invalidated_at', time(), false );
        if ( class_exists( 'NXMEDIA_CSS_Optimizer' ) ) {
            NXMEDIA_CSS_Optimizer::repair_existing_cache();
        }
    }

    if ( class_exists( 'NXMEDIA_Init' ) ) {
        NXMEDIA_Init::get_instance();
    }
} );
