<?php

defined( 'ABSPATH' ) || exit;

class SkyHSHOSO_Logger {

    const SOURCE = 'skyhs-hosting-solution';

    public static function is_enabled() {
        $options = get_option( 'skyhshoso_settings_group', array() );
        return ! empty( $options['enable_wc_log'] );
    }

    public static function log( $message, $level = 'notice', $context = array() ) {
        if ( ! self::is_enabled() ) {
            return;
        }
        if ( function_exists( 'wc_get_logger' ) ) {
            $logger = wc_get_logger();
            $logger->log( $level, $message, array_merge( array( 'source' => self::SOURCE ), $context ) );
        }
    }

    public static function error( $message, $context = array() ) {
        self::log( $message, 'error', $context );
    }

    public static function warning( $message, $context = array() ) {
        self::log( $message, 'warning', $context );
    }

    public static function info( $message, $context = array() ) {
        self::log( $message, 'info', $context );
    }
}
