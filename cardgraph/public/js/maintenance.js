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
            panels.push('cost-matrix', 'analytics-standards', 'alerts', 'transcription', 'users');
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
    }
};
