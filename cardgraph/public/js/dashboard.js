/**
 * Card Graph — Dashboard Tab
 */
const Dashboard = {
    initialized: false,

    async init() {
        const panel = document.getElementById('tab-dashboard');

        if (!this.initialized) {
            panel.innerHTML = `
                <div class="page-header">
                    <h1>Dashboard</h1>
                    <button class="btn btn-primary" id="btn-upload-earnings">Upload Earnings CSV</button>
                </div>
                <div id="dashboard-filters"></div>
                <div id="dashboard-cards" class="cards-grid"></div>
                <div class="mt-4">
                    <h3 class="section-title">Top Buyers</h3>
                    <div id="dashboard-top-buyers" class="cards-grid"></div>
                </div>
                <div class="mt-4">
                    <h3 class="section-title">Earnings by Livestream</h3>
                    <div id="dashboard-livestreams"></div>
                </div>
                <div class="mt-4">
                    <h3 class="section-title">Daily Trends</h3>
                    <div id="dashboard-trends"></div>
                </div>
            `;

            // Upload button
            document.getElementById('btn-upload-earnings').addEventListener('click', () => {
                Upload.showModal(
                    'Upload Earnings CSV',
                    '/api/uploads/earnings',
                    'Accepted format: month_day_month_day_year_earnings.csv',
                    () => { this.loadData(); }
                );
            });

            // Filters
            Filters.render(document.getElementById('dashboard-filters'), [
                { type: 'date', name: 'date_from', label: 'From Date' },
                { type: 'date', name: 'date_to', label: 'To Date' },
            ], (filters) => this.loadData(filters));

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
            this.renderLivestreams(trends.livestreams || []);
            this.renderTrends(trends.trends || []);
        } catch (err) {
            App.toast(err.message, 'error');
        } finally {
            App.hideLoading();
        }
    },

    renderCards(s) {
        const container = document.getElementById('dashboard-cards');
        container.innerHTML = `
            <div class="card">
                <div class="card-label">Total Earnings</div>
                <div class="card-value ${s.total_earnings >= 0 ? 'positive' : 'negative'}">${App.formatCurrency(s.total_earnings)}</div>
                <div class="card-sub">${s.statements_uploaded} statement(s) uploaded</div>
            </div>
            <div class="card">
                <div class="card-label">Total Fees</div>
                <div class="card-value">${App.formatCurrency(s.total_fees)}</div>
                <div class="card-sub">Commission + processing</div>
            </div>
            <div class="card">
                <div class="card-label">Items Sold</div>
                <div class="card-value">${s.auction_count}</div>
                <div class="card-sub">Avg price: ${App.formatCurrency(s.avg_auction_price)}</div>
            </div>
            <div class="card">
                <div class="card-label">Giveaways</div>
                <div class="card-value">${s.giveaway_count}</div>
                <div class="card-sub">${s.total_items} total line items</div>
            </div>
            <div class="card">
                <div class="card-label">Unique Buyers</div>
                <div class="card-value">${s.unique_buyers}</div>
                <div class="card-sub">${s.unique_livestreams} livestream(s)</div>
            </div>
            <div class="card">
                <div class="card-label">Total Payouts</div>
                <div class="card-value">${App.formatCurrency(s.total_payouts)}</div>
                <div class="card-sub">${s.payout_count} payout(s)</div>
            </div>
            <div class="card">
                <div class="card-label">Total Costs</div>
                <div class="card-value">${App.formatCurrency(s.total_costs)}</div>
                <div class="card-sub">Manual cost entries</div>
            </div>
            <div class="card">
                <div class="card-label">Tips</div>
                <div class="card-value">${App.formatCurrency(s.total_tips)}</div>
                <div class="card-sub">${s.tip_count} tip(s) received</div>
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
                <div class="card-value" style="font-size:18px;">${buyer.buyer_name}</div>
                <div class="card-sub">${buyer.total_items} items - ${App.formatCurrency(buyer.total_value)}</div>
            </div>`;
        };

        const lastStreamLabel = s.top_buyer_last_stream_label
            ? 'Last Stream' : 'Last Stream';
        const monthLabel = s.top_buyer_last_month_label || 'Last Month';
        const yearLabel = 'Year ' + (s.top_buyer_year_label || new Date().getFullYear());

        container.innerHTML =
            renderCard('Top Buyer - Last Stream', s.top_buyer_last_stream) +
            renderCard('Top Buyer - ' + monthLabel, s.top_buyer_last_month) +
            renderCard('Top Buyer - ' + yearLabel, s.top_buyer_year);
    },

    renderLivestreams(data) {
        const container = document.getElementById('dashboard-livestreams');
        if (data.length === 0) {
            container.innerHTML = '<div class="empty-state"><p>No livestream data yet. Upload an earnings CSV to get started.</p></div>';
            return;
        }

        DataTable.render(container, {
            columns: [
                { key: 'livestream_title', label: 'Livestream', sortable: false },
                { key: 'stream_date', label: 'Date', sortable: false, format: (v) => App.formatDate(v) },
                { key: 'auction_count', label: 'Auctions', align: 'right', sortable: false },
                { key: 'giveaway_count', label: 'Giveaways', align: 'right', sortable: false },
                { key: 'item_count', label: 'Total Items', align: 'right', sortable: false },
                { key: 'earnings', label: 'Earnings', align: 'right', format: (v) => App.formatCurrency(v), sortable: false },
            ],
            data: data,
            total: data.length,
            page: 1,
            perPage: 100,
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
