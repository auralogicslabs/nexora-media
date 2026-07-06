<?php
/**
 * Nexora Media — Background Queue
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NXMEDIA_Queue {

    private static ?NXMEDIA_Queue $instance = null;

    public static function get_instance(): NXMEDIA_Queue {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Intercept standard metadata generation
        add_filter( 'wp_generate_attachment_metadata', [ $this, 'intercept_upload' ], 10, 2 );
        add_action( 'delete_attachment', [ $this, 'cleanup_attachment' ] );
        
        // Background worker endpoint and cron worker. No public nopriv worker:
        // unauthenticated optimization endpoints are unnecessary attack surface.
        add_action( 'wp_ajax_nxmedia_async_process', [ $this, 'ajax_process_queue' ] );
        add_action( 'nxmedia_process_queue_event', [ $this, 'process_queue_event' ] );

        $this->clear_passive_schedule_when_manual();
    }

    /**
     * Intercepts image uploads and records them for later optimization.
     * Heavy variant generation only starts automatically when the site owner enables it.
     */
    public function intercept_upload( $metadata, $attachment_id ) {
        if ( ! wp_attachment_is_image( $attachment_id ) ) {
            return $metadata;
        }

        if ( ! get_option( 'nxmedia_enable_queue', true ) ) {
            return $metadata;
        }

        $this->enqueue_attachment( (int) $attachment_id, 'upload' );

        return $metadata;
    }

    public function enqueue_attachment( int $attachment_id, string $reason = 'manual' ): bool {
        if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
            return false;
        }

        if ( in_array( sanitize_key( $reason ), [ 'manual', 'bulk' ], true ) && ! $this->attachment_needs_processing( $attachment_id ) ) {
            return false;
        }

        $queue = get_option( 'nxmedia_process_queue', [] );
        if ( ! is_array( $queue ) ) {
            $queue = [];
        }

        $reason      = sanitize_key( $reason );
        $is_explicit = in_array( $reason, [ 'manual', 'bulk' ], true );

        if ( ! isset( $queue[ $attachment_id ] ) ) {
            $queue[ $attachment_id ] = [
                'id'       => $attachment_id,
                'reason'   => $reason,
                'queued_at' => time(),
                'attempts' => 0,
            ];
            update_option( 'nxmedia_process_queue', $queue, false );
            update_option( 'nxmedia_current_queue_total', count( $queue ), false );
            $this->increment_stat( 'nxmedia_total_queued' );
        } elseif ( $is_explicit && ! in_array( (string) ( $queue[ $attachment_id ]['reason'] ?? '' ), [ 'manual', 'bulk' ], true ) ) {
            $queue[ $attachment_id ]['reason'] = $reason;
            update_option( 'nxmedia_process_queue', $queue, false );
        }

        if ( get_option( 'nxmedia_auto_process_queue', false ) && ! $this->is_paused() ) {
            $this->schedule_worker();
        }

        return true;
    }

    private function schedule_worker( int $delay = 10 ): void {
        if ( ! wp_next_scheduled( 'nxmedia_process_queue_event' ) ) {
            wp_schedule_single_event( time() + max( 1, $delay ), 'nxmedia_process_queue_event' );
        }
    }

    public function ajax_process_queue(): void {
        check_ajax_referer( 'nxmedia_async', 'nonce' );
        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'nexora-media' ) ], 403 );
        }

        if ( $this->is_paused() ) {
            wp_send_json_success( [
                'paused'    => true,
                'processed' => 0,
                'pending'   => 0,
                'message'   => __( 'Optimization is paused. No background processing is running.', 'nexora-media' ),
            ] );
        }

        $result = $this->process_queue_batch();
        wp_send_json_success( $result );
    }

    public function process_queue_event(): void {
        $queue = get_option( 'nxmedia_process_queue', [] );
        $queue = is_array( $queue ) ? $queue : [];

        if ( $this->is_paused() || ! get_option( 'nxmedia_auto_process_queue', false ) ) {
            wp_clear_scheduled_hook( 'nxmedia_process_queue_event' );
            return;
        }

        $this->process_queue_batch();
    }

    public function optimize_attachment_now( int $attachment_id, bool $force = false ) {
        if ( $this->is_paused() ) {
            return new WP_Error( 'nxmedia_processing_paused', __( 'Optimization is paused. Resume processing before optimizing images.', 'nexora-media' ) );
        }

        if ( $this->queue_is_locked() ) {
            $this->enqueue_attachment( $attachment_id, 'manual' );
            return new WP_Error( 'nxmedia_worker_locked', __( 'Optimization worker is already running. Image was queued.', 'nexora-media' ) );
        }

        set_transient( 'nxmedia_queue_lock', microtime( true ), 5 * MINUTE_IN_SECONDS );
        $this->set_current_processing( $attachment_id );

        try {
            $metadata = wp_get_attachment_metadata( $attachment_id );
            $result   = $this->process_attachment( $attachment_id, is_array( $metadata ) ? $metadata : [], $force );
        } catch ( Throwable $e ) {
            $result = new WP_Error( 'nxmedia_processing_exception', $e->getMessage() );
        } finally {
            $this->clear_current_processing();
            delete_transient( 'nxmedia_queue_lock' );
        }

        if ( is_wp_error( $result ) ) {
            $this->log_error( $attachment_id, $result->get_error_message() );
            $this->increment_stat( 'nxmedia_total_failed' );
            return $result;
        }

        $queue = get_option( 'nxmedia_process_queue', [] );
        if ( is_array( $queue ) && isset( $queue[ $attachment_id ] ) ) {
            unset( $queue[ $attachment_id ] );
            empty( $queue ) ? delete_option( 'nxmedia_process_queue' ) : update_option( 'nxmedia_process_queue', $queue, false );
            update_option( 'nxmedia_current_queue_total', count( $queue ), false );
        }

        $this->increment_stat( 'nxmedia_total_processed' );

        if ( class_exists( 'NXMEDIA_Engine_Bridge' ) ) {
            NXMEDIA_Engine_Bridge::get_instance()->notify_processing_complete();
        }

        return true;
    }

    public function process_queue_batch( int $limit = 1 ): array {
        if ( $this->is_paused() ) {
            wp_clear_scheduled_hook( 'nxmedia_process_queue_event' );
            delete_transient( 'nxmedia_queue_lock' );
            return [
                'paused'    => true,
                'processed' => 0,
                'pending'   => 0,
                'message'   => __( 'Optimization is paused. No images were processed.', 'nexora-media' ),
            ];
        }

        // Prevent overlapping workers
        if ( $this->queue_is_locked() ) {
            return [ 'locked' => true ];
        }
        set_transient( 'nxmedia_queue_lock', microtime( true ), 5 * MINUTE_IN_SECONDS );

        try {
            $queue = get_option( 'nxmedia_process_queue', [] );
            if ( ! is_array( $queue ) ) {
                $queue = [];
            }
            
            if ( empty( $queue ) ) {
                update_option( 'nxmedia_current_queue_total', 0, false );
                return [ 'processed' => 0, 'pending' => 0 ];
            }

            $processor = NXMEDIA_Image_Processor::get_instance();
            if ( ! $processor->has_engine() ) {
                return [
                    'processed' => 0,
                    'pending'   => count( $queue ),
                    'message'   => __( 'No compatible image engine available.', 'nexora-media' ),
                ];
            }

            $max_execution_time = (int) ini_get( 'max_execution_time' );
            if ( $max_execution_time <= 0 ) {
                $max_execution_time = 30;
            }
            $start_time = microtime( true );

            $processed = 0;
            $errors    = 0;
            $skipped   = 0;

                foreach ( $queue as $attachment_id => $item ) {
                // Check timeout (leave 5 seconds buffer)
                if ( $processed >= $limit || microtime( true ) - $start_time > max( 5, min( 20, $max_execution_time - 5 ) ) ) {
                    break;
                }

                $attachment_id = (int) $attachment_id;
                $this->set_current_processing( $attachment_id );

                if ( ! $this->attachment_needs_processing( $attachment_id ) ) {
                    unset( $queue[ $attachment_id ] );
                    $skipped++;
                    continue;
                }

                // Honor per-image cooldown so one bad image doesn't block the queue.
                if ( class_exists( 'NXMEDIA_Queue_Health' )
                    && NXMEDIA_Queue_Health::get_instance()->should_skip_attachment( $attachment_id ) ) {
                    unset( $queue[ $attachment_id ] );
                    $skipped++;
                    continue;
                }

                $metadata      = wp_get_attachment_metadata( $attachment_id );

                try {
                    $result = $this->process_attachment( $attachment_id, is_array( $metadata ) ? $metadata : [] );
                } catch ( Throwable $e ) {
                    $result = new WP_Error( 'nxmedia_processing_exception', $e->getMessage() );
                }

                if ( is_wp_error( $result ) ) {
                    $queue[ $attachment_id ]['attempts'] = (int) ( $queue[ $attachment_id ]['attempts'] ?? 0 ) + 1;
                    $queue[ $attachment_id ]['last_error'] = $result->get_error_message();
                    $errors++;

                    if ( $queue[ $attachment_id ]['attempts'] >= 3 ) {
                        $this->log_error( $attachment_id, $result->get_error_message() );
                        unset( $queue[ $attachment_id ] );
                        $this->increment_stat( 'nxmedia_total_failed' );
                    }
                } else {
                    unset( $queue[ $attachment_id ] );
                    $processed++;
                    $this->increment_stat( 'nxmedia_total_processed' );
                    if ( class_exists( 'NXMEDIA_Queue_Health' ) ) {
                        NXMEDIA_Queue_Health::get_instance()->record_success( $attachment_id );
                    }
                }
            }

            // Re-save queue
            if ( empty( $queue ) ) {
                delete_option( 'nxmedia_process_queue' );
                update_option( 'nxmedia_current_queue_total', 0, false );
            } else {
                update_option( 'nxmedia_process_queue', $queue, false );
                update_option( 'nxmedia_current_queue_total', count( $queue ) + $processed + $skipped, false );
                if ( get_option( 'nxmedia_auto_process_queue', false ) ) {
                    $this->schedule_worker( 20 );
                }
            }

            // If Nexora Engine is active, trigger SSG update or purge cache.
            if ( $processed > 0 && class_exists( 'NXMEDIA_Engine_Bridge' ) ) {
                NXMEDIA_Engine_Bridge::get_instance()->notify_processing_complete();
            }

            return [
                'processed' => $processed,
                'errors'    => $errors,
                'skipped'   => $skipped,
                'pending'   => count( $queue ),
            ];
        } finally {
            $this->clear_current_processing();
            delete_transient( 'nxmedia_queue_lock' );
        }
    }

    private function process_attachment( int $attachment_id, array $metadata, bool $force = false ) {
        $file_path = get_attached_file( $attachment_id );
        if ( ! $file_path || ! file_exists( $file_path ) ) {
            return new WP_Error( 'nxmedia_missing_file', __( 'Original file missing.', 'nexora-media' ) );
        }

        $processor = NXMEDIA_Image_Processor::get_instance();
        if ( ! $processor->is_supported_file( $file_path ) ) {
            return new WP_Error( 'nxmedia_unsupported_file', __( 'Unsupported image type.', 'nexora-media' ) );
        }

        if ( $force ) {
            $this->cleanup_attachment( $attachment_id );
            delete_post_meta( $attachment_id, '_nxmedia_variants' );
            delete_post_meta( $attachment_id, '_nxmedia_status' );
        }

        // 1. Generate AVIF / WebP for original
        $quality      = (int) get_option( 'nxmedia_quality', 82 );
        $quality      = max( 40, min( 95, $quality ) );
        $avif_enabled = get_option( 'nxmedia_enable_avif', false ) && NXMEDIA_Feature_Gate::can_use( 'avif_generation' );
        $webp_enabled = get_option( 'nxmedia_enable_webp', true );

        $variants = [
            'original'   => [],
            'responsive' => [],
            'bytes_in'   => filesize( $file_path ),
            'bytes_out'  => 0,
            'updated_at' => time(),
        ];

        if ( $avif_enabled && $processor->supports( 'avif' ) ) {
            $avif_path = $processor->convert( $file_path, 'avif', $quality );
            if ( $avif_path ) {
                $variants['original']['avif'] = $avif_path;
            }
        }

        if ( $webp_enabled && $processor->supports( 'webp' ) ) {
            $webp_path = $processor->convert( $file_path, 'webp', $quality );
            if ( $webp_path ) {
                $variants['original']['webp'] = $webp_path;
            }
        }

        if ( get_option( 'nxmedia_enable_responsive_variants', false ) ) {
            $responsive = $processor->create_sizes( $file_path, NXMEDIA_Image_Processor::responsive_widths() );
            foreach ( $responsive as $width => $size_path ) {
                $variants['responsive'][ $width ] = [ 'source' => $size_path ];
                if ( $avif_enabled && $processor->supports( 'avif' ) ) {
                    $avif_path = $processor->convert( $size_path, 'avif', $quality );
                    if ( $avif_path ) {
                        $variants['responsive'][ $width ]['avif'] = $avif_path;
                    }
                }
                if ( $webp_enabled && $processor->supports( 'webp' ) ) {
                    $webp_path = $processor->convert( $size_path, 'webp', $quality );
                    if ( $webp_path ) {
                        $variants['responsive'][ $width ]['webp'] = $webp_path;
                    }
                }
            }
        }

        // 2. Process all sizes defined by WP
        if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            $base_dir = dirname( $file_path );
            foreach ( $metadata['sizes'] as $size => $size_data ) {
                $size_path = $base_dir . DIRECTORY_SEPARATOR . $size_data['file'];
                if ( file_exists( $size_path ) ) {
                    if ( $avif_enabled && $processor->supports( 'avif' ) ) {
                        $avif_path = $processor->convert( $size_path, 'avif', $quality );
                        if ( $avif_path ) {
                            $variants['wordpress_sizes'][ $size ]['avif'] = $avif_path;
                        }
                    }
                    if ( $webp_enabled && $processor->supports( 'webp' ) ) {
                        $webp_path = $processor->convert( $size_path, 'webp', $quality );
                        if ( $webp_path ) {
                            $variants['wordpress_sizes'][ $size ]['webp'] = $webp_path;
                        }
                    }
                }
            }
        }

        $variants['bytes_out'] = $this->calculate_variant_bytes( $variants );

        // Save metadata flag
        update_post_meta( $attachment_id, '_nxmedia_variants', wp_slash( $variants ) );
        update_post_meta( $attachment_id, '_nxmedia_status', 'optimized' );
        update_option( 'nxmedia_css_cache_invalidated_at', time(), false );

        return true;
    }

    private function attachment_needs_processing( int $attachment_id ): bool {
        if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
            return false;
        }

        $file_path = get_attached_file( $attachment_id );
        if ( ! $file_path || ! file_exists( $file_path ) ) {
            return false;
        }

        if ( 'image/webp' === (string) get_post_mime_type( $attachment_id ) ) {
            return false;
        }

        if ( 'optimized' !== (string) get_post_meta( $attachment_id, '_nxmedia_status', true ) ) {
            return true;
        }

        $variants = get_post_meta( $attachment_id, '_nxmedia_variants', true );
        if ( ! is_array( $variants ) ) {
            return true;
        }

        if ( ! empty( $variants['original']['webp'] ) && is_string( $variants['original']['webp'] ) && file_exists( $variants['original']['webp'] ) ) {
            return false;
        }

        if ( ! empty( $variants['updated_at'] ) ) {
            return false;
        }

        return true;
    }

    public function queue_status(): array {
        $queue = get_option( 'nxmedia_process_queue', [] );
        if ( ! is_array( $queue ) ) {
            $queue = [];
        }
        $queue = $this->prune_completed_queue( $queue );

        $pending       = count( $queue );
        $current_total = (int) get_option( 'nxmedia_current_queue_total', $pending );
        $current_total = $pending > 0 ? max( $pending, $current_total ) : 0;
        $done          = $current_total > 0 ? max( 0, $current_total - $pending ) : 0;
        $percent       = $current_total > 0 ? min( 100, (int) round( ( $done / $current_total ) * 100 ) ) : 100;
        $current       = $this->get_current_processing();

        return [
            'pending'   => $pending,
            'current_total' => $current_total,
            'done'      => $done,
            'percent'   => $percent,
            'processed' => (int) get_option( 'nxmedia_total_processed', 0 ),
            'queued'    => (int) get_option( 'nxmedia_total_queued', 0 ),
            'failed'    => (int) get_option( 'nxmedia_total_failed', 0 ),
            'locked'    => $this->queue_is_locked(),
            'paused'    => $this->is_paused(),
            'running'   => $this->queue_is_locked() || ( $pending > 0 && ! $this->is_paused() ),
            'current_id'    => (int) ( $current['id'] ?? 0 ),
            'current_label' => (string) ( $current['label'] ?? '' ),
        ];
    }

    public function prune_completed_queue( ?array $queue = null ): array {
        $queue = is_array( $queue ) ? $queue : get_option( 'nxmedia_process_queue', [] );
        $queue = is_array( $queue ) ? $queue : [];

        if ( empty( $queue ) ) {
            update_option( 'nxmedia_current_queue_total', 0, false );
            return [];
        }

        $changed = false;
        foreach ( array_keys( $queue ) as $attachment_id ) {
            if ( ! $this->attachment_needs_processing( (int) $attachment_id ) ) {
                unset( $queue[ $attachment_id ] );
                $changed = true;
            }
        }

        if ( $changed ) {
            if ( empty( $queue ) ) {
                delete_option( 'nxmedia_process_queue' );
                update_option( 'nxmedia_current_queue_total', 0, false );
                wp_clear_scheduled_hook( 'nxmedia_process_queue_event' );
                delete_transient( 'nxmedia_queue_lock' );
            } else {
                update_option( 'nxmedia_process_queue', $queue, false );
                update_option( 'nxmedia_current_queue_total', count( $queue ), false );
            }
        }

        return $queue;
    }

    public function pause_processing( bool $clear_queue = true ): void {
        update_option( 'nxmedia_processing_paused', 1, false );
        update_option( 'nxmedia_auto_process_queue', 0, false );
        wp_clear_scheduled_hook( 'nxmedia_process_queue_event' );
        delete_transient( 'nxmedia_queue_lock' );
        $this->clear_current_processing();

        if ( $clear_queue ) {
            delete_option( 'nxmedia_process_queue' );
            update_option( 'nxmedia_current_queue_total', 0, false );
        }
    }

    public function resume_processing(): void {
        update_option( 'nxmedia_processing_paused', 0, false );
        delete_transient( 'nxmedia_queue_lock' );
    }

    public function cleanup_attachment( int $attachment_id ): void {
        $variants = get_post_meta( $attachment_id, '_nxmedia_variants', true );
        if ( ! is_array( $variants ) ) {
            return;
        }

        $paths = $this->collect_variant_paths( $variants );

        foreach ( array_unique( $paths ) as $path ) {
            if ( is_string( $path ) && file_exists( $path ) && strpos( wp_normalize_path( $path ), wp_normalize_path( wp_upload_dir()['basedir'] ) ) === 0 ) {
                wp_delete_file( $path );
            }
        }
    }

    private function calculate_variant_bytes( array $variants ): int {
        $bytes = 0;
        $paths = $this->collect_variant_paths( $variants );
        foreach ( array_unique( $paths ) as $path ) {
            if ( is_string( $path ) && file_exists( $path ) ) {
                $bytes += filesize( $path );
            }
        }
        return $bytes;
    }

    private function collect_variant_paths( array $data ): array {
        $paths = [];
        array_walk_recursive( $data, static function( $value ) use ( &$paths ) {
            if ( is_string( $value ) && preg_match( '/\.(jpe?g|png|gif|webp|avif)$/i', $value ) ) {
                $paths[] = $value;
            }
        } );

        return $paths;
    }

    private function queue_is_locked(): bool {
        $lock = get_transient( 'nxmedia_queue_lock' );
        if ( ! $lock ) {
            return false;
        }

        if ( is_numeric( $lock ) && microtime( true ) - (float) $lock > 120 ) {
            delete_transient( 'nxmedia_queue_lock' );
            return false;
        }

        return true;
    }

    private function clear_passive_schedule_when_manual(): void {
        if ( ! $this->is_paused() && get_option( 'nxmedia_auto_process_queue', false ) ) {
            return;
        }

        wp_clear_scheduled_hook( 'nxmedia_process_queue_event' );
    }

    private function log_error( int $attachment_id, string $message ): void {
        $errors = get_option( 'nxmedia_queue_errors', [] );
        if ( ! is_array( $errors ) ) {
            $errors = [];
        }
        array_unshift( $errors, [
            'attachment_id' => $attachment_id,
            'message'       => $message,
            'time'          => time(),
        ] );
        update_option( 'nxmedia_queue_errors', array_slice( $errors, 0, 20 ), false );

        // Forward to the queue-health observer so the UI gets a structured signal.
        // Derive a stable code from the message so similar failures group together.
        if ( class_exists( 'NXMEDIA_Queue_Health' ) ) {
            $code = $this->derive_error_code( $message );
            $file = get_attached_file( $attachment_id );
            NXMEDIA_Queue_Health::get_instance()->record_error(
                $code,
                $message,
                $attachment_id,
                $file ? (string) $file : ''
            );
        }
    }

    /**
     * Map a free-text engine failure message to a structured short code so the
     * health observer can group recurring errors meaningfully.
     */
    private function derive_error_code( string $message ): string {
        $lower = strtolower( $message );
        if ( false !== strpos( $lower, 'webp' ) )                    return 'webp_encode_failed';
        if ( false !== strpos( $lower, 'avif' ) )                    return 'avif_encode_failed';
        if ( false !== strpos( $lower, 'allowed memory' )
          || false !== strpos( $lower, 'memory exhausted' ) )        return 'memory_exhausted';
        if ( false !== strpos( $lower, 'permission' )
          || false !== strpos( $lower, 'not writable' ) )            return 'permission_denied';
        if ( false !== strpos( $lower, 'no such file' )
          || false !== strpos( $lower, 'file missing' ) )            return 'file_missing';
        if ( false !== strpos( $lower, 'unsupported' ) )             return 'unsupported_format';
        return 'engine_error';
    }

    private function set_current_processing( int $attachment_id ): void {
        if ( ! $attachment_id ) {
            return;
        }

        $file = get_attached_file( $attachment_id );
        set_transient(
            'nxmedia_queue_current',
            [
                'id'    => $attachment_id,
                'label' => $file ? wp_basename( $file ) : get_the_title( $attachment_id ),
            ],
            5 * MINUTE_IN_SECONDS
        );
    }

    private function clear_current_processing(): void {
        delete_transient( 'nxmedia_queue_current' );
    }

    private function get_current_processing(): array {
        $current = get_transient( 'nxmedia_queue_current' );
        return is_array( $current ) ? $current : [];
    }

    private function is_paused(): bool {
        return (bool) get_option( 'nxmedia_processing_paused', false );
    }

    private function increment_stat( string $key ): void {
        update_option( $key, (int) get_option( $key, 0 ) + 1, false );
    }
}
