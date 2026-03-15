/**
 * Card Graph - Top Buyers Tab
 */
const TopBuyers = {
    initialized: false,
    livestreams: [],
    currentData: [],
    sortKey: 'total_quantity',
    sortDir: 'desc',

    async init() {
        const panel = document.getElementById('tab-top-buyers');

        if (!this.initialized) {
            panel.innerHTML = `
                <div class="page-header">
                    <h1>Top Buyers</h1>
                </div>
                <div class="mb-4">
                    <div class="form-group">
                        <label>Select Livestream</label>
                        <select id="top-buyers-livestream" style="width:100%;max-width:600px;">
                            <option value="">Loading...</option>
                        </select>
                    </div>
                </div>
                <div id="top-buyers-table"></div>
            `;

            document.getElementById('top-buyers-livestream').addEventListener('change', (e) => {
                if (e.target.value) {
                    this.sortKey = 'total_quantity';
                    this.sortDir = 'desc';
                    this.loadBuyers(e.target.value);
                } else {
                    document.getElementById('top-buyers-table').innerHTML =
                        '<div class="empty-state"><p>Select a livestream to view top buyers.</p></div>';
                }
            });

            this.initialized = true;
        }

        await this.loadLivestreams();
    },

    async loadLivestreams() {
        try {
            App.showLoading();
            const result = await API.get('/api/top-buyers/livestreams');
            this.livestreams = result.data || [];
            const years = result.years || [];

            const select = document.getElementById('top-buyers-livestream');
            select.innerHTML = '<option value="">-- Select --</option>';

            // Add "All Streams" option
            const allOpt = document.createElement('option');
            allOpt.value = 'all';
            allOpt.textContent = 'All Streams';
            select.appendChild(allOpt);

            // Add time period options
            const now = new Date();
            const currentYear = now.getFullYear();
            const currentMonth = now.getMonth() + 1;
            const currentQuarter = Math.ceil(currentMonth / 3);

            // This Month
            const thisMonthYM = currentYear + '-' + String(currentMonth).padStart(2, '0');
            const thisMonthOpt = document.createElement('option');
            thisMonthOpt.value = 'month:' + thisMonthYM;
            thisMonthOpt.textContent = 'This Month (' + now.toLocaleString('en-US', { month: 'long' }) + ')';
            select.appendChild(thisMonthOpt);

            // Last Month
            const lastMonthDate = new Date(currentYear, currentMonth - 2, 1);
            const lastMonthYM = lastMonthDate.getFullYear() + '-' + String(lastMonthDate.getMonth() + 1).padStart(2, '0');
            const lastMonthOpt = document.createElement('option');
            lastMonthOpt.value = 'month:' + lastMonthYM;
            lastMonthOpt.textContent = 'Last Month (' + lastMonthDate.toLocaleString('en-US', { month: 'long' }) + ')';
            select.appendChild(lastMonthOpt);

            // This Quarter
            const thisQtrOpt = document.createElement('option');
            thisQtrOpt.value = 'quarter:' + currentYear + '-' + currentQuarter;
            thisQtrOpt.textContent = 'This Quarter (Q' + currentQuarter + ' ' + currentYear + ')';
            select.appendChild(thisQtrOpt);

            // Year options
            years.forEach(yr => {
                const opt = document.createElement('option');
                opt.value = 'year:' + yr;
                if (parseInt(yr) === currentYear) {
                    opt.textContent = 'This Year (' + yr + ')';
                } else if (parseInt(yr) === currentYear - 1) {
                    opt.textContent = 'Last Year (' + yr + ')';
                } else {
                    opt.textContent = 'Year ' + yr;
                }
                select.appendChild(opt);
            });

            // Add separator
            const sep = document.createElement('option');
            sep.disabled = true;
            sep.textContent = '---';
            select.appendChild(sep);

            // Add individual livestreams - date first
            this.livestreams.forEach(ls => {
                const date = ls.stream_date ? App.formatDate(ls.stream_date) : 'Unknown';
                const label = date + ' - ' + ls.livestream_title + ' - Auction - ' + ls.auction_count;
                const option = document.createElement('option');
                option.value = ls.livestream_id;
                option.textContent = label;
                select.appendChild(option);
            });

            // Auto-select first livestream
            if (this.livestreams.length > 0) {
                select.value = this.livestreams[0].livestream_id;
                this.loadBuyers(this.livestreams[0].livestream_id);
            }
        } catch (err) {
            App.toast(err.message, 'error');
        } finally {
            App.hideLoading();
        }
    },

    async loadBuyers(filter) {
        try {
            App.showLoading();
            const result = await API.get('/api/top-buyers', { filter: filter });
            this.currentData = (result.data || []).map(row => ({
                ...row,
                auctions_won: parseInt(row.auctions_won) || 0,
                giveaways_won: parseInt(row.giveaways_won) || 0,
                items_quantity: parseInt(row.items_quantity) || 0,
                giveaway_quantity: parseInt(row.giveaway_quantity) || 0,
                total_quantity: parseInt(row.total_quantity) || 0,
                total_earnings: parseFloat(row.total_earnings) || 0,
                total_costs: parseFloat(row.total_costs) || 0,
                has_purchases: parseInt(row.has_purchases) || 0,
            }));
            this.renderTable();
        } catch (err) {
            App.toast(err.message, 'error');
        } finally {
            App.hideLoading();
        }
    },

    sortData(data) {
        const key = this.sortKey;
        const dir = this.sortDir === 'asc' ? 1 : -1;

        return [...data].sort((a, b) => {
            // Always keep purchasers above giveaway-only
            if (a.has_purchases !== b.has_purchases) {
                return b.has_purchases - a.has_purchases;
            }
            // Then sort by selected column
            const aVal = typeof a[key] === 'string' ? a[key].toLowerCase() : a[key];
            const bVal = typeof b[key] === 'string' ? b[key].toLowerCase() : b[key];
            if (aVal < bVal) return -1 * dir;
            if (aVal > bVal) return 1 * dir;
            return 0;
        });
    },

    handleSort(key) {
        if (this.sortKey === key) {
            this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            this.sortKey = key;
            this.sortDir = 'desc';
        }
        this.renderTable();
    },

    renderTable() {
        const container = document.getElementById('top-buyers-table');
        const sorted = this.sortData(this.currentData);

        if (sorted.length === 0) {
            container.innerHTML = '<div class="empty-state"><p>No buyer data found.</p></div>';
            return;
        }

        DataTable.render(container, {
            columns: [
                { key: 'buyer_name', label: 'Buyer' },
                { key: 'auctions_won', label: 'Auctions Won', align: 'right' },
                { key: 'giveaways_won', label: 'Giveaways Won', align: 'right' },
                { key: 'items_quantity', label: 'Items Qty', align: 'right' },
                { key: 'giveaway_quantity', label: 'Giveaway Qty', align: 'right' },
                { key: 'total_quantity', label: 'Total Items', align: 'right' },
                { key: 'total_earnings', label: 'Earnings', align: 'right',
                  format: (v) => App.formatCurrency(v) },
                { key: 'total_costs', label: 'Costs', align: 'right',
                  format: (v) => App.formatCurrency(v) },
                {
                    key: 'has_purchases', label: 'Type',
                    render: (row) => {
                        if (row.has_purchases) {
                            return '<span class="status-badge status-completed">Purchaser</span>';
                        }
                        return '<span class="status-badge status-pending">Giveaway Only</span>';
                    }
                },
            ],
            data: sorted,
            total: sorted.length,
            page: 1,
            perPage: 500,
            sortKey: this.sortKey,
            sortDir: this.sortDir,
            onSort: (key) => this.handleSort(key),
        });
    }
};
