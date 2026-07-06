<?php
/**
 * Nexora Media — Engine Bridge (Integration with Nexora Engine)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NXMEDIA_Engine_Bridge {

    private static ?NXMEDIA_Engine_Bridge $instance = null;

    public static function get_instance(): NXMEDIA_Engine_Bridge {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'nxmedia_css_cache_updated', [ $this, 'notify_media_runtime_changed' ] );
        add_action( 'nxmedia_css_cache_purged', [ $this, 'notify_media_runtime_changed' ] );
        add_action( 'nxmedia_media_delivery_mode_changed', [ $this, 'notify_media_runtime_changed' ] );
    }

    /**
     * Called by the background queue when a batch of images finishes processing.
     * Signals Nexora Engine without deleting static mirrors or triggering builds.
     */
    public function notify_processing_complete(): void {
        if ( ! class_exists( 'NCX_Init' ) ) {
            return;
        }

        do_action( 'nxmedia_engine_integration_before_refresh' );

        // Never purge Nexora Engine static files from the media plugin. Media
        // queues can be large; deleting mirrors or forcing builds here would
        // surprise live sites. Expose only a signal for pending notices or
        // future targeted rebuild suggestions.
        update_option( 'nxmedia_engine_media_changed_at', time(), false );
        do_action( 'nxmedia_engine_media_variants_updated' );
    }

    public function notify_media_runtime_changed(): void {
        if ( ! class_exists( 'NCX_Init' ) ) {
            return;
        }

        update_option( 'nxmedia_engine_media_changed_at', time(), false );
        if ( class_exists( 'NCX_SSG' ) && method_exists( 'NCX_SSG', 'is_enabled' ) && NCX_SSG::is_enabled() ) {
            $ssg = NCX_SSG::get_instance();
            if ( method_exists( $ssg, 'schedule_global_invalidate' ) ) {
                $ssg->schedule_global_invalidate();
            }
        }
        do_action( 'nxmedia_engine_media_runtime_updated' );
    }
}
