<?php
/**
 * PMP_Digest — Resumen semanal por correo.
 * Se envía cada lunes a las 7am hora de Costa Rica (13:00 UTC).
 * También disponible para envío manual con destinatario personalizado.
 */
defined( 'ABSPATH' ) || exit;

class PMP_Digest {

    const HOOK = 'pmp_weekly_digest';

    /* ── Programación del cron ──────────────────────────────────────────────── */

    public static function schedule(): void {
        if ( wp_next_scheduled( self::HOOK ) ) return;

        // Próximo lunes a las 13:00 UTC (7am Costa Rica, UTC-6)
        $now         = time();
        $day_of_week = (int) gmdate( 'N', $now ); // 1=lun … 7=dom
        $hour_utc    = (int) gmdate( 'H', $now );

        if ( $day_of_week === 1 && $hour_utc < 13 ) {
            // Hoy es lunes y aún no han dado las 13:00 UTC → disparar hoy
            $next = strtotime( gmdate( 'Y-m-d' ) . ' 13:00:00 UTC' );
        } else {
            // Calcular el próximo lunes
            $days_ahead = ( 8 - $day_of_week ) % 7 ?: 7;
            $next       = strtotime( gmdate( 'Y-m-d', $now + $days_ahead * DAY_IN_SECONDS ) . ' 13:00:00 UTC' );
        }

        wp_schedule_event( $next, 'weekly', self::HOOK );
    }

    public static function unschedule(): void {
        $timestamp = wp_next_scheduled( self::HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::HOOK );
        }
    }

    /* ── Punto de entrada del cron ──────────────────────────────────────────── */

    public static function send(): void {
        $to = trim( (string) get_option( 'pmp_digest_email', '' ) );
        if ( empty( $to ) || ! is_email( $to ) ) return;
        self::send_to( $to );
    }

    /* ── Envío a destinatario específico ────────────────────────────────────── */

    public static function send_to( string $to ): array {
        if ( ! is_email( $to ) ) {
            return [ 'ok' => false, 'message' => 'Email inválido.' ];
        }

        // Período: lunes–domingo de la semana anterior
        $last_sunday = strtotime( 'last sunday', time() );
        $date_to     = gmdate( 'Y-m-d', $last_sunday );
        $date_from   = gmdate( 'Y-m-d', $last_sunday - 6 * DAY_IN_SECONDS );

        // ── WooCommerce ─────────────────────────────────────────────────────
        $woo          = [];
        $revenue      = [];
        $top_products = [];
        if ( PMP_WooCommerce::is_active() ) {
            $woo          = PMP_WooCommerce::get_orders_by_status( $date_from, $date_to );
            $revenue      = PMP_WooCommerce::get_revenue_stats( $date_from, $date_to );
            $top_products = PMP_WooCommerce::get_top_products( $date_from, $date_to, 5 );
        }

        // ── Google Analytics 4 ──────────────────────────────────────────────
        $ga4_all = [];
        if ( PMP_GA4::is_configured() ) {
            $ga4_all = ( new PMP_GA4() )->get_summary( $date_from, $date_to );
        }
        $ga4_overview = $ga4_all['overview'] ?? [];

        // ── Google Search Console ────────────────────────────────────────────
        $gsc_data = [];
        if ( PMP_SearchConsole::is_configured() ) {
            $gsc_data = ( new PMP_SearchConsole() )->get_summary( $date_from, $date_to );
        }

        // ── Snippet de IA ────────────────────────────────────────────────────
        $snippet = self::get_ai_snippet( $woo, $ga4_overview, $revenue, $gsc_data, $date_from, $date_to );

        // ── Construir y enviar ───────────────────────────────────────────────
        $subject = '📊 Resumen semanal — ' . self::fmt_period( $date_from, $date_to );
        $body    = self::build_html( $woo, $revenue, $top_products, $ga4_all, $gsc_data, $snippet, $date_from, $date_to );

        add_filter( 'wp_mail_content_type', fn() => 'text/html' );
        $sent = wp_mail( $to, $subject, $body );
        remove_filter( 'wp_mail_content_type', fn() => 'text/html' );

        return $sent
            ? [ 'ok' => true,  'message' => "Correo enviado correctamente a {$to}" ]
            : [ 'ok' => false, 'message' => 'WordPress no pudo enviar el email. Revisa la configuración de correo del servidor.' ];
    }

    /* ── Claude: snippet motivacional ──────────────────────────────────────── */

    private static function get_ai_snippet(
        array $woo, array $ga4, array $revenue, array $gsc,
        string $date_from, string $date_to
    ): string {
        $api_key = defined( 'PMP_ANTHROPIC_KEY' )
            ? PMP_ANTHROPIC_KEY
            : trim( (string) get_option( 'pmp_anthropic_key', '' ) );

        if ( empty( $api_key ) ) return '';

        $lines = [];

        if ( ! empty( $revenue['net_revenue'] ) ) {
            $lines[] = 'Ingresos netos de la semana: ' . number_format( $revenue['net_revenue'], 2 );
        }
        if ( ! empty( $revenue['total_orders'] ) ) {
            $lines[] = 'Total de pedidos: ' . $revenue['total_orders'];
            if ( $revenue['total_orders'] > 0 ) {
                $avg = $revenue['net_revenue'] / $revenue['total_orders'];
                $lines[] = 'Ticket promedio: ' . number_format( $avg, 2 );
            }
        }
        if ( ! empty( $woo ) ) {
            foreach ( $woo as $s ) {
                $label = $s['status_label'] ?? $s['label'] ?? $s['status'];
                $lines[] = "  - {$label}: {$s['count']}";
            }
        }
        if ( ! empty( $ga4['sessions'] ) ) {
            $lines[] = 'Sesiones de tráfico web: ' . number_format( $ga4['sessions'] );
        }
        if ( ! empty( $ga4['new_users'] ) ) {
            $lines[] = 'Usuarios nuevos: ' . number_format( $ga4['new_users'] );
        }
        if ( ! empty( $gsc['queries'][0] ) ) {
            $q = $gsc['queries'][0];
            $lines[] = "Keyword orgánica top en Google: \"{$q['query']}\" ({$q['clicks']} clics, posición {$q['position']})";
        }

        if ( empty( $lines ) ) return '';

        $data_text = implode( "\n", $lines );
        $period    = self::fmt_period( $date_from, $date_to );

        $prompt = "Datos de la semana {$period} para una tienda online WooCommerce:\n{$data_text}\n\n"
            . 'Escríbeme 2-3 oraciones de resumen con tono motivacional y profesional en español. '
            . 'Incluye una observación concreta sobre los números. Sin saludos ni despedidas.';

        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'timeout' => 30,
            'headers' => [
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ],
            'body' => wp_json_encode( [
                'model'      => 'claude-haiku-4-5',
                'max_tokens' => 200,
                'messages'   => [ [ 'role' => 'user', 'content' => $prompt ] ],
            ] ),
        ] );

        if ( is_wp_error( $response ) ) return '';

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return trim( $body['content'][0]['text'] ?? '' );
    }

    /* ── HTML del email ─────────────────────────────────────────────────────── */

    private static function build_html(
        array $woo, array $revenue, array $top_products,
        array $ga4_all, array $gsc, string $snippet,
        string $date_from, string $date_to
    ): string {
        $ga4      = $ga4_all['overview'] ?? [];
        $channels = $ga4_all['channels'] ?? [];
        $period   = self::fmt_period( $date_from, $date_to );
        $site     = get_bloginfo( 'name' );
        $site_url = get_home_url();
        $currency = function_exists( 'get_woocommerce_currency_symbol' )
            ? html_entity_decode( get_woocommerce_currency_symbol() )
            : '$';

        $avg_order = ( ! empty( $revenue['total_orders'] ) && $revenue['total_orders'] > 0 )
            ? $revenue['net_revenue'] / $revenue['total_orders']
            : 0;

        $status_icons = [
            'wc-completed'  => '✅',
            'wc-processing' => '⏳',
            'wc-pending'    => '🕐',
            'wc-on-hold'    => '⏸',
            'wc-cancelled'  => '❌',
            'wc-refunded'   => '↩️',
        ];

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 16px;">
<tr><td align="center">
<table width="580" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08);">

  <!-- Cabecera -->
  <tr>
    <td style="background:#1a2e4f;padding:28px 32px;">
      <p style="margin:0;font-size:11px;color:#60a5fa;letter-spacing:1px;text-transform:uppercase;font-weight:600;">Pymes Modernas</p>
      <h1 style="margin:6px 0 4px;font-size:20px;color:#ffffff;font-weight:700;">📊 Resumen semanal</h1>
      <p style="margin:0;font-size:13px;color:#93c5fd;"><?php echo esc_html( $period ); ?> · <?php echo esc_html( $site ); ?></p>
    </td>
  </tr>

  <!-- Cuerpo -->
  <tr><td style="padding:28px 32px;">

    <!-- ── KPIs de ventas ── -->
    <?php if ( ! empty( $revenue ) ) : ?>
    <h2 style="margin:0 0 14px;font-size:13px;font-weight:600;color:#1a2e4f;text-transform:uppercase;letter-spacing:.5px;">Ventas de la semana</h2>
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
      <tr>
        <td width="25%" style="padding:0 5px 0 0;">
          <div style="background:#eff6ff;border-radius:8px;padding:14px 10px;text-align:center;">
            <div style="font-size:17px;font-weight:700;color:#1a2e4f;"><?php echo $currency . number_format( $revenue['net_revenue'] ?? 0, 2 ); ?></div>
            <div style="font-size:11px;color:#6b7280;margin-top:3px;">Ingresos netos</div>
          </div>
        </td>
        <td width="25%" style="padding:0 5px 0 0;">
          <div style="background:#f0fdf4;border-radius:8px;padding:14px 10px;text-align:center;">
            <div style="font-size:17px;font-weight:700;color:#15803d;"><?php echo (int) ( $revenue['total_orders'] ?? 0 ); ?></div>
            <div style="font-size:11px;color:#6b7280;margin-top:3px;">Pedidos</div>
          </div>
        </td>
        <td width="25%" style="padding:0 5px 0 0;">
          <div style="background:#fef9c3;border-radius:8px;padding:14px 10px;text-align:center;">
            <div style="font-size:17px;font-weight:700;color:#854d0e;"><?php echo (int) ( $revenue['items_sold'] ?? 0 ); ?></div>
            <div style="font-size:11px;color:#6b7280;margin-top:3px;">Artículos</div>
          </div>
        </td>
        <td width="25%">
          <div style="background:#fdf4ff;border-radius:8px;padding:14px 10px;text-align:center;">
            <div style="font-size:17px;font-weight:700;color:#7e22ce;"><?php echo $currency . number_format( $avg_order, 2 ); ?></div>
            <div style="font-size:11px;color:#6b7280;margin-top:3px;">Ticket prom.</div>
          </div>
        </td>
      </tr>
    </table>
    <?php endif; ?>

    <!-- ── Pedidos por estado ── -->
    <?php if ( ! empty( $woo ) ) : ?>
    <h2 style="margin:0 0 14px;font-size:13px;font-weight:600;color:#1a2e4f;text-transform:uppercase;letter-spacing:.5px;">Pedidos por estado</h2>
    <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-bottom:28px;">
      <thead>
        <tr style="border-bottom:2px solid #f3f4f6;">
          <th style="text-align:left;padding:6px 0;font-size:11px;color:#9ca3af;font-weight:500;">Estado</th>
          <th style="text-align:right;padding:6px 0;font-size:11px;color:#9ca3af;font-weight:500;">Cantidad</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $total_orders = 0;
        foreach ( $woo as $s ) :
            $total_orders += $s['count'];
            $icon  = $status_icons[ $s['status'] ] ?? '📦';
            $label = $s['status_label'] ?? $s['label'] ?? $s['status'];
        ?>
        <tr style="border-bottom:1px solid #f9fafb;">
          <td style="padding:10px 0;font-size:13px;color:#374151;"><?php echo $icon . ' ' . esc_html( $label ); ?></td>
          <td style="text-align:right;padding:10px 0;font-size:13px;font-weight:600;color:#1a2e4f;"><?php echo $s['count']; ?></td>
        </tr>
        <?php endforeach; ?>
        <tr>
          <td style="padding:10px 0;font-size:13px;font-weight:600;color:#1a2e4f;">Total</td>
          <td style="text-align:right;padding:10px 0;font-size:15px;font-weight:700;color:#1a2e4f;"><?php echo $total_orders; ?></td>
        </tr>
      </tbody>
    </table>
    <?php endif; ?>

    <!-- ── Top productos ── -->
    <?php if ( ! empty( $top_products ) ) : ?>
    <h2 style="margin:0 0 14px;font-size:13px;font-weight:600;color:#1a2e4f;text-transform:uppercase;letter-spacing:.5px;">🛍️ Productos más vendidos</h2>
    <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-bottom:28px;">
      <thead>
        <tr style="border-bottom:2px solid #f3f4f6;">
          <th style="text-align:left;padding:6px 0;font-size:11px;color:#9ca3af;font-weight:500;">Producto</th>
          <th style="text-align:right;padding:6px 0;font-size:11px;color:#9ca3af;font-weight:500;">Qty</th>
          <th style="text-align:right;padding:6px 0;font-size:11px;color:#9ca3af;font-weight:500;">Ingresos</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ( $top_products as $i => $p ) : ?>
        <tr style="border-bottom:1px solid #f9fafb;">
          <td style="padding:9px 0;font-size:13px;color:#374151;">
            <span style="display:inline-block;width:18px;height:18px;border-radius:50%;
                         background:#e5e7eb;text-align:center;line-height:18px;
                         font-size:10px;font-weight:700;color:#6b7280;margin-right:6px;">
              <?php echo $i + 1; ?>
            </span>
            <?php echo esc_html( $p['name'] ); ?>
          </td>
          <td style="text-align:right;padding:9px 0;font-size:13px;font-weight:600;color:#1a2e4f;"><?php echo (int) $p['total_qty']; ?></td>
          <td style="text-align:right;padding:9px 0;font-size:13px;color:#6b7280;"><?php echo $currency . number_format( (float) $p['total_revenue'], 2 ); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <!-- ── Tráfico web (GA4) ── -->
    <?php if ( ! empty( $ga4 ) ) : ?>
    <h2 style="margin:0 0 14px;font-size:13px;font-weight:600;color:#1a2e4f;text-transform:uppercase;letter-spacing:.5px;">📊 Tráfico web</h2>
    <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-bottom:<?php echo ! empty( $channels ) ? '12' : '28'; ?>px;">
      <tbody>
        <?php if ( ! empty( $ga4['sessions'] ) ) : ?>
        <tr style="border-bottom:1px solid #f9fafb;">
          <td style="padding:10px 0;font-size:13px;color:#374151;">👥 Sesiones</td>
          <td style="text-align:right;padding:10px 0;font-size:13px;font-weight:600;color:#1a2e4f;"><?php echo number_format( $ga4['sessions'] ); ?></td>
        </tr>
        <?php endif; ?>
        <?php if ( ! empty( $ga4['new_users'] ) ) : ?>
        <tr style="border-bottom:1px solid #f9fafb;">
          <td style="padding:10px 0;font-size:13px;color:#374151;">✨ Usuarios nuevos</td>
          <td style="text-align:right;padding:10px 0;font-size:13px;font-weight:600;color:#1a2e4f;"><?php echo number_format( $ga4['new_users'] ); ?></td>
        </tr>
        <?php endif; ?>
        <?php if ( ! empty( $ga4['engagement_rate'] ) ) : ?>
        <tr style="border-bottom:1px solid #f9fafb;">
          <td style="padding:10px 0;font-size:13px;color:#374151;">⚡ Tasa de engagement</td>
          <td style="text-align:right;padding:10px 0;font-size:13px;font-weight:600;color:#1a2e4f;"><?php echo $ga4['engagement_rate']; ?>%</td>
        </tr>
        <?php endif; ?>
        <?php if ( ! empty( $ga4['bounce_rate'] ) ) : ?>
        <tr>
          <td style="padding:10px 0;font-size:13px;color:#374151;">↩️ Tasa de rebote</td>
          <td style="text-align:right;padding:10px 0;font-size:13px;font-weight:600;color:#1a2e4f;"><?php echo $ga4['bounce_rate']; ?>%</td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>

    <?php if ( ! empty( $channels ) ) : ?>
    <!-- Canales de adquisición -->
    <p style="margin:0 0 6px;font-size:11px;color:#9ca3af;font-weight:500;text-transform:uppercase;letter-spacing:.5px;">Canales de tráfico</p>
    <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-bottom:28px;margin-left:0;">
      <thead>
        <tr style="border-bottom:1px solid #f3f4f6;">
          <th style="text-align:left;padding:5px 0;font-size:11px;color:#9ca3af;font-weight:500;">Canal</th>
          <th style="text-align:right;padding:5px 0;font-size:11px;color:#9ca3af;font-weight:500;">Sesiones</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ( array_slice( $channels, 0, 6 ) as $ch ) : ?>
        <tr style="border-bottom:1px solid #f9fafb;">
          <td style="padding:7px 0;font-size:12px;color:#374151;"><?php echo esc_html( $ch['channel'] ); ?></td>
          <td style="text-align:right;padding:7px 0;font-size:12px;color:#1a2e4f;font-weight:500;"><?php echo number_format( (int) $ch['sessions'] ); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
    <?php endif; ?>

    <!-- ── SEO — Google Search Console ── -->
    <?php if ( ! empty( $gsc['queries'] ) ) : ?>
    <h2 style="margin:0 0 14px;font-size:13px;font-weight:600;color:#1a2e4f;text-transform:uppercase;letter-spacing:.5px;">🔍 Búsquedas en Google</h2>
    <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-bottom:28px;">
      <thead>
        <tr style="border-bottom:2px solid #f3f4f6;">
          <th style="text-align:left;padding:6px 0;font-size:11px;color:#9ca3af;font-weight:500;">Keyword</th>
          <th style="text-align:right;padding:6px 0;font-size:11px;color:#9ca3af;font-weight:500;">Clics</th>
          <th style="text-align:right;padding:6px 0;font-size:11px;color:#9ca3af;font-weight:500;">Posición</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ( array_slice( $gsc['queries'], 0, 5 ) as $q ) :
            $pos       = (float) $q['position'];
            $pos_color = $pos <= 3 ? '#15803d' : ( $pos <= 10 ? '#b45309' : '#6b7280' );
        ?>
        <tr style="border-bottom:1px solid #f9fafb;">
          <td style="padding:9px 0;font-size:13px;color:#374151;"><?php echo esc_html( $q['query'] ); ?></td>
          <td style="text-align:right;padding:9px 0;font-size:13px;font-weight:600;color:#1a2e4f;"><?php echo (int) $q['clicks']; ?></td>
          <td style="text-align:right;padding:9px 0;font-size:13px;font-weight:600;color:<?php echo $pos_color; ?>;">#<?php echo $pos; ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <!-- ── Snippet de IA ── -->
    <?php if ( ! empty( $snippet ) ) : ?>
    <div style="background:#eff6ff;border-left:3px solid #60a5fa;border-radius:0 8px 8px 0;padding:16px 20px;margin-bottom:28px;">
      <p style="margin:0 0 4px;font-size:11px;font-weight:600;color:#60a5fa;text-transform:uppercase;letter-spacing:.5px;">🤖 Análisis</p>
      <p style="margin:0;font-size:13px;color:#1e40af;line-height:1.6;"><?php echo esc_html( $snippet ); ?></p>
    </div>
    <?php endif; ?>

    <!-- Botón -->
    <p style="text-align:center;margin:0;">
      <a href="<?php echo esc_url( $site_url . '/wp-admin/admin.php?page=pymes-modernas-resumen' ); ?>"
         style="display:inline-block;background:#1a2e4f;color:#ffffff;text-decoration:none;padding:12px 28px;border-radius:8px;font-size:13px;font-weight:600;">
        Ver dashboard completo →
      </a>
    </p>

  </td></tr>

  <!-- Pie -->
  <tr>
    <td style="background:#f9fafb;padding:16px 32px;border-top:1px solid #f3f4f6;">
      <p style="margin:0;font-size:11px;color:#9ca3af;text-align:center;">
        Pymes Modernas · Plugin pm-client-portal · Resumen automático semanal
      </p>
    </td>
  </tr>

</table>
</td></tr>
</table>
</body>
</html>
        <?php
        return ob_get_clean();
    }

    /* ── Helpers ────────────────────────────────────────────────────────────── */

    private static function fmt_period( string $date_from, string $date_to ): string {
        $months_es = [ '', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic' ];
        $from_d    = (int) substr( $date_from, 8, 2 );
        $from_m    = (int) substr( $date_from, 5, 2 );
        $to_d      = (int) substr( $date_to,   8, 2 );
        $to_m      = (int) substr( $date_to,   5, 2 );
        $to_y      = substr( $date_to, 0, 4 );

        if ( $from_m === $to_m ) {
            return "{$from_d}–{$to_d} {$months_es[$to_m]} {$to_y}";
        }
        return "{$from_d} {$months_es[$from_m]} – {$to_d} {$months_es[$to_m]} {$to_y}";
    }
}
