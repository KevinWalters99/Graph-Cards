/**
 * Card Graph - eBay Transactions Tab
 */
var Ebay = {
    initialized: false,
    filters: {},
    page: 1,
    sortKey: 'order_date',
    sortDir: 'desc',

    init: function() {
        var panel = document.getElementById('tab-ebay');

        if (!this.initialized) {
            panel.innerHTML = [
                '<div class="page-header">',
                '    <h1>eBay Transactions <span id="ebay-filter-desc" class="filter-description"></span></h1>',
                '    <button class="btn btn-primary" id="ebay-import-btn">Import Emails</button>',
                '</div>',
                '<div id="ebay-summary-cards" class="cards-grid"></div>',
                '<div id="ebay-filters"></div>',
                '<div id="ebay-table"></div>',
            ].join('\n');

            Filters.render(document.getElementById('ebay-filters'), [
                {
                    type: 'select', name: 'source', label: 'Source',
                    options: [
                        { value: 'ebay_confirmed', label: 'eBay Order' },
                        { value: 'paypal_ebay', label: 'PayPal eBay' },
                        { value: 'paypal_direct', label: 'PayPal Direct' },
                        { value: 'manual', label: 'Manual' },
                    ]
                },
                {
                    type: 'select', name: 'status', label: 'Status',
                    options: [
                        { value: 'Pending', label: 'Pending' },
                        { value: 'Confirmed', label: 'Confirmed' },
                        { value: 'Shipped', label: 'Shipped' },
                        { value: 'Delivered', label: 'Delivered' },
                        { value: 'Returned', label: 'Returned' },
                        { value: 'Cancelled', label: 'Cancelled' },
                    ]
                },
                { type: 'text', name: 'search', label: 'Search', placeholder: 'Order #, seller...' },
                { type: 'date', name: 'date_from', label: 'From Date' },
                { type: 'date', name: 'date_to', label: 'To Date' },
            ], function(f) { Ebay.filters = f; Ebay.page = 1; Ebay.loadData(); },
            { descriptionEl: 'ebay-filter-desc' });

            document.getElementById('ebay-import-btn').addEventListener('click', function() {
                Ebay.runImport();
            });

            this.initialized = true;
        }

        this.loadSummary();
        this.loadData();
    },

    sourceLabel: function(src) {
        var labels = {
            'ebay_confirmed': 'eBay',
            'paypal_ebay': 'PayPal/eBay',
            'paypal_direct': 'PayPal',
            'manual': 'Manual'
        };
        return labels[src] || src || 'eBay';
    },

    sourceBadgeClass: function(src) {
        var classes = {
            'ebay_confirmed': 'status-completed',
            'paypal_ebay': 'status-shipped',
            'paypal_direct': 'status-pending',
            'manual': 'status-cancelled'
        };
        return classes[src] || 'status-completed';
    },

    loadSummary: function() {
        API.get('/api/ebay/summary').then(function(s) {
            var cards = document.getElementById('ebay-summary-cards');
            cards.innerHTML = [
                '<div class="card">',
                '    <div class="card-label">Total Spent</div>',
                '    <div class="card-value val-expense">' + App.formatCurrency(s.total_spent) + '</div>',
                '    <div class="card-sub"><span class="val-count">' + s.total_orders + '</span> orders</div>',
                '</div>',
                '<div class="card">',
                '    <div class="card-label">Total Items</div>',
                '    <div class="card-value val-count">' + s.total_items + '</div>',
                '</div>',
                '<div class="card">',
                '    <div class="card-label">Shipping</div>',
                '    <div class="card-value val-expense">' + App.formatCurrency(s.total_shipping) + '</div>',
                '</div>',
                '<div class="card">',
                '    <div class="card-label">Tax</div>',
                '    <div class="card-value val-expense">' + App.formatCurrency(s.total_tax) + '</div>',
                '</div>',
                '<div class="card">',
                '    <div class="card-label">Delivered</div>',
                '    <div class="card-value val-count">' + s.delivered_count + '</div>',
                '</div>',
                '<div class="card">',
                '    <div class="card-label">Sellers</div>',
                '    <div class="card-value val-count">' + s.unique_sellers + '</div>',
                '</div>',
            ].join('\n');
        }).catch(function(err) {
            App.toast(err.message, 'error');
        });
    },

    loadData: function() {
        App.showLoading();
        var params = {};
        for (var key in this.filters) {
            params[key] = this.filters[key];
        }
        params.page = this.page;
        params.per_page = 50;
        params.sort = this.sortKey;
        params.order = this.sortDir;
        API.get('/api/ebay/orders', params).then(function(result) {
            Ebay.renderTable(result);
        }).catch(function(err) {
            App.toast(err.message, 'error');
        }).finally(function() {
            App.hideLoading();
        });
    },

    renderTable: function(result) {
        var container = document.getElementById('ebay-table');

        DataTable.render(container, {
            columns: [
                {
                    key: 'order_date', label: 'Date', sortable: true,
                    format: function(v) { return App.formatDatetime(v); }
                },
                {
                    key: 'source', label: 'Source', sortable: true,
                    render: function(row) {
                        var cls = Ebay.sourceBadgeClass(row.source);
                        var lbl = Ebay.sourceLabel(row.source);
                        return '<span class="status-badge ' + cls + '">' + lbl + '</span>';
                    }
                },
                { key: 'seller_buyer_name', label: 'Seller', sortable: true },
                {
                    key: 'item_count', label: 'Items', align: 'right', sortable: false,
                    render: function(row) {
                        var count = row.item_count || 0;
                        var reported = row.reported_item_count;
                        if (reported && reported > count) {
                            return count + ' of ' + reported;
                        }
                        return '' + count;
                    }
                },
                {
                    key: 'total_amount', label: 'Total', align: 'right', sortable: true,
                    format: function(v) { return App.formatCurrency(v); }
                },
                {
                    key: 'status', label: 'Status', sortable: true,
                    render: function(row) {
                        var cls = App.statusClass(row.status);
                        return '<span class="status-badge ' + cls + '">' + (row.status || '-') + '</span>';
                    }
                },
                {
                    key: 'delivery_date', label: 'Delivered', sortable: true,
                    format: function(v) {
                        if (!v) return '-';
                        return App.formatDatetime(v);
                    }
                },
            ],
            data: result.data || [],
            total: result.total || 0,
            page: result.page || 1,
            perPage: result.per_page || 50,
            sortKey: this.sortKey,
            sortDir: this.sortDir,
            onSort: function(key) {
                if (Ebay.sortKey === key) {
                    Ebay.sortDir = Ebay.sortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    Ebay.sortKey = key;
                    Ebay.sortDir = 'desc';
                }
                Ebay.loadData();
            },
            onPage: function(p) { Ebay.page = p; Ebay.loadData(); },
            onRowClick: function(row) { Ebay.showDetail(row.ebay_order_id); },
        });
    },

    showDetail: function(orderId) {
        API.get('/api/ebay/orders/' + orderId).then(function(result) {
            var order = result.order;
            var items = result.items || [];

            var itemsHtml = items.map(function(item) {
                return [
                    '<div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #f0f0f0;">',
                    '    <div>',
                    '        <strong>' + (item.item_title || 'Unknown') + '</strong>',
                    '        <br><small class="text-muted">Item #' + (item.ebay_item_number || '-') + ' | Seller: ' + (item.seller_buyer_name || '-') + '</small>',
                    '    </div>',
                    '    <div style="text-align:right;white-space:nowrap;">',
                    '        <strong>' + App.formatCurrency(item.item_price) + '</strong>',
                    '    </div>',
                    '</div>',
                ].join('\n');
            }).join('\n');

            var statusOptions = ['Pending', 'Confirmed', 'Shipped', 'Delivered', 'Returned', 'Cancelled']
                .map(function(s) {
                    return '<option value="' + s + '"' + (s === order.status ? ' selected' : '') + '>' + s + '</option>';
                }).join('');

            var typeOptions = ['PURCHASE', 'SALE']
                .map(function(t) {
                    return '<option value="' + t + '"' + (t === order.transaction_type ? ' selected' : '') + '>' + t + '</option>';
                }).join('');

            var srcBadge = '<span class="status-badge ' + Ebay.sourceBadgeClass(order.source) + '">' + Ebay.sourceLabel(order.source) + '</span>';
            var deliveryVal = order.delivery_date ? App.formatDatetime(order.delivery_date) : '-';
            var paypalTxn = order.paypal_transaction_id || '-';

            App.openModal([
                '<div class="modal-header">',
                '    <h2>Order #' + order.order_number + '</h2>',
                '    <button class="modal-close" onclick="App.closeModal()">&times;</button>',
                '</div>',
                '<div class="modal-body">',
                '    <div class="detail-list">',
                '        <div class="detail-item"><div class="detail-label">Order Date</div><div class="detail-value">' + App.formatDatetime(order.order_date) + '</div></div>',
                '        <div class="detail-item"><div class="detail-label">Source</div><div class="detail-value">' + srcBadge + '</div></div>',
                '        <div class="detail-item"><div class="detail-label">Seller</div><div class="detail-value">' + (order.seller_buyer_name || '-') + '</div></div>',
                '        <div class="detail-item"><div class="detail-label">Items</div><div class="detail-value">' + items.length + (order.reported_item_count && order.reported_item_count > items.length ? ' of ' + order.reported_item_count + ' (email truncated)' : '') + '</div></div>',
                '        <div class="detail-item"><div class="detail-label">Subtotal</div><div class="detail-value">' + App.formatCurrency(order.subtotal) + '</div></div>',
                '        <div class="detail-item"><div class="detail-label">Shipping</div><div class="detail-value">' + App.formatCurrency(order.shipping_cost) + '</div></div>',
                '        <div class="detail-item"><div class="detail-label">Tax</div><div class="detail-value">' + App.formatCurrency(order.sales_tax) + '</div></div>',
                '        <div class="detail-item"><div class="detail-label">Total</div><div class="detail-value"><strong>' + App.formatCurrency(order.total_amount) + '</strong></div></div>',
                '        <div class="detail-item"><div class="detail-label">Delivered</div><div class="detail-value">' + deliveryVal + '</div></div>',
                '        <div class="detail-item"><div class="detail-label">PayPal TXN</div><div class="detail-value" style="font-size:0.85em;">' + paypalTxn + '</div></div>',
                '    </div>',
                '',
                '    <hr class="section-divider">',
                '    <h3 class="section-title">Items (' + items.length + ')</h3>',
                '    ' + itemsHtml,
                '',
                '    <hr class="section-divider">',
                '    <h3 class="section-title">Update Order</h3>',
                '    <div class="form-row">',
                '        <div class="form-group">',
                '            <label>Status</label>',
                '            <select id="ebay-detail-status">' + statusOptions + '</select>',
                '        </div>',
                '        <div class="form-group">',
                '            <label>Type</label>',
                '            <select id="ebay-detail-type">' + typeOptions + '</select>',
                '        </div>',
                '    </div>',
                '    <div class="form-row">',
                '        <div class="form-group" style="flex:1;">',
                '            <label>Notes</label>',
                '            <input type="text" id="ebay-detail-notes" value="' + (order.notes || '') + '" placeholder="Optional notes">',
                '        </div>',
                '    </div>',
                '    <button class="btn btn-primary btn-sm" id="ebay-save-btn">Save Changes</button>',
                '    <button class="btn btn-danger btn-sm" id="ebay-delete-btn" style="margin-left:8px;">Delete Order</button>',
                '</div>',
            ].join('\n'));

            document.getElementById('ebay-save-btn').addEventListener('click', function() {
                Ebay.updateOrder(orderId);
            });
            document.getElementById('ebay-delete-btn').addEventListener('click', function() {
                Ebay.deleteOrder(orderId);
            });

        }).catch(function(err) {
            App.toast(err.message, 'error');
        });
    },

    updateOrder: function(orderId) {
        var status = document.getElementById('ebay-detail-status').value;
        var txnType = document.getElementById('ebay-detail-type').value;
        var notes = document.getElementById('ebay-detail-notes').value;

        API.put('/api/ebay/orders/' + orderId, {
            status: status,
            transaction_type: txnType,
            notes: notes,
        }).then(function() {
            App.toast('Order updated', 'success');
            App.closeModal();
            Ebay.loadData();
            Ebay.loadSummary();
        }).catch(function(err) {
            App.toast(err.message, 'error');
        });
    },

    deleteOrder: function(orderId) {
        if (!confirm('Delete this eBay order and all its items?')) return;

        API.del('/api/ebay/orders/' + orderId).then(function() {
            App.toast('Order deleted', 'success');
            App.closeModal();
            Ebay.loadData();
            Ebay.loadSummary();
        }).catch(function(err) {
            App.toast(err.message, 'error');
        });
    },

    runImport: function() {
        var btn = document.getElementById('ebay-import-btn');
        if (btn.disabled) return;

        btn.disabled = true;
        btn.textContent = 'Importing...';
        App.toast('Starting email import...', 'info');

        API.post('/api/ebay/import', { no_move: true }).then(function(result) {
            if (result.status === 'started') {
                Ebay.pollImport(btn);
            } else {
                Ebay.finishImport(btn, result);
            }
        }).catch(function(err) {
            btn.disabled = false;
            btn.textContent = 'Import Emails';
            App.toast('Import failed: ' + err.message, 'error');
        });
    },

    pollImport: function(btn) {
        setTimeout(function() {
            API.post('/api/ebay/import', { check_status: true }).then(function(result) {
                if (result.status === 'running') {
                    btn.textContent = 'Importing...';
                    Ebay.pollImport(btn);
                } else if (result.status === 'complete') {
                    Ebay.finishImport(btn, result);
                } else {
                    btn.disabled = false;
                    btn.textContent = 'Import Emails';
                }
            }).catch(function() {
                Ebay.pollImport(btn);
            });
        }, 3000);
    },

    finishImport: function(btn, result) {
        btn.disabled = false;
        btn.textContent = 'Import Emails';

        var summary = result.summary || {};
        var msg = 'Import complete: ' +
            (summary.orders_imported || 0) + ' new orders, ' +
            (summary.deliveries_updated || 0) + ' deliveries updated';
        App.toast(msg, 'success');

        if (result.output) {
            console.log('Import output:\n' + result.output);
        }

        Ebay.loadSummary();
        Ebay.loadData();
    },
};
