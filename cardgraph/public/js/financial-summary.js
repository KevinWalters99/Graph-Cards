/**
 * Card Graph - Financial Summary Tab
 * Sub-tabs: Summary Overview, Monthly Overview, General Costs
 */
const FinancialSummary = {
    initialized: false,
    currentSubTab: 'monthly',
    costs: [],
    monthlyData: null,
    collapsed: {},  // tracks collapsed state: { '2025': true, '2025-Q1': true }
    monthlyDetailsCache: {},  // { '2025-01': [records...] }
    loadingDetails: {},  // { '2025-01': true } — prevents duplicate fetches
    taxPreviewData: null,
    taxSelectedYear: null,

    async init() {
        const panel = document.getElementById('tab-financial-summary');

        if (!this.initialized) {
            panel.innerHTML = `
                <div class="page-header">
                    <h1>Financial Summary</h1>
                </div>
                <div class="sub-tabs" id="fs-sub-tabs">
                    <button class="sub-tab active" data-subtab="monthly">Monthly Overview</button>
                    <button class="sub-tab" data-subtab="overview">Summary Overview</button>
                    <button class="sub-tab" data-subtab="costs">General Costs</button>
                    <button class="sub-tab" data-subtab="tax">Tax Prep</button>
                </div>
                <div id="fs-overview" class="sub-panel"></div>
                <div id="fs-monthly" class="sub-panel" style="display:none;"></div>
                <div id="fs-costs" class="sub-panel" style="display:none;"></div>
                <div id="fs-tax" class="sub-panel" style="display:none;"></div>
            `;

            document.querySelectorAll('#fs-sub-tabs .sub-tab').forEach(btn => {
                btn.addEventListener('click', () => this.switchSubTab(btn.dataset.subtab));
            });

            this.initialized = true;
        }

        this.switchSubTab(this.currentSubTab);
    },

    switchSubTab(name) {
        this.currentSubTab = name;
        document.querySelectorAll('#fs-sub-tabs .sub-tab').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.subtab === name);
        });
        document.getElementById('fs-overview').style.display = name === 'overview' ? '' : 'none';
        document.getElementById('fs-monthly').style.display = name === 'monthly' ? '' : 'none';
        document.getElementById('fs-costs').style.display = name === 'costs' ? '' : 'none';
        document.getElementById('fs-tax').style.display = name === 'tax' ? '' : 'none';

        if (name === 'overview') this.loadOverview();
        if (name === 'monthly') this.loadMonthly();
        if (name === 'costs') this.loadCosts();
        if (name === 'tax') this.loadTaxPrep();
    },

    // =========================================================
    // Summary Overview
    // =========================================================
    async loadOverview() {
        try {
            App.showLoading();
            const data = await API.get('/api/financial-summary/overview');
            this.renderOverview(data);
        } catch (err) {
            App.toast(err.message, 'error');
        } finally {
            App.hideLoading();
        }
    },

    renderOverview(data) {
        const container = document.getElementById('fs-overview');

        container.innerHTML = `
            <div class="mt-4">
                <h3 class="section-title">Yearly Summary</h3>
                <div id="fs-yearly-table"></div>
            </div>
            <div class="mt-4">
                <h3 class="section-title">Quarterly Summary</h3>
                <div id="fs-quarterly-table"></div>
            </div>
        `;

        const cur = (v) => App.formatCurrency(v);

        // Yearly table
        DataTable.render(document.getElementById('fs-yearly-table'), {
            columns: [
                { key: 'year', label: 'Year', sortable: false },
                { key: 'auction_count', label: 'Auctions', align: 'right', sortable: false },
                { key: 'giveaway_count', label: 'Giveaways', align: 'right', sortable: false },
                { key: 'giveaway_net', label: 'Giveaway Net', align: 'right', format: cur, sortable: false },
                { key: 'tip_count', label: 'Tips', align: 'right', sortable: false },
                { key: 'total_tips', label: 'Tip Amount', align: 'right', format: cur, sortable: false },
                { key: 'total_quantity', label: 'Qty Sold', align: 'right', sortable: false },
                { key: 'total_earnings', label: 'Earnings', align: 'right', format: cur, sortable: false },
                { key: 'total_fees', label: 'Fees', align: 'right', format: cur, sortable: false },
                { key: 'total_shipping', label: 'Shipping', align: 'right', format: cur, sortable: false },
                { key: 'avg_auction_price', label: 'Avg Price', align: 'right', format: cur, sortable: false },
                { key: 'unique_buyers', label: 'Buyers', align: 'right', sortable: false },
                { key: 'unique_livestreams', label: 'Streams', align: 'right', sortable: false },
                { key: 'total_payouts', label: 'Payouts', align: 'right', format: cur, sortable: false },
                { key: 'total_item_costs', label: 'Item Costs', align: 'right', format: cur, sortable: false },
                { key: 'total_general_costs', label: 'Gen. Costs', align: 'right', format: cur, sortable: false },
                { key: 'net', label: 'Net', align: 'right', format: cur, sortable: false },
            ],
            data: data.yearly || [],
            total: (data.yearly || []).length,
            page: 1,
            perPage: 100,
        });

        // Quarterly table - add period label
        const qData = (data.quarterly || []).map(row => ({
            ...row,
            period: row.year + ' Q' + row.quarter,
        }));

        DataTable.render(document.getElementById('fs-quarterly-table'), {
            columns: [
                { key: 'period', label: 'Period', sortable: false },
                { key: 'auction_count', label: 'Auctions', align: 'right', sortable: false },
                { key: 'giveaway_count', label: 'Giveaways', align: 'right', sortable: false },
                { key: 'giveaway_net', label: 'Giveaway Net', align: 'right', format: cur, sortable: false },
                { key: 'tip_count', label: 'Tips', align: 'right', sortable: false },
                { key: 'total_tips', label: 'Tip Amount', align: 'right', format: cur, sortable: false },
                { key: 'total_quantity', label: 'Qty Sold', align: 'right', sortable: false },
                { key: 'total_earnings', label: 'Earnings', align: 'right', format: cur, sortable: false },
                { key: 'total_fees', label: 'Fees', align: 'right', format: cur, sortable: false },
                { key: 'total_shipping', label: 'Shipping', align: 'right', format: cur, sortable: false },
                { key: 'avg_auction_price', label: 'Avg Price', align: 'right', format: cur, sortable: false },
                { key: 'unique_buyers', label: 'Buyers', align: 'right', sortable: false },
                { key: 'unique_livestreams', label: 'Streams', align: 'right', sortable: false },
                { key: 'total_payouts', label: 'Payouts', align: 'right', format: cur, sortable: false },
                { key: 'total_item_costs', label: 'Item Costs', align: 'right', format: cur, sortable: false },
                { key: 'total_general_costs', label: 'Gen. Costs', align: 'right', format: cur, sortable: false },
                { key: 'net', label: 'Net', align: 'right', format: cur, sortable: false },
            ],
            data: qData,
            total: qData.length,
            page: 1,
            perPage: 100,
        });
    },

    // =========================================================
    // General Costs
    // =========================================================
    async loadCosts() {
        try {
            App.showLoading();
            const result = await API.get('/api/financial-summary/costs');
            this.costs = result.data || [];
            this.renderCosts();
        } catch (err) {
            App.toast(err.message, 'error');
        } finally {
            App.hideLoading();
        }
    },

    renderCosts() {
        const container = document.getElementById('fs-costs');

        container.innerHTML = `
            <div style="margin-bottom:16px;display:flex;gap:8px;">
                <button class="btn btn-success" id="btn-add-general-cost">Add Cost</button>
                <button class="btn btn-primary" id="btn-import-general-costs">Import CSV</button>
            </div>
            <div id="fs-costs-table"></div>
        `;

        document.getElementById('btn-add-general-cost').addEventListener('click', () => {
            this.showCostForm();
        });
        document.getElementById('btn-import-general-costs').addEventListener('click', () => {
            Upload.showModal(
                'Import General Costs CSV',
                '/api/uploads/general-costs',
                'CSV with Date, Description, Amount columns. Each row is imported as a lump-sum cost with quantity 1.',
                () => { this.loadCosts(); }
            );
        });

        DataTable.render(document.getElementById('fs-costs-table'), {
            columns: [
                { key: 'cost_date', label: 'Date', sortable: false, format: (v) => App.formatDate(v) },
                { key: 'description', label: 'Description', sortable: false },
                { key: 'amount', label: 'Amount', align: 'right', sortable: false,
                  format: (v) => App.formatCurrency(v) },
                { key: 'quantity', label: 'Qty', align: 'right', sortable: false },
                { key: 'total', label: 'Total', align: 'right', sortable: false,
                  format: (v) => App.formatCurrency(v) },
                { key: 'distribute', label: 'Distribute', sortable: false,
                  render: (row) => {
                      if (parseInt(row.distribute)) {
                          return '<span class="status-badge status-shipped">Distributed</span>';
                      }
                      return '<span class="status-badge status-pending">Lump Sum</span>';
                  }
                },
                { key: 'entered_by_name', label: 'Entered By', sortable: false },
                { key: 'actions', label: 'Actions', sortable: false,
                  render: (row) => {
                      const div = document.createElement('div');
                      div.style.display = 'flex';
                      div.style.gap = '4px';

                      const editBtn = document.createElement('button');
                      editBtn.className = 'btn btn-secondary btn-sm';
                      editBtn.textContent = 'Edit';
                      editBtn.addEventListener('click', (e) => {
                          e.stopPropagation();
                          FinancialSummary.showCostForm(row);
                      });

                      const delBtn = document.createElement('button');
                      delBtn.className = 'btn btn-danger btn-sm';
                      delBtn.textContent = 'Del';
                      delBtn.addEventListener('click', (e) => {
                          e.stopPropagation();
                          FinancialSummary.deleteCost(row.general_cost_id);
                      });

                      div.appendChild(editBtn);
                      div.appendChild(delBtn);
                      return div;
                  }
                },
            ],
            data: this.costs,
            total: this.costs.length,
            page: 1,
            perPage: 100,
        });
    },

    showCostForm(existing = null) {
        const isEdit = !!existing;
        const title = isEdit ? 'Edit General Cost' : 'Add General Cost';

        App.openModal(`
            <div class="modal-header">
                <h2>${title}</h2>
                <button class="modal-close" onclick="App.closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>Date *</label>
                        <input type="date" id="gc-date" value="${existing ? existing.cost_date : ''}">
                    </div>
                    <div class="form-group">
                        <label>Description *</label>
                        <input type="text" id="gc-description"
                               value="${existing ? existing.description : ''}" placeholder="Cost description">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Amount *</label>
                        <input type="number" id="gc-amount" step="0.01" min="0.01"
                               value="${existing ? existing.amount : ''}" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label>Quantity *</label>
                        <input type="number" id="gc-quantity" min="1"
                               value="${existing ? existing.quantity : '1'}">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Total (auto-calculated)</label>
                        <input type="text" id="gc-total" readonly
                               style="background:#f5f5f5;" value="">
                    </div>
                    <div class="form-group" style="display:flex;align-items:flex-end;padding-bottom:16px;">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                            <input type="checkbox" id="gc-distribute"
                                   ${existing && parseInt(existing.distribute) ? 'checked' : ''}>
                            Distribute across units
                        </label>
                    </div>
                </div>
                <div class="text-muted" style="font-size:12px;margin-top:-8px;">
                    When checked, cost is distributed across units in the time frame.
                    When unchecked, cost is applied as a lump-sum for the period.
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="App.closeModal()">Cancel</button>
                <button class="btn btn-primary" id="gc-save-btn">${isEdit ? 'Save Changes' : 'Add Cost'}</button>
            </div>
        `);

        // Auto-calculate total
        const calcTotal = () => {
            const amt = parseFloat(document.getElementById('gc-amount').value) || 0;
            const qty = parseInt(document.getElementById('gc-quantity').value) || 0;
            document.getElementById('gc-total').value = App.formatCurrency(amt * qty);
        };
        document.getElementById('gc-amount').addEventListener('input', calcTotal);
        document.getElementById('gc-quantity').addEventListener('input', calcTotal);
        calcTotal();

        document.getElementById('gc-save-btn').addEventListener('click', () => {
            this.saveCost(existing ? existing.general_cost_id : null);
        });
    },

    async saveCost(costId) {
        const data = {
            cost_date: document.getElementById('gc-date').value,
            description: document.getElementById('gc-description').value.trim(),
            amount: parseFloat(document.getElementById('gc-amount').value),
            quantity: parseInt(document.getElementById('gc-quantity').value) || 1,
            distribute: document.getElementById('gc-distribute').checked ? 1 : 0,
        };

        if (!data.cost_date || !data.description || !data.amount || data.amount <= 0) {
            App.toast('Date, description, and amount are required', 'error');
            return;
        }

        try {
            if (costId) {
                await API.put('/api/financial-summary/costs/' + costId, data);
                App.toast('Cost updated', 'success');
            } else {
                await API.post('/api/financial-summary/costs', data);
                App.toast('Cost added', 'success');
            }
            App.closeModal();
            this.loadCosts();
        } catch (err) {
            App.toast(err.message, 'error');
        }
    },

    async deleteCost(costId) {
        if (!confirm('Delete this cost entry?')) return;

        try {
            await API.del('/api/financial-summary/costs/' + costId);
            App.toast('Cost deleted', 'success');
            this.loadCosts();
        } catch (err) {
            App.toast(err.message, 'error');
        }
    },

    // =========================================================
    // Monthly Overview
    // =========================================================
    async loadMonthly() {
        try {
            App.showLoading();
            const result = await API.get('/api/financial-summary/monthly');
            this.monthlyData = result.monthly || [];
            // Default: months collapsed (days hidden), years/quarters expanded
            if (Object.keys(this.collapsed).length === 0) {
                this.monthlyData.forEach(m => {
                    this.collapsed['m-' + m.month] = true;
                });
            }
            this.renderMonthly();
        } catch (err) {
            document.getElementById('fs-monthly').innerHTML =
                '<p class="text-muted" style="padding:24px;">Unable to load monthly data.</p>';
            App.toast(err.message, 'error');
        } finally {
            App.hideLoading();
        }
    },

    renderMonthly() {
        const container = document.getElementById('fs-monthly');
        const data = this.monthlyData;
        if (!data || data.length === 0) {
            container.innerHTML = '<p class="text-muted" style="padding:24px;">No monthly data available.</p>';
            return;
        }

        const cur = (v) => App.formatCurrency(v);
        const fmtDate = (d) => {
            if (!d) return '';
            const parts = d.split('-');
            return parts[1] + '/' + parts[2];
        };

        // Group months into years and quarters
        const years = {};
        data.forEach(m => {
            const [yr, mo] = m.month.split('-');
            if (!years[yr]) years[yr] = { months: [], totals: this._emptyTotals() };
            years[yr].months.push(m);
            this._addToTotals(years[yr].totals, m);
        });

        // Sort years descending
        const sortedYears = Object.keys(years).sort((a, b) => b - a);

        let html = '<div class="mt-4">';

        // Expand/Collapse All buttons
        html += '<div style="display:flex;gap:8px;margin-bottom:16px;">';
        html += '<button class="btn btn-secondary btn-sm" id="fs-expand-all">Expand All</button>';
        html += '<button class="btn btn-secondary btn-sm" id="fs-collapse-all">Collapse All</button>';
        html += '</div>';

        // Color styles for the 4 net-calc columns
        const sGreen = 'text-align:right;background:rgba(46,125,50,0.08);';
        const sRed   = 'text-align:right;background:rgba(198,40,40,0.08);';

        html += '<div class="table-container"><table class="data-table fs-monthly-table">';

        // Header — Payouts moved before Gen. Costs
        html += '<thead><tr>';
        html += '<th style="min-width:200px;">Period</th>';
        html += '<th style="text-align:right">Auctions</th>';
        html += '<th style="text-align:right">Earnings</th>';
        html += '<th style="text-align:right">Fees</th>';
        html += '<th style="text-align:right">Item Costs</th>';
        html += '<th style="text-align:right;background:rgba(46,125,50,0.15);">Payouts</th>';
        html += '<th style="text-align:right;background:rgba(198,40,40,0.15);">Gen. Costs</th>';
        html += '<th style="text-align:right;background:rgba(198,40,40,0.15);">PayPal Out</th>';
        html += '<th style="text-align:right;background:rgba(46,125,50,0.15);">PayPal In</th>';
        html += '<th style="text-align:right">Net</th>';
        html += '</tr></thead><tbody>';

        sortedYears.forEach(yr => {
            const yearData = years[yr];
            const yTot = yearData.totals;
            const yearCollapsed = !!this.collapsed[yr];

            // Year row
            html += `<tr class="fs-row-year" data-toggle="${yr}" style="cursor:pointer;background:#1a1a2e;color:#fff;border-bottom:2px solid #4a9eff;">`;
            html += `<td><span class="fs-toggle-icon">${yearCollapsed ? '&#9654;' : '&#9660;'}</span> <strong>${yr}</strong></td>`;
            html += `<td style="text-align:right"><strong>${yTot.auction_count}</strong></td>`;
            html += `<td style="text-align:right"><strong>${cur(yTot.total_earnings)}</strong></td>`;
            html += `<td style="text-align:right"><strong>${cur(yTot.total_fees)}</strong></td>`;
            html += `<td style="text-align:right"><strong>${cur(yTot.total_item_costs)}</strong></td>`;
            html += `<td style="text-align:right;background:rgba(46,125,50,0.18);"><strong>${cur(yTot.total_payouts)}</strong></td>`;
            html += `<td style="text-align:right;background:rgba(198,40,40,0.18);"><strong>${cur(yTot.total_general_costs)}</strong></td>`;
            html += `<td style="text-align:right;background:rgba(198,40,40,0.18);"><strong>${cur(yTot.pp_purchases)}</strong></td>`;
            html += `<td style="text-align:right;background:rgba(46,125,50,0.18);"><strong>${cur(yTot.pp_income)}</strong></td>`;
            html += `<td style="text-align:right"><strong class="${yTot.net >= 0 ? 'text-success' : 'text-danger'}">${cur(yTot.net)}</strong></td>`;
            html += '</tr>';

            // Build quarters
            const quarters = {};
            yearData.months.forEach(m => {
                const mo = parseInt(m.month.split('-')[1]);
                const q = Math.ceil(mo / 3);
                const qKey = yr + '-Q' + q;
                if (!quarters[qKey]) quarters[qKey] = { q: q, months: [], totals: this._emptyTotals() };
                quarters[qKey].months.push(m);
                this._addToTotals(quarters[qKey].totals, m);
            });
            const sortedQKeys = Object.keys(quarters).sort((a, b) => {
                return parseInt(b.split('Q')[1]) - parseInt(a.split('Q')[1]);
            });

            sortedQKeys.forEach(qKey => {
                const qData = quarters[qKey];
                const qTot = qData.totals;
                const qCollapsed = !!this.collapsed[qKey];
                const hideQ = yearCollapsed ? 'display:none;' : '';

                // Quarter row
                html += `<tr class="fs-row-quarter fs-child-${yr}" data-toggle="${qKey}" style="cursor:pointer;background:#e3edf7;${hideQ}">`;
                html += `<td style="padding-left:24px;"><span class="fs-toggle-icon">${qCollapsed ? '&#9654;' : '&#9660;'}</span> <strong>Q${qData.q} ${yr}</strong></td>`;
                html += `<td style="text-align:right"><strong>${qTot.auction_count}</strong></td>`;
                html += `<td style="text-align:right"><strong>${cur(qTot.total_earnings)}</strong></td>`;
                html += `<td style="text-align:right"><strong>${cur(qTot.total_fees)}</strong></td>`;
                html += `<td style="text-align:right"><strong>${cur(qTot.total_item_costs)}</strong></td>`;
                html += `<td style="${sGreen}"><strong>${cur(qTot.total_payouts)}</strong></td>`;
                html += `<td style="${sRed}"><strong>${cur(qTot.total_general_costs)}</strong></td>`;
                html += `<td style="${sRed}"><strong>${cur(qTot.pp_purchases)}</strong></td>`;
                html += `<td style="${sGreen}"><strong>${cur(qTot.pp_income)}</strong></td>`;
                html += `<td style="text-align:right"><strong class="${qTot.net >= 0 ? 'text-success' : 'text-danger'}">${cur(qTot.net)}</strong></td>`;
                html += '</tr>';

                // Month rows
                const sortedMonths = qData.months.sort((a, b) => b.month.localeCompare(a.month));
                sortedMonths.forEach(m => {
                    const hideM = (yearCollapsed || qCollapsed) ? 'display:none;' : '';
                    const monthLabel = this._monthName(m.month);
                    const monthExpanded = !this.collapsed['m-' + m.month];

                    // Month summary row — light yellow highlight
                    html += `<tr class="fs-row-month fs-child-${yr} fs-child-${qKey}" data-toggle="m-${m.month}" style="cursor:pointer;background:#f5f5f5;${hideM}">`;
                    html += `<td style="padding-left:48px;"><span class="fs-toggle-icon">${monthExpanded ? '&#9660;' : '&#9654;'}</span> ${monthLabel}</td>`;
                    html += `<td style="text-align:right">${m.auction_count}</td>`;
                    html += `<td style="text-align:right">${cur(m.total_earnings)}</td>`;
                    html += `<td style="text-align:right">${cur(m.total_fees)}</td>`;
                    html += `<td style="text-align:right">${cur(m.total_item_costs)}</td>`;
                    html += `<td style="${sGreen}">${cur(m.total_payouts)}</td>`;
                    html += `<td style="${sRed}">${cur(m.total_general_costs)}</td>`;
                    html += `<td style="${sRed}">${cur(m.pp_purchases)}</td>`;
                    html += `<td style="${sGreen}">${cur(m.pp_income)}</td>`;
                    html += `<td style="text-align:right"><strong class="${m.net >= 0 ? 'text-success' : 'text-danger'}">${cur(m.net)}</strong></td>`;
                    html += '</tr>';

                    // Detail sub-rows: daily summary rows (same columns)
                    const hideD = (yearCollapsed || qCollapsed || !monthExpanded) ? 'display:none;' : '';
                    const dayData = this.monthlyDetailsCache[m.month];

                    if (monthExpanded && !dayData && !this.loadingDetails[m.month]) {
                        html += `<tr class="fs-row-detail fs-child-${yr} fs-child-${qKey} fs-child-m-${m.month}" style="font-size:12px;${hideD}">`;
                        html += `<td colspan="10" style="padding-left:72px;color:#888;font-style:italic;">Loading daily data...</td>`;
                        html += '</tr>';
                    } else if (dayData && dayData.length === 0) {
                        html += `<tr class="fs-row-detail fs-child-${yr} fs-child-${qKey} fs-child-m-${m.month}" style="font-size:12px;${hideD}">`;
                        html += `<td colspan="10" style="padding-left:72px;color:#888;font-style:italic;">No daily data for this month.</td>`;
                        html += '</tr>';
                    } else if (dayData) {
                        dayData.forEach(day => {
                            const dayLabel = fmtDate(day.date);
                            html += `<tr class="fs-row-detail fs-child-${yr} fs-child-${qKey} fs-child-m-${m.month}" style="font-size:12px;${hideD}">`;
                            html += `<td style="padding-left:72px;">${dayLabel}</td>`;
                            html += `<td style="text-align:right">${day.auction_count || ''}</td>`;
                            html += `<td style="text-align:right">${day.total_earnings ? cur(day.total_earnings) : ''}</td>`;
                            html += `<td style="text-align:right">${day.total_fees ? cur(day.total_fees) : ''}</td>`;
                            html += `<td style="text-align:right">${day.total_item_costs ? cur(day.total_item_costs) : ''}</td>`;
                            html += `<td style="${sGreen}">${day.total_payouts ? cur(day.total_payouts) : ''}</td>`;
                            html += `<td style="${sRed}">${day.total_general_costs ? cur(day.total_general_costs) : ''}</td>`;
                            html += `<td style="${sRed}">${day.pp_purchases ? cur(day.pp_purchases) : ''}</td>`;
                            html += `<td style="${sGreen}">${day.pp_income ? cur(day.pp_income) : ''}</td>`;
                            html += `<td style="text-align:right"><span class="${day.net >= 0 ? 'text-success' : 'text-danger'}">${cur(day.net)}</span></td>`;
                            html += '</tr>';
                        });
                    }
                });
            });
        });

        html += '</tbody></table></div></div>';

        container.innerHTML = html;

        // Attach toggle handlers
        container.querySelectorAll('[data-toggle]').forEach(row => {
            row.addEventListener('click', () => {
                const key = row.getAttribute('data-toggle');
                this.collapsed[key] = !this.collapsed[key];

                // If expanding a month (key starts with 'm-'), fetch details
                if (key.startsWith('m-') && !this.collapsed[key]) {
                    const month = key.substring(2);
                    this._fetchMonthDetails(month);
                }

                this.renderMonthly();
            });
        });

        // Expand/Collapse All
        document.getElementById('fs-expand-all').addEventListener('click', () => {
            this.collapsed = {};
            // Fetch details for all months that aren't cached
            data.forEach(m => {
                if (!this.monthlyDetailsCache[m.month]) {
                    this._fetchMonthDetails(m.month);
                }
            });
            this.renderMonthly();
        });
        document.getElementById('fs-collapse-all').addEventListener('click', () => {
            sortedYears.forEach(yr => {
                this.collapsed[yr] = true;
            });
            this.renderMonthly();
        });

        // Trigger fetches for any expanded months that need data
        data.forEach(m => {
            if (!this.collapsed['m-' + m.month] && !this.monthlyDetailsCache[m.month] && !this.loadingDetails[m.month]) {
                this._fetchMonthDetails(m.month);
            }
        });
    },

    async _fetchMonthDetails(month) {
        if (this.monthlyDetailsCache[month] || this.loadingDetails[month]) return;
        this.loadingDetails[month] = true;
        try {
            const result = await API.get('/api/financial-summary/monthly-details', { month });
            this.monthlyDetailsCache[month] = result.days || [];
        } catch (err) {
            this.monthlyDetailsCache[month] = [];
            console.error('Failed to load details for ' + month, err);
        } finally {
            delete this.loadingDetails[month];
            this.renderMonthly();
        }
    },

    _emptyTotals() {
        return {
            auction_count: 0, total_earnings: 0, total_fees: 0,
            total_item_costs: 0, total_general_costs: 0,
            pp_purchases: 0, pp_income: 0, pp_refunds: 0,
            total_payouts: 0, total_item_price: 0, net: 0, auction_net: 0
        };
    },

    _addToTotals(totals, m) {
        totals.auction_count += m.auction_count || 0;
        totals.total_earnings += m.total_earnings || 0;
        totals.total_fees += m.total_fees || 0;
        totals.total_item_costs += m.total_item_costs || 0;
        totals.total_general_costs += m.total_general_costs || 0;
        totals.pp_purchases += m.pp_purchases || 0;
        totals.pp_income += m.pp_income || 0;
        totals.pp_refunds += m.pp_refunds || 0;
        totals.total_payouts += m.total_payouts || 0;
        totals.total_item_price += m.total_item_price || 0;
        // Net = Payouts - GenCosts - PayPalOut + PayPalIn (only these 4)
        totals.net = totals.total_payouts - totals.total_general_costs
                     - Math.abs(totals.pp_purchases) + totals.pp_income;
    },

    _monthName(monthStr) {
        const [yr, mo] = monthStr.split('-');
        const names = ['', 'January', 'February', 'March', 'April', 'May', 'June',
                        'July', 'August', 'September', 'October', 'November', 'December'];
        return names[parseInt(mo)] + ' ' + yr;
    },

    // =========================================================
    // Tax Preparation
    // =========================================================
    async loadTaxPrep() {
        try {
            App.showLoading();
            const year = this.taxSelectedYear || new Date().getFullYear();
            const data = await API.get('/api/financial-summary/tax-preview', { year });
            this.taxPreviewData = data;
            this.taxSelectedYear = data.year;
            this.renderTaxPrep();
        } catch (err) {
            document.getElementById('fs-tax').innerHTML =
                '<p class="text-muted" style="padding:24px;">Unable to load tax data.</p>';
            App.toast(err.message, 'error');
        } finally {
            App.hideLoading();
        }
    },

    renderTaxPrep() {
        const container = document.getElementById('fs-tax');
        const data = this.taxPreviewData;
        if (!data) {
            container.innerHTML = '<p class="text-muted" style="padding:24px;">No tax data available.</p>';
            return;
        }

        const cur = (v) => App.formatCurrency(v);
        const q = data.quarters;
        const a = data.annual;
        const saved = data.saved_records || [];

        // Build saved records lookup: { 1: record, 2: record, null: annual record }
        const savedMap = {};
        saved.forEach(r => {
            const key = r.period_type === 'annual' ? 'annual' : 'q' + r.tax_quarter;
            savedMap[key] = r;
        });

        // Year selector
        let yearOpts = '';
        (data.available_years || []).forEach(y => {
            yearOpts += `<option value="${y}" ${y === data.year ? 'selected' : ''}>${y}</option>`;
        });

        let html = '<div class="mt-4">';

        // Year selector row
        html += '<div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;">';
        html += '<h3 class="section-title" style="margin:0;">Tax Year</h3>';
        html += `<select id="tax-year-select" style="padding:6px 12px;border:1px solid #ddd;border-radius:6px;font-size:14px;">${yearOpts}</select>`;
        html += '<button class="btn btn-secondary btn-sm" id="tax-print-btn" style="margin-left:auto;">Print Summary</button>';
        html += '</div>';

        // === Schedule C Overview Table ===
        html += '<div class="table-container" id="tax-overview-print">';
        html += '<table class="data-table" style="font-size:13px;">';
        html += '<thead><tr>';
        html += '<th style="min-width:180px;">Category</th>';
        html += '<th style="text-align:right">Q1</th>';
        html += '<th style="text-align:right">Q2</th>';
        html += '<th style="text-align:right">Q3</th>';
        html += '<th style="text-align:right">Q4</th>';
        html += '<th style="text-align:right;background:#f0f2f5;">Annual</th>';
        html += '</tr></thead><tbody>';

        const row = (label, key, style = '') => {
            let r = `<tr style="${style}">`;
            r += `<td>${label}</td>`;
            for (let i = 0; i < 4; i++) r += `<td style="text-align:right">${cur(q[i][key])}</td>`;
            r += `<td style="text-align:right;background:#f0f2f5;"><strong>${cur(a[key])}</strong></td>`;
            r += '</tr>';
            return r;
        };

        // Income section
        html += '<tr style="background:#e8f5e9;"><td colspan="6" style="font-weight:600;color:#2e7d32;">Income</td></tr>';
        html += row('Payouts Received', 'total_payouts');
        html += row('PayPal Income', 'paypal_income');
        html += row('Gross Income', 'gross_income', 'font-weight:600;border-bottom:1px solid #ccc;');

        // COGS section
        html += '<tr style="background:#fff3e0;"><td colspan="6" style="font-weight:600;color:#e65100;">Cost of Goods Sold</td></tr>';
        html += row('Card Inventory (Item Costs)', 'item_costs');
        html += row('PayPal Purchases', 'paypal_purchases');
        html += row('Total COGS', 'total_cogs', 'font-weight:600;border-bottom:1px solid #ccc;');

        // Gross Profit
        let gpRow = '<tr style="background:#e3f2fd;font-weight:600;">';
        gpRow += '<td style="color:#1565c0;">Gross Profit</td>';
        for (let i = 0; i < 4; i++) {
            const val = q[i].gross_profit;
            gpRow += `<td style="text-align:right" class="${val >= 0 ? 'text-success' : 'text-danger'}">${cur(val)}</td>`;
        }
        gpRow += `<td style="text-align:right;background:#f0f2f5;" class="${a.gross_profit >= 0 ? 'text-success' : 'text-danger'}"><strong>${cur(a.gross_profit)}</strong></td>`;
        gpRow += '</tr>';
        html += gpRow;

        // Operating Expenses
        html += '<tr style="background:#fce4ec;"><td colspan="6" style="font-weight:600;color:#c62828;">Operating Expenses</td></tr>';
        html += row('Platform Fees', 'platform_fees');
        html += row('Shipping Costs', 'shipping_costs');
        html += row('General Costs', 'general_costs');
        html += row('Total Operating', 'total_operating', 'font-weight:600;border-bottom:1px solid #ccc;');

        // Net Before Deductions
        let nbdRow = '<tr style="background:#ede7f6;font-weight:600;">';
        nbdRow += '<td style="color:#4527a0;">Net Before Deductions</td>';
        for (let i = 0; i < 4; i++) {
            const val = q[i].net_before_deductions;
            nbdRow += `<td style="text-align:right" class="${val >= 0 ? 'text-success' : 'text-danger'}">${cur(val)}</td>`;
        }
        nbdRow += `<td style="text-align:right;background:#f0f2f5;" class="${a.net_before_deductions >= 0 ? 'text-success' : 'text-danger'}"><strong>${cur(a.net_before_deductions)}</strong></td>`;
        nbdRow += '</tr>';
        html += nbdRow;

        html += '</tbody></table></div>';

        // === Quarterly Deduction Cards ===
        html += '<h3 class="section-title mt-4">Deductions by Quarter</h3>';
        html += '<p class="text-muted" style="font-size:12px;margin-bottom:12px;">Enter business deductions for each quarter. Save as draft, then lock in when finalized.</p>';

        html += '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;margin-bottom:24px;">';
        for (let qi = 1; qi <= 4; qi++) {
            const rec = savedMap['q' + qi];
            const locked = rec && rec.is_locked;
            const hasDraft = rec && !rec.is_locked;
            const qCalc = q[qi - 1];

            html += `<div class="card" style="padding:16px;border:2px solid ${locked ? '#4caf50' : hasDraft ? '#ff9800' : '#e0e0e0'};border-radius:10px;">`;
            html += `<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">`;
            html += `<h4 style="margin:0;">Q${qi} ${data.year}</h4>`;
            if (locked) {
                html += '<span style="background:#4caf50;color:#fff;padding:2px 8px;border-radius:4px;font-size:11px;">LOCKED</span>';
            } else if (hasDraft) {
                html += '<span style="background:#ff9800;color:#fff;padding:2px 8px;border-radius:4px;font-size:11px;">DRAFT</span>';
            } else {
                html += '<span style="background:#9e9e9e;color:#fff;padding:2px 8px;border-radius:4px;font-size:11px;">UNSAVED</span>';
            }
            html += '</div>';

            // Summary line
            html += `<div style="font-size:12px;color:#666;margin-bottom:8px;">Net before deductions: <strong class="${qCalc.net_before_deductions >= 0 ? 'text-success' : 'text-danger'}">${cur(qCalc.net_before_deductions)}</strong></div>`;

            const dis = locked ? 'disabled' : '';
            const val = (field, def) => rec ? (rec[field] ?? def) : def;

            html += '<div style="font-size:12px;">';
            html += `<div class="form-row" style="gap:4px;margin-bottom:4px;">`;
            html += `<label style="flex:1;font-size:11px;">Phone $<input type="number" step="0.01" class="tax-input" data-q="${qi}" data-field="phone_amount" value="${val('phone_amount', '')}" ${dis} style="width:60px;padding:2px 4px;font-size:11px;"></label>`;
            html += `<label style="flex:1;font-size:11px;">x <input type="number" min="0" max="100" class="tax-input" data-q="${qi}" data-field="phone_pct" value="${val('phone_pct', '30')}" ${dis} style="width:40px;padding:2px 4px;font-size:11px;">%</label>`;
            html += '</div>';

            html += `<div class="form-row" style="gap:4px;margin-bottom:4px;">`;
            html += `<label style="flex:1;font-size:11px;">Miles<input type="number" step="0.1" class="tax-input" data-q="${qi}" data-field="mileage_miles" value="${val('mileage_miles', '')}" ${dis} style="width:60px;padding:2px 4px;font-size:11px;"></label>`;
            html += `<label style="flex:1;font-size:11px;">@$<input type="number" step="0.001" class="tax-input" data-q="${qi}" data-field="mileage_rate" value="${val('mileage_rate', '0.670')}" ${dis} style="width:55px;padding:2px 4px;font-size:11px;"></label>`;
            html += '</div>';

            html += `<div style="margin-bottom:3px;"><label style="font-size:11px;">Equipment $<input type="number" step="0.01" class="tax-input" data-q="${qi}" data-field="equipment_deduction" value="${val('equipment_deduction', '')}" ${dis} style="width:80px;padding:2px 4px;font-size:11px;"></label></div>`;
            html += `<div style="margin-bottom:3px;"><label style="font-size:11px;">Supplies $<input type="number" step="0.01" class="tax-input" data-q="${qi}" data-field="supplies_deduction" value="${val('supplies_deduction', '')}" ${dis} style="width:80px;padding:2px 4px;font-size:11px;"></label></div>`;
            html += `<div style="margin-bottom:3px;"><label style="font-size:11px;">Advertising $<input type="number" step="0.01" class="tax-input" data-q="${qi}" data-field="advertising_deduction" value="${val('advertising_deduction', '')}" ${dis} style="width:80px;padding:2px 4px;font-size:11px;"></label></div>`;
            html += `<div style="margin-bottom:3px;"><label style="font-size:11px;">Other $<input type="number" step="0.01" class="tax-input" data-q="${qi}" data-field="other_deduction" value="${val('other_deduction', '')}" ${dis} style="width:80px;padding:2px 4px;font-size:11px;"></label></div>`;
            html += `<div style="margin-bottom:6px;"><label style="font-size:11px;">Notes<br><input type="text" class="tax-input" data-q="${qi}" data-field="deduction_notes" value="${val('deduction_notes', '')}" ${dis} style="width:100%;padding:2px 4px;font-size:11px;"></label></div>`;
            html += '</div>';

            if (!locked) {
                html += '<div style="display:flex;gap:6px;margin-top:8px;">';
                html += `<button class="btn btn-primary btn-sm tax-save-btn" data-q="${qi}">Save Draft</button>`;
                if (hasDraft) {
                    html += `<button class="btn btn-success btn-sm tax-lock-btn" data-q="${qi}" data-id="${rec.tax_record_id}">Lock In</button>`;
                }
                html += '</div>';
            } else {
                html += `<div style="font-size:11px;color:#666;margin-top:6px;">Locked by ${rec.locked_by_name || 'user'} on ${rec.locked_at ? rec.locked_at.split(' ')[0] : ''}</div>`;
            }

            html += '</div>';
        }
        html += '</div>';

        // === Annual Summary Card ===
        const annRec = savedMap['annual'];
        const annLocked = annRec && annRec.is_locked;
        const annDraft = annRec && !annRec.is_locked;

        html += '<h3 class="section-title">Annual Summary</h3>';
        html += `<div class="card" style="padding:16px;border:2px solid ${annLocked ? '#4caf50' : annDraft ? '#ff9800' : '#e0e0e0'};border-radius:10px;margin-bottom:24px;">`;
        html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">';
        html += `<h4 style="margin:0;">Full Year ${data.year}</h4>`;
        if (annLocked) {
            html += '<span style="background:#4caf50;color:#fff;padding:2px 8px;border-radius:4px;font-size:11px;">LOCKED</span>';
        } else if (annDraft) {
            html += '<span style="background:#ff9800;color:#fff;padding:2px 8px;border-radius:4px;font-size:11px;">DRAFT</span>';
        }
        html += '</div>';

        // Annual deduction totals (sum of all quarter deductions if locked, or manual entry)
        const dis2 = annLocked ? 'disabled' : '';
        const annVal = (field, def) => annRec ? (annRec[field] ?? def) : def;

        html += '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px;font-size:12px;margin-bottom:12px;">';
        html += `<div><label style="font-size:11px;">Phone $<input type="number" step="0.01" class="tax-input" data-q="annual" data-field="phone_amount" value="${annVal('phone_amount', '')}" ${dis2} style="width:70px;padding:2px 4px;font-size:11px;"></label> x <input type="number" min="0" max="100" class="tax-input" data-q="annual" data-field="phone_pct" value="${annVal('phone_pct', '30')}" ${dis2} style="width:35px;padding:2px 4px;font-size:11px;">%</div>`;
        html += `<div><label style="font-size:11px;">Miles<input type="number" step="0.1" class="tax-input" data-q="annual" data-field="mileage_miles" value="${annVal('mileage_miles', '')}" ${dis2} style="width:60px;padding:2px 4px;font-size:11px;"></label> @$<input type="number" step="0.001" class="tax-input" data-q="annual" data-field="mileage_rate" value="${annVal('mileage_rate', '0.670')}" ${dis2} style="width:50px;padding:2px 4px;font-size:11px;"></div>`;
        html += `<div><label style="font-size:11px;">Equipment $<input type="number" step="0.01" class="tax-input" data-q="annual" data-field="equipment_deduction" value="${annVal('equipment_deduction', '')}" ${dis2} style="width:80px;padding:2px 4px;font-size:11px;"></label></div>`;
        html += `<div><label style="font-size:11px;">Supplies $<input type="number" step="0.01" class="tax-input" data-q="annual" data-field="supplies_deduction" value="${annVal('supplies_deduction', '')}" ${dis2} style="width:80px;padding:2px 4px;font-size:11px;"></label></div>`;
        html += `<div><label style="font-size:11px;">Advertising $<input type="number" step="0.01" class="tax-input" data-q="annual" data-field="advertising_deduction" value="${annVal('advertising_deduction', '')}" ${dis2} style="width:80px;padding:2px 4px;font-size:11px;"></label></div>`;
        html += `<div><label style="font-size:11px;">Other $<input type="number" step="0.01" class="tax-input" data-q="annual" data-field="other_deduction" value="${annVal('other_deduction', '')}" ${dis2} style="width:80px;padding:2px 4px;font-size:11px;"></label></div>`;
        html += '</div>';
        html += `<div style="margin-bottom:8px;"><label style="font-size:11px;">Notes<br><input type="text" class="tax-input" data-q="annual" data-field="deduction_notes" value="${annVal('deduction_notes', '')}" ${dis2} style="width:100%;max-width:500px;padding:2px 4px;font-size:11px;"></label></div>`;

        if (!annLocked) {
            html += '<div style="display:flex;gap:8px;margin-top:8px;">';
            html += '<button class="btn btn-primary btn-sm tax-save-btn" data-q="annual">Save Annual Draft</button>';
            if (annDraft) {
                html += `<button class="btn btn-success btn-sm tax-lock-btn" data-q="annual" data-id="${annRec.tax_record_id}">Lock In Annual</button>`;
            }
            html += '</div>';
        } else {
            html += `<div style="font-size:11px;color:#666;margin-top:6px;">Locked by ${annRec.locked_by_name || 'user'} on ${annRec.locked_at ? annRec.locked_at.split(' ')[0] : ''}</div>`;
        }
        html += '</div>';

        html += '</div>';
        container.innerHTML = html;

        // Event handlers
        document.getElementById('tax-year-select').addEventListener('change', (e) => {
            this.taxSelectedYear = parseInt(e.target.value);
            this.loadTaxPrep();
        });

        document.getElementById('tax-print-btn').addEventListener('click', () => {
            this._printTaxSummary();
        });

        container.querySelectorAll('.tax-save-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const qVal = btn.dataset.q;
                this._saveTaxDraft(qVal);
            });
        });

        container.querySelectorAll('.tax-lock-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const qVal = btn.dataset.q;
                const recId = parseInt(btn.dataset.id);
                this._lockTaxRecord(recId, qVal);
            });
        });
    },

    _getTaxInputs(qVal) {
        const inputs = {};
        document.querySelectorAll(`.tax-input[data-q="${qVal}"]`).forEach(inp => {
            const field = inp.dataset.field;
            if (field === 'deduction_notes') {
                inputs[field] = inp.value;
            } else {
                inputs[field] = parseFloat(inp.value) || 0;
            }
        });
        return inputs;
    },

    async _saveTaxDraft(qVal) {
        const data = this.taxPreviewData;
        if (!data) return;

        const inputs = this._getTaxInputs(qVal);
        const isAnnual = qVal === 'annual';
        const qIdx = isAnnual ? null : parseInt(qVal) - 1;
        const financials = isAnnual ? data.annual : data.quarters[qIdx];

        const body = {
            tax_year: data.year,
            tax_quarter: isAnnual ? null : parseInt(qVal),
            // Financial data from preview
            total_payouts: financials.total_payouts,
            paypal_income: financials.paypal_income,
            item_costs: financials.item_costs,
            paypal_purchases: financials.paypal_purchases,
            platform_fees: financials.platform_fees,
            shipping_costs: financials.shipping_costs,
            general_costs: financials.general_costs,
            // Deductions from user inputs
            ...inputs,
        };

        try {
            await API.post('/api/financial-summary/tax-records', body);
            App.toast('Tax record saved as draft', 'success');
            this.loadTaxPrep();
        } catch (err) {
            App.toast(err.message, 'error');
        }
    },

    async _lockTaxRecord(recordId, qVal) {
        const label = qVal === 'annual' ? 'Annual ' + this.taxSelectedYear : 'Q' + qVal + ' ' + this.taxSelectedYear;
        if (!confirm(`Lock in tax record for ${label}? This cannot be undone from the application.`)) return;

        try {
            await API.put('/api/financial-summary/tax-records/' + recordId + '/lock');
            App.toast(`${label} tax record locked`, 'success');
            this.loadTaxPrep();
        } catch (err) {
            App.toast(err.message, 'error');
        }
    },

    _printTaxSummary() {
        const printArea = document.getElementById('tax-overview-print');
        if (!printArea) return;

        const win = window.open('', '_blank');
        win.document.write(`<html><head><title>Tax Summary ${this.taxSelectedYear}</title>`);
        win.document.write('<style>body{font-family:Arial,sans-serif;padding:20px;}table{width:100%;border-collapse:collapse;font-size:13px;}th,td{border:1px solid #ccc;padding:6px 10px;}th{background:#f0f2f5;text-align:left;}.text-success{color:#2e7d32;}.text-danger{color:#c62828;}h2{margin-bottom:16px;}</style>');
        win.document.write('</head><body>');
        win.document.write(`<h2>Tax Preparation Summary - ${this.taxSelectedYear}</h2>`);
        win.document.write(printArea.innerHTML);
        win.document.write('</body></html>');
        win.document.close();
        win.print();
    }
};
