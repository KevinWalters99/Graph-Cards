/**
 * Card Graph — Items & Costs Tab
 */
const LineItems = {
    initialized: false,
    filters: {},
    page: 1,
    sortKey: 'transaction_completed_at',
    sortDir: 'desc',
    statuses: [],
    livestreams: [],

    async init() {
        const panel = document.getElementById('tab-line-items');

        if (!this.initialized) {
            panel.innerHTML = `
                <div class="page-header">
                    <h1>Items &amp; Costs</h1>
                </div>
                <div id="line-items-filters"></div>
                <div id="line-items-table"></div>
            `;

            // Load filter options
            await this.loadFilterOptions();

            Filters.render(document.getElementById('line-items-filters'), [
                { type: 'date', name: 'date_from', label: 'From Date' },
                { type: 'date', name: 'date_to', label: 'To Date' },
                {
                    type: 'select', name: 'status', label: 'Status',
                    options: this.statuses.map(s => ({ value: s.status_type_id, label: s.status_name }))
                },
                {
                    type: 'select', name: 'buy_format', label: 'Buy Format',
                    options: [
                        { value: 'AUCTION', label: 'Auction' },
                        { value: 'GIVEAWAY', label: 'Giveaway' },
                    ]
                },
                {
                    type: 'select', name: 'livestream_id', label: 'Auction',
                    options: this.livestreams.map(ls => ({
                        value: ls.livestream_id,
                        label: (ls.stream_date || '') + ' - ' + ls.livestream_title + ' (' + ls.total_items + ' items)'
                    }))
                },
                { type: 'text', name: 'search', label: 'Search', placeholder: 'Title or buyer...' },
            ], (f) => { this.filters = f; this.page = 1; this.loadData(); });

            this.initialized = true;
        }

        this.loadData();
    },

    async loadFilterOptions() {
        try {
            const [statusResult, lsResult] = await Promise.all([
                API.get('/api/statuses'),
                API.get('/api/livestreams'),
            ]);
            this.statuses = statusResult.data || [];
            this.livestreams = lsResult.data || [];
        } catch (e) { /* silent */ }
    },

    async loadData() {
        try {
            App.showLoading();
            const params = {
                ...this.filters,
                page: this.page,
                per_page: 100,
                sort: this.sortKey,
                order: this.sortDir,
            };
            const result = await API.get('/api/line-items', params);
            this.renderTable(result);
        } catch (err) {
            App.toast(err.message, 'error');
        } finally {
            App.hideLoading();
        }
    },

    renderTable(result) {
        const container = document.getElementById('line-items-table');

        DataTable.render(container, {
            columns: [
                {
                    key: 'transaction_completed_at', label: 'Date', sortable: true,
                    format: (v) => App.formatDatetime(v)
                },
                { key: 'order_id', label: 'Order ID', sortable: true },
                { key: 'listing_title', label: 'Title', sortable: true },
                { key: 'transaction_type', label: 'Type', sortable: true },
                { key: 'buy_format', label: 'Format', sortable: true },
                { key: 'buyer_name', label: 'Buyer', sortable: true },
                {
                    key: 'original_item_price', label: 'Price', align: 'right', sortable: true,
                    format: (v) => App.formatCurrency(v)
                },
                {
                    key: 'transaction_amount', label: 'Net Amount', align: 'right', sortable: true,
                    format: (v) => App.formatCurrency(v)
                },
                {
                    key: 'cost_amount', label: 'Cost', align: 'right', sortable: true,
                    render: (row) => {
                        const cost = parseFloat(row.cost_amount);
                        return cost > 0 ? App.formatCurrency(cost) : '<span class="text-muted">-</span>';
                    }
                },
                {
                    key: 'profit', label: 'Profit/Loss', align: 'right', sortable: true,
                    render: (row) => {
                        const cost = parseFloat(row.cost_amount) || 0;
                        if (cost === 0) return '<span class="text-muted">-</span>';
                        const profit = parseFloat(row.transaction_amount) - cost;
                        const cls = profit >= 0 ? 'text-success' : 'text-danger';
                        return `<span class="${cls}">${App.formatCurrency(profit)}</span>`;
                    }
                },
                {
                    key: 'status_name', label: 'Status', sortable: true,
                    render: (row) => {
                        const cls = App.statusClass(row.status_name);
                        return `<span class="status-badge ${cls}">${row.status_name || '-'}</span>`;
                    }
                },
            ],
            data: result.data || [],
            total: result.total || 0,
            page: result.page || 1,
            perPage: result.per_page || 100,
            sortKey: this.sortKey,
            sortDir: this.sortDir,
            onSort: (key) => {
                if (this.sortKey === key) {
                    this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    this.sortKey = key;
                    this.sortDir = 'desc';
                }
                this.loadData();
            },
            onPage: (p) => { this.page = p; this.loadData(); },
            onRowClick: (row) => this.showDetail(row.ledger_transaction_id),
        });
    },

    async showDetail(ledgerId) {
        try {
            const result = await API.get(`/api/line-items/${ledgerId}`);
            const item = result.item;
            const history = result.history || [];
            const costs = result.costs || [];

            const statusOptions = this.statuses.map(s =>
                `<option value="${s.status_type_id}" ${s.status_type_id == item.current_status_id ? 'selected' : ''}>${s.status_name}</option>`
            ).join('');

            const historyHtml = history.length > 0
                ? `<ul class="timeline">${history.map(h => `
                    <li class="timeline-item">
                        <div class="timeline-date">${App.formatDatetime(h.changed_at)} by ${h.changed_by_name}</div>
                        <div class="timeline-text">
                            ${h.old_status_name ? h.old_status_name + ' &rarr; ' : ''}${h.new_status_name}
                            ${h.change_reason ? '<br><small class="text-muted">' + h.change_reason + '</small>' : ''}
                        </div>
                    </li>`).join('')}</ul>`
                : '<p class="text-muted">No status changes recorded.</p>';

            const costsHtml = costs.length > 0
                ? costs.map(c => `
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid #f0f0f0;">
                        <div>
                            <strong>${App.formatCurrency(c.cost_amount)}</strong>
                            ${c.cost_description ? '<br><small class="text-muted">' + c.cost_description + '</small>' : ''}
                        </div>
                        <div>
                            <small class="text-muted">${c.entered_by_name} - ${App.formatDatetime(c.created_at)}</small>
                            <button class="btn btn-danger btn-sm" onclick="LineItems.deleteCost(${c.cost_id}, '${ledgerId}')" style="margin-left:8px;">Del</button>
                        </div>
                    </div>`).join('')
                : '<p class="text-muted">No cost entries.</p>';

            App.openModal(`
                <div class="modal-header">
                    <h2>${item.listing_title || 'Item Detail'}</h2>
                    <button class="modal-close" onclick="App.closeModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="detail-list">
                        <div class="detail-item"><div class="detail-label">Order ID</div><div class="detail-value">${item.order_id || '-'}</div></div>
                        <div class="detail-item"><div class="detail-label">Ledger ID</div><div class="detail-value">${item.ledger_transaction_id}</div></div>
                        <div class="detail-item"><div class="detail-label">Date</div><div class="detail-value">${App.formatDatetime(item.transaction_completed_at)}</div></div>
                        <div class="detail-item"><div class="detail-label">Type</div><div class="detail-value">${item.transaction_type}</div></div>
                        <div class="detail-item"><div class="detail-label">Format</div><div class="detail-value">${item.buy_format || '-'}</div></div>
                        <div class="detail-item"><div class="detail-label">Category</div><div class="detail-value">${item.product_category || '-'}</div></div>
                        <div class="detail-item"><div class="detail-label">Buyer</div><div class="detail-value">${item.buyer_name || '-'} ${item.buyer_state ? '(' + item.buyer_state + ')' : ''}</div></div>
                        <div class="detail-item"><div class="detail-label">Livestream</div><div class="detail-value">${item.livestream_title || '-'}</div></div>
                        <div class="detail-item"><div class="detail-label">Item Price</div><div class="detail-value">${App.formatCurrency(item.original_item_price)}</div></div>
                        <div class="detail-item"><div class="detail-label">Buyer Paid</div><div class="detail-value">${App.formatCurrency(item.buyer_paid)}</div></div>
                        <div class="detail-item"><div class="detail-label">Shipping Fee</div><div class="detail-value">${App.formatCurrency(item.shipping_fee)}</div></div>
                        <div class="detail-item"><div class="detail-label">Commission</div><div class="detail-value">${App.formatCurrency(item.commission_fee)}</div></div>
                        <div class="detail-item"><div class="detail-label">Processing Fee</div><div class="detail-value">${App.formatCurrency(item.payment_processing_fee)}</div></div>
                        <div class="detail-item"><div class="detail-label">Net Amount</div><div class="detail-value"><strong>${App.formatCurrency(item.transaction_amount)}</strong></div></div>
                        <div class="detail-item full-width"><div class="detail-label">Description</div><div class="detail-value">${item.transaction_message || '-'}</div></div>
                    </div>

                    <hr class="section-divider">
                    <h3 class="section-title">Change Status</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label>New Status</label>
                            <select id="detail-status-select">${statusOptions}</select>
                        </div>
                        <div class="form-group">
                            <label>Reason</label>
                            <input type="text" id="detail-status-reason" placeholder="Optional reason">
                        </div>
                    </div>
                    <button class="btn btn-primary btn-sm" onclick="LineItems.changeStatus('${ledgerId}')">Update Status</button>

                    <hr class="section-divider">
                    <h3 class="section-title">Costs</h3>
                    ${costsHtml}
                    <div class="form-row mt-2">
                        <div class="form-group">
                            <label>Amount</label>
                            <input type="number" id="detail-cost-amount" step="0.01" min="0" placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label>Description</label>
                            <input type="text" id="detail-cost-desc" placeholder="e.g., Card purchase cost">
                        </div>
                    </div>
                    <button class="btn btn-success btn-sm" onclick="LineItems.addCost('${ledgerId}')">Add Cost</button>

                    <hr class="section-divider">
                    <h3 class="section-title">Status History</h3>
                    ${historyHtml}
                </div>
            `);
        } catch (err) {
            App.toast(err.message, 'error');
        }
    },

    async changeStatus(ledgerId) {
        const statusId = document.getElementById('detail-status-select').value;
        const reason = document.getElementById('detail-status-reason').value;

        try {
            await API.put(`/api/line-items/${ledgerId}/status`, {
                status_id: parseInt(statusId),
                reason: reason,
            });
            App.toast('Status updated', 'success');
            App.closeModal();
            this.loadData();
        } catch (err) {
            App.toast(err.message, 'error');
        }
    },

    async addCost(ledgerId) {
        const amount = document.getElementById('detail-cost-amount').value;
        const desc = document.getElementById('detail-cost-desc').value;

        if (!amount || parseFloat(amount) <= 0) {
            App.toast('Enter a valid cost amount', 'error');
            return;
        }

        try {
            await API.post('/api/costs', {
                ledger_transaction_id: ledgerId,
                cost_amount: parseFloat(amount),
                cost_description: desc,
            });
            App.toast('Cost added', 'success');
            // Refresh detail modal
            this.showDetail(ledgerId);
            this.loadData();
        } catch (err) {
            App.toast(err.message, 'error');
        }
    },

    async deleteCost(costId, ledgerId) {
        if (!confirm('Delete this cost entry?')) return;

        try {
            await API.del(`/api/costs/${costId}`);
            App.toast('Cost deleted', 'success');
            this.showDetail(ledgerId);
            this.loadData();
        } catch (err) {
            App.toast(err.message, 'error');
        }
    }
};
