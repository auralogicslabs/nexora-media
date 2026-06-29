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
 * Plugin Name:       Nexora Media
 * Plugin URI:        https://auralogicslabs.com/nexora-media
 * Description:       Safe image optimization for WordPress — WebP variants, background queue, adaptive delivery, and Nexora Engine SSG bridge.
 * Version:           1.0.0
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
if ( ! defined( 'NXM_VERSION' ) ) {
    define( 'NXM_VERSION', '1.0.0' );
}
if ( ! defined( 'NXM_FILE' ) ) {
    define( 'NXM_FILE', __FILE__ );
}
if ( ! defined( 'NXM_DIR' ) ) {
    define( 'NXM_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'NXM_URL' ) ) {
    define( 'NXM_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'NXM_BASENAME' ) ) {
    define( 'NXM_BASENAME', plugin_basename( __FILE__ ) );
}

// PSR-4 Autoloader
spl_autoload_register( function ( $class ) {
    if ( strpos( $class, 'NXM_' ) !== 0 ) {
        return;
    }

    $path = strtolower( str_replace( '_', '-', $class ) );

    if ( $class === 'NXM_Engine' ) {
        $file = NXM_DIR . 'includes/engines/interface-nxm-engine.php';
    } elseif ( strpos( $path, 'nxm-engine-' ) === 0 ) {
        $file = NXM_DIR . 'includes/engines/class-' . $path . '.php';
    } else {
        $file = NXM_DIR . 'includes/class-' . $path . '.php';
    }

    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

register_activation_hook( __FILE__, function() {
    add_option( 'nxm_enable_queue', 1, '', false );
    add_option( 'nxm_auto_process_queue', 0, '', false );
    add_option( 'nxm_processing_paused', 1, '', false );
    add_option( 'nxm_enable_webp', 1, '', false );
    add_option( 'nxm_enable_avif', 0, '', false );
    add_option( 'nxm_enable_adaptive', 0, '', false );
    add_option( 'nxm_enable_lazyload', 1, '', false );
    add_option( 'nxm_strip_exif', 1, '', false );
    add_option( 'nxm_enable_css_cache', 0, '', false );
    add_option( 'nxm_enable_dom_rewrite', 0, '', false );
    add_option( 'nxm_max_width', 2560, '', false );
    add_option( 'nxm_quality', 82, '', false );
    add_option( 'nxm_responsive_widths', '320,640,960,1600', '', false );
    add_option( 'nxm_wizard_complete', 0, '', false );
    add_option( 'nxm_version', NXM_VERSION, '', false );
} );

register_deactivation_hook( __FILE__, function() {
    wp_clear_scheduled_hook( 'nxm_process_queue_event' );
    delete_transient( 'nxm_queue_lock' );
} );

// Initialize Plugin
add_action( 'plugins_loaded', function() {
    $stored_version = (string) get_option( 'nxm_version', '' );
    if ( NXM_VERSION !== $stored_version ) {
        if ( $stored_version && version_compare( $stored_version, '1.0.21', '<' ) ) {
            // CSS URL rewriting is now opt-in because mirrored SSG pages can
            // safely keep original plugin/theme stylesheet URLs without waiting
            // for generated media CSS files to be copied into the static mirror.
            update_option( 'nxm_enable_css_cache', 0, false );
            delete_transient( 'nxm_queue_lock' );
        }
        if ( $stored_version && version_compare( $stored_version, '1.0.22', '<' ) ) {
            update_option( 'nxm_enable_adaptive', 0, false );
            update_option( 'nxm_enable_queue', 0, false );
            update_option( 'nxm_auto_process_queue', 0, false );
            update_option( 'nxm_processing_paused', 1, false );
            update_option( 'nxm_current_queue_total', 0, false );
            delete_option( 'nxm_process_queue' );
            delete_transient( 'nxm_queue_lock' );
            wp_clear_scheduled_hook( 'nxm_process_queue_event' );
        }
        if ( $stored_version && version_compare( $stored_version, '1.0.27', '<' ) ) {
            update_option( 'nxm_enable_adaptive', 0, false );
            update_option( 'nxm_enable_avif', 0, false );
            update_option( 'nxm_enable_css_cache', 0, false );
            update_option( 'nxm_enable_dom_rewrite', 0, false );
        }
        if ( $stored_version && version_compare( $stored_version, '1.0.51', '<' ) ) {
            update_option( 'nxm_auto_process_queue', 0, false );
            update_option( 'nxm_processing_paused', 1, false );
            update_option( 'nxm_current_queue_total', 0, false );
            delete_option( 'nxm_process_queue' );
            delete_transient( 'nxm_queue_lock' );
            wp_clear_scheduled_hook( 'nxm_process_queue_event' );
        }
        update_option( 'nxm_version', NXM_VERSION, false );
        update_option( 'nxm_css_cache_invalidated_at', time(), false );
        if ( class_exists( 'NXM_CSS_Optimizer' ) ) {
            NXM_CSS_Optimizer::repair_existing_cache();
        }
    }

    if ( class_exists( 'NXM_Init' ) ) {
        NXM_Init::get_instance();
    }
} );
