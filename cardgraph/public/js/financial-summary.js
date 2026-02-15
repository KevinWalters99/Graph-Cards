/**
 * Card Graph - Financial Summary Tab
 * Sub-tabs: Summary Overview, General Costs
 */
const FinancialSummary = {
    initialized: false,
    currentSubTab: 'overview',
    costs: [],

    async init() {
        const panel = document.getElementById('tab-financial-summary');

        if (!this.initialized) {
            panel.innerHTML = `
                <div class="page-header">
                    <h1>Financial Summary</h1>
                </div>
                <div class="sub-tabs" id="fs-sub-tabs">
                    <button class="sub-tab active" data-subtab="overview">Summary Overview</button>
                    <button class="sub-tab" data-subtab="costs">General Costs</button>
                </div>
                <div id="fs-overview" class="sub-panel"></div>
                <div id="fs-costs" class="sub-panel" style="display:none;"></div>
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
        document.getElementById('fs-costs').style.display = name === 'costs' ? '' : 'none';

        if (name === 'overview') this.loadOverview();
        if (name === 'costs') this.loadCosts();
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
            <div style="margin-bottom:16px;">
                <button class="btn btn-success" id="btn-add-general-cost">Add Cost</button>
            </div>
            <div id="fs-costs-table"></div>
        `;

        document.getElementById('btn-add-general-cost').addEventListener('click', () => {
            this.showCostForm();
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
    }
};
