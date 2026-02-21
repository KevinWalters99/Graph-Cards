/**
 * Card Graph — Alerts & Notifications
 *
 * Two modules:
 *   Alerts      — App-wide display (alert bar + scroll ticker)
 *   AlertsAdmin — CRUD management (Maintenance sub-tab, admin only)
 */

// ─── App-wide Display ────────────────────────────────────────
var Alerts = {
    refreshTimer: null,

    /**
     * Load active alerts and scroll ticker data.
     * Called from App.init() and on a 5-minute interval.
     */
    loadActive: function() {
        Alerts.loadAlertBar();
        Alerts.loadTicker();
    },

    loadAlertBar: function() {
        API.get('/api/alerts/active').then(function(result) {
            var bar = document.getElementById('alert-bar');
            if (!bar) return;

            var data = result.data || [];
            if (data.length === 0) {
                bar.innerHTML = '';
                return;
            }

            var html = [];
            for (var i = 0; i < data.length; i++) {
                var a = data[i];
                var isAlert = a.alert_type === 'alert';
                var icon = isAlert
                    ? '<svg class="alert-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>'
                    : '<svg class="alert-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>';

                var dismiss = '';
                if (!isAlert || !a.action_check) {
                    dismiss = '<button class="alert-dismiss" data-alert-id="' + a.alert_id + '" title="Dismiss">&times;</button>';
                }

                html.push(
                    '<div class="alert-item alert-type-' + a.alert_type + '">' +
                    icon +
                    '<span class="alert-title">' + Alerts.escHtml(a.title) + '</span>' +
                    dismiss +
                    '<div class="alert-tooltip">' + Alerts.escHtml(a.description) + '</div>' +
                    '</div>'
                );
            }

            bar.innerHTML = html.join('');

            // Attach dismiss handlers
            var btns = bar.querySelectorAll('.alert-dismiss');
            for (var j = 0; j < btns.length; j++) {
                (function(btn) {
                    btn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        var id = btn.getAttribute('data-alert-id');
                        Alerts.dismiss(id);
                    });
                })(btns[j]);
            }
        }).catch(function() { /* silent */ });
    },

    dismiss: function(alertId) {
        API.post('/api/alerts/' + alertId + '/dismiss').then(function() {
            Alerts.loadAlertBar();
        }).catch(function(err) {
            App.toast(err.message, 'error');
        });
    },

    loadTicker: function() {
        API.get('/api/alerts/scroll/data').then(function(result) {
            var ticker = document.getElementById('scroll-ticker');
            if (!ticker) return;

            if (!result.enabled || !result.items || result.items.length === 0) {
                ticker.innerHTML = '';
                ticker.style.display = 'none';
                return;
            }

            // Set speed
            var speeds = { slow: '45s', medium: '30s', fast: '18s' };
            var duration = speeds[result.speed] || '30s';

            // Build ticker items — duplicate for seamless loop
            var itemsHtml = '';
            for (var i = 0; i < result.items.length; i++) {
                var item = result.items[i];
                itemsHtml += '<span class="ticker-item">' +
                    '<span class="ticker-label">' + Alerts.escHtml(item.label) + ':</span>' +
                    '<span class="ticker-value">' + Alerts.escHtml(item.value) + '</span>' +
                    '</span>';
                if (i < result.items.length - 1) {
                    itemsHtml += '<span class="ticker-sep">|</span>';
                }
            }

            // Duplicate for infinite scroll effect
            ticker.innerHTML =
                '<div class="ticker-track" style="--ticker-duration:' + duration + '">' +
                itemsHtml + '<span class="ticker-sep">|</span>' + itemsHtml +
                '</div>';
            ticker.style.display = '';
        }).catch(function() {
            var ticker = document.getElementById('scroll-ticker');
            if (ticker) {
                ticker.innerHTML = '';
                ticker.style.display = 'none';
            }
        });
    },

    escHtml: function(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
};

// ─── Admin CRUD (Maintenance sub-tab) ────────────────────────
var AlertsAdmin = {
    initialized: false,

    init: function() {
        if (!this.initialized) {
            this.initialized = true;
        }
        this.loadAlerts();
        this.loadScrollSettings();
    },

    loadAlerts: function() {
        var self = this;
        API.get('/api/alerts').then(function(result) {
            var container = document.getElementById('maint-panel-alerts');
            if (!container) return;

            var data = result.data || [];

            var html = [];
            html.push('<div class="mb-2">');
            html.push('<button class="btn btn-success" id="btn-add-alert">Add Alert</button>');
            html.push('</div>');
            html.push('<div id="alerts-table-container"></div>');
            html.push('<div id="scroll-settings-container"></div>');
            container.innerHTML = html.join('\n');

            document.getElementById('btn-add-alert').addEventListener('click', function() {
                self.showAlertForm();
            });

            self.renderTable(data);
        }).catch(function(err) {
            var container = document.getElementById('maint-panel-alerts');
            if (container) {
                container.innerHTML = '<p class="text-muted">Unable to load alerts.</p>';
            }
        });
    },

    renderTable: function(data) {
        var self = this;
        var days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

        DataTable.render(document.getElementById('alerts-table-container'), {
            columns: [
                { key: 'title', label: 'Title', sortable: false },
                {
                    key: 'alert_type', label: 'Type', sortable: false,
                    render: function(row) {
                        var cls = row.alert_type === 'alert' ? 'badge-alert' : 'badge-notification';
                        return '<span class="status-badge ' + cls + '">' +
                            row.alert_type.charAt(0).toUpperCase() + row.alert_type.slice(1) + '</span>';
                    }
                },
                {
                    key: 'frequency', label: 'Frequency', sortable: false,
                    render: function(row) {
                        var f = row.frequency;
                        return f.charAt(0).toUpperCase() + f.slice(1);
                    }
                },
                {
                    key: 'schedule', label: 'Schedule', sortable: false,
                    render: function(row) {
                        var parts = [];
                        if (row.frequency !== 'monthly' && row.day_of_week !== null) {
                            parts.push(days[row.day_of_week] || '?');
                        }
                        if (row.frequency === 'monthly') {
                            parts.push('1st');
                        }
                        if (row.time_of_day) {
                            // Format time_of_day HH:MM:SS to 12h
                            var timeParts = row.time_of_day.split(':');
                            var h = parseInt(timeParts[0]);
                            var m = timeParts[1];
                            var ampm = h >= 12 ? 'PM' : 'AM';
                            h = h % 12 || 12;
                            parts.push(h + ':' + m + ' ' + ampm);
                        }
                        return parts.join(' @ ') || '-';
                    }
                },
                {
                    key: 'action_check', label: 'Action Check', sortable: false,
                    render: function(row) {
                        if (!row.action_check) return '<span class="text-muted">None</span>';
                        var labels = {
                            'upload_earnings': 'Upload Earnings',
                            'upload_payouts': 'Upload Payouts',
                            'upload_paypal': 'Upload PayPal'
                        };
                        return labels[row.action_check] || row.action_check;
                    }
                },
                {
                    key: 'is_active', label: 'Active', sortable: false,
                    render: function(row) {
                        var el = document.createElement('label');
                        el.className = 'toggle-switch';
                        var checked = row.is_active == 1 ? ' checked' : '';
                        el.innerHTML = '<input type="checkbox"' + checked + '><span class="toggle-slider"></span>';
                        el.querySelector('input').addEventListener('change', function(e) {
                            e.stopPropagation();
                            self.toggleActive(row.alert_id);
                        });
                        return el;
                    }
                },
                {
                    key: 'actions', label: 'Actions', sortable: false,
                    render: function(row) {
                        var wrap = document.createElement('span');
                        wrap.style.display = 'flex';
                        wrap.style.gap = '4px';

                        var editBtn = document.createElement('button');
                        editBtn.className = 'btn btn-secondary btn-sm';
                        editBtn.textContent = 'Edit';
                        editBtn.addEventListener('click', function(e) {
                            e.stopPropagation();
                            self.showAlertForm(row);
                        });

                        var delBtn = document.createElement('button');
                        delBtn.className = 'btn btn-danger btn-sm';
                        delBtn.textContent = 'Delete';
                        delBtn.addEventListener('click', function(e) {
                            e.stopPropagation();
                            self.deleteAlert(row.alert_id);
                        });

                        wrap.appendChild(editBtn);
                        wrap.appendChild(delBtn);
                        return wrap;
                    }
                }
            ],
            data: data,
            total: data.length,
            page: 1,
            perPage: 100
        });
    },

    toggleActive: function(alertId) {
        API.put('/api/alerts/' + alertId + '/toggle').then(function(result) {
            App.toast('Alert ' + (result.is_active ? 'activated' : 'deactivated'), 'success');
            Alerts.loadAlertBar();
        }).catch(function(err) {
            App.toast(err.message, 'error');
        });
    },

    deleteAlert: function(alertId) {
        if (!confirm('Delete this alert definition? This cannot be undone.')) return;
        var self = this;
        API.del('/api/alerts/' + alertId).then(function() {
            App.toast('Alert deleted', 'success');
            self.loadAlerts();
            Alerts.loadAlertBar();
        }).catch(function(err) {
            App.toast(err.message, 'error');
        });
    },

    showAlertForm: function(existing) {
        var self = this;
        var isEdit = !!existing;
        var title = isEdit ? 'Edit Alert' : 'Add Alert';

        var days = [
            { val: '0', label: 'Sunday' },
            { val: '1', label: 'Monday' },
            { val: '2', label: 'Tuesday' },
            { val: '3', label: 'Wednesday' },
            { val: '4', label: 'Thursday' },
            { val: '5', label: 'Friday' },
            { val: '6', label: 'Saturday' }
        ];

        var p = [];
        p.push('<div class="modal-header">');
        p.push('<h2>' + title + '</h2>');
        p.push('<button class="modal-close" onclick="App.closeModal()">&times;</button>');
        p.push('</div>');
        p.push('<div class="modal-body">');

        // Title
        p.push('<div class="form-group">');
        p.push('<label>Title *</label>');
        p.push('<input type="text" id="alert-title" value="' + (existing ? Alerts.escHtml(existing.title) : '') + '" placeholder="e.g., Perform Auction Uploads">');
        p.push('</div>');

        // Description
        p.push('<div class="form-group">');
        p.push('<label>Description *</label>');
        p.push('<textarea id="alert-description" rows="3" placeholder="Guidance shown in tooltip...">' + (existing ? Alerts.escHtml(existing.description) : '') + '</textarea>');
        p.push('</div>');

        // Type + Frequency row
        p.push('<div class="form-row">');
        p.push('<div class="form-group">');
        p.push('<label>Type *</label>');
        p.push('<select id="alert-type">');
        p.push('<option value="alert"' + (existing && existing.alert_type === 'alert' ? ' selected' : '') + '>Alert (persistent)</option>');
        p.push('<option value="notification"' + (existing && existing.alert_type === 'notification' ? ' selected' : '') + '>Notification (dismissible)</option>');
        p.push('</select>');
        p.push('</div>');
        p.push('<div class="form-group">');
        p.push('<label>Frequency *</label>');
        p.push('<select id="alert-frequency">');
        p.push('<option value="weekly"' + (existing && existing.frequency === 'weekly' ? ' selected' : '') + '>Weekly</option>');
        p.push('<option value="biweekly"' + (existing && existing.frequency === 'biweekly' ? ' selected' : '') + '>Bi-Weekly</option>');
        p.push('<option value="monthly"' + (existing && existing.frequency === 'monthly' ? ' selected' : '') + '>Monthly</option>');
        p.push('</select>');
        p.push('</div>');
        p.push('</div>');

        // Day of Week + Time row
        p.push('<div class="form-row">');
        p.push('<div class="form-group" id="alert-dow-group">');
        p.push('<label>Day of Week</label>');
        p.push('<select id="alert-dow">');
        for (var i = 0; i < days.length; i++) {
            var sel = existing && String(existing.day_of_week) === days[i].val ? ' selected' : '';
            if (!existing && days[i].val === '1') sel = ' selected'; // default Monday
            p.push('<option value="' + days[i].val + '"' + sel + '>' + days[i].label + '</option>');
        }
        p.push('</select>');
        p.push('</div>');
        p.push('<div class="form-group">');
        p.push('<label>Time *</label>');
        var timeVal = existing ? existing.time_of_day.substring(0, 5) : '14:00';
        p.push('<input type="time" id="alert-time" value="' + timeVal + '">');
        p.push('</div>');
        p.push('</div>');

        // Anchor Date (biweekly only)
        p.push('<div class="form-group" id="alert-anchor-group" style="display:none;">');
        p.push('<label>Anchor Date (bi-weekly reference start)</label>');
        p.push('<input type="date" id="alert-anchor" value="' + (existing && existing.anchor_date ? existing.anchor_date : '') + '">');
        p.push('</div>');

        // Action Check
        p.push('<div class="form-group">');
        p.push('<label>Action Check</label>');
        p.push('<select id="alert-action-check">');
        p.push('<option value="">None (manual dismiss)</option>');
        var checks = [
            { val: 'upload_earnings', label: 'Upload Earnings' },
            { val: 'upload_payouts', label: 'Upload Payouts' },
            { val: 'upload_paypal', label: 'Upload PayPal' }
        ];
        for (var j = 0; j < checks.length; j++) {
            var ck = existing && existing.action_check === checks[j].val ? ' selected' : '';
            p.push('<option value="' + checks[j].val + '"' + ck + '>' + checks[j].label + '</option>');
        }
        p.push('</select>');
        p.push('</div>');

        p.push('</div>'); // modal-body

        p.push('<div class="modal-footer">');
        p.push('<button class="btn btn-secondary" onclick="App.closeModal()">Cancel</button>');
        p.push('<button class="btn btn-primary" id="alert-save-btn">' + (isEdit ? 'Save Changes' : 'Create Alert') + '</button>');
        p.push('</div>');

        App.openModal(p.join('\n'));

        // Toggle visibility of day-of-week and anchor based on frequency
        var freqEl = document.getElementById('alert-frequency');
        var updateVisibility = function() {
            var freq = freqEl.value;
            document.getElementById('alert-dow-group').style.display = freq === 'monthly' ? 'none' : '';
            document.getElementById('alert-anchor-group').style.display = freq === 'biweekly' ? '' : 'none';
        };
        freqEl.addEventListener('change', updateVisibility);
        updateVisibility();

        // Save handler
        document.getElementById('alert-save-btn').addEventListener('click', function() {
            self.saveAlert(existing ? existing.alert_id : null);
        });
    },

    saveAlert: function(alertId) {
        var self = this;
        var freq = document.getElementById('alert-frequency').value;

        var data = {
            title: document.getElementById('alert-title').value.trim(),
            description: document.getElementById('alert-description').value.trim(),
            alert_type: document.getElementById('alert-type').value,
            frequency: freq,
            day_of_week: freq !== 'monthly' ? parseInt(document.getElementById('alert-dow').value) : null,
            time_of_day: document.getElementById('alert-time').value + ':00',
            anchor_date: freq === 'biweekly' ? document.getElementById('alert-anchor').value || null : null,
            action_check: document.getElementById('alert-action-check').value || null,
            is_active: 1
        };

        if (!data.title || !data.description) {
            App.toast('Title and Description are required', 'error');
            return;
        }

        var promise;
        if (alertId) {
            promise = API.put('/api/alerts/' + alertId, data);
        } else {
            promise = API.post('/api/alerts', data);
        }

        promise.then(function() {
            App.toast(alertId ? 'Alert updated' : 'Alert created', 'success');
            App.closeModal();
            self.loadAlerts();
            Alerts.loadAlertBar();
        }).catch(function(err) {
            App.toast(err.message, 'error');
        });
    },

    // ─── Scroll Ticker Settings ──────────────────────────────

    loadScrollSettings: function() {
        var self = this;
        API.get('/api/alerts/scroll').then(function(settings) {
            self.renderScrollSettings(settings);
        }).catch(function() { /* silent */ });
    },

    renderScrollSettings: function(settings) {
        var container = document.getElementById('scroll-settings-container');
        if (!container) return;

        var p = [];
        p.push('<div class="scroll-settings">');
        p.push('<h3>Scroll Ticker Settings</h3>');

        // Enable toggle
        p.push('<div class="setting-row">');
        p.push('<span class="setting-label">Enable Ticker</span>');
        p.push('<label class="toggle-switch">');
        p.push('<input type="checkbox" id="scroll-enabled"' + (settings.is_enabled == 1 ? ' checked' : '') + '>');
        p.push('<span class="toggle-slider"></span>');
        p.push('</label>');
        p.push('</div>');

        // Content types
        p.push('<div class="setting-row">');
        p.push('<span class="setting-label">Scorecard Info</span>');
        p.push('<label class="toggle-switch">');
        p.push('<input type="checkbox" id="scroll-scorecard"' + (settings.show_scorecard == 1 ? ' checked' : '') + '>');
        p.push('<span class="toggle-slider"></span>');
        p.push('</label>');
        p.push('</div>');

        p.push('<div class="setting-row">');
        p.push('<span class="setting-label">Analytics Info</span>');
        p.push('<label class="toggle-switch">');
        p.push('<input type="checkbox" id="scroll-analytics"' + (settings.show_analytics == 1 ? ' checked' : '') + '>');
        p.push('<span class="toggle-slider"></span>');
        p.push('</label>');
        p.push('</div>');

        p.push('<div class="setting-row">');
        p.push('<span class="setting-label">Player Stats</span>');
        p.push('<label class="toggle-switch">');
        p.push('<input type="checkbox" id="scroll-players" disabled>');
        p.push('<span class="toggle-slider"></span>');
        p.push('</label>');
        p.push('<span class="setting-hint">Available with Parser project</span>');
        p.push('</div>');

        p.push('<div class="setting-row">');
        p.push('<span class="setting-label">Teams Status</span>');
        p.push('<label class="toggle-switch">');
        p.push('<input type="checkbox" id="scroll-teams" disabled>');
        p.push('<span class="toggle-slider"></span>');
        p.push('</label>');
        p.push('<span class="setting-hint">Available with Parser project</span>');
        p.push('</div>');

        // Speed
        p.push('<div class="setting-row">');
        p.push('<span class="setting-label">Scroll Speed</span>');
        p.push('<select id="scroll-speed" style="padding:4px 8px;border:1px solid #ddd;border-radius:6px;font-size:13px;">');
        p.push('<option value="slow"' + (settings.scroll_speed === 'slow' ? ' selected' : '') + '>Slow</option>');
        p.push('<option value="medium"' + (settings.scroll_speed === 'medium' ? ' selected' : '') + '>Medium</option>');
        p.push('<option value="fast"' + (settings.scroll_speed === 'fast' ? ' selected' : '') + '>Fast</option>');
        p.push('</select>');
        p.push('</div>');

        // Save button
        p.push('<div style="margin-top:16px;">');
        p.push('<button class="btn btn-primary" id="scroll-save-btn">Save Ticker Settings</button>');
        p.push('</div>');

        p.push('</div>');
        container.innerHTML = p.join('\n');

        document.getElementById('scroll-save-btn').addEventListener('click', function() {
            var data = {
                is_enabled: document.getElementById('scroll-enabled').checked ? 1 : 0,
                show_scorecard: document.getElementById('scroll-scorecard').checked ? 1 : 0,
                show_analytics: document.getElementById('scroll-analytics').checked ? 1 : 0,
                show_players: 0,
                show_teams: 0,
                scroll_speed: document.getElementById('scroll-speed').value
            };
            API.put('/api/alerts/scroll', data).then(function() {
                App.toast('Ticker settings saved', 'success');
                Alerts.loadTicker();
            }).catch(function(err) {
                App.toast(err.message, 'error');
            });
        });
    }
};
