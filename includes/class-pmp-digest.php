<?php
/**
 * PMP_Digest — Resumen semanal por correo.
 * Se envía cada lunes a las 7am hora de Costa Rica (13:00 UTC).
 */
defined( 'ABSPATH' ) || exit;

class PMP_Digest {

    const HOOK = 'pmp_weekly_digest';

    /* ── Programación del cron ──────────────────────────────────────────────── */

    public static function schedule(): void {
        if ( wp_next_scheduled( self::HOOK ) ) return;

        // Próximo lunes a las 13:00 UTC (7am Costa Rica, UTC-6)
        $now        = time();
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

        // Período: lunes–domingo de la semana anterior
        $last_sunday = strtotime( 'last sunday', time() );
        $date_to     = gmdate( 'Y-m-d', $last_sunday );
        $date_from   = gmdate( 'Y-m-d', $last_sunday - 6 * DAY_IN_SECONDS );

        // Datos WooCommerce
        $woo = PMP_WooCommerce::is_active()
            ? PMP_WooCommerce::get_orders_by_status( $date_from, $date_to )
            : [];

        // Datos GA4
        $ga4_overview = [];
        if ( PMP_GA4::is_configured() ) {
            $summary      = ( new PMP_GA4() )->get_summary( $date_from, $date_to );
            $ga4_overview = $summary['overview'] ?? [];
        }

        // Snippet motivacional de Claude
        $snippet = self::get_ai_snippet( $woo, $ga4_overview, $date_from, $date_to );

        // Construir y enviar email
        $subject = '📊 Resumen semanal — ' . self::fmt_period( $date_from, $date_to );
        $body    = self::build_html( $woo, $ga4_overview, $snippet, $date_from, $date_to );

        add_filter( 'wp_mail_content_type', fn() => 'text/html' );
        wp_mail( $to, $subject, $body );
        remove_filter( 'wp_mail_content_type', fn() => 'text/html' );
    }

    /* ── Claude: snippet motivacional ──────────────────────────────────────── */

    private static function get_ai_snippet( array $woo, array $ga4, string $date_from, string $date_to ): string {
        $api_key = defined( 'PMP_ANTHROPIC_KEY' )
            ? PMP_ANTHROPIC_KEY
            : trim( (string) get_option( 'pmp_anthropic_key', '' ) );

        if ( empty( $api_key ) ) return '';

        // Armar contexto de datos
        $lines = [];

        if ( ! empty( $woo ) ) {
            $total = array_sum( array_column( $woo, 'count' ) );
            $lines[] = "Pedidos totales de la semana: {$total}";
            foreach ( $woo as $s ) {
                $lines[] = "  - {$s['label']}: {$s['count']}";
            }
        }

        if ( ! empty( $ga4['sessions'] ) ) {
            $lines[] = 'Visitantes (sesiones GA4): ' . number_format( $ga4['sessions'] );
        }

        if ( empty( $lines ) ) return '';

        $data_text = implode( "\n", $lines );
        $period    = self::fmt_period( $date_from, $date_to );

        $prompt = "Datos de la semana {$period} para una tienda online WooCommerce:\n{$data_text}\n\n"
            . 'Escríbeme 2 oraciones de resumen con tono motivacional y profesional en español. '
            . 'Sin saludos ni despedidas. Solo el resumen.';

        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'timeout' => 30,
            'headers' => [
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ],
            'body' => wp_json_encode( [
                'model'      => 'claude-haiku-4-5',
                'max_tokens' => 150,
                'messages'   => [ [ 'role' => 'user', 'content' => $prompt ] ],
            ] ),
        ] );

        if ( is_wp_error( $response ) ) return '';

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return trim( $body['content'][0]['text'] ?? '' );
    }

    /* ── HTML del email ─────────────────────────────────────────────────────── */

    private static function build_html( array $woo, array $ga4, string $snippet, string $date_from, string $date_to ): string {
        $period   = self::fmt_period( $date_from, $date_to );
        $site     = get_bloginfo( 'name' );
        $site_url = get_home_url();

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 16px;">
<tr><td align="center">
<table width="560" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08);">

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

    <?php if ( ! empty( $woo ) ) : ?>
    <!-- Pedidos por estado -->
    <h2 style="margin:0 0 14px;font-size:13px;font-weight:600;color:#1a2e4f;text-transform:uppercase;letter-spacing:.5px;">Pedidos de la semana</h2>
    <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-bottom:28px;">
      <thead>
        <tr style="border-bottom:2px solid #f3f4f6;">
          <th style="text-align:left;padding:6px 0;font-size:11px;color:#9ca3af;font-weight:500;">Estado</th>
          <th style="text-align:right;padding:6px 0;font-size:11px;color:#9ca3af;font-weight:500;">Cantidad</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $status_icons = [
            'wc-completed'  => '✅',
            'wc-processing' => '⏳',
            'wc-pending'    => '🕐',
            'wc-on-hold'    => '⏸',
            'wc-cancelled'  => '❌',
            'wc-refunded'   => '↩️',
        ];
        $total_orders = 0;
        foreach ( $woo as $s ) :
            $total_orders += $s['count'];
            $icon = $status_icons[ $s['status'] ] ?? '📦';
        ?>
        <tr style="border-bottom:1px solid #f9fafb;">
          <td style="padding:10px 0;font-size:13px;color:#374151;"><?php echo $icon . ' ' . esc_html( $s['label'] ); ?></td>
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

    <?php if ( ! empty( $ga4['sessions'] ) ) : ?>
    <!-- Visitantes -->
    <h2 style="margin:0 0 14px;font-size:13px;font-weight:600;color:#1a2e4f;text-transform:uppercase;letter-spacing:.5px;">Tráfico web</h2>
    <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-bottom:28px;">
      <tbody>
        <tr style="border-bottom:1px solid #f9fafb;">
          <td style="padding:10px 0;font-size:13px;color:#374151;">👥 Sesiones</td>
          <td style="text-align:right;padding:10px 0;font-size:13px;font-weight:600;color:#1a2e4f;"><?php echo number_format( $ga4['sessions'] ); ?></td>
        </tr>
        <?php if ( ! empty( $ga4['new_users'] ) ) : ?>
        <tr style="border-bottom:1px solid #f9fafb;">
          <td style="padding:10px 0;font-size:13px;color:#374151;">✨ Usuarios nuevos</td>
          <td style="text-align:right;padding:10px 0;font-size:13px;font-weight:600;color:#1a2e4f;"><?php echo number_format( $ga4['new_users'] ); ?></td>
        </tr>
        <?php endif; ?>
        <?php if ( ! empty( $ga4['engagement_rate'] ) ) : ?>
        <tr>
          <td style="padding:10px 0;font-size:13px;color:#374151;">⚡ Engagement</td>
          <td style="text-align:right;padding:10px 0;font-size:13px;font-weight:600;color:#1a2e4f;"><?php echo $ga4['engagement_rate']; ?>%</td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <?php if ( ! empty( $snippet ) ) : ?>
    <!-- Snippet de IA -->
    <div style="background:#eff6ff;border-left:3px solid #60a5fa;border-radius:0 8px 8px 0;padding:16px 20px;margin-bottom:28px;">
      <p style="margin:0 0 4px;font-size:11px;font-weight:600;color:#60a5fa;text-transform:uppercase;letter-spacing:.5px;">🤖 Análisis</p>
      <p style="margin:0;font-size:13px;color:#1e40af;line-height:1.6;"><?php echo esc_html( $snippet ); ?></p>
    </div>
    <?php endif; ?>

    <!-- Botón -->
    <p style="text-align:center;margin:0;">
      <a href="<?php echo esc_url( $site_url . '/wp-admin/admin.php?page=pmp-dashboard' ); ?>"
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
