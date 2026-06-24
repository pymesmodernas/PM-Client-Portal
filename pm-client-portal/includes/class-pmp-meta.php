<?php
/**
 * PMP_Meta — Integración con Meta Ads API (Facebook & Instagram).
 *
 * Autenticación: Access Token de larga duración (System User recomendado, no expira).
 * No requiere Composer — usa wp_remote_get() igual que las demás integraciones.
 *
 * Campos requeridos en Configuración:
 *   - pmp_meta_access_token : Token de acceso (largo plazo o System User)
 *   - pmp_meta_account_id   : ID de la cuenta publicitaria (con o sin prefijo act_)
 *   - pmp_meta_app_id       : App ID de Meta (opcional, activa verificación de token)
 *   - pmp_meta_app_secret   : App Secret de Meta (opcional, activa verificación de token)
 */
defined( 'ABSPATH' ) || exit;

class PMP_Meta {

    const GRAPH_URL = 'https://graph.facebook.com/v21.0/';

    /* ── Configuración ──────────────────────────────────────────────────────── */

    /** Verifica que los campos mínimos estén guardados */
    public static function is_configured(): bool {
        return ! empty( get_option( 'pmp_meta_access_token', '' ) )
            && ! empty( get_option( 'pmp_meta_account_id',   '' ) );
    }

    /** Devuelve el Account ID siempre con el prefijo act_ */
    private static function account_id(): string {
        $id = trim( get_option( 'pmp_meta_account_id', '' ) );
        return str_starts_with( $id, 'act_' ) ? $id : 'act_' . $id;
    }

    /* ── Core API ───────────────────────────────────────────────────────────── */

    /**
     * Ejecuta una llamada GET a la Graph API.
     *
     * Los valores se URL-encodean individualmente para que los strings JSON
     * (time_range, etc.) lleguen intactos a Meta.
     *
     * @param  string $endpoint  Ruta relativa (ej: "act_123/insights")
     * @param  array  $params    Query params (sin access_token)
     * @return array             Respuesta JSON decodificada
     * @throws \RuntimeException Si hay error de red o la API devuelve un error
     */
    private function api_get( string $endpoint, array $params = [] ): array {
        $params['access_token'] = get_option( 'pmp_meta_access_token', '' );

        // Construir query string manualmente para preservar JSON en los valores
        $parts = [];
        foreach ( $params as $k => $v ) {
            $parts[] = rawurlencode( (string) $k ) . '=' . rawurlencode( (string) $v );
        }
        $url = self::GRAPH_URL . ltrim( $endpoint, '/' ) . '?' . implode( '&', $parts );

        $response = wp_remote_get( $url, [ 'timeout' => 20 ] );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( 'Error de red Meta: ' . $response->get_error_message() );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! empty( $body['error'] ) ) {
            $msg      = $body['error']['message']          ?? 'Error desconocido';
            $err_code = $body['error']['code']             ?? $code;
            throw new \RuntimeException( "Meta API error {$err_code}: {$msg}" );
        }

        if ( $code !== 200 ) {
            throw new \RuntimeException( "Meta API HTTP {$code}" );
        }

        return $body;
    }

    /* ── Resumen público ────────────────────────────────────────────────────── */

    /**
     * Resumen completo de Ads para el período.
     * Cacheado 15 min (hoy) / 1 hora (períodos cerrados).
     *
     * @return array ['overview' => [...], 'campaigns' => [...]] o ['error' => '...']
     */
    public function get_summary( string $date_from, string $date_to ): array {
        $cache_key = 'pmp_meta_' . md5( $date_from . $date_to );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) return $cached;

        try {
            $result = [
                'overview'  => $this->fetch_overview(  $date_from, $date_to ),
                'campaigns' => $this->fetch_campaigns( $date_from, $date_to ),
            ];
        } catch ( \RuntimeException $e ) {
            return [ 'overview' => [], 'campaigns' => [], 'error' => $e->getMessage() ];
        }

        $ttl = ( $date_to === date( 'Y-m-d' ) ) ? 15 * MINUTE_IN_SECONDS : HOUR_IN_SECONDS;
        set_transient( $cache_key, $result, $ttl );
        return $result;
    }

    /**
     * Prueba de conexión — verifica el token y hace una llamada real.
     * Si están configurados App ID + App Secret, también muestra la fecha de expiración.
     */
    public function test_connection(): array {
        try {
            $token      = get_option( 'pmp_meta_access_token', '' );
            $app_id     = get_option( 'pmp_meta_app_id',       '' );
            $app_secret = get_option( 'pmp_meta_app_secret',   '' );
            $token_info = '';

            if ( $app_id && $app_secret ) {
                $debug = $this->api_get( 'debug_token', [
                    'input_token'  => $token,
                    'access_token' => "{$app_id}|{$app_secret}",
                ] );
                $td = $debug['data'] ?? [];
                if ( ! empty( $td['is_valid'] ) ) {
                    $exp = (int) ( $td['expires_at'] ?? 0 );
                    if ( $exp === 0 ) {
                        $token_info = ' · Token permanente (System User) ♾️';
                    } else {
                        $days_left  = max( 0, (int) ceil( ( $exp - time() ) / 86400 ) );
                        $token_info = " · Vence en {$days_left} días (" . date( 'd/m/Y', $exp ) . ')';
                    }
                }
            }

            $ov    = $this->fetch_overview( date( 'Y-m-d', strtotime( '-7 days' ) ), date( 'Y-m-d' ) );
            $spend = isset( $ov['spend'] ) ? '$' . number_format( $ov['spend'], 2 ) : '$0.00';
            return [ 'ok' => true, 'message' => "Conexión exitosa ✅ Gasto últimos 7 días: {$spend}{$token_info}." ];

        } catch ( \RuntimeException $e ) {
            return [ 'ok' => false, 'message' => $e->getMessage() ];
        }
    }

    /* ── Fetchers privados ──────────────────────────────────────────────────── */

    /** Métricas globales de la cuenta para el período */
    private function fetch_overview( string $date_from, string $date_to ): array {
        $data = $this->api_get(
            self::account_id() . '/insights',
            [
                'fields'     => 'spend,impressions,reach,clicks,ctr,cpm,cpc,actions,action_values',
                'time_range' => wp_json_encode( [ 'since' => $date_from, 'until' => $date_to ] ),
                'level'      => 'account',
            ]
        );

        if ( empty( $data['data'][0] ) ) return [];

        $row            = $data['data'][0];
        $spend          = (float) ( $row['spend'] ?? 0 );
        $purchases      = self::find_action( $row['actions']       ?? [], 'purchase' );
        $purchase_value = self::find_action( $row['action_values'] ?? [], 'purchase' );

        return [
            'spend'          => round( $spend, 2 ),
            'impressions'    => (int)   ( $row['impressions'] ?? 0 ),
            'reach'          => (int)   ( $row['reach']       ?? 0 ),
            'clicks'         => (int)   ( $row['clicks']      ?? 0 ),
            'ctr'            => round( (float) ( $row['ctr'] ?? 0 ), 2 ),
            'cpm'            => round( (float) ( $row['cpm'] ?? 0 ), 2 ),
            'cpc'            => round( (float) ( $row['cpc'] ?? 0 ), 2 ),
            'purchases'      => (int) $purchases,
            'purchase_value' => round( $purchase_value, 2 ),
            // ROAS: ingresos atribuidos por Meta / gasto (solo si hay datos)
            'roas'           => ( $spend > 0 && $purchase_value > 0 )
                                    ? round( $purchase_value / $spend, 2 )
                                    : null,
            // CPA: gasto / número de compras
            'cpa'            => ( $purchases > 0 )
                                    ? round( $spend / $purchases, 2 )
                                    : null,
        ];
    }

    /** Top campañas ordenadas por gasto descendente */
    private function fetch_campaigns( string $date_from, string $date_to ): array {
        $data = $this->api_get(
            self::account_id() . '/insights',
            [
                'fields'     => 'campaign_name,spend,impressions,clicks,ctr,actions,action_values',
                'time_range' => wp_json_encode( [ 'since' => $date_from, 'until' => $date_to ] ),
                'level'      => 'campaign',
                'limit'      => 10,
            ]
        );

        if ( empty( $data['data'] ) ) return [];

        $campaigns = array_map( function ( $row ) {
            $spend          = (float) ( $row['spend'] ?? 0 );
            $purchases      = self::find_action( $row['actions']       ?? [], 'purchase' );
            $purchase_value = self::find_action( $row['action_values'] ?? [], 'purchase' );

            return [
                'name'           => $row['campaign_name'] ?? 'Sin nombre',
                'spend'          => round( $spend, 2 ),
                'impressions'    => (int)   ( $row['impressions'] ?? 0 ),
                'clicks'         => (int)   ( $row['clicks']      ?? 0 ),
                'ctr'            => round( (float) ( $row['ctr'] ?? 0 ), 2 ),
                'purchases'      => (int) $purchases,
                'purchase_value' => round( $purchase_value, 2 ),
                'roas'           => ( $spend > 0 && $purchase_value > 0 )
                                        ? round( $purchase_value / $spend, 2 )
                                        : null,
            ];
        }, $data['data'] );

        // Ordenar por gasto descendente en PHP (evita parámetro sort con encoding complejo)
        usort( $campaigns, fn( $a, $b ) => $b['spend'] <=> $a['spend'] );

        return array_slice( $campaigns, 0, 6 );
    }

    /* ── Helper ─────────────────────────────────────────────────────────────── */

    /** Extrae el valor de un action_type específico del array de acciones de Meta */
    private static function find_action( array $actions, string $type ): float {
        foreach ( $actions as $action ) {
            if ( ( $action['action_type'] ?? '' ) === $type ) {
                return (float) ( $action['value'] ?? 0 );
            }
        }
        return 0.0;
    }
}
