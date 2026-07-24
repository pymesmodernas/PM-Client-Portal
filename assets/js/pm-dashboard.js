/**
 * pm-dashboard.js — Panel Resumen (admin-only ecommerce dashboard)
 * Requiere: jQuery, Chart.js 4.x, objeto PMPD localizado.
 */
(function ($) {
    'use strict';

    var AJAX   = PMPD.ajax_url;
    var NONCE  = PMPD.nonce;        // pmp_admin_nonce
    var PNONCE = PMPD.portal_nonce; // pmp_nonce (para crear tickets)
    var CUR    = PMPD.currency || '$';

    /* ── Estado global ── */
    var state = { range: '30', from: '', to: '', statuses: [] };

    /* ── Instancias de Chart.js ── */
    var charts = { sales: null, status: null, products: null };

    /* ── Último payload de stats (para enviar a la IA) ── */
    var lastStats   = null;
    var lastDormant = null;

    /* ── Historial de conversación con Claude ── */
    var aiMessages = [];   // [{role:'user'|'assistant', content:'…'}, …]

    /* ── Colores por estado de pedido ── */
    var STATUS_COLORS = {
        'wc-completed':  '#22c55e',
        'wc-processing': '#3b82f6',
        'wc-pending':    '#f59e0b',
        'wc-on-hold':    '#8b5cf6',
        'wc-cancelled':  '#ef4444',
        'wc-refunded':   '#6b7280',
        'wc-failed':     '#dc2626',
    };

    /* ─────────────────────────────────────────────────────────────────────────
     * Helpers
     * ───────────────────────────────────────────────────────────────────────── */

    function fmt(n) {
        return parseFloat(n || 0).toLocaleString('es-CR', { maximumFractionDigits: 2 });
    }
    function fmtCur(n) { return CUR + fmt(n); }
    function esc(s)     { return $('<span>').text(String(s || '')).html(); }

    function statusColor(key, alpha) {
        var hex = STATUS_COLORS[key] || '#9ca3af';
        if (!alpha) return hex;
        var r = parseInt(hex.slice(1, 3), 16);
        var g = parseInt(hex.slice(3, 5), 16);
        var b = parseInt(hex.slice(5, 7), 16);
        return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
    }

    function setLoading(show) { $('#pmpd-loading').toggle(show); }

    function getParams() {
        var p = { nonce: NONCE, range: state.range, status: state.statuses.join(',') };
        if (state.range === 'custom') {
            p.date_from = state.from;
            p.date_to   = state.to;
        }
        return p;
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Carga principal (stats + orders en paralelo)
     * ───────────────────────────────────────────────────────────────────────── */

    function loadDashboard() {
        setLoading(true);
        aiMessages = []; // nueva carga = nueva conversación
        var params = getParams();

        var reqStats  = $.post(AJAX, $.extend({ action: 'pmp_dashboard_stats'  }, params));
        var reqOrders = $.post(AJAX, $.extend({ action: 'pmp_dashboard_orders' }, params));

        $.when(reqStats, reqOrders).always(function () { setLoading(false); });

        reqStats.done(function (r) {
            if (!r || !r.success) return;
            var d = r.data;
            lastStats = d; // guardar para el análisis de IA
            renderKPIs(d.revenue);
            renderSalesChart(d.daily_sales);
            renderStatusChart(d.orders_status);
            renderProductsChart(d.top_products);
            renderCustomers(d.top_customers);
            $('#pmpd-chart-range').text(d.date_from + ' — ' + d.date_to);
        });

        reqOrders.done(function (r) {
            if (!r || !r.success) return;
            renderOrders(r.data);
        });

        // GSC — se carga en paralelo
        if (PMPD.gsc_configured === '1') {
            $.post(AJAX, $.extend({ action: 'pmp_dashboard_gsc' }, params))
                .done(function (r) {
                    if (r && r.success) {
                        renderGSC(r.data);
                    } else {
                        var msg = (r && r.data && r.data.message) ? r.data.message : 'Error al cargar Search Console.';
                        $('#pmpd-gsc-body').html(
                            '<p style="color:#ef4444;font-size:13px;padding:8px 0;">⚠️ ' + esc(msg) + '</p>'
                        );
                    }
                })
                .fail(function (xhr) {
                    $('#pmpd-gsc-body').html(
                        '<p style="color:#ef4444;font-size:13px;padding:8px 0;">⚠️ Error HTTP ' + xhr.status + ': ' + (xhr.responseText ? xhr.responseText.substring(0, 200) : 'sin respuesta') + '</p>'
                    );
                });
        }

        // GA4 — se carga en paralelo, independiente del resto
        if (PMPD.ga4_configured === '1') {
            $.post(AJAX, $.extend({ action: 'pmp_dashboard_ga4' }, params), function (r) {
                if (r && r.success) {
                    renderGA4(r.data);
                } else {
                    var msg = (r && r.data && r.data.message) ? r.data.message : 'Error al cargar Analytics.';
                    $('#pmpd-ga4-body').html(
                        '<p style="color:#ef4444;font-size:13px;padding:8px 0;">⚠️ ' + esc(msg) + '</p>'
                    );
                }
            });
        }

        // Meta Ads — se carga en paralelo, independiente del resto
        if (PMPD.meta_configured === '1') {
            $.post(AJAX, $.extend({ action: 'pmp_dashboard_meta' }, params), function (r) {
                if (r && r.success) {
                    renderMeta(r.data);
                } else {
                    var msg = (r && r.data && r.data.message) ? r.data.message : 'Error al cargar Meta Ads.';
                    $('#pmpd-meta-body').html(
                        '<p style="color:#ef4444;font-size:13px;padding:8px 0;">⚠️ ' + esc(msg) + '</p>'
                    );
                }
            });
        }

        // Klaviyo — se carga en paralelo, independiente del resto
        if (PMPD.klaviyo_configured === '1') {
            $.post(AJAX, $.extend({ action: 'pmp_dashboard_klaviyo' }, params), function (r) {
                if (r && r.success) {
                    renderKlaviyo(r.data);
                } else {
                    var msg = (r && r.data && r.data.message) ? r.data.message : 'Error al cargar Klaviyo.';
                    $('#pmpd-klaviyo-body').html(
                        '<p style="color:#ef4444;font-size:13px;padding:8px 0;">⚠️ ' + esc(msg) + '</p>'
                    );
                }
            });
        }
    }

    function loadDormant() {
        $.post(AJAX, { action: 'pmp_dashboard_dormant', nonce: NONCE }, function (r) {
            if (r && r.success) {
                lastDormant = r.data; // guardar para la IA
                renderDormant(r.data);
            }
        });
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Renderers
     * ───────────────────────────────────────────────────────────────────────── */

    function renderKPIs(rev) {
        var avg = rev.total_orders > 0 ? rev.net_revenue / rev.total_orders : 0;
        $('#pmpd-kpi-revenue').text(fmtCur(rev.net_revenue));
        $('#pmpd-kpi-orders').text(rev.total_orders);
        $('#pmpd-kpi-items').text(rev.items_sold);
        $('#pmpd-kpi-avg').text(fmtCur(avg));
    }

    function renderSalesChart(data) {
        var canvas = document.getElementById('pmpd-sales-chart');
        if (!canvas) return;
        if (charts.sales) { charts.sales.destroy(); charts.sales = null; }

        data = data || [];
        var labels   = data.map(function (d) { return d.label; });
        var revenues = data.map(function (d) { return parseFloat(d.revenue) || 0; });
        var orders   = data.map(function (d) { return parseInt(d.orders, 10) || 0; });
        var showPts  = labels.length <= 31;

        charts.sales = new Chart(canvas, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Ingresos',
                        data: revenues,
                        borderColor: '#1a2e4f',
                        backgroundColor: 'rgba(26,46,79,.08)',
                        fill: true,
                        tension: 0.35,
                        pointRadius: showPts ? 3 : 0,
                        pointHoverRadius: 5,
                        borderWidth: 2,
                        yAxisID: 'yRev',
                    },
                    {
                        label: 'Pedidos',
                        data: orders,
                        borderColor: '#60a5fa',
                        backgroundColor: 'transparent',
                        fill: false,
                        tension: 0.35,
                        pointRadius: showPts ? 3 : 0,
                        pointHoverRadius: 5,
                        borderWidth: 2,
                        borderDash: [5, 4],
                        yAxisID: 'yOrd',
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { font: { size: 12 }, usePointStyle: true },
                    },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                return ctx.dataset.yAxisID === 'yRev'
                                    ? ' ' + fmtCur(ctx.raw)
                                    : ' ' + ctx.raw + ' pedidos';
                            },
                        },
                    },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { maxTicksLimit: 12, font: { size: 11 } },
                    },
                    yRev: {
                        position: 'left',
                        grid: { color: '#f3f4f6' },
                        ticks: { callback: function (v) { return CUR + v; }, font: { size: 11 } },
                    },
                    yOrd: {
                        position: 'right',
                        grid: { display: false },
                        ticks: { stepSize: 1, font: { size: 11 } },
                    },
                },
            },
        });
    }

    function renderStatusChart(data) {
        var canvas = document.getElementById('pmpd-status-chart');
        if (!canvas) return;
        if (charts.status) { charts.status.destroy(); charts.status = null; }

        if (!data || data.length === 0) {
            $(canvas).closest('.pmpd-chart-wrap').html('<p class="pmpd-list-empty">Sin pedidos en este período.</p>');
            return;
        }

        charts.status = new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: data.map(function (d) { return d.status_label; }),
                datasets: [{
                    data:            data.map(function (d) { return d.count; }),
                    backgroundColor: data.map(function (d) { return statusColor(d.status); }),
                    borderWidth: 2,
                    borderColor: '#fff',
                    hoverOffset: 6,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                cutout: '60%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { font: { size: 11 }, padding: 10, usePointStyle: true },
                    },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var total = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                                var pct   = total > 0 ? Math.round(ctx.raw / total * 100) : 0;
                                return ' ' + ctx.raw + ' (' + pct + '%)';
                            },
                        },
                    },
                },
            },
        });
    }

    function renderProductsChart(data) {
        var canvas = document.getElementById('pmpd-products-chart');
        if (!canvas) return;
        if (charts.products) { charts.products.destroy(); charts.products = null; }

        if (!data || data.length === 0) {
            $(canvas).closest('.pmpd-chart-wrap').html('<p class="pmpd-list-empty">Sin ventas en este período.</p>');
            return;
        }

        var names = data.map(function (p) {
            var n = p.name || '';
            return n.length > 34 ? n.slice(0, 34) + '…' : n;
        });

        // Altura dinámica: ~52px por fila + espacio para leyenda
        canvas.style.height = Math.max(220, data.length * 52 + 60) + 'px';

        charts.products = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: names,
                datasets: [
                    {
                        label: 'Unidades',
                        data: data.map(function (p) { return p.total_qty; }),
                        backgroundColor: '#1a2e4f',
                        borderRadius: 3,
                        xAxisID: 'xQty',   // eje de valores (horizontal)
                    },
                    {
                        label: 'Ingresos',
                        data: data.map(function (p) { return p.total_revenue; }),
                        backgroundColor: '#60a5fa',
                        borderRadius: 3,
                        xAxisID: 'xRev',   // eje de valores (horizontal)
                    },
                ],
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,   // altura controlada por canvas.style.height
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { font: { size: 11 }, usePointStyle: true },
                    },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                return ctx.dataset.xAxisID === 'xRev'
                                    ? ' ' + fmtCur(ctx.raw)
                                    : ' ' + ctx.raw + ' uds';
                            },
                        },
                    },
                },
                scales: {
                    // Eje Y = categorías (nombres de productos) — visible
                    y: {
                        display: true,
                        ticks: {
                            font: { size: 12 },
                            color: '#374151',
                        },
                        grid: { display: false },
                    },
                    // Ejes X = valores numéricos — ocultos (solo barras visuales)
                    xQty: {
                        axis: 'x',
                        display: false,
                        beginAtZero: true,
                    },
                    xRev: {
                        axis: 'x',
                        display: false,
                        beginAtZero: true,
                    },
                },
            },
        });
    }

    function renderOrders(orders) {
        var $tbody = $('#pmpd-orders-tbody').empty();

        if (!orders || orders.length === 0) {
            $tbody.append('<tr><td colspan="7" class="pmpd-table-empty">Sin pedidos en este período.</td></tr>');
            return;
        }

        $.each(orders, function (_, o) {
            var clr   = STATUS_COLORS[o.status] || '#9ca3af';
            var badge = '<span class="pmpd-status-badge" style="background:'
                + statusColor(o.status, 0.1) + ';color:' + clr
                + ';border:1px solid ' + statusColor(o.status, 0.3) + ';">'
                + esc(o.status_label) + '</span>';

            $tbody.append(
                '<tr>' +
                    '<td><strong>#' + esc(o.number) + '</strong></td>' +
                    '<td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(o.customer) + '</td>' +
                    '<td>' + o.items_count + '</td>' +
                    '<td>' + fmtCur(o.total) + '</td>' +
                    '<td>' + badge + '</td>' +
                    '<td>' + esc(o.date) + '</td>' +
                    '<td><a href="' + esc(o.edit_url) + '" class="button button-small" target="_blank" rel="noopener">Ver →</a></td>' +
                '</tr>'
            );
        });
    }

    function renderCustomers(customers) {
        var $list = $('#pmpd-customers-list').empty();

        if (!customers || customers.length === 0) {
            $list.html('<p class="pmpd-list-empty">Sin datos en este período.</p>');
            return;
        }

        $.each(customers, function (i, c) {
            var name     = c.name || c.email || 'Invitado';
            var words    = name.split(' ').filter(function (w) { return w.length > 0; });
            var initials = words.slice(0, 2).map(function (w) { return w[0].toUpperCase(); }).join('');

            $list.append(
                '<div class="pmpd-list-item">' +
                    '<div class="pmpd-list-rank">' + (i + 1) + '</div>' +
                    '<div class="pmpd-avatar">' + esc(initials || 'IN') + '</div>' +
                    '<div class="pmpd-list-info">' +
                        '<div class="pmpd-list-name">' + esc(name) + '</div>' +
                        '<div class="pmpd-list-sub">' +
                            c.order_count + ' pedido' + (c.order_count !== 1 ? 's' : '') +
                            ' · ' + fmtCur(c.total_spent) +
                        '</div>' +
                    '</div>' +
                '</div>'
            );
        });
    }

    /* ── Productos dormidos con paginación client-side ── */
    var dormantPage     = 0;   // página actual (0-indexed)
    var dormantPerPage  = 10;  // ítems por página
    var dormantProducts = [];  // todos los productos cargados

    function renderDormantPage() {
        var $list  = $('#pmpd-dormant-list').empty();
        var total  = dormantProducts.length;
        var pages  = Math.ceil(total / dormantPerPage);
        var start  = dormantPage * dormantPerPage;
        var slice  = dormantProducts.slice(start, start + dormantPerPage);

        $.each(slice, function (_, p) {
            $list.append(
                '<div class="pmpd-list-item">' +
                    '<div class="pmpd-list-info">' +
                        '<div class="pmpd-list-name">' + esc(p.name) + '</div>' +
                        '<div class="pmpd-list-sub pmpd-dormant-date">Última venta: ' + esc(p.last_sold) + '</div>' +
                    '</div>' +
                    '<a href="' + esc(p.edit_url) + '" class="button button-small" target="_blank" rel="noopener">Editar</a>' +
                '</div>'
            );
        });

        // Paginación — solo si hay más de una página
        if (pages > 1) {
            var from  = start + 1;
            var to    = Math.min(start + dormantPerPage, total);
            var $pag  = $('<div class="pmpd-dormant-pag">');

            $pag.append(
                '<button class="button button-small pmpd-dorm-prev"' +
                (dormantPage === 0 ? ' disabled' : '') + '>← Ant.</button>' +
                '<span class="pmpd-dorm-info">' + from + '–' + to + ' de ' + total + '</span>' +
                '<button class="button button-small pmpd-dorm-next"' +
                (dormantPage >= pages - 1 ? ' disabled' : '') + '>Sig. →</button>'
            );

            $list.append($pag);
        }
    }

    function renderDormant(products) {
        var $list = $('#pmpd-dormant-list').empty();

        if (!products || products.length === 0) {
            $list.html('<p class="pmpd-list-empty pmpd-list-ok">✓ Todos los productos han tenido ventas recientemente.</p>');
            return;
        }

        dormantProducts = products;
        dormantPage     = 0;
        renderDormantPage();
    }

    // Delegación de eventos para los botones de paginación
    $(document).on('click', '.pmpd-dorm-prev', function () {
        if (dormantPage > 0) { dormantPage--; renderDormantPage(); }
    });
    $(document).on('click', '.pmpd-dorm-next', function () {
        var pages = Math.ceil(dormantProducts.length / dormantPerPage);
        if (dormantPage < pages - 1) { dormantPage++; renderDormantPage(); }
    });

    /* ─────────────────────────────────────────────────────────────────────────
     * Google Analytics 4
     * ───────────────────────────────────────────────────────────────────────── */

    function gaKpi(label, value, icon) {
        return '<div style="background:#f9fafb;border-radius:8px;padding:12px 16px;">' +
            '<div style="font-size:11px;color:#9ca3af;margin-bottom:4px;">' + icon + ' ' + label + '</div>' +
            '<div style="font-size:20px;font-weight:700;color:#1a2e4f;line-height:1.2;">' + value + '</div>' +
            '</div>';
    }

    function renderGSC(data) {
        var $body = $('#pmpd-gsc-body');
        if (!$body.length) return;

        var queries = data.queries || [];
        var pages   = data.pages   || [];

        if (!queries.length && !pages.length) {
            $body.html('<p class="pmpd-list-empty">Sin datos de Search Console para este período.</p>');
            return;
        }

        var subTitle = 'font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin:0 0 10px;';
        var tblStyle = 'width:100%;border-collapse:collapse;font-size:12px;';
        var thStyle  = 'padding:4px 4px 6px;color:#9ca3af;font-weight:500;';
        var tdStyle  = 'padding:7px 4px;color:#374151;border-bottom:1px solid #f9fafb;';
        var html     = '';

        // ── Dos columnas: keywords + páginas ──
        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">';

        // Keywords
        html += '<div><h3 style="' + subTitle + '">Top keywords orgánicas</h3>';
        if (queries.length) {
            html += '<table style="' + tblStyle + '"><thead><tr style="border-bottom:2px solid #f3f4f6;">' +
                '<th style="text-align:left;' + thStyle + '">Keyword</th>' +
                '<th style="text-align:right;' + thStyle + '">Pos.</th>' +
                '<th style="text-align:right;' + thStyle + '">Clics</th>' +
                '<th style="text-align:right;' + thStyle + '">CTR</th>' +
                '</tr></thead><tbody>';
            $.each(queries.slice(0, 15), function(_, q) {
                var posColor = q.position <= 3 ? '#16a34a' : q.position <= 10 ? '#d97706' : '#6b7280';
                html += '<tr>' +
                    '<td style="' + tdStyle + 'padding-left:0;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + esc(q.query) + '">' + esc(q.query) + '</td>' +
                    '<td style="text-align:right;' + tdStyle + 'color:' + posColor + ';font-weight:600;">' + q.position + '</td>' +
                    '<td style="text-align:right;' + tdStyle + '">' + fmt(q.clicks) + '</td>' +
                    '<td style="text-align:right;' + tdStyle + '">' + q.ctr + '%</td>' +
                    '</tr>';
            });
            html += '</tbody></table>';

            // Oportunidades
            var opps = queries.filter(function(q){ return q.position >= 4 && q.position <= 20 && q.impressions >= 100; });
            if (opps.length) {
                html += '<div style="margin-top:16px;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:10px 14px;">' +
                    '<p style="margin:0 0 6px;font-size:11px;font-weight:600;color:#92400e;text-transform:uppercase;letter-spacing:.5px;">⚡ Oportunidades (pos. 4–20)</p>';
                $.each(opps.slice(0, 5), function(_, q) {
                    html += '<div style="font-size:12px;color:#374151;padding:3px 0;">' +
                        '"' + esc(q.query) + '" — pos. <strong>' + q.position + '</strong> · ' + fmt(q.impressions) + ' impresiones' +
                    '</div>';
                });
                html += '</div>';
            }
        } else {
            html += '<p style="color:#9ca3af;font-size:12px;">Sin datos.</p>';
        }
        html += '</div>';

        // Páginas orgánicas
        html += '<div><h3 style="' + subTitle + '">Páginas con más clics orgánicos</h3>';
        if (pages.length) {
            $.each(pages, function(_, p) {
                var path = p.page.replace(/^https?:\/\/[^\/]+/, '') || '/';
                var posColor = p.position <= 3 ? '#16a34a' : p.position <= 10 ? '#d97706' : '#6b7280';
                html += '<div style="padding:8px 0;border-bottom:1px solid #f9fafb;">' +
                    '<div style="font-size:12px;color:#374151;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="' + esc(p.page) + '">' + esc(path) + '</div>' +
                    '<div style="font-size:11px;color:#9ca3af;margin-top:3px;">' +
                        'Pos. <span style="color:' + posColor + ';font-weight:600;">' + p.position + '</span>' +
                        ' · ' + fmt(p.clicks) + ' clics' +
                        ' · ' + fmt(p.impressions) + ' impr.' +
                        ' · CTR ' + p.ctr + '%' +
                    '</div>' +
                '</div>';
            });
        } else {
            html += '<p style="color:#9ca3af;font-size:12px;">Sin datos.</p>';
        }
        html += '</div>';

        html += '</div>';

        $body.html(html);
        $('#pmpd-gsc-badge').text('Search Console activo');
    }

    function renderGA4(data) {
        var $body = $('#pmpd-ga4-body');
        if (!$body.length) return;

        var ov           = data.overview     || {};
        var channels     = data.channels     || [];
        var pages        = data.top_pages    || [];
        var devices      = data.devices      || [];
        var retention    = data.retention    || [];
        var events       = data.events       || [];
        var searchTerms  = data.search_terms || [];
        var funnel       = data.funnel       || {};

        if (!ov.sessions) {
            $body.html('<p class="pmpd-list-empty">Sin datos de Analytics para este período.</p>');
            return;
        }

        var newPct = ov.sessions > 0 ? Math.round(ov.new_users / ov.sessions * 100) : 0;
        var html   = '';

        var subTitle = 'font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin:0 0 10px;';
        var tblStyle = 'width:100%;border-collapse:collapse;font-size:12px;';
        var thStyle  = 'padding:4px 4px 6px;color:#9ca3af;font-weight:500;';
        var tdStyle  = 'padding:6px 4px;color:#374151;';

        // ── Funnel de conversión ecommerce ──
        var hasFunnel = funnel.add_to_cart > 0 || funnel.purchases > 0;
        if (hasFunnel) {
            var fSteps = [];
            if (ov.sessions)           fSteps.push({ label: 'Sesiones',         count: ov.sessions,          icon: '👥', color: '#1a4dfd' });
            if (funnel.items_viewed)   fSteps.push({ label: 'Vieron producto',   count: funnel.items_viewed,  icon: '👁', color: '#1a4dfd' });
            if (funnel.add_to_cart)    fSteps.push({ label: 'Al carrito',        count: funnel.add_to_cart,   icon: '🛒', color: '#1a4dfd' });
            if (funnel.checkouts)      fSteps.push({ label: 'Iniciaron pago',    count: funnel.checkouts,     icon: '💳', color: '#1a4dfd' });
            if (funnel.purchases)      fSteps.push({ label: 'Compraron',         count: funnel.purchases,     icon: '✅', color: '#10b981' });

            // Detectar el mayor cuello de botella para el insight
            var biggestDrop = { step: '', pct: 0 };
            for (var si = 1; si < fSteps.length; si++) {
                var dp = fSteps[si-1].count > 0 ? Math.round((1 - fSteps[si].count / fSteps[si-1].count) * 100) : 0;
                if (dp > biggestDrop.pct) biggestDrop = { step: fSteps[si].label, from: fSteps[si-1].label, pct: dp };
            }

            html += '<div style="margin-bottom:24px;">';
            html += '<h3 style="' + subTitle + '">🛒 Embudo de conversión</h3>';
            html += '<div style="display:flex;align-items:stretch;gap:0;overflow-x:auto;padding-bottom:4px;">';

            $.each(fSteps, function(i, step) {
                var prevCount = i > 0 ? fSteps[i-1].count : step.count;
                var convPct   = i > 0 && prevCount > 0 ? Math.round(step.count / prevCount * 100) : 100;
                var dropPct   = i > 0 ? (100 - convPct) : 0;

                var badgeBg    = convPct >= 50 ? '#d1fae5' : convPct >= 25 ? '#fef3c7' : '#fee2e2';
                var badgeColor = convPct >= 50 ? '#065f46' : convPct >= 25 ? '#92400e' : '#991b1b';

                html += '<div style="flex:1;min-width:90px;text-align:center;">';
                html += '<div style="background:#edf1ff;border:1px solid #dce5ff;border-radius:10px;padding:12px 6px;height:100%;box-sizing:border-box;">';
                html += '<div style="font-size:20px;line-height:1;">' + step.icon + '</div>';
                html += '<div style="font-size:17px;font-weight:800;color:' + step.color + ';margin:6px 0 2px;">' + fmt(step.count) + '</div>';
                html += '<div style="font-size:10px;color:#6b7280;font-weight:500;line-height:1.3;">' + step.label + '</div>';
                if (i > 0) {
                    html += '<div style="background:' + badgeBg + ';color:' + badgeColor + ';border-radius:100px;font-size:10px;font-weight:700;padding:2px 7px;margin-top:6px;display:inline-block;">' + convPct + '%</div>';
                }
                html += '</div>';
                html += '</div>';

                if (i < fSteps.length - 1) {
                    var nextDrop = fSteps[i+1].count > 0 && step.count > 0
                        ? Math.round((1 - fSteps[i+1].count / step.count) * 100) : 0;
                    html += '<div style="flex-shrink:0;width:32px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;">';
                    html += '<span style="color:#9ca3af;font-size:14px;">→</span>';
                    if (nextDrop > 0) {
                        html += '<span style="font-size:9px;color:#ef4444;font-weight:700;">-' + nextDrop + '%</span>';
                    }
                    html += '</div>';
                }
            });

            html += '</div>';

            // Insight automático: mayor cuello de botella
            if (biggestDrop.pct > 0) {
                var insightColor = biggestDrop.pct >= 70 ? '#991b1b' : biggestDrop.pct >= 40 ? '#92400e' : '#1e3a5f';
                var insightBg    = biggestDrop.pct >= 70 ? '#fff1f2' : biggestDrop.pct >= 40 ? '#fffbeb' : '#eff6ff';
                html += '<div style="background:' + insightBg + ';border-left:3px solid ' + insightColor + ';border-radius:6px;padding:8px 12px;margin-top:12px;font-size:12px;color:' + insightColor + ';">';
                html += '⚠️ Mayor cuello de botella: <strong>' + biggestDrop.pct + '%</strong> de los usuarios que llegaron a <em>' + biggestDrop.from + '</em> no continuaron a <em>' + biggestDrop.step + '</em>.';
                html += '</div>';
            }

            // Ingresos atribuidos por GA4
            if (funnel.revenue > 0) {
                html += '<div style="margin-top:8px;font-size:12px;color:#6b7280;text-align:right;">';
                html += 'Ingresos atribuidos por GA4: <strong style="color:#10b981;">$' + Number(funnel.revenue).toLocaleString('es', {minimumFractionDigits:2, maximumFractionDigits:2}) + '</strong>';
                html += '</div>';
            }

            html += '</div>';
        }

        // ── Mini KPIs ──
        html += '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px;">';
        html += gaKpi('Sesiones',        fmt(ov.sessions), '👥');
        html += gaKpi('Usuarios nuevos', fmt(ov.new_users) + ' <span style="font-size:11px;color:#9ca3af;font-weight:400;">(' + newPct + '%)</span>', '✨');
        html += gaKpi('Engagement',      ov.engagement_rate + '%', '⚡');
        html += gaKpi('Duración prom.',  ov.avg_session_min + ' min', '⏱');
        html += '</div>';

        // ── Fila 1: Canales + Dispositivos ──
        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:24px;">';

        // Canales
        html += '<div><h3 style="' + subTitle + '">Por canal de adquisición</h3>';
        if (channels.length) {
            html += '<table style="' + tblStyle + '"><thead><tr style="border-bottom:2px solid #f3f4f6;">' +
                '<th style="text-align:left;' + thStyle + '">Canal</th>' +
                '<th style="text-align:right;' + thStyle + '">Sesiones</th>' +
                '<th style="text-align:right;' + thStyle + '">Eng.</th>' +
                '<th style="text-align:right;' + thStyle + '">Conv.</th>' +
                '</tr></thead><tbody>';
            $.each(channels.slice(0, 6), function (_, ch) {
                var cc = ch.conversions > 0 ? '#16a34a' : '#d1d5db';
                html += '<tr style="border-bottom:1px solid #f9fafb;">' +
                    '<td style="' + tdStyle + 'padding-left:0;">' + esc(ch.channel) + '</td>' +
                    '<td style="text-align:right;' + tdStyle + '">' + fmt(ch.sessions) + '</td>' +
                    '<td style="text-align:right;' + tdStyle + '">' + ch.engagement_rate + '%</td>' +
                    '<td style="text-align:right;' + tdStyle + 'color:' + cc + ';font-weight:' + (ch.conversions > 0 ? '600' : '400') + ';">' + ch.conversions + '</td>' +
                    '</tr>';
            });
            html += '</tbody></table>';
        } else {
            html += '<p style="color:#9ca3af;font-size:12px;">Sin datos.</p>';
        }
        html += '</div>';

        // Dispositivos
        html += '<div><h3 style="' + subTitle + '">Por dispositivo</h3>';
        if (devices.length) {
            var totalSess = devices.reduce(function(s, d){ return s + d.sessions; }, 0);
            var deviceIcons = { mobile: '📱', desktop: '💻', tablet: '📟' };
            $.each(devices, function(_, dv) {
                var pct  = totalSess > 0 ? Math.round(dv.sessions / totalSess * 100) : 0;
                var icon = deviceIcons[dv.device] || '🖥';
                var label = dv.device.charAt(0).toUpperCase() + dv.device.slice(1);
                var convStr = dv.conversions > 0
                    ? '<span style="color:#16a34a;font-weight:600;"> · ' + dv.conversions + ' conv.</span>'
                    : '';
                html += '<div style="margin-bottom:10px;">' +
                    '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">' +
                        '<span style="font-size:12px;color:#374151;">' + icon + ' ' + label + '</span>' +
                        '<span style="font-size:12px;color:#6b7280;">' + fmt(dv.sessions) + ' ses. · ' + pct + '%' + convStr + '</span>' +
                    '</div>' +
                    '<div style="background:#f3f4f6;border-radius:4px;height:6px;">' +
                        '<div style="background:#60a5fa;border-radius:4px;height:6px;width:' + pct + '%;"></div>' +
                    '</div>' +
                '</div>';
            });
        } else {
            html += '<p style="color:#9ca3af;font-size:12px;">Sin datos.</p>';
        }
        html += '</div>';

        html += '</div>'; // fin fila 1

        // ── Fila 2: Páginas top + Retención ──
        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:24px;">';

        // Páginas más visitadas
        html += '<div><h3 style="' + subTitle + '">Páginas más visitadas</h3>';
        if (pages.length) {
            $.each(pages.slice(0, 11), function (_, pg) {
                var mins    = Math.floor(pg.avg_time_s / 60);
                var secs    = pg.avg_time_s % 60;
                var timeStr = mins > 0 ? mins + 'm ' + secs + 's' : secs + 's';
                var convStr = pg.conversions > 0
                    ? ' · <span style="color:#16a34a;font-weight:600;">' + pg.conversions + ' conv.</span>' : '';
                html += '<div style="padding:7px 0;border-bottom:1px solid #f9fafb;">' +
                    '<div style="font-size:12px;color:#374151;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="' + esc(pg.path) + '">' + esc(pg.path) + '</div>' +
                    '<div style="font-size:11px;color:#9ca3af;margin-top:2px;">' + fmt(pg.views) + ' vistas · rebote ' + pg.bounce_rate + '% · ' + timeStr + convStr + '</div>' +
                '</div>';
            });
        } else {
            html += '<p style="color:#9ca3af;font-size:12px;">Sin datos.</p>';
        }
        html += '</div>';

        // Retención
        html += '<div><h3 style="' + subTitle + '">Usuarios nuevos vs recurrentes</h3>';
        if (retention.length) {
            var retTotal = retention.reduce(function(s, r){ return s + r.sessions; }, 0);
            var retIcons = { new: '✨', returning: '🔄' };
            var retLabels = { new: 'Nuevos', returning: 'Recurrentes' };
            $.each(retention, function(_, r) {
                var pct    = retTotal > 0 ? Math.round(r.sessions / retTotal * 100) : 0;
                var icon   = retIcons[r.type]  || '👤';
                var label  = retLabels[r.type] || r.type;
                var color  = r.type === 'new' ? '#60a5fa' : '#34d399';
                var convStr = r.conversions > 0
                    ? '<span style="color:#16a34a;font-weight:600;"> · ' + r.conversions + ' conv.</span>' : '';
                html += '<div style="margin-bottom:14px;">' +
                    '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">' +
                        '<span style="font-size:12px;color:#374151;">' + icon + ' ' + label + '</span>' +
                        '<span style="font-size:12px;color:#6b7280;">' + fmt(r.sessions) + ' ses. · ' + pct + '% · eng. ' + r.engagement_rate + '%' + convStr + '</span>' +
                    '</div>' +
                    '<div style="background:#f3f4f6;border-radius:4px;height:6px;">' +
                        '<div style="background:' + color + ';border-radius:4px;height:6px;width:' + pct + '%;"></div>' +
                    '</div>' +
                '</div>';
            });
        } else {
            html += '<p style="color:#9ca3af;font-size:12px;">Sin datos.</p>';
        }
        html += '</div>';

        html += '</div>'; // fin fila 2

        // ── Fila 3: Búsqueda interna (ancho completo) ──
        html += '<div>';

        // Búsqueda interna
        html += '<h3 style="' + subTitle + '">Búsqueda interna del sitio</h3>';
        var filteredTerms = $.grep(searchTerms, function(st) { return st.term.trim() !== ''; });
        if (filteredTerms.length) {
            html += '<table style="' + tblStyle + '"><thead><tr style="border-bottom:2px solid #f3f4f6;">' +
                '<th style="text-align:left;' + thStyle + '">Término</th>' +
                '<th style="text-align:right;' + thStyle + '">Sesiones</th>' +
                '<th style="text-align:right;' + thStyle + '">Conv.</th>' +
                '</tr></thead><tbody>';
            $.each(filteredTerms, function(_, st) {
                var cc = st.conversions > 0 ? '#16a34a' : '#d1d5db';
                html += '<tr style="border-bottom:1px solid #f9fafb;">' +
                    '<td style="' + tdStyle + 'padding-left:0;">' + esc(st.term) + '</td>' +
                    '<td style="text-align:right;' + tdStyle + '">' + fmt(st.sessions) + '</td>' +
                    '<td style="text-align:right;' + tdStyle + 'color:' + cc + ';font-weight:' + (st.conversions > 0 ? '600' : '400') + ';">' + st.conversions + '</td>' +
                    '</tr>';
            });
            html += '</tbody></table>';
        } else {
            html += '<p style="color:#9ca3af;font-size:12px;">Sin datos — requiere búsqueda interna configurada en GA4.</p>';
        }
        html += '</div>';

        $body.html(html);
        $('#pmpd-ga4-badge').text('Analytics activo');
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Meta Ads
     * ───────────────────────────────────────────────────────────────────────── */

    function metaKpi(label, value, icon, highlight) {
        var color = highlight ? '#1a2e4f' : '#1a2e4f';
        var bg    = highlight ? '#eff6ff' : '#f9fafb';
        return '<div style="background:' + bg + ';border-radius:8px;padding:12px 16px;' +
            (highlight ? 'border:1px solid #bfdbfe;' : '') + '">' +
            '<div style="font-size:11px;color:#9ca3af;margin-bottom:4px;">' + icon + ' ' + label + '</div>' +
            '<div style="font-size:20px;font-weight:700;color:' + color + ';line-height:1.2;">' + value + '</div>' +
            '</div>';
    }

    function renderMeta(data) {
        var $body = $('#pmpd-meta-body');
        if (!$body.length) return;

        var ov        = data.overview  || {};
        var campaigns = data.campaigns || [];

        if (!ov.spend && !campaigns.length) {
            $body.html('<p class="pmpd-list-empty">Sin actividad publicitaria en Meta para este período.</p>');
            return;
        }

        var html = '';

        // ── KPIs principales ──
        var roasVal = ov.roas   != null ? ov.roas + 'x'  : '—';
        var cpaVal  = ov.cpa    != null ? '$' + fmt(ov.cpa)  : '—';
        var buyVal  = ov.purchases > 0  ? ov.purchases + ' compras' : '—';

        html += '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px;">';
        html += metaKpi('Gasto total',     '$' + fmt(ov.spend || 0),     '💸', false);
        html += metaKpi('ROAS',            roasVal,                       '📈', ov.roas != null && ov.roas >= 2);
        html += metaKpi('Compras (Meta)',  buyVal,                        '🛒', false);
        html += metaKpi('Costo por compra',cpaVal,                        '🎯', false);
        html += '</div>';

        // ── Métricas secundarias ──
        html += '<div style="display:flex;flex-wrap:wrap;gap:20px;margin-bottom:20px;font-size:12px;color:#6b7280;">';
        if (ov.impressions) html += '<span>👁 <strong>' + fmt(ov.impressions) + '</strong> impresiones</span>';
        if (ov.reach)       html += '<span>🌐 <strong>' + fmt(ov.reach) + '</strong> alcance</span>';
        if (ov.clicks)      html += '<span>🖱 <strong>' + fmt(ov.clicks) + '</strong> clics</span>';
        if (ov.ctr)         html += '<span>CTR: <strong>' + ov.ctr + '%</strong></span>';
        if (ov.cpm)         html += '<span>CPM: <strong>$' + ov.cpm + '</strong></span>';
        if (ov.cpc)         html += '<span>CPC: <strong>$' + ov.cpc + '</strong></span>';
        html += '</div>';

        // ── Tabla de campañas ──
        if (campaigns.length) {
            html += '<h3 style="font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;' +
                    'letter-spacing:.5px;margin:0 0 10px;">Campañas del período</h3>';
            html += '<div style="overflow-x:auto;">';
            html += '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
            html += '<thead><tr style="border-bottom:2px solid #f3f4f6;">' +
                '<th style="text-align:left;padding:4px 8px 6px 0;color:#9ca3af;font-weight:500;">Campaña</th>' +
                '<th style="text-align:right;padding:4px 8px 6px;color:#9ca3af;font-weight:500;">Gasto</th>' +
                '<th style="text-align:right;padding:4px 8px 6px;color:#9ca3af;font-weight:500;">CTR</th>' +
                '<th style="text-align:right;padding:4px 8px 6px;color:#9ca3af;font-weight:500;">Compras</th>' +
                '<th style="text-align:right;padding:4px 0 6px 8px;color:#9ca3af;font-weight:500;">ROAS</th>' +
                '</tr></thead><tbody>';

            $.each(campaigns, function (_, c) {
                var roasStr  = c.roas != null ? c.roas + 'x' : '—';
                var roasClr  = c.roas != null && c.roas >= 2 ? '#16a34a' : (c.roas != null ? '#d97706' : '#d1d5db');
                var buysStr  = c.purchases > 0 ? c.purchases : '—';
                var campName = c.name.length > 42 ? c.name.slice(0, 42) + '…' : c.name;

                html += '<tr style="border-bottom:1px solid #f9fafb;">' +
                    '<td style="padding:7px 8px 7px 0;color:#374151;" title="' + esc(c.name) + '">' + esc(campName) + '</td>' +
                    '<td style="text-align:right;padding:7px 8px;color:#374151;font-weight:500;">$' + fmt(c.spend) + '</td>' +
                    '<td style="text-align:right;padding:7px 8px;color:#374151;">' + c.ctr + '%</td>' +
                    '<td style="text-align:right;padding:7px 8px;color:#374151;">' + buysStr + '</td>' +
                    '<td style="text-align:right;padding:7px 0 7px 8px;color:' + roasClr + ';font-weight:600;">' + roasStr + '</td>' +
                    '</tr>';
            });

            html += '</tbody></table></div>';
        }

        $body.html(html);
        $('#pmpd-meta-badge').text('Meta Ads activo');
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Klaviyo — Email Marketing
     * ───────────────────────────────────────────────────────────────────────── */

    function renderKlaviyo(data) {
        var $body  = $('#pmpd-klaviyo-body');
        var $badge = $('#pmpd-klaviyo-badge');

        if (!data) { $body.html('<p style="color:#9ca3af;font-size:13px;padding:8px 0;">Sin datos disponibles.</p>'); return; }

        var ov        = data.overview  || {};
        var campaigns = data.campaigns || [];

        // Helper: tarjeta KPI estilo Klaviyo (verde esmeralda)
        function klavKpi(label, value, icon, highlighted) {
            var style = highlighted
                ? 'background:#ecfdf5;border:1px solid #a7f3d0;'
                : 'background:#f9fafb;border:1px solid #e5e7eb;';
            var valColor = highlighted ? '#065f46' : '#1f2937';
            return '<div style="' + style + 'border-radius:8px;padding:12px 16px;flex:1;min-width:130px;">' +
                '<div style="font-size:11px;color:#6b7280;margin-bottom:4px;">' + icon + ' ' + label + '</div>' +
                '<div style="font-size:22px;font-weight:700;color:' + valColor + ';">' + value + '</div>' +
                '</div>';
        }

        // ── KPIs principales ──────────────────────────────────────────────────
        var openRate  = ov.open_rate  != null ? ov.open_rate  + '%' : '—';
        var clickRate = ov.click_rate != null ? ov.click_rate + '%' : '—';
        var revenue   = ov.revenue    > 0     ? fmtCur(ov.revenue)  : '—';

        var openHighlight  = ov.open_rate  != null && ov.open_rate  >= 20;
        var clickHighlight = ov.click_rate != null && ov.click_rate >= 2;
        var revHighlight   = ov.revenue    > 0;

        var html = '<div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:16px;">';
        html += klavKpi('Emails enviados', fmt(ov.received || 0), '📨', false);
        html += klavKpi('Tasa apertura',   openRate,              '📭', openHighlight);
        html += klavKpi('Tasa de clic',    clickRate,             '🖱️', clickHighlight);
        html += klavKpi('Ingresos attr.',  revenue,               '💰', revHighlight);
        html += '</div>';

        // ── Desglose flows vs campañas ────────────────────────────────────────
        var hasFlowData     = ov.flow_revenue     > 0 || ov.flow_orders     > 0;
        var hasCampaignData = ov.campaign_revenue > 0 || ov.campaign_orders > 0;

        if (hasFlowData || hasCampaignData) {
            html += '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px 16px;margin-bottom:14px;">';
            html += '<div style="font-size:11px;color:#065f46;font-weight:700;margin-bottom:10px;text-transform:uppercase;letter-spacing:.3px;">💰 Ingresos atribuidos — Desglose</div>';
            html += '<div style="display:flex;gap:24px;flex-wrap:wrap;">';

            if (hasFlowData) {
                html += '<div>' +
                    '<div style="font-size:11px;color:#6b7280;margin-bottom:2px;">⚡ Flows (automatizaciones)</div>' +
                    '<div style="font-size:18px;font-weight:700;color:#065f46;">' + fmtCur(ov.flow_revenue) + '</div>' +
                    '<div style="font-size:11px;color:#9ca3af;">' + fmt(ov.flow_orders) + ' pedidos</div>' +
                    '</div>';
            }
            if (hasCampaignData) {
                html += '<div>' +
                    '<div style="font-size:11px;color:#6b7280;margin-bottom:2px;">📨 Campañas (envíos manuales)</div>' +
                    '<div style="font-size:18px;font-weight:700;color:#1e40af;">' + fmtCur(ov.campaign_revenue) + '</div>' +
                    '<div style="font-size:11px;color:#9ca3af;">' + fmt(ov.campaign_orders) + ' pedidos</div>' +
                    '</div>';
            }

            html += '</div></div>';
        } else if (ov.breakdown_error) {
            // El desglose falló — mostrar el error para diagnóstico
            html += '<div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#92400e;">';
            html += '⚠️ Desglose no disponible: ' + esc(ov.breakdown_error);
            html += '</div>';
        }

        // ── Métricas secundarias ──────────────────────────────────────────────
        var secItems = [];
        if (ov.opened       > 0) secItems.push('<b>' + fmt(ov.opened)       + '</b> abiertos');
        if (ov.clicked      > 0) secItems.push('<b>' + fmt(ov.clicked)      + '</b> clics');
        if (ov.orders       > 0) secItems.push('<b>' + fmt(ov.orders)       + '</b> pedidos attr.');
        if (ov.unsubscribed > 0) secItems.push('<b>' + fmt(ov.unsubscribed) + '</b> desuscripciones');

        if (secItems.length) {
            html += '<div style="font-size:12px;color:#6b7280;margin-bottom:14px;display:flex;flex-wrap:wrap;gap:16px;">';
            html += secItems.join('<span style="color:#d1d5db;">·</span>');
            html += '</div>';
        }

        // ── Últimas campañas ──────────────────────────────────────────────────
        if (campaigns.length) {
            html += '<div style="overflow-x:auto;">';
            html += '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
            html += '<thead><tr style="border-bottom:2px solid #e5e7eb;">' +
                '<th style="text-align:left;padding:6px 8px 6px 0;color:#6b7280;font-weight:600;font-size:11px;text-transform:uppercase;">Campaña</th>' +
                '<th style="text-align:right;padding:6px 0;color:#6b7280;font-weight:600;font-size:11px;text-transform:uppercase;width:100px;">Enviada</th>' +
                '</tr></thead><tbody>';

            $.each(campaigns, function (_, c) {
                var campName = c.name.length > 60 ? c.name.slice(0, 60) + '…' : c.name;
                html += '<tr style="border-bottom:1px solid #f9fafb;">' +
                    '<td style="padding:7px 8px 7px 0;color:#374151;" title="' + esc(c.name) + '">' + esc(campName) + '</td>' +
                    '<td style="text-align:right;padding:7px 0 7px 8px;color:#6b7280;white-space:nowrap;">' + esc(c.send_date) + '</td>' +
                    '</tr>';
            });

            html += '</tbody></table></div>';
        }

        $body.html(html);
        $badge.text('Klaviyo activo');
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * IA — Insights con Claude
     * ───────────────────────────────────────────────────────────────────────── */

    /**
     * Convierte texto Markdown básico a HTML seguro para el modal de IA.
     * Escapa el texto primero para evitar XSS, luego aplica formato.
     */
    function formatInsights(text) {
        // 1. Escape HTML entities
        var safe = text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');

        // 2. Markdown → HTML (sobre texto ya escapado)
        var html = safe
            // Headers ##
            .replace(/^#{1,3} (.+)$/gm,
                '<strong style="display:block;margin:18px 0 5px;color:#1a2e4f;font-size:13px;' +
                'text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid #e5e7eb;padding-bottom:4px;">$1</strong>')
            // Bold **texto**
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            // Italic *texto*
            .replace(/\*([^\*\n]+)\*/g, '<em>$1</em>')
            // Bullet list items: "- texto" o "• texto"
            .replace(/^[\-•] (.+)$/gm,
                '<div style="padding:3px 0 3px 18px;position:relative;">' +
                '<span style="position:absolute;left:0;color:#60a5fa;font-weight:700;">›</span>$1</div>')
            // Numbered list items: "1. texto"
            .replace(/^(\d+)\. (.+)$/gm,
                '<div style="padding:3px 0 3px 28px;position:relative;">' +
                '<span style="position:absolute;left:0;font-weight:700;color:#1a2e4f;min-width:22px;">$1.</span>$2</div>')
            // Párrafos dobles
            .replace(/\n\n/g, '<br>')
            // Saltos de línea simples
            .replace(/\n/g, '<br>');

        return '<div style="line-height:1.72;font-size:13px;color:#374151;">' + html + '</div>';
    }

    function initAIInsights() {
        var BTN_LABEL = '🤖 Analizador Pymes Modernas';
        var $btn      = $('#pmpd-ai-insights');

        if (PMPD.ai_key_set !== '1') {
            $btn.attr('title', '⚠️ Configura tu API Key de Claude en Configuración.');
        }

        /* ── Helpers del modal ── */
        function openModal() { $('#pmpd-ai-modal').show(); }
        function closeModal() {
            $('#pmpd-ai-modal').hide();
            aiMessages = [];
            $('#pmpd-ai-modal-body').empty();
            $('#pmpd-ai-chat-area').hide();
            $('#pmpd-ai-modal-footer').hide();
            $('#pmpd-ai-wa-copy').hide().removeData('wa-text');
            $('#pmpd-ai-chat-msg').val('');
        }

        /**
         * Añade un bubble al hilo de chat y hace scroll al final.
         * role: 'user' | 'assistant' | 'error'
         */
        function appendBubble(role, text) {
            var $thread = $('#pmpd-ai-modal-body');
            var $bubble = $('<div>').addClass('pmpd-chat-bubble pmpd-chat-' + role);

            if (role === 'user') {
                $bubble.html('<div class="pmpd-chat-user-text">' + esc(text) + '</div>');
            } else if (role === 'error') {
                $bubble.html(
                    '<div class="pmpd-chat-assistant-text">' +
                    '<div class="notice notice-error" style="margin:0;"><p>❌ ' + esc(text) + '</p></div>' +
                    '</div>'
                );
            } else {
                $bubble.addClass('pmpd-chat-assistant')
                       .html('<div class="pmpd-chat-assistant-text">' + formatInsights(text) + '</div>');
            }

            $thread.append($bubble);
            $thread.scrollTop($thread[0].scrollHeight);
        }

        function showTyping() {
            var $t = $('<div class="pmpd-chat-bubble pmpd-chat-assistant" id="pmpd-typing">' +
                '<div class="pmpd-chat-typing"><span></span><span></span><span></span></div></div>');
            $('#pmpd-ai-modal-body').append($t);
            $('#pmpd-ai-modal-body').scrollTop($('#pmpd-ai-modal-body')[0].scrollHeight);
        }
        function hideTyping() { $('#pmpd-typing').remove(); }

        /* ── Botón principal: genera el análisis inicial ── */
        $(document).on('click', '#pmpd-ai-insights', function () {
            if (!lastStats) {
                alert('Espera a que el dashboard termine de cargarse.');
                return;
            }
            if (PMPD.ai_key_set !== '1') {
                $('#pmpd-ai-modal-body').html(
                    '<div style="padding:20px;">' +
                    '<p>⚠️ <strong>API Key de Claude no configurada.</strong></p>' +
                    '<p>Ve a <a href="' + esc(PMPD.settings_url) + '">Pymes Modernas → Configuración</a> ' +
                    'y agrega tu key de <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener">console.anthropic.com</a>.</p>' +
                    '<p style="color:#6b7280;font-size:12px;">El registro es gratuito. El costo por análisis es mínimo.</p>' +
                    '</div>'
                );
                $('#pmpd-ai-chat-area').hide();
                $('#pmpd-ai-modal-footer').hide();
                openModal();
                return;
            }

            // Spinner inicial
            aiMessages = [];
            $('#pmpd-ai-modal-body').html(
                '<div style="text-align:center;padding:40px 20px;">' +
                '<span class="spinner is-active" style="float:none;display:inline-block;width:30px;height:30px;"></span>' +
                '<p style="margin-top:16px;color:#6b7280;font-size:13px;">' +
                'Claude está analizando tu tienda…<br>' +
                '<small style="color:#9ca3af;">Puede tardar hasta 30 segundos.</small></p>' +
                '</div>'
            );
            $('#pmpd-ai-chat-area').hide();
            $('#pmpd-ai-modal-footer').hide();
            openModal();
            $btn.prop('disabled', true).text('⏳ Analizando…');

            var payload = {
                date_from:     lastStats.date_from     || '',
                date_to:       lastStats.date_to       || '',
                revenue:       lastStats.revenue       || {},
                top_products:  lastStats.top_products  || [],
                top_customers: lastStats.top_customers || [],
                orders_status: lastStats.orders_status || [],
                dormant:       lastDormant !== null ? lastDormant : undefined,
            };

            $.ajax({
                url:     AJAX,
                type:    'POST',
                timeout: 95000,
                data: {
                    action: 'pmp_dashboard_ai_insights',
                    nonce:  NONCE,
                    data:   JSON.stringify(payload),
                },
                success: function (r) {
                    $btn.prop('disabled', false).text(BTN_LABEL);
                    if (r && r.success && r.data && r.data.insights) {
                        var insights      = r.data.insights;
                        var initialPrompt = r.data.initial_prompt || '';
                        var dateRange     = (lastStats.date_from || '') + ' al ' + (lastStats.date_to || '');

                        // ── Extraer sección de WhatsApp (no mostrarla en el chat) ──
                        var waText = '';
                        var waMatch = insights.match(/##\s*Resumen para WhatsApp\s*\n+([\s\S]+?)$/i);
                        if (waMatch) {
                            waText   = waMatch[1].trim();
                            insights = insights.replace(/\n*##\s*Resumen para WhatsApp\s*\n+[\s\S]+$/i, '').trim();
                        }

                        // Inicializar historial con el intercambio completo
                        aiMessages = [
                            { role: 'user',      content: initialPrompt },
                            { role: 'assistant', content: insights       },
                        ];

                        // Renderizar el análisis como primer bubble (sin la sección WA)
                        $('#pmpd-ai-modal-body').empty();
                        appendBubble('assistant', insights);

                        // Mostrar botón WhatsApp si se extrajo el mensaje
                        if (waText) {
                            $('#pmpd-ai-wa-copy').data('wa-text', waText).show();
                        } else {
                            $('#pmpd-ai-wa-copy').hide();
                        }

                        // Mostrar input de chat y footer
                        $('#pmpd-ai-chat-area').show();
                        $('#pmpd-ai-modal-footer').data('range', dateRange).show();

                    } else {
                        var msg = (r && r.data && r.data.message) ? r.data.message : 'Error al contactar la IA.';
                        $('#pmpd-ai-modal-body').html(
                            '<div style="padding:16px;"><div class="notice notice-error" style="margin:0;">' +
                            '<p>❌ ' + esc(msg) + '</p></div></div>'
                        );
                    }
                },
                error: function (xhr, status) {
                    $btn.prop('disabled', false).text(BTN_LABEL);
                    var msg = status === 'timeout'
                        ? 'La solicitud tardó demasiado. Intenta de nuevo.'
                        : 'Error de red. Verifica tu conexión e intenta de nuevo.';
                    $('#pmpd-ai-modal-body').html(
                        '<div style="padding:16px;"><div class="notice notice-error" style="margin:0;">' +
                        '<p>❌ ' + esc(msg) + '</p></div></div>'
                    );
                },
            });
        });

        /* ── Chat: enviar mensaje de seguimiento ── */
        function sendChat() {
            var userMsg  = $('#pmpd-ai-chat-msg').val().trim();
            var $sendBtn = $('#pmpd-ai-chat-send');
            if (!userMsg || aiMessages.length === 0) return;

            appendBubble('user', userMsg);
            $('#pmpd-ai-chat-msg').val('');
            $sendBtn.prop('disabled', true).text('…');
            showTyping();

            $.ajax({
                url:     AJAX,
                type:    'POST',
                timeout: 60000,
                data: {
                    action:   'pmp_dashboard_ai_chat',
                    nonce:    NONCE,
                    messages: JSON.stringify(aiMessages),
                    message:  userMsg,
                },
                success: function (r) {
                    hideTyping();
                    $sendBtn.prop('disabled', false).text('Enviar →');
                    if (r && r.success && r.data && r.data.reply) {
                        var reply = r.data.reply;
                        aiMessages.push({ role: 'user',      content: userMsg });
                        aiMessages.push({ role: 'assistant', content: reply   });
                        appendBubble('assistant', reply);
                    } else {
                        var msg = (r && r.data && r.data.message) ? r.data.message : 'Error en la respuesta.';
                        appendBubble('error', msg);
                    }
                },
                error: function () {
                    hideTyping();
                    $sendBtn.prop('disabled', false).text('Enviar →');
                    appendBubble('error', 'Error de red. Intenta de nuevo.');
                },
            });
        }

        $(document).on('click', '#pmpd-ai-chat-send', sendChat);
        $(document).on('keydown', '#pmpd-ai-chat-msg', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendChat(); }
        });

        /* ── Exportar conversación ── */
        function buildExport() {
            var range = $('#pmpd-ai-modal-footer').data('range') || '';
            var lines = ['Analizador Pymes Modernas — Conversación con Claude', 'Período: ' + range, ''];
            // Saltamos el primer mensaje (el prompt con datos crudos)
            for (var i = 1; i < aiMessages.length; i++) {
                var m = aiMessages[i];
                lines.push(m.role === 'assistant' ? '── Claude ──' : '── Equipo PM ──');
                lines.push(m.content);
                lines.push('');
            }
            return lines.join('\n');
        }

        /* ── Copiar mensaje de WhatsApp ── */
        $(document).on('click', '#pmpd-ai-wa-copy', function () {
            var $btn = $(this);
            var text = $btn.data('wa-text') || '';
            if (!text) return;
            function onCopied() {
                $btn.text('✓ ¡Copiado!');
                setTimeout(function () { $btn.text('📱 WhatsApp'); }, 2500);
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(onCopied).catch(function () { fallbackCopy(text, onCopied); });
            } else {
                fallbackCopy(text, onCopied);
            }
        });

        $(document).on('click', '#pmpd-ai-copy', function () {
            var $copyBtn = $(this);
            var full     = buildExport();
            function onCopied() {
                $copyBtn.text('✓ Copiado');
                setTimeout(function () { $copyBtn.text('📋 Copiar'); }, 2200);
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(full).then(onCopied).catch(function () { fallbackCopy(full, onCopied); });
            } else {
                fallbackCopy(full, onCopied);
            }
        });

        $(document).on('click', '#pmpd-ai-download', function () {
            var full  = buildExport();
            var fname = 'analisis-pm-' + (lastStats ? lastStats.date_from : 'export') + '.txt';
            var blob  = new Blob([full], { type: 'text/plain;charset=utf-8' });
            var url   = URL.createObjectURL(blob);
            var $a    = $('<a>').attr({ href: url, download: fname }).appendTo('body');
            $a[0].click();
            $a.remove();
            URL.revokeObjectURL(url);
        });

        /* ── Cerrar modal ── */
        $(document).on('click', '#pmpd-ai-modal', function (e) {
            if ($(e.target).is('#pmpd-ai-modal') || $(e.target).closest('.pmpd-modal-close').length) {
                closeModal();
            }
        });
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && $('#pmpd-ai-modal').is(':visible')) { closeModal(); }
        });
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Ideas de contenido con IA
     * ───────────────────────────────────────────────────────────────────────── */

    function loadContentIdeas(done) {
        $('#pmpd-content-body').html(
            '<div style="text-align:center;padding:20px;color:#9ca3af;font-size:13px;">' +
            '<span class="spinner is-active" style="float:none;display:inline-block;"></span>' +
            '<span style="display:block;margin-top:8px;">Analizando tus datos…</span></div>'
        );
        $.post(AJAX, { action: 'pmp_content_ideas', nonce: NONCE }, function (r) {
            if (r && r.success) {
                renderContentIdeas(r.data);
            } else {
                var msg = r && r.data && r.data.message ? r.data.message : 'No hay sugerencias disponibles.';
                $('#pmpd-content-body').html(
                    '<p style="color:#9ca3af;font-size:13px;padding:4px 0;">' + esc(msg) + '</p>'
                );
            }
            if (typeof done === 'function') done();
        }).fail(function () {
            $('#pmpd-content-body').html(
                '<p style="color:#ef4444;font-size:13px;">⚠️ Error al cargar ideas de contenido.</p>'
            );
            if (typeof done === 'function') done();
        });
    }

    function renderIdeaCard(idea, aiReady, isSocial) {
        var mainBtn;
        if (aiReady) {
            if (isSocial) {
                mainBtn =
                    '<button class="button button-primary pmpd-gen-social-btn" ' +
                    'data-keyword="'  + esc(idea.keyword)  + '" ' +
                    'data-type="'     + esc(idea.type)     + '" ' +
                    'data-platform="' + esc(idea.platform || '') + '" ' +
                    'style="flex:1;font-size:12px;">📱 Generar contenido</button>';
            } else {
                mainBtn =
                    '<button class="button button-primary pmpd-gen-post-btn" ' +
                    'data-keyword="' + esc(idea.keyword) + '" ' +
                    'data-type="'    + esc(idea.type)    + '" ' +
                    'data-title="'   + esc(idea.title)   + '" ' +
                    'style="flex:1;font-size:12px;">✍️ Generar borrador</button>';
            }
        } else {
            mainBtn =
                '<span style="flex:1;font-size:11px;color:#9ca3af;align-self:center;">' +
                '⚙ <a href="' + esc(PMPD.settings_url) + '">Configura tu API Key</a> para generar.</span>';
        }

        var dismissBtn =
            '<button class="button pmpd-dismiss-btn" ' +
            'data-key="' + esc(idea.key || '') + '" ' +
            'title="Marcar como hecho — no volverá a aparecer" ' +
            'style="flex-shrink:0;font-size:11px;color:#6b7280;">✓ Hecho</button>';

        return (
            '<div class="pmpd-content-idea-card" style="' +
            'background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:16px;' +
            'display:flex;flex-direction:column;">' +
            '<div style="display:flex;align-items:center;gap:6px;margin-bottom:8px;">' +
                '<span style="font-size:16px;">' + esc(idea.icon) + '</span>' +
                '<span style="font-size:10px;font-weight:600;color:#60a5fa;text-transform:uppercase;letter-spacing:.4px;">' + esc(idea.tag) + '</span>' +
            '</div>' +
            '<div style="font-size:13px;font-weight:600;color:#1a2e4f;line-height:1.4;margin-bottom:6px;">' + esc(idea.title) + '</div>' +
            '<div style="font-size:12px;color:#6b7280;line-height:1.5;flex:1;">' + esc(idea.reason) + '</div>' +
            '<div class="pmpd-gen-result"></div>' +
            '<div style="display:flex;gap:6px;margin-top:12px;align-items:stretch;">' +
                mainBtn +
                dismissBtn +
            '</div>' +
            '</div>'
        );
    }

    function renderContentIdeas(data) {
        var ideas          = data.ideas          || [];
        var socialIdeas    = data.social_ideas   || [];
        var aiReady        = data.ai_ready       || false;
        var dismissedCount = data.dismissed_count || 0;
        var $body          = $('#pmpd-content-body');
        var totalIdeas     = ideas.length + socialIdeas.length;

        // Mostrar u ocultar botón Restablecer según descartadas
        if (dismissedCount > 0) {
            $('#pmpd-content-reset').show().text('↩ Restablecer (' + dismissedCount + ')');
        } else {
            $('#pmpd-content-reset').hide();
        }

        if (!totalIdeas) {
            var emptyMsg = dismissedCount > 0
                ? 'Todas las ideas han sido marcadas como hechas. Haz clic en "↩ Restablecer" para verlas de nuevo.'
                : 'Sin suficientes datos para sugerir ideas. Conecta Google Search Console o espera a que haya más ventas en el período.';
            $body.html('<p style="color:#9ca3af;font-size:13px;padding:4px 0;">' + esc(emptyMsg) + '</p>');
            return;
        }

        var html = '';

        if (ideas.length) {
            html += '<div style="margin-bottom:18px;">';
            html += '<div style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;' +
                    'letter-spacing:.6px;margin-bottom:12px;">📝 Blog</div>';
            html += '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:14px;">';
            $.each(ideas, function (_, idea) {
                html += renderIdeaCard(idea, aiReady, false);
            });
            html += '</div></div>';
        }

        if (socialIdeas.length) {
            html += '<div>';
            html += '<div style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;' +
                    'letter-spacing:.6px;margin-bottom:12px;">📱 Redes Sociales</div>';
            html += '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:14px;">';
            $.each(socialIdeas, function (_, idea) {
                html += renderIdeaCard(idea, aiReady, true);
            });
            html += '</div></div>';
        }

        $body.html(html);
        $('#pmpd-content-badge').text(totalIdeas + (totalIdeas === 1 ? ' idea' : ' ideas'));
    }

    function initContentIdeas() {
        // Blog post generation
        $(document).on('click', '.pmpd-gen-post-btn', function () {
            var $btn    = $(this);
            var $card   = $btn.closest('.pmpd-content-idea-card');
            var $result = $card.find('.pmpd-gen-result');
            var keyword = $btn.data('keyword');
            var type    = $btn.data('type');
            var title   = $btn.data('title');

            $btn.prop('disabled', true).text('Generando…');
            $result.html(
                '<p style="font-size:12px;color:#6b7280;margin:8px 0 0;">' +
                '⏳ Claude está redactando el borrador — puede tardar hasta 30 segundos…</p>'
            );

            $.ajax({
                url:     AJAX,
                method:  'POST',
                timeout: 100000,
                data: {
                    action:  'pmp_generate_post',
                    nonce:   NONCE,
                    keyword: keyword,
                    type:    type,
                    title:   title,
                },
                success: function (r) {
                    if (r && r.success) {
                        $result.html(
                            '<div style="margin-top:10px;padding:10px 12px;background:#f0fdf4;' +
                            'border:1px solid #bbf7d0;border-radius:8px;">' +
                            '<div style="font-size:12px;font-weight:600;color:#15803d;margin-bottom:4px;">✅ Borrador creado</div>' +
                            '<div style="font-size:12px;color:#374151;margin-bottom:8px;">' + esc(r.data.title) + '</div>' +
                            '<a href="' + r.data.edit_url + '" class="button button-primary" ' +
                            'style="font-size:12px;" target="_blank" rel="noopener">✏️ Abrir editor →</a>' +
                            '</div>'
                        );
                        $btn.hide();
                    } else {
                        var msg = r && r.data && r.data.message ? r.data.message : 'Error al generar el post.';
                        $result.html('<p style="color:#ef4444;font-size:12px;margin-top:6px;">❌ ' + esc(msg) + '</p>');
                        $btn.prop('disabled', false).text('✍️ Generar borrador');
                    }
                },
                error: function () {
                    $result.html('<p style="color:#ef4444;font-size:12px;margin-top:6px;">❌ Error de conexión.</p>');
                    $btn.prop('disabled', false).text('✍️ Generar borrador');
                },
            });
        });

        // Social content generation
        $(document).on('click', '.pmpd-gen-social-btn', function () {
            var $btn      = $(this);
            var $card     = $btn.closest('.pmpd-content-idea-card');
            var $result   = $card.find('.pmpd-gen-result');
            var keyword   = $btn.data('keyword');
            var type      = $btn.data('type');
            var platform  = $btn.data('platform');

            $btn.prop('disabled', true).text('Generando…');
            $result.html(
                '<p style="font-size:12px;color:#6b7280;margin:8px 0 0;">' +
                '⏳ Claude está creando el contenido…</p>'
            );

            $.ajax({
                url:     AJAX,
                method:  'POST',
                timeout: 90000,
                data: {
                    action:   'pmp_generate_social',
                    nonce:    NONCE,
                    keyword:  keyword,
                    type:     type,
                    platform: platform,
                },
                success: function (r) {
                    if (r && r.success) {
                        var content = r.data.content || '';
                        var uid     = 'pmpd-social-copy-' + Math.random().toString(36).slice(2);
                        $result.html(
                            '<div style="margin-top:10px;">' +
                            '<div style="font-size:12px;font-weight:600;color:#15803d;margin-bottom:6px;">✅ Contenido generado</div>' +
                            '<textarea id="' + uid + '" readonly ' +
                            'style="width:100%;height:180px;font-size:11px;line-height:1.5;' +
                            'border:1px solid #d1d5db;border-radius:6px;padding:8px;' +
                            'background:#fff;color:#374151;resize:vertical;">' +
                            esc(content) + '</textarea>' +
                            '<button class="button pmpd-copy-social-btn" data-target="' + uid + '" ' +
                            'style="margin-top:6px;font-size:12px;">📋 Copiar todo</button>' +
                            '</div>'
                        );
                        $btn.hide();
                    } else {
                        var msg = r && r.data && r.data.message ? r.data.message : 'Error al generar contenido.';
                        $result.html('<p style="color:#ef4444;font-size:12px;margin-top:6px;">❌ ' + esc(msg) + '</p>');
                        $btn.prop('disabled', false).text('📱 Generar contenido');
                    }
                },
                error: function () {
                    $result.html('<p style="color:#ef4444;font-size:12px;margin-top:6px;">❌ Error de conexión.</p>');
                    $btn.prop('disabled', false).text('📱 Generar contenido');
                },
            });
        });

        // Copy social content button
        $(document).on('click', '.pmpd-copy-social-btn', function () {
            var $btn    = $(this);
            var target  = $btn.data('target');
            var text    = $('#' + target).val();
            var origTxt = $btn.text();

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(function () {
                    $btn.text('✅ Copiado');
                    setTimeout(function () { $btn.text(origTxt); }, 2000);
                });
            } else {
                fallbackCopy(text, function () {
                    $btn.text('✅ Copiado');
                    setTimeout(function () { $btn.text(origTxt); }, 2000);
                });
            }
        });

        // Marcar idea como hecha
        $(document).on('click', '.pmpd-dismiss-btn', function () {
            var $btn  = $(this);
            var key   = $btn.data('key');
            var $card = $btn.closest('.pmpd-content-idea-card');

            $btn.prop('disabled', true).text('…');

            $.post(AJAX, { action: 'pmp_dismiss_idea', nonce: NONCE, key: key }, function (r) {
                if (r && r.success) {
                    $card.css({ transition: 'opacity .3s, transform .3s', opacity: 0, transform: 'scale(.95)' });
                    setTimeout(function () {
                        $card.remove();
                        // Actualizar badge y mostrar Restablecer
                        var total = $('.pmpd-content-idea-card').length;
                        $('#pmpd-content-badge').text(total + (total === 1 ? ' idea' : ' ideas'));
                        var cur = parseInt($('#pmpd-content-reset').text().match(/\d+/) || [0], 10) + 1;
                        $('#pmpd-content-reset').show().text('↩ Restablecer (' + cur + ')');
                        // Si no quedan tarjetas, mostrar mensaje
                        if (!total) {
                            $('#pmpd-content-body').html(
                                '<p style="color:#9ca3af;font-size:13px;padding:4px 0;">' +
                                'Todas las ideas han sido marcadas como hechas. Haz clic en "↩ Restablecer" para verlas de nuevo.</p>'
                            );
                        }
                    }, 320);
                } else {
                    $btn.prop('disabled', false).text('✓ Hecho');
                }
            }).fail(function () {
                $btn.prop('disabled', false).text('✓ Hecho');
            });
        });

        // Regenerar ideas
        $('#pmpd-content-reload').on('click', function () {
            var $btn = $(this);
            $btn.prop('disabled', true).text('…');
            loadContentIdeas(function () { $btn.prop('disabled', false).text('🔄 Regenerar'); });
        });

        // Restablecer ideas descartadas
        $('#pmpd-content-reset').on('click', function () {
            var $btn = $(this);
            $btn.prop('disabled', true).text('…');
            $.post(AJAX, { action: 'pmp_reset_ideas', nonce: NONCE }, function () {
                $btn.prop('disabled', false);
                loadContentIdeas(function () { $btn.prop('disabled', false); });
            });
        });
    }

    /** Fallback de copiado para navegadores sin clipboard API */
    function fallbackCopy(text, callback) {
        var $tmp = $('<textarea>')
            .val(text)
            .css({ position: 'fixed', top: 0, left: 0, opacity: 0 })
            .appendTo('body');
        $tmp[0].select();
        try { document.execCommand('copy'); if (callback) callback(); } catch (e) { /* silent */ }
        $tmp.remove();
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Filtros
     * ───────────────────────────────────────────────────────────────────────── */

    function initFilters() {
        // Botones de rango predefinido
        $(document).on('click', '.pmpd-range', function () {
            var $btn = $(this);
            state.range = $btn.data('range');
            state.from  = '';
            state.to    = '';
            $('.pmpd-range').removeClass('active');
            $btn.addClass('active');
            loadDashboard();
        });

        // Rango personalizado
        $(document).on('click', '#pmpd-apply-custom', function () {
            var from = $('#pmpd-from').val();
            var to   = $('#pmpd-to').val();
            if (!from || !to) return;
            state.range = 'custom';
            state.from  = from;
            state.to    = to;
            $('.pmpd-range').removeClass('active');
            loadDashboard();
        });

        // Filtro de estado — dropdown con checkboxes
        var STATUS_LABELS = {
            'wc-completed': 'Completado', 'wc-processing': 'Procesando',
            'wc-pending': 'Pendiente',    'wc-on-hold': 'En espera',
            'wc-cancelled': 'Cancelado',  'wc-refunded': 'Reembolsado',
        };

        function updateStatusLabel() {
            var sel = state.statuses;
            var lbl = sel.length === 0
                ? 'Todos los estados'
                : sel.length === 1
                    ? (STATUS_LABELS[sel[0]] || sel[0])
                    : sel.length + ' estados';
            $('#pmpd-status-label').text(lbl);
        }

        $(document).on('click', '#pmpd-status-btn', function (e) {
            e.stopPropagation();
            $('#pmpd-status-menu').toggleClass('pmpd-status-open');
        });

        $(document).on('click', function (e) {
            if (!$(e.target).closest('#pmpd-status-dd').length) {
                $('#pmpd-status-menu').removeClass('pmpd-status-open');
            }
        });

        $(document).on('change', '#pmpd-status-all', function () {
            if ($(this).prop('checked')) {
                $('.pmpd-scb').prop('checked', false);
                state.statuses = [];
                updateStatusLabel();
                loadDashboard();
            }
        });

        $(document).on('change', '.pmpd-scb', function () {
            state.statuses = $('.pmpd-scb:checked').map(function () { return this.value; }).get();
            var allChecked = state.statuses.length === 0;
            $('#pmpd-status-all').prop('checked', allChecked);
            updateStatusLabel();
            loadDashboard();
        });
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Modal de ticket
     * ───────────────────────────────────────────────────────────────────────── */

    function initTicketModal() {
        // Abrir
        $(document).on('click', '#pmpd-open-ticket', function () {
            $('#pmpd-ticket-modal').show();
            loadTicketTypes();
        });

        // Cerrar: clic en overlay o botón ×
        $(document).on('click', '#pmpd-ticket-modal', function (e) {
            if ($(e.target).is('#pmpd-ticket-modal') || $(e.target).is('.pmpd-modal-close')) {
                closeModal();
            }
        });

        // Cerrar: tecla ESC
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && $('#pmpd-ticket-modal').is(':visible')) {
                closeModal();
            }
        });

        // Envío del formulario
        $(document).on('submit', '#pmpd-ticket-form', submitTicket);
    }

    function closeModal() {
        $('#pmpd-ticket-modal').hide();
        $('#pmpd-ticket-msg').hide().text('').removeClass('pmpd-msg-ok pmpd-msg-err');
        var form = document.getElementById('pmpd-ticket-form');
        if (form) form.reset();
    }

    function loadTicketTypes() {
        if ($('#pmpd-ticket-type option').length > 1) return;
        $.post(AJAX, { action: 'pmp_get_ticket_types', nonce: PNONCE }, function (r) {
            if (!r || !r.success) return;
            var $sel = $('#pmpd-ticket-type');
            $.each(r.data, function (_, t) {
                $sel.append('<option value="' + esc(t) + '">' + esc(t) + '</option>');
            });
        });
    }

    function submitTicket(e) {
        e.preventDefault();
        var $form  = $(this);
        var $btn   = $('#pmpd-ticket-submit');
        var $msg   = $('#pmpd-ticket-msg');
        var title  = $form.find('[name=title]').val().trim();

        if (!title) {
            $msg.removeClass('pmpd-msg-ok').addClass('pmpd-msg-err').text('El título es requerido.').show();
            return;
        }

        $btn.prop('disabled', true).text('Enviando…');
        $msg.hide();

        $.post(AJAX, {
            action:       'pmp_create_ticket',
            nonce:        PNONCE,
            title:        title,
            priority:     $form.find('[name=priority]').val(),
            ticket_type:  $form.find('[name=ticket_type]').val(),
            client_notes: $form.find('[name=client_notes]').val(),
        }, function (r) {
            $btn.prop('disabled', false).text('Enviar solicitud');
            if (r && r.success) {
                $msg.removeClass('pmpd-msg-err').addClass('pmpd-msg-ok')
                    .text('✓ Solicitud #' + r.data.ticket_id + ' creada. Te contactaremos pronto.')
                    .show();
                $form[0].reset();
            } else {
                var errMsg = (r && r.data && r.data.message) ? r.data.message : 'Error al enviar.';
                $msg.removeClass('pmpd-msg-ok').addClass('pmpd-msg-err').text('✕ ' + errMsg).show();
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('Enviar solicitud');
            $msg.removeClass('pmpd-msg-ok').addClass('pmpd-msg-err').text('✕ Error de red. Intenta de nuevo.').show();
        });
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Bootstrap
     * ───────────────────────────────────────────────────────────────────────── */

    $(function () {
        if ($('#pmpd-dashboard').length === 0) return;

        // Valores iniciales para el rango personalizado
        var today   = new Date().toISOString().slice(0, 10);
        var minus30 = new Date(Date.now() - 30 * 86400000).toISOString().slice(0, 10);
        $('#pmpd-from').val(minus30);
        $('#pmpd-to').val(today);

        initFilters();
        initTicketModal();
        initAIInsights();
        initContentIdeas();
        loadDashboard();
        loadDormant();
        loadContentIdeas();
    });

}(jQuery));
