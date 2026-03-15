/**
 * Card Graph — Dashboard Tab
 */
const Dashboard = {
    initialized: false,
    livestreamData: [],
    lsSortKey: 'stream_date',
    lsSortDir: 'desc',

    async init() {
        const panel = document.getElementById('tab-dashboard');

        if (!this.initialized) {
            panel.innerHTML = `
                <div class="page-header">
                    <h1>Dashboard <span id="dashboard-filter-desc" class="filter-description"></span></h1>
                </div>
                <div id="dashboard-cards" class="cards-row"></div>
                <div class="mt-4" style="display:flex;gap:20px;align-items:start;">
                    <div style="flex:1;">
                        <h3 class="section-title">Top Buyers (Item Count)</h3>
                        <div id="dashboard-top-buyers" class="cards-grid cards-compact" style="grid-template-columns:repeat(3,1fr);"></div>
                    </div>
                    <div style="flex:1;">
                        <h3 class="section-title">Top Buyers (Total Spend)</h3>
                        <div id="dashboard-top-spenders" class="cards-grid cards-compact" style="grid-template-columns:repeat(3,1fr);"></div>
                    </div>
                </div>
                <div id="dashboard-filters"></div>
                <div class="mt-4">
                    <h3 class="section-title">Earnings by Livestream</h3>
                    <div id="dashboard-livestream-count" style="font-size:12px;color:#888;margin-bottom:6px;"></div>
                    <div id="dashboard-livestreams"></div>
                </div>
                <div class="mt-4">
                    <h3 class="section-title">Daily Trends</h3>
                    <div id="dashboard-trends"></div>
                </div>
            `;

            // Filters
            Filters.render(document.getElementById('dashboard-filters'), [
                { type: 'date', name: 'date_from', label: 'From Date' },
                { type: 'date', name: 'date_to', label: 'To Date' },
            ], (filters) => this.loadData(filters),
            { descriptionEl: 'dashboard-filter-desc' });

            this.initialized = true;
        }

        this.loadData();
    },

    async loadData(filters = {}) {
        try {
            App.showLoading();
            const [summary, trends] = await Promise.all([
                API.get('/api/dashboard/summary', filters),
                API.get('/api/dashboard/trends', { ...filters, group_by: 'day' }),
            ]);
            this.renderCards(summary);
            this.renderTopBuyers(summary);
            this.renderTopSpenders(summary);
            this.livestreamData = trends.livestreams || [];
            this.renderLivestreams();
            this.renderTrends(trends.trends || []);
        } catch (err) {
            App.toast(err.message, 'error');
        } finally {
            App.hideLoading();
        }
    },

    renderCards(s) {
        const container = document.getElementById('dashboard-cards');
        const cur = (v) => App.formatCurrency(v);
        const profitClass = (s.profit || 0) >= 0 ? 'val-income' : 'val-negative';
        const pctClass = (s.profit_pct || 0) >= 0 ? 'val-income' : 'val-negative';

        container.innerHTML = `
            <div class="cards-group" style="flex:0 0 auto;grid-template-columns:repeat(3,1fr);">
                <div class="card">
                    <div class="card-label">Livestreams</div>
                    <div class="card-value val-count">${s.unique_livestreams}</div>
                </div>
                <div class="card">
                    <div class="card-label">Statements</div>
                    <div class="card-value val-count">${s.statements_uploaded}</div>
                    <div class="card-sub">Uploaded</div>
                </div>
                <div class="card">
                    <div class="card-label">Items Sold</div>
                    <div class="card-value val-count">${s.auction_count}</div>
                    <div class="card-sub">Avg price: <span class="val-income">${cur(s.avg_auction_price)}</span></div>
                </div>
                <div class="card">
                    <div class="card-label">Buyers</div>
                    <div class="card-value val-buyer">${s.unique_buyers}</div>
                </div>
                <div class="card">
                    <div class="card-label">Giveaways</div>
                    <div class="card-value val-count">${s.giveaway_count}</div>
                    <div class="card-sub"><span class="val-count">${s.buyer_giveaways}</span> buyer giveaways</div>
                </div>
                <div class="card">
                    <div class="card-label">Shipments</div>
                    <div class="card-value val-count">${s.unique_shipments}</div>
                </div>
                <div class="card">
                    <div class="card-label">Tips</div>
                    <div class="card-value val-count">${s.tip_count}</div>
                    <div class="card-sub"><span class="val-income">${cur(s.total_tips)}</span></div>
                </div>
            </div>
            <div class="cards-value-cols">
                <div class="cards-col">
                    <div class="card">
                        <div class="card-label">Buyer Paid</div>
                        <div class="card-value val-count">${cur(s.total_buyer_paid)}</div>
                        <div class="card-sub">Includes shipping &amp; tax</div>
                    </div>
                    <div class="card">
                        <div class="card-label">Giveaway Expense</div>
                        <div class="card-value val-negative">${cur(s.giveaway_net)}</div>
                        <div class="card-sub">Included in Shipping fee</div>
                    </div>
                </div>
                <div class="cards-col">
                    <div class="card">
                        <div class="card-label">Payout Amount</div>
                        <div class="card-value ${(s.total_earnings || 0) >= 0 ? 'val-income' : 'val-negative'}">${cur(s.total_earnings)}</div>
                        <div class="card-sub">Total Sales less expenses</div>
                    </div>
                    <div class="card">
                        <div class="card-label">Actual Total Payouts</div>
                        <div class="card-value val-income">${cur(s.total_payouts)}</div>
                        <div class="card-sub"><span class="val-count">${s.payout_count}</span> payout(s)</div>
                        ${(() => {
                            const pending = (s.total_earnings || 0) - (s.total_payouts || 0);
                            if (pending > 0.01) {
                                return `<div class="card-sub" style="margin-top:6px;padding-top:6px;border-top:1px solid #ddd;">
                                    <span style="color:#b8860b;" title="Payout Amount minus Actual Total Payouts">&#9888; Pending: <strong>${cur(pending)}</strong></span>
                                </div>`;
                            }
                            return '';
                        })()}
                    </div>
                </div>
                <div class="cards-col">
                    <div class="card">
                        <div class="card-label">Total Sales</div>
                        <div class="card-value val-income">${cur(s.total_item_price)}</div>
                        <div class="card-sub">SUM of Item Price (Auction)<br>Includes Tips</div>
                    </div>
                    <div class="card">
                        <div class="card-label">Total Fees</div>
                        <div class="card-value val-expense">${cur(s.total_fees)}</div>
                        <div class="card-sub">
                            Commission: ${cur(s.commission_fee)}<br>
                            Processing: ${cur(s.processing_fee)}<br>
                            Shipping: ${cur(s.total_shipping)}<br>
                            Tax on Commission: ${cur(s.tax_on_commission)}<br>
                            Tax on Processing: ${cur(s.tax_on_processing)}<br>
                            <span style="border-top:1px solid #ddd;display:block;margin-top:4px;padding-top:4px;">
                                Auction fees: ${cur(s.auction_fees)} &bull; Giveaway fees: ${cur(s.giveaway_fees)}
                            </span>
                        </div>
                    </div>
                </div>
                <div class="cards-col">
                    <div class="card">
                        <div class="card-label">Item Costs</div>
                        <div class="card-value val-expense">${cur(s.total_costs)}</div>
                        <div class="card-sub">Includes Card, Mags, additional shipping</div>
                    </div>
                    <div class="card">
                        <div class="card-label">Profit</div>
                        <div class="card-value ${profitClass}">${cur(s.profit)}</div>
                        <div class="card-sub">Sales ${cur(s.total_item_price)} - Fees ${cur(s.total_fees)} (incl ${cur(s.giveaway_fees)} giveaway) - Costs ${cur(s.total_costs)}</div>
                    </div>
                    <div class="card">
                        <div class="card-label">Profit %</div>
                        <div class="card-value ${pctClass}">${(s.profit_pct || 0).toFixed(1)}%</div>
                        <div class="card-sub">Profit / Total Sales</div>
                    </div>
                </div>
            </div>
        `;
    },

    renderTopBuyers(s) {
        const container = document.getElementById('dashboard-top-buyers');
        const renderCard = (label, buyer) => {
            if (!buyer) {
                return `<div class="card">
                    <div class="card-label">${label}</div>
                    <div class="card-value" style="font-size:14px;">No data</div>
                </div>`;
            }
            return `<div class="card">
                <div class="card-label">${label}</div>
                <div class="card-value val-buyer" style="font-size:16px;">${buyer.buyer_name} <span style="font-size:11px;color:#888;">#${buyer.buyer_id}</span></div>
                <div class="card-sub"><span class="val-count">${buyer.total_items}</span> items - <span class="val-income">${App.formatCurrency(buyer.total_value)}</span></div>
            </div>`;
        };

        const monthLabel = s.top_buyer_last_month_label || 'Last Month';
        const yearLabel = 'Year ' + (s.top_buyer_year_label || new Date().getFullYear());

        container.innerHTML =
            renderCard('Last Stream', s.top_buyer_last_stream) +
            renderCard(monthLabel, s.top_buyer_last_month) +
            renderCard(yearLabel, s.top_buyer_year);
    },

    renderTopSpenders(s) {
        const container = document.getElementById('dashboard-top-spenders');
        const renderCard = (label, buyer) => {
            if (!buyer) {
                return `<div class="card">
                    <div class="card-label">${label}</div>
                    <div class="card-value" style="font-size:14px;">No data</div>
                </div>`;
            }
            return `<div class="card">
                <div class="card-label">${label}</div>
                <div class="card-value val-buyer" style="font-size:16px;">${buyer.buyer_name} <span style="font-size:11px;color:#888;">#${buyer.buyer_id}</span></div>
                <div class="card-sub"><span class="val-income">${App.formatCurrency(buyer.total_value)}</span> - <span class="val-count">${buyer.total_items}</span> items</div>
            </div>`;
        };

        const monthLabel = s.top_buyer_last_month_label || 'Last Month';
        const yearLabel = 'Year ' + (s.top_buyer_year_label || new Date().getFullYear());

        container.innerHTML =
            renderCard('Last Stream', s.top_spender_last_stream) +
            renderCard(monthLabel, s.top_spender_last_month) +
            renderCard(yearLabel, s.top_spender_year);
    },

    renderLivestreams() {
        const data = this.livestreamData;
        const container = document.getElementById('dashboard-livestreams');
        const countEl = document.getElementById('dashboard-livestream-count');

        countEl.textContent = data.length + ' record(s)';

        if (data.length === 0) {
            container.innerHTML = '<div class="empty-state"><p>No livestream data yet. Upload an earnings CSV to get started.</p></div>';
            return;
        }

        // Client-side sort
        const sorted = [...data].sort((a, b) => {
            let va = a[this.lsSortKey];
            let vb = b[this.lsSortKey];
            if (va == null) va = '';
            if (vb == null) vb = '';
            const numA = parseFloat(va);
            const numB = parseFloat(vb);
            if (!isNaN(numA) && !isNaN(numB)) {
                return this.lsSortDir === 'asc' ? numA - numB : numB - numA;
            }
            const cmp = String(va).localeCompare(String(vb));
            return this.lsSortDir === 'asc' ? cmp : -cmp;
        });

        DataTable.render(container, {
            columns: [
                { key: 'livestream_title', label: 'Livestream', sortable: true },
                { key: 'stream_date', label: 'Date', sortable: true, format: (v) => App.formatDate(v) },
                { key: 'auction_count', label: 'Auctions', align: 'right', sortable: true },
                { key: 'giveaway_count', label: 'Giveaways', align: 'right', sortable: true },
                { key: 'item_count', label: 'Total Items', align: 'right', sortable: true },
                { key: 'shipment_count', label: 'Shipments', align: 'right', sortable: true },
                { key: 'earnings', label: 'Earnings', align: 'right', format: (v) => App.formatCurrency(v), sortable: true },
            ],
            data: sorted,
            total: sorted.length,
            page: 1,
            perPage: 100,
            sortKey: this.lsSortKey,
            sortDir: this.lsSortDir,
            onSort: (key) => {
                if (this.lsSortKey === key) {
                    this.lsSortDir = this.lsSortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    this.lsSortKey = key;
                    this.lsSortDir = 'asc';
                }
                this.renderLivestreams();
            },
        });
    },

    renderTrends(data) {
        const container = document.getElementById('dashboard-trends');
        if (data.length === 0) {
            container.innerHTML = '<div class="empty-state"><p>No trend data available.</p></div>';
            return;
        }

        DataTable.render(container, {
            columns: [
                { key: 'period', label: 'Date', sortable: false },
                { key: 'auction_count', label: 'Auctions', align: 'right', sortable: false },
                { key: 'item_count', label: 'Total Items', align: 'right', sortable: false },
                { key: 'earnings', label: 'Earnings', align: 'right', format: (v) => App.formatCurrency(v), sortable: false },
                { key: 'fees', label: 'Fees', align: 'right', format: (v) => App.formatCurrency(v), sortable: false },
            ],
            data: data,
            total: data.length,
            page: 1,
            perPage: 100,
        });
    }
};
