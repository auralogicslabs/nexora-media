<?php
/**
 * Nexora Media — Feature Gate & Entitlements
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NXMEDIA_Feature_Gate {

    private static ?NXMEDIA_Feature_Gate $instance = null;

    public static function get_instance(): NXMEDIA_Feature_Gate {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Nexora Media is fully free — every feature ships to everyone. This gate
     * is kept as a thin, stable seam so call sites don't have to change, but it
     * grants all capabilities. (No Freemius, no paid tier, no crippled free
     * build — matches the readme's "Pro extensions: there aren't any.")
     */
    public static function is_pro(): bool {
        return true;
    }

    /**
     * Legacy alias retained for call-site compatibility.
     */
    public static function is_agency(): bool {
        return self::is_pro();
    }

    /**
     * Every feature is available. Kept so existing `can_use('…')` call sites
     * (e.g. avif_generation) keep working without edits.
     */
    public static function can_use( string $feature ): bool {
        return true;
    }
}
