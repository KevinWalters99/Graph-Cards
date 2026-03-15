/**
 * Card Graph - Maintenance Tab
 * Sub-tabs: Upload History, Status Types, Cost Matrix (admin), User Management (admin)
 */
var Maintenance = {
    initialized: false,
    currentSubTab: 'uploads',

    init: function() {
        var panel = document.getElementById('tab-maintenance');
        var isAdmin = App.user && App.user.role === 'admin';

        if (!this.initialized) {
            var parts = [];
            parts.push('<div class="page-header"><h1>Maintenance</h1></div>');
            parts.push('<div class="sub-tabs" id="maint-sub-tabs">');
            parts.push('<button class="sub-tab active" data-subtab="uploads">Upload History</button>');
            parts.push('<button class="sub-tab" data-subtab="statuses">Status Types</button>');
            if (isAdmin) {
                parts.push('<button class="sub-tab" data-subtab="cost-matrix">Cost Matrix</button>');
                parts.push('<button class="sub-tab" data-subtab="analytics-standards">Analytics Standards</button>');
                parts.push('<button class="sub-tab" data-subtab="alerts">Alerts</button>');
                parts.push('<button class="sub-tab" data-subtab="transcription">Transcription</button>');
                parts.push('<button class="sub-tab" data-subtab="users">User Management</button>');
                parts.push('<button class="sub-tab" data-subtab="sql-tables">SQL Tables</button>');
            }
            parts.push('</div>');
            parts.push('<div id="maint-panel-uploads" class="sub-panel"></div>');
            parts.push('<div id="maint-panel-statuses" class="sub-panel" style="display:none;"></div>');
            if (isAdmin) {
                parts.push('<div id="maint-panel-cost-matrix" class="sub-panel" style="display:none;"></div>');
                parts.push('<div id="maint-panel-analytics-standards" class="sub-panel" style="display:none;"></div>');
                parts.push('<div id="maint-panel-alerts" class="sub-panel" style="display:none;"></div>');
                parts.push('<div id="maint-panel-transcription" class="sub-panel" style="display:none;"></div>');
                parts.push('<div id="maint-panel-users" class="sub-panel" style="display:none;"></div>');
                parts.push('<div id="maint-panel-sql-tables" class="sub-panel" style="display:none;"></div>');
            }
            panel.innerHTML = parts.join('\n');

            var self = this;
            var tabs = document.querySelectorAll('#maint-sub-tabs .sub-tab');
            for (var i = 0; i < tabs.length; i++) {
                (function(btn) {
                    btn.addEventListener('click', function() {
                        self.switchSubTab(btn.getAttribute('data-subtab'));
                    });
                })(tabs[i]);
            }

            this.initialized = true;
        }

        this.switchSubTab(this.currentSubTab);
    },

    switchSubTab: function(name) {
        var isAdmin = App.user && App.user.role === 'admin';
        this.currentSubTab = name;

        var tabs = document.querySelectorAll('#maint-sub-tabs .sub-tab');
        for (var i = 0; i < tabs.length; i++) {
            var active = tabs[i].getAttribute('data-subtab') === name;
            if (active) {
                tabs[i].classList.add('active');
            } else {
                tabs[i].classList.remove('active');
            }
        }

        var panels = ['uploads', 'statuses'];
        if (isAdmin) {
            panels.push('cost-matrix', 'analytics-standards', 'alerts', 'transcription', 'users', 'sql-tables');
        }
        for (var j = 0; j < panels.length; j++) {
            var el = document.getElementById('maint-panel-' + panels[j]);
            if (el) {
                el.style.display = panels[j] === name ? '' : 'none';
            }
        }

        if (name === 'uploads') this.loadUploadLog();
        if (name === 'statuses') this.loadStatuses();
        if (name === 'cost-matrix' && isAdmin) CostMatrix.init();
        if (name === 'analytics-standards' && isAdmin) AnalyticsAdmin.init();
        if (name === 'alerts' && isAdmin) AlertsAdmin.init();
        if (name === 'transcription' && isAdmin) TranscriptionAdmin.init();
        if (name === 'users' && isAdmin) this.loadUsers();
        if (name === 'sql-tables' && isAdmin) this.loadSqlTables();
    },

    loadUploadLog: function() {
        API.get('/api/maintenance/upload-log').then(function(result) {
            var container = document.getElementById('maint-panel-uploads');
            DataTable.render(container, {
                columns: [
                    { key: 'original_filename', label: 'Filename', sortable: false },
                    { key: 'upload_type', label: 'Type', sortable: false },
                    {
                        key: 'uploaded_at', label: 'Uploaded', sortable: false,
                        format: function(v) { return App.formatDatetime(v); }
                    },
                    { key: 'uploaded_by_name', label: 'By', sortable: false },
                    { key: 'rows_inserted', label: 'Inserted', align: 'right', sortable: false },
                    { key: 'rows_skipped', label: 'Skipped', align: 'right', sortable: false },
                    {
                        key: 'status', label: 'Status', sortable: false,
                        render: function(row) {
                            var cls = App.statusClass(row.status);
                            return '<span class="status-badge ' + cls + '">' + row.status + '</span>';
                        }
                    },
                    {
                        key: 'parsed_start_date', label: 'Date Range', sortable: false,
                        render: function(row) {
                            if (row.parsed_start_date && row.parsed_end_date) {
                                return App.formatDate(row.parsed_start_date) + ' - ' + App.formatDate(row.parsed_end_date);
                            }
                            return '-';
                        }
                    }
                ],
                data: result.data || [],
                total: result.total || 0,
                page: result.page || 1,
                perPage: result.per_page || 50
            });
        }).catch(function() {
            document.getElementById('maint-panel-uploads').innerHTML =
                '<p class="text-muted">Unable to load upload log. Admin access may be required.</p>';
        });
    },

    loadUsers: function() {
        var self = this;
        API.get('/api/users').then(function(result) {
            var container = document.getElementById('maint-panel-users');
            var html = [];
            html.push('<div class="mb-2">');
            html.push('<button class="btn btn-success" id="btn-add-user">Add User</button>');
            html.push('</div>');
            html.push('<div id="maint-users-table"></div>');
            container.innerHTML = html.join('\n');

            document.getElementById('btn-add-user').addEventListener('click', function() {
                self.showUserForm();
            });

            DataTable.render(document.getElementById('maint-users-table'), {
                columns: [
                    { key: 'username', label: 'Username', sortable: false },
                    { key: 'display_name', label: 'Display Name', sortable: false },
                    { key: 'role', label: 'Role', sortable: false },
                    {
                        key: 'is_active', label: 'Active', sortable: false,
                        render: function(row) {
                            if (row.is_active == 1) {
                                return '<span class="status-badge status-completed">Active</span>';
                            }
                            return '<span class="status-badge status-cancelled">Inactive</span>';
                        }
                    },
                    {
                        key: 'created_at', label: 'Created', sortable: false,
                        format: function(v) { return App.formatDatetime(v); }
                    },
                    {
                        key: 'actions', label: 'Actions', sortable: false,
                        render: function(row) {
                            var btn = document.createElement('button');
                            btn.className = 'btn btn-secondary btn-sm';
                            btn.textContent = 'Edit';
                            btn.addEventListener('click', function(e) {
                                e.stopPropagation();
                                self.showUserForm(row);
                            });
                            return btn;
                        }
                    }
                ],
                data: result.data || [],
                total: (result.data || []).length,
                page: 1,
                perPage: 100
            });
        }).catch(function() { /* silent */ });
    },

    loadStatuses: function() {
        API.get('/api/statuses').then(function(result) {
            var container = document.getElementById('maint-panel-statuses');
            DataTable.render(container, {
                columns: [
                    { key: 'status_type_id', label: 'ID', sortable: false },
                    {
                        key: 'status_name', label: 'Status Name', sortable: false,
                        render: function(row) {
                            var cls = App.statusClass(row.status_name);
                            return '<span class="status-badge ' + cls + '">' + row.status_name + '</span>';
                        }
                    },
                    { key: 'display_order', label: 'Order', sortable: false },
                    {
                        key: 'is_active', label: 'Active', sortable: false,
                        render: function(row) { return row.is_active == 1 ? 'Yes' : 'No'; }
                    }
                ],
                data: result.data || [],
                total: (result.data || []).length,
                page: 1,
                perPage: 100
            });
        }).catch(function() { /* silent */ });
    },

    showUserForm: function(existing) {
        var isEdit = !!existing;
        var title = isEdit ? 'Edit User' : 'Add User';
        var self = this;

        var parts = [];
        parts.push('<div class="modal-header">');
        parts.push('<h2>' + title + '</h2>');
        parts.push('<button class="modal-close" onclick="App.closeModal()">&times;</button>');
        parts.push('</div>');
        parts.push('<div class="modal-body">');
        if (!isEdit) {
            parts.push('<div class="form-group">');
            parts.push('<label>Username *</label>');
            parts.push('<input type="text" id="user-username" placeholder="Username">');
            parts.push('</div>');
        }
        parts.push('<div class="form-group">');
        parts.push('<label>Display Name *</label>');
        parts.push('<input type="text" id="user-display-name" value="' + (existing ? existing.display_name : '') + '" placeholder="Full Name">');
        parts.push('</div>');
        parts.push('<div class="form-group">');
        parts.push('<label>' + (isEdit ? 'New Password (leave blank to keep current)' : 'Password *') + '</label>');
        parts.push('<input type="password" id="user-password" placeholder="' + (isEdit ? 'Leave blank to keep' : 'Min 8 characters') + '">');
        parts.push('</div>');
        parts.push('<div class="form-row">');
        parts.push('<div class="form-group">');
        parts.push('<label>Role</label>');
        parts.push('<select id="user-role">');
        parts.push('<option value="user"' + (existing && existing.role === 'user' ? ' selected' : '') + '>User</option>');
        parts.push('<option value="admin"' + (existing && existing.role === 'admin' ? ' selected' : '') + '>Admin</option>');
        parts.push('</select>');
        parts.push('</div>');
        if (isEdit) {
            parts.push('<div class="form-group">');
            parts.push('<label>Active</label>');
            parts.push('<select id="user-active">');
            parts.push('<option value="1"' + (existing.is_active == 1 ? ' selected' : '') + '>Active</option>');
            parts.push('<option value="0"' + (existing.is_active == 0 ? ' selected' : '') + '>Inactive</option>');
            parts.push('</select>');
            parts.push('</div>');
        }
        parts.push('</div>');
        parts.push('</div>');
        parts.push('<div class="modal-footer">');
        parts.push('<button class="btn btn-secondary" onclick="App.closeModal()">Cancel</button>');
        parts.push('<button class="btn btn-primary" id="user-save-btn">' + (isEdit ? 'Save Changes' : 'Create User') + '</button>');
        parts.push('</div>');

        App.openModal(parts.join('\n'));

        document.getElementById('user-save-btn').addEventListener('click', function() {
            self.saveUser(existing ? existing.user_id : null);
        });
    },

    saveUser: function(userId) {
        var data = {
            display_name: document.getElementById('user-display-name').value.trim(),
            password: document.getElementById('user-password').value,
            role: document.getElementById('user-role').value
        };

        if (!userId) {
            data.username = document.getElementById('user-username').value.trim();
            if (!data.username || !data.display_name || !data.password) {
                App.toast('All fields are required for new users', 'error');
                return;
            }
        }

        var activeEl = document.getElementById('user-active');
        if (activeEl) {
            data.is_active = parseInt(activeEl.value);
        }

        if (userId && !data.password) {
            delete data.password;
        }

        var self = this;
        var promise;
        if (userId) {
            promise = API.put('/api/users/' + userId, data);
        } else {
            promise = API.post('/api/users', data);
        }

        promise.then(function() {
            App.toast(userId ? 'User updated' : 'User created', 'success');
            App.closeModal();
            self.loadUsers();
        }).catch(function(err) {
            App.toast(err.message, 'error');
        });
    },

    // =========================================================
    // SQL Table Structures
    // =========================================================
    loadSqlTables: function() {
        var container = document.getElementById('maint-panel-sql-tables');
        container.innerHTML = '<p style="padding:24px;color:#888;">Loading table structures...</p>';

        API.get('/api/maintenance/table-structures').then(function(result) {
            var tables = result.tables || [];
            var totalRows = 0;
            tables.forEach(function(t) { totalRows += t.row_count; });

            var h = [];
            // Header row
            h.push('<div style="display:flex;align-items:center;gap:16px;margin-bottom:12px;padding-top:8px;">');
            h.push('<h3 class="section-title" style="margin:0;">Database Schema</h3>');
            h.push('<span class="text-muted" style="font-size:12px;">' + tables.length + ' tables &middot; ' + totalRows.toLocaleString() + ' total rows</span>');
            h.push('<button class="btn btn-secondary btn-sm" id="sql-expand-all" style="margin-left:auto;">Expand All</button>');
            h.push('<button class="btn btn-secondary btn-sm" id="sql-collapse-all">Collapse All</button>');
            h.push('</div>');

            // 60/40 split layout
            h.push('<div style="display:flex;gap:20px;align-items:flex-start;">');

            // LEFT COLUMN — collapsible table list (60%)
            h.push('<div style="flex:0 0 58%;min-width:0;">');

            tables.forEach(function(tbl, idx) {
                var colCount = tbl.columns.length;
                h.push('<div class="sql-table-entry" style="border:1px solid #e0e0e0;border-radius:6px;margin-bottom:6px;overflow:hidden;">');

                // Header (clickable) — compact
                h.push('<div class="sql-table-header" data-idx="' + idx + '" style="display:flex;align-items:center;gap:8px;padding:6px 10px;cursor:pointer;background:#fafafa;user-select:none;">');
                h.push('<span class="sql-toggle" style="font-size:9px;width:10px;color:#999;">&#9654;</span>');
                h.push('<strong style="font-size:12px;min-width:180px;white-space:nowrap;">' + tbl.table_name + '</strong>');
                h.push('<span style="font-size:10px;color:#666;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + (tbl.description || '') + '</span>');
                h.push('<span style="font-size:10px;color:#999;white-space:nowrap;">' + colCount + 'c/' + tbl.row_count.toLocaleString() + 'r</span>');
                if (tbl.feature) {
                    h.push('<span style="font-size:9px;background:#e3edf7;color:#1565c0;padding:1px 5px;border-radius:3px;white-space:nowrap;">' + tbl.feature + '</span>');
                }
                h.push('</div>');

                // Collapsible body
                h.push('<div class="sql-table-body" data-idx="' + idx + '" style="display:none;border-top:1px solid #e0e0e0;">');
                h.push('<table style="width:100%;font-size:10px;border-collapse:collapse;">');
                h.push('<thead><tr style="background:#f0f2f5;">');
                h.push('<th style="padding:3px 8px;text-align:left;font-weight:600;">Column</th>');
                h.push('<th style="padding:3px 8px;text-align:left;font-weight:600;">Type</th>');
                h.push('<th style="padding:3px 8px;text-align:center;font-weight:600;">Key</th>');
                h.push('<th style="padding:3px 8px;text-align:center;font-weight:600;">Null</th>');
                h.push('<th style="padding:3px 8px;text-align:left;font-weight:600;">Default</th>');
                h.push('</tr></thead><tbody>');

                tbl.columns.forEach(function(col) {
                    var keyBadge = '';
                    if (col.key === 'PRI') keyBadge = '<span style="background:#ff9800;color:#fff;padding:1px 3px;border-radius:2px;font-size:8px;">PK</span>';
                    else if (col.key === 'MUL') keyBadge = '<span style="background:#2196f3;color:#fff;padding:1px 3px;border-radius:2px;font-size:8px;">IDX</span>';
                    else if (col.key === 'UNI') keyBadge = '<span style="background:#9c27b0;color:#fff;padding:1px 3px;border-radius:2px;font-size:8px;">UNI</span>';

                    h.push('<tr style="border-top:1px solid #f0f0f0;">');
                    h.push('<td style="padding:2px 8px;font-family:monospace;font-size:10px;">' + col.name + '</td>');
                    h.push('<td style="padding:2px 8px;font-family:monospace;font-size:9px;color:#666;">' + col.type + '</td>');
                    h.push('<td style="padding:2px 8px;text-align:center;">' + keyBadge + '</td>');
                    h.push('<td style="padding:2px 8px;text-align:center;color:#999;font-size:9px;">' + (col.nullable ? 'YES' : '') + '</td>');
                    h.push('<td style="padding:2px 8px;font-size:9px;color:#888;">' + (col.default !== null ? col.default : '') + '</td>');
                    h.push('</tr>');
                });

                h.push('</tbody></table>');
                h.push('</div>');
                h.push('</div>');
            });

            h.push('</div>'); // end left column

            // RIGHT COLUMN — relationship diagram (40%)
            h.push('<div style="flex:0 0 40%;min-width:0;position:sticky;top:20px;">');
            h.push('<div style="background:#1a1a2e;color:#c8d6e5;border-radius:8px;padding:16px 18px;font-family:\'Consolas\',\'Courier New\',monospace;font-size:11px;line-height:1.6;overflow-x:auto;">');
            h.push('<div style="color:#4a9eff;font-weight:600;font-size:12px;margin-bottom:10px;border-bottom:1px solid #2d3748;padding-bottom:6px;">TABLE RELATIONSHIPS</div>');

            // Core Transaction Flow
            h.push('<div style="color:#ffd93d;font-weight:600;margin-top:8px;">CORE TRANSACTION FLOW</div>');
            h.push('<div style="color:#6c7a89;"> &#9474;</div>');
            h.push('<div> &#9500;&#9472; <span style="color:#4a9eff;">CG_EarningsStatements</span></div>');
            h.push('<div style="color:#6c7a89;"> &#9474;   &#9492;&#9472;&#9472;</div>');
            h.push('<div> &#9500;&#9472; <span style="color:#4a9eff;">CG_AuctionLineItems</span> <span style="color:#666;">(FK: statement_id, buyer_id, livestream_id, status_type_id)</span></div>');
            h.push('<div style="color:#6c7a89;"> &#9474;   &#9500;&#9472;&#9472;</div>');
            h.push('<div> &#9474;   &#9500;&#9472; <span style="color:#81c784;">CG_ItemCosts</span> <span style="color:#666;">(FK: line_item_id)</span></div>');
            h.push('<div> &#9474;   &#9492;&#9472; <span style="color:#81c784;">CG_StatusHistory</span> <span style="color:#666;">(FK: line_item_id, status_type_id)</span></div>');
            h.push('<div style="color:#6c7a89;"> &#9474;</div>');
            h.push('<div> &#9500;&#9472; <span style="color:#4a9eff;">CG_Livestreams</span> <span style="color:#666;">(referenced by AuctionLineItems)</span></div>');
            h.push('<div> &#9500;&#9472; <span style="color:#4a9eff;">CG_Buyers</span> <span style="color:#666;">(referenced by AuctionLineItems)</span></div>');
            h.push('<div> &#9492;&#9472; <span style="color:#4a9eff;">CG_StatusTypes</span> <span style="color:#666;">(referenced by AuctionLineItems, StatusHistory)</span></div>');

            // Financial
            h.push('<div style="color:#ffd93d;font-weight:600;margin-top:14px;">FINANCIAL</div>');
            h.push('<div style="color:#6c7a89;"> &#9474;</div>');
            h.push('<div> &#9500;&#9472; <span style="color:#4a9eff;">CG_Payouts</span> <span style="color:#666;">(standalone)</span></div>');
            h.push('<div> &#9500;&#9472; <span style="color:#4a9eff;">CG_GeneralCosts</span> <span style="color:#666;">(standalone)</span></div>');
            h.push('<div> &#9492;&#9472; <span style="color:#4a9eff;">CG_TaxRecords</span> <span style="color:#666;">(FK: locked_by &rarr; Users)</span></div>');

            // PayPal
            h.push('<div style="color:#ffd93d;font-weight:600;margin-top:14px;">PAYPAL</div>');
            h.push('<div style="color:#6c7a89;"> &#9474;</div>');
            h.push('<div> &#9500;&#9472; <span style="color:#4a9eff;">CG_PayPalTransactions</span></div>');
            h.push('<div style="color:#6c7a89;"> &#9474;   &#9492;&#9472;&#9472;</div>');
            h.push('<div> &#9492;&#9472; <span style="color:#81c784;">CG_PayPalAllocations</span> <span style="color:#666;">(FK: transaction_id)</span></div>');

            // Teams hierarchy
            h.push('<div style="color:#ffd93d;font-weight:600;margin-top:14px;">PARSER — TEAMS</div>');
            h.push('<div style="color:#6c7a89;"> &#9474;</div>');
            h.push('<div> &#9492;&#9472; <span style="color:#4a9eff;">CG_Teams</span></div>');
            h.push('<div>     &#9500;&#9472; <span style="color:#81c784;">CG_TeamAliases</span> <span style="color:#666;">(FK: team_id)</span></div>');
            h.push('<div>     &#9492;&#9472; <span style="color:#81c784;">CG_TeamStatistics</span> <span style="color:#666;">(FK: team_id)</span></div>');

            // Players hierarchy
            h.push('<div style="color:#ffd93d;font-weight:600;margin-top:14px;">PARSER — PLAYERS</div>');
            h.push('<div style="color:#6c7a89;"> &#9474;</div>');
            h.push('<div> &#9492;&#9472; <span style="color:#4a9eff;">CG_Players</span></div>');
            h.push('<div>     &#9500;&#9472; <span style="color:#81c784;">CG_PlayerNicknames</span> <span style="color:#666;">(FK: player_id)</span></div>');
            h.push('<div>     &#9492;&#9472; <span style="color:#81c784;">CG_PlayerStatistics</span> <span style="color:#666;">(FK: player_id)</span></div>');

            // Cards
            h.push('<div style="color:#ffd93d;font-weight:600;margin-top:14px;">PARSER — CARDS</div>');
            h.push('<div style="color:#6c7a89;"> &#9474;</div>');
            h.push('<div> &#9500;&#9472; <span style="color:#4a9eff;">CG_CardMakers</span> <span style="color:#666;">(standalone)</span></div>');
            h.push('<div> &#9500;&#9472; <span style="color:#4a9eff;">CG_CardStyles</span> <span style="color:#666;">(standalone)</span></div>');
            h.push('<div> &#9500;&#9472; <span style="color:#4a9eff;">CG_CardSpecialties</span> <span style="color:#666;">(standalone)</span></div>');
            h.push('<div> &#9492;&#9472; <span style="color:#4a9eff;">CG_DataRefreshLog</span> <span style="color:#666;">(standalone)</span></div>');

            // Transcription
            h.push('<div style="color:#ffd93d;font-weight:600;margin-top:14px;">TRANSCRIPTION</div>');
            h.push('<div style="color:#6c7a89;"> &#9474;</div>');
            h.push('<div> &#9500;&#9472; <span style="color:#4a9eff;">CG_TranscriptionSettings</span> <span style="color:#666;">(singleton config)</span></div>');
            h.push('<div> &#9492;&#9472; <span style="color:#4a9eff;">CG_TranscriptionSessions</span></div>');
            h.push('<div>     &#9500;&#9472; <span style="color:#81c784;">CG_TranscriptionSegments</span> <span style="color:#666;">(FK: session_id)</span></div>');
            h.push('<div>     &#9500;&#9472; <span style="color:#81c784;">CG_TranscriptionLogs</span> <span style="color:#666;">(FK: session_id)</span></div>');
            h.push('<div>     &#9492;&#9472; <span style="color:#81c784;">CG_TranscriptionParseRuns</span> <span style="color:#666;">(FK: session_id)</span></div>');
            h.push('<div>         &#9492;&#9472; <span style="color:#e57373;">CG_TranscriptionRecords</span> <span style="color:#666;">(FK: parse_run_id)</span></div>');

            // Alerts
            h.push('<div style="color:#ffd93d;font-weight:600;margin-top:14px;">ALERTS</div>');
            h.push('<div style="color:#6c7a89;"> &#9474;</div>');
            h.push('<div> &#9500;&#9472; <span style="color:#4a9eff;">CG_AlertDefinitions</span></div>');
            h.push('<div> &#9474;   &#9492;&#9472; <span style="color:#81c784;">CG_AlertDismissals</span> <span style="color:#666;">(FK: alert_id, user_id)</span></div>');
            h.push('<div> &#9492;&#9472; <span style="color:#4a9eff;">CG_ScrollSettings</span> <span style="color:#666;">(standalone)</span></div>');

            // System
            h.push('<div style="color:#ffd93d;font-weight:600;margin-top:14px;">SYSTEM</div>');
            h.push('<div style="color:#6c7a89;"> &#9474;</div>');
            h.push('<div> &#9500;&#9472; <span style="color:#4a9eff;">CG_Users</span> <span style="color:#666;">(referenced by many tables)</span></div>');
            h.push('<div> &#9500;&#9472; <span style="color:#4a9eff;">CG_Sessions</span> <span style="color:#666;">(FK: user_id)</span></div>');
            h.push('<div> &#9500;&#9472; <span style="color:#4a9eff;">CG_UploadLog</span> <span style="color:#666;">(FK: uploaded_by)</span></div>');
            h.push('<div> &#9500;&#9472; <span style="color:#4a9eff;">CG_AnalyticsMetrics</span> <span style="color:#666;">(standalone)</span></div>');
            h.push('<div> &#9500;&#9472; <span style="color:#4a9eff;">CG_AnalyticsMilestones</span> <span style="color:#666;">(FK: metric_id)</span></div>');
            h.push('<div> &#9500;&#9472; <span style="color:#4a9eff;">CG_CostMatrixRules</span> <span style="color:#666;">(standalone)</span></div>');
            h.push('<div> &#9492;&#9472; <span style="color:#4a9eff;">CG_EbayOrders</span> <span style="color:#666;">(standalone)</span></div>');

            // Legend
            h.push('<div style="margin-top:14px;border-top:1px solid #2d3748;padding-top:8px;font-size:10px;color:#6c7a89;">');
            h.push('<span style="color:#4a9eff;">&#9632;</span> Parent &nbsp; ');
            h.push('<span style="color:#81c784;">&#9632;</span> Child (FK) &nbsp; ');
            h.push('<span style="color:#e57373;">&#9632;</span> Grandchild');
            h.push('</div>');

            h.push('</div>'); // end diagram box
            h.push('</div>'); // end right column

            h.push('</div>'); // end flex container
            container.innerHTML = h.join('');

            // Toggle handlers
            var headers = container.querySelectorAll('.sql-table-header');
            for (var i = 0; i < headers.length; i++) {
                (function(header) {
                    header.addEventListener('click', function() {
                        var idx = header.getAttribute('data-idx');
                        var body = container.querySelector('.sql-table-body[data-idx="' + idx + '"]');
                        var toggle = header.querySelector('.sql-toggle');
                        if (body.style.display === 'none') {
                            body.style.display = '';
                            toggle.innerHTML = '&#9660;';
                            header.style.background = '#fff';
                        } else {
                            body.style.display = 'none';
                            toggle.innerHTML = '&#9654;';
                            header.style.background = '#fafafa';
                        }
                    });
                })(headers[i]);
            }

            // Expand/Collapse All
            document.getElementById('sql-expand-all').addEventListener('click', function() {
                var bodies = container.querySelectorAll('.sql-table-body');
                var toggles = container.querySelectorAll('.sql-toggle');
                var hdrs = container.querySelectorAll('.sql-table-header');
                for (var k = 0; k < bodies.length; k++) {
                    bodies[k].style.display = '';
                    toggles[k].innerHTML = '&#9660;';
                    hdrs[k].style.background = '#fff';
                }
            });
            document.getElementById('sql-collapse-all').addEventListener('click', function() {
                var bodies = container.querySelectorAll('.sql-table-body');
                var toggles = container.querySelectorAll('.sql-toggle');
                var hdrs = container.querySelectorAll('.sql-table-header');
                for (var k = 0; k < bodies.length; k++) {
                    bodies[k].style.display = 'none';
                    toggles[k].innerHTML = '&#9654;';
                    hdrs[k].style.background = '#fafafa';
                }
            });

        }).catch(function(err) {
            container.innerHTML = '<p style="padding:24px;color:#c62828;">Failed to load table structures: ' + (err.message || 'Unknown error') + '</p>';
        });
    }
};
