<?php
/**
 * Nexora Media — Native WordPress image delivery.
 *
 * Uses WordPress attachment image filters instead of full-page HTML rewriting.
 * This keeps Elementor, menus, galleries, popups, and Nexora Engine mirrors safer.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NXM_Native_Delivery {

    private static ?NXM_Native_Delivery $instance = null;

    public static function get_instance(): NXM_Native_Delivery {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        if ( class_exists( 'NXM_Init' ) && ! NXM_Init::is_frontend_delivery_request() ) {
            return;
        }

        if ( ! get_option( 'nxm_enable_adaptive', false ) || ! get_option( 'nxm_enable_webp', true ) ) {
            return;
        }

        add_filter( 'wp_get_attachment_image_attributes', [ $this, 'filter_image_attributes' ], 20, 3 );
        add_filter( 'wp_get_attachment_image_src', [ $this, 'filter_image_src' ], 20, 4 );
        add_filter( 'wp_calculate_image_srcset', [ $this, 'filter_srcset' ], 20, 5 );
        add_filter( 'the_content', [ $this, 'filter_content_images' ], 999 );
    }

    public function filter_image_attributes( array $attr, WP_Post $attachment, $size ): array {
        $attachment_id = (int) $attachment->ID;
        if ( $this->is_delivery_disabled( $attachment_id ) ) {
            return $attr;
        }

        if ( ! empty( $attr['src'] ) ) {
            $variant = $this->variant_url_for_url( (string) $attr['src'] );
            if ( $variant ) {
                $attr['src'] = $variant;
            }
        }

        if ( ! empty( $attr['srcset'] ) ) {
            $attr['srcset'] = $this->replace_srcset_urls( (string) $attr['srcset'] );
        }

        return $attr;
    }

    public function filter_image_src( $image, int $attachment_id, $size, bool $icon ) {
        if ( $icon || ! is_array( $image ) || empty( $image[0] ) || $this->is_delivery_disabled( $attachment_id ) ) {
            return $image;
        }

        $variant = $this->variant_url_for_url( (string) $image[0] );
        if ( $variant ) {
            $image[0] = $variant;
        }

        return $image;
    }

    public function filter_srcset( array $sources, array $size_array, string $image_src, array $image_meta, int $attachment_id ): array {
        if ( empty( $sources ) || $this->is_delivery_disabled( $attachment_id ) ) {
            return $sources;
        }

        foreach ( $sources as $width => $source ) {
            if ( empty( $source['url'] ) ) {
                continue;
            }

            $variant = $this->variant_url_for_url( (string) $source['url'] );
            if ( ! $variant ) {
                continue;
            }

            $sources[ $width ]['url']  = $variant;
            $sources[ $width ]['mime'] = 'image/webp';
        }

        return $sources;
    }

    public function filter_content_images( string $content ): string {
        if ( '' === $content || false === stripos( $content, '<img' ) ) {
            return $content;
        }

        return preg_replace_callback( '/<img\b[^>]*>/i', function( array $matches ): string {
            $img = $matches[0];

            if ( $this->should_skip_img_tag( $img ) ) {
                return $img;
            }

            $img = $this->replace_img_attribute_url( $img, 'src' );
            $img = $this->replace_img_attribute_url( $img, 'data-src' );
            $img = $this->replace_img_srcset_attribute( $img, 'srcset' );
            $img = $this->replace_img_srcset_attribute( $img, 'data-srcset' );

            return $img;
        }, $content ) ?? $content;
    }

    private function replace_srcset_urls( string $srcset ): string {
        $sources = array_filter( array_map( 'trim', explode( ',', $srcset ) ) );
        $updated = [];

        foreach ( $sources as $source ) {
            $parts = preg_split( '/\s+/', $source );
            if ( empty( $parts[0] ) ) {
                continue;
            }

            $variant = $this->variant_url_for_url( $parts[0] );
            if ( $variant ) {
                $parts[0] = $variant;
            }

            $updated[] = implode( ' ', $parts );
        }

        return implode( ', ', $updated );
    }

    private function replace_img_attribute_url( string $img, string $attribute ): string {
        return preg_replace_callback(
            '/\s' . preg_quote( $attribute, '/' ) . '=(["\'])(.*?)\1/i',
            function( array $matches ) use ( $attribute ): string {
                $variant = $this->variant_url_for_url( html_entity_decode( $matches[2], ENT_QUOTES ) );
                $url     = $variant ?: $matches[2];

                return ' ' . $attribute . '=' . $matches[1] . esc_url( $url ) . $matches[1];
            },
            $img
        ) ?? $img;
    }

    private function replace_img_srcset_attribute( string $img, string $attribute ): string {
        return preg_replace_callback(
            '/\s' . preg_quote( $attribute, '/' ) . '=(["\'])(.*?)\1/i',
            function( array $matches ) use ( $attribute ): string {
                return ' ' . $attribute . '=' . $matches[1] . esc_attr( $this->replace_srcset_urls( html_entity_decode( $matches[2], ENT_QUOTES ) ) ) . $matches[1];
            },
            $img
        ) ?? $img;
    }

    private function should_skip_img_tag( string $img ): bool {
        $normalized = strtolower( html_entity_decode( $img, ENT_QUOTES ) );

        // Universal skip markers — for lightboxes, galleries, carousels, logos,
        // and menus where rewriting the src can break JS-driven popup behavior.
        $markers = [
            'data-elementor-open-lightbox',
            'elementor-gallery',
            'elementor-lightbox',
            'elementor-widget-image-carousel',
            'e-gallery',
            'swiper',
            'slick',
            'fancybox',
            'lightbox',
            'photoswipe',
            'woocommerce-product-gallery',
            'custom-logo',
            'site-logo',
            'logo-',
            '/logo',
            'menu-item',
            'mega-menu',
            'mobile-menu',

            // Honor explicit "don't touch me" attributes set by themes or builders
            // for above-the-fold hero images. fetchpriority=high signals an LCP
            // candidate — keep the original src so the browser preload picks the
            // right file from any <link rel=preload> the theme also emits.
            'data-no-lazy',
            'data-no-webp',
            'fetchpriority="high"',
            "fetchpriority='high'",
            'fetchpriority=high',
        ];

        foreach ( $markers as $marker ) {
            if ( false !== strpos( $normalized, $marker ) ) {
                return true;
            }
        }

        return (bool) apply_filters( 'nxm_native_delivery_skip_img_tag', false, $img );
    }

    private function is_delivery_disabled( int $attachment_id ): bool {
        return $attachment_id > 0 && (bool) get_post_meta( $attachment_id, '_nxm_delivery_disabled', true );
    }

    private function variant_url_for_url( string $url ): ?string {
        if ( '' === $url || false !== strpos( $url, '.svg' ) || 0 === strpos( $url, 'data:image' ) ) {
            return null;
        }

        $path = $this->url_to_local_path( $url );
        if ( ! $path || ! file_exists( $path ) ) {
            return null;
        }

        $extension = strtolower( pathinfo( strtok( $path, '?' ), PATHINFO_EXTENSION ) );
        if ( ! in_array( $extension, [ 'jpg', 'jpeg', 'png', 'gif' ], true ) ) {
            return null;
        }

        $webp_path = preg_replace( '/\.(jpe?g|png|gif)$/i', '.webp', $path );
        if ( ! $webp_path || ! file_exists( $webp_path ) ) {
            return null;
        }

        if ( filesize( $webp_path ) <= 0 || filesize( $webp_path ) > filesize( $path ) ) {
            return null;
        }

        $variant_url = $this->local_path_to_url( $webp_path );
        if ( ! $variant_url ) {
            return null;
        }

        /**
         * Filter: nxm_variant_resolved
         *
         * Fires every time Media swaps a source URL for a generated variant.
         * Engine subscribes to learn the source→variant mapping during static
         * rendering so it can pre-populate static mirrors with the right asset.
         *
         * @param string $variant_url  Final variant URL to use (e.g. .webp).
         * @param string $original_url Source URL that was requested.
         * @param string $path         Resolved local path of the source.
         * @param string $variant_path Local path of the variant.
         */
        return (string) apply_filters( 'nxm_variant_resolved', $variant_url, $url, $path, $webp_path );
    }

    private function url_to_local_path( string $url ): ?string {
        $uploads  = wp_upload_dir();
        $base_url = trailingslashit( $uploads['baseurl'] );
        $base_dir = wp_normalize_path( trailingslashit( $uploads['basedir'] ) );
        $clean    = strtok( $url, '?' );

        if ( false === $clean || '' === $clean ) {
            return null;
        }

        if ( 0 === strpos( $clean, $base_url ) ) {
            return wp_normalize_path( $base_dir . ltrim( substr( $clean, strlen( $base_url ) ), '/' ) );
        }

        $parsed = wp_parse_url( $clean );
        $path   = $parsed['path'] ?? '';
        if ( false === strpos( $path, '/wp-content/uploads/' ) ) {
            return null;
        }

        $relative = explode( '/wp-content/uploads/', $path, 2 )[1] ?? '';
        return $relative ? wp_normalize_path( $base_dir . ltrim( $relative, '/' ) ) : null;
    }

    private function local_path_to_url( string $path ): ?string {
        $uploads  = wp_upload_dir();
        $base_dir = wp_normalize_path( trailingslashit( $uploads['basedir'] ) );
        $path     = wp_normalize_path( $path );

        if ( 0 !== strpos( $path, $base_dir ) ) {
            return null;
        }

        $relative = ltrim( substr( $path, strlen( $base_dir ) ), '/' );
        return trailingslashit( $uploads['baseurl'] ) . str_replace( '\\', '/', $relative );
    }
}
