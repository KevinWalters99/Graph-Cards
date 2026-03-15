/**
 * Card Graph — PayPal Tab
 * Sub-tabs: Detail, Assignment, Category Breakdown, Reconciliation, Assignment Summary
 */
const PayPal = {
    initialized: false,
    currentSubTab: 'detail',
    // Detail state
    detailFilters: {},
    detailPage: 1,
    detailSortKey: 'transaction_date',
    detailSortDir: 'desc',
    types: [],
    // Assignment state
    assignFilters: {},
    assignPage: 1,
    assignSortKey: 'transaction_date',
    assignSortDir: 'desc',
    livestreams: [],

    async init() {
        const panel = document.getElementById('tab-paypal');

        if (!this.initialized) {
            panel.innerHTML = `
                <div class="page-header">
                    <h1>PayPal</h1>
                    <button class="btn btn-primary" id="btn-upload-paypal">Upload PayPal CSV</button>
                </div>
                <div class="sub-tabs" id="pp-sub-tabs">
                    <button class="sub-tab active" data-subtab="detail">Detail</button>
                    <button class="sub-tab" data-subtab="assignment">Assignment</button>
                    <button class="sub-tab" data-subtab="breakdown">Category Breakdown</button>
                    <button class="sub-tab" data-subtab="reconciliation">Reconciliation</button>
                    <button class="sub-tab" data-subtab="assign-summary">Assignment Summary</button>
                </div>
                <div id="pp-detail" class="sub-panel">
                    <div id="pp-detail-cards" class="cards-row"></div>
                    <div id="pp-detail-filters"></div>
                    <div id="pp-detail-table"></div>
                </div>
                <div id="pp-assignment" class="sub-panel" style="display:none;">
                    <div id="pp-assign-actions" class="page-header" style="padding:0;margin-bottom:12px;">
                        <div></div>
                        <div style="display:flex;gap:8px;">
                            <button class="btn btn-success" id="btn-auto-assign">Auto-Assign</button>
                            <button class="btn btn-primary" id="btn-lock-range">Lock Date Range</button>
                            <button class="btn btn-secondary" id="btn-unlock-range">Unlock Date Range</button>
                        </div>
                    </div>
                    <div id="pp-assign-cards" class="cards-row"></div>
                    <div id="pp-assign-filters"></div>
                    <div id="pp-assign-table"></div>
                </div>
                <div id="pp-breakdown" class="sub-panel" style="display:none;">
                    <div id="pp-breakdown-content"></div>
                </div>
                <div id="pp-reconciliation" class="sub-panel" style="display:none;">
                    <div id="pp-reconciliation-content"></div>
                </div>
                <div id="pp-assign-summary" class="sub-panel" style="display:none;">
                    <div id="pp-summary-content"></div>
                </div>
            `;

            // Upload button
            document.getElementById('btn-upload-paypal').addEventListener('click', () => {
                Upload.showModal(
                    'Upload PayPal CSV',
                    '/api/uploads/paypal',
                    'PayPal transaction download CSV (Date, Time, TimeZone, Name, Type, ...)',
                    () => { this.initialized = false; this.init(); }
                );
            });

            // Sub-tab navigation
            document.querySelectorAll('#pp-sub-tabs .sub-tab').forEach(btn => {
                btn.addEventListener('click', () => this.switchSubTab(btn.dataset.subtab));
            });

            // Assignment action buttons
            document.getElementById('btn-auto-assign').addEventListener('click', () => this.autoAssign());
            document.getElementById('btn-lock-range').addEventListener('click', () => this.showLockModal());
            document.getElementById('btn-unlock-range').addEventListener('click', () => this.showUnlockModal());

            // Load filter options
            await this.loadFilterOptions();

            this.initDetailFilters();
            this.initAssignFilters();

            this.initialized = true;
        }

        this.switchSubTab(this.currentSubTab);
    },

    switchSubTab(name) {
        this.currentSubTab = name;
        document.querySelectorAll('#pp-sub-tabs .sub-tab').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.subtab === name);
        });
        document.getElementById('pp-detail').style.display = name === 'detail' ? '' : 'none';
        document.getElementById('pp-assignment').style.display = name === 'assignment' ? '' : 'none';
        document.getElementById('pp-breakdown').style.display = name === 'breakdown' ? '' : 'none';
        document.getElementById('pp-reconciliation').style.display = name === 'reconciliation' ? '' : 'none';
        document.getElementById('pp-assign-summary').style.display = name === 'assign-summary' ? '' : 'none';

        if (name === 'detail') this.loadDetailData();
        if (name === 'assignment') this.loadAssignmentData();
        if (name === 'breakdown') this.loadBreakdown();
        if (name === 'reconciliation') this.loadReconciliation();
        if (name === 'assign-summary') this.loadAssignmentSummary();
    },

    async loadFilterOptions() {
        try {
            const [typesResult, lsResult] = await Promise.all([
                API.get('/api/paypal/types'),
                API.get('/api/livestreams'),
            ]);
            this.types = typesResult.data || [];
            this.livestreams = lsResult.data || [];
        } catch (e) { /* silent */ }
    },

    // =========================================================
    // Detail Sub-tab
    // =========================================================
    initDetailFilters() {
        Filters.render(document.getElementById('pp-detail-filters'), [
            { type: 'date', name: 'date_from', label: 'From Date' },
            { type: 'date', name: 'date_to', label: 'To Date' },
            { type: 'text', name: 'search', label: 'Search', placeholder: 'Name, order #, title...' },
            {
                type: 'select', name: 'charge_category', label: 'Category',
                options: [
                    { value: 'purchase', label: 'Purchase' },
                    { value: 'refund', label: 'Refund' },
                    { value: 'income', label: 'Income' },
                    { value: 'offset', label: 'Offset' },
                    { value: 'auth', label: 'Auth' },
                    { value: 'withdrawal', label: 'Withdrawal' },
                ]
            },
            {
                type: 'select', name: 'assignment_status', label: 'Assignment',
                options: [
                    { value: 'unassigned', label: 'Unassigned' },
                    { value: 'partial', label: 'Partial' },
                    { value: 'assigned', label: 'Assigned' },
                    { value: 'locked', label: 'Locked' },
                ]
            },
        ], (f) => { this.detailFilters = f; this.detailPage = 1; this.loadDetailData(); });
    },

    async loadDetailData() {
        try {
            App.showLoading();
            const [listResult, summaryResult] = await Promise.all([
                API.get('/api/paypal/transactions', {
                    ...this.detailFilters,
                    page: this.detailPage,
                    per_page: 50,
                    sort: this.detailSortKey,
                    order: this.detailSortDir,
                }),
                API.get('/api/paypal/summary', this.detailFilters),
            ]);
            this.renderDetailCards(summaryResult);
            this.renderDetailTable(listResult);
        } catch (err) {
            App.toast(err.message, 'error');
        } finally {
            App.hideLoading();
        }
    },

    renderDetailCards(s) {
        const container = document.getElementById('pp-detail-cards');
        const cur = (v) => App.formatCurrency(v);

        container.innerHTML = `
            <div class="cards-group" style="flex:0 0 auto;grid-template-columns:repeat(3,1fr);">
                <div class="card">
                    <div class="card-label">Transactions</div>
                    <div class="card-value val-count">${s.total_transactions || 0}</div>
                    <div class="card-sub"><span class="val-count">${s.assignable_count || 0}</span> assignable</div>
                </div>
                <div class="card">
                    <div class="card-label">Purchases</div>
                    <div class="card-value val-count">${s.purchase_count || 0}</div>
                </div>
                <div class="card">
                    <div class="card-label">Refunds</div>
                    <div class="card-value val-count">${s.refund_count || 0}</div>
                </div>
            </div>
            <div class="cards-value-cols">
                <div class="cards-col">
                    <div class="card">
                        <div class="card-label">Total Debits</div>
                        <div class="card-value val-negative">${cur(s.total_debits)}</div>
                    </div>
                </div>
                <div class="cards-col">
                    <div class="card">
                        <div class="card-label">Total Credits</div>
                        <div class="card-value val-income">${cur(s.total_credits)}</div>
                    </div>
                </div>
                <div class="cards-col">
                    <div class="card">
                        <div class="card-label">Net Amount</div>
                        <div class="card-value ${(parseFloat(s.net_amount) || 0) >= 0 ? 'val-income' : 'val-negative'}">${cur(s.net_amount)}</div>
                    </div>
                </div>
            </div>
        `;
    },

    renderDetailTable(result) {
        DataTable.render(document.getElementById('pp-detail-table'), {
            columns: [
                {
                    key: 'transaction_date', label: 'Date', sortable: true,
                    format: (v) => App.formatDate(v)
                },
                { key: 'name', label: 'Name', sortable: true },
                { key: 'type', label: 'Type', sortable: true },
                {
                    key: 'amount', label: 'Amount', align: 'right', sortable: true,
                    render: (row) => {
                        const amt = parseFloat(row.amount);
                        const cls = amt >= 0 ? 'text-success' : 'text-danger';
                        return `<span class="${cls}">${App.formatCurrency(amt)}</span>`;
                    }
                },
                {
                    key: 'charge_category', label: 'Category', sortable: true,
                    render: (row) => {
                        const colors = {
                            purchase: 'status-completed', refund: 'status-shipped',
                            income: 'status-completed', offset: 'status-cancelled',
                            auth: 'status-cancelled', withdrawal: 'status-cancelled',
                        };
                        const cls = colors[row.charge_category] || '';
                        return `<span class="status-badge ${cls}">${row.charge_category}</span>`;
                    }
                },
                {
                    key: 'order_number', label: 'Order #', sortable: true,
                    render: (row) => row.order_number || '<span class="text-muted">-</span>'
                },
                {
                    key: 'assignment_status', label: 'Assignment', sortable: false,
                    render: (row) => {
                        const colors = {
                            unassigned: '', assigned: 'status-completed',
                            partial: 'status-shipped', locked: 'status-completed',
                        };
                        const status = row.assignment_status || 'unassigned';
                        const icon = status === 'locked' ? '&#128274; ' : '';
                        return `<span class="status-badge ${colors[status] || ''}">${icon}${status}</span>`;
                    }
                },
            ],
            data: result.data || [],
            total: result.total || 0,
            page: result.page || 1,
            perPage: result.per_page || 50,
            sortKey: this.detailSortKey,
            sortDir: this.detailSortDir,
            onSort: (key) => {
                if (this.detailSortKey === key) {
                    this.detailSortDir = this.detailSortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    this.detailSortKey = key;
                    this.detailSortDir = 'desc';
                }
                this.loadDetailData();
            },
            onPage: (p) => { this.detailPage = p; this.loadDetailData(); },
            onRowClick: (row) => this.showTransactionDetail(row.pp_transaction_id),
        });
    },

    async showTransactionDetail(id) {
        try {
            const result = await API.get(`/api/paypal/transactions/${id}`);
            const txn = result.transaction;
            const allocs = result.allocations || [];

            // Compute allocation totals
            const txnAmount = parseFloat(txn.amount) || 0;
            const allocatedSum = allocs.reduce((s, a) => s + (parseFloat(a.amount_allocated) || 0), 0);
            const remaining = +(txnAmount - allocatedSum).toFixed(2);
            const fullyAllocated = Math.abs(remaining) < 0.01;
            const isAssignable = txn.charge_category === 'purchase' || txn.charge_category === 'refund' || txn.charge_category === 'income';

            const allocHtml = allocs.length > 0
                ? allocs.map(a => `
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid #f0f0f0;">
                        <div>
                            <strong>${App.formatCurrency(a.amount_allocated)}</strong>
                            &rarr; <span class="status-badge status-completed">${a.sales_source}</span>
                            ${a.livestream_title ? ' &bull; ' + a.livestream_title : ''}
                            ${a.notes ? '<br><small class="text-muted">' + a.notes + '</small>' : ''}
                        </div>
                        <div style="display:flex;align-items:center;gap:6px;">
                            <small class="text-muted">${a.assigned_by_name} - ${App.formatDatetime(a.assigned_at)}</small>
                            ${a.is_locked == 1
                                ? '<span class="status-badge status-completed" style="margin-left:8px;">&#128274; Locked</span>'
                                : `<button class="btn btn-secondary btn-sm" onclick="PayPal.showEditAllocation(${a.allocation_id}, ${id}, '${a.sales_source}', ${a.livestream_id || 'null'}, ${parseFloat(a.amount_allocated)}, '${(a.notes || '').replace(/'/g, "\\'")}')">Edit</button>
                                   <button class="btn btn-danger btn-sm" onclick="PayPal.deleteAllocation(${a.allocation_id}, ${id})">Del</button>`
                            }
                        </div>
                    </div>`).join('')
                : '<p class="text-muted">No allocations yet.</p>';

            // Allocation summary bar
            const allocSummary = isAssignable ? `
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px;padding:8px 12px;background:${fullyAllocated ? '#e8f5e9' : '#fff3e0'};border-radius:6px;font-size:13px;">
                    <span><strong>Total:</strong> ${App.formatCurrency(txnAmount)} &nbsp; <strong>Allocated:</strong> ${App.formatCurrency(allocatedSum)}</span>
                    <span style="font-weight:700;color:${fullyAllocated ? '#2e7d32' : '#e65100'};">
                        ${fullyAllocated ? 'Fully Allocated' : 'Remaining: ' + App.formatCurrency(remaining)}
                    </span>
                </div>` : '';

            // Add Allocation form — only show if assignable AND not fully allocated
            const addFormHtml = isAssignable && !fullyAllocated ? `
                    <hr class="section-divider">
                    <h3 class="section-title">Add Allocation</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Sales Source</label>
                            <select id="pp-alloc-source">
                                <option value="Auction">Auction</option>
                                <option value="eBay">eBay</option>
                                <option value="Private-Collection">Private-Collection</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Livestream</label>
                            <select id="pp-alloc-livestream">
                                <option value="">None</option>
                                ${this.livestreams.map(ls => `<option value="${ls.livestream_id}">${(ls.stream_date || '') + ' - ' + ls.livestream_title}</option>`).join('')}
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Amount <small class="text-muted">(remaining: ${App.formatCurrency(remaining)})</small></label>
                            <input type="number" id="pp-alloc-amount" step="0.01" value="${remaining}" placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label>Notes</label>
                            <input type="text" id="pp-alloc-notes" placeholder="Optional notes">
                        </div>
                    </div>
                    <button class="btn btn-success btn-sm" onclick="PayPal.saveAllocation(${txn.pp_transaction_id})">Add Allocation</button>
            ` : '';

            App.openModal(`
                <div class="modal-header">
                    <h2>PayPal Transaction</h2>
                    <button class="modal-close" onclick="App.closeModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="detail-list">
                        <div class="detail-item"><div class="detail-label">Date</div><div class="detail-value">${App.formatDate(txn.transaction_date)} ${txn.transaction_time || ''}</div></div>
                        <div class="detail-item"><div class="detail-label">Name</div><div class="detail-value">${txn.name || '-'}</div></div>
                        <div class="detail-item"><div class="detail-label">Type</div><div class="detail-value">${txn.type}</div></div>
                        <div class="detail-item"><div class="detail-label">Status</div><div class="detail-value">${txn.status}</div></div>
                        <div class="detail-item"><div class="detail-label">Amount</div><div class="detail-value">${App.formatCurrency(txn.amount)}</div></div>
                        <div class="detail-item"><div class="detail-label">Fees</div><div class="detail-value">${App.formatCurrency(txn.fees)}</div></div>
                        <div class="detail-item"><div class="detail-label">Net</div><div class="detail-value">${App.formatCurrency(txn.net_amount)}</div></div>
                        <div class="detail-item"><div class="detail-label">Balance</div><div class="detail-value">${App.formatCurrency(txn.balance)}</div></div>
                        <div class="detail-item"><div class="detail-label">Category</div><div class="detail-value">${txn.charge_category}</div></div>
                        <div class="detail-item"><div class="detail-label">Order #</div><div class="detail-value">${txn.order_number || '-'}</div></div>
                        <div class="detail-item"><div class="detail-label">Transaction ID</div><div class="detail-value">${txn.paypal_txn_id}</div></div>
                        ${txn.item_title ? `<div class="detail-item full-width"><div class="detail-label">Item Title</div><div class="detail-value">${txn.item_title}</div></div>` : ''}
                    </div>

                    <hr class="section-divider">
                    <h3 class="section-title">Allocations</h3>
                    ${allocHtml}
                    ${allocSummary}
                    ${addFormHtml}

                    <hr class="section-divider">
                    <div style="display:flex;justify-content:flex-end;">
                        <button class="btn btn-danger btn-sm" onclick="PayPal.deleteTransaction(${txn.pp_transaction_id})">Delete Transaction</button>
                    </div>
                </div>
            `);
        } catch (err) {
            App.toast(err.message, 'error');
        }
    },

    async saveAllocation(ppTxnId) {
        const source = document.getElementById('pp-alloc-source').value;
        const livestreamId = document.getElementById('pp-alloc-livestream').value;
        const amount = parseFloat(document.getElementById('pp-alloc-amount').value);
        const notes = document.getElementById('pp-alloc-notes').value.trim();

        if (!amount) {
            App.toast('Amount is required', 'error');
            return;
        }

        try {
            await API.post('/api/paypal/allocations', {
                pp_transaction_id: ppTxnId,
                sales_source: source,
                livestream_id: livestreamId || null,
                amount_allocated: amount,
                notes: notes || null,
            });
            App.toast('Allocation added', 'success');
            await this.showTransactionDetail(ppTxnId);
            this.loadAssignmentData();
            this.loadDetailData();
        } catch (err) {
            App.toast(err.message, 'error');
        }
    },

    async deleteAllocation(allocId, ppTxnId) {
        if (!confirm('Delete this allocation?')) return;
        try {
            await API.del(`/api/paypal/allocations/${allocId}`);
            App.toast('Allocation deleted', 'success');
            await this.showTransactionDetail(ppTxnId);
            this.loadAssignmentData();
            this.loadDetailData();
        } catch (err) {
            App.toast(err.message, 'error');
        }
    },

    // =========================================================
    // Assignment Sub-tab
    // =========================================================
    initAssignFilters() {
        Filters.render(document.getElementById('pp-assign-filters'), [
            { type: 'date', name: 'date_from', label: 'From Date' },
            { type: 'date', name: 'date_to', label: 'To Date' },
            {
                type: 'select', name: 'assignment_status', label: 'Status',
                options: [
                    { value: 'unassigned', label: 'Unassigned' },
                    { value: 'partial', label: 'Partial' },
                    { value: 'assigned', label: 'Assigned' },
                    { value: 'locked', label: 'Locked' },
                ]
            },
            {
                type: 'select', name: 'charge_category', label: 'Category',
                value: 'purchase',
                options: [
                    { value: 'purchase', label: 'Purchase' },
                    { value: 'refund', label: 'Refund' },
                    { value: 'income', label: 'Income' },
                ]
            },
        ], (f) => { this.assignFilters = f; this.assignPage = 1; this.loadAssignmentData(); });

        // Default to showing purchases
        this.assignFilters = { charge_category: 'purchase' };
    },

    async loadAssignmentData() {
        try {
            App.showLoading();

            // Only show assignable categories
            const filters = { ...this.assignFilters };
            if (!filters.charge_category) {
                filters.charge_category = '';
            }

            const [listResult, summaryResult] = await Promise.all([
                API.get('/api/paypal/transactions', {
                    ...filters,
                    page: this.assignPage,
                    per_page: 50,
                    sort: this.assignSortKey,
                    order: this.assignSortDir,
                }),
                API.get('/api/paypal/summary', filters),
            ]);
            this.renderAssignmentCards(summaryResult);
            this.renderAssignmentTable(listResult);
        } catch (err) {
            App.toast(err.message, 'error');
        } finally {
            App.hideLoading();
        }
    },

    renderAssignmentCards(s) {
        const container = document.getElementById('pp-assign-cards');
        const cur = (v) => App.formatCurrency(v);

        container.innerHTML = `
            <div class="cards-group" style="flex:0 0 auto;grid-template-columns:repeat(4,1fr);">
                <div class="card">
                    <div class="card-label">Unassigned</div>
                    <div class="card-value val-negative">${s.unassigned_count || 0}</div>
                    <div class="card-sub">${cur(s.unassigned_total)}</div>
                </div>
                <div class="card">
                    <div class="card-label">Partial</div>
                    <div class="card-value val-expense">${s.partial_count || 0}</div>
                </div>
                <div class="card">
                    <div class="card-label">Assigned</div>
                    <div class="card-value val-income">${s.assigned_count || 0}</div>
                    <div class="card-sub">${cur(s.assigned_total)}</div>
                </div>
                <div class="card">
                    <div class="card-label">Locked</div>
                    <div class="card-value val-count">${s.locked_count || 0}</div>
                    <div class="card-sub">${cur(s.locked_total)}</div>
                </div>
            </div>
        `;
    },

    renderAssignmentTable(result) {
        DataTable.render(document.getElementById('pp-assign-table'), {
            columns: [
                {
                    key: 'transaction_date', label: 'Date', sortable: true,
                    format: (v) => App.formatDate(v)
                },
                { key: 'name', label: 'Name', sortable: true },
                {
                    key: 'amount', label: 'Amount', align: 'right', sortable: true,
                    render: (row) => {
                        const amt = parseFloat(row.amount);
                        const cls = amt >= 0 ? 'text-success' : 'text-danger';
                        return `<span class="${cls}">${App.formatCurrency(amt)}</span>`;
                    }
                },
                {
                    key: 'order_number', label: 'Order #', sortable: true,
                    render: (row) => row.order_number || '<span class="text-muted">-</span>'
                },
                {
                    key: 'allocated_amount', label: 'Allocated', align: 'right', sortable: false,
                    render: (row) => {
                        const alloc = parseFloat(row.allocated_amount) || 0;
                        if (alloc === 0) return '<span class="text-muted">-</span>';
                        return App.formatCurrency(alloc);
                    }
                },
                {
                    key: 'assignment_status', label: 'Status', sortable: false,
                    render: (row) => {
                        const colors = {
                            unassigned: '', assigned: 'status-completed',
                            partial: 'status-shipped', locked: 'status-completed',
                        };
                        const status = row.assignment_status || 'unassigned';
                        const icon = status === 'locked' ? '&#128274; ' : '';
                        return `<span class="status-badge ${colors[status] || ''}">${icon}${status}</span>`;
                    }
                },
                {
                    key: 'actions', label: 'Actions', sortable: false,
                    render: (row) => {
                        if (row.assignment_status === 'locked') return '';
                        const div = document.createElement('div');
                        div.style.display = 'flex';
                        div.style.gap = '4px';

                        const assignBtn = document.createElement('button');
                        assignBtn.className = 'btn btn-success btn-sm';
                        assignBtn.textContent = 'Assign';
                        assignBtn.addEventListener('click', (e) => {
                            e.stopPropagation();
                            PayPal.showTransactionDetail(row.pp_transaction_id);
                        });
                        div.appendChild(assignBtn);

                        return div;
                    }
                },
            ],
            data: result.data || [],
            total: result.total || 0,
            page: result.page || 1,
            perPage: result.per_page || 50,
            sortKey: this.assignSortKey,
            sortDir: this.assignSortDir,
            onSort: (key) => {
                if (this.assignSortKey === key) {
                    this.assignSortDir = this.assignSortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    this.assignSortKey = key;
                    this.assignSortDir = 'desc';
                }
                this.loadAssignmentData();
            },
            onPage: (p) => { this.assignPage = p; this.loadAssignmentData(); },
            onRowClick: (row) => this.showTransactionDetail(row.pp_transaction_id),
        });
    },

    // =========================================================
    // Auto-Assign, Lock, Unlock
    // =========================================================
    async autoAssign() {
        if (!confirm('Auto-assign will match unassigned PayPal purchases to auctions using eBay order numbers. Continue?')) return;

        try {
            App.showLoading();
            const result = await API.post('/api/paypal/auto-assign', {});
            App.toast(result.message, 'success');
            this.loadAssignmentData();
            this.loadDetailData();
        } catch (err) {
            App.toast(err.message, 'error');
        } finally {
            App.hideLoading();
        }
    },

    showLockModal() {
        App.openModal(`
            <div class="modal-header">
                <h2>Lock Allocations</h2>
                <button class="modal-close" onclick="App.closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p>Lock (sign off) all allocations within a date range. Locked allocations cannot be edited or deleted.</p>
                <div class="form-row">
                    <div class="form-group">
                        <label>From Date *</label>
                        <input type="date" id="pp-lock-from">
                    </div>
                    <div class="form-group">
                        <label>To Date *</label>
                        <input type="date" id="pp-lock-to">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="App.closeModal()">Cancel</button>
                <button class="btn btn-primary" id="pp-lock-confirm">Lock Allocations</button>
            </div>
        `);

        document.getElementById('pp-lock-confirm').addEventListener('click', async () => {
            const dateFrom = document.getElementById('pp-lock-from').value;
            const dateTo = document.getElementById('pp-lock-to').value;
            if (!dateFrom || !dateTo) {
                App.toast('Both dates are required', 'error');
                return;
            }
            try {
                const result = await API.post('/api/paypal/lock', { date_from: dateFrom, date_to: dateTo });
                App.toast(result.message, 'success');
                App.closeModal();
                this.loadAssignmentData();
                this.loadDetailData();
            } catch (err) {
                App.toast(err.message, 'error');
            }
        });
    },

    showUnlockModal() {
        App.openModal(`
            <div class="modal-header">
                <h2>Unlock Allocations</h2>
                <button class="modal-close" onclick="App.closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p>Unlock allocations within a date range to allow editing. This action requires admin access.</p>
                <div class="form-row">
                    <div class="form-group">
                        <label>From Date *</label>
                        <input type="date" id="pp-unlock-from">
                    </div>
                    <div class="form-group">
                        <label>To Date *</label>
                        <input type="date" id="pp-unlock-to">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="App.closeModal()">Cancel</button>
                <button class="btn btn-primary" id="pp-unlock-confirm">Unlock Allocations</button>
            </div>
        `);

        document.getElementById('pp-unlock-confirm').addEventListener('click', async () => {
            const dateFrom = document.getElementById('pp-unlock-from').value;
            const dateTo = document.getElementById('pp-unlock-to').value;
            if (!dateFrom || !dateTo) {
                App.toast('Both dates are required', 'error');
                return;
            }
            try {
                const result = await API.post('/api/paypal/unlock', { date_from: dateFrom, date_to: dateTo });
                App.toast(result.message, 'success');
                App.closeModal();
                this.loadAssignmentData();
                this.loadDetailData();
            } catch (err) {
                App.toast(err.message, 'error');
            }
        });
    },

    // =========================================================
    // Delete Transaction
    // =========================================================
    async deleteTransaction(ppTxnId) {
        if (!confirm('Delete this PayPal transaction? This will also remove all allocations. This cannot be undone.')) return;
        try {
            await API.del(`/api/paypal/transactions/${ppTxnId}`);
            App.toast('Transaction deleted', 'success');
            App.closeModal();
            this.loadDetailData();
            this.loadAssignmentData();
        } catch (err) {
            App.toast(err.message, 'error');
        }
    },

    // =========================================================
    // Edit Allocation
    // =========================================================
    showEditAllocation(allocId, ppTxnId, source, livestreamId, amount, notes) {
        App.openModal(`
            <div class="modal-header">
                <h2>Edit Allocation</h2>
                <button class="modal-close" onclick="App.closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>Sales Source</label>
                        <select id="pp-edit-alloc-source">
                            <option value="Auction" ${source === 'Auction' ? 'selected' : ''}>Auction</option>
                            <option value="eBay" ${source === 'eBay' ? 'selected' : ''}>eBay</option>
                            <option value="Private-Collection" ${source === 'Private-Collection' ? 'selected' : ''}>Private-Collection</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Livestream</label>
                        <select id="pp-edit-alloc-livestream">
                            <option value="">None</option>
                            ${this.livestreams.map(ls => `<option value="${ls.livestream_id}" ${ls.livestream_id == livestreamId ? 'selected' : ''}>${(ls.stream_date || '') + ' - ' + ls.livestream_title}</option>`).join('')}
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Amount</label>
                        <input type="number" id="pp-edit-alloc-amount" step="0.01" value="${amount}">
                    </div>
                    <div class="form-group">
                        <label>Notes</label>
                        <input type="text" id="pp-edit-alloc-notes" value="${notes || ''}">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="App.closeModal()">Cancel</button>
                <button class="btn btn-primary" id="pp-edit-alloc-save">Save Changes</button>
            </div>
        `);

        document.getElementById('pp-edit-alloc-save').addEventListener('click', async () => {
            const data = {
                sales_source: document.getElementById('pp-edit-alloc-source').value,
                livestream_id: document.getElementById('pp-edit-alloc-livestream').value || null,
                amount_allocated: parseFloat(document.getElementById('pp-edit-alloc-amount').value),
                notes: document.getElementById('pp-edit-alloc-notes').value.trim() || null,
            };
            if (!data.amount_allocated) {
                App.toast('Amount is required', 'error');
                return;
            }
            try {
                await API.put(`/api/paypal/allocations/${allocId}`, data);
                App.toast('Allocation updated', 'success');
                App.closeModal();
                this.showTransactionDetail(ppTxnId);
                this.loadAssignmentData();
                this.loadDetailData();
            } catch (err) {
                App.toast(err.message, 'error');
            }
        });
    },

    // =========================================================
    // Category Breakdown Sub-tab
    // =========================================================
    async loadBreakdown() {
        const container = document.getElementById('pp-breakdown-content');
        try {
            App.showLoading();
            const result = await API.get('/api/paypal/breakdown');
            this.renderBreakdown(result);
        } catch (err) {
            container.innerHTML = '<p class="text-muted" style="padding:24px;">Unable to load breakdown data.</p>';
            App.toast(err.message, 'error');
        } finally {
            App.hideLoading();
        }
    },

    renderBreakdown(data) {
        const container = document.getElementById('pp-breakdown-content');
        const cur = (v) => App.formatCurrency(v);
        const byCategory = data.by_category || [];
        const byType = data.by_type || [];
        const byStatus = data.by_status || [];

        // Category summary cards
        const catColors = {
            purchase: '#e53935', refund: '#ff9800', income: '#2e7d32',
            offset: '#546e7a', auth: '#7e57c2', withdrawal: '#d84315'
        };
        let catCardsHtml = '<div class="cards-row"><div class="cards-group" style="flex:0 0 auto;grid-template-columns:repeat(' + Math.max(byCategory.length, 1) + ',1fr);">';
        byCategory.forEach(c => {
            const color = catColors[c.charge_category] || '#333';
            catCardsHtml += `
                <div class="card">
                    <div class="card-label" style="text-transform:capitalize;">${c.charge_category}</div>
                    <div class="card-value val-count">${c.transaction_count}</div>
                    <div class="card-sub" style="color:${color};font-weight:600;">${cur(c.total_amount)}</div>
                    ${c.total_fees != 0 ? `<div class="card-sub text-muted">Fees: ${cur(c.total_fees)}</div>` : ''}
                </div>`;
        });
        catCardsHtml += '</div></div>';

        // Type breakdown table grouped by category
        let typeTableHtml = '<div class="table-container"><table class="data-table"><thead><tr>';
        typeTableHtml += '<th>Category</th><th>Type</th><th>Status</th>';
        typeTableHtml += '<th style="text-align:right">Count</th>';
        typeTableHtml += '<th style="text-align:right">Amount</th>';
        typeTableHtml += '<th style="text-align:right">Fees</th>';
        typeTableHtml += '<th style="text-align:right">Net</th>';
        typeTableHtml += '</tr></thead><tbody>';

        let prevCat = '';
        byType.forEach(row => {
            const catLabel = row.charge_category !== prevCat ? `<strong style="text-transform:capitalize;">${row.charge_category}</strong>` : '';
            prevCat = row.charge_category;
            const amtClass = parseFloat(row.total_amount) >= 0 ? 'text-success' : 'text-danger';
            typeTableHtml += `<tr>
                <td>${catLabel}</td>
                <td>${row.type}</td>
                <td><span class="status-badge ${row.status === 'Completed' ? 'status-completed' : ''}">${row.status}</span></td>
                <td style="text-align:right">${row.transaction_count}</td>
                <td style="text-align:right"><span class="${amtClass}">${cur(row.total_amount)}</span></td>
                <td style="text-align:right">${cur(row.total_fees)}</td>
                <td style="text-align:right">${cur(row.total_net)}</td>
            </tr>`;
        });
        typeTableHtml += '</tbody></table></div>';

        // Status summary
        let statusHtml = '<div class="cards-row"><div class="cards-group" style="flex:0 0 auto;grid-template-columns:repeat(' + Math.max(byStatus.length, 1) + ',1fr);">';
        byStatus.forEach(s => {
            statusHtml += `
                <div class="card">
                    <div class="card-label">${s.status}</div>
                    <div class="card-value val-count">${s.transaction_count}</div>
                    <div class="card-sub">${cur(s.total_amount)}</div>
                </div>`;
        });
        statusHtml += '</div></div>';

        container.innerHTML = `
            <div class="mt-4">
                <h3 class="section-title">By Category</h3>
                ${catCardsHtml}
            </div>
            <div class="mt-4">
                <h3 class="section-title">By Status</h3>
                ${statusHtml}
            </div>
            <div class="mt-4">
                <h3 class="section-title">Detailed Type Breakdown</h3>
                <p class="text-muted" style="font-size:12px;margin-bottom:8px;">
                    Shows how each PayPal transaction type is classified into categories.
                    Purchases = money out for supplies/shipping. Income = money in. Offsets = bank transfers/card deposits.
                </p>
                ${typeTableHtml}
            </div>
        `;
    },

    // =========================================================
    // Reconciliation Sub-tab
    // =========================================================
    async loadReconciliation() {
        const container = document.getElementById('pp-reconciliation-content');
        try {
            App.showLoading();
            const result = await API.get('/api/paypal/reconciliation');
            this.renderReconciliation(result);
        } catch (err) {
            container.innerHTML = '<p class="text-muted" style="padding:24px;">Unable to load reconciliation data.</p>';
            App.toast(err.message, 'error');
        } finally {
            App.hideLoading();
        }
    },

    renderReconciliation(data) {
        const container = document.getElementById('pp-reconciliation-content');
        const cur = (v) => App.formatCurrency(v);
        const monthly = data.monthly || [];
        const totals = data.totals || {};

        // Grand totals cards
        let totalsHtml = `<div class="cards-row">
            <div class="cards-group" style="flex:0 0 auto;grid-template-columns:repeat(5,1fr);">
                <div class="card">
                    <div class="card-label">Purchases (Out)</div>
                    <div class="card-value text-danger">${cur(totals.purchases)}</div>
                    <div class="card-sub text-muted">${totals.transaction_count || 0} total txns</div>
                </div>
                <div class="card">
                    <div class="card-label">Income (In)</div>
                    <div class="card-value text-success">${cur(totals.income)}</div>
                </div>
                <div class="card">
                    <div class="card-label">Refunds</div>
                    <div class="card-value" style="color:#ff9800;">${cur(totals.refunds)}</div>
                </div>
                <div class="card">
                    <div class="card-label">Fees</div>
                    <div class="card-value text-danger">${cur(totals.total_fees)}</div>
                </div>
                <div class="card">
                    <div class="card-label">Net PayPal</div>
                    <div class="card-value ${(totals.total_net || 0) >= 0 ? 'text-success' : 'text-danger'}">${cur(totals.total_net)}</div>
                </div>
            </div>
        </div>`;

        // Monthly table
        let tableHtml = '<div class="table-container"><table class="data-table"><thead><tr>';
        tableHtml += '<th>Month</th>';
        tableHtml += '<th style="text-align:right">Txns</th>';
        tableHtml += '<th style="text-align:right">Purchases</th>';
        tableHtml += '<th style="text-align:right">Refunds</th>';
        tableHtml += '<th style="text-align:right">Income</th>';
        tableHtml += '<th style="text-align:right">Offsets</th>';
        tableHtml += '<th style="text-align:right">Withdrawals</th>';
        tableHtml += '<th style="text-align:right">Fees</th>';
        tableHtml += '<th style="text-align:right">Net</th>';
        tableHtml += '</tr></thead><tbody>';

        monthly.forEach(m => {
            const netClass = parseFloat(m.total_net) >= 0 ? 'text-success' : 'text-danger';
            tableHtml += `<tr>
                <td><strong>${this._recoMonthLabel(m.month)}</strong></td>
                <td style="text-align:right">${m.transaction_count}</td>
                <td style="text-align:right;color:#e53935;">${cur(m.purchases)}</td>
                <td style="text-align:right;color:#ff9800;">${m.refunds != 0 ? cur(m.refunds) : '-'}</td>
                <td style="text-align:right;color:#2e7d32;">${m.income != 0 ? cur(m.income) : '-'}</td>
                <td style="text-align:right">${m.offsets != 0 ? cur(m.offsets) : '-'}</td>
                <td style="text-align:right">${m.withdrawals != 0 ? cur(m.withdrawals) : '-'}</td>
                <td style="text-align:right;color:#e53935;">${m.total_fees != 0 ? cur(m.total_fees) : '-'}</td>
                <td style="text-align:right"><strong class="${netClass}">${cur(m.total_net)}</strong></td>
            </tr>`;
        });

        // Totals row
        if (monthly.length > 0) {
            const netClass = (totals.total_net || 0) >= 0 ? 'text-success' : 'text-danger';
            tableHtml += `<tr style="background:#f0f2f5;font-weight:700;">
                <td>TOTAL</td>
                <td style="text-align:right">${totals.transaction_count}</td>
                <td style="text-align:right;color:#e53935;">${cur(totals.purchases)}</td>
                <td style="text-align:right;color:#ff9800;">${cur(totals.refunds)}</td>
                <td style="text-align:right;color:#2e7d32;">${cur(totals.income)}</td>
                <td style="text-align:right">${cur(totals.offsets)}</td>
                <td style="text-align:right">${cur(totals.withdrawals)}</td>
                <td style="text-align:right;color:#e53935;">${cur(totals.total_fees)}</td>
                <td style="text-align:right"><strong class="${netClass}">${cur(totals.total_net)}</strong></td>
            </tr>`;
        }

        tableHtml += '</tbody></table></div>';

        container.innerHTML = `
            <div class="mt-4">
                <h3 class="section-title">PayPal Financial Summary</h3>
                <p class="text-muted" style="font-size:12px;margin-bottom:8px;">
                    Monthly breakdown of all PayPal money flows. Purchases = money leaving (supplies, shipping labels, eBay payments).
                    Income = money entering. Offsets = bank transfers and card deposits (neutral). This data feeds into the Financial Summary.
                </p>
                ${totalsHtml}
            </div>
            <div class="mt-4">
                <h3 class="section-title">Monthly Reconciliation</h3>
                ${tableHtml}
            </div>
        `;
    },

    _recoMonthLabel(monthStr) {
        const [yr, mo] = monthStr.split('-');
        const names = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                        'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        return names[parseInt(mo)] + ' ' + yr;
    },

    // =========================================================
    // Assignment Summary Sub-tab
    // =========================================================
    async loadAssignmentSummary() {
        const container = document.getElementById('pp-summary-content');
        try {
            App.showLoading();
            const result = await API.get('/api/paypal/assignments/summary');
            this.renderAssignmentSummary(result);
        } catch (err) {
            container.innerHTML = '<p class="text-muted" style="padding:24px;">Unable to load assignment summary.</p>';
            App.toast(err.message, 'error');
        } finally {
            App.hideLoading();
        }
    },

    renderAssignmentSummary(data) {
        const container = document.getElementById('pp-summary-content');
        const cur = (v) => App.formatCurrency(v);
        const bySource = data.by_source || [];
        const byMonth = data.by_month || [];

        // By-source summary cards
        let sourceCardsHtml = '<div class="cards-row"><div class="cards-group" style="flex:0 0 auto;grid-template-columns:repeat(' + Math.max(bySource.length, 1) + ',1fr);">';
        bySource.forEach(s => {
            const pct = s.allocation_count > 0 ? Math.round((s.locked_count / s.allocation_count) * 100) : 0;
            sourceCardsHtml += `
                <div class="card">
                    <div class="card-label">${s.sales_source}</div>
                    <div class="card-value val-count">${s.allocation_count}</div>
                    <div class="card-sub">${cur(s.total_allocated)}</div>
                    <div class="card-sub text-muted">${s.locked_count} locked (${pct}%)</div>
                </div>`;
        });
        sourceCardsHtml += '</div></div>';

        // Monthly breakdown table
        let monthTableHtml = '';
        if (byMonth.length > 0) {
            monthTableHtml = '<div class="mt-4"><h3 class="section-title">Monthly Breakdown</h3>';
            monthTableHtml += '<div class="table-container"><table class="data-table"><thead><tr>';
            monthTableHtml += '<th>Month</th><th style="text-align:right">Transactions</th><th style="text-align:right">Total Amount</th><th style="text-align:right">Assigned</th><th style="text-align:right">Assignment %</th>';
            monthTableHtml += '</tr></thead><tbody>';

            byMonth.forEach(m => {
                const pct = m.transaction_count > 0 ? Math.round((m.assigned_count / m.transaction_count) * 100) : 0;
                const pctClass = pct === 100 ? 'text-success' : (pct >= 50 ? 'val-expense' : 'text-danger');
                monthTableHtml += `<tr>
                    <td><strong>${m.month}</strong></td>
                    <td style="text-align:right">${m.transaction_count}</td>
                    <td style="text-align:right">${cur(m.total_amount)}</td>
                    <td style="text-align:right">${m.assigned_count}</td>
                    <td style="text-align:right"><span class="${pctClass}">${pct}%</span></td>
                </tr>`;
            });

            monthTableHtml += '</tbody></table></div></div>';
        }

        container.innerHTML = `
            <div class="mt-4">
                <h3 class="section-title">Allocations by Source</h3>
                ${sourceCardsHtml}
            </div>
            ${monthTableHtml}
        `;
    },
};
