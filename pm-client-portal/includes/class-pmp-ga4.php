<?php
/**
 * PMP_GA4 — Integración con Google Analytics 4 Data API.
 * Autenticación: Service Account con JWT firmado RS256 (sin Composer ni librerías externas).
 */
defined( 'ABSPATH' ) || exit;

class PMP_GA4 {

    /* ── Configuración ──────────────────────────────────────────────────────── */

    /** Verifica si el JSON de Service Account y el Property ID están guardados */
    public static function is_configured(): bool {
        return ! empty( get_option( 'pmp_ga4_sa_json',    '' ) )
            && ! empty( get_option( 'pmp_ga4_property_id', '' ) );
    }

    /* ── Autenticación ──────────────────────────────────────────────────────── */

    /**
     * Genera un JWT firmado con la clave privada del Service Account y lo intercambia
     * por un Access Token de Google. El token se cachea hasta 1 minuto antes de expirar.
     *
     * @return string        Access Token si todo OK.
     * @throws \RuntimeException  Con el mensaje de error de Google si falla.
     */
    private function get_access_token(): string {
        $cached = get_transient( 'pmp_ga4_access_token' );
        if ( $cached ) return $cached;

        $sa = json_decode( get_option( 'pmp_ga4_sa_json', '' ), true );

        if ( empty( $sa['private_key'] ) || empty( $sa['client_email'] ) ) {
            throw new \RuntimeException( 'Service Account JSON inválido o incompleto.' );
        }

        $now    = time();
        $header = $this->base64url( (string) wp_json_encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] ) );
        $claims = $this->base64url( (string) wp_json_encode( [
            'iss'   => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
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
            $google_error = $body['error']             ?? '';
            $google_desc  = $body['error_description'] ?? '';
            throw new \RuntimeException(
                "Google SA error {$code}: {$google_error}" .
                ( $google_desc ? " — {$google_desc}" : '' )
            );
        }

        $ttl = max( 60, (int) ( $body['expires_in'] ?? 3600 ) - 60 );
        set_transient( 'pmp_ga4_access_token', $body['access_token'], $ttl );

        return $body['access_token'];
    }

    /** Codificación Base64 URL-safe sin relleno */
    private function base64url( string $data ): string {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }

    /* ── API ────────────────────────────────────────────────────────────────── */

    /**
     * Ejecuta un reporte arbitrario en la GA4 Data API.
     *
     * @param  array  $body  Cuerpo del request (dateRanges, dimensions, metrics…).
     * @return array         Respuesta decodificada.
     * @throws \RuntimeException  Si hay error de red o la API devuelve un error.
     */
    private function run_report( array $body ): array {
        $token = $this->get_access_token(); // lanza RuntimeException si falla

        $property_id = get_option( 'pmp_ga4_property_id', '' );
        $url         = "https://analyticsdata.googleapis.com/v1beta/properties/{$property_id}:runReport";

        $response = wp_remote_post( $url, [
            'timeout' => 20,
            'headers' => [
                'Authorization' => "Bearer {$token}",
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( 'Error de red GA4: ' . $response->get_error_message() );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            $msg = $data['error']['message'] ?? "HTTP {$code}";
            throw new \RuntimeException( "GA4 API error: {$msg}" );
        }

        return $data;
    }

    /* ── Resumen público ────────────────────────────────────────────────────── */

    /**
     * Devuelve el resumen completo de GA4 para el período dado.
     * Los datos se cachean 15 min (hoy) o 1 hora (períodos cerrados).
     *
     * @param  string $date_from  Y-m-d
     * @param  string $date_to    Y-m-d
     * @return array              ['overview' => […], 'channels' => […], 'top_pages' => […]]
     */
    public function get_summary( string $date_from, string $date_to ): array {
        $cache_key = 'pmp_ga4_v2_' . md5( $date_from . '_' . $date_to );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) return $cached;

        try {
            $result = [
                'overview'     => $this->fetch_overview(     $date_from, $date_to ),
                'channels'     => $this->fetch_channels(     $date_from, $date_to ),
                'top_pages'    => $this->fetch_top_pages(    $date_from, $date_to ),
                'devices'      => $this->fetch_devices(      $date_from, $date_to ),
                'retention'    => $this->fetch_retention(    $date_from, $date_to ),
                'events'       => $this->fetch_events(       $date_from, $date_to ),
                'search_terms' => $this->fetch_search_terms( $date_from, $date_to ),
            ];
        } catch ( \RuntimeException $e ) {
            // Si GA4 falla, devolvemos vacío — el análisis de IA continúa sin datos de GA
            return [ 'overview' => [], 'channels' => [], 'top_pages' => [], 'error' => $e->getMessage() ];
        }

        $ttl = ( $date_to === date( 'Y-m-d' ) ) ? 15 * MINUTE_IN_SECONDS : HOUR_IN_SECONDS;
        set_transient( $cache_key, $result, $ttl );

        return $result;
    }

    /**
     * Prueba rápida de conectividad — devuelve ['ok' => bool, 'message' => string].
     * El mensaje incluye el error exacto de Google para facilitar el diagnóstico.
     */
    public function test_connection(): array {
        try {
            $data     = $this->run_report( [
                'dateRanges' => [ [ 'startDate' => '7daysAgo', 'endDate' => 'today' ] ],
                'metrics'    => [ [ 'name' => 'sessions' ] ],
                'limit'      => 1,
            ] );
            $sessions = (int) ( $data['rows'][0]['metricValues'][0]['value'] ?? 0 );
            return [ 'ok' => true, 'message' => "Conexión exitosa ✅ Sesiones últimos 7 días: {$sessions}." ];
        } catch ( \RuntimeException $e ) {
            return [ 'ok' => false, 'message' => $e->getMessage() ];
        }
    }

    /* ── Fetchers privados ──────────────────────────────────────────────────── */

    /** Métricas globales de tráfico para el período */
    private function fetch_overview( string $date_from, string $date_to ): array {
        $data = $this->run_report( [
            'dateRanges' => [ [ 'startDate' => $date_from, 'endDate' => $date_to ] ],
            'metrics'    => [
                [ 'name' => 'sessions' ],
                [ 'name' => 'newUsers' ],
                [ 'name' => 'activeUsers' ],
                [ 'name' => 'bounceRate' ],
                [ 'name' => 'averageSessionDuration' ],
                [ 'name' => 'engagementRate' ],
                [ 'name' => 'conversions' ],
            ],
        ] );

        if ( empty( $data['rows'][0]['metricValues'] ) ) return [];

        $v = $data['rows'][0]['metricValues'];
        return [
            'sessions'        => (int)   $v[0]['value'],
            'new_users'       => (int)   $v[1]['value'],
            'active_users'    => (int)   $v[2]['value'],
            'bounce_rate'     => round( (float) $v[3]['value'] * 100, 1 ),
            'avg_session_min' => round( (float) $v[4]['value'] / 60,  1 ),
            'engagement_rate' => round( (float) $v[5]['value'] * 100, 1 ),
            'conversions'     => (int)   $v[6]['value'],
        ];
    }

    /** Sesiones, conversiones y engagement por canal de tráfico */
    private function fetch_channels( string $date_from, string $date_to ): array {
        $data = $this->run_report( [
            'dateRanges' => [ [ 'startDate' => $date_from, 'endDate' => $date_to ] ],
            'dimensions' => [ [ 'name' => 'sessionDefaultChannelGroup' ] ],
            'metrics'    => [
                [ 'name' => 'sessions' ],
                [ 'name' => 'conversions' ],
                [ 'name' => 'engagementRate' ],
            ],
            'orderBys' => [ [ 'metric' => [ 'metricName' => 'sessions' ], 'desc' => true ] ],
            'limit'    => 8,
        ] );

        if ( empty( $data['rows'] ) ) return [];

        return array_map( fn( $row ) => [
            'channel'         => $row['dimensionValues'][0]['value'],
            'sessions'        => (int)   $row['metricValues'][0]['value'],
            'conversions'     => (int)   $row['metricValues'][1]['value'],
            'engagement_rate' => round( (float) $row['metricValues'][2]['value'] * 100, 1 ),
        ], $data['rows'] );
    }

    /** Páginas más visitadas con métricas de calidad */
    private function fetch_top_pages( string $date_from, string $date_to ): array {
        $data = $this->run_report( [
            'dateRanges' => [ [ 'startDate' => $date_from, 'endDate' => $date_to ] ],
            'dimensions' => [ [ 'name' => 'pagePath' ] ],
            'metrics'    => [
                [ 'name' => 'screenPageViews' ],
                [ 'name' => 'bounceRate' ],
                [ 'name' => 'averageSessionDuration' ],
                [ 'name' => 'conversions' ],
            ],
            'orderBys' => [ [ 'metric' => [ 'metricName' => 'screenPageViews' ], 'desc' => true ] ],
            'limit'    => 12,
        ] );

        if ( empty( $data['rows'] ) ) return [];

        return array_map( fn( $row ) => [
            'path'        => $row['dimensionValues'][0]['value'],
            'views'       => (int)   $row['metricValues'][0]['value'],
            'bounce_rate' => round( (float) $row['metricValues'][1]['value'] * 100, 1 ),
            'avg_time_s'  => (int) round( (float) $row['metricValues'][2]['value'] ),
            'conversions' => (int)   $row['metricValues'][3]['value'],
        ], $data['rows'] );
    }

    /** Sesiones, engagement y conversiones por tipo de dispositivo */
    private function fetch_devices( string $date_from, string $date_to ): array {
        $data = $this->run_report( [
            'dateRanges' => [ [ 'startDate' => $date_from, 'endDate' => $date_to ] ],
            'dimensions' => [ [ 'name' => 'deviceCategory' ] ],
            'metrics'    => [
                [ 'name' => 'sessions' ],
                [ 'name' => 'engagementRate' ],
                [ 'name' => 'conversions' ],
            ],
            'orderBys' => [ [ 'metric' => [ 'metricName' => 'sessions' ], 'desc' => true ] ],
        ] );

        if ( empty( $data['rows'] ) ) return [];

        return array_map( fn( $row ) => [
            'device'          => $row['dimensionValues'][0]['value'],
            'sessions'        => (int)   $row['metricValues'][0]['value'],
            'engagement_rate' => round( (float) $row['metricValues'][1]['value'] * 100, 1 ),
            'conversions'     => (int)   $row['metricValues'][2]['value'],
        ], $data['rows'] );
    }

    /** Usuarios nuevos vs recurrentes */
    private function fetch_retention( string $date_from, string $date_to ): array {
        $data = $this->run_report( [
            'dateRanges' => [ [ 'startDate' => $date_from, 'endDate' => $date_to ] ],
            'dimensions' => [ [ 'name' => 'newVsReturning' ] ],
            'metrics'    => [
                [ 'name' => 'sessions' ],
                [ 'name' => 'engagementRate' ],
                [ 'name' => 'conversions' ],
            ],
        ] );

        if ( empty( $data['rows'] ) ) return [];

        return array_map( fn( $row ) => [
            'type'            => $row['dimensionValues'][0]['value'],
            'sessions'        => (int)   $row['metricValues'][0]['value'],
            'engagement_rate' => round( (float) $row['metricValues'][1]['value'] * 100, 1 ),
            'conversions'     => (int)   $row['metricValues'][2]['value'],
        ], $data['rows'] );
    }

    /** Eventos marcados como conversiones en GA4 */
    private function fetch_events( string $date_from, string $date_to ): array {
        $data = $this->run_report( [
            'dateRanges'   => [ [ 'startDate' => $date_from, 'endDate' => $date_to ] ],
            'dimensions'   => [ [ 'name' => 'eventName' ] ],
            'metrics'      => [
                [ 'name' => 'eventCount' ],
                [ 'name' => 'conversions' ],
            ],
            'metricFilter' => [
                'filter' => [
                    'fieldName'     => 'conversions',
                    'numericFilter' => [
                        'operation' => 'GREATER_THAN',
                        'value'     => [ 'int64Value' => '0' ],
                    ],
                ],
            ],
            'orderBys' => [ [ 'metric' => [ 'metricName' => 'conversions' ], 'desc' => true ] ],
            'limit'    => 10,
        ] );

        if ( empty( $data['rows'] ) ) return [];

        return array_map( fn( $row ) => [
            'event'       => $row['dimensionValues'][0]['value'],
            'count'       => (int) $row['metricValues'][0]['value'],
            'conversions' => (int) $row['metricValues'][1]['value'],
        ], $data['rows'] );
    }

    /** Términos de búsqueda interna del sitio */
    private function fetch_search_terms( string $date_from, string $date_to ): array {
        $data = $this->run_report( [
            'dateRanges'      => [ [ 'startDate' => $date_from, 'endDate' => $date_to ] ],
            'dimensions'      => [ [ 'name' => 'searchTerm' ] ],
            'metrics'         => [
                [ 'name' => 'sessions' ],
                [ 'name' => 'conversions' ],
            ],
            'dimensionFilter' => [
                'notExpression' => [
                    'filter' => [
                        'fieldName'    => 'searchTerm',
                        'stringFilter' => [ 'matchType' => 'EXACT', 'value' => '(not set)' ],
                    ],
                ],
            ],
            'orderBys' => [ [ 'metric' => [ 'metricName' => 'sessions' ], 'desc' => true ] ],
            'limit'    => 10,
        ] );

        if ( empty( $data['rows'] ) ) return [];

        return array_map( fn( $row ) => [
            'term'        => $row['dimensionValues'][0]['value'],
            'sessions'    => (int) $row['metricValues'][0]['value'],
            'conversions' => (int) $row['metricValues'][1]['value'],
        ], $data['rows'] );
    }

    /* ── Helper de formato ──────────────────────────────────────────────────── */

    /** Convierte segundos a formato legible "Xm Ys" */
    public static function format_duration( int $seconds ): string {
        if ( $seconds < 60 ) return "{$seconds}s";
        return intdiv( $seconds, 60 ) . 'm ' . ( $seconds % 60 ) . 's';
    }
}
