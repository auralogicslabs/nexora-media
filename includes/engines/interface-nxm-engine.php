<?php
/**
 * Nexora Media — Image Processing Engine Interface
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface NXM_Engine {
    
    /**
     * Check if the engine is available on the current server.
     */
    public static function is_available(): bool;

    /**
     * Check if the engine supports a specific format (e.g., 'avif', 'webp').
     */
    public static function supports_format( string $format ): bool;

    /**
     * Convert an image to a target format and save it.
     *
     * @param string $source_path Absolute path to the source image.
     * @param string $target_path Absolute path to save the converted image.
     * @param string $format      Target format ('avif', 'webp').
     * @param int    $quality     Compression quality (1-100).
     * @return bool True on success, false on failure.
     */
    public function convert( string $source_path, string $target_path, string $format, int $quality = 82 ): bool;

    /**
     * Resize an image to new dimensions.
     */
    public function resize( string $source_path, string $target_path, int $width, int $height, bool $crop = false ): bool;
}
