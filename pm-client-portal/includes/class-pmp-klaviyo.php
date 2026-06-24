<?php
/**
 * PMP_Klaviyo — Integración con Klaviyo (Email Marketing).
 *
 * Autenticación: Private API Key (no requiere OAuth).
 * No requiere Composer — usa wp_remote_get() / wp_remote_post().
 *
 * Campo requerido en Configuración:
 *   - pmp_klaviyo_api_key : Private API Key de Klaviyo (Settings → Account → API Keys)
 */
defined( 'ABSPATH' ) || exit;

class PMP_Klaviyo {

    const API_URL  = 'https://a.klaviyo.com/api/';
    const REVISION = '2024-10-15';

    /** Nombres estándar de métricas de email en Klaviyo */
    const METRIC_NAMES = [
        'received'     => 'Received Email',
        'opened'       => 'Opened Email',
        'clicked'      => 'Clicked Email',
        'unsubscribed' => 'Unsubscribed',
        'placed_order' => 'Placed Order',
    ];

    /* ── Configuración ──────────────────────────────────────────────────────── */

    public static function is_configured(): bool {
        return ! empty( get_option( 'pmp_klaviyo_api_key', '' ) );
    }

    /* ── Headers comunes ────────────────────────────────────────────────────── */

    private function headers(): array {
        return [
            'Authorization' => 'Klaviyo-API-Key ' . trim( get_option( 'pmp_klaviyo_api_key', '' ) ),
            'revision'      => self::REVISION,
            'Accept'        => 'application/vnd.api+json',
        ];
    }

    /* ── Core API ───────────────────────────────────────────────────────────── */

    /**
     * Construye una query string con corchetes SIN codificar en las claves.
     * Klaviyo (JSON:API) requiere page[size]=200, no page%5Bsize%5D=200.
     * http_build_query codifica los corchetes y el servidor los convierte a
     * page_size, causando error 400.
     */
    private function build_query( array $params, string $prefix = '' ): string {
        $parts = [];
        foreach ( $params as $key => $value ) {
            $full_key = $prefix !== '' ? "{$prefix}[{$key}]" : (string) $key;
            if ( is_array( $value ) ) {
                $nested = $this->build_query( $value, $full_key );
                if ( $nested !== '' ) {
                    $parts[] = $nested;
                }
            } else {
                $parts[] = $full_key . '=' . rawurlencode( (string) $value );
            }
        }
        return implode( '&', $parts );
    }

    /**
     * GET a la Klaviyo API.
     * Los parámetros anidados (ej: ['page'=>['size'=>200]]) se encodean
     * como page[size]=200 (corchetes literales, no codificados), que es lo
     * que espera Klaviyo / JSON:API.
     *
     * @throws \RuntimeException Si hay error de red o la API devuelve un error.
     */
    private function api_get( string $endpoint, array $params = [] ): array {
        $url = self::API_URL . ltrim( $endpoint, '/' );
        if ( ! empty( $params ) ) {
            $url .= '?' . $this->build_query( $params );
        }

        $response = wp_remote_get( $url, [
            'timeout' => 20,
            'headers' => $this->headers(),
        ] );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( 'Error de red Klaviyo: ' . $response->get_error_message() );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 401 || $code === 403 ) {
            throw new \RuntimeException( "Klaviyo error {$code}: API Key inválida o sin permisos suficientes." );
        }
        if ( $code >= 400 ) {
            $msg = $body['errors'][0]['detail'] ?? $body['errors'][0]['title'] ?? "HTTP {$code}";
            throw new \RuntimeException( "Klaviyo API error {$code}: {$msg}" );
        }

        return (array) $body;
    }

    /**
     * POST a la Klaviyo API (JSON:API format).
     * El body se envía como JSON con Content-Type application/vnd.api+json.
     *
     * @throws \RuntimeException Si hay error de red o la API devuelve un error.
     */
    private function api_post( string $endpoint, array $body, int $retry = 1 ): array {
        $url = self::API_URL . ltrim( $endpoint, '/' );

        $args = [
            'timeout' => 25,
            'headers' => array_merge( $this->headers(), [
                'Content-Type' => 'application/vnd.api+json',
            ] ),
            'body' => wp_json_encode( $body ),
        ];

        $response = wp_remote_post( $url, $args );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( 'Error de red Klaviyo: ' . $response->get_error_message() );
        }

        $code         = (int) wp_remote_retrieve_response_code( $response );
        $body_decoded = json_decode( wp_remote_retrieve_body( $response ), true );

        // Rate limit — espera 1.5 s y reintenta una vez
        if ( $code === 429 && $retry > 0 ) {
            usleep( 1500000 ); // 1.5 segundos
            return $this->api_post( $endpoint, $body, $retry - 1 );
        }

        if ( $code === 401 || $code === 403 ) {
            throw new \RuntimeException( "Klaviyo error {$code}: API Key inválida o sin permisos suficientes." );
        }
        if ( $code >= 400 ) {
            $msg = $body_decoded['errors'][0]['detail'] ?? $body_decoded['errors'][0]['title'] ?? "HTTP {$code}";
            throw new \RuntimeException( "Klaviyo API error {$code}: {$msg}" );
        }

        return (array) $body_decoded;
    }

    /* ── Metric IDs (cacheados 24 h) ────────────────────────────────────────── */

    /**
     * Obtiene los IDs de las métricas estándar de email de la cuenta.
     * Se cachean 24 horas — los IDs de métricas no cambian.
     */
    private function get_metric_ids(): array {
        $cached = get_transient( 'pmp_klaviyo_metric_ids' );
        // Ignorar caché vacía (puede ser resultado de un intento fallido anterior)
        if ( $cached !== false && ! empty( $cached ) ) return (array) $cached;

        $ids    = [];
        $cursor = null;

        // Paginar con cursor hasta encontrar todas las métricas (máx. 6 páginas ≈ 300 métricas)
        for ( $page = 0; $page < 6; $page++ ) {
            // Sin page[size] ni fields[] — solo cursor en páginas siguientes
            $params = $cursor ? [ 'page' => [ 'cursor' => $cursor ] ] : [];
            $data   = $this->api_get( 'metrics/', $params );

            foreach ( $data['data'] ?? [] as $metric ) {
                $name = $metric['attributes']['name'] ?? '';
                foreach ( self::METRIC_NAMES as $key => $target ) {
                    if ( $name === $target ) {
                        $ids[ $key ] = $metric['id'];
                    }
                }
            }

            // Si ya tenemos todas las métricas objetivo, parar
            if ( count( $ids ) >= count( self::METRIC_NAMES ) ) break;

            // Extraer cursor del link "next" para la siguiente página
            $next_url = $data['links']['next'] ?? null;
            if ( ! $next_url ) break;

            $query = parse_url( $next_url, PHP_URL_QUERY ) ?? '';
            // El cursor puede venir como page[cursor] o page%5Bcursor%5D
            $query_decoded = str_replace( [ 'page%5Bcursor%5D', 'page[cursor]' ], '__cur__', $query );
            parse_str( $query_decoded, $qp );
            $cursor = $qp['__cur__'] ?? null;
            if ( ! $cursor ) break;
        }

        set_transient( 'pmp_klaviyo_metric_ids', $ids, 24 * HOUR_IN_SECONDS );
        return $ids;
    }

    /* ── Aggregate helper ───────────────────────────────────────────────────── */

    /**
     * Llama a /api/metric-aggregates/ para un métric ID y período.
     * Devuelve la respuesta completa (para que sum_metric() / sum_by_dimension() la procesen).
     *
     * @param  string   $metric_id
     * @param  string   $date_from     Formato Y-m-d
     * @param  string   $date_to       Formato Y-m-d
     * @param  string[] $measurements  Ej: ['count'] o ['count', 'sum_value']
     * @param  string[] $by            Dimensiones para agrupar (ej: ['$attributed_source_type'])
     */
    private function aggregate(
        string $metric_id,
        string $date_from,
        string $date_to,
        array  $measurements = [ 'count' ],
        array  $by = []
    ): array {
        // Klaviyo solo soporta 'greater-or-equal' y 'less-than' en datetime.
        // Para incluir todo el día $date_to usamos less-than del día siguiente.
        $date_to_exclusive = gmdate( 'Y-m-d', strtotime( $date_to . ' +1 day' ) );

        $attrs = [
            'metric_id'    => $metric_id,
            'measurements' => $measurements,
            'interval'     => 'day',
            'page_size'    => 500,
            'filter'       => [
                "greater-or-equal(datetime,{$date_from}T00:00:00+00:00)",
                "less-than(datetime,{$date_to_exclusive}T00:00:00+00:00)",
            ],
            'timezone'     => 'UTC',
        ];

        if ( ! empty( $by ) ) {
            $attrs['by'] = $by;
        }

        return $this->api_post( 'metric-aggregates/', [
            'data' => [
                'type'       => 'metric-aggregate',
                'attributes' => $attrs,
            ],
        ] );
    }

    /** Suma todos los valores de una medición del response de metric-aggregates (total sin agrupar) */
    private function sum_metric( array $response, string $measurement = 'count' ): float {
        $total = 0.0;
        $rows  = $response['data']['attributes']['data'] ?? [];
        if ( empty( $rows ) ) return 0.0;
        foreach ( $rows[0]['measurements'][ $measurement ] ?? [] as $val ) {
            $total += (float) $val;
        }
        return $total;
    }

    /**
     * Suma una estadística de los resultados de un values-report (flow o campaign).
     * El response tiene la forma:
     *   data.attributes.results[].statistics.{stat_name}
     */
    private function sum_values_report( array $response, string $stat ): float {
        $total = 0.0;
        foreach ( $response['data']['attributes']['results'] ?? [] as $row ) {
            $total += (float) ( $row['statistics'][ $stat ] ?? 0 );
        }
        return $total;
    }

    /* ── Fetchers privados ──────────────────────────────────────────────────── */

    /** Métricas globales de email marketing para el período */
    private function fetch_overview( string $date_from, string $date_to ): array {
        $ids = $this->get_metric_ids();

        // Pausa mínima entre llamadas POST para no exceder el rate limit de Klaviyo.
        // metric-aggregates tiene un límite más estricto que otros endpoints.
        $pause = function() { usleep( 300000 ); }; // 300 ms

        $received = isset( $ids['received'] )
            ? (int) $this->sum_metric( $this->aggregate( $ids['received'], $date_from, $date_to ) )
            : 0;

        $pause();
        $opened = isset( $ids['opened'] )
            ? (int) $this->sum_metric( $this->aggregate( $ids['opened'], $date_from, $date_to ) )
            : 0;

        $pause();
        $clicked = isset( $ids['clicked'] )
            ? (int) $this->sum_metric( $this->aggregate( $ids['clicked'], $date_from, $date_to ) )
            : 0;

        $pause();
        $unsubscribed = isset( $ids['unsubscribed'] )
            ? (int) $this->sum_metric( $this->aggregate( $ids['unsubscribed'], $date_from, $date_to ) )
            : 0;

        // ── Ingresos atribuidos a Klaviyo (Flows + Campañas) ────────────────────
        // Usamos los endpoints dedicados de reporting de Klaviyo:
        //   · flow-values-reports    → ingresos que Klaviyo atribuye a flows/automations
        //   · campaign-values-reports → ingresos que Klaviyo atribuye a campañas manuales
        // El aggregate genérico de 'Placed Order' devuelve TODOS los pedidos WooCommerce,
        // no solo los atribuidos, por eso NO lo usamos para revenue.
        $orders           = 0;
        $revenue          = 0.0;
        $flow_orders      = 0;
        $flow_revenue     = 0.0;
        $campaign_orders  = 0;
        $campaign_revenue = 0.0;
        $breakdown_error  = null;

        if ( isset( $ids['placed_order'] ) ) {
            $date_to_excl = gmdate( 'Y-m-d', strtotime( $date_to . ' +1 day' ) );
            $timeframe    = [
                'start' => $date_from   . 'T00:00:00+00:00',
                'end'   => $date_to_excl . 'T00:00:00+00:00',
            ];

            // ── Flows / Automaciones ─────────────────────────────────────────────
            try {
                $pause();
                $flow_report  = $this->api_post( 'flow-values-reports/', [
                    'data' => [
                        'type'       => 'flow-values-report',
                        'attributes' => [
                            'timeframe'             => $timeframe,
                            'conversion_metric_id'  => $ids['placed_order'],
                            'statistics'            => [ 'conversion_value', 'conversion_uniques' ],
                        ],
                    ],
                ] );
                $flow_revenue = round( $this->sum_values_report( $flow_report, 'conversion_value' ), 2 );
                $flow_orders  = (int) $this->sum_values_report( $flow_report, 'conversion_uniques' );
            } catch ( \RuntimeException $e ) {
                $breakdown_error = 'Flows: ' . $e->getMessage();
            }

            // ── Campañas manuales ────────────────────────────────────────────────
            try {
                $pause();
                $camp_report      = $this->api_post( 'campaign-values-reports/', [
                    'data' => [
                        'type'       => 'campaign-values-report',
                        'attributes' => [
                            'timeframe'             => $timeframe,
                            'conversion_metric_id'  => $ids['placed_order'],
                            'statistics'            => [ 'conversion_value', 'conversion_uniques' ],
                        ],
                    ],
                ] );
                $campaign_revenue = round( $this->sum_values_report( $camp_report, 'conversion_value' ), 2 );
                $campaign_orders  = (int) $this->sum_values_report( $camp_report, 'conversion_uniques' );
            } catch ( \RuntimeException $e ) {
                $breakdown_error = ( $breakdown_error ? $breakdown_error . ' | ' : '' )
                                 . 'Campañas: ' . $e->getMessage();
            }

            // Revenue e ingresos atribuidos = lo que Klaviyo se atribuye (flows + campañas)
            $revenue = $flow_revenue + $campaign_revenue;
            $orders  = $flow_orders  + $campaign_orders;
        }

        return [
            'received'         => $received,
            'opened'           => $opened,
            'clicked'          => $clicked,
            'unsubscribed'     => $unsubscribed,
            'orders'           => $orders,
            'revenue'          => $revenue,          // = flow + campaign (atribuido)
            'flow_orders'      => $flow_orders,
            'flow_revenue'     => $flow_revenue,
            'campaign_orders'  => $campaign_orders,
            'campaign_revenue' => $campaign_revenue,
            'open_rate'        => $received > 0 ? round( $opened  / $received * 100, 1 ) : null,
            'click_rate'       => $received > 0 ? round( $clicked / $received * 100, 1 ) : null,
            // null si OK; mensaje de error si el desglose falló
            'breakdown_error'  => $breakdown_error,
        ];
    }

    /**
     * Últimas campañas de email enviadas.
     * No depende del rango de fechas — muestra las más recientes del historial.
     */
    private function fetch_campaigns( string $date_from, string $date_to ): array {
        $data = $this->api_get( 'campaigns/', [
            'filter'  => "equals(channel,'email'),equals(status,'Sent')",
            'sort'    => '-send_time',
            'page'    => [ 'size' => 10 ],
            'fields'  => [ 'campaign' => 'name,status,send_time' ],
        ] );

        $campaigns = [];
        foreach ( $data['data'] ?? [] as $row ) {
            $attrs    = $row['attributes'] ?? [];
            $send_raw = $attrs['send_time'] ?? '';
            $campaigns[] = [
                'name'      => $attrs['name']   ?? 'Sin nombre',
                'send_date' => $send_raw ? date( 'd/m/Y', strtotime( $send_raw ) ) : '—',
            ];
        }

        return array_slice( $campaigns, 0, 8 );
    }

    /* ── Resumen público ────────────────────────────────────────────────────── */

    /**
     * Resumen completo de email marketing para el período.
     * Cacheado 15 min (hoy) / 1 hora (períodos cerrados).
     *
     * @return array ['overview' => [...], 'campaigns' => [...]] o ['error' => '...']
     */
    public function get_summary( string $date_from, string $date_to ): array {
        $cache_key = 'pmp_klaviyo_v6_' . md5( $date_from . $date_to );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) return $cached;

        try {
            $result = [
                'overview'  => $this->fetch_overview( $date_from, $date_to ),
                'campaigns' => [],
            ];

            // Las campañas son opcionales — si fallan no bloqueamos el resumen
            try {
                $result['campaigns'] = $this->fetch_campaigns( $date_from, $date_to );
            } catch ( \RuntimeException $e ) {
                $result['campaigns_error'] = $e->getMessage();
            }

        } catch ( \RuntimeException $e ) {
            return [ 'overview' => [], 'campaigns' => [], 'error' => $e->getMessage() ];
        }

        $ttl = ( $date_to === date( 'Y-m-d' ) ) ? 15 * MINUTE_IN_SECONDS : HOUR_IN_SECONDS;
        set_transient( $cache_key, $result, $ttl );
        return $result;
    }

    /* ── Prueba de conexión ─────────────────────────────────────────────────── */

    public function test_connection(): array {
        try {
            // Obtener nombre de la cuenta
            $org = 'Klaviyo';
            try {
                $accounts = $this->api_get( 'accounts/', [
                    'fields' => [ 'account' => 'contact_information' ],
                ] );
                $org = $accounts['data'][0]['attributes']['contact_information']['organization_name']
                    ?? 'Klaviyo';
            } catch ( \RuntimeException $e ) {
                // El endpoint de cuentas puede requerir permiso adicional — no es bloqueante
            }

            // Verificar métricas disponibles (confirma que la key tiene acceso de lectura)
            $ids          = $this->get_metric_ids();
            $metric_count = count( $ids );

            if ( $metric_count === 0 ) {
                return [
                    'ok'      => true,
                    'message' => "Conexión exitosa ✅ Cuenta: {$org} · Aún no hay métricas de email (envía tu primera campaña para verlas).",
                ];
            }

            $metric_labels = array_map( fn( $k ) => self::METRIC_NAMES[ $k ], array_keys( $ids ) );
            $metric_str    = implode( ', ', $metric_labels );

            return [
                'ok'      => true,
                'message' => "Conexión exitosa ✅ Cuenta: {$org} · Métricas encontradas: {$metric_str}.",
            ];

        } catch ( \RuntimeException $e ) {
            return [ 'ok' => false, 'message' => $e->getMessage() ];
        }
    }
}
