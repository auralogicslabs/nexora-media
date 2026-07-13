<?php
/**
 * Nexora Media — Init (Bootstrapper)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NXMEDIA_Init {

    private static ?NXMEDIA_Init $instance = null;

    public static function get_instance(): NXMEDIA_Init {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Core Hooks
        add_action( 'init', [ $this, 'register_core' ] );
        add_action( 'init', [ $this, 'register_image_sizes' ] );
        add_filter( 'big_image_size_threshold', [ $this, 'big_image_threshold' ] );
        
        // Admin Hooks
        if ( is_admin() ) {
            $this->register_admin();
        }

        // REST API — always registered, gated by capability checks per route.
        if ( class_exists( 'NXMEDIA_REST' ) ) {
            NXMEDIA_REST::get_instance();
        }

        // Detect Ecosystem
        $this->detect_ecosystem();
    }

    public function register_image_sizes(): void {
        if ( ! get_option( 'nxmedia_enable_responsive_variants', false ) ) {
            return;
        }

        foreach ( NXMEDIA_Image_Processor::responsive_widths() as $width ) {
            add_image_size( 'nxm-' . $width . 'w', $width, 0, false );
        }
    }

    public function big_image_threshold( $threshold ): int {
        $max_width = (int) get_option( 'nxmedia_max_width', 2560 );
        return max( 1024, min( 6000, $max_width ) );
    }

    public function register_core(): void {
        // Translations are loaded automatically by WordPress.org for hosted
        // plugins since WP 4.6, so no manual load_plugin_textdomain() is needed.

        // Native delivery swaps eligible WordPress attachment image URLs only.
        if ( self::is_frontend_delivery_request() && class_exists( 'NXMEDIA_Native_Delivery' ) ) {
            NXMEDIA_Native_Delivery::get_instance();
        }

        // Full DOM rewriting is retained as an explicit advanced/experimental path only.
        if ( self::is_frontend_delivery_request() && get_option( 'nxmedia_enable_dom_rewrite', false ) && class_exists( 'NXMEDIA_Html_Rewriter' ) ) {
            NXMEDIA_Html_Rewriter::get_instance();
        }

        if ( self::is_frontend_delivery_request() && class_exists( 'NXMEDIA_CSS_Optimizer' ) ) {
            NXMEDIA_CSS_Optimizer::get_instance();
        }
        
        // Initialize Background Queue
        if ( class_exists( 'NXMEDIA_Queue' ) ) {
            NXMEDIA_Queue::get_instance();
        }
    }

    public function register_admin(): void {
        if ( class_exists( 'NXMEDIA_Admin' ) ) {
            NXMEDIA_Admin::get_instance();
        }
    }

    private function detect_ecosystem(): void {
        // Detect Nexora Engine
        if ( self::is_nexora_engine_active() ) {
            if ( class_exists( 'NXMEDIA_Engine_Bridge' ) ) {
                NXMEDIA_Engine_Bridge::get_instance();
                $notified_version = (string) get_option( 'nxmedia_native_delivery_engine_notified_version', '' );
                if ( get_option( 'nxmedia_enable_adaptive', false ) && get_option( 'nxmedia_enable_webp', true ) && NXMEDIA_VERSION !== $notified_version ) {
                    NXMEDIA_Engine_Bridge::get_instance()->notify_media_runtime_changed();
                    update_option( 'nxmedia_native_delivery_engine_notified_version', NXMEDIA_VERSION, false );
                }
            }
        }
    }

    public static function is_nexora_engine_active(): bool {
        // Current Engine prefix (NEXENG_) first, then legacy (NCX_) so the
        // bridge works with both older and current Engine builds.
        if ( defined( 'NEXENG_VERSION' ) || class_exists( 'NEXENG_Init' ) || class_exists( 'NEXENG_SSG' ) ) {
            return true;
        }
        if ( defined( 'NCX_VERSION' ) || class_exists( 'NCX_Init' ) || class_exists( 'NCX_SSG' ) ) {
            return true;
        }

        if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'nexora-engine/nexora-engine.php' ) ) {
            return true;
        }

        return '' !== (string) get_option( 'nexeng_ssg_enabled', '' )
            || '' !== (string) get_option( 'ncx_version', '' )
            || '' !== (string) get_option( 'ncx_ssg_enabled', '' );
    }

    public static function is_frontend_delivery_request(): bool {
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
            return false;
        }

        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return false;
        }

        if ( self::is_builder_or_preview_request() ) {
            return false;
        }

        if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
            return false;
        }

        return true;
    }

    private static function is_builder_or_preview_request(): bool {
        // This only READS query vars to detect a page-builder/preview context so
        // delivery rewriting can stand down while editing. It performs no action
        // and changes no state, so a nonce is neither applicable nor available
        // (these markers are set by third-party builders, not by this plugin).
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $query_keys = [
            'elementor-preview',
            'et_fb',
            'fl_builder',
            'ct_builder',
            'bricks',
            'oxygen_iframe',
            'vc_editable',
            'customize_changeset_uuid',
        ];

        foreach ( $query_keys as $key ) {
            if ( isset( $_GET[ $key ] ) ) {
                return true;
            }
        }

        $action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
        if ( in_array( $action, [ 'elementor', 'elementor_ajax', 'edit' ], true ) ) {
            return true;
        }

        $preview = isset( $_GET['preview'] ) ? sanitize_key( wp_unslash( $_GET['preview'] ) ) : '';
        if ( in_array( $preview, [ '1', 'true' ], true ) ) {
            return true;
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
            return true;
        }

        if ( class_exists( '\Elementor\Plugin' ) ) {
            $elementor = \Elementor\Plugin::$instance;
            if (
                isset( $elementor->editor ) &&
                method_exists( $elementor->editor, 'is_edit_mode' ) &&
                $elementor->editor->is_edit_mode()
            ) {
                return true;
            }

            if (
                isset( $elementor->preview ) &&
                method_exists( $elementor->preview, 'is_preview_mode' ) &&
                $elementor->preview->is_preview_mode()
            ) {
                return true;
            }
        }

        return false;
    }
}
