<?php
/**
 * PMP_API_Client — Cliente HTTP para los endpoints REST del plugin principal (EAM).
 * Todas las peticiones se hacen server-side (PHP → EAM) para no exponer la API Key al browser.
 */
defined( 'ABSPATH' ) || exit;

class PMP_API_Client {

    private string $api_key;
    private string $base_url;

    public function __construct() {
        $this->api_key  = PMP_Settings::get_api_key();
        $this->base_url = PMP_Settings::get_api_url();
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * HTTP helpers
     * ───────────────────────────────────────────────────────────────────────── */

    private function request( string $method, string $path, array $body = [] ): array {
        $url  = $this->base_url . '/wp-json/pm/v1/' . ltrim( $path, '/' );
        $args = [
            'method'  => strtoupper( $method ),
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'timeout' => 15,
        ];

        if ( ! empty( $body ) && in_array( $args['method'], [ 'POST', 'PUT', 'PATCH' ], true ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return [ 'error' => true, 'message' => $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            $msg = $body['message'] ?? "Error HTTP {$code}";
            return [ 'error' => true, 'message' => $msg, 'code' => $code ];
        }

        return [ 'error' => false, 'data' => $body ];
    }

    private function get( string $path, array $params = [] ): array {
        if ( ! empty( $params ) ) {
            $path .= '?' . http_build_query( $params );
        }
        return $this->request( 'GET', $path );
    }

    private function post( string $path, array $body ): array {
        return $this->request( 'POST', $path, $body );
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Endpoints
     * ───────────────────────────────────────────────────────────────────────── */

    /** Créditos del cliente (mes actual) */
    public function get_credits(): array {
        return $this->get( 'credits' );
    }

    /** Historial de créditos usados por mes */
    public function get_credits_history( int $months = 6 ): array {
        return $this->get( 'credits/history', [ 'months' => $months ] );
    }

    /** Lista de tickets (con filtros opcionales) */
    public function get_tickets( array $filters = [] ): array {
        return $this->get( 'tickets', $filters );
    }

    /** Detalle completo de un ticket */
    public function get_ticket( int $id ): array {
        return $this->get( 'tickets/' . $id );
    }

    /** Crear ticket */
    public function create_ticket( array $data ): array {
        return $this->post( 'tickets', $data );
    }

    /** Tipos de ticket (cacheado 1 hora) */
    public function get_ticket_types(): array {
        $cached = get_transient( 'pmp_ticket_types' );
        if ( $cached !== false ) {
            return [ 'error' => false, 'data' => $cached ];
        }
        $result = $this->get( 'ticket-types' );
        if ( ! $result['error'] && is_array( $result['data'] ) ) {
            set_transient( 'pmp_ticket_types', $result['data'], HOUR_IN_SECONDS );
        }
        return $result;
    }

    /** Noticias activas (cacheadas 30 min solo si hay datos) */
    public function get_news(): array {
        $cached = get_transient( 'pmp_news' );
        // Solo usar caché si tiene datos reales; si está vacío volvemos a buscar
        // para que las noticias publicadas aparezcan sin esperar la expiración.
        if ( $cached !== false && is_array( $cached ) && ! empty( $cached ) ) {
            return [ 'error' => false, 'data' => $cached ];
        }
        $result = $this->get( 'news' );
        if ( ! $result['error'] && is_array( $result['data'] ) && ! empty( $result['data'] ) ) {
            set_transient( 'pmp_news', $result['data'], 30 * MINUTE_IN_SECONDS );
        }
        return $result;
    }

    /**
     * Envía los datos de un nuevo pedido WooCommerce al EAM.
     *
     * Siempre se llama desde un job en background (Action Scheduler o WP-Cron),
     * nunca directamente desde el ciclo de checkout — por eso puede usar blocking:true
     * y un timeout generoso sin afectar la experiencia del cliente.
     *
     * @param array $order_data  Datos del pedido (order_id, subtotal, products, attribution…)
     * @return array             [ 'error' => bool, 'data' => ... ]
     */
    public function push_order( array $order_data ): array {
        return $this->post( 'orders', $order_data );
    }

    /** Prueba de conexión — devuelve true o mensaje de error */
    public function test_connection(): array {
        $result = $this->get( 'credits' );
        if ( $result['error'] ) {
            return [ 'ok' => false, 'message' => $result['message'] ];
        }
        $client_name = $result['data']['client_name'] ?? '(sin nombre)';
        return [ 'ok' => true, 'message' => "Conectado. Cliente: {$client_name}" ];
    }
}
