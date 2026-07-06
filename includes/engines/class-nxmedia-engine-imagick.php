<?php
/**
 * Nexora Media — Imagick Engine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NXMEDIA_Engine_Imagick implements NXMEDIA_Engine {

    public static function is_available(): bool {
        return extension_loaded( 'imagick' ) && class_exists( 'Imagick' );
    }

    public static function supports_format( string $format ): bool {
        if ( ! self::is_available() ) {
            return false;
        }

        try {
            $imagick = new Imagick();
            $formats = $imagick->queryFormats();
            return in_array( strtoupper( $format ), $formats, true );
        } catch ( Exception $e ) {
            return false;
        }
    }

    public function convert( string $source_path, string $target_path, string $format, int $quality = 82 ): bool {
        try {
            $imagick = new Imagick( $source_path );
            $imagick->setImageFormat( $format );
            $imagick->setImageCompressionQuality( $quality );
            
            if ( get_option( 'nxmedia_strip_exif', true ) ) {
                $imagick->stripImage();
            }
            
            $success = $imagick->writeImage( $target_path );
            $imagick->clear();
            $imagick->destroy();
            
            return $success;
        } catch ( Exception $e ) {
            return false;
        }
    }

    public function resize( string $source_path, string $target_path, int $width, int $height, bool $crop = false ): bool {
        try {
            $imagick = new Imagick( $source_path );
            
            if ( $crop ) {
                $imagick->cropThumbnailImage( $width, $height );
            } else {
                $imagick->thumbnailImage( $width, $height, true );
            }
            
            if ( get_option( 'nxmedia_strip_exif', true ) ) {
                $imagick->stripImage();
            }
            
            $success = $imagick->writeImage( $target_path );
            $imagick->clear();
            $imagick->destroy();
            
            return $success;
        } catch ( Exception $e ) {
            return false;
        }
    }
}
