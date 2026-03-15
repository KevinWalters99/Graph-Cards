/**
 * Card Graph - Cards Analytics Tab
 * Analyzes card performance by player, team, maker, style, specialty.
 */
const CardsAnalytics = {
    initialized: false,
    currentDimension: 'player',
    _data: [],
    _sortKey: 'total_revenue',
    _sortDir: 'desc',

    async init() {
        const panel = document.getElementById('tab-cards-analytics');
        if (!panel) return;

        if (!this.initialized) {
            panel.innerHTML = `
                <div class="page-header">
                    <h1>Cards Analytics</h1>
                </div>
                <div id="ca-totals" class="ca-totals-row"></div>
                <div class="ca-controls">
                    <div class="ca-dimension-tabs" id="ca-dim-tabs">
                        <button class="ca-dim-btn active" data-dim="player">By Player</button>
                        <button class="ca-dim-btn" data-dim="team">By Team</button>
                        <button class="ca-dim-btn" data-dim="maker">By Maker</button>
                        <button class="ca-dim-btn" data-dim="style">By Style</button>
                        <button class="ca-dim-btn" data-dim="specialty">By Specialty</button>
                    </div>
                </div>
                <div id="ca-table-area"></div>
            `;

            document.querySelectorAll('#ca-dim-tabs .ca-dim-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    this.currentDimension = btn.dataset.dim;
                    this._sortKey = 'total_revenue';
                    this._sortDir = 'desc';
                    document.querySelectorAll('#ca-dim-tabs .ca-dim-btn').forEach(b =>
                        b.classList.toggle('active', b === btn));
                    this.loadSummary();
                });
            });

            this.initialized = true;
        }

        this.loadTotals();
        this.loadSummary();
    },

    async loadTotals() {
        try {
            const data = await API.get('/api/cards-analytics/totals');
            this.renderTotals(data);
        } catch (err) {
            // Silent fail on totals
        }
    },

    renderTotals(data) {
        const container = document.getElementById('ca-totals');
        const cur = (v) => App.formatCurrency(v);

        container.innerHTML = `
            <div class="ca-stat-card">
                <div class="ca-stat-value">${data.total_aligned}</div>
                <div class="ca-stat-label">Cards Analyzed</div>
            </div>
            <div class="ca-stat-card">
                <div class="ca-stat-value">${cur(data.total_revenue)}</div>
                <div class="ca-stat-label">Total Revenue</div>
            </div>
            <div class="ca-stat-card">
                <div class="ca-stat-value">${cur(data.avg_price)}</div>
                <div class="ca-stat-label">Avg Price</div>
            </div>
            <div class="ca-stat-card">
                <div class="ca-stat-value">${data.unique_players}</div>
                <div class="ca-stat-label">Players</div>
            </div>
            <div class="ca-stat-card">
                <div class="ca-stat-value">${data.unique_teams}</div>
                <div class="ca-stat-label">Teams</div>
            </div>
            <div class="ca-stat-card">
                <div class="ca-stat-value">${data.rookie_count}</div>
                <div class="ca-stat-label">Rookies</div>
            </div>
            <div class="ca-stat-card">
                <div class="ca-stat-value">${data.auto_count}</div>
                <div class="ca-stat-label">Autographs</div>
            </div>
            <div class="ca-stat-card">
                <div class="ca-stat-value">${data.graded_count}</div>
                <div class="ca-stat-label">Graded</div>
            </div>
        `;
    },

    async loadSummary() {
        const area = document.getElementById('ca-table-area');
        area.innerHTML = '<p style="padding:24px;color:#888;">Loading...</p>';

        try {
            const data = await API.get('/api/cards-analytics/summary', {
                dimension: this.currentDimension,
                sort: 'revenue',
                order: 'desc',
                limit: 500,
            });
            this._data = data.data || [];
            this._sortAndRender();
        } catch (err) {
            area.innerHTML = '<p style="padding:24px;color:#c62828;">Failed to load data: ' + err.message + '</p>';
        }
    },

    _sortAndRender() {
        const key = this._sortKey;
        const dir = this._sortDir;
        const sorted = [...this._data].sort((a, b) => {
            let va = a[key], vb = b[key];
            if (typeof va === 'string') {
                va = va.toLowerCase();
                vb = (vb || '').toLowerCase();
                return dir === 'asc' ? va.localeCompare(vb) : vb.localeCompare(va);
            }
            va = va || 0;
            vb = vb || 0;
            return dir === 'asc' ? va - vb : vb - va;
        });
        this.renderTable(sorted, this.currentDimension);
    },

    renderTable(rows, dimension) {
        const area = document.getElementById('ca-table-area');
        const cur = (v) => App.formatCurrency(v);
        const self = this;

        const dimLabels = {
            player: 'Player', team: 'Team', maker: 'Maker',
            style: 'Style', specialty: 'Specialty'
        };

        if (rows.length === 0) {
            area.innerHTML = '<p style="padding:24px;color:#888;">No aligned card data available. Cards must be parsed and aligned to line items to appear here.</p>';
            return;
        }

        // Build dimension label column with optional imagery
        const dimCol = { key: 'dimension_label', label: dimLabels[dimension] || 'Name' };
        if (dimension === 'player') {
            dimCol.render = function(row) {
                let html = '<div class="ca-label-cell">';
                if (row.team_mlb_id) {
                    html += `<img class="ca-team-logo" src="/img/teams/${row.team_mlb_id}.png" alt="" onerror="this.style.display='none'">`;
                }
                if (row.player_mlb_id) {
                    html += `<img class="ca-player-photo" src="https://img.mlbstatic.com/mlb-photos/image/upload/d_people:generic:headshot:silo:current.png/w_60,q_auto:best/v1/people/${row.player_mlb_id}/headshot/silo/current" alt="" onerror="this.style.display='none'">`;
                }
                html += `<span>${row.dimension_label}</span></div>`;
                return html;
            };
        } else if (dimension === 'maker') {
            const makerLogos = {
                'topps': 'topps', 'bowman': 'bowman', 'panini': 'panini',
                'upper deck': 'upper_deck', 'donruss': 'donruss',
                'fleer': 'fleer', 'leaf': 'leaf'
            };
            dimCol.render = function(row) {
                let html = '<div class="ca-label-cell">';
                const key = (row.dimension_label || '').toLowerCase();
                const logo = makerLogos[key];
                if (logo) {
                    html += `<img class="ca-maker-logo" src="/img/makers/${logo}.png" alt="" onerror="this.style.display='none'">`;
                }
                html += `<span>${row.dimension_label}</span></div>`;
                return html;
            };
        } else if (dimension === 'team') {
            dimCol.render = function(row) {
                let html = '<div class="ca-label-cell">';
                if (row.team_mlb_id) {
                    html += `<img class="ca-team-logo" src="/img/teams/${row.team_mlb_id}.png" alt="" onerror="this.style.display='none'">`;
                }
                html += `<span>${row.dimension_label}</span></div>`;
                return html;
            };
        }

        const columns = [
            { key: 'rank', label: '#', sortable: false },
            dimCol,
            { key: 'auction_count', label: 'Auctions', align: 'right' },
            { key: 'total_revenue', label: 'Revenue', align: 'right', format: cur },
            { key: 'avg_price', label: 'Avg Price', align: 'right', format: cur },
            { key: 'min_price', label: 'Min', align: 'right', format: cur },
            { key: 'max_price', label: 'Max', align: 'right', format: cur },
            { key: 'unique_buyers', label: 'Buyers', align: 'right' },
            { key: 'stream_count', label: 'Streams', align: 'right' },
        ];

        if (dimension === 'player') {
            columns.push(
                { key: 'rookie_count', label: 'RC', align: 'right' },
                { key: 'auto_count', label: 'Auto', align: 'right' },
                { key: 'graded_count', label: 'Graded', align: 'right' }
            );
        }

        if (dimension === 'player' || dimension === 'team') {
            columns.push(
                { key: 'giveaway_count', label: 'Giveaways', align: 'right' }
            );
        }

        // Add rank after sorting
        const rankedRows = rows.map((row, i) => ({ ...row, rank: i + 1 }));

        DataTable.render(area, {
            columns: columns,
            data: rankedRows,
            total: rankedRows.length,
            page: 1,
            perPage: 100,
            sortKey: this._sortKey,
            sortDir: this._sortDir,
            onSort: function(key) {
                if (self._sortKey === key) {
                    self._sortDir = self._sortDir === 'desc' ? 'asc' : 'desc';
                } else {
                    self._sortKey = key;
                    self._sortDir = 'desc';
                }
                self._sortAndRender();
            },
        });
    }
};
