<?php
/**
 * Nexora Media — Image Processor (Orchestrator)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NXMEDIA_Image_Processor {

    private static ?NXMEDIA_Image_Processor $instance = null;
    
    private ?NXMEDIA_Engine $engine = null;

    public static function get_instance(): NXMEDIA_Image_Processor {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->detect_engine();
    }

    private function detect_engine(): void {
        if ( NXMEDIA_Engine_Imagick::is_available() ) {
            $this->engine = new NXMEDIA_Engine_Imagick();
        } elseif ( NXMEDIA_Engine_GD::is_available() ) {
            $this->engine = new NXMEDIA_Engine_GD();
        }
    }

    public function has_engine(): bool {
        return null !== $this->engine;
    }

    /**
     * Determine if a format is supported by the active engine.
     */
    public function supports( string $format ): bool {
        if ( ! $this->has_engine() ) {
            return false;
        }

        if ( $this->engine instanceof NXMEDIA_Engine_Imagick ) {
            return NXMEDIA_Engine_Imagick::supports_format( $format );
        }
        
        if ( $this->engine instanceof NXMEDIA_Engine_GD ) {
            return NXMEDIA_Engine_GD::supports_format( $format );
        }

        return false;
    }

    /**
     * Convert an image to AVIF or WebP.
     */
    public function convert( string $source_path, string $format, int $quality = 82 ): ?string {
        if ( ! $this->has_engine() || ! $this->supports( $format ) || ! $this->is_supported_file( $source_path ) ) {
            return null;
        }

        $dir  = dirname( $source_path );
        $name = pathinfo( $source_path, PATHINFO_FILENAME );
        $target_path = $dir . DIRECTORY_SEPARATOR . $name . '.' . strtolower( $format );

        if ( file_exists( $target_path ) && filesize( $target_path ) > 0 ) {
            return $target_path; // Already converted
        }

        $tmp_path = $target_path . '.tmp';
        $success  = $this->engine->convert( $source_path, $tmp_path, $format, $quality );
        if ( $success && file_exists( $tmp_path ) ) {
            // Atomic publish: move the fully-encoded temp file into place so a
            // half-written variant never appears at the final path.
            $fs = self::filesystem();
            if ( $fs && $fs->move( $tmp_path, $target_path, true ) ) {
                // Moved.
            } elseif ( file_exists( $tmp_path ) ) {
                wp_delete_file( $tmp_path );
            }
        } elseif ( file_exists( $tmp_path ) ) {
            wp_delete_file( $tmp_path );
        }

        return file_exists( $target_path ) ? $target_path : null;
    }

    /**
     * Lazily initialize and return the WP_Filesystem instance, or null on failure.
     */
    private static function filesystem() {
        global $wp_filesystem;
        if ( $wp_filesystem instanceof \WP_Filesystem_Base ) {
            return $wp_filesystem;
        }
        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if ( WP_Filesystem() ) {
            return $wp_filesystem;
        }
        return null;
    }

    /**
     * Create responsive sizes.
     */
    public function create_sizes( string $source_path, array $sizes ): array {
        if ( ! $this->has_engine() ) {
            return [];
        }

        $results = [];
        $dir  = dirname( $source_path );
        $name = pathinfo( $source_path, PATHINFO_FILENAME );
        $ext  = pathinfo( $source_path, PATHINFO_EXTENSION );

        foreach ( $sizes as $width ) {
            // Keep height proportional (e.g. 0 allows engine logic to auto-calculate height from ratio)
            // For this orchestrator, we assume the engine `resize` can handle 0 as proportional.
            // Let's calculate height here based on source image to be safe.
            $img_info = @getimagesize( $source_path );
            if ( ! $img_info ) continue;
            
            $orig_w = $img_info[0];
            $orig_h = $img_info[1];
            
            if ( $orig_w <= $width ) continue; // Skip if source is smaller

            $height = (int) round( $orig_h * ( $width / $orig_w ) );
            
            $target_path = $dir . DIRECTORY_SEPARATOR . $name . '-nxm-' . $width . 'w.' . $ext;
            
            if ( ! file_exists( $target_path ) ) {
                $this->engine->resize( $source_path, $target_path, $width, $height, false );
            }
            
            if ( file_exists( $target_path ) ) {
                $results[ $width ] = $target_path;
            }
        }
        
        return $results;
    }

    public function is_supported_file( string $source_path ): bool {
        $ext = strtolower( pathinfo( $source_path, PATHINFO_EXTENSION ) );
        return in_array( $ext, [ 'jpg', 'jpeg', 'png', 'gif', 'webp' ], true );
    }

    public static function responsive_widths(): array {
        $raw = (string) get_option( 'nxmedia_responsive_widths', '320,640,960,1600' );
        $widths = array_filter( array_map( 'absint', preg_split( '/[\s,]+/', $raw ) ) );
        $widths = array_values( array_unique( array_filter( $widths, static fn( $width ) => $width >= 160 && $width <= 3840 ) ) );
        sort( $widths );

        return $widths ?: [ 320, 640, 960, 1600 ];
    }

    public function engine_name(): string {
        if ( $this->engine instanceof NXMEDIA_Engine_Imagick ) {
            return 'Imagick';
        }
        if ( $this->engine instanceof NXMEDIA_Engine_GD ) {
            return 'GD';
        }
        return 'None';
    }
}
