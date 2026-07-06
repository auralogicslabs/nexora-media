<?php
/**
 * Nexora Media — GD Engine (Fallback)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NXMEDIA_Engine_GD implements NXMEDIA_Engine {

    public static function is_available(): bool {
        return extension_loaded( 'gd' ) && function_exists( 'gd_info' );
    }

    public static function supports_format( string $format ): bool {
        if ( ! self::is_available() ) {
            return false;
        }

        $info = gd_info();
        $format = strtolower( $format );

        if ( $format === 'webp' ) {
            return isset( $info['WebP Support'] ) && $info['WebP Support'];
        }

        if ( $format === 'avif' ) {
            return isset( $info['AVIF Support'] ) && $info['AVIF Support'];
        }

        return false;
    }

    public function convert( string $source_path, string $target_path, string $format, int $quality = 82 ): bool {
        $image = $this->load_image( $source_path );
        if ( ! $image ) {
            return false;
        }

        $success = $this->save_image( $image, $target_path, $format, $quality );
        imagedestroy( $image );
        
        return $success;
    }

    public function resize( string $source_path, string $target_path, int $width, int $height, bool $crop = false ): bool {
        $image = $this->load_image( $source_path );
        if ( ! $image ) {
            return false;
        }

        $orig_w = imagesx( $image );
        $orig_h = imagesy( $image );

        if ( $crop ) {
            // Basic crop logic
            $ratio = max( $width / $orig_w, $height / $orig_h );
            $new_w = (int) round( $orig_w * $ratio );
            $new_h = (int) round( $orig_h * $ratio );
            $src_x = (int) round( ( $new_w - $width ) / 2 / $ratio );
            $src_y = (int) round( ( $new_h - $height ) / 2 / $ratio );
            
            $new_image = imagecreatetruecolor( $width, $height );
            imagealphablending( $new_image, false );
            imagesavealpha( $new_image, true );
            
            imagecopyresampled( $new_image, $image, 0, 0, $src_x, $src_y, $width, $height, $orig_w, $orig_h );
        } else {
            // Proportional resize
            $ratio = min( $width / $orig_w, $height / $orig_h );
            $new_w = (int) round( $orig_w * $ratio );
            $new_h = (int) round( $orig_h * $ratio );
            
            $new_image = imagecreatetruecolor( $new_w, $new_h );
            imagealphablending( $new_image, false );
            imagesavealpha( $new_image, true );
            
            imagecopyresampled( $new_image, $image, 0, 0, 0, 0, $new_w, $new_h, $orig_w, $orig_h );
        }

        // Get extension from source to save in original format
        $ext = strtolower( pathinfo( $source_path, PATHINFO_EXTENSION ) );
        if ( $ext === 'jpg' ) $ext = 'jpeg';
        
        $success = $this->save_image( $new_image, $target_path, $ext, 82 );
        
        imagedestroy( $image );
        imagedestroy( $new_image );
        
        return $success;
    }

    private function load_image( string $path ) {
        $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        switch ( $ext ) {
            case 'jpg':
            case 'jpeg': return @imagecreatefromjpeg( $path );
            case 'png':  return @imagecreatefrompng( $path );
            case 'gif':  return @imagecreatefromgif( $path );
            case 'webp': return @imagecreatefromwebp( $path );
            case 'avif': return function_exists( 'imagecreatefromavif' ) ? @imagecreatefromavif( $path ) : false;
        }
        return false;
    }

    private function save_image( $image, string $path, string $format, int $quality ): bool {
        switch ( strtolower( $format ) ) {
            case 'jpg':
            case 'jpeg': return imagejpeg( $image, $path, $quality );
            case 'png':  return imagepng( $image, $path, (int) round( 9 * ( $quality / 100 ) ) );
            case 'gif':  return imagegif( $image, $path );
            case 'webp': return imagewebp( $image, $path, $quality );
            case 'avif': return function_exists( 'imageavif' ) ? imageavif( $image, $path, $quality ) : false;
        }
        return false;
    }
}
