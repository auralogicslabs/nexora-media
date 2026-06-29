<?php
/**
 * Nexora Media — Adaptive Frontend Delivery (HTML Rewriter)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NXM_Html_Rewriter {

    private static ?NXM_Html_Rewriter $instance = null;

    public static function get_instance(): NXM_Html_Rewriter {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if ( class_exists( 'NXM_Init' ) && ! NXM_Init::is_frontend_delivery_request() ) {
            return;
        }

        if ( ! get_option( 'nxm_enable_adaptive', false ) ) {
            return;
        }

        // Engine takeover: when Nexora Engine is active and its SSG runtime is
        // enabled, Engine handles inline-CSS rewriting during static render so
        // Media stands down to avoid double-processing the same markup.
        if ( $this->engine_handles_inline_css() ) {
            return;
        }

        add_filter( 'the_content', [ $this, 'rewrite_content' ], 999 );

        if ( get_option( 'nxm_enable_dom_rewrite', false ) ) {
            add_action( 'template_redirect', [ $this, 'start_buffer' ], 1 );
        }
    }

    /**
     * Detect whether Nexora Engine SSG is taking over inline-CSS rewriting.
     * Filterable so site owners and Engine itself can override the decision.
     */
    private function engine_handles_inline_css(): bool {
        $auto = false;
        if ( class_exists( 'NCX_SSG' ) && method_exists( 'NCX_SSG', 'is_enabled' ) && NCX_SSG::is_enabled() ) {
            $auto = true;
        }
        /**
         * Filter: should Nexora Engine handle inline-CSS image rewriting?
         *
         * Engine should add_filter('nxm_engine_handles_inline_css','__return_true')
         * when its SSG rewriter is wired up. Site owners can override either way.
         */
        return (bool) apply_filters( 'nxm_engine_handles_inline_css', $auto );
    }

    public function start_buffer(): void {
        if ( class_exists( 'NXM_Init' ) && ! NXM_Init::is_frontend_delivery_request() ) {
            return;
        }

        ob_start( [ $this, 'rewrite_content' ] );
    }

    /**
     * Finds <img> tags and wraps them in <picture> with AVIF/WebP sources.
     */
    public function rewrite_content( string $content ): string {
        if ( class_exists( 'NXM_Init' ) && ! NXM_Init::is_frontend_delivery_request() ) {
            return $content;
        }

        if ( empty( $content ) || $this->looks_like_builder_markup( $content ) ) {
            return $content;
        }

        // Quick check to avoid regex if no images
        if ( strpos( $content, '<img' ) === false && strpos( $content, 'url(' ) === false ) {
            return $content;
        }

        $avif_enabled = get_option( 'nxm_enable_avif', true ) && NXM_Feature_Gate::can_use( 'avif_generation' );
        $webp_enabled = get_option( 'nxm_enable_webp', true );

        if ( ! $avif_enabled && ! $webp_enabled ) {
            return $content;
        }

        $content = $this->rewrite_background_urls( $content, $avif_enabled, $webp_enabled );

        $pattern = '/<img\b[^>]*>/i';

        return preg_replace_callback( $pattern, function( $matches ) use ( $avif_enabled, $webp_enabled ) {
            $img_tag = $matches[0];

            if ( stripos( $img_tag, 'data-nxm-adaptive=' ) !== false ) {
                return $img_tag;
            }

            if ( $this->should_leave_img_tag_untouched( $img_tag ) ) {
                return $img_tag;
            }

            // Extract src and srcset
            preg_match( '/src=["\']([^"\']+)["\']/i', $img_tag, $src_match );
            preg_match( '/srcset=["\']([^"\']+)["\']/i', $img_tag, $srcset_match );

            if ( empty( $src_match[1] ) ) {
                return $img_tag;
            }

            $src    = $src_match[1];
            $srcset = ! empty( $srcset_match[1] ) ? $srcset_match[1] : '';

            // Skip SVGs and base64
            if ( strpos( $src, '.svg' ) !== false || strpos( $src, 'data:image' ) !== false ) {
                return $img_tag;
            }

            $sources = '';

            // AVIF Source
            if ( $avif_enabled ) {
                $avif_srcset = $this->generate_variant_srcset( $srcset ?: $src, 'avif' );
                if ( $avif_srcset !== '' ) {
                    $sources .= '<source type="image/avif" srcset="' . esc_attr( $avif_srcset ) . '">';
                }
            }

            // WebP Source
            if ( $webp_enabled ) {
                $webp_srcset = $this->generate_variant_srcset( $srcset ?: $src, 'webp' );
                if ( $webp_srcset !== '' ) {
                    $sources .= '<source type="image/webp" srcset="' . esc_attr( $webp_srcset ) . '">';
                }
            }

            $img_tag = $this->prepare_img_tag( $img_tag );

            if ( $sources === '' ) {
                return $img_tag;
            }

            return '<picture class="nxm-adaptive">' . $sources . $img_tag . '</picture>';
        }, $content );
    }

    private function rewrite_background_urls( string $content, bool $avif_enabled, bool $webp_enabled ): string {
        if ( strpos( $content, 'url(' ) === false ) {
            return $content;
        }

        return preg_replace_callback( '/url\((["\']?)([^"\')]+?\.(?:jpe?g|png|gif|webp)(?:\?[^"\')]+)?)(["\']?)\)/i', function( $matches ) use ( $avif_enabled, $webp_enabled ) {
            $quote = $matches[1] ?: $matches[3];
            $url   = trim( $matches[2] );

            if ( strpos( $url, 'data:image' ) === 0 || strpos( $url, '.svg' ) !== false ) {
                return $matches[0];
            }

            $format = $avif_enabled ? 'avif' : ( $webp_enabled ? 'webp' : '' );
            if ( $format === '' ) {
                return $matches[0];
            }

            $variant = $this->get_variant_url_for_image_url( $url, $format );
            if ( ! $variant && $format === 'avif' && $webp_enabled ) {
                $variant = $this->get_variant_url_for_image_url( $url, 'webp' );
            }

            if ( ! $variant ) {
                return $matches[0];
            }

            return 'url(' . $quote . esc_url_raw( $variant ) . $quote . ')';
        }, $content );
    }

    private function looks_like_builder_markup( string $content ): bool {
        $normalized = strtolower( $content );
        $builders = [
            'elementor-',
            'data-elementor-',
            'e-con',
            'e-gallery',
            'swiper',
            'slick',
            'et_pb_',
            'fl-builder',
            'bricks-',
            'oxy-',
        ];

        foreach ( $builders as $marker ) {
            if ( strpos( $normalized, $marker ) !== false ) {
                return true;
            }
        }

        return (bool) apply_filters( 'nxm_skip_adaptive_builder_markup', false, $content );
    }

    private function should_leave_img_tag_untouched( string $img_tag ): bool {
        $normalized = strtolower( html_entity_decode( $img_tag, ENT_QUOTES ) );

        $fragile_markers = [
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
        ];

        foreach ( $fragile_markers as $marker ) {
            if ( strpos( $normalized, $marker ) !== false ) {
                return true;
            }
        }

        return (bool) apply_filters( 'nxm_skip_adaptive_img_tag', false, $img_tag );
    }

    private function generate_variant_srcset( string $original_srcset, string $format ): string {
        // e.g. "image-300x200.jpg 300w, image.jpg 800w" -> "image-300x200.avif 300w, image.avif 800w"
        $sources = explode( ',', $original_srcset );
        $new_sources = [];

        foreach ( $sources as $source ) {
            $source = trim( $source );
            $parts = preg_split( '/\s+/', $source );
            $url = $parts[0];
            
            // Replace extension
            $url_parts = pathinfo( $url );
            if ( isset( $url_parts['extension'] ) ) {
                $new_url = $this->get_variant_url_for_image_url( $url, $format );
                if ( ! $new_url ) {
                    $new_url = $this->replace_url_extension( $url, $format );
                }
                
                // CRITICAL FIX: Verify the file actually exists on disk before rewriting the HTML.
                $local_path = $this->get_local_path( $new_url );
                if ( ! $local_path || ! file_exists( $local_path ) ) {
                    continue; // Skip this specific variant if it hasn't been generated yet
                }

                $parts[0] = $new_url;
            }

            $new_sources[] = implode( ' ', $parts );
        }

        return implode( ', ', $new_sources );
    }

    private function replace_url_extension( string $url, string $format ): string {
        $parts = wp_parse_url( $url );
        $path  = $parts['path'] ?? $url;
        $path  = preg_replace( '/\.(jpe?g|png|gif|webp)(\?.*)?$/i', '.' . $format, $path );

        if ( isset( $parts['scheme'], $parts['host'] ) ) {
            $rebuilt = $parts['scheme'] . '://' . $parts['host'];
            if ( isset( $parts['port'] ) ) {
                $rebuilt .= ':' . $parts['port'];
            }
            $rebuilt .= $path;
            if ( isset( $parts['query'] ) ) {
                $rebuilt .= '?' . $parts['query'];
            }
            return $rebuilt;
        }

        return $path;
    }

    private function get_variant_url_for_image_url( string $url, string $format ): ?string {
        $attachment_id = attachment_url_to_postid( $url );
        if ( $attachment_id && get_post_meta( $attachment_id, '_nxm_delivery_disabled', true ) ) {
            return null;
        }

        $variants      = $attachment_id ? get_post_meta( $attachment_id, '_nxm_variants', true ) : [];

        if ( is_array( $variants ) && ! empty( $variants['original'][ $format ] ) ) {
            $variant_url = $this->path_to_url( $variants['original'][ $format ] );
            if ( $variant_url && $this->variant_is_useful( $url, $variants['original'][ $format ] ) ) {
                return $variant_url;
            }
        }

        $candidate = $this->replace_url_extension( $url, $format );
        $path      = $this->get_local_path( $candidate );

        return ( $path && file_exists( $path ) && $this->variant_is_useful( $url, $path ) ) ? $candidate : null;
    }

    private function variant_is_useful( string $original_url, string $variant_path ): bool {
        $original_path = $this->get_local_path( $original_url );
        if ( ! $original_path || ! file_exists( $original_path ) || ! file_exists( $variant_path ) ) {
            return true;
        }

        return filesize( $variant_path ) <= filesize( $original_path );
    }

    private function prepare_img_tag( string $img_tag ): string {
        $is_priority_image = stripos( $img_tag, 'fetchpriority="high"' ) !== false || stripos( $img_tag, "fetchpriority='high'" ) !== false;

        if ( get_option( 'nxm_enable_lazyload', true ) && ! $is_priority_image && stripos( $img_tag, ' loading=' ) === false ) {
            $img_tag = preg_replace( '/<img/i', '<img loading="lazy"', $img_tag, 1 );
        }
        if ( stripos( $img_tag, ' decoding=' ) === false ) {
            $img_tag = preg_replace( '/<img/i', '<img decoding="async"', $img_tag, 1 );
        }
        if ( stripos( $img_tag, ' data-nxm-adaptive=' ) === false ) {
            $img_tag = preg_replace( '/<img/i', '<img data-nxm-adaptive="1"', $img_tag, 1 );
        }
        return $img_tag;
    }

    /**
     * Converts a public URL to a local absolute path for file_exists verification.
     */
    private function get_local_path( string $url ): ?string {
        $upload_dir = wp_upload_dir();
        $base_url   = $upload_dir['baseurl'];
        $base_dir   = $upload_dir['basedir'];

        if ( strpos( $url, $base_url ) === 0 ) {
            return str_replace( $base_url, $base_dir, $url );
        }
        
        // Fallback for relative URLs or missing domains
        $parsed = wp_parse_url( $url );
        if ( isset( $parsed['path'] ) && strpos( $parsed['path'], '/wp-content/uploads/' ) !== false ) {
            $rel_path = explode( '/wp-content/uploads/', $parsed['path'] )[1];
            return trailingslashit( $base_dir ) . ltrim( $rel_path, '/' );
        }

        return null;
    }

    private function path_to_url( string $path ): ?string {
        $upload_dir = wp_upload_dir();
        $base_dir   = wp_normalize_path( $upload_dir['basedir'] );
        $base_url   = $upload_dir['baseurl'];
        $path       = wp_normalize_path( $path );

        if ( strpos( $path, $base_dir ) !== 0 ) {
            return null;
        }

        $relative = ltrim( substr( $path, strlen( $base_dir ) ), '/' );
        return trailingslashit( $base_url ) . str_replace( '\\', '/', $relative );
    }
}
