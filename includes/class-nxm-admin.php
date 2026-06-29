<?php
/**
 * Nexora Media — Admin UI, Settings & AJAX Handlers
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NXM_Admin {

    private static ?NXM_Admin $instance = null;

    public static function get_instance(): NXM_Admin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',             [ $this, 'register_menu' ] );
        add_action( 'admin_init',             [ $this, 'register_settings' ] );
        // Brand menu icon — runs on every admin screen (the top-level menu is
        // always visible), so it can't live in the page-scoped enqueue_assets.
        add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_menu_icon' ] );

        // AJAX: Save a single option live (toggle switch)
        add_action( 'wp_ajax_nxm_save_option',   [ $this, 'ajax_save_option' ] );

        // AJAX: Queue all existing library images for retro-optimization
        add_action( 'wp_ajax_nxm_queue_all',      [ $this, 'ajax_queue_all' ] );

        // AJAX: Server capability diagnostic
        add_action( 'wp_ajax_nxm_diagnostic',     [ $this, 'ajax_diagnostic' ] );

        // AJAX: Live queue status polling
        add_action( 'wp_ajax_nxm_queue_status',   [ $this, 'ajax_queue_status' ] );
        add_action( 'wp_ajax_nxm_stop_queue',      [ $this, 'ajax_stop_queue' ] );

        // AJAX: Optimize one media item from the intelligence library.
        add_action( 'wp_ajax_nxm_optimize_attachment', [ $this, 'ajax_optimize_attachment' ] );
        add_action( 'wp_ajax_nxm_sync_attachment', [ $this, 'ajax_sync_attachment' ] );

        add_action( 'wp_ajax_nxm_purge_css_cache', [ $this, 'ajax_purge_css_cache' ] );
        add_action( 'wp_ajax_nxm_toggle_delivery_mode', [ $this, 'ajax_toggle_delivery_mode' ] );
        add_action( 'wp_ajax_nxm_complete_wizard', [ $this, 'ajax_complete_wizard' ] );

        add_filter( 'manage_media_columns', [ $this, 'media_columns' ] );
        add_action( 'manage_media_custom_column', [ $this, 'media_column_content' ], 10, 2 );
        add_filter( 'media_row_actions', [ $this, 'media_row_actions' ], 10, 2 );
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * MENU & ASSETS
     * ───────────────────────────────────────────────────────────────────── */

    public function register_menu(): void {
        // Icon painted by CSS (enqueue_menu_icon) so the Nexora brand mark
        // renders crisply at 20x20 in the admin menu — matching Nexora Pulse
        // and Nexora Engine.
        add_menu_page(
            __( 'Nexora Media', 'nexora-media' ),
            __( 'Nexora Media', 'nexora-media' ),
            'manage_options',
            'nxm-settings',
            [ $this, 'render_settings_page' ],
            'none',
            11
        );

        add_submenu_page(
            'nxm-settings',
            __( 'Dashboard', 'nexora-media' ),
            __( 'Dashboard', 'nexora-media' ),
            'manage_options',
            'nxm-settings',
            [ $this, 'render_settings_page' ]
        );

        add_submenu_page(
            'upload.php',
            __( 'Nexora Media', 'nexora-media' ),
            __( 'Nexora Media', 'nexora-media' ),
            'manage_options',
            'nxm-settings',
            [ $this, 'render_settings_page' ]
        );

        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    /**
     * Paint the Nexora brand mark as the top-level admin-menu icon.
     *
     * The menu was registered with icon 'none', so we set it here via a CSS
     * background-image (20x20), the same way Nexora Pulse and Nexora Engine do.
     * Keeps the brand consistent across the family and renders crisply on every
     * admin theme. Output goes through wp_add_inline_style so WordPress owns it
     * (no echoed <style> — wp.org guideline). Runs on every admin screen.
     */
    public function enqueue_menu_icon(): void {
        $icon = esc_url( NXM_URL . 'assets/img/nexora-icon.png' );
        $css  = '#adminmenu #toplevel_page_nxm-settings .wp-menu-image{'
              . 'background:url("' . $icon . '") center center no-repeat !important;'
              . 'background-size:20px 20px !important;}'
              . '#adminmenu #toplevel_page_nxm-settings .wp-menu-image img,'
              . '#adminmenu #toplevel_page_nxm-settings .wp-menu-image:before{display:none !important;}';
        wp_register_style( 'nxm-menu-icon', false, [], NXM_VERSION );
        wp_enqueue_style( 'nxm-menu-icon' );
        wp_add_inline_style( 'nxm-menu-icon', $css );
    }

    public function enqueue_assets( string $hook ): void {
        $page        = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        $is_nxm_page = 0 === strpos( $page, 'nxm' );

        if ( ! $is_nxm_page && 'upload.php' !== $hook ) {
            return;
        }

        // Keep the legacy stylesheet for Media Library column rendering (uses
        // a few nxm-media-status pills) — never on the SPA page.
        if ( ! $is_nxm_page ) {
            wp_enqueue_style( 'nxm-admin-css', NXM_URL . 'assets/css/admin.css', [], NXM_VERSION );
            return;
        }

        // ─── New React SPA bundle ─────────────────────────────────
        $js_file = NXM_DIR . 'assets/dist/nexora-media.js';
        $css_file = NXM_DIR . 'assets/dist/nexora-media.css';
        $version  = NXM_VERSION;
        if ( file_exists( $js_file ) ) {
            // Append mtime so each rebuild busts browser caches even when the
            // semantic plugin version is unchanged.
            $version .= '.' . filemtime( $js_file );
        }

        if ( file_exists( $css_file ) ) {
            wp_enqueue_style( 'nexora-media', NXM_URL . 'assets/dist/nexora-media.css', [], $version );
        }

        wp_enqueue_script( 'nexora-media', NXM_URL . 'assets/dist/nexora-media.js', [], $version, true );

        // Install ID — when missing or rotated, the React app wipes its
        // localStorage so wizard state survives uninstall correctly.
        if ( ! get_option( 'nxm_install_id' ) ) {
            update_option( 'nxm_install_id', wp_generate_uuid4(), false );
        }

        wp_localize_script( 'nexora-media', 'NexoraMedia', [
            'apiUrl'    => rest_url( 'nexora-media/v1/' ),
            'nonce'     => wp_create_nonce( 'wp_rest' ),
            'adminUrl'  => admin_url(),
            'siteUrl'   => get_site_url(),
            'pluginUrl' => NXM_URL,
            'version'   => NXM_VERSION,
            'installId' => (string) get_option( 'nxm_install_id', '' ),
            'onboardingComplete' => (bool) get_user_meta( get_current_user_id(), 'nxm_onboarding_complete', true ),
            'engineActive' => NXM_Init::is_nexora_engine_active(),
            'pulseActive'  => defined( 'NEXORA_PULSE_VERSION' ) || file_exists( WP_PLUGIN_DIR . '/nexora-pulse/nexora-pulse.php' ),
            'user'      => [
                'id'    => get_current_user_id(),
                'name'  => wp_get_current_user()->display_name,
                'email' => wp_get_current_user()->user_email,
            ],
        ] );
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * SETTINGS REGISTRATION
     * ───────────────────────────────────────────────────────────────────── */

    public function register_settings(): void {
        register_setting( 'nxm_settings_group', 'nxm_enable_queue',    [ 'type' => 'boolean', 'default' => true, 'sanitize_callback' => 'rest_sanitize_boolean' ] );
        register_setting( 'nxm_settings_group', 'nxm_auto_process_queue', [ 'type' => 'boolean', 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ] );
        register_setting( 'nxm_settings_group', 'nxm_enable_avif',     [ 'type' => 'boolean', 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ] );
        register_setting( 'nxm_settings_group', 'nxm_enable_webp',     [ 'type' => 'boolean', 'default' => true, 'sanitize_callback' => 'rest_sanitize_boolean' ] );
        register_setting( 'nxm_settings_group', 'nxm_enable_adaptive', [ 'type' => 'boolean', 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ] );
        register_setting( 'nxm_settings_group', 'nxm_enable_lazyload', [ 'type' => 'boolean', 'default' => true, 'sanitize_callback' => 'rest_sanitize_boolean' ] );
        register_setting( 'nxm_settings_group', 'nxm_strip_exif',      [ 'type' => 'boolean', 'default' => true, 'sanitize_callback' => 'rest_sanitize_boolean' ] );
        register_setting( 'nxm_settings_group', 'nxm_enable_css_cache',[ 'type' => 'boolean', 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ] );
        register_setting( 'nxm_settings_group', 'nxm_quality',         [ 'type' => 'integer', 'default' => 82, 'sanitize_callback' => [ $this, 'sanitize_quality' ] ] );
        register_setting( 'nxm_settings_group', 'nxm_max_width',       [ 'type' => 'integer', 'default' => 2560, 'sanitize_callback' => [ $this, 'sanitize_max_width' ] ] );
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * VIEW
     * ───────────────────────────────────────────────────────────────────── */

    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        // SPA mount point — React owns the layout, no .wrap so we get full
        // bleed against the WP admin chrome.
        echo '<div id="nexora-media-root"></div>';
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * AJAX: Save single option (live toggle)
     * ───────────────────────────────────────────────────────────────────── */

    public function ajax_save_option(): void {
        check_ajax_referer( 'nxm_async', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();

        $boolean_allowed = [ 'nxm_enable_queue', 'nxm_auto_process_queue', 'nxm_enable_avif', 'nxm_enable_webp', 'nxm_enable_adaptive', 'nxm_enable_lazyload', 'nxm_strip_exif', 'nxm_enable_css_cache' ];
        $numeric_allowed = [ 'nxm_quality', 'nxm_max_width' ];
        $allowed         = array_merge( $boolean_allowed, $numeric_allowed );
        $option          = sanitize_key( $_POST['option'] ?? '' );

        if ( ! in_array( $option, $allowed, true ) ) {
            wp_send_json_error( 'Invalid option.' );
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked above via check_ajax_referer.
        if ( in_array( $option, $numeric_allowed, true ) ) {
            $raw_value = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '0';
            $value     = 'nxm_quality' === $option ? $this->sanitize_quality( $raw_value ) : $this->sanitize_max_width( $raw_value );
        } else {
            $value = isset( $_POST['value'] ) ? (int) wp_unslash( $_POST['value'] ) : 0;
        }
        // phpcs:enable

        update_option( $option, $value );

        if ( 'nxm_auto_process_queue' === $option && ! $value ) {
            wp_clear_scheduled_hook( 'nxm_process_queue_event' );
        }

        if ( in_array( $option, [ 'nxm_enable_adaptive', 'nxm_enable_webp', 'nxm_enable_avif' ], true ) && class_exists( 'NXM_Engine_Bridge' ) ) {
            NXM_Engine_Bridge::get_instance()->notify_media_runtime_changed();
        }

        wp_send_json_success( [
            'option'  => $option,
            'value'   => $value,
            'message' => __( 'Control saved.', 'nexora-media' ),
        ] );
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * AJAX: Queue ALL existing library images for retro-optimization
     * ───────────────────────────────────────────────────────────────────── */

    public function ajax_queue_all(): void {
        check_ajax_referer( 'nxm_async', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();

        NXM_Queue::get_instance()->prune_completed_queue();
        NXM_Queue::get_instance()->resume_processing();

        $attachments = get_posts( [
            'post_type'      => 'attachment',
            'post_mime_type' => [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ],
            'post_status'    => 'inherit',
            'numberposts'    => -1,
            'fields'         => 'ids',
        ] );

        usort( $attachments, static function( $a, $b ): int {
            $a_file = get_attached_file( (int) $a );
            $b_file = get_attached_file( (int) $b );
            $a_size = ( $a_file && file_exists( $a_file ) ) ? (int) filesize( $a_file ) : PHP_INT_MAX;
            $b_size = ( $b_file && file_exists( $b_file ) ) ? (int) filesize( $b_file ) : PHP_INT_MAX;

            return $a_size <=> $b_size;
        } );

        $added = 0;

        foreach ( $attachments as $id ) {
            if ( NXM_Queue::get_instance()->enqueue_attachment( (int) $id, 'bulk' ) ) {
                $added++;
            }
        }

        wp_send_json_success( [
            'count'   => $added,
            'skipped' => max( 0, count( $attachments ) - $added ),
        ] );
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * AJAX: Server engine diagnostic
     * ───────────────────────────────────────────────────────────────────── */

    public function ajax_diagnostic(): void {
        check_ajax_referer( 'nxm_async', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();

        $imagick_ok = NXM_Engine_Imagick::is_available();
        $gd_ok      = NXM_Engine_GD::is_available();

        $engine = 'none';
        $webp   = false;
        $avif   = false;

        if ( $imagick_ok ) {
            $engine = 'Imagick';
            $webp   = NXM_Engine_Imagick::supports_format( 'webp' );
            $avif   = NXM_Engine_Imagick::supports_format( 'avif' );
        } elseif ( $gd_ok ) {
            $engine = 'GD';
            $webp   = NXM_Engine_GD::supports_format( 'webp' );
            $avif   = NXM_Engine_GD::supports_format( 'avif' );
        }

        $queue_status = NXM_Queue::get_instance()->queue_status();

        wp_send_json_success( [
            'engine'        => $engine,
            'webp'          => $webp,
            'avif'          => $avif,
            'memory'        => size_format( memory_get_usage( true ) ),
            'memory_limit'  => ini_get( 'memory_limit' ) ?: __( 'Unknown', 'nexora-media' ),
            'php_version'   => PHP_VERSION,
            'plugin_version'=> NXM_VERSION,
            'queue_pending' => (int) ( $queue_status['pending'] ?? 0 ),
            'queue_locked'  => (bool) ( $queue_status['locked'] ?? false ),
            'queue_paused'  => (bool) ( $queue_status['paused'] ?? false ),
        ] );
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * AJAX: Live queue status
     * ───────────────────────────────────────────────────────────────────── */

    public function ajax_queue_status(): void {
        check_ajax_referer( 'nxm_async', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();

        $status    = NXM_Queue::get_instance()->queue_status();
        $pending   = $status['pending'];
        $processed = $status['processed'];
        $total     = (int) ( $status['current_total'] ?? $pending );
        $library_total = $this->count_library_images();
        $ready_count   = $this->count_variants( 'webp' );
        $optimized_count = $this->count_optimized_images();

        wp_send_json_success( [
            'pending'    => $pending,
            'processed'  => $processed,
            'total'      => $total,
            'done'       => (int) ( $status['done'] ?? max( 0, $total - $pending ) ),
            'percent'    => (int) ( $status['percent'] ?? ( $total > 0 ? round( ( max( 0, $total - $pending ) / $total ) * 100 ) : 100 ) ),
            'queued'     => (int) ( $status['queued'] ?? 0 ),
            'webp_count' => $ready_count,
            'avif_count' => $this->count_variants( 'avif' ),
            'library_total' => $library_total,
            'ready_percent' => $library_total > 0 ? (int) round( ( $ready_count / $library_total ) * 100 ) : 0,
            'optimized_count' => $optimized_count,
            'optimized_percent' => $library_total > 0 ? (int) round( ( $optimized_count / $library_total ) * 100 ) : 0,
            'failed'        => $status['failed'],
            'locked'        => $status['locked'],
            'paused'        => (bool) ( $status['paused'] ?? false ),
            'running'       => (bool) ( $status['running'] ?? false ),
            'current_id'    => (int) ( $status['current_id'] ?? 0 ),
            'current_label' => (string) ( $status['current_label'] ?? '' ),
        ] );
    }

    public function ajax_stop_queue(): void {
        check_ajax_referer( 'nxm_async', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'nexora-media' ) ], 403 );
        }

        NXM_Queue::get_instance()->pause_processing( true );

        wp_send_json_success( [
            'pending' => 0,
            'paused'  => true,
            'message' => __( 'Optimization stopped. The queue was cleared and no background worker will resume until you start it again.', 'nexora-media' ),
        ] );
    }

    public function ajax_optimize_attachment(): void {
        check_ajax_referer( 'nxm_async', 'nonce' );
        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'nexora-media' ) ], 403 );
        }

        $attachment_id = absint( $_POST['attachment_id'] ?? 0 );
        if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid image attachment.', 'nexora-media' ) ], 400 );
        }

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 120 );
        }

        $before = $this->get_media_item_report( $attachment_id );

        if ( ! empty( $before['is_optimized'] ) ) {
            $message = __( 'Image already checked. Nexora is using the best available delivery for this file.', 'nexora-media' );
            if ( 'native' === (string) ( $before['sync_status'] ?? '' ) ) {
                $message = __( 'Image already checked. The original file is smaller than the generated variant, so Nexora keeps the original.', 'nexora-media' );
            } elseif ( ! empty( $before['frontend_output_active'] ) ) {
                $message = __( 'Image already checked. Public delivery is connected for this image.', 'nexora-media' );
            }

            wp_send_json_success( [
                'row'     => $before,
                'queued'  => false,
                'message' => $message,
            ] );
        }

        NXM_Queue::get_instance()->resume_processing();
        $result = NXM_Queue::get_instance()->optimize_attachment_now( $attachment_id, false );

        if ( is_wp_error( $result ) && 'nxm_worker_locked' !== $result->get_error_code() ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
        }

        wp_send_json_success( [
            'row'     => $this->get_media_item_report( $attachment_id ),
            'queued'  => is_wp_error( $result ),
            'message' => is_wp_error( $result ) ? $result->get_error_message() : $this->optimization_message( $before, $this->get_media_item_report( $attachment_id ) ),
        ] );
    }

    public function ajax_toggle_delivery_mode(): void {
        check_ajax_referer( 'nxm_async', 'nonce' );
        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'nexora-media' ) ], 403 );
        }

        $attachment_id = absint( $_POST['attachment_id'] ?? 0 );
        if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid image attachment.', 'nexora-media' ) ], 400 );
        }

        $disabled = (bool) get_post_meta( $attachment_id, '_nxm_delivery_disabled', true );
        update_post_meta( $attachment_id, '_nxm_delivery_disabled', $disabled ? 0 : 1 );
        if ( class_exists( 'NXM_CSS_Optimizer' ) ) {
            NXM_CSS_Optimizer::purge_cache();
        }
        do_action( 'nxm_media_delivery_mode_changed', $attachment_id, ! $disabled ? 'original' : 'optimized' );

        wp_send_json_success( [
            'row'     => $this->get_media_item_report( $attachment_id ),
            'message' => $disabled ? __( 'Optimized delivery restored for this image.', 'nexora-media' ) : __( 'This image now serves the original file.', 'nexora-media' ),
        ] );
    }

    public function ajax_sync_attachment(): void {
        check_ajax_referer( 'nxm_async', 'nonce' );
        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'nexora-media' ) ], 403 );
        }

        $attachment_id = absint( $_POST['attachment_id'] ?? 0 );
        if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid image attachment.', 'nexora-media' ) ], 400 );
        }

        $before = $this->get_media_item_report( $attachment_id );
        if ( empty( $before['formats'] ) ) {
            wp_send_json_error( [ 'message' => __( 'This image needs a useful WebP variant before frontend sync can be enabled.', 'nexora-media' ) ], 400 );
        }

        update_option( 'nxm_enable_webp', 1, false );
        update_post_meta( $attachment_id, '_nxm_delivery_disabled', 0 );
        update_post_meta( $attachment_id, '_nxm_frontend_synced_at', time() );

        if ( class_exists( 'NXM_Engine_Bridge' ) ) {
            NXM_Engine_Bridge::get_instance()->notify_media_runtime_changed();
        }

        $engine_pending = false;
        if ( class_exists( 'NCX_SSG' ) && method_exists( 'NCX_SSG', 'is_enabled' ) && NCX_SSG::is_enabled() ) {
            $ssg = NCX_SSG::get_instance();
            if ( method_exists( $ssg, 'schedule_global_invalidate' ) ) {
                $ssg->schedule_global_invalidate();
                $engine_pending = true;
            }
        }

        wp_send_json_success( [
            'row'     => $this->get_media_item_report( $attachment_id ),
            'message' => $engine_pending
                ? __( 'Frontend sync is ready. Nexora Engine has been notified so static pages can be refreshed.', 'nexora-media' )
                : __( 'Frontend sync is ready. Public visitors can receive optimized variants for this image.', 'nexora-media' ),
        ] );
    }

    public function ajax_complete_wizard(): void {
        check_ajax_referer( 'nxm_async', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'nexora-media' ) ], 403 );
        }

        $apply_recommended = ! empty( $_POST['apply_recommended'] );

        if ( $apply_recommended ) {
            $processor      = NXM_Image_Processor::get_instance();
            $webp_supported = $processor->supports( 'webp' );

            update_option( 'nxm_enable_webp', $webp_supported ? 1 : 0, false );
            update_option( 'nxm_enable_adaptive', $webp_supported ? 1 : 0, false );
            update_option( 'nxm_enable_lazyload', 1, false );
            update_option( 'nxm_strip_exif', 1, false );
            update_option( 'nxm_enable_queue', 1, false );
            update_option( 'nxm_auto_process_queue', 0, false );
            update_option( 'nxm_processing_paused', 1, false );
            update_option( 'nxm_enable_css_cache', 0, false );
            update_option( 'nxm_enable_dom_rewrite', 0, false );

            if ( class_exists( 'NXM_Engine_Bridge' ) ) {
                NXM_Engine_Bridge::get_instance()->notify_media_runtime_changed();
            }
        }

        update_option( 'nxm_wizard_complete', 1, false );
        wp_send_json_success( [
            'message' => $apply_recommended
                ? __( 'Recommended safe settings applied. Start optimization when you are ready.', 'nexora-media' )
                : __( 'Setup complete.', 'nexora-media' ),
        ] );
    }

    public function ajax_purge_css_cache(): void {
        check_ajax_referer( 'nxm_async', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'nexora-media' ) ], 403 );
        }

        $deleted = class_exists( 'NXM_CSS_Optimizer' ) ? NXM_CSS_Optimizer::purge_cache() : 0;

        wp_send_json_success( [
            'deleted' => $deleted,
            'message' => __( 'CSS cache marked for refresh. Existing files were preserved so current static mirrors keep loading safely.', 'nexora-media' ),
        ] );
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * HELPERS
     * ───────────────────────────────────────────────────────────────────── */

    private function count_variants( string $format ): int {
        global $wpdb;

        $format = strtolower( preg_replace( '/[^a-z0-9]/', '', $format ) );
        if ( ! in_array( $format, [ 'webp', 'avif' ], true ) ) {
            return 0;
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_nxm_variants' AND meta_value LIKE %s",
                '%' . $wpdb->esc_like( '"' . $format . '"' ) . '%'
            )
        );
    }

    private function count_library_images(): int {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Static query with no user input.
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit' AND post_mime_type IN ('image/jpeg','image/png','image/gif','image/webp')"
        );
    }

    private function count_optimized_images(): int {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Static query with no user input.
        return (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_type = 'attachment'
             AND p.post_status = 'inherit'
             AND p.post_mime_type IN ('image/jpeg','image/png','image/gif','image/webp')
             AND pm.meta_key = '_nxm_status'
             AND pm.meta_value = 'optimized'"
        );
    }

    public function get_media_inventory( int $limit = 60 ): array {
        $attachments = get_posts( [
            'post_type'      => 'attachment',
            'post_mime_type' => [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ],
            'post_status'    => 'inherit',
            'numberposts'    => max( 1, min( 500, $limit ) ),
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ] );

        return array_map( [ $this, 'get_media_item_report' ], $attachments );
    }

    public function get_media_summary(): array {
        $attachments = get_posts( [
            'post_type'      => 'attachment',
            'post_mime_type' => [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ],
            'post_status'    => 'inherit',
            'numberposts'    => -1,
            'fields'         => 'ids',
        ] );

        $original     = 0;
        $optimized    = 0;
        $optimized_no = 0;

        foreach ( $attachments as $attachment_id ) {
            $item = $this->get_media_item_report( (int) $attachment_id );
            $original  += $item['source_bytes'];
            $optimized += $item['best_bytes'] ?: $item['working_bytes'];
            if ( ! empty( $item['is_optimized'] ) ) {
                $optimized_no++;
            }
        }

        $saved = max( 0, $original - $optimized );

        return [
            'total'          => count( $attachments ),
            'optimized'      => $optimized_no,
            'bytes_in'       => $original,
            'best_bytes'     => $optimized,
            'saved'          => $saved,
            'saved_percent'  => $original > 0 ? round( ( $saved / $original ) * 100 ) : 0,
        ];
    }

    public function get_media_item_report( int $attachment_id ): array {
        $file     = get_attached_file( $attachment_id );
        $source_file = $this->source_original_file( $attachment_id, $file );
        $variants = get_post_meta( $attachment_id, '_nxm_variants', true );
        $variants = is_array( $variants ) ? $variants : [];
        $variants = $this->repair_variant_paths( $attachment_id, $variants, $file );

        $working_bytes = ( $file && file_exists( $file ) ) ? (int) filesize( $file ) : 0;
        $source_bytes  = ( $source_file && file_exists( $source_file ) ) ? (int) filesize( $source_file ) : $working_bytes;
        $webp_path  = (string) ( $variants['original']['webp'] ?? '' );
        $webp_bytes = ( $webp_path && file_exists( $webp_path ) ) ? (int) filesize( $webp_path ) : 0;
        $avif_bytes = 0;
        $best_bytes = $this->best_variant_bytes( $working_bytes, $webp_bytes, 0 );
        $saved      = $best_bytes > 0 ? max( 0, $source_bytes - $best_bytes ) : 0;
        $useful_webp = $this->variant_can_improve_delivery( $working_bytes, $webp_bytes );

        $formats       = array_values( array_filter( [
            $useful_webp ? 'WebP' : '',
        ] ) );
        $generated_formats = array_values( array_filter( [
            $webp_bytes > 0 ? 'WebP' : '',
        ] ) );
        $updated_at    = ! empty( $variants['updated_at'] ) ? (int) $variants['updated_at'] : 0;
        $status_meta   = (string) get_post_meta( $attachment_id, '_nxm_status', true );
        $mime_type     = (string) get_post_mime_type( $attachment_id );
        $is_processed  = 'optimized' === $status_meta || $updated_at > 0;
        $is_optimized  = ! empty( $formats ) || $is_processed || 'image/webp' === $mime_type;

        if ( $best_bytes <= 0 && $is_optimized ) {
            $best_bytes = $working_bytes;
            $saved      = 0;
        }

        if ( ! empty( $formats ) ) {
            $formats_label = __( 'Variant ready', 'nexora-media' );
        } elseif ( ! empty( $generated_formats ) && $is_optimized ) {
            $formats_label = __( 'Original retained', 'nexora-media' );
        } else {
            $formats_label = $is_optimized ? __( 'Original retained', 'nexora-media' ) : __( 'No variant yet', 'nexora-media' );
        }
        $delivery_disabled = (bool) get_post_meta( $attachment_id, '_nxm_delivery_disabled', true );
        $status        = $delivery_disabled ? 'original-delivery' : ( $is_optimized ? 'optimized' : 'needs-optimization' );
        $status_label  = $delivery_disabled ? __( 'Original delivery', 'nexora-media' ) : ( $is_optimized ? __( 'Fully optimized', 'nexora-media' ) : __( 'Needs optimization', 'nexora-media' ) );
        $adaptive_ready = (bool) get_option( 'nxm_enable_adaptive', false );
        $webp_ready     = (bool) get_option( 'nxm_enable_webp', true );
        $optimized_original_ready = $is_optimized && empty( $formats ) && ( ! empty( $generated_formats ) || $best_bytes > 0 );
        $frontend_output_active = $is_optimized && ! $delivery_disabled && $adaptive_ready && $webp_ready && ! empty( $formats );
        $frontend_synced = $frontend_output_active || ( $is_optimized && ! $delivery_disabled && $optimized_original_ready );

        if ( $frontend_output_active ) {
            $sync_status = 'active';
            $sync_label  = __( 'Connected', 'nexora-media' );
            $sync_note   = __( 'Public delivery active', 'nexora-media' );
        } elseif ( $optimized_original_ready && ! $delivery_disabled ) {
            $sync_status = 'native';
            $sync_label  = __( 'Original retained', 'nexora-media' );
            $sync_note   = __( 'Variant not smaller', 'nexora-media' );
        } elseif ( ! $adaptive_ready && ! empty( $formats ) && ! $delivery_disabled ) {
            $sync_status = 'safe-mode';
            $sync_label  = __( 'Delivery off', 'nexora-media' );
            $sync_note   = __( 'Enable delivery', 'nexora-media' );
        } elseif ( empty( $formats ) ) {
            $sync_status = 'blocked';
            $sync_label  = __( 'Not ready', 'nexora-media' );
            $sync_note   = __( 'Optimize image', 'nexora-media' );
        } elseif ( $delivery_disabled ) {
            $sync_status = 'not-synced';
            $sync_label  = __( 'Original selected', 'nexora-media' );
            $sync_note   = __( 'Original selected', 'nexora-media' );
        } elseif ( ! $webp_ready ) {
            $sync_status = 'not-synced';
            $sync_label  = __( 'Delivery off', 'nexora-media' );
            $sync_note   = __( 'Format disabled', 'nexora-media' );
        } else {
            $sync_status = 'not-synced';
            $sync_label  = __( 'Needs sync', 'nexora-media' );
            $sync_note   = __( 'Reconnect delivery', 'nexora-media' );
        }

        return [
            'id'            => $attachment_id,
            'title'         => get_the_title( $attachment_id ),
            'filename'      => $file ? wp_basename( $file ) : '',
            'thumb'         => wp_get_attachment_image_url( $attachment_id, 'thumbnail' ) ?: wp_get_attachment_url( $attachment_id ),
            'status'        => $status,
            'status_label'  => $status_label,
            'is_optimized'  => $is_optimized,
            'delivery_disabled' => $delivery_disabled,
            'delivery_label' => $delivery_disabled ? __( 'Use optimized', 'nexora-media' ) : __( 'Use original', 'nexora-media' ),
            'optimize_label' => $is_optimized ? __( 'Optimized', 'nexora-media' ) : __( 'Optimize', 'nexora-media' ),
            'optimize_enabled' => ! $is_optimized,
            'bytes_in'      => $source_bytes,
            'source_bytes'  => $source_bytes,
            'working_bytes' => $working_bytes,
            'working_bytes_label' => size_format( $working_bytes ),
            'source_bytes_label' => size_format( $source_bytes ),
            'webp_bytes'    => $webp_bytes,
            'avif_bytes'    => $avif_bytes,
            'best_bytes'    => $best_bytes,
            'best_bytes_label' => $best_bytes ? size_format( $best_bytes ) : __( 'Not ready', 'nexora-media' ),
            'saved'         => $saved,
            'saved_label'   => $saved ? size_format( $saved ) . ' (' . round( $source_bytes > 0 ? ( $saved / $source_bytes ) * 100 : 0 ) . '%)' : ( $is_optimized ? __( 'Already optimal', 'nexora-media' ) : '-' ),
            'saved_percent' => $source_bytes > 0 ? round( ( $saved / $source_bytes ) * 100 ) : 0,
            'formats'       => $formats,
            'formats_label' => $formats_label,
            'updated_at'    => $updated_at,
            'updated_label' => $updated_at ? sprintf( __( 'Optimized %s ago', 'nexora-media' ), human_time_diff( $updated_at ) ) : __( 'Not processed yet', 'nexora-media' ),
            'frontend_synced' => $frontend_synced,
            'frontend_output_active' => $frontend_output_active,
            'sync_status'   => $sync_status,
            'sync_label'    => $sync_label,
            'sync_note'     => $sync_note,
            'sync_enabled'  => ! $frontend_synced && $adaptive_ready && ! empty( $formats ),
            'sync_button_label' => $frontend_synced ? $sync_label : ( empty( $formats ) ? __( 'Not ready', 'nexora-media' ) : __( 'Sync', 'nexora-media' ) ),
        ];
    }

    private function optimization_message( array $before, array $after ): string {
        if ( $after['best_bytes'] <= 0 ) {
            return __( 'No optimized variant was generated. Check the diagnostic output for server format support.', 'nexora-media' );
        }

        if ( (int) $before['best_bytes'] === (int) $after['best_bytes'] && (int) $before['saved'] === (int) $after['saved'] ) {
            return __( 'Image re-optimized. It is already at the best available local delivery size.', 'nexora-media' );
        }

        return __( 'Image re-optimized and the delivery report was updated.', 'nexora-media' );
    }

    private function repair_variant_paths( int $attachment_id, array $variants, ?string $file ): array {
        if ( ! $file || ! file_exists( $file ) ) {
            return $variants;
        }

        $changed  = false;
        $repaired = $this->repair_variant_tree( $variants, dirname( $file ), $changed );

        if ( $changed ) {
            update_post_meta( $attachment_id, '_nxm_variants', wp_slash( $repaired ) );
        }

        return $repaired;
    }

    private function repair_variant_tree( array $value, string $dir, bool &$changed ): array {
        foreach ( $value as $key => $item ) {
            if ( is_array( $item ) ) {
                $value[ $key ] = $this->repair_variant_tree( $item, $dir, $changed );
                continue;
            }

            if ( is_string( $item ) && preg_match( '/\.(?:jpe?g|png|gif|webp|avif)$/i', $item ) ) {
                $fixed = $this->repair_variant_path( $item, $dir );
                if ( $fixed && $fixed !== $item ) {
                    $value[ $key ] = $fixed;
                    $changed = true;
                }
            }
        }

        return $value;
    }

    private function repair_variant_path( string $path, string $dir ): ?string {
        if ( file_exists( $path ) ) {
            return $path;
        }

        $normalized = wp_normalize_path( $path );
        foreach ( glob( trailingslashit( $dir ) . '*' ) ?: [] as $candidate ) {
            if ( substr( $normalized, -strlen( wp_basename( $candidate ) ) ) === wp_basename( $candidate ) ) {
                return $candidate;
            }
        }

        return null;
    }

    private function source_original_file( int $attachment_id, ?string $fallback ): ?string {
        $backup_sizes = wp_get_attachment_metadata( $attachment_id );
        $backup_sizes = is_array( $backup_sizes ) ? ( $backup_sizes['original_image'] ?? '' ) : '';

        if ( $backup_sizes && $fallback ) {
            $candidate = trailingslashit( dirname( $fallback ) ) . wp_basename( (string) $backup_sizes );
            if ( file_exists( $candidate ) ) {
                return $candidate;
            }
        }

        $backups = get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true );
        if ( is_array( $backups ) && ! empty( $backups['full-orig']['file'] ) && $fallback ) {
            $candidate = trailingslashit( dirname( $fallback ) ) . wp_basename( (string) $backups['full-orig']['file'] );
            if ( file_exists( $candidate ) ) {
                return $candidate;
            }
        }

        return $fallback;
    }

    private function best_variant_bytes( int $original, int $webp, int $avif ): int {
        $candidates = array_filter( [ $webp, $avif ] );
        if ( empty( $candidates ) ) {
            return 0;
        }

        $candidates[] = $original;
        return min( $candidates );
    }

    private function variant_can_improve_delivery( int $original, int $variant ): bool {
        if ( $variant <= 0 ) {
            return false;
        }

        return $original <= 0 || $variant <= $original;
    }

    public function sanitize_quality( $value ): int {
        return max( 40, min( 95, (int) $value ) );
    }

    public function sanitize_max_width( $value ): int {
        return max( 1024, min( 6000, (int) $value ) );
    }

    public function media_columns( array $columns ): array {
        $columns['nxm_media'] = __( 'Nexora', 'nexora-media' );
        return $columns;
    }

    public function media_column_content( string $column_name, int $post_id ): void {
        if ( 'nxm_media' !== $column_name || ! wp_attachment_is_image( $post_id ) ) {
            return;
        }

        $item = $this->get_media_item_report( $post_id );
        echo '<span class="nxm-media-status nxm-media-status--' . esc_attr( $item['status'] ) . '">' . esc_html( $item['status_label'] ) . '</span>';
        echo '<br><a href="' . esc_url( admin_url( 'admin.php?page=nxm-settings' ) ) . '">' . esc_html__( 'Open report', 'nexora-media' ) . '</a>';
    }

    public function media_row_actions( array $actions, WP_Post $post ): array {
        if ( 'attachment' === $post->post_type && wp_attachment_is_image( $post->ID ) ) {
            $actions['nxm_media'] = '<a href="' . esc_url( admin_url( 'admin.php?page=nxm-settings' ) ) . '">' . esc_html__( 'Nexora Media report', 'nexora-media' ) . '</a>';
        }

        return $actions;
    }
}
