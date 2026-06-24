/**
 * pm-portal.js — Lógica del Portal Cliente (pm-client-portal)
 * Vanilla jQuery: tabs, AJAX proxy, SVG ring, SVG bar chart, formularios.
 */
(function ($) {
    'use strict';

    /* ──────────────────────────────────────────────────────────────────────────
     * Constantes e inicialización
     * ────────────────────────────────────────────────────────────────────────── */

    var AJAX_URL   = PMP.ajax_url;
    var NONCE      = PMP.nonce;
    var CREDITS_URL = PMP.credits_url;
    var WOO_ACTIVE  = PMP.woo_active === '1';
    var CURRENCY    = PMP.currency || '$';

    // Seguimiento de lo que ya se cargó (para no repetir peticiones)
    var loaded = { credits: false, tickets: false, news: false, woo: false, history: false };

    /* ──────────────────────────────────────────────────────────────────────────
     * Helper AJAX
     * ────────────────────────────────────────────────────────────────────────── */

    function ajax(action, data, onSuccess, onError) {
        data.action = action;
        data.nonce  = NONCE;
        $.post(AJAX_URL, data, function (r) {
            if (r.success) {
                if (onSuccess) onSuccess(r.data);
            } else {
                var msg = (r.data && r.data.message) ? r.data.message : 'Error de comunicación con el servidor.';
                if (onError) onError(msg);
                else         console.error('[PMP] ' + action + ':', msg);
            }
        }).fail(function () {
            var msg = 'Error de red. Intenta de nuevo.';
            if (onError) onError(msg);
        });
    }

    function fmtNum(n) { return parseFloat(n).toLocaleString('es-CR', { maximumFractionDigits: 2 }); }
    function fmtCurrency(n) { return CURRENCY + fmtNum(n); }
    function fmtCredits(n) {
        n = parseFloat(n);
        return (n === Math.floor(n)) ? Math.floor(n).toString() : n.toFixed(1);
    }

    /* ──────────────────────────────────────────────────────────────────────────
     * Tabs
     * ────────────────────────────────────────────────────────────────────────── */

    function initTabs() {
        $(document).on('click', '.pm-portal-tab', function () {
            var $tab  = $(this);
            var panel = $tab.data('tab');

            $('.pm-portal-tab').removeClass('active').attr('aria-selected', 'false');
            $tab.addClass('active').attr('aria-selected', 'true');

            $('.pm-portal-panel').removeClass('active');
            $('[data-panel="' + panel + '"]').addClass('active');

            // Lazy load del panel
            if (!loaded[panel]) {
                loadPanel(panel);
            }
        });
    }

    function loadPanel(panel) {
        switch (panel) {
            case 'credits': loadCredits(); break;
            case 'tickets': loadTickets(); break;
            case 'news':    loadNews();    break;
            case 'woo':     loadWoo('30'); break;
            case 'history': loadHistory(); break;
        }
    }

    /* ──────────────────────────────────────────────────────────────────────────
     * SVG Ring
     * ────────────────────────────────────────────────────────────────────────── */

    function renderRing(pct, remaining, total) {
        var r     = 47;
        var circ  = 2 * Math.PI * r;
        var dash  = Math.round(pct / 100 * circ * 100) / 100;
        var color = pct >= 90 ? '#dc2626' : (pct >= 70 ? '#d97706' : '#1a2e4f');

        $('#pm-ring-progress')
            .attr('stroke-dasharray', dash + ' ' + Math.round(circ * 100) / 100)
            .attr('stroke', color);

        $('#pm-ring-value').text(fmtCredits(remaining));
        $('#pm-ring-caption').text('de ' + fmtCredits(total) + ' créditos disponibles').css('color', color);

        var caption = pct >= 90 ? '⚠️ Créditos casi agotados' :
                      pct >= 70 ? 'Más de la mitad utilizado' :
                                  'Tienes créditos disponibles';
        $('#pm-ring-caption').text(caption).css('color', color);
    }

    /* ──────────────────────────────────────────────────────────────────────────
     * SVG Bar Chart — créditos por mes
     * ────────────────────────────────────────────────────────────────────────── */

    function renderCreditsChart(history) {
        var $wrap  = $('#pm-credits-chart');
        var maxVal = 0;
        $.each(history, function (_, d) { if (parseFloat(d.used_credits) > maxVal) maxVal = parseFloat(d.used_credits); });
        if (maxVal === 0) { $wrap.html('<p style="color:#9ca3af;font-size:13px;">Sin datos.</p>'); return; }

        var bw = 30, gap = 10, padL = 40, padB = 36, padT = 16, H = 140;
        var n  = history.length;
        var W  = padL + n * (bw + gap) + 20;
        var barH = H - padT - padB;

        var svg = '<svg class="pm-bar-chart-svg" width="' + W + '" height="' + H + '" viewBox="0 0 ' + W + ' ' + H + '" xmlns="http://www.w3.org/2000/svg">';

        // Líneas de guía
        for (var g = 0; g <= 4; g++) {
            var gy = padT + barH - (g / 4 * barH);
            svg += '<line x1="' + padL + '" y1="' + gy + '" x2="' + W + '" y2="' + gy + '" stroke="#dde4ee" stroke-width="1"/>';
            if (g > 0) {
                svg += '<text x="' + (padL - 5) + '" y="' + (gy + 4) + '" text-anchor="end" font-size="9" fill="#9ca3af">' +
                       Math.round(maxVal * g / 4 * 10) / 10 + '</text>';
            }
        }

        // Barras
        $.each(history, function (i, d) {
            var x    = padL + i * (bw + gap);
            var val  = parseFloat(d.used_credits);
            var bH   = maxVal > 0 ? Math.round(val / maxVal * barH) : 0;
            var y    = padT + barH - bH;
            var isLast = i === history.length - 1;
            var fill   = isLast ? '#1a2e4f' : '#3d5a8a';

            svg += '<rect x="' + x + '" y="' + y + '" width="' + bw + '" height="' + bH + '" fill="' + fill + '" rx="3"/>';
            svg += '<text x="' + (x + bw / 2) + '" y="' + (y - 4) + '" text-anchor="middle" font-size="8" fill="#6b7280">' +
                   (val > 0 ? val.toFixed(1) : '') + '</text>';
            svg += '<text x="' + (x + bw / 2) + '" y="' + (padT + barH + 14) + '" text-anchor="middle" font-size="9" fill="#6b7280">' +
                   d.month_label + '</text>';
        });

        svg += '</svg>';
        $wrap.html(svg);
    }

    /* ──────────────────────────────────────────────────────────────────────────
     * SVG Bar Chart — ventas por día
     * ────────────────────────────────────────────────────────────────────────── */

    function renderDailyChart(dailySales) {
        var $wrap  = $('#pm-woo-chart');
        var maxVal = 0;
        $.each(dailySales, function (_, d) { if (parseFloat(d.revenue) > maxVal) maxVal = parseFloat(d.revenue); });
        if (maxVal === 0) { $wrap.html('<p style="color:#9ca3af;font-size:13px;">Sin ventas en el período.</p>'); return; }

        var bw = 24, gap = 6, padL = 50, padB = 36, padT = 16, H = 160;
        var n  = dailySales.length;
        var W  = padL + n * (bw + gap) + 20;
        var barH = H - padT - padB;

        var svg = '<svg class="pm-bar-chart-svg" width="' + W + '" height="' + H + '" viewBox="0 0 ' + W + ' ' + H + '" xmlns="http://www.w3.org/2000/svg">';

        for (var g = 0; g <= 4; g++) {
            var gy = padT + barH - (g / 4 * barH);
            svg += '<line x1="' + padL + '" y1="' + gy + '" x2="' + W + '" y2="' + gy + '" stroke="#dde4ee" stroke-width="1"/>';
            if (g > 0) {
                svg += '<text x="' + (padL - 5) + '" y="' + (gy + 4) + '" text-anchor="end" font-size="9" fill="#9ca3af">' +
                       CURRENCY + Math.round(maxVal * g / 4) + '</text>';
            }
        }

        $.each(dailySales, function (i, d) {
            var x  = padL + i * (bw + gap);
            var v  = parseFloat(d.revenue);
            var bH = maxVal > 0 ? Math.round(v / maxVal * barH) : 0;
            var y  = padT + barH - bH;
            var fill = d.orders > 0 ? '#1a2e4f' : '#dde4ee';

            svg += '<rect x="' + x + '" y="' + y + '" width="' + bw + '" height="' + bH + '" fill="' + fill + '" rx="2"/>';
            svg += '<text x="' + (x + bw / 2) + '" y="' + (padT + barH + 14) + '" text-anchor="middle" font-size="8" fill="#9ca3af">' +
                   d.label + '</text>';
        });

        svg += '</svg>';
        $wrap.html(svg);
    }

    /* ──────────────────────────────────────────────────────────────────────────
     * Panel: Créditos
     * ────────────────────────────────────────────────────────────────────────── */

    function loadCredits() {
        loaded.credits = true;
        $('#pm-credits-loading').show();
        $('#pm-credits-content').hide();

        ajax('pmp_get_credits', {}, function (d) {
            $('#pm-credits-loading').hide();
            $('#pm-credits-content').show();

            // Cabecera
            $('.pm-portal-client-name').text(d.client_name);
            $('#pm-month-badge').text(d.month_label);

            // Stats
            $('#pm-credits-remaining').text(fmtCredits(d.remaining_credits));
            $('#pm-credits-used').text(fmtCredits(d.used_credits));
            $('#pm-credits-plan').text(fmtCredits(d.plan_credits));

            if (parseFloat(d.extra_credits) > 0) {
                $('#pm-credits-extra').text('+' + fmtCredits(d.extra_credits));
                $('#pm-credits-extra-card').show();
            }

            // Anillo
            renderRing(d.percent_used, d.remaining_credits, d.total_credits);

            // ── Bloquear formulario si no hay créditos ──────────────────────
            applyCreditsGate(parseFloat(d.remaining_credits));

            // Historial
            ajax('pmp_get_credits_history', { months: 6 }, function (history) {
                renderCreditsChart(history);
            });

        }, function (msg) {
            $('#pm-credits-loading').hide();
            $('#pm-credits-content').show().html(
                '<div class="pm-portal-notice pm-portal-notice-warn">⚠️ Error al cargar créditos: ' + msg + '</div>'
            );
        });

        // Tipos de ticket (para el formulario)
        loadTicketTypes();
    }

    /**
     * Bloquea o habilita el formulario de nuevo ticket según créditos disponibles.
     * Se llama al cargar créditos y después de cada envío exitoso.
     */
    function applyCreditsGate(remainingCredits) {
        var $form   = $('#pm-ticket-form');
        var $btn    = $('#pm-ticket-submit');
        var $notice = $('#pm-ticket-credits-notice');

        // Eliminar aviso anterior si existe
        $notice.remove();

        if (remainingCredits <= 0) {
            // Insertar aviso encima del formulario
            $form.before(
                '<div id="pm-ticket-credits-notice" class="pm-credits-gate-notice">' +
                    '<span class="pm-credits-gate-icon">⚠️</span>' +
                    '<div>' +
                        '<strong>Sin créditos disponibles</strong>' +
                        '<p>Has agotado tus créditos de este mes. ' +
                        'Compra más créditos para poder enviar nuevas solicitudes.</p>' +
                    '</div>' +
                    '<a class="pm-btn pm-btn-buy pm-btn-buy-sm" href="' + CREDITS_URL + '" ' +
                       'target="_blank" rel="noopener noreferrer">Comprar créditos →</a>' +
                '</div>'
            );
            // Deshabilitar todos los campos y el botón
            $form.find('input, select, textarea, button').prop('disabled', true);
            $form.addClass('pm-form-disabled');
        } else {
            // Asegurarse de que el formulario esté habilitado
            $form.find('input, select, textarea, button').prop('disabled', false);
            $form.removeClass('pm-form-disabled');
        }
    }

    /* ──────────────────────────────────────────────────────────────────────────
     * Tipos de ticket (select del formulario)
     * ────────────────────────────────────────────────────────────────────────── */

    function loadTicketTypes() {
        ajax('pmp_get_ticket_types', {}, function (types) {
            var $sel = $('#pm-ticket-type-select');
            $sel.find('option:not(:first)').remove();
            $.each(types, function (_, t) {
                $sel.append('<option value="' + $('<span>').text(t).html() + '">' + $('<span>').text(t).html() + '</option>');
            });
        });
    }

    /* ──────────────────────────────────────────────────────────────────────────
     * Panel: Tickets
     * ────────────────────────────────────────────────────────────────────────── */

    function loadTickets(month) {
        loaded.tickets = true;
        $('#pm-tickets-loading').show();
        $('#pm-tickets-content').hide();

        var data = {};
        if (month) data.month = month;

        // Activos (pendiente + en_proceso)
        ajax('pmp_get_tickets', { status: 'pendiente' }, function (active) {
            ajax('pmp_get_tickets', { status: 'en_proceso' }, function (inprog) {
                renderTicketList('#pm-tickets-active', active.concat(inprog), 'No hay solicitudes activas.');
            });
            renderTicketList('#pm-tickets-active', active, 'Cargando…');
        });

        // Resueltos del mes seleccionado
        var filterMonth = month || $('#pm-tickets-month-filter').val();
        var filterData  = {};
        if (filterMonth) { filterData.status = 'resuelto'; filterData.month = filterMonth; }
        else filterData.status = 'resuelto';

        ajax('pmp_get_tickets', filterData, function (resolved) {
            $('#pm-tickets-loading').hide();
            $('#pm-tickets-content').show();
            renderTicketList('#pm-tickets-resolved', resolved, 'No hay tickets resueltos en este período.');
        });
    }

    function renderTicketList(selector, tickets, emptyMsg) {
        var $list = $(selector);
        $list.empty();
        if (!tickets || tickets.length === 0) {
            $list.html('<p style="color:#9ca3af;font-size:14px;text-align:center;padding:20px 0;">' + emptyMsg + '</p>');
            return;
        }
        $.each(tickets, function (_, t) {
            $list.append(buildTicketItem(t));
        });
    }

    function buildTicketItem(t) {
        var statusCls = 'pm-badge-' + t.status;
        var priCls    = 'pm-pri-' + t.priority;
        var typeLabel = t.ticket_type ? '<span class="pm-ticket-type-label">' + esc(t.ticket_type) + '</span>' : '';
        var duration  = t.duration_minutes ? '<span>' + t.duration_minutes + ' min</span>' : '';
        var extraTag  = t.is_extra ? '<span class="pm-badge pm-badge-urgente" style="font-size:10px;">Extra</span>' : '';

        return '<div class="pm-ticket-item" data-ticket-id="' + t.id + '" title="Ver detalle">' +
            '<div class="pm-ticket-item-left">' +
                '<span class="pm-badge ' + statusCls + '">' + esc(t.status_label) + '</span>' +
                '<span class="pm-ticket-title">' + esc(t.title) + '</span>' +
                typeLabel +
                extraTag +
            '</div>' +
            '<div class="pm-ticket-meta">' +
                '<span class="pm-badge ' + priCls + '">' + esc(t.priority_label) + '</span>' +
                duration +
                '<span>' + esc(t.date_label) + '</span>' +
                '<span class="pm-ticket-item-hint">Ver →</span>' +
            '</div>' +
        '</div>';
    }

    function esc(str) {
        return $('<span>').text(str || '').html();
    }

    /* ──────────────────────────────────────────────────────────────────────────
     * Panel: Noticias
     * ────────────────────────────────────────────────────────────────────────── */

    function loadNews() {
        loaded.news = true;
        $('#pm-news-loading').show();
        $('#pm-news-content').hide();

        ajax('pmp_get_news', {}, function (items) {
            $('#pm-news-loading').hide();
            var $list = $('#pm-news-content').empty().show();

            if (!items || items.length === 0) {
                $list.html('<p style="color:#9ca3af;text-align:center;padding:30px 0;">No hay noticias por el momento.</p>');
                return;
            }

            $.each(items, function (_, n) {
                $list.append(buildNewsItem(n));
            });
        }, function (msg) {
            $('#pm-news-loading').hide();
            $('#pm-news-content').show().html('<div class="pm-portal-notice pm-portal-notice-warn">Error: ' + msg + '</div>');
        });
    }

    function buildNewsItem(n) {
        var media = '';
        if (n.media_url) {
            var embedUrl = resolveEmbedUrl(n.media_url);
            if (embedUrl) {
                media = '<div class="pm-news-media"><div class="pm-news-iframe-wrap">' +
                        '<iframe src="' + embedUrl + '" frameborder="0" allowfullscreen ' +
                        'allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"></iframe>' +
                        '</div></div>';
            }
        }

        var date = n.created_at ? new Date(n.created_at).toLocaleDateString('es-CR', { day: 'numeric', month: 'long', year: 'numeric' }) : '';

        return '<div class="pm-news-item">' +
            '<div class="pm-news-body">' +
                '<h3 class="pm-news-title">' + esc(n.title) + '</h3>' +
                (n.content ? '<div class="pm-news-content">' + n.content + '</div>' : '') +
                '<span class="pm-news-date">' + date + '</span>' +
            '</div>' +
            media +
        '</div>';
    }

    function resolveEmbedUrl(url) {
        // YouTube: watch?v= o youtu.be/ → embed
        var ytMatch = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/);
        if (ytMatch) return 'https://www.youtube.com/embed/' + ytMatch[1];

        // YouTube embed already
        if (url.indexOf('youtube.com/embed/') !== -1) return url;

        // Instagram reel/post → embed (instala instagram embed script si es necesario)
        var igMatch = url.match(/instagram\.com\/(p|reel)\/([A-Za-z0-9_-]+)/);
        if (igMatch) return 'https://www.instagram.com/' + igMatch[1] + '/' + igMatch[2] + '/embed/';

        return null;
    }

    /* ──────────────────────────────────────────────────────────────────────────
     * Panel: WooCommerce
     * ────────────────────────────────────────────────────────────────────────── */

    function loadWoo(range, customFrom, customTo) {
        loaded.woo = true;
        $('#pm-woo-loading').show();
        $('#pm-woo-content').hide();

        var data = { range: range };
        if (range === 'custom') {
            data.date_from = customFrom;
            data.date_to   = customTo;
        }

        ajax('pmp_get_woo_stats', data, function (d) {
            $('#pm-woo-loading').hide();
            $('#pm-woo-content').show();

            // Stats principales
            $('#pm-woo-revenue').text(fmtCurrency(d.revenue.net_revenue));
            $('#pm-woo-orders').text(d.revenue.total_orders);
            $('#pm-woo-items').text(d.revenue.items_sold);

            // Rango
            $('#pm-chart-range-label').text('(' + d.date_from + ' — ' + d.date_to + ')');

            // Gráfica diaria
            renderDailyChart(d.daily_sales);

            // Top productos
            renderTopProducts(d.top_products);

            // Top clientes
            renderTopCustomers(d.top_customers);

            // Pedidos por estado
            renderOrdersByStatus(d.orders_status);

        }, function (msg) {
            $('#pm-woo-loading').hide();
            $('#pm-woo-content').show().html('<div class="pm-portal-notice pm-portal-notice-warn">Error: ' + msg + '</div>');
        });
    }

    function renderTopProducts(products) {
        var $list = $('#pm-top-products').empty();
        if (!products || products.length === 0) {
            $list.html('<p style="color:#9ca3af;font-size:13px;">Sin datos.</p>');
            return;
        }
        $.each(products, function (i, p) {
            var img = p.image_url
                ? '<img class="pm-top-img" src="' + p.image_url + '" alt="" loading="lazy">'
                : '<div class="pm-top-img-placeholder">📦</div>';
            $list.append(
                '<div class="pm-top-item">' +
                    '<span class="pm-top-rank">' + (i + 1) + '</span>' +
                    img +
                    '<div class="pm-top-info">' +
                        '<div class="pm-top-name">' + esc(p.name) + '</div>' +
                        '<div class="pm-top-sub">' + p.total_qty + ' uds · ' + fmtCurrency(p.total_revenue) + '</div>' +
                    '</div>' +
                '</div>'
            );
        });
    }

    function renderTopCustomers(customers) {
        var $list = $('#pm-top-customers').empty();
        if (!customers || customers.length === 0) {
            $list.html('<p style="color:#9ca3af;font-size:13px;">Sin datos.</p>');
            return;
        }
        $.each(customers, function (i, c) {
            $list.append(
                '<div class="pm-top-item">' +
                    '<span class="pm-top-rank">' + (i + 1) + '</span>' +
                    '<div class="pm-top-img-placeholder">👤</div>' +
                    '<div class="pm-top-info">' +
                        '<div class="pm-top-name">' + esc(c.name || c.email || 'Invitado') + '</div>' +
                        '<div class="pm-top-sub">' + c.order_count + ' pedidos · ' + fmtCurrency(c.total_spent) + '</div>' +
                    '</div>' +
                '</div>'
            );
        });
    }

    function renderOrdersByStatus(statuses) {
        var $wrap = $('#pm-orders-by-status').empty();
        if (!statuses || statuses.length === 0) {
            $wrap.html('<p style="color:#9ca3af;font-size:13px;">Sin pedidos.</p>');
            return;
        }
        $.each(statuses, function (_, s) {
            $wrap.append(
                '<div class="pm-status-pill">' +
                    '<span class="pm-status-pill-count">' + s.count + '</span>' +
                    '<span class="pm-status-pill-label">' + esc(s.status_label) + '</span>' +
                '</div>'
            );
        });
    }

    /* ──────────────────────────────────────────────────────────────────────────
     * Panel: Historial
     * ────────────────────────────────────────────────────────────────────────── */

    function loadHistory() {
        loaded.history = true;
        $('#pm-history-loading').show();
        $('#pm-history-content').hide();

        var month  = $('#pm-history-month').val();
        var status = $('#pm-history-status').val();

        var data = {};
        if (month)  { var parts = month.split('-'); data.month = parts[0] + '-' + parts[1]; }
        if (status) data.status = status;

        ajax('pmp_get_tickets', data, function (tickets) {
            $('#pm-history-loading').hide();
            $('#pm-history-content').show();

            // Resumen
            var totalMin = 0, resolved = 0;
            $.each(tickets, function (_, t) {
                totalMin += parseInt(t.duration_minutes) || 0;
                if (t.status === 'resuelto') resolved++;
            });

            var $summary = $('#pm-history-summary').empty();
            if (tickets.length > 0) {
                $summary.append('<span>' + tickets.length + ' solicitud' + (tickets.length !== 1 ? 'es' : '') + '</span>');
                $summary.append('<span>' + resolved + ' resuelta' + (resolved !== 1 ? 's' : '') + '</span>');
                if (totalMin > 0) $summary.append('<span>' + totalMin + ' min totales</span>');
            }

            // Tabla
            var $tbody = $('#pm-history-tbody').empty();
            if (tickets.length === 0) {
                $tbody.append('<tr><td colspan="7" class="pm-table-empty">No hay registros para este período.</td></tr>');
                return;
            }
            $.each(tickets, function (_, t) {
                var statusCls = 'pm-badge-' + t.status;
                var priCls    = 'pm-pri-' + t.priority;
                $tbody.append(
                    '<tr>' +
                        '<td style="color:#9ca3af;font-size:12px;">#' + t.id + '</td>' +
                        '<td>' + esc(t.title) + '</td>' +
                        '<td style="color:#6b7280;font-size:12px;">' + esc(t.ticket_type || '—') + '</td>' +
                        '<td><span class="pm-badge ' + priCls + '">' + esc(t.priority_label) + '</span></td>' +
                        '<td><span class="pm-badge ' + statusCls + '">' + esc(t.status_label) + '</span></td>' +
                        '<td style="white-space:nowrap;">' + (t.duration_minutes ? t.duration_minutes + ' min' : '—') + '</td>' +
                        '<td style="white-space:nowrap;color:#6b7280;font-size:12px;">' + esc(t.date_label) + '</td>' +
                    '</tr>'
                );
            });
        }, function (msg) {
            $('#pm-history-loading').hide();
            $('#pm-history-content').show().html('<div class="pm-portal-notice pm-portal-notice-warn">Error: ' + msg + '</div>');
        });
    }

    /* ──────────────────────────────────────────────────────────────────────────
     * Formulario: nuevo ticket
     * ────────────────────────────────────────────────────────────────────────── */

    function initTicketForm() {
        $(document).on('submit', '#pm-ticket-form', function (e) {
            e.preventDefault();
            var $form = $(this);
            var $btn  = $('#pm-ticket-submit');
            var $msg  = $('#pm-ticket-msg');

            var data = {
                title:        $form.find('[name=title]').val(),
                priority:     $form.find('[name=priority]').val(),
                ticket_type:  $form.find('[name=ticket_type]').val(),
                client_notes: $form.find('[name=client_notes]').val(),
            };

            if (!data.title.trim()) {
                showFormMsg($msg, 'El título es requerido.', 'error');
                return;
            }

            $btn.prop('disabled', true).text('Enviando…');
            showFormMsg($msg, '', '');

            ajax('pmp_create_ticket', data, function (r) {
                $btn.prop('disabled', false).text('Enviar solicitud');
                showFormMsg($msg, '✅ Solicitud #' + r.ticket_id + ' registrada. Te contactaremos pronto.', 'success');
                $form[0].reset();
                // Invalidar cache de tickets para próxima carga
                loaded.tickets = false;
                // Re-evaluar el gate con los créditos restantes devueltos por la API
                if (typeof r.remaining_credits !== 'undefined') {
                    applyCreditsGate(parseFloat(r.remaining_credits));
                }
            }, function (msg) {
                $btn.prop('disabled', false).text('Enviar solicitud');
                showFormMsg($msg, '❌ ' + msg, 'error');
            });
        });
    }

    function showFormMsg($el, text, type) {
        $el.removeClass('success error').text(text);
        if (type) $el.addClass(type).show();
        else $el.hide();
    }

    /* ──────────────────────────────────────────────────────────────────────────
     * Modal de detalle de ticket
     * ────────────────────────────────────────────────────────────────────────── */

    function initTicketModal() {
        // Inyectar contenedor del modal una sola vez
        if ( ! $('#pm-ticket-modal').length ) {
            $('body').append('<div id="pm-ticket-modal" class="pm-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="pm-modal-title"></div>');
        }

        // Abrir modal al hacer clic en cualquier ticket item
        $(document).on('click', '.pm-ticket-item', function () {
            var id = $(this).data('ticket-id');
            if ( ! id ) return;
            openTicketModal(id);
        });

        // Cerrar con clic en overlay o botón ×
        $(document).on('click', '#pm-ticket-modal', function (e) {
            if ( $(e.target).is('#pm-ticket-modal') || $(e.target).is('.pm-modal-close') ) {
                closeTicketModal();
            }
        });

        // Cerrar con ESC
        $(document).on('keydown', function (e) {
            if ( e.key === 'Escape' && $('#pm-ticket-modal').is(':visible') ) {
                closeTicketModal();
            }
        });
    }

    function openTicketModal(ticketId) {
        var $overlay = $('#pm-ticket-modal');
        $overlay.html(
            '<div class="pm-modal">' +
                '<div class="pm-modal-header">' +
                    '<div><span class="pm-modal-id">#' + ticketId + '</span>' +
                    '<h3 class="pm-modal-title" id="pm-modal-title">Cargando...</h3></div>' +
                    '<button class="pm-modal-close" aria-label="Cerrar">✕</button>' +
                '</div>' +
                '<div class="pm-modal-body">' +
                    '<div class="pm-portal-loading">' +
                        '<div class="pm-portal-spinner"></div><span>Cargando detalle...</span>' +
                    '</div>' +
                '</div>' +
            '</div>'
        ).show();

        ajax('pmp_get_ticket', { id: ticketId }, function (t) {
            renderTicketModal(t);
        }, function (msg) {
            $('#pm-ticket-modal .pm-modal-body').html(
                '<div class="pm-portal-notice pm-portal-notice-warn">⚠️ ' + esc(msg) + '</div>'
            );
        });
    }

    function closeTicketModal() {
        $('#pm-ticket-modal').hide().html('');
    }

    function renderTicketModal(t) {
        var statusCls = 'pm-badge-' + t.status;
        var priCls    = 'pm-pri-' + t.priority;

        // Cabecera
        $('#pm-modal-title').text(t.title);

        // Badges
        var badges = '<div class="pm-modal-badges">' +
            '<span class="pm-badge ' + statusCls + '">' + esc(t.status_label) + '</span>' +
            '<span class="pm-badge ' + priCls + '">' + esc(t.priority_label) + '</span>' +
            ( t.ticket_type ? '<span class="pm-ticket-type-label">' + esc(t.ticket_type) + '</span>' : '' ) +
            ( t.is_extra ? '<span class="pm-badge pm-badge-urgente" style="font-size:10px;">Extra</span>' : '' ) +
        '</div>';

        // Meta grid
        var meta = '<div class="pm-modal-meta">';
        meta += metaField('Desarrollador', t.developer_name || '—');
        meta += metaField('Duración',      t.duration_minutes ? t.duration_minutes + ' minutos' : '—');
        meta += metaField('Fecha creación', t.date_label);
        meta += metaField('Fecha compromiso', t.scheduled_label || '—');
        if (t.start_label) meta += metaField('Inicio trabajo', t.start_label);
        if (t.end_label)   meta += metaField('Fin trabajo',    t.end_label);
        meta += '</div>';

        // Notas del cliente
        var notasCliente = '';
        if (t.client_notes && t.client_notes.trim()) {
            notasCliente = '<div class="pm-modal-notes-block">' +
                '<span class="pm-modal-notes-label">📝 Tu solicitud</span>' +
                '<div class="pm-modal-notes-text">' + esc(t.client_notes) + '</div>' +
            '</div>';
        }

        // Trabajo realizado (objective)
        var objetivo = '';
        if (t.objective && t.objective.trim()) {
            objetivo = '<div class="pm-modal-notes-block">' +
                '<span class="pm-modal-notes-label">✅ Trabajo realizado</span>' +
                '<div class="pm-modal-notes-text">' + esc(t.objective) + '</div>' +
            '</div>';
        }

        $('#pm-ticket-modal .pm-modal-body').html(badges + meta + notasCliente + objetivo);
    }

    function metaField(label, value) {
        return '<div class="pm-modal-field">' +
            '<span class="pm-modal-field-label">' + esc(label) + '</span>' +
            '<span class="pm-modal-field-value' + (value === '—' ? ' muted' : '') + '">' + esc(value) + '</span>' +
        '</div>';
    }

    /* ──────────────────────────────────────────────────────────────────────────
     * Filtro de tickets resueltos
     * ────────────────────────────────────────────────────────────────────────── */

    function initTicketFilters() {
        $(document).on('click', '#pm-tickets-filter-btn', function () {
            loaded.tickets = false;
            var month = $('#pm-tickets-month-filter').val();
            loadTicketsResolved(month);
        });
    }

    function loadTicketsResolved(month) {
        var data = { status: 'resuelto' };
        if (month) data.month = month;
        ajax('pmp_get_tickets', data, function (tickets) {
            renderTicketList('#pm-tickets-resolved', tickets, 'No hay tickets resueltos en este período.');
        });
    }

    /* ──────────────────────────────────────────────────────────────────────────
     * WooCommerce: filtros de rango
     * ────────────────────────────────────────────────────────────────────────── */

    function initWooFilters() {
        $(document).on('click', '.pm-range-btn', function () {
            var $btn  = $(this);
            var range = $btn.data('range');
            $('.pm-range-btn').removeClass('active');
            $btn.addClass('active');
            loaded.woo = false;
            loadWoo(range);
        });

        $(document).on('click', '#pm-woo-custom-btn', function () {
            var from = $('#pm-woo-from').val();
            var to   = $('#pm-woo-to').val();
            if (!from || !to) return;
            $('.pm-range-btn').removeClass('active');
            loaded.woo = false;
            loadWoo('custom', from, to);
        });
    }

    /* ──────────────────────────────────────────────────────────────────────────
     * Historial: filtros
     * ────────────────────────────────────────────────────────────────────────── */

    function initHistoryFilters() {
        $(document).on('click', '#pm-history-load-btn', function () {
            loaded.history = false;
            loadHistory();
        });
    }

    /* ──────────────────────────────────────────────────────────────────────────
     * Bootstrap
     * ────────────────────────────────────────────────────────────────────────── */

    $(function () {
        if ($('#pm-portal-root').length === 0) return;

        initTabs();
        initTicketForm();
        initTicketFilters();
        initTicketModal();
        initHistoryFilters();

        if (WOO_ACTIVE) {
            initWooFilters();
            // Setear fechas default para el rango custom
            $('#pm-woo-from').val(new Date(Date.now() - 30 * 86400000).toISOString().slice(0, 10));
            $('#pm-woo-to').val(new Date().toISOString().slice(0, 10));
        }

        // Auto-abrir pestaña desde parámetro URL (ej. ?pmp_tab=news desde el Dashboard Resumen)
        var urlParams = new URLSearchParams( window.location.search );
        var openTab   = urlParams.get( 'pmp_tab' );
        if ( openTab && $('[data-tab="' + openTab + '"]').length ) {
            $('[data-tab="' + openTab + '"]').trigger( 'click' );
        } else {
            loadCredits();
        }
    });

}(jQuery));
