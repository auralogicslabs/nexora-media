<?php
/**
 * Nexora Media — CSS Optimization Cache.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NXM_CSS_Optimizer {

    private static ?NXM_CSS_Optimizer $instance = null;

    public static function get_instance(): NXM_CSS_Optimizer {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if ( class_exists( 'NXM_Init' ) && ! NXM_Init::is_frontend_delivery_request() ) {
            return;
        }

        if ( ! get_option( 'nxm_enable_css_cache', false ) ) {
            return;
        }

        if ( $this->should_defer_to_engine() ) {
            return;
        }

        add_filter( 'style_loader_src', [ $this, 'rewrite_stylesheet_src' ], 20, 2 );
    }

    public function rewrite_stylesheet_src( string $src, string $handle ): string {
        if ( ( class_exists( 'NXM_Init' ) && ! NXM_Init::is_frontend_delivery_request() ) || $this->should_defer_to_engine() || empty( $src ) || ! get_option( 'nxm_enable_webp', true ) ) {
            return $src;
        }

        $source_path = $this->url_to_local_path( $src );
        if ( ! $source_path || ! file_exists( $source_path ) || strtolower( pathinfo( $source_path, PATHINFO_EXTENSION ) ) !== 'css' ) {
            return $src;
        }

        if ( filesize( $source_path ) > 2 * MB_IN_BYTES ) {
            return $src;
        }

        $cache = $this->cache_target( $src, $source_path );
        if ( ! $cache ) {
            return $src;
        }

        $invalidated_at = (int) get_option( 'nxm_css_cache_invalidated_at', 0 );
        if ( ! file_exists( $cache['path'] ) || filemtime( $cache['path'] ) < max( filemtime( $source_path ), $invalidated_at ) ) {
            $this->build_cache_file( $source_path, $src, $cache['path'] );
        }

        return file_exists( $cache['path'] ) ? $cache['url'] : $src;
    }

    public static function stats(): array {
        $upload_dir = wp_upload_dir();
        $dir        = trailingslashit( $upload_dir['basedir'] ) . 'nexora-media/css';
        $count      = 0;
        $bytes      = 0;

        if ( is_dir( $dir ) ) {
            foreach ( glob( trailingslashit( $dir ) . '*.css' ) ?: [] as $file ) {
                $count++;
                $bytes += filesize( $file );
            }
        }

        $enabled = (bool) get_option( 'nxm_enable_css_cache', false );
        if ( $enabled && class_exists( 'NXM_Init' ) && NXM_Init::is_nexora_engine_active() && ! (bool) get_option( 'nxm_allow_engine_css_cache', false ) ) {
            $enabled = false;
        }

        return [
            'enabled' => $enabled,
            'files'   => $count,
            'bytes'   => $bytes,
        ];
    }

    public static function purge_cache(): int {
        update_option( 'nxm_css_cache_purged_at', time(), false );
        update_option( 'nxm_css_cache_invalidated_at', time(), false );
        do_action( 'nxm_css_cache_purged' );

        // Preserve existing files because static mirrors may still reference
        // them. Cache files rebuild in place on the next live render/capture.
        return 0;
    }

    public static function repair_existing_cache(): int {
        $upload_dir = wp_upload_dir();
        $cache_dir  = trailingslashit( $upload_dir['basedir'] ) . 'nexora-media/css';

        if ( ! is_dir( $cache_dir ) ) {
            return 0;
        }

        $instance = new self();
        $repaired = 0;

        foreach ( glob( trailingslashit( $cache_dir ) . '*.css' ) ?: [] as $cache_file ) {
            if ( ! is_file( $cache_file ) ) {
                continue;
            }

            $source_filename = preg_replace( '/-[a-f0-9]{32}\.css$/i', '.css', wp_basename( $cache_file ) );
            if ( ! is_string( $source_filename ) || $source_filename === wp_basename( $cache_file ) ) {
                continue;
            }

            $source_path = $instance->find_source_css( $source_filename, $cache_file );
            if ( ! $source_path ) {
                continue;
            }

            $source_url = $instance->local_path_to_url( $source_path );
            if ( ! $source_url ) {
                continue;
            }

            $instance->build_cache_file( $source_path, $source_url, $cache_file );
            $repaired++;
        }

        if ( $repaired > 0 ) {
            update_option( 'nxm_css_cache_repaired_at', time(), false );
        }

        return $repaired;
    }

    private function build_cache_file( string $source_path, string $source_url, string $target_path ): void {
        $css = file_get_contents( $source_path );
        if ( false === $css ) {
            return;
        }

        $rewritten = preg_replace_callback(
            '/url\(\s*(["\']?)([^"\')]+)(["\']?)\s*\)/i',
            function( array $matches ) use ( $source_path, $source_url ) {
                $quote = $matches[1] ?: $matches[3];
                $url   = trim( $matches[2] );

                if ( $this->should_preserve_css_url( $url ) ) {
                    return $matches[0];
                }

                $absolute_url = $this->resolve_css_url( $url, $source_path, $source_url );

                if ( $this->is_css_image_url( $absolute_url ) ) {
                    $variant_url = $this->webp_variant_url( $absolute_url );
                    if ( $variant_url ) {
                        $absolute_url = $variant_url;
                    }
                }

                return 'url(' . $quote . esc_url_raw( $absolute_url ) . $quote . ')';
            },
            $css
        );

        if ( ! is_string( $rewritten ) ) {
            return;
        }

        wp_mkdir_p( dirname( $target_path ) );
        if ( false !== file_put_contents( $target_path, $rewritten, LOCK_EX ) ) {
            update_option( 'nxm_css_cache_updated_at', time(), false );
            do_action( 'nxm_css_cache_updated', $target_path );
        }
    }

    private function should_defer_to_engine(): bool {
        return class_exists( 'NXM_Init' )
            && NXM_Init::is_nexora_engine_active()
            && ! (bool) get_option( 'nxm_allow_engine_css_cache', false );
    }

    private function cache_target( string $src, string $source_path ): ?array {
        $upload_dir = wp_upload_dir();
        $dir        = trailingslashit( $upload_dir['basedir'] ) . 'nexora-media/css';
        $url        = trailingslashit( $upload_dir['baseurl'] ) . 'nexora-media/css';
        $hash       = md5( strtok( $src, '?' ) . '|' . wp_normalize_path( $source_path ) );
        $filename   = sanitize_file_name( pathinfo( $source_path, PATHINFO_FILENAME ) . '-' . $hash . '.css' );

        return [
            'path' => trailingslashit( $dir ) . $filename,
            'url'  => trailingslashit( $url ) . $filename,
        ];
    }

    private function resolve_css_url( string $url, string $source_path, string $source_url ): string {
        if ( preg_match( '#^https?://#i', $url ) || strpos( $url, '//' ) === 0 ) {
            return $url;
        }

        if ( strpos( $url, '/' ) === 0 ) {
            $home  = wp_parse_url( home_url() );
            $host  = ( $home['scheme'] ?? 'https' ) . '://' . ( $home['host'] ?? '' );
            $host .= isset( $home['port'] ) ? ':' . $home['port'] : '';
            return $host . $url;
        }

        $base = trailingslashit( dirname( strtok( $source_url, '?' ) ) );
        return $base . $url;
    }

    private function should_preserve_css_url( string $url ): bool {
        $url = trim( $url );

        if ( '' === $url || '#' === $url[0] ) {
            return true;
        }

        if ( preg_match( '#^(data:|blob:|mailto:|tel:|javascript:|about:)#i', $url ) ) {
            return true;
        }

        return false;
    }

    private function is_css_image_url( string $url ): bool {
        return (bool) preg_match( '/\.(jpe?g|png|gif|webp)(?:[?#].*)?$/i', $url );
    }

    private function find_source_css( string $filename, string $cache_file ): ?string {
        $upload_dir = wp_upload_dir();
        $roots = array_filter( array_unique( [
            defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '',
            get_theme_root(),
            trailingslashit( ABSPATH ) . WPINC,
            $upload_dir['basedir'],
        ] ) );

        $cache_file = wp_normalize_path( $cache_file );
        $cache_dir  = wp_normalize_path( trailingslashit( $upload_dir['basedir'] ) . 'nexora-media/css' );

        foreach ( $roots as $root ) {
            if ( ! is_dir( $root ) ) {
                continue;
            }

            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
                );
            } catch ( Exception $e ) {
                continue;
            }

            foreach ( $iterator as $file ) {
                if ( ! $file instanceof SplFileInfo || ! $file->isFile() || $file->getFilename() !== $filename ) {
                    continue;
                }

                $path = wp_normalize_path( $file->getPathname() );
                if ( $path === $cache_file || strpos( $path, $cache_dir ) === 0 ) {
                    continue;
                }

                return $path;
            }
        }

        return null;
    }

    private function webp_variant_url( string $url ): ?string {
        $attachment_id = attachment_url_to_postid( $url );
        if ( $attachment_id && get_post_meta( $attachment_id, '_nxm_delivery_disabled', true ) ) {
            return null;
        }

        $path = $this->url_to_local_path( $url );
        if ( ! $path || ! file_exists( $path ) ) {
            return null;
        }

        $variant = preg_replace( '/\.(jpe?g|png|gif|webp)$/i', '.webp', strtok( $path, '?' ) );
        if ( ! $variant || ! file_exists( $variant ) || filesize( $variant ) > filesize( $path ) ) {
            return null;
        }

        return $this->local_path_to_url( $variant );
    }

    private function url_to_local_path( string $url ): ?string {
        $url       = strtok( $url, '?' );
        $site_url  = site_url();
        $home_url  = home_url();
        $abspath   = wp_normalize_path( ABSPATH );

        foreach ( [ $site_url, $home_url ] as $base_url ) {
            if ( strpos( $url, $base_url ) === 0 ) {
                $relative = ltrim( substr( $url, strlen( $base_url ) ), '/' );
                return $abspath . $relative;
            }
        }

        $uploads = wp_upload_dir();
        if ( strpos( $url, $uploads['baseurl'] ) === 0 ) {
            return wp_normalize_path( $uploads['basedir'] . substr( $url, strlen( $uploads['baseurl'] ) ) );
        }

        return null;
    }

    private function local_path_to_url( string $path ): ?string {
        $path     = wp_normalize_path( $path );
        $abspath  = wp_normalize_path( ABSPATH );
        $uploads  = wp_upload_dir();
        $base_dir = wp_normalize_path( $uploads['basedir'] );

        if ( strpos( $path, $base_dir ) === 0 ) {
            return trailingslashit( $uploads['baseurl'] ) . ltrim( substr( $path, strlen( $base_dir ) ), '/' );
        }

        if ( strpos( $path, $abspath ) === 0 ) {
            return trailingslashit( site_url() ) . ltrim( substr( $path, strlen( $abspath ) ), '/' );
        }

        return null;
    }
}
