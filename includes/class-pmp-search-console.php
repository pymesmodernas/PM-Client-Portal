<?php
/**
 * PMP_SearchConsole — Integración con Google Search Console API.
 * Reutiliza el mismo Service Account JSON de GA4.
 * Muestra keywords, posiciones y páginas de tráfico orgánico.
 */
defined( 'ABSPATH' ) || exit;

class PMP_SearchConsole {

    /* ── Configuración ──────────────────────────────────────────────────────── */

    public static function is_configured(): bool {
        return ! empty( get_option( 'pmp_ga4_sa_json',  '' ) )
            && ! empty( get_option( 'pmp_gsc_site_url', '' ) );
    }

    /* ── Autenticación ──────────────────────────────────────────────────────── */

    private function get_access_token(): string {
        $cached = get_transient( 'pmp_gsc_access_token' );
        if ( $cached ) return $cached;

        $sa = json_decode( get_option( 'pmp_ga4_sa_json', '' ), true );

        if ( empty( $sa['private_key'] ) || empty( $sa['client_email'] ) ) {
            throw new \RuntimeException( 'Service Account JSON inválido o incompleto.' );
        }

        $now    = time();
        $header = $this->base64url( (string) wp_json_encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] ) );
        $claims = $this->base64url( (string) wp_json_encode( [
            'iss'   => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'exp'   => $now + 3600,
            'iat'   => $now,
        ] ) );

        openssl_sign( "{$header}.{$claims}", $signature, $sa['private_key'], 'SHA256' );
        $jwt = "{$header}.{$claims}." . $this->base64url( $signature );

        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', [
            'timeout' => 15,
            'body'    => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( 'Error de red: ' . $response->get_error_message() );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 || empty( $body['access_token'] ) ) {
            $err  = $body['error']             ?? '';
            $desc = $body['error_description'] ?? '';
            throw new \RuntimeException(
                "Google SA error {$code}: {$err}" . ( $desc ? " — {$desc}" : '' )
            );
        }

        $ttl = max( 60, (int) ( $body['expires_in'] ?? 3600 ) - 60 );
        set_transient( 'pmp_gsc_access_token', $body['access_token'], $ttl );

        return $body['access_token'];
    }

    private function base64url( string $data ): string {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }

    /* ── API ────────────────────────────────────────────────────────────────── */

    private function query( array $body ): array {
        $token    = $this->get_access_token();
        $site_url = get_option( 'pmp_gsc_site_url', '' );
        $url      = 'https://searchconsole.googleapis.com/webmasters/v3/sites/'
                    . rawurlencode( $site_url ) . '/searchAnalytics/query';

        $response = wp_remote_post( $url, [
            'timeout' => 20,
            'headers' => [
                'Authorization' => "Bearer {$token}",
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( 'Error de red GSC: ' . $response->get_error_message() );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            $msg = $data['error']['message'] ?? "HTTP {$code}";
            throw new \RuntimeException( "Search Console API error: {$msg}" );
        }

        return $data;
    }

    /* ── Resumen público ────────────────────────────────────────────────────── */

    public function get_summary( string $date_from, string $date_to ): array {
        // Search Console tiene retraso de ~3 días — ajustamos date_to para evitar rechazos
        $gsc_date_to = gmdate( 'Y-m-d', min(
            strtotime( $date_to ),
            strtotime( '-3 days' )
        ) );
        if ( $gsc_date_to < $date_from ) {
            $gsc_date_to = $date_from;
        }

        $cache_key = 'pmp_gsc_v1_' . md5( $date_from . '_' . $gsc_date_to );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) return $cached;

        try {
            $result = [
                'queries' => $this->fetch_top_queries( $date_from, $gsc_date_to ),
                'pages'   => $this->fetch_top_pages(   $date_from, $gsc_date_to ),
            ];
        } catch ( \RuntimeException $e ) {
            return [ 'queries' => [], 'pages' => [], 'error' => $e->getMessage() ];
        }

        $ttl = ( $date_to === date( 'Y-m-d' ) ) ? 15 * MINUTE_IN_SECONDS : HOUR_IN_SECONDS;
        set_transient( $cache_key, $result, $ttl );

        return $result;
    }

    public function test_connection(): array {
        try {
            $data = $this->query( [
                'startDate'  => date( 'Y-m-d', strtotime( '-7 days' ) ),
                'endDate'    => date( 'Y-m-d', strtotime( '-1 day' ) ),
                'dimensions' => [ 'query' ],
                'rowLimit'   => 1,
            ] );
            $rows = count( $data['rows'] ?? [] );
            return [ 'ok' => true, 'message' => "Conexión exitosa ✅ Keywords encontradas: {$rows}+" ];
        } catch ( \RuntimeException $e ) {
            return [ 'ok' => false, 'message' => $e->getMessage() ];
        }
    }

    /* ── Fetchers privados ──────────────────────────────────────────────────── */

    private function fetch_top_queries( string $date_from, string $date_to ): array {
        $data = $this->query( [
            'startDate'  => $date_from,
            'endDate'    => $date_to,
            'dimensions' => [ 'query' ],
            'orderBy'    => [ [ 'fieldName' => 'clicks', 'sortOrder' => 'DESCENDING' ] ],
            'rowLimit'   => 20,
        ] );

        if ( empty( $data['rows'] ) ) return [];

        return array_map( fn( $row ) => [
            'query'       => $row['keys'][0],
            'clicks'      => (int)   $row['clicks'],
            'impressions' => (int)   $row['impressions'],
            'ctr'         => round( $row['ctr'] * 100, 1 ),
            'position'    => round( $row['position'], 1 ),
        ], $data['rows'] );
    }

    private function fetch_top_pages( string $date_from, string $date_to ): array {
        $data = $this->query( [
            'startDate'  => $date_from,
            'endDate'    => $date_to,
            'dimensions' => [ 'page' ],
            'orderBy'    => [ [ 'fieldName' => 'clicks', 'sortOrder' => 'DESCENDING' ] ],
            'rowLimit'   => 8,
        ] );

        if ( empty( $data['rows'] ) ) return [];

        return array_map( fn( $row ) => [
            'page'        => $row['keys'][0],
            'clicks'      => (int)   $row['clicks'],
            'impressions' => (int)   $row['impressions'],
            'ctr'         => round( $row['ctr'] * 100, 1 ),
            'position'    => round( $row['position'], 1 ),
        ], $data['rows'] );
    }
}
