<?php
/**
 * PMP_Order_Sync — Sincroniza pedidos WooCommerce al EAM en background.
 *
 * Captura TODOS los estados (pending, failed, cancelled, processing, completed…)
 * para que operaciones pueda detectar pedidos fallidos o períodos sin actividad.
 *
 * Flujo de dos pasos:
 *
 *   1. Hook síncrono (checkout / cambio de estado) → solo encola un job.
 *      Zero bloqueo en el checkout del cliente.
 *
 *   2. Job asíncrono (Action Scheduler o WP-Cron, ~60 s después) → hace el
 *      POST al EAM con el estado actual del pedido en ese momento.
 *      Como el endpoint EAM usa INSERT … ON DUPLICATE KEY UPDATE, enviar el
 *      mismo pedido en distintos estados simplemente actualiza el registro.
 *
 * Deduplicación:
 *   pmp_order_queued_{id}  (TTL 5 min) — evita encolar el mismo pedido dos
 *   veces si varios hooks disparan en ráfaga (ej. pending → processing en
 *   décimas de segundo). El job que corre a los 60 s leerá el estado actual,
 *   que ya será el definitivo.
 */
defined( 'ABSPATH' ) || exit;

class PMP_Order_Sync {

    /** Nombre del hook del job asíncrono */
    const ASYNC_HOOK = 'pmp_push_order_to_eam';

    /** Segundos de espera antes del push — bien fuera del ciclo de checkout */
    const DELAY_SECONDS = 60;

    /** TTL del transient anti-ráfaga (segundos) */
    const QUEUE_TTL = 5 * MINUTE_IN_SECONDS;

    public function __construct() {
        if ( ! PMP_Settings::is_configured() ) return;
        if ( ! PMP_WooCommerce::is_active() )  return;

        // ── Disparadores: encolan el job, no hacen el push ───────────────────

        // Pedido recién creado en checkout (cualquier estado inicial: pending, failed…)
        add_action( 'woocommerce_checkout_order_created',
            [ $this, 'on_order_created' ], 10, 1 );

        // Cualquier cambio de estado posterior (pending→failed, pending→processing…)
        add_action( 'woocommerce_order_status_changed',
            [ $this, 'on_status_changed' ], 10, 4 );

        // ── Ejecutor del job asíncrono ────────────────────────────────────────
        add_action( self::ASYNC_HOOK, [ $this, 'execute_push' ], 10, 1 );
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Paso 1 — Encolar  (zero bloqueo — solo escribe un transient / encola job)
     * ───────────────────────────────────────────────────────────────────────── */

    /** Pedido creado en checkout (cualquier estado) */
    public function on_order_created( WC_Order $order ): void {
        $this->schedule( $order->get_id() );
    }

    /** Cualquier cambio de estado (incluyendo pending → failed, cancelled, etc.) */
    public function on_status_changed( int $order_id, string $from, string $to, WC_Order $order ): void {
        // Si ya hay un job encolado para este pedido que aún no corrió,
        // déjalo correr — leerá el estado actual a los 60 s y será el definitivo.
        // Si el transient expiró (> 5 min), encola un nuevo push para el cambio.
        $this->schedule( $order_id );
    }

    /**
     * Encola el job asíncrono si no hay uno reciente ya encolado.
     * El TTL corto (5 min) evita ráfagas pero deja pasar cambios tardíos.
     */
    private function schedule( int $order_id ): void {
        if ( get_transient( 'pmp_order_queued_' . $order_id ) ) {
            return; // Ya hay un job en vuelo — cuando corra leerá el estado actual.
        }

        $fire_at = time() + self::DELAY_SECONDS;

        if ( function_exists( 'as_schedule_single_action' ) ) {
            // Action Scheduler (bundled con WooCommerce) — robusto, con reintentos.
            if ( ! as_next_scheduled_action( self::ASYNC_HOOK, [ $order_id ] ) ) {
                as_schedule_single_action( $fire_at, self::ASYNC_HOOK, [ $order_id ] );
            }
        } else {
            // Fallback: WP-Cron — corre en la siguiente visita al sitio tras $fire_at.
            if ( ! wp_next_scheduled( self::ASYNC_HOOK, [ $order_id ] ) ) {
                wp_schedule_single_event( $fire_at, self::ASYNC_HOOK, [ $order_id ] );
            }
        }

        // Marcar como "en cola" durante 5 min para absorber ráfagas de cambios rápidos.
        set_transient( 'pmp_order_queued_' . $order_id, 1, self::QUEUE_TTL );
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Paso 2 — Ejecutar push  (corre en background, fuera del checkout)
     * ───────────────────────────────────────────────────────────────────────── */

    /**
     * Callback del job asíncrono.
     * Lee el pedido fresco de la BD (estado actual, no el de cuando se encoló)
     * y lo envía al EAM.
     */
    public function execute_push( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $result = ( new PMP_API_Client() )->push_order( self::build_payload( $order ) );

        if ( ! $result['error'] ) {
            delete_transient( 'pmp_order_queued_' . $order_id );
        }
        // Si falla: Action Scheduler reintentará (3 veces por defecto).
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Utilidad compartida — construir el payload de un pedido
     * Usada tanto por el push en tiempo real como por el backfill histórico.
     * ───────────────────────────────────────────────────────────────────────── */

    public static function build_payload( WC_Order $order ): array {
        $products = [];
        foreach ( $order->get_items() as $item ) {
            /** @var WC_Order_Item_Product $item */
            $products[] = [
                'product_id'    => (int)    $item->get_product_id(),
                'name'          => (string) $item->get_name(),
                'qty'           => (int)    $item->get_quantity(),
                'line_subtotal' => (float)  $item->get_subtotal(),
            ];
        }

        $date_obj = $order->get_date_created();

        return [
            'order_id'       => $order->get_id(),
            'order_number'   => $order->get_order_number(),
            'status'         => $order->get_status(),
            'subtotal'       => (float) $order->get_subtotal(),
            'total'          => (float) $order->get_total(),
            'currency'       => $order->get_currency(),
            'items_count'    => $order->get_item_count(),
            'products'       => $products,
            'customer_name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            'customer_email' => $order->get_billing_email(),
            'order_date'     => $date_obj ? $date_obj->date( 'Y-m-d H:i:s' ) : current_time( 'mysql' ),
            'attribution'    => PMP_WooCommerce::get_order_attribution( $order ),
        ];
    }
}
