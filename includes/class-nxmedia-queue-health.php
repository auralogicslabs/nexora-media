<?php
/**
 * Nexora Media — Queue Health
 *
 * Observability + recovery for the background queue.
 *
 * Reports an overall status, recent errors, stale-lock detection, cron health, and
 * per-image skip tracking — so the UI can surface honest feedback when something
 * goes wrong and recover gracefully without user intervention.
 *
 * Storage:
 *   nxmedia_recent_errors    — option, array of last 10 errors (newest first)
 *   nxmedia_total_failed     — option, lifetime failure counter (already maintained by NXMEDIA_Queue)
 *   nxmedia_last_run_at      — option, microtime of last successful image processed
 *   nxmedia_queue_lock       — transient, owned by NXMEDIA_Queue (we only read it)
 *   _nxmedia_skip_until      — post meta on attachment, unix ts; queue skips until reached
 *   _nxmedia_failure_count   — post meta on attachment, int; resets when image succeeds
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NXMEDIA_Queue_Health {

	private static ?NXMEDIA_Queue_Health $instance = null;

	/** Lock older than this is considered stale (no worker progress). */
	private const STALE_LOCK_SECONDS = 5 * MINUTE_IN_SECONDS;

	/** Maximum errors retained in the persistent log. */
	private const MAX_LOG_ENTRIES = 10;

	/** After this many consecutive failures, an attachment gets a cooldown window. */
	private const FAILURE_COOLDOWN_THRESHOLD = 3;

	/** Cooldown window (seconds) once an attachment hits the failure threshold. */
	private const FAILURE_COOLDOWN_WINDOW = 24 * HOUR_IN_SECONDS;

	public static function get_instance(): NXMEDIA_Queue_Health {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// No hooks today — modules call us directly. Kept as a singleton for state
		// continuity within a single request.
	}

	// ──────────────────────────────────────────────────────────────
	// Public API
	// ──────────────────────────────────────────────────────────────

	/**
	 * Record an error. Called from NXMEDIA_Queue when an attachment fails or the worker
	 * encounters a fatal condition. Safe to call frequently — older entries are pruned.
	 *
	 * @param string $code            Short identifier (e.g. 'webp_encode_failed').
	 * @param string $message         Human-readable error message.
	 * @param int    $attachment_id   Optional attachment id involved.
	 * @param string $file            Optional file path for context.
	 */
	public function record_error( string $code, string $message, int $attachment_id = 0, string $file = '' ): void {
		$entries = $this->get_errors();
		array_unshift( $entries, [
			'code'          => sanitize_key( $code ),
			'message'       => sanitize_text_field( $message ),
			'attachment_id' => $attachment_id,
			'file'          => sanitize_text_field( $file ),
			'time'          => time(),
		] );
		$entries = array_slice( $entries, 0, self::MAX_LOG_ENTRIES );
		update_option( 'nxmedia_recent_errors', $entries, false );

		if ( $attachment_id > 0 ) {
			$count = (int) get_post_meta( $attachment_id, '_nxmedia_failure_count', true );
			$count++;
			update_post_meta( $attachment_id, '_nxmedia_failure_count', $count );

			if ( $count >= self::FAILURE_COOLDOWN_THRESHOLD ) {
				// Pause this image so it doesn't block the queue forever. The user
				// can see it in Diagnostic and decide what to do.
				update_post_meta( $attachment_id, '_nxmedia_skip_until', time() + self::FAILURE_COOLDOWN_WINDOW );
			}
		}
	}

	/** Called when an attachment succeeds — clear cooldown and failure history. */
	public function record_success( int $attachment_id ): void {
		if ( $attachment_id > 0 ) {
			delete_post_meta( $attachment_id, '_nxmedia_failure_count' );
			delete_post_meta( $attachment_id, '_nxmedia_skip_until' );
		}
		update_option( 'nxmedia_last_run_at', microtime( true ), false );
	}

	/** Should the queue skip this attachment (cooldown active)? */
	public function should_skip_attachment( int $attachment_id ): bool {
		$skip_until = (int) get_post_meta( $attachment_id, '_nxmedia_skip_until', true );
		return $skip_until > 0 && $skip_until > time();
	}

	/** Clear the failure log + per-image cooldowns. Used by "Recover" UI. */
	public function reset_failures(): void {
		delete_option( 'nxmedia_recent_errors' );
		delete_transient( 'nxmedia_queue_lock' );

		// Clear per-image cooldown so the queue can retry everything next run.
		// We do this in one direct query because there could be many rows.
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->postmeta, [ 'meta_key' => '_nxmedia_skip_until' ] );
		$wpdb->delete( $wpdb->postmeta, [ 'meta_key' => '_nxmedia_failure_count' ] );
		// phpcs:enable
	}

	/** Get the persistent error log (newest first). */
	public function get_errors(): array {
		$entries = get_option( 'nxmedia_recent_errors', [] );
		return is_array( $entries ) ? $entries : [];
	}

	// ──────────────────────────────────────────────────────────────
	// Health report — consumed by the React UI
	// ──────────────────────────────────────────────────────────────

	/**
	 * Compute the overall queue health snapshot. Surfaced via /summary REST so
	 * the React app can render alerts and recommended actions.
	 *
	 * @return array{
	 *   status: 'ok'|'warning'|'stuck'|'degraded',
	 *   recent_failures: int,
	 *   stale_lock: bool,
	 *   stale_lock_age: int,
	 *   cron_healthy: bool,
	 *   cron_next_run: int|null,
	 *   last_error: array|null,
	 *   recommendations: array<int, array{code:string,severity:string,message:string,fix:string}>,
	 * }
	 */
	public function report(): array {
		$errors          = $this->get_errors();
		$recent_failures = $this->count_recent_failures( $errors );
		$stale_info      = $this->detect_stale_lock();
		$cron            = $this->cron_health();

		$recommendations = [];

		// Stale lock → recovery action.
		if ( $stale_info['stale'] ) {
			$recommendations[] = [
				'code'     => 'stale_lock',
				'severity' => 'high',
				'message'  => sprintf(
					/* translators: %d age of stale lock in seconds */
					__( 'The optimization worker stopped responding %d seconds ago. The queue is paused until you recover it.', 'nexora-media' ),
					$stale_info['age']
				),
				'fix'      => __( 'Click "Recover stuck queue" in Diagnostic to clear the lock and restart processing.', 'nexora-media' ),
			];
		}

		// Recent failures → look into root cause.
		if ( $recent_failures >= 3 ) {
			$top_code = $this->most_common_error_code( $errors );
			$recommendations[] = [
				'code'     => 'recent_failures',
				'severity' => 'medium',
				'message'  => sprintf(
					/* translators: %1$d number of failures, %2$s most common error code */
					__( '%1$d recent optimization failures detected. Most common cause: %2$s.', 'nexora-media' ),
					$recent_failures,
					$this->humanize_error_code( $top_code )
				),
				'fix'      => __( 'Open Diagnostic to view the full error log and confirm your server can encode WebP.', 'nexora-media' ),
			];
		}

		// Cron disabled but auto-process is on → user enabled auto mode but nothing fires.
		if ( ! $cron['healthy'] ) {
			$recommendations[] = [
				'code'     => 'cron_unhealthy',
				'severity' => 'medium',
				'message'  => __( 'WordPress cron is not firing the optimization worker as expected.', 'nexora-media' ),
				'fix'      => __( 'Switch to manual processing in Settings, or ask your host to confirm WP-Cron is running.', 'nexora-media' ),
			];
		}

		$status = 'ok';
		if ( $stale_info['stale'] ) {
			$status = 'stuck';
		} elseif ( $recent_failures >= 3 ) {
			$status = 'degraded';
		} elseif ( ! $cron['healthy'] || $recent_failures > 0 ) {
			$status = 'warning';
		}

		return [
			'status'          => $status,
			'recent_failures' => $recent_failures,
			'stale_lock'      => $stale_info['stale'],
			'stale_lock_age'  => $stale_info['age'],
			'cron_healthy'    => $cron['healthy'],
			'cron_next_run'   => $cron['next_run'],
			'last_error'      => $errors[0] ?? null,
			'recommendations' => $recommendations,
		];
	}

	// ──────────────────────────────────────────────────────────────
	// Helpers
	// ──────────────────────────────────────────────────────────────

	private function detect_stale_lock(): array {
		$lock = get_transient( 'nxmedia_queue_lock' );
		if ( ! $lock ) {
			return [ 'stale' => false, 'age' => 0 ];
		}
		$age = (int) ( microtime( true ) - (float) $lock );
		return [
			'stale' => $age >= self::STALE_LOCK_SECONDS,
			'age'   => max( 0, $age ),
		];
	}

	private function cron_health(): array {
		$auto = (bool) get_option( 'nxmedia_auto_process_queue', false );
		if ( ! $auto ) {
			// Manual mode — cron isn't expected to run. Treat as healthy.
			return [ 'healthy' => true, 'next_run' => null ];
		}
		$next = wp_next_scheduled( 'nxmedia_process_queue_event' );
		if ( ! $next ) {
			return [ 'healthy' => false, 'next_run' => null ];
		}
		// If next run is more than 12h away when auto is on, something is wrong.
		if ( $next - time() > 12 * HOUR_IN_SECONDS ) {
			return [ 'healthy' => false, 'next_run' => (int) $next ];
		}
		return [ 'healthy' => true, 'next_run' => (int) $next ];
	}

	private function count_recent_failures( array $errors ): int {
		$cutoff = time() - ( 6 * HOUR_IN_SECONDS );
		$count  = 0;
		foreach ( $errors as $e ) {
			if ( (int) ( $e['time'] ?? 0 ) >= $cutoff ) {
				$count++;
			}
		}
		return $count;
	}

	private function most_common_error_code( array $errors ): string {
		if ( empty( $errors ) ) {
			return 'unknown';
		}
		$counts = [];
		foreach ( $errors as $e ) {
			$code = (string) ( $e['code'] ?? 'unknown' );
			$counts[ $code ] = ( $counts[ $code ] ?? 0 ) + 1;
		}
		arsort( $counts );
		return (string) array_key_first( $counts );
	}

	private function humanize_error_code( string $code ): string {
		$known = [
			'webp_encode_failed' => __( 'WebP encoder failed', 'nexora-media' ),
			'avif_encode_failed' => __( 'AVIF encoder failed', 'nexora-media' ),
			'no_engine'          => __( 'No image library available', 'nexora-media' ),
			'file_missing'       => __( 'Source file missing', 'nexora-media' ),
			'permission_denied'  => __( 'Permission denied writing variants', 'nexora-media' ),
			'memory_exhausted'   => __( 'Out of memory', 'nexora-media' ),
			'unsupported_format' => __( 'Unsupported source format', 'nexora-media' ),
		];
		return $known[ $code ] ?? sprintf(
			/* translators: %s raw error code */
			__( 'unknown (%s)', 'nexora-media' ),
			$code
		);
	}
}
