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
     * Nexora Engine's SSG class name, supporting both the current (NEXENG_)
     * and legacy (NCX_) prefixes so the bridge keeps working across Engine
     * versions. Empty string when Engine is not active.
     */
    public static function engine_ssg_class(): string {
        if ( class_exists( 'NEXENG_SSG' ) ) {
            return 'NEXENG_SSG';
        }
        if ( class_exists( 'NCX_SSG' ) ) {
            return 'NCX_SSG';
        }
        return '';
    }

    /** True when Nexora Engine (any supported version) is active. */
    public static function engine_active(): bool {
        return class_exists( 'NEXENG_Init' ) || class_exists( 'NCX_Init' );
    }

    /**
     * Called by the background queue when a batch of images finishes processing.
     * Signals Nexora Engine without deleting static mirrors or triggering builds.
     */
    public function notify_processing_complete(): void {
        if ( ! self::engine_active() ) {
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
        if ( ! self::engine_active() ) {
            return;
        }

        update_option( 'nxmedia_engine_media_changed_at', time(), false );
        $ssg_class = self::engine_ssg_class();
        if ( $ssg_class && method_exists( $ssg_class, 'is_enabled' ) && $ssg_class::is_enabled() ) {
            $ssg = $ssg_class::get_instance();
            if ( method_exists( $ssg, 'schedule_global_invalidate' ) ) {
                $ssg->schedule_global_invalidate();
            }
        }
        do_action( 'nxmedia_engine_media_runtime_updated' );
    }
}
