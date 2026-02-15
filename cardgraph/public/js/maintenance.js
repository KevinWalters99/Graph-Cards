/**
 * Card Graph — Maintenance Tab
 */
const Maintenance = {
    initialized: false,

    async init() {
        const panel = document.getElementById('tab-maintenance');

        if (!this.initialized) {
            const isAdmin = App.user && App.user.role === 'admin';

            panel.innerHTML = `
                <div class="page-header">
                    <h1>Maintenance</h1>
                </div>

                <div class="mb-4">
                    <h3 class="section-title">Upload History</h3>
                    <div id="maint-upload-log"></div>
                </div>

                ${isAdmin ? `
                <div class="mb-4">
                    <h3 class="section-title">User Management</h3>
                    <button class="btn btn-success mb-2" id="btn-add-user">Add User</button>
                    <div id="maint-users"></div>
                </div>
                ` : ''}

                <div class="mb-4">
                    <h3 class="section-title">Status Types</h3>
                    <div id="maint-statuses"></div>
                </div>
            `;

            if (isAdmin) {
                document.getElementById('btn-add-user')?.addEventListener('click', () => this.showUserForm());
            }

            this.initialized = true;
        }

        this.loadAll();
    },

    async loadAll() {
        try {
            App.showLoading();
            await Promise.all([
                this.loadUploadLog(),
                this.loadStatuses(),
                App.user.role === 'admin' ? this.loadUsers() : Promise.resolve(),
            ]);
        } catch (err) {
            App.toast(err.message, 'error');
        } finally {
            App.hideLoading();
        }
    },

    async loadUploadLog() {
        try {
            const result = await API.get('/api/maintenance/upload-log');
            const container = document.getElementById('maint-upload-log');

            DataTable.render(container, {
                columns: [
                    { key: 'original_filename', label: 'Filename', sortable: false },
                    { key: 'upload_type', label: 'Type', sortable: false },
                    {
                        key: 'uploaded_at', label: 'Uploaded', sortable: false,
                        format: (v) => App.formatDatetime(v)
                    },
                    { key: 'uploaded_by_name', label: 'By', sortable: false },
                    { key: 'rows_inserted', label: 'Inserted', align: 'right', sortable: false },
                    { key: 'rows_skipped', label: 'Skipped', align: 'right', sortable: false },
                    {
                        key: 'status', label: 'Status', sortable: false,
                        render: (row) => {
                            const cls = App.statusClass(row.status);
                            return `<span class="status-badge ${cls}">${row.status}</span>`;
                        }
                    },
                    {
                        key: 'parsed_start_date', label: 'Date Range', sortable: false,
                        render: (row) => {
                            if (row.parsed_start_date && row.parsed_end_date) {
                                return `${App.formatDate(row.parsed_start_date)} - ${App.formatDate(row.parsed_end_date)}`;
                            }
                            return '-';
                        }
                    },
                ],
                data: result.data || [],
                total: result.total || 0,
                page: result.page || 1,
                perPage: result.per_page || 50,
            });
        } catch (e) {
            document.getElementById('maint-upload-log').innerHTML =
                '<p class="text-muted">Unable to load upload log. Admin access may be required.</p>';
        }
    },

    async loadUsers() {
        try {
            const result = await API.get('/api/users');
            const container = document.getElementById('maint-users');

            DataTable.render(container, {
                columns: [
                    { key: 'username', label: 'Username', sortable: false },
                    { key: 'display_name', label: 'Display Name', sortable: false },
                    { key: 'role', label: 'Role', sortable: false },
                    {
                        key: 'is_active', label: 'Active', sortable: false,
                        render: (row) => row.is_active == 1
                            ? '<span class="status-badge status-completed">Active</span>'
                            : '<span class="status-badge status-cancelled">Inactive</span>'
                    },
                    {
                        key: 'created_at', label: 'Created', sortable: false,
                        format: (v) => App.formatDatetime(v)
                    },
                    {
                        key: 'actions', label: 'Actions', sortable: false,
                        render: (row) => {
                            const btn = document.createElement('button');
                            btn.className = 'btn btn-secondary btn-sm';
                            btn.textContent = 'Edit';
                            btn.addEventListener('click', (e) => {
                                e.stopPropagation();
                                Maintenance.showUserForm(row);
                            });
                            return btn;
                        }
                    },
                ],
                data: result.data || [],
                total: (result.data || []).length,
                page: 1,
                perPage: 100,
            });
        } catch (e) {
            // Non-admin can't see users
        }
    },

    async loadStatuses() {
        try {
            const result = await API.get('/api/statuses');
            const container = document.getElementById('maint-statuses');

            DataTable.render(container, {
                columns: [
                    { key: 'status_type_id', label: 'ID', sortable: false },
                    {
                        key: 'status_name', label: 'Status Name', sortable: false,
                        render: (row) => {
                            const cls = App.statusClass(row.status_name);
                            return `<span class="status-badge ${cls}">${row.status_name}</span>`;
                        }
                    },
                    { key: 'display_order', label: 'Order', sortable: false },
                    {
                        key: 'is_active', label: 'Active', sortable: false,
                        render: (row) => row.is_active == 1 ? 'Yes' : 'No'
                    },
                ],
                data: result.data || [],
                total: (result.data || []).length,
                page: 1,
                perPage: 100,
            });
        } catch (e) { /* silent */ }
    },

    showUserForm(existing = null) {
        const isEdit = !!existing;
        const title = isEdit ? 'Edit User' : 'Add User';

        App.openModal(`
            <div class="modal-header">
                <h2>${title}</h2>
                <button class="modal-close" onclick="App.closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                ${!isEdit ? `
                <div class="form-group">
                    <label>Username *</label>
                    <input type="text" id="user-username" placeholder="Username">
                </div>` : ''}
                <div class="form-group">
                    <label>Display Name *</label>
                    <input type="text" id="user-display-name"
                           value="${existing ? existing.display_name : ''}" placeholder="Full Name">
                </div>
                <div class="form-group">
                    <label>${isEdit ? 'New Password (leave blank to keep current)' : 'Password *'}</label>
                    <input type="password" id="user-password" placeholder="${isEdit ? 'Leave blank to keep' : 'Min 8 characters'}">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Role</label>
                        <select id="user-role">
                            <option value="user" ${existing && existing.role === 'user' ? 'selected' : ''}>User</option>
                            <option value="admin" ${existing && existing.role === 'admin' ? 'selected' : ''}>Admin</option>
                        </select>
                    </div>
                    ${isEdit ? `
                    <div class="form-group">
                        <label>Active</label>
                        <select id="user-active">
                            <option value="1" ${existing.is_active == 1 ? 'selected' : ''}>Active</option>
                            <option value="0" ${existing.is_active == 0 ? 'selected' : ''}>Inactive</option>
                        </select>
                    </div>` : ''}
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="App.closeModal()">Cancel</button>
                <button class="btn btn-primary" id="user-save-btn">${isEdit ? 'Save Changes' : 'Create User'}</button>
            </div>
        `);

        document.getElementById('user-save-btn').addEventListener('click', () => {
            this.saveUser(existing ? existing.user_id : null);
        });
    },

    async saveUser(userId) {
        const data = {
            display_name: document.getElementById('user-display-name').value.trim(),
            password: document.getElementById('user-password').value,
            role: document.getElementById('user-role').value,
        };

        if (!userId) {
            data.username = document.getElementById('user-username').value.trim();
            if (!data.username || !data.display_name || !data.password) {
                App.toast('All fields are required for new users', 'error');
                return;
            }
        }

        const activeEl = document.getElementById('user-active');
        if (activeEl) {
            data.is_active = parseInt(activeEl.value);
        }

        // Don't send empty password on edit
        if (userId && !data.password) {
            delete data.password;
        }

        try {
            if (userId) {
                await API.put(`/api/users/${userId}`, data);
                App.toast('User updated', 'success');
            } else {
                await API.post('/api/users', data);
                App.toast('User created', 'success');
            }
            App.closeModal();
            this.loadUsers();
        } catch (err) {
            App.toast(err.message, 'error');
        }
    }
};
