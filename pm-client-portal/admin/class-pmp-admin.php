<?php
/**
 * PMP_Admin — Menú "Pymes Modernas" en el admin de WordPress.
 *
 * Estructura del menú:
 *   📊 Pymes Modernas          ← página principal = portal completo
 *      └── Configuración       ← API Key, URL, etc.
 *
 * Seguridad:
 *   - Todo requiere wp-login (no hay nopriv).
 *   - Capacidad mínima: 'read' (compatible con rol Subscriber y cualquier admin).
 *   - Assets solo se cargan en la página del portal, no en todo el admin.
 */
defined( 'ABSPATH' ) || exit;

class PMP_Admin {

    /** Slug de la página principal (usado para detectar el hook de assets) */
    const MENU_SLUG      = 'pymes-modernas';
    const SETTINGS_SLUG  = 'pymes-modernas-settings';
    const DASHBOARD_SLUG = 'pymes-modernas-resumen';

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menus'   ] );
        add_action( 'admin_init',            [ $this, 'register_settings'] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets'   ] );
        add_action( 'admin_notices',         [ $this, 'notice_not_connected' ] );

        add_action( 'wp_ajax_pmp_test_connection',    [ $this, 'ajax_test_connection'    ] );
        add_action( 'wp_ajax_pmp_create_portal_page', [ $this, 'ajax_create_portal_page' ] );
        add_action( 'wp_ajax_pmp_backfill_orders',    [ $this, 'ajax_backfill_orders'    ] );

        add_action( 'wp_ajax_pmp_dashboard_stats',       [ $this, 'ajax_dashboard_stats'      ] );
        add_action( 'wp_ajax_pmp_dashboard_orders',      [ $this, 'ajax_dashboard_orders'     ] );
        add_action( 'wp_ajax_pmp_dashboard_dormant',     [ $this, 'ajax_dashboard_dormant'    ] );
        add_action( 'wp_ajax_pmp_dashboard_ai_insights', [ $this, 'ajax_dashboard_ai_insights'] );
        add_action( 'wp_ajax_pmp_dashboard_ai_chat',     [ $this, 'ajax_dashboard_ai_chat'    ] );
        add_action( 'wp_ajax_pmp_dashboard_ga4',         [ $this, 'ajax_dashboard_ga4'        ] );
        add_action( 'wp_ajax_pmp_dashboard_gsc',         [ $this, 'ajax_dashboard_gsc'        ] );
        add_action( 'wp_ajax_pmp_test_gsc',              [ $this, 'ajax_test_gsc'             ] );
        add_action( 'wp_ajax_pmp_test_ga4',              [ $this, 'ajax_test_ga4'             ] );
        add_action( 'wp_ajax_pmp_dashboard_meta',        [ $this, 'ajax_dashboard_meta'       ] );
        add_action( 'wp_ajax_pmp_test_meta',             [ $this, 'ajax_test_meta'            ] );
        add_action( 'wp_ajax_pmp_dashboard_klaviyo',    [ $this, 'ajax_dashboard_klaviyo'    ] );
        add_action( 'wp_ajax_pmp_test_klaviyo',         [ $this, 'ajax_test_klaviyo'         ] );
        add_action( 'wp_ajax_pmp_dismiss_connect_notice', [ $this, 'ajax_dismiss_connect_notice' ] );
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Aviso de plugin no conectado
     * ───────────────────────────────────────────────────────────────────────── */

    /**
     * Muestra un banner en el admin de WordPress cuando el plugin no está
     * conectado al sitio de operaciones de Pymes Modernas.
     * Solo visible para administradores; se puede descartar con el botón ×
     * (el dismiss se guarda en un user meta).
     */
    public function notice_not_connected(): void {
        // Solo admins
        if ( ! current_user_can( 'manage_options' ) ) return;

        // Si ya está configurado, no mostrar nada
        if ( PMP_Settings::is_configured() ) return;

        // Si el usuario descartó el aviso esta sesión, no mostrar
        $dismissed_until = (int) get_user_meta( get_current_user_id(), 'pmp_notice_dismissed', true );
        if ( $dismissed_until > time() ) return;

        $url_comprar  = 'https://pymesmodernas.com/creditos';
        $url_config   = admin_url( 'admin.php?page=' . self::SETTINGS_SLUG );
        $nonce        = wp_create_nonce( 'pmp_dismiss_notice' );
        ?>
        <div class="notice pmp-notice-connect" style="
            border-left: 4px solid #1a2e4f;
            background: #fff;
            padding: 0;
            display: flex;
            align-items: stretch;
            border-radius: 4px;
            overflow: hidden;
            box-shadow: 0 1px 4px rgba(0,0,0,.08);
        ">
            <!-- Banda izquierda de color -->
            <div style="background:#1a2e4f;width:48px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 20 20" fill="#60a5fa">
                    <rect x="1"  y="10" width="4" height="9" rx="1"/>
                    <rect x="8"  y="5"  width="4" height="14" rx="1"/>
                    <rect x="15" y="1"  width="4" height="18" rx="1"/>
                </svg>
            </div>

            <!-- Contenido -->
            <div style="padding:12px 16px;flex:1;display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
                <div style="flex:1;min-width:260px;">
                    <p style="margin:0 0 3px;font-size:13px;font-weight:700;color:#1a2e4f;">
                        Conecta tu tienda con el servicio de soporte personalizado de Pymes Modernas
                    </p>
                    <p style="margin:0;font-size:12px;color:#6b7280;">
                        Activa la gestión de créditos, tickets y sincronización de pedidos con el equipo de Pymes Modernas.
                    </p>
                </div>
                <div style="display:flex;gap:10px;align-items:center;flex-shrink:0;">
                    <a href="<?php echo esc_url( $url_comprar ); ?>"
                       target="_blank" rel="noopener"
                       style="
                           display:inline-block;
                           background:#1a2e4f;color:#fff;
                           padding:7px 16px;border-radius:4px;
                           font-size:12px;font-weight:600;
                           text-decoration:none;
                           white-space:nowrap;
                       ">
                        🛒 Adquirir servicio
                    </a>
                    <a href="<?php echo esc_url( $url_config ); ?>"
                       style="
                           display:inline-block;
                           background:#f1f5f9;color:#1a2e4f;
                           padding:7px 14px;border-radius:4px;
                           font-size:12px;font-weight:500;
                           text-decoration:none;
                           white-space:nowrap;
                           border:1px solid #e2e8f0;
                       ">
                        ⚙ Configurar
                    </a>
                </div>
            </div>

            <!-- Botón cerrar -->
            <button type="button"
                    class="pmp-dismiss-notice"
                    data-nonce="<?php echo esc_attr( $nonce ); ?>"
                    style="
                        background:none;border:none;cursor:pointer;
                        padding:12px 14px;color:#9ca3af;font-size:18px;
                        align-self:flex-start;line-height:1;
                    "
                    title="Descartar por 7 días">×</button>
        </div>

        <script>
        (function($){
            $(document).on('click', '.pmp-dismiss-notice', function(){
                var btn = $(this);
                $.post(ajaxurl, {
                    action: 'pmp_dismiss_connect_notice',
                    nonce:  btn.data('nonce'),
                }, function(){ btn.closest('.pmp-notice-connect').slideUp(200); });
            });
        }(jQuery));
        </script>
        <?php
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Menús
     * ───────────────────────────────────────────────────────────────────────── */

    public function register_menus() {
        // Icono SVG en base64 — gráfica de barras con colores PM
        $icon = 'data:image/svg+xml;base64,' . base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="#a7aaad">'
            . '<rect x="1"  y="10" width="4" height="9" rx="1"/>'
            . '<rect x="8"  y="5"  width="4" height="14" rx="1"/>'
            . '<rect x="15" y="1"  width="4" height="18" rx="1"/>'
            . '</svg>'
        );

        // Menú principal → renderiza el portal
        add_menu_page(
            'Pymes Modernas',
            'Pymes Modernas',
            'read',                     // cualquier usuario logueado puede ver su portal
            self::MENU_SLUG,
            [ $this, 'page_portal'     ],
            $icon,
            58                          // posición: debajo de WooCommerce (55)
        );

        // Submenú: renombrar el primero (WP lo duplica automáticamente)
        add_submenu_page(
            self::MENU_SLUG,
            'Mi Portal',
            'Mi Portal',
            'read',
            self::MENU_SLUG,
            [ $this, 'page_portal' ]
        );

        // Submenú: Resumen — Dashboard de ecommerce (solo admins)
        add_submenu_page(
            self::MENU_SLUG,
            'Resumen — Pymes Modernas',
            'Resumen',
            'manage_options',
            self::DASHBOARD_SLUG,
            [ $this, 'page_dashboard'  ]
        );

        // Submenú: Configuración (solo admins)
        add_submenu_page(
            self::MENU_SLUG,
            'Configuración — Portal PM',
            'Configuración',
            'manage_options',
            self::SETTINGS_SLUG,
            [ $this, 'page_settings'   ]
        );
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Assets — solo en la página del portal
     * ───────────────────────────────────────────────────────────────────────── */

    public function enqueue_assets( string $hook ): void {
        $is_portal    = $hook === 'toplevel_page_' . self::MENU_SLUG;
        $is_dashboard = str_ends_with( $hook, self::DASHBOARD_SLUG );

        if ( ! $is_portal && ! $is_dashboard ) return;

        wp_enqueue_style(
            'pm-portal',
            PMP_PLUGIN_URL . 'assets/css/pm-portal.css',
            [],
            PMP_VERSION
        );

        if ( $is_portal ) {
            // Overrides de admin para el portal
            wp_add_inline_style( 'pm-portal', '
                #pm-portal-root { margin-top: 0; }
                .pm-portal-header { border-radius: 6px 6px 0 0; }
                .pm-portal-panel  { border-radius: 0 0 6px 6px; }
            ' );

            wp_enqueue_script(
                'pm-portal',
                PMP_PLUGIN_URL . 'assets/js/pm-portal.js',
                [ 'jquery' ],
                PMP_VERSION,
                true
            );
            wp_localize_script( 'pm-portal', 'PMP', [
                'ajax_url'    => admin_url( 'admin-ajax.php' ),
                'nonce'       => wp_create_nonce( 'pmp_nonce' ),
                'credits_url' => esc_url( PMP_Settings::get_credits_shop_url() ),
                'woo_active'  => PMP_WooCommerce::is_active() ? '1' : '0',
                'currency'    => function_exists( 'get_woocommerce_currency_symbol' )
                    ? html_entity_decode( get_woocommerce_currency_symbol() )
                    : '$',
            ] );
        }

        if ( $is_dashboard ) {
            // Estilos del chat conversacional de IA
            wp_add_inline_style( 'pm-portal', '
                #pmpd-ai-modal .pmpd-modal { display:flex; flex-direction:column; max-height:90vh; }
                #pmpd-ai-modal-body { flex:1; overflow-y:auto; padding:16px 20px; }
                .pmpd-chat-bubble { margin-bottom:14px; max-width:88%; }
                .pmpd-chat-assistant { margin-right:auto; }
                .pmpd-chat-user { margin-left:auto; text-align:right; }
                .pmpd-chat-user-text {
                    display:inline-block; background:#1a2e4f; color:#fff;
                    padding:9px 14px; border-radius:12px 12px 2px 12px;
                    font-size:13px; max-width:100%; word-wrap:break-word; text-align:left;
                }
                .pmpd-chat-assistant-text {
                    background:#f9fafb; border:1px solid #e5e7eb;
                    padding:12px 16px; border-radius:2px 12px 12px 12px; font-size:13px;
                }
                .pmpd-chat-typing {
                    display:flex; gap:5px; align-items:center;
                    padding:12px 16px; background:#f9fafb; border:1px solid #e5e7eb;
                    border-radius:2px 12px 12px 12px; width:fit-content;
                }
                .pmpd-chat-typing span {
                    width:8px; height:8px; border-radius:50%; background:#9ca3af;
                    animation:pmpd-bounce 1.3s infinite;
                }
                .pmpd-chat-typing span:nth-child(2) { animation-delay:.22s; }
                .pmpd-chat-typing span:nth-child(3) { animation-delay:.44s; }
                @keyframes pmpd-bounce {
                    0%,60%,100% { transform:translateY(0); }
                    30%         { transform:translateY(-7px); }
                }
                #pmpd-ai-chat-area { flex-shrink:0; padding:10px 20px; border-top:1px solid #e5e7eb; background:#fff; }
                #pmpd-ai-chat-area textarea {
                    width:100%; box-sizing:border-box; resize:none;
                    border:1px solid #d1d5db; border-radius:6px;
                    padding:8px 12px; font-size:13px; font-family:inherit;
                    transition:border-color .15s;
                }
                #pmpd-ai-chat-area textarea:focus { outline:none; border-color:#60a5fa; }
                #pmpd-ai-chat-row { display:flex; gap:8px; align-items:flex-end; margin-top:6px; }
                #pmpd-ai-chat-row textarea { flex:1; }
            ' );

            wp_enqueue_script(
                'chartjs',
                'https://cdn.jsdelivr.net/npm/chart.js@4.4.8/dist/chart.umd.min.js',
                [],
                '4.4.8',
                true
            );
            wp_enqueue_script(
                'pm-dashboard',
                PMP_PLUGIN_URL . 'assets/js/pm-dashboard.js',
                [ 'jquery', 'chartjs' ],
                PMP_VERSION,
                true
            );
            wp_localize_script( 'pm-dashboard', 'PMPD', [
                'ajax_url'     => admin_url( 'admin-ajax.php' ),
                'nonce'        => wp_create_nonce( 'pmp_admin_nonce' ),
                'portal_nonce' => wp_create_nonce( 'pmp_nonce' ),
                'currency'     => function_exists( 'get_woocommerce_currency_symbol' )
                    ? html_entity_decode( get_woocommerce_currency_symbol() )
                    : '$',
                'portal_url'   => admin_url( 'admin.php?page=' . self::MENU_SLUG ),
                'orders_url'   => admin_url( 'edit.php?post_type=shop_order' ),
                'ai_key_set'    => ( defined( 'PMP_ANTHROPIC_KEY' ) || ! empty( trim( get_option( 'pmp_anthropic_key', '' ) ) ) ) ? '1' : '0',
                'ga4_configured'  => PMP_GA4::is_configured()           ? '1' : '0',
                'gsc_configured'  => PMP_SearchConsole::is_configured() ? '1' : '0',
                'meta_configured'    => PMP_Meta::is_configured()    ? '1' : '0',
                'klaviyo_configured' => PMP_Klaviyo::is_configured() ? '1' : '0',
                'settings_url'       => admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ),
            ] );
        }
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Página principal: Portal
     * ───────────────────────────────────────────────────────────────────────── */

    public function page_portal(): void {
        if ( ! PMP_Settings::is_configured() ) {
            $settings_url = admin_url( 'admin.php?page=' . self::SETTINGS_SLUG );
            echo '<div class="wrap">';
            echo '<div class="notice notice-warning" style="margin-top:20px;"><p>';
            echo '⚠️ El portal no está configurado. Ve a ';
            echo '<a href="' . esc_url( $settings_url ) . '"><strong>Pymes Modernas → Configuración</strong></a>';
            echo ' para ingresar tu API Key.</p></div></div>';
            return;
        }

        // Renderizar el portal (misma función de PMP_Portal)
        echo '<div class="wrap" style="max-width:980px;">';
        echo PMP_Portal::render();
        echo '</div>';
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Subpágina: Configuración
     * ───────────────────────────────────────────────────────────────────────── */

    public function register_settings(): void {
        register_setting( 'pmp_settings_group', 'pmp_api_key',       [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'pmp_settings_group', 'pmp_api_url',       [ 'sanitize_callback' => 'esc_url_raw'         ] );
        register_setting( 'pmp_settings_group', 'pmp_credits_url',   [ 'sanitize_callback' => 'esc_url_raw'         ] );
        register_setting( 'pmp_settings_group', 'pmp_anthropic_key',    [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'pmp_settings_group', 'pmp_ga4_property_id', [ 'sanitize_callback' => 'sanitize_text_field'  ] );
        register_setting( 'pmp_settings_group', 'pmp_ga4_sa_json',    [ 'sanitize_callback' => 'sanitize_textarea_field' ] );
        register_setting( 'pmp_settings_group', 'pmp_meta_access_token',[ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'pmp_settings_group', 'pmp_meta_account_id',  [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'pmp_settings_group', 'pmp_meta_app_id',      [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'pmp_settings_group', 'pmp_meta_app_secret',  [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'pmp_settings_group', 'pmp_klaviyo_api_key', [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'pmp_settings_group', 'pmp_digest_email',    [ 'sanitize_callback' => 'sanitize_email'       ] );
        register_setting( 'pmp_settings_group', 'pmp_gsc_site_url',   [ 'sanitize_callback' => 'sanitize_text_field'   ] );
    }

    public function page_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Sin permiso.' );
        }

        $configured = PMP_Settings::is_configured();
        $nonce      = wp_create_nonce( 'pmp_admin_nonce' );
        ?>
        <div class="wrap">
            <h1 style="display:flex;align-items:center;gap:10px;">
                <svg width="22" height="22" viewBox="0 0 20 20" fill="#1a2e4f" xmlns="http://www.w3.org/2000/svg">
                    <rect x="1"  y="10" width="4" height="9" rx="1"/>
                    <rect x="8"  y="5"  width="4" height="14" rx="1"/>
                    <rect x="15" y="1"  width="4" height="18" rx="1"/>
                </svg>
                Pymes Modernas — Configuración
            </h1>

            <?php if ( $configured ): ?>
            <div class="notice notice-success is-dismissible"><p>✅ Plugin configurado. El portal está activo en <strong>Pymes Modernas → Mi Portal</strong>.</p></div>
            <?php else: ?>
            <div class="notice notice-warning"><p>⚠️ Ingresa tu API Key y la URL de operaciones para activar el portal.</p></div>
            <?php endif; ?>

            <div id="pmp-test-notice" style="display:none;margin-bottom:16px;"></div>

            <form method="post" action="options.php" style="max-width:680px;">
                <?php settings_fields( 'pmp_settings_group' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="pmp_api_url">URL del sitio de operaciones</label></th>
                        <td>
                            <input type="url" name="pmp_api_url" id="pmp_api_url" class="large-text"
                                   value="<?= esc_attr( PMP_Settings::get_api_url() ) ?>"
                                   placeholder="https://operaciones.pymesmodernas.com">
                            <p class="description">URL base donde está instalado el EAM (sin barra final).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pmp_api_key">API Key</label></th>
                        <td>
                            <input type="text" name="pmp_api_key" id="pmp_api_key" class="large-text"
                                   value="<?= esc_attr( PMP_Settings::get_api_key() ) ?>"
                                   placeholder="Generada en EAM → Portal Cliente → API Keys"
                                   autocomplete="off">
                            <p class="description">Cópiala desde <strong>operaciones.pymesmodernas.com → EAM → Portal Cliente → API Keys</strong>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pmp_credits_url">URL de compra de créditos</label></th>
                        <td>
                            <input type="url" name="pmp_credits_url" id="pmp_credits_url" class="large-text"
                                   value="<?= esc_attr( get_option( 'pmp_credits_url', '' ) ) ?>"
                                   placeholder="https://operaciones.pymesmodernas.com/producto/creditos/">
                            <p class="description">Opcional. El botón "Comprar créditos" apunta aquí. Si está vacío usa la URL por defecto.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row" style="padding-top:24px;border-top:1px solid #eee;">
                            <label for="pmp_anthropic_key">🤖 API Key de Claude (IA)</label>
                        </th>
                        <td style="padding-top:24px;border-top:1px solid #eee;">
                            <input type="password" name="pmp_anthropic_key" id="pmp_anthropic_key" class="large-text"
                                   value="<?= esc_attr( get_option( 'pmp_anthropic_key', '' ) ) ?>"
                                   placeholder="sk-ant-api03-…"
                                   autocomplete="off">
                            <p class="description">
                                Opcional. Activa el botón <strong>"🤖 Analizador Pymes Modernas"</strong> en el panel Resumen — Claude analiza
                                los datos de tu tienda y da insights accionables.<br>
                                Obtén tu key en <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener">console.anthropic.com → API Keys</a>
                                (es gratis registrarse; el costo es por uso, muy bajo).<br>
                                Alternativa avanzada: define <code>define('PMP_ANTHROPIC_KEY', 'sk-ant-…');</code> en <code>wp-config.php</code>
                                — esa constante toma prioridad sobre este campo.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th colspan="2" style="padding-top:28px;padding-bottom:0;">
                            <h2 style="margin:0;padding:16px 0 4px;font-size:14px;
                                       border-top:2px solid #1a2e4f;color:#1a2e4f;">
                                📊 Google Analytics 4
                            </h2>
                            <p style="font-weight:normal;color:#6b7280;font-size:12px;margin:0 0 4px;">
                                Opcional. Añade datos de tráfico web al análisis de IA: canales de adquisición,
                                tasa de rebote, páginas más visitadas y conversiones.
                            </p>
                        </th>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pmp_ga4_property_id">Property ID</label></th>
                        <td>
                            <input type="text" name="pmp_ga4_property_id" id="pmp_ga4_property_id"
                                   class="regular-text"
                                   value="<?= esc_attr( get_option( 'pmp_ga4_property_id', '' ) ) ?>"
                                   placeholder="123456789">
                            <p class="description">GA4 → Administrar → Detalles de la propiedad → Property ID (solo números).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pmp_ga4_sa_json">Service Account JSON</label></th>
                        <td>
                            <textarea name="pmp_ga4_sa_json" id="pmp_ga4_sa_json"
                                      class="large-text" rows="6"
                                      placeholder='{"type":"service_account","project_id":"...","private_key":"-----BEGIN RSA PRIVATE KEY-----\n...","client_email":"pm-analytics@....iam.gserviceaccount.com",...}'
                                      autocomplete="off"><?= esc_textarea( get_option( 'pmp_ga4_sa_json', '' ) ) ?></textarea>
                            <p class="description">
                                Pega aquí el contenido completo del archivo JSON descargado desde
                                Google Cloud Console → IAM → Cuentas de servicio → Claves.
                                <?php if ( PMP_GA4::is_configured() ) : ?>
                                    <br><button type="button" id="pmp-ga4-test-btn" class="button button-small" style="margin-top:6px;">
                                        🔍 Probar conexión GA4
                                    </button>
                                    <span id="pmp-ga4-test-result" style="margin-left:8px;font-size:12px;"></span>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>

                    <!-- ── Meta Ads ── -->
                    <tr>
                        <th colspan="2" style="padding-top:28px;padding-bottom:0;">
                            <h2 style="margin:0;padding:16px 0 4px;font-size:14px;
                                       border-top:2px solid #1a2e4f;color:#1a2e4f;">
                                📣 Meta Ads (Facebook & Instagram)
                            </h2>
                            <p style="font-weight:normal;color:#6b7280;font-size:12px;margin:0 0 4px;">
                                Opcional. Muestra gasto, ROAS y campañas en el Resumen, y añade esos datos al análisis de IA.<br>
                                Recomendado: usa un <strong>System User Token</strong> en Business Manager (no expira).
                                <a href="https://developers.facebook.com/docs/marketing-api/system-users" target="_blank" rel="noopener">Ver guía →</a>
                            </p>
                        </th>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pmp_meta_account_id">Ad Account ID</label></th>
                        <td>
                            <input type="text" name="pmp_meta_account_id" id="pmp_meta_account_id"
                                   class="regular-text"
                                   value="<?= esc_attr( get_option( 'pmp_meta_account_id', '' ) ) ?>"
                                   placeholder="act_123456789  o  123456789">
                            <p class="description">Business Manager → Cuentas publicitarias → ID de la cuenta. Con o sin prefijo <code>act_</code>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pmp_meta_access_token">Access Token</label></th>
                        <td>
                            <input type="password" name="pmp_meta_access_token" id="pmp_meta_access_token"
                                   class="large-text"
                                   value="<?= esc_attr( get_option( 'pmp_meta_access_token', '' ) ) ?>"
                                   placeholder="EAAxxxxx…"
                                   autocomplete="off">
                            <p class="description">Token de larga duración. Obtenlo en Graph API Explorer con scope <code>ads_read</code>, o usa un System User Token de Business Manager.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pmp_meta_app_id">App ID <span style="font-weight:normal;color:#9ca3af;">(opcional)</span></label></th>
                        <td>
                            <input type="text" name="pmp_meta_app_id" id="pmp_meta_app_id"
                                   class="regular-text"
                                   value="<?= esc_attr( get_option( 'pmp_meta_app_id', '' ) ) ?>"
                                   placeholder="123456789012345">
                            <p class="description">Necesario solo para verificar expiración del token en "Probar conexión".</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pmp_meta_app_secret">App Secret <span style="font-weight:normal;color:#9ca3af;">(opcional)</span></label></th>
                        <td>
                            <input type="password" name="pmp_meta_app_secret" id="pmp_meta_app_secret"
                                   class="regular-text"
                                   value="<?= esc_attr( get_option( 'pmp_meta_app_secret', '' ) ) ?>"
                                   placeholder="abc123…"
                                   autocomplete="off">
                            <p class="description">
                                <?php if ( PMP_Meta::is_configured() ) : ?>
                                    <button type="button" id="pmp-meta-test-btn" class="button button-small" style="margin-bottom:4px;">
                                        🔍 Probar conexión Meta Ads
                                    </button>
                                    <span id="pmp-meta-test-result" style="margin-left:8px;font-size:12px;display:block;margin-top:4px;"></span>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                    <!-- ── Klaviyo ── -->
                    <tr>
                        <th colspan="2" style="padding-top:28px;padding-bottom:0;">
                            <h2 style="margin:0;padding:16px 0 4px;font-size:14px;
                                       border-top:2px solid #1a2e4f;color:#1a2e4f;">
                                📧 Klaviyo (Email Marketing)
                            </h2>
                            <p style="font-weight:normal;color:#6b7280;font-size:12px;margin:0 0 4px;">
                                Opcional. Añade métricas de email al panel Resumen y al análisis de IA: emails enviados,
                                tasa de apertura, clics, desuscripciones e ingresos atribuidos.
                                Usa una <strong>Private API Key</strong> (no la pública).<br>
                                En Klaviyo → Settings → Account → API Keys → <strong>Create Private API Key</strong>
                                (scopes mínimos: <code>metrics:read</code>, <code>campaigns:read</code>).
                            </p>
                        </th>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pmp_klaviyo_api_key">Private API Key</label></th>
                        <td>
                            <input type="password" name="pmp_klaviyo_api_key" id="pmp_klaviyo_api_key"
                                   class="large-text"
                                   value="<?= esc_attr( get_option( 'pmp_klaviyo_api_key', '' ) ) ?>"
                                   placeholder="pk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                                   autocomplete="off">
                            <p class="description">
                                Empieza con <code>pk_</code>. Obtén la key en
                                <a href="https://www.klaviyo.com/settings/account/api-keys" target="_blank" rel="noopener">Klaviyo → Settings → API Keys</a>.
                                <?php if ( PMP_Klaviyo::is_configured() ) : ?>
                                    <br>
                                    <button type="button" id="pmp-klaviyo-test-btn" class="button button-small" style="margin-top:6px;">
                                        🔍 Probar conexión Klaviyo
                                    </button>
                                    <span id="pmp-klaviyo-test-result" style="margin-left:8px;font-size:12px;display:block;margin-top:4px;"></span>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                    <!-- ── Google Search Console ── -->
                    <tr>
                        <th colspan="2" style="padding-top:28px;padding-bottom:0;">
                            <h2 style="margin:0;padding:16px 0 4px;font-size:14px;
                                       border-top:2px solid #1a2e4f;color:#1a2e4f;">
                                🔍 Google Search Console
                            </h2>
                            <p style="font-weight:normal;color:#6b7280;font-size:12px;margin:0 0 4px;">
                                Reutiliza el mismo Service Account JSON de GA4. Solo agrega el email de la cuenta de servicio como usuario en Search Console del sitio.
                            </p>
                        </th>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pmp_gsc_site_url">URL de la propiedad</label></th>
                        <td>
                            <input type="url" name="pmp_gsc_site_url" id="pmp_gsc_site_url"
                                   class="large-text"
                                   value="<?= esc_attr( get_option( 'pmp_gsc_site_url', '' ) ) ?>"
                                   placeholder="https://ejemplo.com/">
                            <p class="description">
                                Debe coincidir exactamente con la propiedad en Search Console (con o sin <code>www</code>, con <code>/</code> al final).
                                Para propiedades de dominio usa: <code>sc-domain:ejemplo.com</code>
                                <?php if ( PMP_SearchConsole::is_configured() ) : ?>
                                    <br><button type="button" id="pmp-gsc-test-btn" class="button button-small" style="margin-top:6px;">
                                        🔍 Probar conexión Search Console
                                    </button>
                                    <span id="pmp-gsc-test-result" style="margin-left:8px;font-size:12px;"></span>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>

                    <!-- ── Resumen semanal ── -->
                    <tr>
                        <th colspan="2" style="padding-top:28px;padding-bottom:0;">
                            <h2 style="margin:0;padding:16px 0 4px;font-size:14px;
                                       border-top:2px solid #1a2e4f;color:#1a2e4f;">
                                📬 Resumen semanal por correo
                            </h2>
                            <p style="font-weight:normal;color:#6b7280;font-size:12px;margin:0 0 4px;">
                                Se envía automáticamente cada lunes a las 7am (Costa Rica) con pedidos de la semana, visitantes y un análisis breve de Claude.
                            </p>
                        </th>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pmp_digest_email">Email destinatario</label></th>
                        <td>
                            <input type="email" name="pmp_digest_email" id="pmp_digest_email"
                                   class="regular-text"
                                   value="<?= esc_attr( get_option( 'pmp_digest_email', '' ) ) ?>"
                                   placeholder="michael@pymesmodernas.com">
                            <p class="description">Deja vacío para desactivar el envío.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Guardar configuración' ); ?>
            </form>

            <hr style="max-width:680px;">
            <h2>Prueba de conexión</h2>
            <p>Verifica que la API Key y la URL son correctas.</p>
            <button id="pmp-test-btn" class="button button-secondary">Probar conexión</button>

            <?php if ( $configured && PMP_WooCommerce::is_active() ) : ?>
            <hr style="max-width:680px;">
            <h2>Sincronización histórica de pedidos</h2>
            <p style="max-width:620px;">
                Envía al EAM todos los pedidos de WooCommerce registrados
                <strong>desde el 1&nbsp;de&nbsp;mayo de&nbsp;2026</strong> hasta hoy,
                independientemente de su estado. Los pedidos ya enviados se actualizarán
                sin duplicarse.
            </p>

            <div id="pmp-backfill-area">
                <button id="pmp-backfill-btn" class="button button-primary">
                    🔄 Sincronizar pedidos históricos
                </button>

                <div id="pmp-backfill-progress" style="display:none;margin-top:16px;max-width:560px;">
                    <div style="background:#e5e7eb;border-radius:20px;height:12px;overflow:hidden;">
                        <div id="pmp-backfill-bar"
                             style="height:100%;width:0%;background:linear-gradient(90deg,#4f46e5,#818cf8);
                                    border-radius:20px;transition:width .3s ease;">
                        </div>
                    </div>
                    <p id="pmp-backfill-status" style="margin:8px 0 0;font-size:13px;color:#6b7280;">
                        Iniciando…
                    </p>
                </div>

                <div id="pmp-backfill-result" style="display:none;margin-top:12px;"></div>
            </div>
            <?php endif; ?>

            <hr style="max-width:680px;">
            <h2>Guía de instalación</h2>
            <ol style="max-width:620px;line-height:1.9;">
                <li>En <strong>operaciones.pymesmodernas.com</strong> → EAM → Portal Cliente → API Keys → <strong>Generar</strong> para este cliente.</li>
                <li>Copia la key y pégala en el campo <strong>API Key</strong> de arriba.</li>
                <li>Ingresa la URL del sitio de operaciones.</li>
                <li>Guarda y pulsa <strong>Probar conexión</strong>.</li>
                <li>El portal aparece automáticamente en el menú lateral: <strong>Pymes Modernas → Mi Portal</strong>.</li>
            </ol>
        </div>

        <script>
        (function($){
            var nonce   = '<?= esc_js( $nonce ) ?>';
            var ajaxUrl = '<?= esc_js( admin_url('admin-ajax.php') ) ?>';

            function notice(msg, type) {
                var $n = $('#pmp-test-notice');
                $n.attr('class', 'notice notice-' + (type || 'success') + ' is-dismissible')
                  .html('<p>' + msg + '</p>').show();
                setTimeout(function(){ $n.fadeOut(); }, 5000);
            }

            $('#pmp-test-btn').on('click', function(){
                var $btn = $(this);
                $btn.prop('disabled', true).text('Probando…');
                $.post(ajaxUrl, { action: 'pmp_test_connection', nonce: nonce }, function(r){
                    if (r.success) notice('✅ ' + r.data.message, 'success');
                    else           notice('❌ ' + (r.data && r.data.message ? r.data.message : 'Error de conexión.'), 'error');
                    $btn.prop('disabled', false).text('Probar conexión');
                });
            });

            /* ── Backfill histórico ── */
            var backfillTotal    = 0;
            var backfillDone     = 0;
            var backfillErrors   = 0;
            var backfillRunning  = false;

            $('#pmp-backfill-btn').on('click', function(){
                if (backfillRunning) return;
                if (!confirm('¿Iniciar sincronización de pedidos históricos desde el 1 de mayo de 2026?')) return;

                backfillTotal   = 0;
                backfillDone    = 0;
                backfillErrors  = 0;
                backfillRunning = true;

                $(this).prop('disabled', true).text('Sincronizando…');
                $('#pmp-backfill-progress').show();
                $('#pmp-backfill-result').hide();
                $('#pmp-backfill-bar').css('width', '0%');
                $('#pmp-backfill-status').text('Iniciando…');

                runBackfillBatch(0);
            });

            function runBackfillBatch(offset) {
                $.post(ajaxUrl, {
                    action : 'pmp_backfill_orders',
                    nonce  : nonce,
                    offset : offset,
                }, function(r) {
                    if (!r || !r.success) {
                        var msg = (r && r.data && r.data.message) ? r.data.message : 'Error inesperado.';
                        backfillFinish(false, msg);
                        return;
                    }

                    var d = r.data;

                    // Primera llamada trae el total
                    if (offset === 0 && d.total > 0) backfillTotal = d.total;

                    backfillDone   += d.processed;
                    backfillErrors += d.errors;

                    var pct = backfillTotal > 0
                        ? Math.min(100, Math.round(backfillDone / backfillTotal * 100))
                        : 0;

                    $('#pmp-backfill-bar').css('width', pct + '%');
                    $('#pmp-backfill-status').text(
                        backfillDone + ' de ' + backfillTotal + ' pedidos procesados'
                        + (backfillErrors > 0 ? ' (' + backfillErrors + ' errores)' : '')
                    );

                    if (d.done) {
                        backfillFinish(true);
                    } else {
                        // Pequeña pausa antes del siguiente lote para no saturar el EAM
                        setTimeout(function(){ runBackfillBatch(d.next_offset); }, 300);
                    }
                }).fail(function(){
                    backfillFinish(false, 'La petición falló. Revisa la conexión e inténtalo de nuevo.');
                });
            }

            /* ── Test GA4 ── */
            $('#pmp-ga4-test-btn').on('click', function(){
                var $btn = $(this);
                var $res = $('#pmp-ga4-test-result');
                $btn.prop('disabled', true).text('Probando…');
                $res.text('').css('color', '#6b7280');
                $.post(ajaxUrl, { action: 'pmp_test_ga4', nonce: nonce }, function(r){
                    if (r.success) {
                        $res.css('color', '#16a34a').text('✅ ' + r.data.message);
                    } else {
                        $res.css('color', '#dc2626').text('❌ ' + (r.data && r.data.message ? r.data.message : 'Error.'));
                    }
                    $btn.prop('disabled', false).text('🔍 Probar conexión GA4');
                });
            });

            /* ── Test Search Console ── */
            $('#pmp-gsc-test-btn').on('click', function(){
                var $btn = $(this);
                var $res = $('#pmp-gsc-test-result');
                $btn.prop('disabled', true).text('Probando…');
                $res.text('').css('color', '#6b7280');
                $.post(ajaxUrl, { action: 'pmp_test_gsc', nonce: nonce }, function(r){
                    if (r.success) {
                        $res.css('color', '#16a34a').text('✅ ' + r.data.message);
                    } else {
                        $res.css('color', '#dc2626').text('❌ ' + (r.data && r.data.message ? r.data.message : 'Error.'));
                    }
                    $btn.prop('disabled', false).text('🔍 Probar conexión Search Console');
                });
            });

            /* ── Test Meta Ads ── */
            $('#pmp-meta-test-btn').on('click', function(){
                var $btn = $(this);
                var $res = $('#pmp-meta-test-result');
                $btn.prop('disabled', true).text('Probando…');
                $res.text('').css('color', '#6b7280');
                $.post(ajaxUrl, { action: 'pmp_test_meta', nonce: nonce }, function(r){
                    if (r.success) {
                        $res.css('color', '#16a34a').text('✅ ' + r.data.message);
                    } else {
                        $res.css('color', '#dc2626').text('❌ ' + (r.data && r.data.message ? r.data.message : 'Error.'));
                    }
                    $btn.prop('disabled', false).text('🔍 Probar conexión Meta Ads');
                });
            });

            /* ── Test Klaviyo ── */
            $('#pmp-klaviyo-test-btn').on('click', function(){
                var $btn = $(this);
                var $res = $('#pmp-klaviyo-test-result');
                $btn.prop('disabled', true).text('Probando…');
                $res.text('').css('color', '#6b7280');
                $.post(ajaxUrl, { action: 'pmp_test_klaviyo', nonce: nonce }, function(r){
                    if (r.success) {
                        $res.css('color', '#16a34a').text('✅ ' + r.data.message);
                    } else {
                        $res.css('color', '#dc2626').text('❌ ' + (r.data && r.data.message ? r.data.message : 'Error.'));
                    }
                    $btn.prop('disabled', false).text('🔍 Probar conexión Klaviyo');
                });
            });

            function backfillFinish(ok, errorMsg) {
                backfillRunning = false;
                $('#pmp-backfill-btn').prop('disabled', false).text('🔄 Sincronizar pedidos históricos');

                var $result = $('#pmp-backfill-result').show();
                if (ok) {
                    $('#pmp-backfill-bar').css('width', '100%');
                    $('#pmp-backfill-status').text(backfillDone + ' pedidos procesados. ¡Listo!');
                    $result.html(
                        '<div class="notice notice-success" style="margin:0;">'
                        + '<p>✅ Sincronización completa. <strong>' + backfillDone + '</strong> pedidos enviados al EAM'
                        + (backfillErrors > 0
                            ? ' (<strong>' + backfillErrors + '</strong> no se pudieron enviar — revisa los logs de Action Scheduler).'
                            : '.')
                        + '</p></div>'
                    );
                } else {
                    $result.html(
                        '<div class="notice notice-error" style="margin:0;"><p>❌ '
                        + (errorMsg || 'Error durante la sincronización.') + '</p></div>'
                    );
                }
            }
        }(jQuery));
        </script>
        <?php
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * AJAX — Prueba de conexión
     * ───────────────────────────────────────────────────────────────────────── */

    public function ajax_test_connection(): void {
        if ( ! check_ajax_referer( 'pmp_admin_nonce', 'nonce', false )
            || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Sin permiso.' ], 403 );
        }
        if ( ! PMP_Settings::is_configured() ) {
            wp_send_json_error( [ 'message' => 'Guarda primero la API Key y la URL.' ] );
        }
        $result = ( new PMP_API_Client() )->test_connection();
        $result['ok']
            ? wp_send_json_success( [ 'message' => $result['message'] ] )
            : wp_send_json_error(   [ 'message' => $result['message'] ] );
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * AJAX — Backfill histórico de pedidos WooCommerce → EAM
     * ───────────────────────────────────────────────────────────────────────── */

    public function ajax_backfill_orders(): void {
        if ( ! check_ajax_referer( 'pmp_admin_nonce', 'nonce', false )
            || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Sin permiso.' ], 403 );
        }

        if ( ! PMP_Settings::is_configured() ) {
            wp_send_json_error( [ 'message' => 'El plugin no está configurado.' ] );
        }

        if ( ! PMP_WooCommerce::is_active() ) {
            wp_send_json_error( [ 'message' => 'WooCommerce no está activo.' ] );
        }

        $batch_size  = 10;
        $offset      = max( 0, (int) ( $_POST['offset'] ?? 0 ) );
        $date_from   = '2026-05-01';
        $date_to     = date( 'Y-m-d' );
        $date_range  = $date_from . '...' . $date_to;
        $all_statuses = [
            'pending', 'processing', 'completed',
            'on-hold', 'cancelled',  'refunded', 'failed',
        ];

        // ── Primera llamada: contar el total para la barra de progreso ──────
        $total = -1;
        if ( $offset === 0 ) {
            $count_result = wc_get_orders( [
                'status'       => $all_statuses,
                'date_created' => $date_range,
                'limit'        => 1,
                'paginate'     => true,
            ] );
            $total = isset( $count_result->total ) ? (int) $count_result->total : 0;
        }

        // ── Obtener el lote ─────────────────────────────────────────────────
        $orders = wc_get_orders( [
            'status'       => $all_statuses,
            'date_created' => $date_range,
            'limit'        => $batch_size,
            'offset'       => $offset,
            'orderby'      => 'date',
            'order'        => 'ASC',
            'return'       => 'objects',
        ] );

        $processed = 0;
        $errors    = 0;
        $api       = new PMP_API_Client();

        foreach ( $orders as $order ) {
            $result = $api->push_order( PMP_Order_Sync::build_payload( $order ) );
            if ( $result['error'] ) $errors++;
            else                    $processed++;
        }

        $count_in_batch = count( $orders );
        $done           = $count_in_batch < $batch_size;

        wp_send_json_success( [
            'processed'   => $processed,
            'errors'      => $errors,
            'next_offset' => $offset + $count_in_batch,
            'done'        => $done,
            'total'       => $total,   // -1 en lotes > 0 (no se recalcula)
        ] );
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * AJAX — Crear página (ya no es necesario, se mantiene por compatibilidad)
     * ───────────────────────────────────────────────────────────────────────── */

    public function ajax_create_portal_page(): void {
        wp_send_json_error( [ 'message' => 'El portal ahora vive en el menú de administración. No es necesaria una página.' ] );
    }

    /* ═════════════════════════════════════════════════════════════════════════
     * SUBPÁGINA: RESUMEN (Dashboard de ecommerce)
     * ═════════════════════════════════════════════════════════════════════════ */

    public function page_dashboard(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Sin permiso.' );
        }

        if ( ! PMP_WooCommerce::is_active() ) {
            echo '<div class="wrap"><div class="notice notice-warning" style="margin-top:20px;"><p>'
                . '⚠️ <strong>WooCommerce</strong> no está activo. El panel Resumen requiere WooCommerce.'
                . '</p></div></div>';
            return;
        }

        $news_url   = esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&pmp_tab=news' ) );
        $orders_url = esc_url( admin_url( 'edit.php?post_type=shop_order' ) );
        ?>
        <div class="wrap pmpd-wrap" id="pmpd-dashboard">

            <!-- Cabecera de página -->
            <div class="pmpd-page-header">
                <div class="pmpd-page-title-wrap">
                    <h1 class="pmpd-page-title">Resumen de tu tienda</h1>
                    <span class="pmpd-page-subtitle">Panel de rendimiento del ecommerce</span>
                </div>
                <div class="pmpd-quick-actions">
                    <button class="button" id="pmpd-ai-insights" title="Analizador de tienda con IA — Pymes Modernas">🤖 Analizador Pymes Modernas</button>
                    <button class="button button-primary" id="pmpd-open-ticket">🎫 Enviar Ticket</button>
                    <a href="<?= $news_url ?>" class="button">📰 Noticias PM</a>
                </div>
            </div>

            <!-- Barra de filtros -->
            <div class="pmpd-filters-bar">
                <div class="pmpd-range-btns">
                    <button class="pmpd-range active" data-range="30">Últ. 30 días</button>
                    <button class="pmpd-range" data-range="7">Esta semana</button>
                    <button class="pmpd-range" data-range="month">Este mes</button>
                    <button class="pmpd-range" data-range="prev_month">Mes anterior</button>
                </div>
                <div class="pmpd-custom-range">
                    <input type="date" id="pmpd-from" class="pmpd-date-input">
                    <span class="pmpd-range-sep">—</span>
                    <input type="date" id="pmpd-to" class="pmpd-date-input">
                    <button id="pmpd-apply-custom" class="button">Aplicar</button>
                </div>
                <div class="pmpd-status-filter">
                    <label for="pmpd-status">Estado:</label>
                    <select id="pmpd-status" class="pmpd-select">
                        <option value="">Todos los estados</option>
                        <option value="wc-completed">Completado</option>
                        <option value="wc-processing">Procesando</option>
                        <option value="wc-pending">Pendiente</option>
                        <option value="wc-on-hold">En espera</option>
                        <option value="wc-cancelled">Cancelado</option>
                        <option value="wc-refunded">Reembolsado</option>
                    </select>
                </div>
                <span id="pmpd-loading" style="display:none;">
                    <span class="spinner is-active" style="float:none;vertical-align:middle;margin:0 4px 0 0;"></span>
                    <small style="color:#6b7280;">Actualizando…</small>
                </span>
            </div>

            <!-- KPIs -->
            <div class="pmpd-kpi-row">
                <div class="pmpd-kpi-card">
                    <span class="pmpd-kpi-icon">💰</span>
                    <span class="pmpd-kpi-value" id="pmpd-kpi-revenue">—</span>
                    <span class="pmpd-kpi-label">Ingresos netos</span>
                </div>
                <div class="pmpd-kpi-card">
                    <span class="pmpd-kpi-icon">📦</span>
                    <span class="pmpd-kpi-value" id="pmpd-kpi-orders">—</span>
                    <span class="pmpd-kpi-label">Pedidos</span>
                </div>
                <div class="pmpd-kpi-card">
                    <span class="pmpd-kpi-icon">🛒</span>
                    <span class="pmpd-kpi-value" id="pmpd-kpi-items">—</span>
                    <span class="pmpd-kpi-label">Unidades vendidas</span>
                </div>
                <div class="pmpd-kpi-card">
                    <span class="pmpd-kpi-icon">📈</span>
                    <span class="pmpd-kpi-value" id="pmpd-kpi-avg">—</span>
                    <span class="pmpd-kpi-label">Ticket promedio</span>
                </div>
            </div>

            <?php if ( PMP_GA4::is_configured() ) : ?>
            <!-- Tráfico web — Google Analytics 4 -->
            <div class="pmpd-card pmpd-card-full" id="pmpd-ga4-section">
                <div class="pmpd-card-header">
                    <h2 class="pmpd-card-title">
                        📊 Tráfico web
                        <span style="font-size:11px;font-weight:normal;color:#9ca3af;margin-left:6px;">Google Analytics 4</span>
                    </h2>
                    <span id="pmpd-ga4-badge" class="pmpd-card-badge"></span>
                </div>
                <div id="pmpd-ga4-body" style="min-height:80px;">
                    <div style="text-align:center;padding:24px;color:#9ca3af;font-size:13px;">
                        <span class="spinner is-active" style="float:none;display:inline-block;"></span>
                        <span style="display:block;margin-top:8px;">Cargando Analytics…</span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ( PMP_SearchConsole::is_configured() ) : ?>
            <!-- SEO — Google Search Console -->
            <div class="pmpd-card pmpd-card-full" id="pmpd-gsc-section">
                <div class="pmpd-card-header">
                    <h2 class="pmpd-card-title">
                        🔍 SEO orgánico
                        <span style="font-size:11px;font-weight:normal;color:#9ca3af;margin-left:6px;">Google Search Console</span>
                    </h2>
                    <span id="pmpd-gsc-badge" class="pmpd-card-badge"></span>
                </div>
                <div id="pmpd-gsc-body" style="min-height:80px;">
                    <div style="text-align:center;padding:24px;color:#9ca3af;font-size:13px;">
                        <span class="spinner is-active" style="float:none;display:inline-block;"></span>
                        <span style="display:block;margin-top:8px;">Cargando Search Console…</span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ( PMP_Meta::is_configured() ) : ?>
            <!-- Publicidad — Meta Ads -->
            <div class="pmpd-card pmpd-card-full" id="pmpd-meta-section">
                <div class="pmpd-card-header">
                    <h2 class="pmpd-card-title">
                        📣 Publicidad
                        <span style="font-size:11px;font-weight:normal;color:#9ca3af;margin-left:6px;">Meta · Facebook & Instagram</span>
                    </h2>
                    <span id="pmpd-meta-badge" class="pmpd-card-badge"></span>
                </div>
                <div id="pmpd-meta-body" style="min-height:80px;">
                    <div style="text-align:center;padding:24px;color:#9ca3af;font-size:13px;">
                        <span class="spinner is-active" style="float:none;display:inline-block;"></span>
                        <span style="display:block;margin-top:8px;">Cargando Ads…</span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ( PMP_Klaviyo::is_configured() ) : ?>
            <!-- Email Marketing — Klaviyo -->
            <div class="pmpd-card pmpd-card-full" id="pmpd-klaviyo-section">
                <div class="pmpd-card-header">
                    <h2 class="pmpd-card-title">
                        📧 Email Marketing
                        <span style="font-size:11px;font-weight:normal;color:#9ca3af;margin-left:6px;">Klaviyo</span>
                    </h2>
                    <span id="pmpd-klaviyo-badge" class="pmpd-card-badge"></span>
                </div>
                <div id="pmpd-klaviyo-body" style="min-height:80px;">
                    <div style="text-align:center;padding:24px;color:#9ca3af;font-size:13px;">
                        <span class="spinner is-active" style="float:none;display:inline-block;"></span>
                        <span style="display:block;margin-top:8px;">Cargando Klaviyo…</span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Gráfica de tendencia (ancho completo) -->
            <div class="pmpd-card pmpd-card-full">
                <div class="pmpd-card-header">
                    <h2 class="pmpd-card-title">Tendencia de ventas</h2>
                    <span id="pmpd-chart-range" class="pmpd-card-badge"></span>
                </div>
                <div class="pmpd-chart-wrap">
                    <canvas id="pmpd-sales-chart"></canvas>
                </div>
            </div>

            <!-- Dos columnas: dona de estados + barras de top productos -->
            <div class="pmpd-two-col">
                <div class="pmpd-card">
                    <div class="pmpd-card-header">
                        <h2 class="pmpd-card-title">Pedidos por estado</h2>
                    </div>
                    <div class="pmpd-chart-wrap pmpd-chart-sm">
                        <canvas id="pmpd-status-chart"></canvas>
                    </div>
                </div>
                <div class="pmpd-card">
                    <div class="pmpd-card-header">
                        <h2 class="pmpd-card-title">Top productos vendidos</h2>
                    </div>
                    <div class="pmpd-chart-wrap pmpd-chart-sm">
                        <canvas id="pmpd-products-chart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Tabla de pedidos recientes (ancho completo) -->
            <div class="pmpd-card pmpd-card-full">
                <div class="pmpd-card-header">
                    <h2 class="pmpd-card-title">Últimos pedidos</h2>
                    <a href="<?= $orders_url ?>" class="button button-small" target="_blank" rel="noopener">Ver todos en WooCommerce →</a>
                </div>
                <div class="pmpd-table-wrap">
                    <table class="wp-list-table widefat fixed striped pmpd-orders-table">
                        <thead>
                            <tr>
                                <th style="width:70px">#</th>
                                <th>Cliente</th>
                                <th style="width:60px">Ítems</th>
                                <th style="width:110px">Total</th>
                                <th style="width:130px">Estado</th>
                                <th style="width:100px">Fecha</th>
                                <th style="width:70px"></th>
                            </tr>
                        </thead>
                        <tbody id="pmpd-orders-tbody">
                            <tr><td colspan="7" class="pmpd-table-empty">Cargando…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Dos columnas: mejores clientes + productos dormidos -->
            <div class="pmpd-two-col">
                <div class="pmpd-card">
                    <div class="pmpd-card-header">
                        <h2 class="pmpd-card-title">Mejores clientes</h2>
                    </div>
                    <div id="pmpd-customers-list" class="pmpd-list"></div>
                </div>
                <div class="pmpd-card">
                    <div class="pmpd-card-header">
                        <h2 class="pmpd-card-title">Productos sin ventas <span class="pmpd-badge-warn">+6 meses</span></h2>
                    </div>
                    <div id="pmpd-dormant-list" class="pmpd-list"></div>
                </div>
            </div>

            <!-- Modal: Enviar Ticket -->
            <div id="pmpd-ticket-modal" class="pmpd-modal-overlay" style="display:none;"
                 role="dialog" aria-modal="true" aria-labelledby="pmpd-modal-title">
                <div class="pmpd-modal" role="document">
                    <div class="pmpd-modal-header">
                        <h2 class="pmpd-modal-title" id="pmpd-modal-title">🎫 Enviar Ticket de Soporte</h2>
                        <button class="pmpd-modal-close" aria-label="Cerrar">✕</button>
                    </div>
                    <div class="pmpd-modal-body">
                        <form id="pmpd-ticket-form">
                            <div class="pmpd-form-group">
                                <label for="pmpd-ticket-title">Solicitud <span class="pmpd-req">*</span></label>
                                <input type="text" id="pmpd-ticket-title" name="title"
                                       class="large-text" placeholder="¿Qué necesitas?" required>
                            </div>
                            <div class="pmpd-form-row">
                                <div class="pmpd-form-group">
                                    <label>Tipo</label>
                                    <select name="ticket_type" id="pmpd-ticket-type" class="pmpd-select">
                                        <option value="">Selecciona un tipo…</option>
                                    </select>
                                </div>
                                <div class="pmpd-form-group">
                                    <label>Prioridad</label>
                                    <select name="priority" class="pmpd-select">
                                        <option value="baja">Baja — puede esperar</option>
                                        <option value="media" selected>Media — pronto</option>
                                        <option value="alta">Alta — esta semana</option>
                                        <option value="urgente">Urgente — lo antes posible</option>
                                    </select>
                                </div>
                            </div>
                            <div class="pmpd-form-group">
                                <label>Notas adicionales</label>
                                <textarea name="client_notes" class="pmpd-textarea" rows="3"
                                          placeholder="Links, contexto, capturas de pantalla…"></textarea>
                            </div>
                            <div class="pmpd-form-actions">
                                <button type="submit" class="button button-primary" id="pmpd-ticket-submit">
                                    Enviar solicitud
                                </button>
                                <span id="pmpd-ticket-msg" style="display:none;margin-left:12px;font-size:13px;"></span>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Modal: Analizador Pymes Modernas (chat conversacional) -->
            <div id="pmpd-ai-modal" class="pmpd-modal-overlay" style="display:none;"
                 role="dialog" aria-modal="true" aria-labelledby="pmpd-ai-modal-title">
                <div class="pmpd-modal" role="document" style="max-width:680px;width:95%;">
                    <div class="pmpd-modal-header" style="flex-shrink:0;">
                        <h2 class="pmpd-modal-title" id="pmpd-ai-modal-title">🤖 Analizador Pymes Modernas</h2>
                        <button class="pmpd-modal-close" aria-label="Cerrar">✕</button>
                    </div>
                    <!-- Hilo de conversación -->
                    <div class="pmpd-modal-body" id="pmpd-ai-modal-body"></div>
                    <!-- Input de chat (visible solo después del primer análisis) -->
                    <div id="pmpd-ai-chat-area" style="display:none;">
                        <p style="margin:0 0 6px;font-size:11px;color:#9ca3af;">
                            💬 Puedes seguir preguntando sobre el análisis — Claude recuerda el contexto de esta tienda.
                        </p>
                        <div id="pmpd-ai-chat-row">
                            <textarea id="pmpd-ai-chat-msg" rows="2"
                                placeholder="¿Cuál ticket harías primero? ¿Por qué bajaron las ventas? Shift+Enter para nueva línea…"></textarea>
                            <button id="pmpd-ai-chat-send" class="button button-primary">Enviar →</button>
                        </div>
                    </div>
                    <!-- Pie: copiar / descargar -->
                    <div class="pmpd-modal-footer" id="pmpd-ai-modal-footer" style="display:none;padding:10px 20px;border-top:1px solid #e5e7eb;font-size:11px;color:#9ca3af;">
                        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                            <span>Claude Opus 4.7 · Solo orientativo, verifica con tus datos.</span>
                            <div style="display:flex;gap:6px;">
                                <button id="pmpd-ai-wa-copy"  class="button button-small" style="display:none;background:#25d366;color:#fff;border-color:#25d366;">📱 WhatsApp</button>
                                <button id="pmpd-ai-copy"     class="button button-small">📋 Copiar</button>
                                <button id="pmpd-ai-download" class="button button-small">📥 Descargar</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- /#pmpd-dashboard -->
        <?php
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * AJAX — Dashboard: estadísticas + gráficas
     * ───────────────────────────────────────────────────────────────────────── */

    public function ajax_dashboard_stats(): void {
        if ( ! check_ajax_referer( 'pmp_admin_nonce', 'nonce', false )
            || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Sin permiso.' ], 403 );
        }

        if ( ! PMP_WooCommerce::is_active() ) {
            wp_send_json_error( [ 'message' => 'WooCommerce no está activo.' ] );
        }

        $range  = sanitize_text_field( $_POST['range']  ?? '30' );
        $status = sanitize_text_field( $_POST['status'] ?? '' );

        [ $date_from, $date_to ] = self::resolve_date_range( $range, $_POST );

        $cache_key = 'pmpd_stats2_' . md5( $date_from . '_' . $date_to . '_' . $status );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) {
            wp_send_json_success( $cached );
            return;
        }

        $result = [
            'date_from'     => $date_from,
            'date_to'       => $date_to,
            'revenue'       => PMP_WooCommerce::get_revenue_stats( $date_from, $date_to, $status ),
            'orders_status' => PMP_WooCommerce::get_orders_by_status( $date_from, $date_to ),
            'top_products'  => PMP_WooCommerce::get_top_products( $date_from, $date_to, 7 ),
            'top_customers' => PMP_WooCommerce::get_top_customers( $date_from, $date_to, 5, $status ),
            'daily_sales'   => PMP_WooCommerce::get_daily_sales_range( $date_from, $date_to, $status ),
        ];

        $ttl = ( $date_to === date( 'Y-m-d' ) ) ? 10 * MINUTE_IN_SECONDS : HOUR_IN_SECONDS;
        set_transient( $cache_key, $result, $ttl );

        wp_send_json_success( $result );
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * AJAX — Dashboard: tabla de pedidos recientes
     * ───────────────────────────────────────────────────────────────────────── */

    public function ajax_dashboard_orders(): void {
        if ( ! check_ajax_referer( 'pmp_admin_nonce', 'nonce', false )
            || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Sin permiso.' ], 403 );
        }

        if ( ! PMP_WooCommerce::is_active() ) {
            wp_send_json_error( [ 'message' => 'WooCommerce no está activo.' ] );
        }

        $range  = sanitize_text_field( $_POST['range']  ?? '30' );
        $status = sanitize_text_field( $_POST['status'] ?? '' );

        [ $date_from, $date_to ] = self::resolve_date_range( $range, $_POST );

        wp_send_json_success( PMP_WooCommerce::get_recent_orders( 15, $date_from, $date_to, $status ) );
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * AJAX — Dashboard: productos dormidos (sin ventas en 6 meses)
     * ───────────────────────────────────────────────────────────────────────── */

    public function ajax_dashboard_dormant(): void {
        if ( ! check_ajax_referer( 'pmp_admin_nonce', 'nonce', false )
            || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Sin permiso.' ], 403 );
        }

        if ( ! PMP_WooCommerce::is_active() ) {
            wp_send_json_error( [ 'message' => 'WooCommerce no está activo.' ] );
        }

        $cache_key = 'pmpd_dormant_180d_v2';
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) {
            wp_send_json_success( $cached );
            return;
        }

        $products = PMP_WooCommerce::get_dormant_products( 180, 100 );
        set_transient( $cache_key, $products, 30 * MINUTE_IN_SECONDS );
        wp_send_json_success( $products );
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * AJAX — Dashboard: datos de Google Analytics 4
     * ───────────────────────────────────────────────────────────────────────── */

    public function ajax_dashboard_ga4(): void {
        if ( ! check_ajax_referer( 'pmp_admin_nonce', 'nonce', false )
            || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Sin permiso.' ], 403 );
        }

        if ( ! PMP_GA4::is_configured() ) {
            wp_send_json_error( [ 'message' => 'GA4 no configurado.' ] );
            return;
        }

        $range = sanitize_text_field( $_POST['range'] ?? '30' );
        [ $date_from, $date_to ] = self::resolve_date_range( $range, $_POST );

        $result = ( new PMP_GA4() )->get_summary( $date_from, $date_to );

        if ( ! empty( $result['error'] ) ) {
            wp_send_json_error( [ 'message' => $result['error'] ] );
            return;
        }

        wp_send_json_success( $result );
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * AJAX — Dashboard: Google Search Console
     * ───────────────────────────────────────────────────────────────────────── */

    public function ajax_dashboard_gsc(): void {
        if ( ! check_ajax_referer( 'pmp_admin_nonce', 'nonce', false )
            || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Sin permiso.' ], 403 );
        }

        if ( ! PMP_SearchConsole::is_configured() ) {
            wp_send_json_error( [ 'message' => 'Search Console no configurado.' ] );
            return;
        }

        $range = sanitize_text_field( $_POST['range'] ?? '30' );
        [ $date_from, $date_to ] = self::resolve_date_range( $range, $_POST );

        $result = ( new PMP_SearchConsole() )->get_summary( $date_from, $date_to );

        if ( ! empty( $result['error'] ) ) {
            wp_send_json_error( [ 'message' => $result['error'] ] );
            return;
        }

        wp_send_json_success( $result );
    }

    public function ajax_test_gsc(): void {
        if ( ! check_ajax_referer( 'pmp_admin_nonce', 'nonce', false )
            || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Sin permiso.' ], 403 );
        }

        if ( ! PMP_SearchConsole::is_configured() ) {
            wp_send_json_error( [ 'message' => 'Completa la URL de la propiedad y guarda primero.' ] );
            return;
        }

        $result = ( new PMP_SearchConsole() )->test_connection();
        $result['ok']
            ? wp_send_json_success( [ 'message' => $result['message'] ] )
            : wp_send_json_error(   [ 'message' => $result['message'] ] );
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * AJAX — Dashboard: Insights de IA con Claude
     * ───────────────────────────────────────────────────────────────────────── */

    public function ajax_dashboard_ai_insights(): void {
        if ( ! check_ajax_referer( 'pmp_admin_nonce', 'nonce', false )
            || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Sin permiso.' ], 403 );
        }

        // ── API Key ─────────────────────────────────────────────────────────
        $api_key = defined( 'PMP_ANTHROPIC_KEY' )
            ? PMP_ANTHROPIC_KEY
            : get_option( 'pmp_anthropic_key', '' );

        if ( empty( trim( (string) $api_key ) ) ) {
            wp_send_json_error( [
                'message' => 'API Key de Claude no configurada. '
                    . 'Ve a <strong>Pymes Modernas → Configuración</strong> y agrega tu key de Anthropic.',
            ] );
            return;
        }

        // ── Datos del dashboard enviados desde JS ────────────────────────────
        $raw  = wp_unslash( $_POST['data'] ?? '{}' );
        $data = json_decode( (string) $raw, true );
        if ( ! is_array( $data ) ) {
            wp_send_json_error( [ 'message' => 'Datos inválidos.' ] );
            return;
        }

        $date_from = sanitize_text_field( $data['date_from'] ?? '' );
        $date_to   = sanitize_text_field( $data['date_to']   ?? '' );
        $currency  = function_exists( 'get_woocommerce_currency_symbol' )
            ? html_entity_decode( get_woocommerce_currency_symbol() )
            : '$';

        // ── Construir prompt ─────────────────────────────────────────────────
        $system = 'Eres un analista de CRO (Conversion Rate Optimization) experto en ecommerce latinoamericano, '
            . 'integrado al equipo de mantenimiento de Pymes Modernas. '
            . 'Tu rol es leer los datos de una tienda WooCommerce — y cuando estén disponibles, datos de Google Analytics 4, '
            . 'Meta Ads (Facebook/Instagram) y Klaviyo (email marketing) — para producir: '
            . '(1) un diagnóstico claro del período analizando el embudo completo (anuncio → visita → email → compra), y '
            . '(2) tickets de mejora CRO concretos, cada uno ejecutable en ~60 minutos por el equipo. '
            . 'Responde SIEMPRE en español. Usa emojis con moderación para mejorar la legibilidad. '
            . 'NO repitas los números exactos ya visibles — interprételos, encuéntrales el patrón y conviértelos en acción. '
            . 'Usa encabezados Markdown (##) para estructurar el análisis. '
            . 'Sé específico: menciona productos, campañas y segmentos de clientes por nombre cuando los datos lo permitan. '
            . 'Si hay pocos datos o el período es muy corto, indícalo brevemente y entrega igual las mejores recomendaciones posibles.';

        $user  = "**Período analizado:** {$date_from} al {$date_to}\n\n";

        // KPIs
        if ( ! empty( $data['revenue'] ) && is_array( $data['revenue'] ) ) {
            $rev = $data['revenue'];
            $avg = ( (int) ( $rev['total_orders'] ?? 0 ) ) > 0
                ? round( (float) ( $rev['net_revenue'] ?? 0 ) / (int) $rev['total_orders'], 2 )
                : 0;
            $user .= "## KPIs del período\n";
            $user .= '- Ingresos netos: ' . $currency . number_format( (float) ( $rev['net_revenue'] ?? 0 ), 2, '.', ',' ) . "\n";
            $user .= '- Pedidos totales: ' . (int) ( $rev['total_orders'] ?? 0 ) . "\n";
            $user .= '- Unidades vendidas: ' . (int) ( $rev['items_sold'] ?? 0 ) . "\n";
            $user .= '- Ticket promedio: ' . $currency . number_format( $avg, 2, '.', ',' ) . "\n\n";
        }

        // Top productos
        if ( ! empty( $data['top_products'] ) && is_array( $data['top_products'] ) ) {
            $user .= "## Top productos vendidos\n";
            foreach ( array_slice( $data['top_products'], 0, 7 ) as $i => $p ) {
                $user .= ( $i + 1 ) . '. ' . sanitize_text_field( $p['name'] ?? 'Producto' )
                    . ' — ' . (int) ( $p['total_qty'] ?? 0 ) . ' uds'
                    . ', ' . $currency . number_format( (float) ( $p['total_revenue'] ?? 0 ), 2, '.', ',' ) . "\n";
            }
            $user .= "\n";
        }

        // Mejores clientes
        if ( ! empty( $data['top_customers'] ) && is_array( $data['top_customers'] ) ) {
            $user .= "## Mejores clientes\n";
            foreach ( array_slice( $data['top_customers'], 0, 5 ) as $i => $c ) {
                $name = sanitize_text_field( $c['name'] ?: ( $c['email'] ?? 'Invitado' ) );
                $user .= ( $i + 1 ) . '. ' . $name
                    . ' — ' . (int) ( $c['order_count'] ?? 0 ) . ' pedido(s)'
                    . ', total: ' . $currency . number_format( (float) ( $c['total_spent'] ?? 0 ), 2, '.', ',' ) . "\n";
            }
            $user .= "\n";
        }

        // Pedidos por estado
        if ( ! empty( $data['orders_status'] ) && is_array( $data['orders_status'] ) ) {
            $user .= "## Pedidos por estado\n";
            foreach ( $data['orders_status'] as $s ) {
                $user .= '- ' . sanitize_text_field( $s['status_label'] ?? $s['status'] ?? '?' )
                    . ': ' . (int) ( $s['count'] ?? 0 ) . "\n";
            }
            $user .= "\n";
        }

        // Productos dormidos
        if ( array_key_exists( 'dormant', $data ) ) {
            if ( empty( $data['dormant'] ) ) {
                $user .= "## Productos sin ventas en 6 meses\n"
                    . "Ninguno — todos los productos tienen ventas recientes.\n\n";
            } else {
                $user .= "## Productos sin ventas en 6 meses\n";
                foreach ( array_slice( (array) $data['dormant'], 0, 8 ) as $p ) {
                    $user .= '- ' . sanitize_text_field( $p['name'] ?? 'Producto' )
                        . ' (última venta: ' . sanitize_text_field( $p['last_sold'] ?? 'desconocida' ) . ")\n";
                }
                $user .= "\n";
            }
        }

        // ── Segmentos adicionales para IA — obtenidos del servidor ───────────

        // IDs de top sellers ya conocidos (excluirlos del high-stock query para no repetir)
        $top_seller_ids = array_filter(
            array_map( fn( $p ) => (int) ( $p['product_id'] ?? 0 ), $data['top_products'] ?? [] )
        );

        // Productos con mucho stock y pocas ventas
        $high_stock = PMP_WooCommerce::get_high_stock_low_sales( array_values( $top_seller_ids ), 10 );
        if ( ! empty( $high_stock ) ) {
            $user .= "## Productos con alto stock y pocas/nulas ventas en el período\n";
            $user .= "(Candidatos prioritarios a descuento, promoción agresiva o revisión de estrategia)\n";
            foreach ( $high_stock as $i => $p ) {
                $user .= ( $i + 1 ) . '. ' . sanitize_text_field( $p['name'] )
                    . ' — stock disponible: ' . $p['stock'] . ' uds'
                    . ', precio: ' . $currency . number_format( (float) $p['price'], 2, '.', ',' ) . "\n";
            }
            $user .= "\n";
        }

        // Productos de alta rotación con precio unitario elevado (proxy de margen)
        $high_value = PMP_WooCommerce::get_high_value_products( $date_from, $date_to, 10 );
        if ( ! empty( $high_value ) ) {
            $has_real_cost = ! empty( array_filter( $high_value, fn( $p ) => $p['has_cost_data'] ) );
            $user .= "## Productos con alta rotación y valor unitario elevado\n";
            $user .= $has_real_cost
                ? "(Margen calculado con datos de costo reales del plugin Cost of Goods)\n"
                : "(Precio promedio de venta por unidad — usado como proxy de margen al no haber datos de costo)\n";
            foreach ( $high_value as $i => $p ) {
                $margin_str = $p['margin_pct'] !== null
                    ? ', margen: ' . $p['margin_pct'] . '%'
                    : '';
                $user .= ( $i + 1 ) . '. ' . sanitize_text_field( $p['name'] )
                    . ' — ' . $p['total_qty'] . ' uds vendidas'
                    . ', precio prom./ud: ' . $currency . number_format( $p['avg_price'], 2, '.', ',' )
                    . $margin_str . "\n";
            }
            $user .= "\n";
        }

        // ── Google Analytics 4 (si está configurado) ─────────────────────────
        if ( PMP_GA4::is_configured() ) {
            $ga4     = new PMP_GA4();
            $ga4_sum = $ga4->get_summary( $date_from, $date_to );

            $user .= "## Datos de Google Analytics 4\n";

            // Overview
            if ( ! empty( $ga4_sum['overview'] ) ) {
                $ov = $ga4_sum['overview'];
                $new_pct = $ov['sessions'] > 0
                    ? round( $ov['new_users'] / $ov['sessions'] * 100 )
                    : 0;
                $user .= "### Tráfico del período\n";
                $user .= '- Sesiones totales: ' . number_format( $ov['sessions'] ) . "\n";
                $user .= '- Usuarios nuevos: ' . number_format( $ov['new_users'] ) . " ({$new_pct}% del total)\n";
                $user .= '- Tasa de engagement: ' . $ov['engagement_rate'] . "% (sesiones con interacción >10 s)\n";
                $user .= '- Tasa de rebote: ' . $ov['bounce_rate'] . "%\n";
                $user .= '- Duración promedio de sesión: ' . $ov['avg_session_min'] . " min\n";
                if ( $ov['conversions'] > 0 ) {
                    $conv_rate = $ov['sessions'] > 0
                        ? round( $ov['conversions'] / $ov['sessions'] * 100, 2 )
                        : 0;
                    $user .= '- Conversiones: ' . $ov['conversions'] . " (tasa: {$conv_rate}%)\n";
                }
                $user .= "\n";
            }

            // Canales
            if ( ! empty( $ga4_sum['channels'] ) ) {
                $user .= "### Tráfico por canal de adquisición\n";
                foreach ( $ga4_sum['channels'] as $i => $ch ) {
                    $conv_str = $ch['conversions'] > 0 ? ", {$ch['conversions']} conv." : '';
                    $user .= ( $i + 1 ) . '. ' . $ch['channel']
                        . ' — ' . number_format( $ch['sessions'] ) . ' sesiones'
                        . ', engagement ' . $ch['engagement_rate'] . '%'
                        . $conv_str . "\n";
                }
                $user .= "\n";
            }

            // Páginas más visitadas
            if ( ! empty( $ga4_sum['top_pages'] ) ) {
                $user .= "### Páginas más visitadas\n";
                foreach ( $ga4_sum['top_pages'] as $i => $pg ) {
                    $time_str = PMP_GA4::format_duration( $pg['avg_time_s'] );
                    $conv_str = $pg['conversions'] > 0 ? ", {$pg['conversions']} conv." : '';
                    $user .= ( $i + 1 ) . '. ' . $pg['path']
                        . ' — ' . number_format( $pg['views'] ) . ' vistas'
                        . ', rebote ' . $pg['bounce_rate'] . '%'
                        . ', tiempo prom. ' . $time_str
                        . $conv_str . "\n";
                }
                $user .= "\n";
            }

            // Dispositivos
            if ( ! empty( $ga4_sum['devices'] ) ) {
                $user .= "### Tráfico por dispositivo\n";
                foreach ( $ga4_sum['devices'] as $dv ) {
                    $conv_str = $dv['conversions'] > 0 ? ", {$dv['conversions']} conv." : '';
                    $user .= '- ' . ucfirst( $dv['device'] )
                        . ': ' . number_format( $dv['sessions'] ) . ' sesiones'
                        . ', engagement ' . $dv['engagement_rate'] . '%'
                        . $conv_str . "\n";
                }
                $user .= "\n";
            }

            // Retención
            if ( ! empty( $ga4_sum['retention'] ) ) {
                $user .= "### Usuarios nuevos vs recurrentes\n";
                foreach ( $ga4_sum['retention'] as $r ) {
                    $label    = $r['type'] === 'new' ? 'Nuevos' : 'Recurrentes';
                    $conv_str = $r['conversions'] > 0 ? ", {$r['conversions']} conv." : '';
                    $user .= '- ' . $label
                        . ': ' . number_format( $r['sessions'] ) . ' sesiones'
                        . ', engagement ' . $r['engagement_rate'] . '%'
                        . $conv_str . "\n";
                }
                $user .= "\n";
            }

            // Eventos de conversión
            if ( ! empty( $ga4_sum['events'] ) ) {
                $user .= "### Eventos de conversión\n";
                foreach ( $ga4_sum['events'] as $ev ) {
                    $user .= '- ' . $ev['event']
                        . ': ' . number_format( $ev['count'] ) . ' disparos'
                        . ', ' . $ev['conversions'] . " conv.\n";
                }
                $user .= "\n";
            }

            // Términos de búsqueda interna
            if ( ! empty( $ga4_sum['search_terms'] ) ) {
                $user .= "### Búsqueda interna del sitio (top términos)\n";
                foreach ( $ga4_sum['search_terms'] as $i => $st ) {
                    $conv_str = $st['conversions'] > 0 ? ", {$st['conversions']} conv." : '';
                    $user .= ( $i + 1 ) . '. "' . $st['term'] . '"'
                        . ' — ' . $st['sessions'] . ' sesiones'
                        . $conv_str . "\n";
                }
                $user .= "\n";
            }
        }

        // ── Google Search Console (si está configurado) ──────────────────────
        if ( PMP_SearchConsole::is_configured() ) {
            $gsc     = new PMP_SearchConsole();
            $gsc_sum = $gsc->get_summary( $date_from, $date_to );

            $user .= "## Datos de Google Search Console (SEO orgánico)\n";

            if ( ! empty( $gsc_sum['error'] ) ) {
                $user .= "Error: " . sanitize_text_field( $gsc_sum['error'] ) . "\n\n";
            } else {
                if ( ! empty( $gsc_sum['queries'] ) ) {
                    $user .= "### Top keywords orgánicas\n";
                    foreach ( $gsc_sum['queries'] as $i => $q ) {
                        $user .= ( $i + 1 ) . '. "' . $q['query'] . '"'
                            . ' — pos. ' . $q['position']
                            . ', ' . $q['clicks'] . ' clics'
                            . ', ' . $q['impressions'] . ' impresiones'
                            . ', CTR ' . $q['ctr'] . "%\n";
                    }
                    $user .= "\n";

                    // Oportunidades: posición 4-20 con >100 impresiones
                    $opps = array_filter( $gsc_sum['queries'], fn( $q ) =>
                        $q['position'] >= 4 && $q['position'] <= 20 && $q['impressions'] >= 100
                    );
                    if ( ! empty( $opps ) ) {
                        $user .= "### Keywords en zona de oportunidad (pos. 4–20, +100 impresiones)\n";
                        foreach ( array_values( $opps ) as $i => $q ) {
                            $user .= ( $i + 1 ) . '. "' . $q['query'] . '"'
                                . ' — pos. ' . $q['position']
                                . ', ' . $q['impressions'] . " impresiones\n";
                        }
                        $user .= "\n";
                    }
                }

                if ( ! empty( $gsc_sum['pages'] ) ) {
                    $user .= "### Páginas con más tráfico orgánico\n";
                    foreach ( $gsc_sum['pages'] as $i => $p ) {
                        $user .= ( $i + 1 ) . '. ' . $p['page']
                            . ' — pos. ' . $p['position']
                            . ', ' . $p['clicks'] . ' clics'
                            . ', CTR ' . $p['ctr'] . "%\n";
                    }
                    $user .= "\n";
                }
            }
        }

        // ── Meta Ads (si está configurado) ───────────────────────────────────
        if ( PMP_Meta::is_configured() ) {
            $meta     = new PMP_Meta();
            $meta_sum = $meta->get_summary( $date_from, $date_to );

            $user .= "## Datos de Meta Ads (Facebook & Instagram)\n";

            if ( ! empty( $meta_sum['error'] ) ) {
                $user .= "Error al obtener datos de Meta Ads: " . sanitize_text_field( $meta_sum['error'] ) . "\n\n";
            } elseif ( ! empty( $meta_sum['overview'] ) ) {
                $ov = $meta_sum['overview'];

                $user .= "### Resumen de cuenta — período\n";
                $user .= '- Gasto total: $' . number_format( $ov['spend'], 2 ) . "\n";
                $user .= '- Impresiones: ' . number_format( $ov['impressions'] ) . "\n";
                $user .= '- Alcance (reach): ' . number_format( $ov['reach'] ) . "\n";
                $user .= '- Clics en anuncios: ' . number_format( $ov['clicks'] ) . "\n";
                $user .= '- CTR: ' . $ov['ctr'] . "%\n";
                $user .= '- CPM: $' . $ov['cpm'] . "\n";
                $user .= '- CPC: $' . $ov['cpc'] . "\n";

                if ( $ov['purchases'] > 0 ) {
                    $user .= '- Compras atribuidas por Meta: ' . $ov['purchases'] . "\n";
                    $user .= '- Ingresos atribuidos por Meta: $' . number_format( $ov['purchase_value'], 2 ) . "\n";
                }
                if ( $ov['roas'] !== null ) {
                    $user .= '- ROAS (ingresos atribuidos / gasto): ' . $ov['roas'] . "x\n";
                }
                if ( $ov['cpa'] !== null ) {
                    $user .= '- Costo por compra (CPA): $' . $ov['cpa'] . "\n";
                }
                $user .= "\n";

                if ( ! empty( $meta_sum['campaigns'] ) ) {
                    $user .= "### Campañas del período (ordenadas por gasto)\n";
                    foreach ( $meta_sum['campaigns'] as $i => $c ) {
                        $roas_str = $c['roas'] !== null ? ', ROAS: ' . $c['roas'] . 'x' : '';
                        $buys_str = $c['purchases'] > 0
                            ? ', compras: ' . $c['purchases']
                            : '';
                        $user .= ( $i + 1 ) . '. ' . sanitize_text_field( $c['name'] )
                            . ' — $' . number_format( $c['spend'], 2 ) . ' gastados'
                            . ', CTR: ' . $c['ctr'] . '%'
                            . $buys_str . $roas_str . "\n";
                    }
                    $user .= "\n";
                }
            } else {
                $user .= "Sin actividad publicitaria en Meta durante este período.\n\n";
            }
        }

        // ── Klaviyo (si está configurado) ────────────────────────────────────
        if ( PMP_Klaviyo::is_configured() ) {
            $klav     = new PMP_Klaviyo();
            $klav_sum = $klav->get_summary( $date_from, $date_to );

            $user .= "## Datos de Klaviyo (Email Marketing)\n";

            if ( ! empty( $klav_sum['error'] ) ) {
                $user .= "Error al obtener datos de Klaviyo: " . sanitize_text_field( $klav_sum['error'] ) . "\n\n";
            } elseif ( ! empty( $klav_sum['overview'] ) ) {
                $ov = $klav_sum['overview'];

                $user .= "### Métricas de email del período\n";
                $user .= '- Emails enviados (Received): ' . number_format( $ov['received'] ) . "\n";
                if ( $ov['open_rate'] !== null ) {
                    $user .= '- Tasa de apertura: ' . $ov['open_rate'] . "% ({$ov['opened']} abiertos)\n";
                }
                if ( $ov['click_rate'] !== null ) {
                    $user .= '- Tasa de clic: ' . $ov['click_rate'] . "% ({$ov['clicked']} clics)\n";
                }
                if ( $ov['unsubscribed'] > 0 ) {
                    $user .= '- Desuscripciones: ' . $ov['unsubscribed'] . "\n";
                }
                if ( $ov['orders'] > 0 ) {
                    $user .= '- Pedidos atribuidos a email: ' . $ov['orders'] . "\n";
                    $user .= '- Ingresos atribuidos a email (total): ' . $currency . number_format( $ov['revenue'], 2, '.', ',' ) . "\n";
                    // Desglose flows vs campañas (disponible si la API lo soportó)
                    if ( $ov['flow_revenue'] > 0 || $ov['campaign_revenue'] > 0 ) {
                        $user .= '  - Ingresos atribuidos a Flows: ' . $currency . number_format( $ov['flow_revenue'], 2, '.', ',' )
                            . " ({$ov['flow_orders']} pedidos)\n";
                        $user .= '  - Ingresos atribuidos a Campañas: ' . $currency . number_format( $ov['campaign_revenue'], 2, '.', ',' )
                            . " ({$ov['campaign_orders']} pedidos)\n";
                    }
                }
                $user .= "\n";

                if ( ! empty( $klav_sum['campaigns'] ) ) {
                    $user .= "### Últimas campañas de email enviadas\n";
                    $user .= "(Listado histórico — pueden incluir campañas fuera del período analizado)\n";
                    foreach ( $klav_sum['campaigns'] as $i => $c ) {
                        $user .= ( $i + 1 ) . '. ' . sanitize_text_field( $c['name'] )
                            . ' — enviada el ' . $c['send_date'] . "\n";
                    }
                    $user .= "\n";
                }
            } else {
                $user .= "Sin actividad de email marketing en Klaviyo durante este período.\n\n";
            }
        }

        // ── Instrucción final ─────────────────────────────────────────────────
        $user .= "Con base en estos datos, entrega el siguiente análisis estructurado:\n\n"
            . "## Diagnóstico del período\n"
            . "2-3 párrafos con los hallazgos clave: qué funcionó, qué no, tendencias y alertas importantes.\n\n"
            . "## Tickets CRO sugeridos\n"
            . "Entre 3 y 5 tickets ordenados de mayor a menor impacto estimado. "
            . "Cada ticket representa ~60 minutos de trabajo del equipo. "
            . "Usa exactamente este formato para cada uno:\n\n"
            . "**🎫 [Título del ticket — acción concreta, máx. 8 palabras]**\n"
            . "- **Tipo:** Regla de descuento | Campaña de email | Actualización Hero | Reposicionamiento de producto | Optimización de tienda\n"
            . "- **Qué hacer:** descripción específica (menciona productos o segmentos por nombre)\n"
            . "- **Por qué:** justificación directa con base en los datos del período\n"
            . "- **Impacto estimado:** Alto | Medio | Bajo\n\n"
            . "Prioriza los tickets que tengan mayor respaldo en los datos observados.\n\n"
            . "## Resumen para WhatsApp\n"
            . "Por último, escribe un mensaje corto (máx. 130 palabras, entre 6 y 8 líneas) listo para copiar y pegar en WhatsApp. "
            . "Dirígelo al equipo o al cliente con un tono cercano y profesional. "
            . "Resume el período en 1-2 líneas y menciona el ticket CRO más urgente con el impacto esperado. "
            . "Usa emojis para dar formato (no asteriscos ni guiones Markdown). "
            . "Escribe solo texto plano — sin negritas, sin listas con guiones, sin formato Markdown de ningún tipo.";

        // ── Llamar a Claude API ──────────────────────────────────────────────
        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'timeout'     => 90,
            'redirection' => 0,
            'headers'     => [
                'x-api-key'         => (string) $api_key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ],
            'body' => wp_json_encode( [
                'model'      => 'claude-opus-4-7',
                'max_tokens' => 1900,
                'system'     => $system,
                'messages'   => [
                    [ 'role' => 'user', 'content' => $user ],
                ],
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => 'Error de conexión: ' . $response->get_error_message() ] );
            return;
        }

        $http_code = (int) wp_remote_retrieve_response_code( $response );
        $body      = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $http_code !== 200 ) {
            $err = $body['error']['message'] ?? 'Error desconocido de la API.';
            if ( $http_code === 401 ) $err = 'API Key inválida o no autorizada. Verifica que copiaste la key completa.';
            if ( $http_code === 429 ) $err = 'Límite de solicitudes alcanzado. Espera un momento e intenta de nuevo.';
            wp_send_json_error( [ 'message' => "Error Claude API ({$http_code}): {$err}" ] );
            return;
        }

        // Extraer texto de la respuesta
        $text = '';
        foreach ( $body['content'] ?? [] as $block ) {
            if ( ( $block['type'] ?? '' ) === 'text' ) {
                $text = trim( $block['text'] );
                break;
            }
        }

        if ( empty( $text ) ) {
            wp_send_json_error( [ 'message' => 'Claude no devolvió texto. Intenta de nuevo.' ] );
            return;
        }

        wp_send_json_success( [ 'insights' => $text, 'initial_prompt' => $user ] );
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * AJAX — Chat de seguimiento con Claude (conversación)
     * ───────────────────────────────────────────────────────────────────────── */

    public function ajax_dashboard_ai_chat(): void {
        if ( ! check_ajax_referer( 'pmp_admin_nonce', 'nonce', false )
            || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Sin permiso.' ], 403 );
        }

        $api_key = defined( 'PMP_ANTHROPIC_KEY' )
            ? PMP_ANTHROPIC_KEY
            : get_option( 'pmp_anthropic_key', '' );

        if ( empty( trim( (string) $api_key ) ) ) {
            wp_send_json_error( [ 'message' => 'API Key de Claude no configurada.' ] );
            return;
        }

        // Historial de mensajes (generado por el cliente, validado aquí)
        $raw_messages = wp_unslash( $_POST['messages'] ?? '[]' );
        $new_message  = trim( sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) ) );

        $messages = json_decode( (string) $raw_messages, true );
        if ( ! is_array( $messages ) || empty( $messages ) ) {
            wp_send_json_error( [ 'message' => 'Conversación inválida.' ] );
            return;
        }
        if ( empty( $new_message ) ) {
            wp_send_json_error( [ 'message' => 'Mensaje vacío.' ] );
            return;
        }

        // Sanitizar y limitar el historial (máx. 16 mensajes para no exceder tokens)
        $clean = [];
        foreach ( array_slice( $messages, -16 ) as $msg ) {
            $role    = in_array( $msg['role'] ?? '', [ 'user', 'assistant' ], true ) ? $msg['role'] : '';
            $content = substr( (string) ( $msg['content'] ?? '' ), 0, 60000 );
            if ( $role && $content !== '' ) {
                $clean[] = [ 'role' => $role, 'content' => $content ];
            }
        }
        // Los mensajes deben empezar siempre por 'user'
        while ( ! empty( $clean ) && $clean[0]['role'] !== 'user' ) {
            array_shift( $clean );
        }
        if ( empty( $clean ) ) {
            wp_send_json_error( [ 'message' => 'Historial de conversación inválido.' ] );
            return;
        }

        // Añadir el nuevo mensaje del usuario
        $clean[] = [ 'role' => 'user', 'content' => $new_message ];

        $system = 'Eres un analista de CRO experto en ecommerce latinoamericano del equipo de Pymes Modernas. '
            . 'Estás respondiendo preguntas de seguimiento sobre el análisis que ya entregaste. '
            . 'El primer mensaje del usuario contiene todos los datos de la tienda — ya los conoces. '
            . 'Responde SIEMPRE en español. Sé concreto y accionable. '
            . 'Si te preguntan por un ticket específico, da detalles de implementación. '
            . 'Si te preguntan por datos que no están en el contexto, indícalo claramente.';

        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'timeout'     => 60,
            'redirection' => 0,
            'headers'     => [
                'x-api-key'         => (string) $api_key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ],
            'body' => wp_json_encode( [
                'model'      => 'claude-opus-4-7',
                'max_tokens' => 900,
                'system'     => $system,
                'messages'   => $clean,
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => 'Error de conexión: ' . $response->get_error_message() ] );
            return;
        }

        $http_code = (int) wp_remote_retrieve_response_code( $response );
        $body      = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $http_code !== 200 ) {
            $err = $body['error']['message'] ?? 'Error desconocido.';
            if ( $http_code === 429 ) $err = 'Límite de solicitudes alcanzado. Espera un momento.';
            wp_send_json_error( [ 'message' => "Error Claude API ({$http_code}): {$err}" ] );
            return;
        }

        $text = '';
        foreach ( $body['content'] ?? [] as $block ) {
            if ( ( $block['type'] ?? '' ) === 'text' ) {
                $text = trim( $block['text'] );
                break;
            }
        }

        if ( empty( $text ) ) {
            wp_send_json_error( [ 'message' => 'Sin respuesta de Claude. Intenta de nuevo.' ] );
            return;
        }

        wp_send_json_success( [ 'reply' => $text ] );
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * AJAX — Probar conexión con GA4
     * ───────────────────────────────────────────────────────────────────────── */

    public function ajax_test_ga4(): void {
        if ( ! check_ajax_referer( 'pmp_admin_nonce', 'nonce', false )
            || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Sin permiso.' ], 403 );
        }

        if ( ! PMP_GA4::is_configured() ) {
            wp_send_json_error( [ 'message' => 'Completa el Service Account JSON y el Property ID, y guarda primero.' ] );
        }

        $result = ( new PMP_GA4() )->test_connection();
        $result['ok']
            ? wp_send_json_success( [ 'message' => $result['message'] ] )
            : wp_send_json_error(   [ 'message' => $result['message'] ] );
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * AJAX — Dashboard: datos de Meta Ads
     * ───────────────────────────────────────────────────────────────────────── */

    public function ajax_dashboard_meta(): void {
        if ( ! check_ajax_referer( 'pmp_admin_nonce', 'nonce', false )
            || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Sin permiso.' ], 403 );
        }

        if ( ! PMP_Meta::is_configured() ) {
            wp_send_json_error( [ 'message' => 'Meta Ads no configurado.' ] );
            return;
        }

        $range = sanitize_text_field( $_POST['range'] ?? '30' );
        [ $date_from, $date_to ] = self::resolve_date_range( $range, $_POST );

        $result = ( new PMP_Meta() )->get_summary( $date_from, $date_to );

        if ( ! empty( $result['error'] ) ) {
            wp_send_json_error( [ 'message' => $result['error'] ] );
            return;
        }

        wp_send_json_success( $result );
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * AJAX — Probar conexión con Meta Ads
     * ───────────────────────────────────────────────────────────────────────── */

    public function ajax_test_meta(): void {
        if ( ! check_ajax_referer( 'pmp_admin_nonce', 'nonce', false )
            || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Sin permiso.' ], 403 );
        }

        if ( ! PMP_Meta::is_configured() ) {
            wp_send_json_error( [ 'message' => 'Completa al menos el Access Token y el Ad Account ID y guarda primero.' ] );
            return;
        }

        $result = ( new PMP_Meta() )->test_connection();
        $result['ok']
            ? wp_send_json_success( [ 'message' => $result['message'] ] )
            : wp_send_json_error(   [ 'message' => $result['message'] ] );
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * AJAX — Dashboard: datos de Klaviyo
     * ───────────────────────────────────────────────────────────────────────── */

    public function ajax_dashboard_klaviyo(): void {
        if ( ! check_ajax_referer( 'pmp_admin_nonce', 'nonce', false )
            || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Sin permiso.' ], 403 );
        }

        if ( ! PMP_Klaviyo::is_configured() ) {
            wp_send_json_error( [ 'message' => 'Klaviyo no configurado.' ] );
            return;
        }

        $range = sanitize_text_field( $_POST['range'] ?? '30' );
        [ $date_from, $date_to ] = self::resolve_date_range( $range, $_POST );

        $result = ( new PMP_Klaviyo() )->get_summary( $date_from, $date_to );

        if ( ! empty( $result['error'] ) ) {
            wp_send_json_error( [ 'message' => $result['error'] ] );
            return;
        }

        wp_send_json_success( $result );
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * AJAX — Probar conexión con Klaviyo
     * ───────────────────────────────────────────────────────────────────────── */

    public function ajax_test_klaviyo(): void {
        if ( ! check_ajax_referer( 'pmp_admin_nonce', 'nonce', false )
            || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Sin permiso.' ], 403 );
        }

        if ( ! PMP_Klaviyo::is_configured() ) {
            wp_send_json_error( [ 'message' => 'Ingresa tu Private API Key y guarda primero.' ] );
            return;
        }

        $result = ( new PMP_Klaviyo() )->test_connection();
        $result['ok']
            ? wp_send_json_success( [ 'message' => $result['message'] ] )
            : wp_send_json_error(   [ 'message' => $result['message'] ] );
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * AJAX — Descartar aviso de plugin no conectado (7 días)
     * ───────────────────────────────────────────────────────────────────────── */

    public function ajax_dismiss_connect_notice(): void {
        if ( ! check_ajax_referer( 'pmp_dismiss_notice', 'nonce', false )
            || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [], 403 );
        }
        update_user_meta(
            get_current_user_id(),
            'pmp_notice_dismissed',
            time() + 7 * DAY_IN_SECONDS   // no vuelve a aparecer por 7 días
        );
        wp_send_json_success();
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Helper — Resuelve rango de fechas desde parámetros POST
     * ───────────────────────────────────────────────────────────────────────── */

    private static function resolve_date_range( string $range, array $post ): array {
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
                $date_from = sanitize_text_field( $post['date_from'] ?? date( 'Y-m-01' ) );
                $date_to   = sanitize_text_field( $post['date_to']   ?? date( 'Y-m-d'  ) );
                if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) $date_from = date( 'Y-m-01' );
                if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to   ) ) $date_to   = date( 'Y-m-d'  );
                break;
            default: // '30'
                $date_from = date( 'Y-m-d', strtotime( '-30 days' ) );
        }
        return [ $date_from, $date_to ];
    }
}
