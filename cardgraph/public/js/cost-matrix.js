/**
 * Card Graph - Cost Matrix (Admin Only)
 * Manages pricing rules and applies calculated costs to auctions.
 * Rendered as a sub-tab within the Maintenance tab.
 */
var CostMatrix = {
    initialized: false,
    livestreams: [],

    init: function() {
        var panel = document.getElementById('maint-panel-cost-matrix');

        if (!this.initialized) {
            var parts = [];
            parts.push('<div class="page-header"><h1>Cost Matrix</h1></div>');

            // Auction selector + scorecards at top
            parts.push('<div class="mb-4">');
            parts.push('<div class="cm-action-bar">');
            parts.push('<div class="filter-group">');
            parts.push('<label>Select Auction</label>');
            parts.push('<select id="cm-livestream-select" style="min-width:300px;"></select>');
            parts.push('</div>');
            parts.push('<div class="filter-group">');
            parts.push('<label>&nbsp;</label>');
            parts.push('<div class="cm-buttons">');
            parts.push('<button class="btn btn-primary" id="cm-preview-btn">Preview</button>');
            parts.push('<button class="btn btn-success" id="cm-apply-btn">Apply Cost Matrix</button>');
            parts.push('<button class="btn btn-danger" id="cm-clear-btn">Clear Costs</button>');
            parts.push('</div>');
            parts.push('</div>');
            parts.push('</div>');
            parts.push('<div id="cm-auction-scorecards"></div>');
            parts.push('<div id="cm-preview-result"></div>');
            parts.push('</div>');

            // Pricing Rules table
            parts.push('<div class="mb-4">');
            parts.push('<h3 class="section-title">Pricing Rules</h3>');
            parts.push('<div class="mb-2">');
            parts.push('<button class="btn btn-success" id="cm-add-rule-btn">Add Rule</button>');
            parts.push('</div>');
            parts.push('<div id="cm-rules-table"></div>');
            parts.push('</div>');

            panel.innerHTML = parts.join('\n');

            var self = this;
            document.getElementById('cm-add-rule-btn').addEventListener('click', function() {
                self.showRuleForm();
            });
            document.getElementById('cm-preview-btn').addEventListener('click', function() {
                self.previewCosts();
            });
            document.getElementById('cm-apply-btn').addEventListener('click', function() {
                self.applyCosts();
            });
            document.getElementById('cm-clear-btn').addEventListener('click', function() {
                self.clearCosts();
            });
            document.getElementById('cm-livestream-select').addEventListener('change', function() {
                self.loadAuctionSummary();
            });

            this.initialized = true;
        }

        this.loadRules();
        this.loadLivestreams();
    },

    loadRules: function() {
        API.get('/api/cost-matrix/rules').then(function(result) {
            var container = document.getElementById('cm-rules-table');
            var data = result.data || [];

            DataTable.render(container, {
                columns: [
                    { key: 'display_order', label: 'Order', sortable: false, align: 'center' },
                    {
                        key: 'min_price', label: 'Min Price', sortable: false, align: 'right',
                        format: function(v) { return App.formatCurrency(v); }
                    },
                    {
                        key: 'max_price', label: 'Max Price', sortable: false, align: 'right',
                        render: function(row) {
                            return row.max_price !== null ? App.formatCurrency(row.max_price) : 'No Limit';
                        }
                    },
                    {
                        key: 'pct_rate', label: 'Rate %', sortable: false, align: 'right',
                        format: function(v) { return parseFloat(v).toFixed(2) + '%'; }
                    },
                    {
                        key: 'fixed_cost', label: 'Fixed Cost', sortable: false, align: 'right',
                        format: function(v) { return App.formatCurrency(v); }
                    },
                    {
                        key: 'minimum_cost', label: 'Minimum', sortable: false, align: 'right',
                        format: function(v) { return App.formatCurrency(v); }
                    },
                    {
                        key: 'is_active', label: 'Active', sortable: false, align: 'center',
                        render: function(row) {
                            if (row.is_active == 1) {
                                return '<span class="status-badge status-completed">Active</span>';
                            }
                            return '<span class="status-badge status-cancelled">Inactive</span>';
                        }
                    },
                    {
                        key: 'actions', label: 'Actions', sortable: false,
                        render: function(row) {
                            var wrapper = document.createElement('span');

                            var editBtn = document.createElement('button');
                            editBtn.className = 'btn btn-secondary btn-sm';
                            editBtn.textContent = 'Edit';
                            editBtn.addEventListener('click', function(e) {
                                e.stopPropagation();
                                CostMatrix.showRuleForm(row);
                            });

                            var delBtn = document.createElement('button');
                            delBtn.className = 'btn btn-danger btn-sm';
                            delBtn.textContent = 'Del';
                            delBtn.style.marginLeft = '4px';
                            delBtn.addEventListener('click', function(e) {
                                e.stopPropagation();
                                CostMatrix.deleteRule(row.rule_id);
                            });

                            wrapper.appendChild(editBtn);
                            wrapper.appendChild(delBtn);
                            return wrapper;
                        }
                    }
                ],
                data: data,
                total: data.length,
                page: 1,
                perPage: 100
            });
        }).catch(function(err) {
            App.toast('Failed to load rules: ' + err.message, 'error');
        });
    },

    loadLivestreams: function() {
        var self = this;
        API.get('/api/cost-matrix/livestreams').then(function(result) {
            self.livestreams = result.data || [];
            var select = document.getElementById('cm-livestream-select');
            var parts = ['<option value="">-- Select an Auction --</option>'];
            for (var i = 0; i < self.livestreams.length; i++) {
                var ls = self.livestreams[i];
                var label = (ls.stream_date || '') + ' - ' + ls.livestream_title +
                    ' (' + ls.total_items + ' items, ' + App.formatCurrency(ls.total_revenue) + ')';
                parts.push('<option value="' + ls.livestream_id + '">' + label + '</option>');
            }
            select.innerHTML = parts.join('\n');
        }).catch(function(err) {
            App.toast('Failed to load auctions: ' + err.message, 'error');
        });
    },

    loadAuctionSummary: function() {
        var container = document.getElementById('cm-auction-scorecards');
        var livestreamId = this.getSelectedLivestream();

        if (!livestreamId) {
            container.innerHTML = '';
            document.getElementById('cm-preview-result').innerHTML = '';
            return;
        }

        API.get('/api/cost-matrix/auction-summary', { livestream_id: livestreamId }).then(function(result) {
            var profitClass = result.profit_loss >= 0 ? 'positive' : 'negative';
            var parts = [];
            parts.push('<div class="cards-grid cards-compact" style="margin-top:12px;">');

            parts.push('<div class="card">');
            parts.push('<div class="card-label">Items</div>');
            parts.push('<div class="card-value">' + result.total_items + '</div>');
            parts.push('</div>');

            parts.push('<div class="card">');
            parts.push('<div class="card-label">Buyers</div>');
            parts.push('<div class="card-value">' + result.unique_buyers + '</div>');
            parts.push('</div>');

            parts.push('<div class="card">');
            parts.push('<div class="card-label">Giveaways</div>');
            parts.push('<div class="card-value">' + result.giveaway_count + '</div>');
            parts.push('<div class="card-sub">' + App.formatCurrency(result.giveaway_net) + ' net</div>');
            parts.push('</div>');

            parts.push('<div class="card">');
            parts.push('<div class="card-label">Revenue</div>');
            parts.push('<div class="card-value">' + App.formatCurrency(result.total_revenue) + '</div>');
            parts.push('</div>');

            parts.push('<div class="card">');
            parts.push('<div class="card-label">Earnings</div>');
            parts.push('<div class="card-value">' + App.formatCurrency(result.total_earnings) + '</div>');
            parts.push('</div>');

            parts.push('<div class="card">');
            parts.push('<div class="card-label">Fees</div>');
            parts.push('<div class="card-value">' + App.formatCurrency(result.total_fees) + '</div>');
            parts.push('</div>');

            parts.push('<div class="card">');
            parts.push('<div class="card-label">Costs</div>');
            parts.push('<div class="card-value">' + App.formatCurrency(result.total_costs) + '</div>');
            parts.push('</div>');

            parts.push('<div class="card">');
            parts.push('<div class="card-label">Profit / Loss</div>');
            parts.push('<div class="card-value ' + profitClass + '">' + App.formatCurrency(result.profit_loss) + '</div>');
            parts.push('</div>');

            parts.push('<div class="card">');
            parts.push('<div class="card-label">Avg Price</div>');
            parts.push('<div class="card-value">' + App.formatCurrency(result.avg_item_price) + '</div>');
            parts.push('</div>');

            parts.push('</div>');
            container.innerHTML = parts.join('\n');
        }).catch(function(err) {
            container.innerHTML = '<p class="text-danger">Failed to load summary: ' + err.message + '</p>';
        });
    },

    showRuleForm: function(existing) {
        var isEdit = !!existing;
        var title = isEdit ? 'Edit Rule' : 'Add Rule';

        var parts = [];
        parts.push('<div class="modal-header">');
        parts.push('<h2>' + title + '</h2>');
        parts.push('<button class="modal-close" onclick="App.closeModal()">&times;</button>');
        parts.push('</div>');
        parts.push('<div class="modal-body">');
        parts.push('<div class="form-row">');
        parts.push('<div class="form-group">');
        parts.push('<label>Min Price ($)</label>');
        parts.push('<input type="number" id="rule-min-price" step="0.01" min="0" value="' + (existing ? existing.min_price : '0.00') + '">');
        parts.push('</div>');
        parts.push('<div class="form-group">');
        parts.push('<label>Max Price ($ - blank for no limit)</label>');
        parts.push('<input type="number" id="rule-max-price" step="0.01" min="0" value="' + (existing && existing.max_price !== null ? existing.max_price : '') + '">');
        parts.push('</div>');
        parts.push('</div>');
        parts.push('<div class="form-row">');
        parts.push('<div class="form-group">');
        parts.push('<label>Percentage Rate (%)</label>');
        parts.push('<input type="number" id="rule-pct-rate" step="0.01" min="0" max="100" value="' + (existing ? existing.pct_rate : '0.00') + '">');
        parts.push('</div>');
        parts.push('<div class="form-group">');
        parts.push('<label>Fixed Cost ($)</label>');
        parts.push('<input type="number" id="rule-fixed-cost" step="0.01" min="0" value="' + (existing ? existing.fixed_cost : '0.00') + '">');
        parts.push('</div>');
        parts.push('</div>');
        parts.push('<div class="form-row">');
        parts.push('<div class="form-group">');
        parts.push('<label>Minimum Cost ($)</label>');
        parts.push('<input type="number" id="rule-minimum-cost" step="0.01" min="0" value="' + (existing ? existing.minimum_cost : '0.00') + '">');
        parts.push('</div>');
        parts.push('<div class="form-group">');
        parts.push('<label>Display Order</label>');
        parts.push('<input type="number" id="rule-display-order" step="1" min="0" value="' + (existing ? existing.display_order : '0') + '">');
        parts.push('</div>');
        parts.push('</div>');
        if (isEdit) {
            parts.push('<div class="form-group">');
            parts.push('<label>Active</label>');
            parts.push('<select id="rule-is-active">');
            parts.push('<option value="1"' + (existing.is_active == 1 ? ' selected' : '') + '>Active</option>');
            parts.push('<option value="0"' + (existing.is_active == 0 ? ' selected' : '') + '>Inactive</option>');
            parts.push('</select>');
            parts.push('</div>');
        }
        parts.push('</div>');
        parts.push('<div class="modal-footer">');
        parts.push('<button class="btn btn-secondary" onclick="App.closeModal()">Cancel</button>');
        parts.push('<button class="btn btn-primary" id="rule-save-btn">' + (isEdit ? 'Save Changes' : 'Create Rule') + '</button>');
        parts.push('</div>');

        App.openModal(parts.join('\n'));

        document.getElementById('rule-save-btn').addEventListener('click', function() {
            CostMatrix.saveRule(existing ? existing.rule_id : null);
        });
    },

    saveRule: function(ruleId) {
        var maxPriceVal = document.getElementById('rule-max-price').value;
        var data = {
            min_price: parseFloat(document.getElementById('rule-min-price').value) || 0,
            max_price: maxPriceVal !== '' ? parseFloat(maxPriceVal) : null,
            pct_rate: parseFloat(document.getElementById('rule-pct-rate').value) || 0,
            fixed_cost: parseFloat(document.getElementById('rule-fixed-cost').value) || 0,
            minimum_cost: parseFloat(document.getElementById('rule-minimum-cost').value) || 0,
            display_order: parseInt(document.getElementById('rule-display-order').value) || 0
        };

        var activeEl = document.getElementById('rule-is-active');
        if (activeEl) {
            data.is_active = parseInt(activeEl.value);
        }

        var promise;
        if (ruleId) {
            promise = API.put('/api/cost-matrix/rules/' + ruleId, data);
        } else {
            promise = API.post('/api/cost-matrix/rules', data);
        }

        promise.then(function() {
            App.toast(ruleId ? 'Rule updated' : 'Rule created', 'success');
            App.closeModal();
            CostMatrix.loadRules();
        }).catch(function(err) {
            App.toast(err.message, 'error');
        });
    },

    deleteRule: function(ruleId) {
        if (!confirm('Delete this pricing rule?')) return;

        API.del('/api/cost-matrix/rules/' + ruleId).then(function() {
            App.toast('Rule deleted', 'success');
            CostMatrix.loadRules();
        }).catch(function(err) {
            App.toast(err.message, 'error');
        });
    },

    getSelectedLivestream: function() {
        var select = document.getElementById('cm-livestream-select');
        return select ? select.value : '';
    },

    previewCosts: function() {
        var livestreamId = this.getSelectedLivestream();
        if (!livestreamId) {
            App.toast('Please select an auction first', 'error');
            return;
        }

        var container = document.getElementById('cm-preview-result');
        container.innerHTML = '<p class="text-muted">Calculating preview...</p>';

        API.post('/api/cost-matrix/preview', { livestream_id: livestreamId }).then(function(result) {
            if (result.error) {
                container.innerHTML = '<p class="text-danger">' + result.error + '</p>';
                return;
            }

            var parts = [];

            // Tier breakdown
            var tiers = result.tiers || [];
            if (tiers.length > 0) {
                parts.push('<div class="table-container" style="margin-top:12px;">');
                parts.push('<table class="data-table">');
                parts.push('<thead><tr>');
                parts.push('<th>Price Range</th>');
                parts.push('<th style="text-align:right;">Rate</th>');
                parts.push('<th style="text-align:right;">Fixed</th>');
                parts.push('<th style="text-align:right;">Items</th>');
                parts.push('<th style="text-align:right;">Tier Total</th>');
                parts.push('</tr></thead><tbody>');
                for (var i = 0; i < tiers.length; i++) {
                    var t = tiers[i];
                    var range = App.formatCurrency(t.min_price) + ' - ' +
                        (t.max_price !== null ? App.formatCurrency(t.max_price) : 'No Limit');
                    parts.push('<tr>');
                    parts.push('<td>' + range + '</td>');
                    parts.push('<td style="text-align:right;">' + parseFloat(t.pct_rate).toFixed(2) + '%</td>');
                    parts.push('<td style="text-align:right;">' + App.formatCurrency(t.fixed_cost) + '</td>');
                    parts.push('<td style="text-align:right;">' + t.count + '</td>');
                    parts.push('<td style="text-align:right;">' + App.formatCurrency(t.total) + '</td>');
                    parts.push('</tr>');
                }
                parts.push('</tbody></table></div>');
            }

            container.innerHTML = parts.join('\n');
        }).catch(function(err) {
            container.innerHTML = '<p class="text-danger">Preview failed: ' + err.message + '</p>';
        });
    },

    applyCosts: function() {
        var livestreamId = this.getSelectedLivestream();
        if (!livestreamId) {
            App.toast('Please select an auction first', 'error');
            return;
        }

        if (!confirm('Apply cost matrix to this auction? This will replace any existing matrix costs.')) {
            return;
        }

        var applyBtn = document.getElementById('cm-apply-btn');
        applyBtn.disabled = true;
        applyBtn.textContent = 'Applying...';

        API.post('/api/cost-matrix/apply', { livestream_id: livestreamId }).then(function(result) {
            applyBtn.disabled = false;
            applyBtn.textContent = 'Apply Cost Matrix';
            App.toast(result.message, 'success');
            CostMatrix.previewCosts();
            CostMatrix.loadAuctionSummary();
        }).catch(function(err) {
            applyBtn.disabled = false;
            applyBtn.textContent = 'Apply Cost Matrix';
            App.toast('Apply failed: ' + err.message, 'error');
        });
    },

    clearCosts: function() {
        var livestreamId = this.getSelectedLivestream();
        if (!livestreamId) {
            App.toast('Please select an auction first', 'error');
            return;
        }

        if (!confirm('Clear all matrix-applied costs for this auction? Manual costs will not be affected.')) {
            return;
        }

        var clearBtn = document.getElementById('cm-clear-btn');
        clearBtn.disabled = true;
        clearBtn.textContent = 'Clearing...';

        API.post('/api/cost-matrix/clear', { livestream_id: livestreamId }).then(function(result) {
            clearBtn.disabled = false;
            clearBtn.textContent = 'Clear Costs';
            App.toast(result.message, 'success');
            document.getElementById('cm-preview-result').innerHTML = '';
            CostMatrix.loadAuctionSummary();
        }).catch(function(err) {
            clearBtn.disabled = false;
            clearBtn.textContent = 'Clear Costs';
            App.toast('Clear failed: ' + err.message, 'error');
        });
    }
};
