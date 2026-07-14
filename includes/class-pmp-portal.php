<?php
/**
 * PMP_Portal — Renderizado del portal + handlers AJAX.
 *
 * El portal ya NO usa shortcode ni páginas públicas.
 * Se muestra dentro del admin de WP (menú Pymes Modernas).
 *
 * Seguridad:
 *   - Solo se registran wp_ajax_ (sin wp_ajax_nopriv_).
 *   - WordPress requiere login para ejecutar wp_ajax_ hooks.
 *   - Todos los handlers verifican el nonce pmp_nonce adicionalmente.
 *   - Los datos de WooCommerce y tickets NUNCA son accesibles sin sesión WP.
 */
defined( 'ABSPATH' ) || exit;

class PMP_Portal {

    public function __construct() {
        // AJAX — solo para usuarios logueados (wp_ajax_ sin nopriv)
        $actions = [
            'pmp_get_credits',
            'pmp_get_tickets',
            'pmp_get_ticket',
            'pmp_get_news',
            'pmp_get_ticket_types',
            'pmp_create_ticket',
            'pmp_get_woo_stats',
            'pmp_get_credits_history',
        ];
        foreach ( $actions as $a ) {
            add_action( "wp_ajax_{$a}", [ $this, $a ] );
            // ← Sin wp_ajax_nopriv_. WordPress ignorará estos AJAX si el usuario
            //   no está logueado, devolviendo -1 automáticamente.
        }
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Render estático — llamado desde PMP_Admin::page_portal()
     * ───────────────────────────────────────────────────────────────────────── */

    public static function render(): string {
        $woo_active = PMP_WooCommerce::is_active();

        ob_start();
        ?>
        <div class="pm-portal" id="pm-portal-root">

            <!-- Cabecera -->
            <div class="pm-portal-header">
                <div class="pm-portal-header-left">
                    <h2 class="pm-portal-title">Mi Portal</h2>
                    <p class="pm-portal-subtitle pm-portal-client-name">Cargando...</p>
                </div>
                <div class="pm-portal-header-right">
                    <span class="pm-portal-month-badge" id="pm-month-badge"></span>
                </div>
            </div>

            <!-- Tabs -->
            <nav class="pm-portal-tabs" role="tablist">
                <button class="pm-portal-tab" data-tab="news"    role="tab" aria-selected="false">📰 Noticias</button>
                <button class="pm-portal-tab active" data-tab="credits" role="tab" aria-selected="true">💳 Mis Créditos</button>
                <button class="pm-portal-tab" data-tab="tickets" role="tab" aria-selected="false">🎫 Mis Tickets</button>
                <button class="pm-portal-tab" data-tab="history" role="tab" aria-selected="false">📋 Historial</button>
            </nav>

            <!-- ── Panel: Noticias ── -->
            <div class="pm-portal-panel" data-panel="news">
                <div class="pm-portal-loading" id="pm-news-loading">
                    <div class="pm-portal-spinner"></div><span>Cargando noticias...</span>
                </div>
                <div id="pm-news-content" class="pm-news-list" style="display:none;"></div>
            </div>

            <!-- ── Panel: Créditos ── -->
            <div class="pm-portal-panel active" data-panel="credits">
                <div class="pm-portal-loading" id="pm-credits-loading">
                    <div class="pm-portal-spinner"></div><span>Cargando créditos...</span>
                </div>

                <div id="pm-credits-content" style="display:none;">

                    <div class="pm-credits-hero">
                        <div class="pm-ring-wrap">
                            <svg class="pm-ring-svg" viewBox="0 0 120 120" width="160" height="160" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="60" cy="60" r="47" fill="none" stroke="#dde4ee" stroke-width="12"/>
                                <circle cx="60" cy="60" r="47" fill="none"
                                        stroke="#1a2e4f" stroke-width="12"
                                        stroke-dasharray="0 295.3"
                                        stroke-linecap="round"
                                        transform="rotate(-90 60 60)"
                                        id="pm-ring-progress"/>
                                <text x="60" y="54" text-anchor="middle" font-size="22" font-weight="700" fill="#1a2e4f" id="pm-ring-value">—</text>
                                <text x="60" y="69" text-anchor="middle" font-size="9"  fill="#6b7280"   id="pm-ring-label">créditos disp.</text>
                            </svg>
                            <p class="pm-ring-caption" id="pm-ring-caption"></p>
                        </div>

                        <div class="pm-credits-stats">
                            <div class="pm-stat-card">
                                <span class="pm-stat-val" id="pm-credits-remaining">—</span>
                                <span class="pm-stat-lbl">Créditos disponibles</span>
                            </div>
                            <div class="pm-stat-card">
                                <span class="pm-stat-val" id="pm-credits-used">—</span>
                                <span class="pm-stat-lbl">Usados este mes</span>
                            </div>
                            <div class="pm-stat-card">
                                <span class="pm-stat-val" id="pm-credits-plan">—</span>
                                <span class="pm-stat-lbl">Créditos del plan</span>
                            </div>
                            <div class="pm-stat-card pm-stat-card-extra" id="pm-credits-extra-card" style="display:none;">
                                <span class="pm-stat-val" id="pm-credits-extra">—</span>
                                <span class="pm-stat-lbl">Créditos extra</span>
                            </div>
                        </div>
                    </div>

                    <div class="pm-buy-credits-banner">
                        <div class="pm-buy-text">
                            <strong>¿Necesitas más créditos?</strong>
                            <span>Cada crédito = 1 hora de soporte personalizado</span>
                        </div>
                        <a class="pm-btn pm-btn-buy"
                           href="<?= esc_url( PMP_Settings::get_credits_shop_url() ) ?>"
                           target="_blank" rel="noopener noreferrer">
                            Comprar créditos →
                        </a>
                    </div>

                    <div class="pm-section-block">
                        <h3 class="pm-section-title">Uso de créditos por mes</h3>
                        <div id="pm-credits-chart" class="pm-bar-chart-wrap"></div>
                    </div>

                    <div class="pm-section-block">
                        <h3 class="pm-section-title">Nueva solicitud</h3>
                        <form id="pm-ticket-form" class="pm-form">
                            <div class="pm-form-grid">
                                <div class="pm-form-group pm-form-full">
                                    <label>Solicitud <span class="pm-req">*</span></label>
                                    <input type="text" name="title" class="pm-input" placeholder="¿Qué necesitas?" required>
                                </div>
                                <div class="pm-form-group">
                                    <label>Tipo</label>
                                    <select name="ticket_type" class="pm-select" id="pm-ticket-type-select">
                                        <option value="">Selecciona un tipo…</option>
                                    </select>
                                </div>
                                <div class="pm-form-group">
                                    <label>Prioridad</label>
                                    <select name="priority" class="pm-select">
                                        <option value="baja">Baja — puede esperar</option>
                                        <option value="media" selected>Media — pronto</option>
                                        <option value="alta">Alta — esta semana</option>
                                        <option value="urgente">Urgente — lo antes posible</option>
                                    </select>
                                </div>
                                <div class="pm-form-group pm-form-full">
                                    <label>Notas adicionales</label>
                                    <textarea name="client_notes" class="pm-textarea" rows="3"
                                              placeholder="Links, contexto, capturas de pantalla..."></textarea>
                                </div>
                            </div>
                            <div class="pm-form-actions">
                                <button type="submit" class="pm-btn pm-btn-primary" id="pm-ticket-submit">Enviar solicitud</button>
                                <span class="pm-form-msg" id="pm-ticket-msg" style="display:none;"></span>
                            </div>
                        </form>
                    </div>

                </div>
            </div>

            <!-- ── Panel: Tickets activos ── -->
            <div class="pm-portal-panel" data-panel="tickets">
                <div class="pm-portal-loading" id="pm-tickets-loading">
                    <div class="pm-portal-spinner"></div><span>Cargando tickets...</span>
                </div>
                <div id="pm-tickets-content" style="display:none;">
                    <div class="pm-section-block">
                        <h3 class="pm-section-title">Solicitudes activas</h3>
                        <div id="pm-tickets-active" class="pm-ticket-list"></div>
                    </div>
                    <div class="pm-section-block">
                        <h3 class="pm-section-title">Resueltos recientes</h3>
                        <div class="pm-history-filter">
                            <label>Mes:</label>
                            <input type="month" id="pm-tickets-month-filter" class="pm-input pm-input-sm"
                                   value="<?= esc_attr( date( 'Y-m' ) ) ?>" max="<?= esc_attr( date( 'Y-m' ) ) ?>">
                            <button class="pm-btn pm-btn-secondary" id="pm-tickets-filter-btn">Cargar</button>
                        </div>
                        <div id="pm-tickets-resolved" class="pm-ticket-list"></div>
                    </div>
                </div>
            </div>

            <!-- ── Panel: Historial ── -->
            <div class="pm-portal-panel" data-panel="history">
                <div class="pm-portal-loading" id="pm-history-loading">
                    <div class="pm-portal-spinner"></div><span>Cargando historial...</span>
                </div>
                <div id="pm-history-content" style="display:none;">
                    <div class="pm-history-filter">
                        <label>Mes:</label>
                        <input type="month" id="pm-history-month" class="pm-input pm-input-sm" value="<?= esc_attr( date( 'Y-m' ) ) ?>">
                        <select id="pm-history-status" class="pm-select pm-input-sm">
                            <option value="">Todos los estados</option>
                            <option value="pendiente">Pendiente</option>
                            <option value="en_proceso">En Proceso</option>
                            <option value="resuelto">Resuelto</option>
                        </select>
                        <button class="pm-btn pm-btn-secondary" id="pm-history-load-btn">Cargar</button>
                    </div>
                    <div id="pm-history-summary" class="pm-history-summary-row"></div>
                    <div id="pm-history-table-wrap" class="pm-table-wrap">
                        <table class="pm-table" id="pm-history-table">
                            <thead>
                                <tr>
                                    <th>#</th><th>Solicitud</th><th>Tipo</th>
                                    <th>Prioridad</th><th>Estado</th><th>Duración</th><th>Fecha</th>
                                </tr>
                            </thead>
                            <tbody id="pm-history-tbody"></tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div><!-- /pm-portal -->
        <?php
        return ob_get_clean();
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Helper: nonce + login requerido
     * ───────────────────────────────────────────────────────────────────────── */

    private function verify(): void {
        // WordPress ya garantizó que el usuario está logueado al llegar aquí
        // (wp_ajax_ no se dispara sin sesión). El nonce es defensa en profundidad.
        if ( ! check_ajax_referer( 'pmp_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Token inválido.' ], 403 );
        }
    }

    private function api(): PMP_API_Client {
        return new PMP_API_Client();
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * AJAX: Créditos
     * ───────────────────────────────────────────────────────────────────────── */

    public function pmp_get_credits(): void {
        $this->verify();
        $result = $this->api()->get_credits();
        $result['error']
            ? wp_send_json_error( [ 'message' => $result['message'] ] )
            : wp_send_json_success( $result['data'] );
    }

    public function pmp_get_credits_history(): void {
        $this->verify();
        $months = max( 1, min( 12, intval( $_POST['months'] ?? 6 ) ) );
        $result = $this->api()->get_credits_history( $months );
        $result['error']
            ? wp_send_json_error( [ 'message' => $result['message'] ] )
            : wp_send_json_success( $result['data'] );
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * AJAX: Tickets
     * ───────────────────────────────────────────────────────────────────────── */

    public function pmp_get_tickets(): void {
        $this->verify();
        $filters = [];

        $status = sanitize_text_field( $_POST['status'] ?? '' );
        if ( $status ) $filters['status'] = $status;

        $month = sanitize_text_field( $_POST['month'] ?? '' );
        if ( preg_match( '/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $m ) ) {
            $filters['month'] = (int) $m[2];
            $filters['year']  = (int) $m[1];
        }

        $result = $this->api()->get_tickets( $filters );
        $result['error']
            ? wp_send_json_error( [ 'message' => $result['message'] ] )
            : wp_send_json_success( $result['data'] );
    }

    public function pmp_get_ticket_types(): void {
        $this->verify();
        $result = $this->api()->get_ticket_types();
        $result['error']
            ? wp_send_json_error( [ 'message' => $result['message'] ] )
            : wp_send_json_success( $result['data'] );
    }

    public function pmp_get_ticket(): void {
        $this->verify();
        $id = intval( $_POST['id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( [ 'message' => 'ID inválido.' ] );
        }
        $result = $this->api()->get_ticket( $id );
        $result['error']
            ? wp_send_json_error( [ 'message' => $result['message'] ] )
            : wp_send_json_success( $result['data'] );
    }

    public function pmp_create_ticket(): void {
        $this->verify();

        $title = sanitize_text_field( $_POST['title'] ?? '' );
        if ( ! $title ) {
            wp_send_json_error( [ 'message' => 'El título es requerido.' ] );
        }

        $priority = sanitize_text_field( $_POST['priority'] ?? 'media' );
        if ( ! in_array( $priority, [ 'baja', 'media', 'alta', 'urgente' ], true ) ) {
            $priority = 'media';
        }

        $result = $this->api()->create_ticket( [
            'title'        => $title,
            'priority'     => $priority,
            'ticket_type'  => sanitize_text_field( $_POST['ticket_type']  ?? '' ),
            'client_notes' => sanitize_textarea_field( $_POST['client_notes'] ?? '' ),
        ] );

        $result['error']
            ? wp_send_json_error( [ 'message' => $result['message'] ] )
            : wp_send_json_success( $result['data'] );
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * AJAX: Noticias
     * ───────────────────────────────────────────────────────────────────────── */

    public function pmp_get_news(): void {
        $this->verify();
        $result = $this->api()->get_news();
        $result['error']
            ? wp_send_json_error( [ 'message' => $result['message'] ] )
            : wp_send_json_success( $result['data'] );
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * AJAX: Estadísticas WooCommerce (datos locales)
     * ───────────────────────────────────────────────────────────────────────── */

    public function pmp_get_woo_stats(): void {
        $this->verify();

        if ( ! PMP_WooCommerce::is_active() ) {
            wp_send_json_error( [ 'message' => 'WooCommerce no está activo.' ] );
        }

        $range = sanitize_text_field( $_POST['range'] ?? '30' );

        $date_to = date( 'Y-m-d' );
        switch ( $range ) {
            case '7':
                $date_from = date( 'Y-m-d', strtotime( '-7 days' ) );
                break;
            case 'month':
                $date_from = date( 'Y-m-01' );
                break;
            case 'prev_month':
                $date_from = date( 'Y-m-01', strtotime( 'first day of last month' ) );
                $date_to   = date( 'Y-m-t',  strtotime( 'last day of last month'  ) );
                break;
            case 'custom':
                $date_from = sanitize_text_field( $_POST['date_from'] ?? date( 'Y-m-01' ) );
                $date_to   = sanitize_text_field( $_POST['date_to']   ?? date( 'Y-m-d'  ) );
                if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) $date_from = date( 'Y-m-01' );
                if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to   ) ) $date_to   = date( 'Y-m-d'  );
                break;
            default:
                $date_from = date( 'Y-m-d', strtotime( '-30 days' ) );
        }

        // Caché por rango
        $cache_key = 'pmp_woo_' . md5( $date_from . '_' . $date_to );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) {
            wp_send_json_success( $cached );
        }

        $result = [
            'date_from'     => $date_from,
            'date_to'       => $date_to,
            'revenue'       => PMP_WooCommerce::get_revenue_stats( $date_from, $date_to ),
            'orders_status' => array_values( PMP_WooCommerce::get_orders_by_status( $date_from, $date_to ) ),
            'top_products'  => PMP_WooCommerce::get_top_products( $date_from, $date_to, 5 ),
            'top_customers' => PMP_WooCommerce::get_top_customers( $date_from, $date_to, 5 ),
            'daily_sales'   => PMP_WooCommerce::get_daily_sales( intval( $_POST['chart_days'] ?? 14 ) ),
        ];

        $cache_ttl = ( $date_to === date( 'Y-m-d' ) ) ? 15 * MINUTE_IN_SECONDS : HOUR_IN_SECONDS;
        set_transient( $cache_key, $result, $cache_ttl );

        wp_send_json_success( $result );
    }
}
