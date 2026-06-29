<?php
/**
 * Nexora Media — REST Controller
 *
 * Modern REST surface for the React admin SPA. Wraps existing engine,
 * queue, and image-processor logic. Old wp_ajax_* handlers stay in
 * NXM_Admin for backwards compatibility but the new UI talks REST only.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NXM_REST {

	private const NS = 'nexora-media/v1';

	private static ?NXM_REST $instance = null;

	public static function get_instance(): NXM_REST {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		$caps = static function () {
			return current_user_can( 'manage_options' );
		};
		$upload_caps = static function () {
			return current_user_can( 'upload_files' );
		};

		register_rest_route( self::NS, '/summary', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_summary' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/library', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_library' ],
			'permission_callback' => $caps,
			'args'                => [
				'limit'  => [ 'type' => 'integer', 'default' => 60, 'minimum' => 1, 'maximum' => 500 ],
				'filter' => [ 'type' => 'string',  'default' => 'all', 'enum' => [ 'all', 'optimized', 'needs', 'original' ] ],
			],
		] );

		register_rest_route( self::NS, '/queue/status', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_queue_status' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/queue/start', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'queue_start' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/queue/stop', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'queue_stop' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/queue/errors', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_queue_errors' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/queue/recover', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'queue_recover' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/settings', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_settings' ],
				'permission_callback' => $caps,
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'save_settings' ],
				'permission_callback' => $caps,
			],
		] );

		register_rest_route( self::NS, '/diagnostic', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_diagnostic' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/attachment/(?P<id>\d+)/optimize', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'optimize_attachment' ],
			'permission_callback' => $upload_caps,
		] );

		register_rest_route( self::NS, '/attachment/(?P<id>\d+)/sync', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'sync_attachment' ],
			'permission_callback' => $upload_caps,
		] );

		register_rest_route( self::NS, '/attachment/(?P<id>\d+)/delivery', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'toggle_delivery' ],
			'permission_callback' => $upload_caps,
		] );

		register_rest_route( self::NS, '/cache/purge', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'purge_cache' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/wizard/complete', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'complete_wizard' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/onboarding/complete', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'complete_onboarding' ],
			'permission_callback' => $caps,
		] );
	}

	// ──────────────────────────────────────────────────────────────
	// Endpoints
	// ──────────────────────────────────────────────────────────────

	public function get_summary( WP_REST_Request $request ): WP_REST_Response {
		$admin     = NXM_Admin::get_instance();
		$processor = NXM_Image_Processor::get_instance();
		$summary   = $admin->get_media_summary();
		$status    = NXM_Queue::get_instance()->queue_status();
		$engine    = NXM_Init::is_nexora_engine_active();

		$current_total = (int) ( $status['current_total'] ?? $status['pending'] );
		$current_done  = (int) ( $status['done'] ?? max( 0, $current_total - $status['pending'] ) );

		$webp_enabled     = (bool) get_option( 'nxm_enable_webp', true );
		$adaptive_enabled = (bool) get_option( 'nxm_enable_adaptive', false );
		$webp_supported   = $processor->supports( 'webp' );
		$delivery_ready   = $adaptive_enabled && $webp_enabled && $webp_supported;

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'library'         => $summary,
				'queue'           => [
					'pending'     => (int) $status['pending'],
					'processed'   => (int) $status['processed'],
					'current_total' => $current_total,
					'current_done'  => $current_done,
					'percent'     => (int) ( $status['percent'] ?? 0 ),
					'failed'      => (int) ( $status['failed'] ?? 0 ),
					'paused'      => (bool) ( $status['paused'] ?? false ),
					'running'     => (bool) ( $status['running'] ?? false ),
					'current_label' => (string) ( $status['current_label'] ?? '' ),
				],
				'engine'          => [
					'name'      => $processor->engine_name(),
					'webp_supported' => $webp_supported,
					'memory'    => ini_get( 'memory_limit' ) ?: 'Unknown',
					'upload'    => size_format( wp_max_upload_size() ),
				],
				'delivery_ready'  => $delivery_ready,
				'webp_enabled'    => $webp_enabled,
				'adaptive_enabled' => $adaptive_enabled,
				'engine_bridge'   => [
					'connected' => $engine,
					'changed_at' => (int) get_option( 'nxm_engine_media_changed_at', 0 ),
				],
				'wizard_complete' => (bool) get_option( 'nxm_wizard_complete', 0 ),
				'queue_health'    => class_exists( 'NXM_Queue_Health' )
					? NXM_Queue_Health::get_instance()->report()
					: null,
			],
		] );
	}

	public function get_library( WP_REST_Request $request ): WP_REST_Response {
		$limit  = (int) $request->get_param( 'limit' );
		$filter = sanitize_text_field( (string) $request->get_param( 'filter' ) );
		$admin  = NXM_Admin::get_instance();
		$items  = $admin->get_media_inventory( $limit );

		if ( 'all' !== $filter ) {
			$items = array_values( array_filter( $items, static function ( $item ) use ( $filter ) {
				return match ( $filter ) {
					'optimized' => 'optimized' === $item['status'],
					'needs'     => 'needs-optimization' === $item['status'],
					'original'  => 'original-delivery' === $item['status'],
					default     => true,
				};
			} ) );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'items' => $items,
				'total' => count( $items ),
			],
		] );
	}

	public function get_queue_status( WP_REST_Request $request ): WP_REST_Response {
		$status = NXM_Queue::get_instance()->queue_status();
		return rest_ensure_response( [ 'success' => true, 'data' => $status ] );
	}

	public function queue_start( WP_REST_Request $request ): WP_REST_Response {
		NXM_Queue::get_instance()->prune_completed_queue();
		NXM_Queue::get_instance()->resume_processing();

		$attachments = get_posts( [
			'post_type'      => 'attachment',
			'post_mime_type' => [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ],
			'post_status'    => 'inherit',
			'numberposts'    => -1,
			'fields'         => 'ids',
		] );

		usort( $attachments, static function ( $a, $b ): int {
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

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'queued'  => $added,
				'skipped' => max( 0, count( $attachments ) - $added ),
			],
		] );
	}

	public function queue_stop( WP_REST_Request $request ): WP_REST_Response {
		NXM_Queue::get_instance()->pause_processing( true );
		return rest_ensure_response( [
			'success' => true,
			'data'    => [ 'paused' => true ],
		] );
	}

	public function get_queue_errors( WP_REST_Request $request ): WP_REST_Response {
		$errors = class_exists( 'NXM_Queue_Health' )
			? NXM_Queue_Health::get_instance()->get_errors()
			: [];
		return rest_ensure_response( [
			'success' => true,
			'data'    => [ 'errors' => $errors ],
		] );
	}

	public function queue_recover( WP_REST_Request $request ): WP_REST_Response {
		// Clear the stale lock, drop per-image cooldowns, and reset the failure log
		// so the next run starts clean. Doesn't touch settings or processed counters.
		if ( class_exists( 'NXM_Queue_Health' ) ) {
			NXM_Queue_Health::get_instance()->reset_failures();
		}
		NXM_Queue::get_instance()->resume_processing();
		return rest_ensure_response( [
			'success' => true,
			'data'    => [ 'recovered' => true ],
		] );
	}

	public function get_settings( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'nxm_enable_webp'         => (bool) get_option( 'nxm_enable_webp', true ),
				'nxm_enable_avif'         => (bool) get_option( 'nxm_enable_avif', false ),
				'nxm_enable_adaptive'     => (bool) get_option( 'nxm_enable_adaptive', false ),
				'nxm_enable_lazyload'     => (bool) get_option( 'nxm_enable_lazyload', true ),
				'nxm_strip_exif'          => (bool) get_option( 'nxm_strip_exif', true ),
				'nxm_enable_queue'        => (bool) get_option( 'nxm_enable_queue', true ),
				'nxm_auto_process_queue'  => (bool) get_option( 'nxm_auto_process_queue', false ),
				'nxm_enable_css_cache'    => (bool) get_option( 'nxm_enable_css_cache', false ),
				'nxm_enable_dom_rewrite'  => (bool) get_option( 'nxm_enable_dom_rewrite', false ),
				'nxm_quality'             => (int) get_option( 'nxm_quality', 82 ),
				'nxm_max_width'           => (int) get_option( 'nxm_max_width', 2560 ),
				'nxm_responsive_widths'   => (string) get_option( 'nxm_responsive_widths', '320,640,960,1600' ),
			],
		] );
	}

	public function save_settings( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = [];
		}

		$boolean_allowed = [
			'nxm_enable_webp', 'nxm_enable_avif', 'nxm_enable_adaptive', 'nxm_enable_lazyload',
			'nxm_strip_exif', 'nxm_enable_queue', 'nxm_auto_process_queue',
			'nxm_enable_css_cache', 'nxm_enable_dom_rewrite',
		];
		$numeric_allowed = [ 'nxm_quality', 'nxm_max_width' ];
		$string_allowed  = [ 'nxm_responsive_widths' ];

		foreach ( $boolean_allowed as $key ) {
			if ( array_key_exists( $key, $body ) ) {
				update_option( $key, $body[ $key ] ? 1 : 0, false );
			}
		}
		foreach ( $numeric_allowed as $key ) {
			if ( array_key_exists( $key, $body ) ) {
				$value = (int) $body[ $key ];
				if ( 'nxm_quality' === $key ) {
					$value = max( 1, min( 100, $value ) );
				} elseif ( 'nxm_max_width' === $key ) {
					$value = max( 320, min( 6000, $value ) );
				}
				update_option( $key, $value, false );
			}
		}
		foreach ( $string_allowed as $key ) {
			if ( array_key_exists( $key, $body ) ) {
				update_option( $key, sanitize_text_field( (string) $body[ $key ] ), false );
			}
		}

		if ( array_key_exists( 'nxm_auto_process_queue', $body ) && ! $body['nxm_auto_process_queue'] ) {
			wp_clear_scheduled_hook( 'nxm_process_queue_event' );
		}

		if ( class_exists( 'NXM_Engine_Bridge' ) ) {
			NXM_Engine_Bridge::get_instance()->notify_media_runtime_changed();
		}

		return $this->get_settings( $request );
	}

	public function get_diagnostic( WP_REST_Request $request ): WP_REST_Response {
		$imagick_ok = NXM_Engine_Imagick::is_available();
		$gd_ok      = NXM_Engine_GD::is_available();

		$engine = 'None';
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

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'engine'         => $engine,
				'webp'           => $webp,
				'avif'           => $avif,
				'memory'         => size_format( memory_get_usage( true ) ),
				'memory_limit'   => ini_get( 'memory_limit' ) ?: 'Unknown',
				'upload_limit'   => size_format( wp_max_upload_size() ),
				'php_version'    => PHP_VERSION,
				'wp_version'     => get_bloginfo( 'version' ),
				'plugin_version' => NXM_VERSION,
				'imagick'        => $imagick_ok,
				'gd'             => $gd_ok,
				'queue'          => [
					'pending' => (int) ( $queue_status['pending'] ?? 0 ),
					'locked'  => (bool) ( $queue_status['locked'] ?? false ),
					'paused'  => (bool) ( $queue_status['paused'] ?? false ),
				],
			],
		] );
	}

	public function optimize_attachment( WP_REST_Request $request ): WP_REST_Response {
		$id = (int) $request['id'];
		if ( ! $id || ! wp_attachment_is_image( $id ) ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => __( 'Invalid image attachment.', 'nexora-media' ) ], 400 );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			// Extend the limit for a single on-demand image encode; image
			// optimization can legitimately exceed the default cap on large files.
			@set_time_limit( 120 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		}

		NXM_Queue::get_instance()->resume_processing();
		$result = NXM_Queue::get_instance()->optimize_attachment_now( $id, false );
		$admin  = NXM_Admin::get_instance();
		$row    = $admin->get_media_item_report( $id );

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'row'    => $row,
				'queued' => is_wp_error( $result ),
				'message' => is_wp_error( $result ) ? $result->get_error_message() : __( 'Image processed.', 'nexora-media' ),
			],
		] );
	}

	public function sync_attachment( WP_REST_Request $request ): WP_REST_Response {
		$id = (int) $request['id'];
		if ( ! $id || ! wp_attachment_is_image( $id ) ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => __( 'Invalid image attachment.', 'nexora-media' ) ], 400 );
		}
		$admin = NXM_Admin::get_instance();
		$before = $admin->get_media_item_report( $id );
		if ( empty( $before['formats'] ) ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => __( 'Optimize the image first.', 'nexora-media' ) ], 400 );
		}

		update_option( 'nxm_enable_webp', 1, false );
		update_post_meta( $id, '_nxm_delivery_disabled', 0 );
		update_post_meta( $id, '_nxm_frontend_synced_at', time() );

		if ( class_exists( 'NXM_Engine_Bridge' ) ) {
			NXM_Engine_Bridge::get_instance()->notify_media_runtime_changed();
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => [ 'row' => $admin->get_media_item_report( $id ) ],
		] );
	}

	public function toggle_delivery( WP_REST_Request $request ): WP_REST_Response {
		$id = (int) $request['id'];
		if ( ! $id || ! wp_attachment_is_image( $id ) ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => __( 'Invalid image attachment.', 'nexora-media' ) ], 400 );
		}
		$disabled = (bool) get_post_meta( $id, '_nxm_delivery_disabled', true );
		update_post_meta( $id, '_nxm_delivery_disabled', $disabled ? 0 : 1 );
		if ( class_exists( 'NXM_CSS_Optimizer' ) ) {
			NXM_CSS_Optimizer::purge_cache();
		}
		do_action( 'nxm_media_delivery_mode_changed', $id, ! $disabled ? 'original' : 'optimized' );

		$admin = NXM_Admin::get_instance();
		return rest_ensure_response( [
			'success' => true,
			'data'    => [ 'row' => $admin->get_media_item_report( $id ) ],
		] );
	}

	public function purge_cache( WP_REST_Request $request ): WP_REST_Response {
		$deleted = class_exists( 'NXM_CSS_Optimizer' ) ? NXM_CSS_Optimizer::purge_cache() : 0;
		return rest_ensure_response( [
			'success' => true,
			'data'    => [ 'deleted' => $deleted ],
		] );
	}

	public function complete_wizard( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params() ?: [];
		$apply = ! empty( $body['apply_recommended'] );

		if ( $apply ) {
			$processor = NXM_Image_Processor::get_instance();
			$webp_supported = $processor->supports( 'webp' );

			update_option( 'nxm_enable_webp',      $webp_supported ? 1 : 0, false );
			update_option( 'nxm_enable_adaptive',  $webp_supported ? 1 : 0, false );
			update_option( 'nxm_enable_lazyload',  1, false );
			update_option( 'nxm_strip_exif',       1, false );
			update_option( 'nxm_enable_queue',     1, false );
			update_option( 'nxm_auto_process_queue', 0, false );
			update_option( 'nxm_processing_paused', 1, false );
		}

		update_option( 'nxm_wizard_complete', 1, false );

		if ( class_exists( 'NXM_Engine_Bridge' ) ) {
			NXM_Engine_Bridge::get_instance()->notify_media_runtime_changed();
		}

		return rest_ensure_response( [ 'success' => true, 'data' => [ 'completed' => true ] ] );
	}

	public function complete_onboarding( WP_REST_Request $request ): WP_REST_Response {
		update_user_meta( get_current_user_id(), 'nxm_onboarding_complete', 1 );
		return rest_ensure_response( [ 'success' => true, 'data' => [ 'completed' => true ] ] );
	}
}
