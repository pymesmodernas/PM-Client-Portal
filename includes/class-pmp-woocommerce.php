<?php
/**
 * PMP_WooCommerce — Lectura de datos de WooCommerce desde la instalación local del cliente.
 * HPOS-compatible: usa wc_get_orders() y tablas de analíticas (wc_order_stats, wc_order_product_lookup).
 */
defined( 'ABSPATH' ) || exit;

class PMP_WooCommerce {

    /** WooCommerce está activo en este sitio */
    public static function is_active(): bool {
        return function_exists( 'wc_get_orders' ) && class_exists( 'WooCommerce' );
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Ingresos y pedidos (usa wc_order_stats — disponible desde WC 4.0)
     * ───────────────────────────────────────────────────────────────────────── */

    public static function get_revenue_stats( string $date_from, string $date_to, string $status = '' ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_order_stats';

        // Verificar que la tabla existe
        if ( ! self::table_exists( $table ) ) {
            return self::fallback_revenue_stats( $date_from, $date_to );
        }

        $status_list  = self::resolve_statuses( $status );
        $placeholders = implode( ',', array_fill( 0, count( $status_list ), '%s' ) );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*)                         AS total_orders,
                COALESCE(SUM(net_total), 0)      AS net_revenue,
                COALESCE(SUM(total_sales), 0)    AS gross_revenue,
                COALESCE(SUM(num_items_sold), 0) AS items_sold
             FROM {$table}
             WHERE status IN ($placeholders)
               AND DATE(date_created) BETWEEN %s AND %s",
            ...array_merge( $status_list, [ $date_from, $date_to ] )
        ) );

        return [
            'total_orders'  => (int)   ( $row->total_orders  ?? 0 ),
            'net_revenue'   => (float) ( $row->net_revenue    ?? 0 ),
            'gross_revenue' => (float) ( $row->gross_revenue  ?? 0 ),
            'items_sold'    => (int)   ( $row->items_sold     ?? 0 ),
        ];
    }

    public static function get_orders_by_status( string $date_from, string $date_to ): array {
        global $wpdb;

        // Usar wc_order_stats para el conteo — una sola query agregada, sin cargar objetos en memoria
        $stats_table = $wpdb->prefix . 'wc_order_stats';

        if ( self::table_exists( $stats_table ) ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT status, COUNT(*) AS count
                 FROM {$stats_table}
                 WHERE DATE(date_created) BETWEEN %s AND %s
                 GROUP BY status",
                $date_from, $date_to
            ) );

            $label_map = [
                'wc-completed'  => 'Completado',
                'wc-processing' => 'Procesando',
                'wc-pending'    => 'Pendiente',
                'wc-on-hold'    => 'En espera',
                'wc-cancelled'  => 'Cancelado',
                'wc-refunded'   => 'Reembolsado',
            ];

            $data = [];
            foreach ( $rows as $r ) {
                $slug  = $r->status;
                $label = $label_map[ $slug ] ?? ucfirst( str_replace( 'wc-', '', $slug ) );
                $data[] = [
                    'status'       => $slug,
                    'status_label' => $label,
                    'count'        => (int) $r->count,
                ];
            }
            usort( $data, fn( $a, $b ) => $b['count'] - $a['count'] );
            return $data;
        }

        // Fallback HPOS: contar por estado sin limit -1 (por lotes de 100)
        $statuses = [ 'wc-completed', 'wc-processing', 'wc-pending', 'wc-on-hold', 'wc-cancelled', 'wc-refunded' ];
        $label_map = [
            'wc-completed'  => 'Completado', 'wc-processing' => 'Procesando',
            'wc-pending'    => 'Pendiente',  'wc-on-hold'    => 'En espera',
            'wc-cancelled'  => 'Cancelado',  'wc-refunded'   => 'Reembolsado',
        ];
        $data = [];
        foreach ( $statuses as $slug ) {
            $count = count( wc_get_orders( [
                'status'       => [ $slug ],
                'date_created' => $date_from . '...' . $date_to,
                'limit'        => 1000, // razonable; si hay más, trunca pero no revienta
                'return'       => 'ids',
            ] ) );
            if ( $count > 0 ) {
                $data[] = [ 'status' => $slug, 'status_label' => $label_map[ $slug ], 'count' => $count ];
            }
        }
        usort( $data, fn( $a, $b ) => $b['count'] - $a['count'] );
        return $data;
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Top productos (usa wc_order_product_lookup)
     * ───────────────────────────────────────────────────────────────────────── */

    public static function get_top_products( string $date_from, string $date_to, int $limit = 5 ): array {
        global $wpdb;
        $lookup = $wpdb->prefix . 'wc_order_product_lookup';

        if ( self::table_exists( $lookup ) ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT product_id, SUM(product_qty) AS total_qty, SUM(product_net_revenue) AS total_revenue
                 FROM {$lookup}
                 WHERE DATE(date_created) BETWEEN %s AND %s
                 GROUP BY product_id
                 ORDER BY total_qty DESC
                 LIMIT %d",
                $date_from, $date_to, $limit
            ) );

            if ( ! empty( $rows ) ) {
                $data = [];
                foreach ( $rows as $r ) {
                    $product = wc_get_product( $r->product_id );
                    if ( ! $product ) continue;

                    $image_url = '';
                    $image_id  = $product->get_image_id();
                    if ( $image_id ) {
                        $src       = wp_get_attachment_image_src( $image_id, 'thumbnail' );
                        $image_url = $src ? $src[0] : '';
                    }

                    $data[] = [
                        'product_id'    => (int)   $r->product_id,
                        'name'          => $product->get_name(),
                        'total_qty'     => (int)   $r->total_qty,
                        'total_revenue' => (float) $r->total_revenue,
                        'image_url'     => $image_url,
                        'permalink'     => get_permalink( $r->product_id ),
                    ];
                }
                return $data;
            }
        }

        // La tabla de analytics no existe o no está sincronizada: fallback directo a pedidos
        return self::get_top_products_fallback( $date_from, $date_to, $limit );
    }

    private static function get_top_products_fallback( string $date_from, string $date_to, int $limit ): array {
        $orders = wc_get_orders( [
            'status'       => [ 'wc-completed', 'wc-processing' ],
            'date_created' => $date_from . '...' . $date_to,
            'limit'        => 500,
            'return'       => 'objects',
            'orderby'      => 'date',
            'order'        => 'DESC',
        ] );

        $totals = [];
        foreach ( $orders as $order ) {
            foreach ( $order->get_items() as $item ) {
                $product_id   = (int) $item->get_product_id();
                $variation_id = (int) $item->get_variation_id();

                // Agrupar variaciones bajo el producto padre
                if ( $variation_id ) {
                    $parent = (int) wp_get_post_parent_id( $variation_id );
                    if ( $parent ) $product_id = $parent;
                }

                if ( ! $product_id ) continue;

                if ( ! isset( $totals[ $product_id ] ) ) {
                    $totals[ $product_id ] = [ 'qty' => 0, 'revenue' => 0.0 ];
                }
                $totals[ $product_id ]['qty']     += (int)   $item->get_quantity();
                $totals[ $product_id ]['revenue'] += (float) $item->get_subtotal();
            }
        }

        uasort( $totals, fn( $a, $b ) => $b['qty'] <=> $a['qty'] );

        $data  = [];
        $count = 0;
        foreach ( $totals as $product_id => $t ) {
            if ( $count >= $limit ) break;

            $product = wc_get_product( $product_id );
            if ( ! $product ) continue;

            $image_url = '';
            $image_id  = $product->get_image_id();
            if ( $image_id ) {
                $src       = wp_get_attachment_image_src( $image_id, 'thumbnail' );
                $image_url = $src ? $src[0] : '';
            }

            $data[] = [
                'product_id'    => $product_id,
                'name'          => $product->get_name(),
                'total_qty'     => $t['qty'],
                'total_revenue' => $t['revenue'],
                'image_url'     => $image_url,
                'permalink'     => get_permalink( $product_id ),
            ];
            $count++;
        }

        return $data;
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Top clientes (usa wc_order_stats)
     * ───────────────────────────────────────────────────────────────────────── */

    public static function get_top_customers( string $date_from, string $date_to, int $limit = 5, string $status = '' ): array {
        global $wpdb;
        $stats = $wpdb->prefix . 'wc_order_stats';

        if ( ! self::table_exists( $stats ) ) {
            return [];
        }

        $status_list  = self::resolve_statuses( $status );
        $placeholders = implode( ',', array_fill( 0, count( $status_list ), '%s' ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT customer_id,
                    SUM(total_sales) AS total_spent,
                    COUNT(*) AS order_count
             FROM {$stats}
             WHERE status IN ($placeholders)
               AND DATE(date_created) BETWEEN %s AND %s
               AND customer_id > 0
             GROUP BY customer_id
             ORDER BY total_spent DESC
             LIMIT %d",
            ...array_merge( $status_list, [ $date_from, $date_to, $limit ] )
        ) );

        $data = [];
        foreach ( $rows as $r ) {
            // customer_id en wc_order_stats es el ID de la tabla wc_customer_lookup, no WP user ID
            // Necesitamos obtener el user_id real
            $lookup = $wpdb->prefix . 'wc_customer_lookup';
            $user_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT user_id FROM {$lookup} WHERE customer_id = %d LIMIT 1",
                $r->customer_id
            ) );

            $name  = '—';
            $email = '';
            if ( $user_id ) {
                $user  = get_userdata( $user_id );
                $name  = $user ? trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name : '—';
                $email = $user ? $user->user_email : '';
            } else {
                // Guest: buscar en wc_customer_lookup
                $customer_row = $wpdb->get_row( $wpdb->prepare(
                    "SELECT first_name, last_name, email FROM {$lookup} WHERE customer_id = %d LIMIT 1",
                    $r->customer_id
                ) );
                if ( $customer_row ) {
                    $name  = trim( $customer_row->first_name . ' ' . $customer_row->last_name ) ?: 'Invitado';
                    $email = $customer_row->email ?? '';
                }
            }

            $data[] = [
                'name'        => $name,
                'email'       => $email,
                'total_spent' => (float) $r->total_spent,
                'order_count' => (int)   $r->order_count,
            ];
        }

        return $data;
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Ventas diarias (últimos N días)
     * ───────────────────────────────────────────────────────────────────────── */

    public static function get_daily_sales( int $days = 14 ): array {
        global $wpdb;
        $stats = $wpdb->prefix . 'wc_order_stats';

        $date_from = date( 'Y-m-d', strtotime( "-{$days} days" ) );
        $date_to   = date( 'Y-m-d' );

        if ( ! self::table_exists( $stats ) ) {
            return self::fallback_daily_sales( $days );
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(date_created) AS day,
                    COALESCE(SUM(net_total), 0)   AS revenue,
                    COUNT(*)                       AS orders
             FROM {$stats}
             WHERE status IN ('wc-completed','wc-processing')
               AND DATE(date_created) BETWEEN %s AND %s
             GROUP BY day
             ORDER BY day ASC",
            $date_from, $date_to
        ) );

        // Rellenar días sin ventas con cero
        $map = [];
        foreach ( $rows as $r ) $map[ $r->day ] = [ 'revenue' => (float) $r->revenue, 'orders' => (int) $r->orders ];

        $data = [];
        for ( $i = $days; $i >= 0; $i-- ) {
            $day = date( 'Y-m-d', strtotime( "-{$i} days" ) );
            $data[] = [
                'date'    => $day,
                'label'   => date_i18n( 'd M', strtotime( $day ) ),
                'revenue' => $map[ $day ]['revenue'] ?? 0.0,
                'orders'  => $map[ $day ]['orders']  ?? 0,
            ];
        }

        return $data;
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Fallbacks cuando wc_order_stats no existe (WC < 4.0)
     * ───────────────────────────────────────────────────────────────────────── */

    private static function fallback_revenue_stats( string $date_from, string $date_to ): array {
        $orders = wc_get_orders( [
            'status'       => [ 'completed', 'processing' ],
            'date_created' => $date_from . '...' . $date_to,
            'limit'        => -1,
        ] );

        $revenue = 0.0;
        foreach ( $orders as $o ) $revenue += (float) $o->get_net_payment();

        return [
            'total_orders'  => count( $orders ),
            'net_revenue'   => $revenue,
            'gross_revenue' => $revenue,
            'items_sold'    => 0,
        ];
    }

    private static function fallback_daily_sales( int $days ): array {
        $data = [];
        for ( $i = $days; $i >= 0; $i-- ) {
            $day    = date( 'Y-m-d', strtotime( "-{$i} days" ) );
            $data[] = [ 'date' => $day, 'label' => date_i18n( 'd M', strtotime( $day ) ), 'revenue' => 0.0, 'orders' => 0 ];
        }
        return $data;
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Order Attribution (nativo WooCommerce 8.5+)
     * ───────────────────────────────────────────────────────────────────────── */

    /**
     * Lee los metadatos de WooCommerce Order Attribution de un pedido.
     * Disponibles desde WC 8.5 sin plugin adicional.
     *
     * @param WC_Order $order
     * @return array  Claves: source_type, utm_source, utm_medium, utm_campaign,
     *                        session_entry, device_type, referrer, session_count
     */
    public static function get_order_attribution( WC_Order $order ): array {
        return [
            'source_type'   => $order->get_meta( '_wc_order_attribution_source_type',    true ) ?: null,
            'utm_source'    => $order->get_meta( '_wc_order_attribution_utm_source',      true ) ?: null,
            'utm_medium'    => $order->get_meta( '_wc_order_attribution_utm_medium',      true ) ?: null,
            'utm_campaign'  => $order->get_meta( '_wc_order_attribution_utm_campaign',    true ) ?: null,
            'utm_content'   => $order->get_meta( '_wc_order_attribution_utm_content',     true ) ?: null,
            'utm_term'      => $order->get_meta( '_wc_order_attribution_utm_term',        true ) ?: null,
            'session_entry' => $order->get_meta( '_wc_order_attribution_session_entry',   true ) ?: null,
            'device_type'   => $order->get_meta( '_wc_order_attribution_device_type',     true ) ?: null,
            'referrer'      => $order->get_meta( '_wc_order_attribution_referrer',        true ) ?: null,
            'session_count' => (int) ( $order->get_meta( '_wc_order_attribution_session_count', true ) ?: 0 ),
        ];
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Ventas diarias por rango explícito (Dashboard Resumen)
     * ───────────────────────────────────────────────────────────────────────── */

    public static function get_daily_sales_range( string $date_from, string $date_to, string $status = '' ): array {
        global $wpdb;
        $stats = $wpdb->prefix . 'wc_order_stats';

        if ( ! self::table_exists( $stats ) ) {
            return [];
        }

        $status_list  = self::resolve_statuses( $status );
        $placeholders = implode( ',', array_fill( 0, count( $status_list ), '%s' ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(date_created) AS day,
                    COALESCE(SUM(net_total), 0) AS revenue,
                    COUNT(*) AS orders
             FROM {$stats}
             WHERE status IN ($placeholders)
               AND DATE(date_created) BETWEEN %s AND %s
             GROUP BY day
             ORDER BY day ASC",
            ...array_merge( $status_list, [ $date_from, $date_to ] )
        ) );

        $map = [];
        foreach ( $rows as $r ) {
            $map[ $r->day ] = [ 'revenue' => (float) $r->revenue, 'orders' => (int) $r->orders ];
        }

        $data    = [];
        $current = $date_from;
        while ( $current <= $date_to ) {
            $data[] = [
                'date'    => $current,
                'label'   => date_i18n( 'd M', strtotime( $current ) ),
                'revenue' => $map[ $current ]['revenue'] ?? 0.0,
                'orders'  => $map[ $current ]['orders']  ?? 0,
            ];
            $current = date( 'Y-m-d', strtotime( $current . ' +1 day' ) );
        }

        return $data;
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Productos sin ventas en los últimos N días (Dashboard Resumen)
     * ───────────────────────────────────────────────────────────────────────── */

    public static function get_dormant_products( int $days = 180, int $limit = 10 ): array {
        global $wpdb;
        $lookup = $wpdb->prefix . 'wc_order_product_lookup';
        $cutoff = date( 'Y-m-d', strtotime( "-{$days} days" ) );

        if ( self::table_exists( $lookup ) ) {
            // Verificar que la tabla tiene datos (no solo existe vacía)
            $table_has_data = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$lookup} LIMIT 1" );

            if ( $table_has_data > 0 ) {
                // LEFT JOIN es más seguro y eficiente que NOT IN para tablas grandes
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT p.ID AS product_id, p.post_title AS name
                     FROM {$wpdb->posts} p
                     LEFT JOIN {$lookup} opl
                            ON opl.product_id = p.ID
                           AND DATE(opl.date_created) >= %s
                     WHERE p.post_type   = 'product'
                       AND p.post_status = 'publish'
                       AND opl.product_id IS NULL
                     ORDER BY p.ID DESC
                     LIMIT %d",
                    $cutoff, $limit
                ) );

                if ( false !== $rows && ! $wpdb->last_error ) {
                    $data = [];
                    foreach ( $rows as $r ) {
                        $last_gmt = $wpdb->get_var( $wpdb->prepare(
                            "SELECT MAX(date_created) FROM {$lookup} WHERE product_id = %d",
                            $r->product_id
                        ) );

                        $data[] = [
                            'product_id' => (int) $r->product_id,
                            'name'       => $r->name,
                            'last_sold'  => $last_gmt
                                ? date_i18n( get_option( 'date_format' ), strtotime( $last_gmt ) )
                                : 'Nunca',
                            'edit_url'   => admin_url( 'post.php?post=' . (int) $r->product_id . '&action=edit' ),
                        ];
                    }
                    return $data;
                }
            }
        }

        // La tabla de analytics no existe o está vacía: fallback directo a pedidos
        return self::get_dormant_products_fallback( $days, $limit );
    }

    private static function get_dormant_products_fallback( int $days, int $limit ): array {
        global $wpdb;

        $date_from = date( 'Y-m-d', strtotime( "-{$days} days" ) );
        $date_to   = current_time( 'Y-m-d' );

        // Paso 1: IDs de productos vendidos en los últimos N días
        $recent_orders = wc_get_orders( [
            'status'       => [ 'wc-completed', 'wc-processing', 'wc-pending', 'wc-on-hold' ],
            'date_created' => $date_from . '...' . $date_to,
            'limit'        => 1000,
            'return'       => 'objects',
        ] );

        $sold_ids = [];
        foreach ( $recent_orders as $order ) {
            foreach ( $order->get_items() as $item ) {
                $pid = (int) $item->get_product_id();
                if ( $pid ) $sold_ids[ $pid ] = true;
            }
        }

        // Paso 2: todos los productos publicados
        $all_ids = get_posts( [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ] );

        // Paso 3: filtrar los que no se han vendido en el período
        $dormant_ids = [];
        foreach ( $all_ids as $pid ) {
            if ( ! isset( $sold_ids[ (int) $pid ] ) ) {
                $dormant_ids[] = (int) $pid;
                if ( count( $dormant_ids ) >= $limit ) break;
            }
        }

        if ( empty( $dormant_ids ) ) return [];

        // Paso 4: fecha de última venta — soporta HPOS y pedidos clásicos
        $use_hpos  = self::table_exists( $wpdb->prefix . 'wc_orders' );
        $items_tbl = $wpdb->prefix . 'woocommerce_order_items';
        $meta_tbl  = $wpdb->prefix . 'woocommerce_order_itemmeta';

        $data = [];
        foreach ( $dormant_ids as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product ) continue;

            if ( $use_hpos ) {
                $orders_tbl = $wpdb->prefix . 'wc_orders';
                $last_gmt   = $wpdb->get_var( $wpdb->prepare(
                    "SELECT MAX(o.date_created_gmt)
                     FROM {$items_tbl} oi
                     INNER JOIN {$meta_tbl} oim ON oi.order_item_id = oim.order_item_id
                     INNER JOIN {$orders_tbl} o  ON oi.order_id = o.id
                     WHERE oim.meta_key = '_product_id' AND oim.meta_value = %d",
                    $product_id
                ) );
            } else {
                $last_gmt = $wpdb->get_var( $wpdb->prepare(
                    "SELECT MAX(p.post_date_gmt)
                     FROM {$items_tbl} oi
                     INNER JOIN {$meta_tbl} oim ON oi.order_item_id = oim.order_item_id
                     INNER JOIN {$wpdb->posts} p  ON oi.order_id = p.ID AND p.post_type = 'shop_order'
                     WHERE oim.meta_key = '_product_id' AND oim.meta_value = %d",
                    $product_id
                ) );
            }

            $data[] = [
                'product_id' => $product_id,
                'name'       => $product->get_name(),
                'last_sold'  => $last_gmt
                    ? date_i18n( get_option( 'date_format' ), strtotime( $last_gmt ) )
                    : 'Nunca',
                'edit_url'   => admin_url( 'post.php?post=' . $product_id . '&action=edit' ),
            ];
        }

        return $data;
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Pedidos recientes para la tabla del Dashboard Resumen
     * ───────────────────────────────────────────────────────────────────────── */

    public static function get_recent_orders( int $limit = 15, string $date_from = '', string $date_to = '', string $status = '' ): array {
        $allowed = [ 'wc-completed', 'wc-processing', 'wc-pending', 'wc-on-hold', 'wc-cancelled', 'wc-refunded', 'wc-failed' ];

        $args = [
            'limit'   => $limit,
            'orderby' => 'date',
            'order'   => 'DESC',
            'return'  => 'objects',
            'status'  => ( $status && in_array( $status, $allowed, true ) ) ? [ $status ] : $allowed,
        ];

        if ( $date_from && $date_to ) {
            $args['date_created'] = $date_from . '...' . $date_to;
        }

        $orders      = wc_get_orders( $args );
        $wc_statuses = wc_get_order_statuses();
        $data        = [];

        foreach ( $orders as $order ) {
            $status_key = 'wc-' . $order->get_status();
            $data[] = [
                'id'           => $order->get_id(),
                'number'       => $order->get_order_number(),
                'date'         => $order->get_date_created()
                    ? $order->get_date_created()->date_i18n( get_option( 'date_format' ) )
                    : '—',
                'customer'     => trim( $order->get_formatted_billing_full_name() )
                    ?: ( $order->get_billing_email() ?: 'Invitado' ),
                'total'        => (float) $order->get_total(),
                'status'       => $status_key,
                'status_label' => $wc_statuses[ $status_key ] ?? ucfirst( $order->get_status() ),
                'items_count'  => $order->get_item_count(),
                'edit_url'     => $order->get_edit_order_url(),
            ];
        }

        return $data;
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Contexto para análisis de IA (Dashboard Resumen)
     * ───────────────────────────────────────────────────────────────────────── */

    /**
     * Productos con mucho stock y pocas/nulas ventas en el período.
     * Candidatos a descuento, promoción o revisión de estrategia.
     *
     * @param int[] $top_seller_ids IDs de los top sellers (se excluyen del resultado).
     * @param int   $limit          Máximo de resultados.
     */
    public static function get_high_stock_low_sales( array $top_seller_ids = [], int $limit = 10 ): array {
        global $wpdb;

        // Construir cláusula NOT IN de forma segura (array_map('intval') garantiza enteros)
        $exclude_sql = '';
        if ( ! empty( $top_seller_ids ) ) {
            $ids_safe    = implode( ',', array_map( 'intval', $top_seller_ids ) );
            $exclude_sql = "AND p.ID NOT IN ($ids_safe)";
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, CAST(pm_s.meta_value AS SIGNED) AS stock
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_ms
                     ON pm_ms.post_id  = p.ID AND pm_ms.meta_key = '_manage_stock' AND pm_ms.meta_value = 'yes'
             INNER JOIN {$wpdb->postmeta} pm_s
                     ON pm_s.post_id   = p.ID AND pm_s.meta_key  = '_stock'
             WHERE p.post_type   = 'product'
               AND p.post_status = 'publish'
               AND CAST(pm_s.meta_value AS SIGNED) >= 5
               {$exclude_sql}
             ORDER BY CAST(pm_s.meta_value AS SIGNED) DESC
             LIMIT %d",
            $limit * 3   // sobramos para filtrar invisibles
        ) );
        // phpcs:enable

        if ( empty( $rows ) ) return [];

        $data  = [];
        $count = 0;
        foreach ( $rows as $row ) {
            if ( $count >= $limit ) break;
            $product = wc_get_product( (int) $row->ID );
            if ( ! $product || ! $product->is_visible() ) continue;
            $data[] = [
                'product_id' => (int)   $row->ID,
                'name'       => $product->get_name(),
                'stock'      => (int)   $row->stock,
                'price'      => (float) $product->get_regular_price(),
            ];
            $count++;
        }

        return $data;
    }

    /**
     * Productos con alto precio unitario de venta — proxy de margen elevado.
     * Si existe el meta _wc_cog_cost (plugin WooCommerce Cost of Goods),
     * calcula el margen real (%); de lo contrario lo indica como "estimado".
     *
     * @param string $date_from Fecha inicio (Y-m-d).
     * @param string $date_to   Fecha fin   (Y-m-d).
     * @param int    $limit     Máximo de resultados.
     */
    public static function get_high_value_products( string $date_from, string $date_to, int $limit = 10 ): array {
        // Tomar una lista amplia de productos vendidos para calcular precio prom.
        $top = self::get_top_products( $date_from, $date_to, 60 );
        if ( empty( $top ) ) return [];

        $candidates = [];
        foreach ( $top as $p ) {
            $qty     = (int)   $p['total_qty'];
            $revenue = (float) $p['total_revenue'];
            if ( $qty <= 0 || $revenue <= 0 ) continue;

            $avg_price = $revenue / $qty;

            // Intentar obtener costo real (plugin WooCommerce Cost of Goods)
            $cost_raw  = get_post_meta( (int) $p['product_id'], '_wc_cog_cost', true );
            $cost      = ( $cost_raw !== '' && $cost_raw !== false ) ? (float) $cost_raw : null;
            $margin    = ( $cost !== null && $cost > 0 && $avg_price > 0 )
                ? round( ( $avg_price - $cost ) / $avg_price * 100, 1 )
                : null;

            $candidates[] = [
                'product_id'  => $p['product_id'],
                'name'        => $p['name'],
                'total_qty'   => $qty,
                'avg_price'   => round( $avg_price, 2 ),
                'margin_pct'  => $margin,   // null = sin datos de costo
                'has_cost_data' => $cost !== null,
            ];
        }

        // Ordenar por precio promedio unitario DESC (proxy de margen)
        usort( $candidates, fn( $a, $b ) => $b['avg_price'] <=> $a['avg_price'] );

        return array_slice( $candidates, 0, $limit );
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Helpers
     * ───────────────────────────────────────────────────────────────────────── */

    private static function resolve_statuses( string $status ): array {
        $allowed = [ 'wc-completed', 'wc-processing', 'wc-pending', 'wc-on-hold', 'wc-cancelled', 'wc-refunded', 'wc-failed' ];
        if ( $status && in_array( $status, $allowed, true ) ) {
            return [ $status ];
        }
        return [ 'wc-completed', 'wc-processing' ];
    }

    private static function table_exists( string $table ): bool {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }
}
