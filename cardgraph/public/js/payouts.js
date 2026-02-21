/**
 * Card Graph — Payouts Tab
 */
const Payouts = {
    initialized: false,
    filters: {},
    page: 1,
    sortKey: 'date_initiated',
    sortDir: 'desc',

    async init() {
        const panel = document.getElementById('tab-payouts');

        if (!this.initialized) {
            panel.innerHTML = `
                <div class="page-header">
                    <h1>Payouts <span id="payouts-filter-desc" class="filter-description"></span></h1>
                    <div>
                        <button class="btn btn-success" id="btn-add-payout">Add Payout</button>
                        <button class="btn btn-primary" id="btn-upload-payouts" style="margin-left:8px;">Upload CSV</button>
                    </div>
                </div>
                <div id="payouts-summary" class="payout-summary"></div>
                <div id="payouts-filters"></div>
                <div id="payouts-table"></div>
            `;

            document.getElementById('btn-add-payout').addEventListener('click', () => this.showForm());
            document.getElementById('btn-upload-payouts').addEventListener('click', () => {
                Upload.showModal(
                    'Upload Payouts CSV',
                    '/api/uploads/payouts',
                    'Columns: Amount, Destination, Date Initiated, Arrival Date, Status',
                    () => { this.initialized = false; this.init(); }
                );
            });

            Filters.render(document.getElementById('payouts-filters'), [
                {
                    type: 'select', name: 'status', label: 'Status',
                    options: [
                        { value: 'In Progress', label: 'In Progress' },
                        { value: 'Completed', label: 'Completed' },
                        { value: 'Failed', label: 'Failed' },
                    ]
                },
                { type: 'date', name: 'date_from', label: 'From Date' },
                { type: 'date', name: 'date_to', label: 'To Date' },
            ], (f) => { this.filters = f; this.page = 1; this.loadData(); },
            { descriptionEl: 'payouts-filter-desc' });

            this.initialized = true;
        }

        this.loadData();
    },

    async loadData() {
        try {
            App.showLoading();
            const params = {
                ...this.filters,
                page: this.page,
                per_page: 50,
                sort: this.sortKey,
                order: this.sortDir,
            };
            const result = await API.get('/api/payouts', params);
            this.renderSummary(result.summary || {});
            this.renderTable(result);
        } catch (err) {
            App.toast(err.message, 'error');
        } finally {
            App.hideLoading();
        }
    },

    renderSummary(s) {
        const el = document.getElementById('payouts-summary');
        const cur = App.formatCurrency;
        el.innerHTML = `
            <span class="val-income">${cur(s.total_amount)}</span> total
            <span class="payout-summary-sep">|</span>
            <span class="val-count">${s.payout_count}</span> payouts
            <span class="payout-summary-sep">|</span>
            <span class="val-income">${cur(s.completed_amount)}</span> completed (${s.completed_count})
            <span class="payout-summary-sep">|</span>
            <span class="val-count">${s.in_progress_count}</span> in progress
        `;
    },

    renderTable(result) {
        const container = document.getElementById('payouts-table');

        DataTable.render(container, {
            columns: [
                {
                    key: 'amount', label: 'Amount', align: 'right', sortable: true,
                    format: (v) => App.formatCurrency(v)
                },
                { key: 'destination', label: 'Destination', sortable: true },
                {
                    key: 'date_initiated', label: 'Date Initiated', sortable: true,
                    format: (v) => App.formatDate(v)
                },
                {
                    key: 'arrival_date', label: 'Arrival Date', sortable: true,
                    format: (v) => App.formatDate(v)
                },
                {
                    key: 'status', label: 'Status', sortable: true,
                    render: (row) => {
                        const cls = App.statusClass(row.status);
                        return `<span class="status-badge ${cls}">${row.status}</span>`;
                    }
                },
                { key: 'entered_by_name', label: 'Entered By', sortable: false },
                {
                    key: 'actions', label: 'Actions', sortable: false,
                    render: (row) => {
                        const div = document.createElement('div');
                        div.style.display = 'flex';
                        div.style.gap = '4px';

                        const editBtn = document.createElement('button');
                        editBtn.className = 'btn btn-secondary btn-sm';
                        editBtn.textContent = 'Edit';
                        editBtn.addEventListener('click', (e) => {
                            e.stopPropagation();
                            Payouts.showForm(row);
                        });

                        const delBtn = document.createElement('button');
                        delBtn.className = 'btn btn-danger btn-sm';
                        delBtn.textContent = 'Del';
                        delBtn.addEventListener('click', (e) => {
                            e.stopPropagation();
                            Payouts.deletePayout(row.payout_id);
                        });

                        div.appendChild(editBtn);
                        div.appendChild(delBtn);
                        return div;
                    }
                },
            ],
            data: result.data || [],
            total: result.total || 0,
            page: result.page || 1,
            perPage: result.per_page || 50,
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
        });
    },

    showForm(existing = null) {
        const isEdit = !!existing;
        const title = isEdit ? 'Edit Payout' : 'Add Payout';

        App.openModal(`
            <div class="modal-header">
                <h2>${title}</h2>
                <button class="modal-close" onclick="App.closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>Amount *</label>
                        <input type="number" id="payout-amount" step="0.01" min="0"
                               value="${existing ? existing.amount : ''}" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label>Destination *</label>
                        <input type="text" id="payout-destination"
                               value="${existing ? existing.destination : ''}" placeholder="e.g., Bank Account">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Date Initiated *</label>
                        <input type="date" id="payout-date-initiated"
                               value="${existing ? existing.date_initiated : ''}">
                    </div>
                    <div class="form-group">
                        <label>Arrival Date</label>
                        <input type="date" id="payout-arrival-date"
                               value="${existing ? (existing.arrival_date || '') : ''}">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Status</label>
                        <select id="payout-status">
                            <option value="In Progress" ${existing && existing.status === 'In Progress' ? 'selected' : ''}>In Progress</option>
                            <option value="Completed" ${existing && existing.status === 'Completed' ? 'selected' : ''}>Completed</option>
                            <option value="Failed" ${existing && existing.status === 'Failed' ? 'selected' : ''}>Failed</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea id="payout-notes" rows="2">${existing ? (existing.notes || '') : ''}</textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="App.closeModal()">Cancel</button>
                <button class="btn btn-primary" id="payout-save-btn">${isEdit ? 'Save Changes' : 'Add Payout'}</button>
            </div>
        `);

        document.getElementById('payout-save-btn').addEventListener('click', () => {
            this.savePayout(existing ? existing.payout_id : null);
        });
    },

    async savePayout(payoutId) {
        const data = {
            amount: parseFloat(document.getElementById('payout-amount').value),
            destination: document.getElementById('payout-destination').value.trim(),
            date_initiated: document.getElementById('payout-date-initiated').value,
            arrival_date: document.getElementById('payout-arrival-date').value || null,
            status: document.getElementById('payout-status').value,
            notes: document.getElementById('payout-notes').value.trim(),
        };

        if (!data.amount || !data.destination || !data.date_initiated) {
            App.toast('Amount, destination, and date are required', 'error');
            return;
        }

        try {
            if (payoutId) {
                await API.put(`/api/payouts/${payoutId}`, data);
                App.toast('Payout updated', 'success');
            } else {
                await API.post('/api/payouts', data);
                App.toast('Payout added', 'success');
            }
            App.closeModal();
            this.loadData();
        } catch (err) {
            App.toast(err.message, 'error');
        }
    },

    async deletePayout(payoutId) {
        if (!confirm('Delete this payout?')) return;

        try {
            await API.del(`/api/payouts/${payoutId}`);
            App.toast('Payout deleted', 'success');
            this.loadData();
        } catch (err) {
            App.toast(err.message, 'error');
        }
    }
};
