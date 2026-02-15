/**
 * Card Graph — Auth Module
 * Handles login form and session management.
 */
const AuthModule = {
    init() {
        const form = document.getElementById('login-form');
        if (form) {
            form.addEventListener('submit', (e) => this.handleLogin(e));
        }
    },

    async handleLogin(e) {
        e.preventDefault();
        const btn = document.getElementById('login-btn');
        const errorDiv = document.getElementById('login-error');
        const username = document.getElementById('username').value.trim();
        const password = document.getElementById('password').value;

        if (!username || !password) {
            this.showError('Please enter username and password');
            return;
        }

        btn.disabled = true;
        btn.textContent = 'Signing in...';
        errorDiv.style.display = 'none';

        try {
            const result = await API.post('/api/auth/login', { username, password });
            if (result && result.csrf_token) {
                API.csrfToken = result.csrf_token;
                localStorage.setItem('cg_csrf', result.csrf_token);
                localStorage.setItem('cg_user', JSON.stringify(result.user));
                window.location.href = '/app.html';
            }
        } catch (err) {
            this.showError(err.message || 'Login failed');
        } finally {
            btn.disabled = false;
            btn.textContent = 'Sign In';
        }
    },

    showError(msg) {
        const errorDiv = document.getElementById('login-error');
        if (errorDiv) {
            errorDiv.textContent = msg;
            errorDiv.style.display = 'block';
        }
    },

    async checkSession() {
        try {
            const stored = localStorage.getItem('cg_csrf');
            if (stored) API.csrfToken = stored;

            const result = await API.get('/api/auth/me');
            if (result && result.user) {
                API.csrfToken = result.csrf_token;
                localStorage.setItem('cg_csrf', result.csrf_token);
                localStorage.setItem('cg_user', JSON.stringify(result.user));
                return result.user;
            }
        } catch (e) {
            // Not logged in
        }
        return null;
    },

    async logout() {
        try {
            await API.post('/api/auth/logout');
        } catch (e) {
            // Ignore errors on logout
        }
        localStorage.removeItem('cg_csrf');
        localStorage.removeItem('cg_user');
        API.csrfToken = null;
        window.location.href = '/login.html';
    }
};

// Auto-init on login page
if (document.getElementById('login-form')) {
    AuthModule.init();
}
