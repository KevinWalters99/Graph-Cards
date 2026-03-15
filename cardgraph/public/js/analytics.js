/**
 * Card Graph — Analytics & Forecasting Tab
 * Sub-tabs: Summary + one per metric category.
 */
const Analytics = {
    initialized: false,
    currentSubTab: 'summary',
    metrics: [],
    actuals: [],
    charts: {},

    async init() {
        const panel = document.getElementById('tab-analytics');

        if (!this.initialized) {
            panel.innerHTML = `
                <div class="page-header">
                    <h1>Analytics &amp; Forecasting</h1>
                </div>
                <div class="sub-tabs" id="analytics-sub-tabs"></div>
                <div id="analytics-content"></div>
            `;

            await this.loadMetrics();
            this.buildSubTabs();
            this.initialized = true;
        }

        this.switchSubTab(this.currentSubTab);
    },

    async loadMetrics() {
        try {
            const result = await API.get('/api/analytics/metrics');
            this.metrics = (result.data || []).filter(m => m.is_active == 1);
        } catch (e) { /* silent */ }
    },

    buildSubTabs() {
        const container = document.getElementById('analytics-sub-tabs');
        let html = '<button class="sub-tab active" data-subtab="summary">Summary</button>';
        this.metrics.forEach(m => {
            html += `<button class="sub-tab" data-subtab="${m.metric_key}">${m.metric_name}</button>`;
        });
        container.innerHTML = html;

        container.querySelectorAll('.sub-tab').forEach(btn => {
            btn.addEventListener('click', () => this.switchSubTab(btn.dataset.subtab));
        });
    },

    switchSubTab(name) {
        this.currentSubTab = name;
        document.querySelectorAll('#analytics-sub-tabs .sub-tab').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.subtab === name);
        });

        // Destroy any existing charts
        Object.keys(this.charts).forEach(id => this.destroyChart(id));

        if (name === 'summary') {
            this.loadSummary();
        } else {
            this.loadCategory(name);
        }
    },

    // =========================================================
    // Summary Sub-tab
    // =========================================================
    async loadSummary() {
        const container = document.getElementById('analytics-content');
        container.innerHTML = '<p class="text-muted">Loading analytics...</p>';

        try {
            App.showLoading();
            const [actualsResult, pacingResult] = await Promise.all([
                API.get('/api/analytics/actuals'),
                API.get('/api/analytics/pacing'),
            ]);
            this.actuals = actualsResult.monthly || [];
            this.renderSummary(pacingResult.milestones || []);
        } catch (err) {
            container.innerHTML = `<p class="text-danger">${err.message}</p>`;
        } finally {
            App.hideLoading();
        }
    },

    renderSummary(milestones) {
        const container = document.getElementById('analytics-content');
        const allActuals = this.actuals;

        if (allActuals.length === 0) {
            container.innerHTML = '<div class="empty-state"><p>No data available yet. Upload earnings CSVs to populate analytics.</p></div>';
            return;
        }

        // Separate completed from current partial month
        const completed = allActuals.filter(r => !r.is_partial);
        const partial = allActuals.find(r => r.is_partial) || null;

        const current = completed.length > 0 ? completed[completed.length - 1] : null;
        const prev = completed.length > 1 ? completed[completed.length - 2] : null;

        // Summary cards (based on last COMPLETED month)
        let cardsHtml = '';
        if (current) {
            cardsHtml = '<div class="analytics-summary-grid">';
            this.metrics.forEach(m => {
                const val = current[m.metric_key];
                const prevVal = prev ? prev[m.metric_key] : null;
                const formatted = this.formatValue(val, m.unit_type);
                const trend = this.trendArrow(val, prevVal);
                const colorClass = m.unit_type === 'currency' ? 'val-income' : 'val-count';

                cardsHtml += `
                    <div class="analytics-summary-card">
                        <div class="metric-info">
                            <div class="card-label">${m.metric_name}</div>
                            <div class="card-value ${colorClass}" style="font-size:22px;">${formatted}</div>
                            <div class="card-sub">${current.period} ${trend}</div>
                        </div>
                        <div class="sparkline-container">
                            <canvas id="spark-${m.metric_key}"></canvas>
                        </div>
                    </div>
                `;
            });
            cardsHtml += '</div>';
        }

        // Pacing section
        let pacingHtml = '';
        if (milestones.length > 0) {
            pacingHtml = '<h3 class="section-title" style="margin-top:24px;">Milestone Pacing</h3>';
            pacingHtml += '<div class="pacing-grid">';
            milestones.forEach(ms => {
                pacingHtml += this.renderPacingCard(ms);
            });
            pacingHtml += '</div>';
        }

        container.innerHTML = cardsHtml + pacingHtml;

        // Sparklines use completed months only (no partial month dip)
        this.metrics.forEach(m => {
            const values = completed.map(r => parseFloat(r[m.metric_key]) || 0);
            this.renderSparkline(`spark-${m.metric_key}`, values);
        });
    },

    // =========================================================
    // Category Sub-tab
    // =========================================================
    async loadCategory(metricKey) {
        const container = document.getElementById('analytics-content');
        container.innerHTML = '<p class="text-muted">Loading...</p>';

        const metric = this.metrics.find(m => m.metric_key === metricKey);
        if (!metric) {
            container.innerHTML = '<p class="text-danger">Metric not found.</p>';
            return;
        }

        try {
            App.showLoading();
            const [forecastResult, pacingResult] = await Promise.all([
                API.get('/api/analytics/forecast', { metric: metricKey, periods_ahead: 6 }),
                API.get('/api/analytics/pacing', { metric_id: metric.metric_id }),
            ]);

            this.renderCategory(metric, forecastResult, pacingResult.milestones || []);
        } catch (err) {
            container.innerHTML = `<p class="text-danger">${err.message}</p>`;
        } finally {
            App.hideLoading();
        }
    },

    renderCategory(metric, forecastData, milestones) {
        const container = document.getElementById('analytics-content');
        const historical = forecastData.historical || [];
        const forecast = forecastData.forecast || [];
        const trend = forecastData.trend || {};
        const currentMonth = forecastData.current_month || null;

        if (historical.length === 0 && !currentMonth) {
            container.innerHTML = '<div class="empty-state"><p>No data available for this metric.</p></div>';
            return;
        }

        const trendIcon = trend.direction === 'up' ? '&#9650;' : trend.direction === 'down' ? '&#9660;' : '&#9654;';
        const trendColor = trend.direction === 'up' ? 'val-income' : trend.direction === 'down' ? 'val-negative' : '';
        const r2Pct = (trend.r_squared * 100).toFixed(0);
        const valClass = metric.unit_type === 'currency' ? 'val-income' : 'val-count';
        const lastCompleted = historical.length > 0 ? historical[historical.length - 1] : null;

        let html = '<div class="cards-row" style="margin-bottom:20px;"><div class="cards-group">';

        // Last completed month
        if (lastCompleted) {
            html += `
                <div class="card">
                    <div class="card-label">Last Completed (${lastCompleted.period})</div>
                    <div class="card-value ${valClass}">${this.formatValue(lastCompleted.value, metric.unit_type)}</div>
                </div>`;
        }

        // Current month progress
        if (currentMonth) {
            html += `
                <div class="card">
                    <div class="card-label">Current Month (${currentMonth.period})</div>
                    <div class="card-value val-count" style="font-size:18px;">${this.formatValue(currentMonth.actual, metric.unit_type)}</div>
                    <div class="card-sub">Day ${currentMonth.days_elapsed} of ${currentMonth.days_in_month} &middot; On pace: <span class="${valClass}">${this.formatValue(currentMonth.prorated, metric.unit_type)}</span></div>
                </div>
                <div class="card">
                    <div class="card-label">Month Forecast</div>
                    <div class="card-value ${valClass}" style="font-size:18px;">${this.formatValue(currentMonth.forecast, metric.unit_type)}</div>
                    <div class="card-sub">${this.formatValue(currentMonth.lower, metric.unit_type)} - ${this.formatValue(currentMonth.upper, metric.unit_type)}</div>
                </div>`;
        }

        // Trend
        html += `
                <div class="card">
                    <div class="card-label">Trend</div>
                    <div class="card-value ${trendColor}" style="font-size:20px;">${trendIcon} ${trend.direction}</div>
                    <div class="card-sub">R&sup2; = ${r2Pct}% confidence</div>
                </div>`;

        // Next future month forecast
        if (forecast.length > 0) {
            html += `
                <div class="card">
                    <div class="card-label">Next Month (${forecast[0].period})</div>
                    <div class="card-value val-count">${this.formatValue(forecast[0].value, metric.unit_type)}</div>
                    <div class="card-sub">${this.formatValue(forecast[0].lower, metric.unit_type)} - ${this.formatValue(forecast[0].upper, metric.unit_type)}</div>
                </div>`;
        }

        html += '</div></div>';

        // Chart
        html += `<div class="chart-container"><canvas id="chart-${metric.metric_key}"></canvas></div>`;

        // Pacing
        if (milestones.length > 0) {
            html += '<h3 class="section-title" style="margin-top:24px;">Milestone Pacing</h3>';
            html += '<div class="pacing-grid">';
            milestones.forEach(ms => { html += this.renderPacingCard(ms); });
            html += '</div>';
        }

        // Data table
        html += '<h3 class="section-title" style="margin-top:24px;">Monthly Data</h3>';
        html += '<div id="analytics-data-table"></div>';

        container.innerHTML = html;

        // Render chart with current position flag
        this.renderTrendChart(`chart-${metric.metric_key}`, historical, forecast, metric, milestones, currentMonth);

        // Build data table rows
        const tableData = [
            ...historical.map(h => ({ period: h.period, actual: h.value, forecast: null, type: 'Actual' })),
        ];
        if (currentMonth) {
            tableData.push({
                period: currentMonth.period,
                actual: currentMonth.actual,
                forecast: currentMonth.forecast,
                type: 'In Progress',
            });
        }
        tableData.push(...forecast.map(f => ({ period: f.period, actual: null, forecast: f.value, type: 'Forecast' })));

        DataTable.render(document.getElementById('analytics-data-table'), {
            columns: [
                { key: 'period', label: 'Period', sortable: false },
                {
                    key: 'actual', label: 'Actual', align: 'right', sortable: false,
                    render: (row) => row.actual !== null ? this.formatValue(row.actual, metric.unit_type) : '<span class="text-muted">-</span>'
                },
                {
                    key: 'forecast', label: 'Forecast', align: 'right', sortable: false,
                    render: (row) => row.forecast !== null ? this.formatValue(row.forecast, metric.unit_type) : '<span class="text-muted">-</span>'
                },
                {
                    key: 'type', label: 'Type', sortable: false,
                    render: (row) => {
                        const cls = row.type === 'Actual' ? 'status-completed'
                            : row.type === 'In Progress' ? 'status-in-progress'
                            : 'status-pending';
                        return `<span class="status-badge ${cls}">${row.type}</span>`;
                    }
                },
            ],
            data: tableData,
            total: tableData.length,
            page: 1,
            perPage: 100,
        });
    },

    // =========================================================
    // Chart.js Renderers
    // =========================================================
    renderTrendChart(canvasId, historical, forecast, metric, milestones, currentMonth = null) {
        this.destroyChart(canvasId);

        const ctx = document.getElementById(canvasId);
        if (!ctx) return;

        const hasPartial = !!currentMonth;

        // Build labels: completed months + current month + future months
        const labels = [...historical.map(h => h.period)];
        if (hasPartial) labels.push(currentMonth.period);
        labels.push(...forecast.map(f => f.period));

        const totalLen = labels.length;
        const histLen = historical.length;
        const curIdx = hasPartial ? histLen : -1;
        const forecastStartIdx = hasPartial ? histLen + 1 : histLen;

        // Dataset 1: Actual (solid line) — completed months only
        const actualData = [
            ...historical.map(h => h.value),
            ...Array(totalLen - histLen).fill(null),
        ];

        // Dataset 2: Forecast (dashed line) — smooth continuation from last completed
        const forecastLine = Array(totalLen).fill(null);
        if (histLen > 0) forecastLine[histLen - 1] = historical[histLen - 1].value;
        if (hasPartial) forecastLine[curIdx] = currentMonth.forecast;
        forecast.forEach((f, i) => { forecastLine[forecastStartIdx + i] = f.value; });

        // Datasets 3+4: Confidence band (upper fills to lower)
        const upperData = Array(totalLen).fill(null);
        const lowerData = Array(totalLen).fill(null);
        if (histLen > 0) {
            upperData[histLen - 1] = historical[histLen - 1].value;
            lowerData[histLen - 1] = historical[histLen - 1].value;
        }
        if (hasPartial) {
            upperData[curIdx] = currentMonth.upper;
            lowerData[curIdx] = currentMonth.lower;
        }
        forecast.forEach((f, i) => {
            upperData[forecastStartIdx + i] = f.upper;
            lowerData[forecastStartIdx + i] = f.lower;
        });

        // Dataset 5: Current position flag (single point)
        const flagData = Array(totalLen).fill(null);
        if (hasPartial) flagData[curIdx] = currentMonth.actual;

        // Dataset 6: On-pace estimate (single diamond)
        const proratedData = Array(totalLen).fill(null);
        if (hasPartial) proratedData[curIdx] = currentMonth.prorated;

        const datasets = [
            {
                label: metric.metric_name + ' (Actual)',
                data: actualData,
                borderColor: '#1a3a6b',
                backgroundColor: 'rgba(26, 58, 107, 0.05)',
                fill: false,
                tension: 0.3,
                pointRadius: 3,
                borderWidth: 2,
            },
            {
                label: metric.metric_name + ' (Forecast)',
                data: forecastLine,
                borderColor: '#4a9eff',
                borderDash: [6, 3],
                fill: false,
                tension: 0.3,
                pointRadius: 2,
                borderWidth: 2,
            },
            {
                label: 'Upper Bound',
                data: upperData,
                borderColor: 'transparent',
                backgroundColor: 'rgba(74, 158, 255, 0.1)',
                fill: '+1',
                pointRadius: 0,
            },
            {
                label: 'Lower Bound',
                data: lowerData,
                borderColor: 'transparent',
                fill: false,
                pointRadius: 0,
            },
        ];

        // Current position flag (red triangle)
        if (hasPartial) {
            datasets.push({
                label: 'Current Position',
                data: flagData,
                borderColor: '#c0392b',
                backgroundColor: '#c0392b',
                pointRadius: 8,
                pointStyle: 'triangle',
                showLine: false,
                fill: false,
            });
            // On-pace estimate (green diamond)
            datasets.push({
                label: 'On Pace',
                data: proratedData,
                borderColor: '#27ae60',
                backgroundColor: '#27ae60',
                pointRadius: 6,
                pointStyle: 'rectRot',
                showLine: false,
                fill: false,
            });
        }

        // Milestone target lines
        milestones.forEach((ms, idx) => {
            datasets.push({
                label: ms.milestone_name + ' Target',
                data: Array(totalLen).fill(ms.target_value),
                borderColor: ['#d4930d', '#c0392b', '#7b1fa2', '#00838f'][idx % 4],
                borderDash: [4, 4],
                borderWidth: 1,
                pointRadius: 0,
                fill: false,
            });
        });

        const self = this;
        this.charts[canvasId] = new Chart(ctx, {
            type: 'line',
            data: { labels, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            filter: (item) => !['Upper Bound', 'Lower Bound'].includes(item.text),
                            usePointStyle: true,
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                if (['Upper Bound', 'Lower Bound'].includes(context.dataset.label)) return null;
                                const val = context.parsed.y;
                                if (val === null) return null;
                                return context.dataset.label + ': ' + self.formatValue(val, metric.unit_type);
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: (val) => self.formatValueShort(val, metric.unit_type)
                        }
                    }
                }
            }
        });
    },

    renderSparkline(canvasId, values) {
        const ctx = document.getElementById(canvasId);
        if (!ctx || values.length < 2) return;

        // Only show last 12 months
        const data = values.slice(-12);

        this.charts[canvasId] = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.map((_, i) => i),
                datasets: [{
                    data: data,
                    borderColor: '#4a9eff',
                    borderWidth: 1.5,
                    fill: false,
                    tension: 0.4,
                    pointRadius: 0,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
                scales: {
                    x: { display: false },
                    y: { display: false }
                },
                elements: { line: { borderWidth: 1.5 } }
            }
        });
    },

    // =========================================================
    // Pacing Card
    // =========================================================
    renderPacingCard(ms) {
        const pct = Math.min(ms.percent_complete, 100);
        const statusClass = this.getPacingClass(ms.pacing_status);
        const statusLabel = ms.pacing_status.replace(/_/g, ' ');

        return `
            <div class="analytics-pacing-card">
                <div class="card-label">${ms.milestone_name}</div>
                <div class="pacing-bar-container">
                    <div class="pacing-bar">
                        <div class="pacing-bar-fill ${statusClass}" style="width:${pct}%"></div>
                        <div class="pacing-bar-target" style="left:${Math.min(ms.percent_time_elapsed, 100)}%"></div>
                    </div>
                </div>
                <div class="pacing-stats">
                    <span class="pacing-actual">${this.formatValue(ms.actual_value, ms.unit_type)}</span>
                    <span class="pacing-sep">/</span>
                    <span class="pacing-target">${this.formatValue(ms.target_value, ms.unit_type)}</span>
                </div>
                <div class="pacing-status">
                    <span class="pacing-badge ${statusClass}">${statusLabel}</span>
                    <span class="pacing-forecast">Forecast: ${this.formatValue(ms.forecasted_end_value, ms.unit_type)}</span>
                </div>
                <div class="pacing-time">${ms.days_remaining} days remaining</div>
            </div>
        `;
    },

    // =========================================================
    // Utilities
    // =========================================================
    formatValue(value, unitType) {
        if (value === null || value === undefined) return '-';
        switch (unitType) {
            case 'currency': return App.formatCurrency(value);
            case 'percent':  return parseFloat(value).toFixed(1) + '%';
            case 'count':    return parseInt(value).toLocaleString();
            default:         return String(value);
        }
    },

    formatValueShort(value, unitType) {
        if (value === null || value === undefined) return '';
        if (unitType === 'currency') {
            if (Math.abs(value) >= 1000) return '$' + (value / 1000).toFixed(1) + 'k';
            return '$' + parseFloat(value).toFixed(0);
        }
        if (unitType === 'percent') return parseFloat(value).toFixed(0) + '%';
        if (Math.abs(value) >= 1000) return (value / 1000).toFixed(1) + 'k';
        return String(parseInt(value));
    },

    trendArrow(current, previous) {
        if (previous === null || previous === undefined) return '';
        const diff = parseFloat(current) - parseFloat(previous);
        if (Math.abs(diff) < 0.01) return '<span class="trend-arrow trend-flat">&#9654; flat</span>';
        if (diff > 0) return `<span class="trend-arrow trend-up">&#9650; +${this.formatValueShort(diff, 'count')}</span>`;
        return `<span class="trend-arrow trend-down">&#9660; ${this.formatValueShort(diff, 'count')}</span>`;
    },

    getPacingClass(status) {
        switch (status) {
            case 'exceeded':  return 'pacing-exceeded';
            case 'achieved':  return 'pacing-achieved';
            case 'on_track':  return 'pacing-on-track';
            case 'at_risk':   return 'pacing-at-risk';
            case 'behind':    return 'pacing-behind';
            default:          return '';
        }
    },

    destroyChart(canvasId) {
        if (this.charts[canvasId]) {
            this.charts[canvasId].destroy();
            delete this.charts[canvasId];
        }
    },
};
