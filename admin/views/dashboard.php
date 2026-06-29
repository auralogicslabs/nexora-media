<?php
/**
 * Nexora Media admin dashboard.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$queue_enabled        = (bool) get_option( 'nxm_enable_queue', true );
$auto_process_enabled = (bool) get_option( 'nxm_auto_process_queue', false );
$processing_paused    = (bool) get_option( 'nxm_processing_paused', false );
$webp_enabled         = (bool) get_option( 'nxm_enable_webp', true );
$adaptive_enabled     = (bool) get_option( 'nxm_enable_adaptive', false );
$lazy_enabled         = (bool) get_option( 'nxm_enable_lazyload', true );
$strip_exif           = (bool) get_option( 'nxm_strip_exif', true );
$quality              = (int) get_option( 'nxm_quality', 82 );
$max_width            = (int) get_option( 'nxm_max_width', 2560 );
$processor            = NXM_Image_Processor::get_instance();
$queue_status         = NXM_Queue::get_instance()->queue_status();
$engine_active        = class_exists( 'NXM_Init' ) && NXM_Init::is_nexora_engine_active();
$webp_supported       = $processor->supports( 'webp' );
$admin                = NXM_Admin::get_instance();
$media_items          = $admin->get_media_inventory( 30 );
$media_summary        = $admin->get_media_summary();
$wizard_complete      = (bool) get_option( 'nxm_wizard_complete', false );

$image_query = new WP_Query( [
    'post_type'      => 'attachment',
    'post_mime_type' => [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ],
    'post_status'    => 'inherit',
    'posts_per_page' => 1,
    'fields'         => 'ids',
] );
$total_image_count = (int) $image_query->found_posts;
wp_reset_postdata();

$current_total      = (int) ( $queue_status['current_total'] ?? $queue_status['pending'] );
$current_done       = (int) ( $queue_status['done'] ?? max( 0, $current_total - $queue_status['pending'] ) );
$queue_pct          = (int) ( $queue_status['percent'] ?? ( $current_total > 0 ? max( 0, min( 100, (int) round( ( $current_done / $current_total ) * 100 ) ) ) : 100 ) );
$optimized_pct      = $media_summary['total'] > 0 ? max( 0, min( 100, (int) round( ( $media_summary['optimized'] / $media_summary['total'] ) * 100 ) ) ) : 0;
$unoptimized_count  = max( 0, (int) $media_summary['total'] - (int) $media_summary['optimized'] );
$delivery_ready     = $adaptive_enabled && $webp_enabled && $webp_supported;
$frontend_label     = $delivery_ready ? __( 'Native WebP delivery active', 'nexora-media' ) : __( 'Safe original delivery active', 'nexora-media' );
$frontend_note      = $delivery_ready
    ? __( 'Eligible WordPress image URLs are replaced with generated WebP variants for logged-out visitors. Verify output in an incognito or logged-out browser.', 'nexora-media' )
    : __( 'Enable Native WebP Delivery and WebP, then optimize images. Logged-in editors and builders keep original output for safety.', 'nexora-media' );
$memory_limit       = ini_get( 'memory_limit' ) ?: __( 'Unknown', 'nexora-media' );
$upload_limit       = size_format( wp_max_upload_size() );
$processing_mode    = $auto_process_enabled ? __( 'Automatic queue', 'nexora-media' ) : __( 'Manual safe queue', 'nexora-media' );
$server_cards       = [
    [
        'icon'  => 'admin-tools',
        'label' => __( 'Image Engine', 'nexora-media' ),
        'value' => $processor->engine_name(),
        'state' => 'ready',
    ],
    [
        'icon'  => 'format-image',
        'label' => __( 'WebP Support', 'nexora-media' ),
        'value' => $webp_supported ? __( 'Available', 'nexora-media' ) : __( 'Unavailable', 'nexora-media' ),
        'state' => $webp_supported ? 'ready' : 'blocked',
    ],
    [
        'icon'  => 'update',
        'label' => __( 'Processing Mode', 'nexora-media' ),
        'value' => $processing_mode,
        'state' => $auto_process_enabled ? 'warning' : 'ready',
    ],
    [
        'icon'  => 'database',
        'label' => __( 'PHP Memory', 'nexora-media' ),
        'value' => $memory_limit,
        'state' => 'ready',
    ],
    [
        'icon'  => 'upload',
        'label' => __( 'Upload Limit', 'nexora-media' ),
        'value' => $upload_limit,
        'state' => 'ready',
    ],
    [
        'icon'  => 'cloud',
        'label' => __( 'Engine Bridge', 'nexora-media' ),
        'value' => $engine_active ? __( 'Connected', 'nexora-media' ) : __( 'Standalone', 'nexora-media' ),
        'state' => $engine_active ? 'ready' : 'neutral',
    ],
];
?>

<div class="nxm-admin ncx-admin-wrapper">
    <div class="nxm-shell nxm-shell--app">
        <aside class="nxm-app-rail" aria-label="<?php esc_attr_e( 'Nexora Media sections', 'nexora-media' ); ?>">
            <div class="nxm-rail-brand">
                <span class="nxm-rail-logo">NM</span>
                <div>
                    <strong><?php esc_html_e( 'Nexora Media', 'nexora-media' ); ?></strong>
                    <span><?php esc_html_e( 'Delivery Intelligence', 'nexora-media' ); ?></span>
                </div>
            </div>
            <nav class="nxm-rail-nav">
                <a class="active" href="#nxm-media-dashboard"><span class="dashicons dashicons-dashboard"></span><?php esc_html_e( 'Dashboard', 'nexora-media' ); ?></a>
                <a href="#nxm-build-control"><span class="dashicons dashicons-update"></span><?php esc_html_e( 'Build Control', 'nexora-media' ); ?></a>
                <a href="#nxm-media-report"><span class="dashicons dashicons-format-gallery"></span><?php esc_html_e( 'Media Report', 'nexora-media' ); ?></a>
                <a href="#nxm-delivery-controls"><span class="dashicons dashicons-admin-generic"></span><?php esc_html_e( 'Controls', 'nexora-media' ); ?></a>
            </nav>
            <div class="nxm-rail-card">
                <span><?php esc_html_e( 'Server', 'nexora-media' ); ?></span>
                <strong><?php echo esc_html( $processor->engine_name() ); ?></strong>
                <small><?php echo esc_html( $webp_supported ? __( 'WebP ready', 'nexora-media' ) : __( 'WebP unavailable', 'nexora-media' ) ); ?></small>
            </div>
        </aside>
        <main class="nxm-workspace">
            <div class="nxm-app-topbar" id="nxm-media-dashboard">
                <div>
                    <span class="nxm-topbar-eyebrow"><?php esc_html_e( 'Media command center', 'nexora-media' ); ?></span>
                    <strong><?php esc_html_e( 'Safe image optimization for WordPress delivery.', 'nexora-media' ); ?></strong>
                </div>
                <div class="nxm-topbar-pills">
                    <span><?php echo esc_html( $processing_mode ); ?></span>
                    <span><?php echo esc_html( $delivery_ready ? __( 'Public WebP active', 'nexora-media' ) : __( 'Safe delivery mode', 'nexora-media' ) ); ?></span>
                    <span><?php echo esc_html( 'v' . NXM_VERSION ); ?></span>
                </div>
            </div>
            <div class="nxm-hero">
                <div>
                    <span class="nxm-kicker"><?php esc_html_e( 'Nexora Media', 'nexora-media' ); ?> / <?php esc_html_e( 'WP.org Ready', 'nexora-media' ); ?></span>
                    <h1><?php esc_html_e( 'Image Optimization Engine for WordPress Delivery', 'nexora-media' ); ?></h1>
                    <p><?php esc_html_e( 'Compress heavy uploads, generate WebP variants, protect builders, and verify what the public frontend is actually serving.', 'nexora-media' ); ?></p>
                </div>
                <div class="nxm-hero-mosaic" aria-label="<?php esc_attr_e( 'Media delivery summary', 'nexora-media' ); ?>">
                    <div>
                        <span><?php esc_html_e( 'Library', 'nexora-media' ); ?></span>
                        <strong><?php echo esc_html( (string) $media_summary['total'] ); ?></strong>
                    </div>
                    <div>
                        <span><?php esc_html_e( 'Ready', 'nexora-media' ); ?></span>
                        <strong><?php echo esc_html( (string) $media_summary['optimized'] ); ?></strong>
                    </div>
                    <div class="nxm-hero-mosaic-wide">
                        <span class="nxm-status-dot <?php echo esc_attr( $delivery_ready ? 'is-ready' : 'is-paused' ); ?>"></span>
                        <strong><?php echo esc_html( $frontend_label ); ?></strong>
                    </div>
                </div>
            </div>

            <form method="post" action="options.php" class="nxm-control-form">
                <?php settings_fields( 'nxm_settings_group' ); ?>

                <?php if ( ! $wizard_complete ) : ?>
                <section class="nxm-wizard-card nxm-wizard-card--first-run" id="nxm-media-wizard">
                    <div class="nxm-wizard-head">
                        <div>
                            <span class="nxm-kicker"><?php esc_html_e( 'Quick Setup', 'nexora-media' ); ?></span>
                            <h2><?php esc_html_e( 'Prepare the first image build.', 'nexora-media' ); ?></h2>
                            <p><?php esc_html_e( 'Nexora checks the server, applies safe delivery settings, and waits for your confirmation before processing the media library.', 'nexora-media' ); ?></p>
                        </div>
                        <div class="nxm-wizard-actions">
                            <button type="button" class="button button-primary" id="nxm-start-first-build"><?php esc_html_e( 'Start Safe Optimization', 'nexora-media' ); ?></button>
                            <button type="button" class="button" id="nxm-finish-wizard"><?php esc_html_e( 'Apply Settings Only', 'nexora-media' ); ?></button>
                        </div>
                    </div>
                    <div class="nxm-wizard-summary" aria-label="<?php esc_attr_e( 'Setup summary', 'nexora-media' ); ?>">
                        <div class="nxm-wizard-metric"><span class="dashicons dashicons-format-gallery"></span><strong><?php echo esc_html( (string) $total_image_count ); ?></strong><small><?php esc_html_e( 'Images found', 'nexora-media' ); ?></small></div>
                        <div class="nxm-wizard-metric"><span class="dashicons dashicons-admin-tools"></span><strong><?php echo esc_html( $processor->engine_name() ); ?></strong><small><?php esc_html_e( 'Image engine', 'nexora-media' ); ?></small></div>
                        <div class="nxm-wizard-metric <?php echo esc_attr( $webp_supported ? 'is-ready' : 'is-blocked' ); ?>"><span class="dashicons dashicons-yes-alt"></span><strong><?php echo esc_html( $webp_supported ? __( 'Ready', 'nexora-media' ) : __( 'Missing', 'nexora-media' ) ); ?></strong><small><?php esc_html_e( 'WebP support', 'nexora-media' ); ?></small></div>
                        <div class="nxm-wizard-metric"><span class="dashicons dashicons-database"></span><strong><?php echo esc_html( $memory_limit ); ?></strong><small><?php esc_html_e( 'PHP memory', 'nexora-media' ); ?></small></div>
                        <div class="nxm-wizard-metric"><span class="dashicons dashicons-upload"></span><strong><?php echo esc_html( $upload_limit ); ?></strong><small><?php esc_html_e( 'Upload limit', 'nexora-media' ); ?></small></div>
                        <div class="nxm-wizard-metric is-ready"><span class="dashicons dashicons-shield"></span><strong><?php esc_html_e( 'Safe', 'nexora-media' ); ?></strong><small><?php esc_html_e( 'Editor bypass', 'nexora-media' ); ?></small></div>
                    </div>
                    <div class="nxm-wizard-steps">
                        <div class="nxm-wizard-step is-done"><span class="nxm-step-icon dashicons dashicons-search"></span><div><strong><?php esc_html_e( 'Detect server capability', 'nexora-media' ); ?></strong><span><?php esc_html_e( 'Nexora chooses Imagick or GD and confirms WebP availability before any build.', 'nexora-media' ); ?></span></div></div>
                        <div class="nxm-wizard-step is-done"><span class="nxm-step-icon dashicons dashicons-shield"></span><div><strong><?php esc_html_e( 'Protect editing workflow', 'nexora-media' ); ?></strong><span><?php esc_html_e( 'Logged-in editors, Elementor, Divi, and builders keep original live output.', 'nexora-media' ); ?></span></div></div>
                        <div class="nxm-wizard-step"><span class="nxm-step-icon dashicons dashicons-visibility"></span><div><strong><?php esc_html_e( 'Optimize and verify', 'nexora-media' ); ?></strong><span><?php esc_html_e( 'Run the first build, then confirm WebP delivery in a logged-out Network tab.', 'nexora-media' ); ?></span></div></div>
                    </div>
                </section>
                <?php endif; ?>

                <div class="nxm-dashboard-layout <?php echo esc_attr( ! $wizard_complete ? 'nxm-dashboard-layout--locked' : '' ); ?>" id="nxm-dashboard-layout">
                    <div class="nxm-dashboard-main">
                        <section class="nxm-build-control nxm-build-control--top" id="nxm-build-control">
                            <div>
                                <span class="nxm-kicker"><?php esc_html_e( 'Build Control', 'nexora-media' ); ?></span>
                                <h2><?php esc_html_e( 'Optimization Queue', 'nexora-media' ); ?></h2>
                                <p id="nxm-build-copy"><?php echo esc_html( $processing_paused ? __( 'Optimization is paused. Start the build when the site is ready.', 'nexora-media' ) : __( 'Manual optimization runs in protected batches and keeps page publishing responsive.', 'nexora-media' ) ); ?></p>
                            </div>
                            <div class="nxm-build-actions">
                                <button type="button" class="button button-primary" id="nxm-retro-btn"><?php esc_html_e( 'Optimize Images', 'nexora-media' ); ?></button>
                                <button type="button" class="button" id="nxm-stop-queue"><?php esc_html_e( 'Stop', 'nexora-media' ); ?></button>
                                <button type="button" class="button" id="nxm-test-worker"><?php esc_html_e( 'Diagnostic', 'nexora-media' ); ?></button>
                            </div>
                            <div class="nxm-progress-panel<?php echo ( ! $processing_paused && ( $queue_status['pending'] > 0 || ! empty( $queue_status['running'] ) ) ) ? ' is-active' : ''; ?>" id="nxm-progress-panel">
                                <div class="nxm-progress-row">
                                    <div class="nxm-progress-track" aria-hidden="true"><span id="nxm-queue-bar-fill" style="width: <?php echo esc_attr( (string) ( $processing_paused ? 0 : $queue_pct ) ); ?>%;"></span></div>
                                    <strong id="nxm-queue-pct"><?php echo $processing_paused ? esc_html__( 'Paused', 'nexora-media' ) : ( $queue_status['pending'] > 0 ? esc_html( $current_done . '/' . $current_total . ' - ' . $queue_pct . '%' ) : esc_html__( 'Idle', 'nexora-media' ) ); ?></strong>
                                </div>
                                <div class="nxm-progress-meta">
                                    <span class="nxm-progress-indicator<?php echo ! $processing_paused && ! empty( $queue_status['running'] ) ? ' is-running' : ''; ?>" id="nxm-progress-indicator" aria-hidden="true"></span>
                                    <span id="nxm-progress-current"><?php
                                        if ( ! empty( $queue_status['current_label'] ) ) {
                                            echo esc_html( sprintf( __( 'Processing %s', 'nexora-media' ), $queue_status['current_label'] ) );
                                        } elseif ( $queue_status['pending'] > 0 ) {
                                            esc_html_e( 'Waiting for next batch...', 'nexora-media' );
                                        } else {
                                            esc_html_e( 'No active batch', 'nexora-media' );
                                        }
                                    ?></span>
                                    <span class="nxm-progress-failed" id="nxm-progress-failed" <?php echo (int) ( $queue_status['failed'] ?? 0 ) > 0 ? '' : 'hidden'; ?>><?php echo esc_html( sprintf( __( '%d failed (lifetime)', 'nexora-media' ), (int) ( $queue_status['failed'] ?? 0 ) ) ); ?></span>
                                </div>
                            </div>
                            <div class="nxm-status-message nxm-status--info" id="nxm-status-message">
                                <?php echo $processing_paused ? esc_html__( 'Optimization is paused. Click Optimize Images when you want to process the library.', 'nexora-media' ) : ( $queue_status['pending'] > 0 ? esc_html( sprintf( __( '%d images waiting for optimization.', 'nexora-media' ), $queue_status['pending'] ) ) : esc_html__( 'Queue is clear. New images wait for your next manual run.', 'nexora-media' ) ); ?>
                            </div>
                        </section>

                        <section class="nxm-metrics-strip">
                            <div class="nxm-command-grid nxm-command-grid--metrics">
                                <div class="nxm-stat-card"><span><?php esc_html_e( 'Queue Remaining', 'nexora-media' ); ?></span><strong id="nxm-stat-pending"><?php echo esc_html( (string) $queue_status['pending'] ); ?></strong><small><?php esc_html_e( 'Current build', 'nexora-media' ); ?></small></div>
                                <div class="nxm-stat-card"><span><?php esc_html_e( 'Build Completed', 'nexora-media' ); ?></span><strong id="nxm-stat-processed"><?php echo esc_html( (string) $current_done ); ?></strong><small id="nxm-stat-processed-note"><?php echo esc_html( $current_total > 0 ? sprintf( __( 'of %d in this run', 'nexora-media' ), $current_total ) : __( 'No active run', 'nexora-media' ) ); ?></small></div>
                                <div class="nxm-stat-card"><span><?php esc_html_e( 'Ready Variants', 'nexora-media' ); ?></span><strong id="nxm-stat-webp"><?php echo esc_html( (string) $media_summary['optimized'] ); ?></strong><small id="nxm-stat-webp-note"><?php echo esc_html( sprintf( __( 'of %d library images', 'nexora-media' ), (int) $media_summary['total'] ) ); ?></small></div>
                            </div>
                            <div class="nxm-optimization-insights nxm-optimization-insights--dashboard">
                                <div class="nxm-ring-card">
                                    <div class="nxm-ring" style="--nxm-ring-value: <?php echo esc_attr( (string) $media_summary['saved_percent'] ); ?>%;"><span><?php echo esc_html( (string) $media_summary['saved_percent'] ); ?>%</span></div>
                                    <div class="nxm-ring-copy"><strong><?php esc_html_e( 'Space saved', 'nexora-media' ); ?></strong><small><?php echo esc_html( sprintf( __( 'Initial %1$s - Current %2$s', 'nexora-media' ), size_format( $media_summary['bytes_in'] ), size_format( $media_summary['best_bytes'] ) ) ); ?></small></div>
                                </div>
                                <div class="nxm-ring-card">
                                    <div class="nxm-ring" id="nxm-library-ready-ring" style="--nxm-ring-value: <?php echo esc_attr( (string) $optimized_pct ); ?>%;"><span id="nxm-library-ready-pct"><?php echo esc_html( (string) $optimized_pct ); ?>%</span></div>
                                    <div class="nxm-ring-copy"><strong><?php esc_html_e( 'Images optimized', 'nexora-media' ); ?></strong><small id="nxm-library-ready-text"><?php echo esc_html( sprintf( __( '%1$d optimized - %2$d pending - %3$d total', 'nexora-media' ), (int) $media_summary['optimized'], (int) $unoptimized_count, (int) $media_summary['total'] ) ); ?></small></div>
                                </div>
                            </div>
                        </section>

                        <section class="nxm-engine-pipeline" aria-label="<?php esc_attr_e( 'Optimization pipeline', 'nexora-media' ); ?>">
                            <div class="nxm-pipeline-node is-ready">
                                <span class="dashicons dashicons-upload"></span>
                                <div><strong><?php esc_html_e( 'Upload Intake', 'nexora-media' ); ?></strong><small><?php echo esc_html( sprintf( __( '%d library images detected', 'nexora-media' ), (int) $media_summary['total'] ) ); ?></small></div>
                            </div>
                            <div class="nxm-pipeline-node <?php echo esc_attr( $queue_status['pending'] > 0 ? 'is-warning' : 'is-ready' ); ?>">
                                <span class="dashicons dashicons-update"></span>
                                <div><strong><?php esc_html_e( 'Protected Queue', 'nexora-media' ); ?></strong><small><?php echo esc_html( $queue_status['pending'] > 0 ? sprintf( __( '%d waiting in safe batches', 'nexora-media' ), (int) $queue_status['pending'] ) : __( 'No pending work', 'nexora-media' ) ); ?></small></div>
                            </div>
                            <div class="nxm-pipeline-node <?php echo esc_attr( $media_summary['optimized'] > 0 ? 'is-ready' : 'is-neutral' ); ?>">
                                <span class="dashicons dashicons-format-image"></span>
                                <div><strong><?php esc_html_e( 'WebP Variant Layer', 'nexora-media' ); ?></strong><small><?php echo esc_html( sprintf( __( '%d optimized assets ready', 'nexora-media' ), (int) $media_summary['optimized'] ) ); ?></small></div>
                            </div>
                            <div class="nxm-pipeline-node <?php echo esc_attr( $delivery_ready ? 'is-ready' : 'is-neutral' ); ?>">
                                <span class="dashicons dashicons-visibility"></span>
                                <div><strong><?php esc_html_e( 'Frontend Delivery', 'nexora-media' ); ?></strong><small><?php echo esc_html( $delivery_ready ? __( 'Public visitors receive optimized output', 'nexora-media' ) : __( 'Safe original delivery until enabled', 'nexora-media' ) ); ?></small></div>
                            </div>
                        </section>

                        <section class="nxm-media-app nxm-media-app--full" id="nxm-media-report">
                            <div class="nxm-media-head">
                                <div>
                                    <span class="nxm-kicker"><?php esc_html_e( 'Media Visibility', 'nexora-media' ); ?></span>
                                    <h2><?php esc_html_e( 'Delivery Intelligence Board', 'nexora-media' ); ?></h2>
                                    <p><?php esc_html_e( 'Each image shows optimization health, frontend readiness, payload impact, and the safest next action.', 'nexora-media' ); ?></p>
                                    <div class="nxm-report-badges" aria-label="<?php esc_attr_e( 'Delivery report summary', 'nexora-media' ); ?>">
                                        <span><?php esc_html_e( 'Format: WebP', 'nexora-media' ); ?></span>
                                        <span><?php echo esc_html( $delivery_ready ? __( 'Public delivery: Active', 'nexora-media' ) : __( 'Public delivery: Safe mode', 'nexora-media' ) ); ?></span>
                                        <span><?php esc_html_e( 'Editors/builders: Original output', 'nexora-media' ); ?></span>
                                    </div>
                                </div>
                                <div class="nxm-media-savings">
                                    <strong><?php echo esc_html( (string) $media_summary['saved_percent'] ); ?>%</strong>
                                    <span><?php echo esc_html( sprintf( __( '%s saved', 'nexora-media' ), size_format( $media_summary['saved'] ) ) ); ?></span>
                                </div>
                            </div>
                            <div class="nxm-media-board" id="nxm-media-table-body">
                                <?php if ( empty( $media_items ) ) : ?>
                                    <div class="nxm-media-empty">
                                        <span class="dashicons dashicons-format-gallery"></span>
                                        <strong><?php esc_html_e( 'No images in the library yet', 'nexora-media' ); ?></strong>
                                        <p><?php esc_html_e( 'Upload images to WordPress and they will appear here with optimization status, delivery mode, and savings.', 'nexora-media' ); ?></p>
                                    </div>
                                <?php else : ?>
                                    <?php foreach ( $media_items as $item ) : ?>
                                        <article class="nxm-media-card nxm-media-card--<?php echo esc_attr( $item['status'] ); ?>" data-attachment-id="<?php echo esc_attr( (string) $item['id'] ); ?>">
                                            <div class="nxm-media-card-preview">
                                                <img src="<?php echo esc_url( $item['thumb'] ); ?>" alt="">
                                                <span class="nxm-media-status nxm-media-status--<?php echo esc_attr( $item['status'] ); ?>"><?php echo esc_html( $item['status_label'] ); ?></span>
                                            </div>
                                            <div class="nxm-media-card-body">
                                                <div class="nxm-media-card-title">
                                                    <div>
                                                        <strong><?php echo esc_html( $item['title'] ?: $item['filename'] ); ?></strong>
                                                        <small><?php echo esc_html( $item['filename'] ); ?></small>
                                                    </div>
                                                    <span class="nxm-card-format nxm-format-list"><?php echo esc_html( $item['formats_label'] ); ?></span>
                                                </div>
                                                <div class="nxm-media-health">
                                                    <div><span><?php esc_html_e( 'Optimized', 'nexora-media' ); ?></span><strong class="nxm-best-size"><?php echo esc_html( $item['best_bytes_label'] ); ?></strong></div>
                                                    <div><span><?php esc_html_e( 'Uploaded', 'nexora-media' ); ?></span><strong class="nxm-source-size"><?php echo esc_html( $item['source_bytes_label'] ); ?></strong></div>
                                                    <div><span><?php esc_html_e( 'Saved', 'nexora-media' ); ?></span><strong class="nxm-saved-size"><?php echo esc_html( $item['saved_label'] ); ?></strong></div>
                                                </div>
                                                <div class="nxm-card-delivery">
                                                    <div>
                                                        <span class="nxm-sync-status nxm-sync-status--<?php echo esc_attr( $item['sync_status'] ); ?>"><?php echo esc_html( $item['sync_label'] ); ?></span>
                                                        <small class="nxm-sync-note"><?php echo esc_html( $item['sync_note'] ); ?></small>
                                                    </div>
                                                    <small class="nxm-updated-label"><?php echo esc_html( $item['updated_label'] ); ?></small>
                                                </div>
                                                <div class="nxm-row-actions">
                                                    <?php if ( ! empty( $item['sync_enabled'] ) ) : ?>
                                                        <button type="button" class="button nxm-sync-one" data-id="<?php echo esc_attr( (string) $item['id'] ); ?>"><?php echo esc_html( $item['sync_button_label'] ); ?></button>
                                                    <?php endif; ?>
                                                    <button type="button" class="button nxm-optimize-one" data-id="<?php echo esc_attr( (string) $item['id'] ); ?>" <?php disabled( empty( $item['optimize_enabled'] ) ); ?>><?php echo esc_html( $item['optimize_label'] ); ?></button>
                                                    <button type="button" class="button nxm-toggle-delivery" data-id="<?php echo esc_attr( (string) $item['id'] ); ?>"><?php echo esc_html( $item['delivery_label'] ); ?></button>
                                                </div>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </section>
                    </div>

                    <aside class="nxm-dashboard-side" id="nxm-delivery-controls">
                        <section class="nxm-side-card nxm-server-card">
                            <span class="nxm-kicker"><?php esc_html_e( 'Server Readiness', 'nexora-media' ); ?></span>
                            <h2><?php esc_html_e( 'Configuration', 'nexora-media' ); ?></h2>
                            <div class="nxm-server-grid">
                                <?php foreach ( $server_cards as $card ) : ?>
                                    <div class="nxm-server-item nxm-server-item--<?php echo esc_attr( $card['state'] ); ?>">
                                        <span class="dashicons dashicons-<?php echo esc_attr( $card['icon'] ); ?>"></span>
                                        <div>
                                            <small><?php echo esc_html( $card['label'] ); ?></small>
                                            <strong><?php echo esc_html( $card['value'] ); ?></strong>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </section>

                        <section class="nxm-side-card nxm-quality-card">
                            <div class="nxm-side-card-head">
                                <div>
                                    <span class="nxm-kicker"><?php esc_html_e( 'Quality Policy', 'nexora-media' ); ?></span>
                                    <h2><?php esc_html_e( 'Optimization Rules', 'nexora-media' ); ?></h2>
                                </div>
                                <button type="button" class="button button-primary nxm-save-rule"><?php esc_html_e( 'Save', 'nexora-media' ); ?></button>
                            </div>
                            <div class="nxm-range-grid">
                                <label><span><?php esc_html_e( 'Quality', 'nexora-media' ); ?></span><input class="nxm-number-live" data-option="nxm_quality" type="number" name="nxm_quality" min="40" max="95" value="<?php echo esc_attr( (string) $quality ); ?>"></label>
                                <label><span><?php esc_html_e( 'Max Width', 'nexora-media' ); ?></span><input class="nxm-number-live" data-option="nxm_max_width" type="number" name="nxm_max_width" min="1024" max="6000" value="<?php echo esc_attr( (string) $max_width ); ?>"></label>
                            </div>
                        </section>

                        <section class="nxm-side-card">
                            <span class="nxm-kicker"><?php esc_html_e( 'Controls', 'nexora-media' ); ?></span>
                            <h2><?php esc_html_e( 'Delivery Settings', 'nexora-media' ); ?></h2>
                            <div class="nxm-side-settings">
                                <div class="nxm-setting-row"><div><strong><?php esc_html_e( 'Native WebP Delivery', 'nexora-media' ); ?></strong><small><?php esc_html_e( 'Serve generated WebP variants to public visitors.', 'nexora-media' ); ?></small></div><label class="nxm-switch"><input type="hidden" name="nxm_enable_adaptive" value="0"><input class="nxm-toggle-live" data-option="nxm_enable_adaptive" type="checkbox" name="nxm_enable_adaptive" value="1" <?php checked( $adaptive_enabled ); ?>><span class="nxm-slider"></span></label></div>
                                <div class="nxm-setting-row"><div><strong><?php esc_html_e( 'WebP Generation', 'nexora-media' ); ?></strong><small><?php echo esc_html( $webp_supported ? __( 'Generate lightweight WebP variants.', 'nexora-media' ) : __( 'Unavailable on this server.', 'nexora-media' ) ); ?></small></div><label class="nxm-switch"><input type="hidden" name="nxm_enable_webp" value="0"><input class="nxm-toggle-live" data-option="nxm_enable_webp" type="checkbox" name="nxm_enable_webp" value="1" <?php checked( $webp_enabled ); ?> <?php disabled( ! $webp_supported ); ?>><span class="nxm-slider"></span></label></div>
                                <div class="nxm-setting-row"><div><strong><?php esc_html_e( 'Queue New Uploads', 'nexora-media' ); ?></strong><small><?php esc_html_e( 'Show new uploads as pending work.', 'nexora-media' ); ?></small></div><label class="nxm-switch"><input type="hidden" name="nxm_enable_queue" value="0"><input class="nxm-toggle-live" data-option="nxm_enable_queue" type="checkbox" name="nxm_enable_queue" value="1" <?php checked( $queue_enabled ); ?>><span class="nxm-slider"></span></label></div>
                                <div class="nxm-setting-row"><div><strong><?php esc_html_e( 'Auto Process Queue', 'nexora-media' ); ?></strong><small><?php esc_html_e( 'Run queued work automatically.', 'nexora-media' ); ?></small></div><label class="nxm-switch"><input type="hidden" name="nxm_auto_process_queue" value="0"><input class="nxm-toggle-live" data-option="nxm_auto_process_queue" type="checkbox" name="nxm_auto_process_queue" value="1" <?php checked( $auto_process_enabled ); ?>><span class="nxm-slider"></span></label></div>
                                <div class="nxm-setting-row"><div><strong><?php esc_html_e( 'Lazy Loading', 'nexora-media' ); ?></strong><small><?php esc_html_e( 'Add safe loading attributes.', 'nexora-media' ); ?></small></div><label class="nxm-switch"><input type="hidden" name="nxm_enable_lazyload" value="0"><input class="nxm-toggle-live" data-option="nxm_enable_lazyload" type="checkbox" name="nxm_enable_lazyload" value="1" <?php checked( $lazy_enabled ); ?>><span class="nxm-slider"></span></label></div>
                                <div class="nxm-setting-row"><div><strong><?php esc_html_e( 'Strip EXIF', 'nexora-media' ); ?></strong><small><?php esc_html_e( 'Remove camera metadata.', 'nexora-media' ); ?></small></div><label class="nxm-switch"><input type="hidden" name="nxm_strip_exif" value="0"><input class="nxm-toggle-live" data-option="nxm_strip_exif" type="checkbox" name="nxm_strip_exif" value="1" <?php checked( $strip_exif ); ?>><span class="nxm-slider"></span></label></div>
                            </div>
                        </section>

                        <section class="nxm-side-card nxm-side-card--blue">
                            <span class="nxm-kicker"><?php esc_html_e( 'Frontend Delivery', 'nexora-media' ); ?></span>
                            <h2><?php echo esc_html( $frontend_label ); ?></h2>
                            <p><?php echo esc_html( $frontend_note ); ?></p>
                            <div class="nxm-detection-list">
                                <div><span><?php esc_html_e( 'Native Delivery', 'nexora-media' ); ?></span><strong><?php echo esc_html( $adaptive_enabled ? __( 'On', 'nexora-media' ) : __( 'Safe mode', 'nexora-media' ) ); ?></strong></div>
                                <div><span><?php esc_html_e( 'WebP Generation', 'nexora-media' ); ?></span><strong><?php echo esc_html( $webp_enabled && $webp_supported ? __( 'Ready', 'nexora-media' ) : __( 'Off', 'nexora-media' ) ); ?></strong></div>
                                <div><span><?php esc_html_e( 'Verification', 'nexora-media' ); ?></span><strong><?php esc_html_e( 'Logged-out browser', 'nexora-media' ); ?></strong></div>
                                <div><span><?php esc_html_e( 'Engine Bridge', 'nexora-media' ); ?></span><strong><?php echo esc_html( $engine_active ? __( 'Connected', 'nexora-media' ) : __( 'Standalone', 'nexora-media' ) ); ?></strong></div>
                            </div>
                        </section>
                    </aside>
                </div>
            </form>

            <div class="nxm-diagnostic-panel" id="nxm-diagnostic-panel" hidden>
                <div class="nxm-diagnostic-panel-inner">
                    <div class="nxm-diagnostic-panel-head">
                        <strong><?php esc_html_e( 'Engine Diagnostic', 'nexora-media' ); ?></strong>
                        <button type="button" class="button-link nxm-diagnostic-close" id="nxm-diagnostic-close" aria-label="<?php esc_attr_e( 'Close diagnostic', 'nexora-media' ); ?>">&times;</button>
                    </div>
                    <dl id="nxm-diagnostic-body"></dl>
                </div>
            </div>
        </main>
    </div>
</div>
