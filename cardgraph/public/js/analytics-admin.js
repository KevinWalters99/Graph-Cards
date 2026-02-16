/**
 * Card Graph — Analytics Standards (Maintenance Sub-tab)
 * Admin-only: metric definitions + milestones CRUD.
 */
const AnalyticsAdmin = {
    initialized: false,
    metrics: [],
    milestones: [],

    init() {
        const panel = document.getElementById('maint-panel-analytics-standards');
        if (!panel) return;

        if (!this.initialized) {
            panel.innerHTML = `
                <div class="mb-4">
                    <h3 class="section-title">Metric Standards</h3>
                    <p class="text-muted" style="font-size:12px;margin-bottom:12px;">
                        Defines how each analytics metric is calculated. Click Edit to update descriptions.
                    </p>
                    <div id="aa-metrics-table"></div>
                </div>
                <div class="mb-4">
                    <h3 class="section-title">Milestones</h3>
                    <div class="mb-2">
                        <button class="btn btn-success" id="aa-add-milestone-btn">Add Milestone</button>
                    </div>
                    <div id="aa-milestones-table"></div>
                </div>
            `;

            document.getElementById('aa-add-milestone-btn').addEventListener('click', () => {
                this.showMilestoneForm();
            });

            this.initialized = true;
        }

        this.loadMetrics();
        this.loadMilestones();
    },

    // =========================================================
    // Metric Standards
    // =========================================================
    async loadMetrics() {
        try {
            const result = await API.get('/api/analytics/metrics');
            this.metrics = result.data || [];
            this.renderMetrics();
        } catch (err) {
            App.toast('Failed to load metrics: ' + err.message, 'error');
        }
    },

    renderMetrics() {
        DataTable.render(document.getElementById('aa-metrics-table'), {
            columns: [
                { key: 'display_order', label: '#', sortable: false, align: 'center' },
                { key: 'metric_name', label: 'Metric', sortable: false },
                { key: 'unit_type', label: 'Unit', sortable: false },
                {
                    key: 'description', label: 'Description', sortable: false,
                    render: (row) => {
                        const text = row.description || '-';
                        return text.length > 80 ? text.substring(0, 80) + '...' : text;
                    }
                },
                {
                    key: 'method', label: 'Calculation Method', sortable: false,
                    render: (row) => {
                        const text = row.method || '-';
                        return text.length > 80 ? text.substring(0, 80) + '...' : text;
                    }
                },
                {
                    key: 'actions', label: 'Actions', sortable: false,
                    render: (row) => {
                        const btn = document.createElement('button');
                        btn.className = 'btn btn-secondary btn-sm';
                        btn.textContent = 'Edit';
                        btn.addEventListener('click', (e) => {
                            e.stopPropagation();
                            AnalyticsAdmin.showMetricEditForm(row);
                        });
                        return btn;
                    }
                },
            ],
            data: this.metrics,
            total: this.metrics.length,
            page: 1,
            perPage: 100,
        });
    },

    showMetricEditForm(metric) {
        App.openModal(`
            <div class="modal-header">
                <h2>Edit Metric: ${metric.metric_name}</h2>
                <button class="modal-close" onclick="App.closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Description</label>
                    <textarea id="aa-metric-desc" rows="3">${metric.description || ''}</textarea>
                </div>
                <div class="form-group">
                    <label>Calculation Method</label>
                    <textarea id="aa-metric-method" rows="3">${metric.method || ''}</textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="App.closeModal()">Cancel</button>
                <button class="btn btn-primary" id="aa-metric-save-btn">Save Changes</button>
            </div>
        `);

        document.getElementById('aa-metric-save-btn').addEventListener('click', () => {
            this.saveMetric(metric.metric_id);
        });
    },

    async saveMetric(metricId) {
        try {
            await API.put(`/api/analytics/metrics/${metricId}`, {
                description: document.getElementById('aa-metric-desc').value.trim(),
                method: document.getElementById('aa-metric-method').value.trim(),
            });
            App.toast('Metric updated', 'success');
            App.closeModal();
            this.loadMetrics();
        } catch (err) {
            App.toast(err.message, 'error');
        }
    },

    // =========================================================
    // Milestones
    // =========================================================
    async loadMilestones() {
        try {
            const result = await API.get('/api/analytics/milestones');
            this.milestones = result.data || [];
            this.renderMilestones();
        } catch (err) {
            App.toast('Failed to load milestones: ' + err.message, 'error');
        }
    },

    renderMilestones() {
        DataTable.render(document.getElementById('aa-milestones-table'), {
            columns: [
                { key: 'metric_name', label: 'Category', sortable: false },
                { key: 'milestone_name', label: 'Milestone', sortable: false },
                {
                    key: 'target_value', label: 'Target', align: 'right', sortable: false,
                    render: (row) => {
                        if (row.unit_type === 'currency') return App.formatCurrency(row.target_value);
                        if (row.unit_type === 'percent') return parseFloat(row.target_value).toFixed(1) + '%';
                        return parseInt(row.target_value).toLocaleString();
                    }
                },
                { key: 'time_window', label: 'Window', sortable: false },
                {
                    key: 'window_start', label: 'Period', sortable: false,
                    render: (row) => App.formatDate(row.window_start) + ' - ' + App.formatDate(row.window_end)
                },
                {
                    key: 'is_active', label: 'Active', sortable: false,
                    render: (row) => {
                        if (row.is_active == 1) return '<span class="status-badge status-completed">Active</span>';
                        return '<span class="status-badge status-cancelled">Inactive</span>';
                    }
                },
                {
                    key: 'is_recurring', label: 'Recurring', sortable: false,
                    render: (row) => {
                        if (row.is_recurring != 1) return '-';
                        let label = row.recurrence_type.charAt(0).toUpperCase() + row.recurrence_type.slice(1);
                        if (row.recurrence_type === 'custom' && row.recurrence_days) {
                            label += ` (${row.recurrence_days}d)`;
                        }
                        return `<span class="status-badge status-completed">${label}</span>`;
                    }
                },
                { key: 'created_by_name', label: 'Created By', sortable: false },
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
                            AnalyticsAdmin.showMilestoneForm(row);
                        });

                        const delBtn = document.createElement('button');
                        delBtn.className = 'btn btn-danger btn-sm';
                        delBtn.textContent = 'Del';
                        delBtn.addEventListener('click', (e) => {
                            e.stopPropagation();
                            AnalyticsAdmin.deleteMilestone(row.milestone_id);
                        });

                        div.appendChild(editBtn);
                        div.appendChild(delBtn);
                        return div;
                    }
                },
            ],
            data: this.milestones,
            total: this.milestones.length,
            page: 1,
            perPage: 100,
        });
    },

    showMilestoneForm(existing = null) {
        const isEdit = !!existing;
        const title = isEdit ? 'Edit Milestone' : 'Add Milestone';

        const metricOptions = this.metrics.map(m =>
            `<option value="${m.metric_id}" ${existing && existing.metric_id == m.metric_id ? 'selected' : ''}>${m.metric_name}</option>`
        ).join('');

        const windowOptions = ['auction','weekly','monthly','quarterly','annually','2-year','3-year','4-year','5-year'].map(w =>
            `<option value="${w}" ${existing && existing.time_window === w ? 'selected' : ''}>${w.charAt(0).toUpperCase() + w.slice(1)}</option>`
        ).join('');

        const isRecurring = existing && parseInt(existing.is_recurring);
        const recType = existing ? existing.recurrence_type : 'weekly';
        const recDays = existing && existing.recurrence_days ? existing.recurrence_days : '';

        App.openModal(`
            <div class="modal-header">
                <h2>${title}</h2>
                <button class="modal-close" onclick="App.closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>Category *</label>
                        <select id="aa-ms-metric">${metricOptions}</select>
                    </div>
                    <div class="form-group">
                        <label>Time Window *</label>
                        <select id="aa-ms-window">${windowOptions}</select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Milestone Name *</label>
                    <input type="text" id="aa-ms-name" value="${existing ? existing.milestone_name : ''}"
                           placeholder="e.g., Q1 2026 Sales Target">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Target Value *</label>
                        <input type="number" id="aa-ms-target" step="0.01" min="0.01"
                               value="${existing ? existing.target_value : ''}" placeholder="0.00">
                    </div>
                    ${isEdit ? `
                    <div class="form-group">
                        <label>Active</label>
                        <select id="aa-ms-active">
                            <option value="1" ${existing.is_active == 1 ? 'selected' : ''}>Active</option>
                            <option value="0" ${existing.is_active == 0 ? 'selected' : ''}>Inactive</option>
                        </select>
                    </div>` : ''}
                </div>
                <div class="form-row">
                    <div class="form-group" style="display:flex;align-items:flex-end;padding-bottom:16px;">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                            <input type="checkbox" id="aa-ms-recurring" ${isRecurring ? 'checked' : ''}>
                            Recurring Milestone
                        </label>
                    </div>
                </div>
                <div id="aa-ms-recurrence-options" style="display:${isRecurring ? 'block' : 'none'}">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Frequency *</label>
                            <select id="aa-ms-recurrence-type">
                                <option value="weekly" ${recType === 'weekly' ? 'selected' : ''}>Weekly</option>
                                <option value="monthly" ${recType === 'monthly' ? 'selected' : ''}>Monthly</option>
                                <option value="custom" ${recType === 'custom' ? 'selected' : ''}>Custom</option>
                            </select>
                        </div>
                        <div class="form-group" id="aa-ms-custom-days-group"
                             style="display:${recType === 'custom' ? 'block' : 'none'}">
                            <label>Interval (days) *</label>
                            <input type="number" id="aa-ms-recurrence-days" min="1" step="1"
                                   value="${recDays}" placeholder="e.g., 14">
                        </div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Window Start *</label>
                        <input type="date" id="aa-ms-start" value="${existing ? existing.window_start : ''}">
                    </div>
                    <div class="form-group">
                        <label>Window End *</label>
                        <input type="date" id="aa-ms-end" value="${existing ? existing.window_end : ''}">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="App.closeModal()">Cancel</button>
                <button class="btn btn-primary" id="aa-ms-save-btn">${isEdit ? 'Save Changes' : 'Add Milestone'}</button>
            </div>
        `);

        document.getElementById('aa-ms-save-btn').addEventListener('click', () => {
            this.saveMilestone(existing ? existing.milestone_id : null);
        });

        document.getElementById('aa-ms-recurring').addEventListener('change', (e) => {
            document.getElementById('aa-ms-recurrence-options').style.display =
                e.target.checked ? 'block' : 'none';
        });

        document.getElementById('aa-ms-recurrence-type').addEventListener('change', (e) => {
            document.getElementById('aa-ms-custom-days-group').style.display =
                e.target.value === 'custom' ? 'block' : 'none';
        });
    },

    async saveMilestone(milestoneId) {
        const data = {
            metric_id: parseInt(document.getElementById('aa-ms-metric').value),
            milestone_name: document.getElementById('aa-ms-name').value.trim(),
            target_value: parseFloat(document.getElementById('aa-ms-target').value),
            time_window: document.getElementById('aa-ms-window').value,
            window_start: document.getElementById('aa-ms-start').value,
            window_end: document.getElementById('aa-ms-end').value,
        };

        const activeEl = document.getElementById('aa-ms-active');
        if (activeEl) data.is_active = parseInt(activeEl.value);

        const recurringEl = document.getElementById('aa-ms-recurring');
        if (recurringEl) {
            data.is_recurring = recurringEl.checked ? 1 : 0;
            if (data.is_recurring) {
                data.recurrence_type = document.getElementById('aa-ms-recurrence-type').value;
                if (data.recurrence_type === 'custom') {
                    data.recurrence_days = parseInt(document.getElementById('aa-ms-recurrence-days').value) || null;
                } else {
                    data.recurrence_days = null;
                }
            } else {
                data.recurrence_type = null;
                data.recurrence_days = null;
            }
        }

        if (!data.milestone_name || !data.target_value || !data.window_start || !data.window_end) {
            App.toast('All fields are required', 'error');
            return;
        }

        try {
            if (milestoneId) {
                await API.put(`/api/analytics/milestones/${milestoneId}`, data);
                App.toast('Milestone updated', 'success');
            } else {
                await API.post('/api/analytics/milestones', data);
                App.toast('Milestone created', 'success');
            }
            App.closeModal();
            this.loadMilestones();
        } catch (err) {
            App.toast(err.message, 'error');
        }
    },

    async deleteMilestone(milestoneId) {
        if (!confirm('Delete this milestone?')) return;

        try {
            await API.del(`/api/analytics/milestones/${milestoneId}`);
            App.toast('Milestone deleted', 'success');
            this.loadMilestones();
        } catch (err) {
            App.toast(err.message, 'error');
        }
    },
};
