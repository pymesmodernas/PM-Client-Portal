<?php
/**
 * PMP_Settings — Acceso centralizado a las opciones del plugin.
 */
defined( 'ABSPATH' ) || exit;

class PMP_Settings {

    public static function get_api_key(): string {
        return trim( (string) get_option( 'pmp_api_key', '' ) );
    }

    public static function get_api_url(): string {
        return rtrim( trim( (string) get_option( 'pmp_api_url', '' ) ), '/' );
    }

    public static function get_credits_shop_url(): string {
        $custom = trim( (string) get_option( 'pmp_credits_url', '' ) );
        return $custom ?: 'https://operaciones.pymesmodernas.com/producto/creditos/';
    }

    /** Verifica que el plugin está configurado (tiene API key y URL) */
    public static function is_configured(): bool {
        return self::get_api_key() !== '' && self::get_api_url() !== '';
    }

    /** Construye la URL completa del endpoint del portal */
    public static function endpoint_url( string $path ): string {
        return self::get_api_url() . '/wp-json/pm/v1/' . ltrim( $path, '/' );
    }
}
