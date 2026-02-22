/**
 * Card Graph — Main Application
 * Initializes the SPA, handles tab navigation, and coordinates modules.
 */
const App = {
    currentTab: 'dashboard',
    user: null,

    async init() {
        // Check if logged in
        const user = await AuthModule.checkSession();
        if (!user) {
            window.location.href = '/login.html';
            return;
        }

        this.user = user;
        API.csrfToken = localStorage.getItem('cg_csrf');

        // Update sidebar user info
        document.getElementById('current-user').textContent = user.display_name;
        const roleBadge = document.getElementById('current-role');
        roleBadge.textContent = user.role;
        if (user.role === 'admin') {
            roleBadge.style.background = 'rgba(255, 183, 77, 0.2)';
            roleBadge.style.color = '#ffb74d';
        }

        // Set up tab navigation
        document.querySelectorAll('.nav-tabs li').forEach(tab => {
            tab.addEventListener('click', () => this.switchTab(tab.dataset.tab));
        });

        // Logout button
        document.getElementById('logout-btn').addEventListener('click', () => AuthModule.logout());

        // Load alerts & scroll ticker
        Alerts.loadActive();
        setInterval(function() { Alerts.loadActive(); }, 300000); // refresh every 5 min

        // Load initial tab
        this.switchTab('dashboard');
    },

    switchTab(tabName) {
        this.currentTab = tabName;

        // Update nav active state
        document.querySelectorAll('.nav-tabs li').forEach(tab => {
            tab.classList.toggle('active', tab.dataset.tab === tabName);
        });

        // Update panel visibility
        document.querySelectorAll('.tab-panel').forEach(panel => {
            panel.classList.toggle('active', panel.id === `tab-${tabName}`);
        });

        // Stop MLB live polling when leaving the tab
        if (typeof Mlb !== 'undefined' && Mlb.liveTimer) {
            Mlb.stopLivePolling();
        }

        // Initialize tab content
        switch (tabName) {
            case 'dashboard':
                Dashboard.init();
                break;
            case 'line-items':
                LineItems.init();
                break;
            case 'top-buyers':
                TopBuyers.init();
                break;
            case 'payouts':
                Payouts.init();
                break;
            case 'financial-summary':
                FinancialSummary.init();
                break;
            case 'ebay':
                Ebay.init();
                break;
            case 'paypal':
                PayPal.init();
                break;
            case 'analytics':
                Analytics.init();
                break;
            case 'mlb':
                Mlb.init();
                break;
            case 'maintenance':
                Maintenance.init();
                break;
        }

        // Move scroll ticker into the active tab's page-header (right of the title)
        const ticker = document.getElementById('scroll-ticker');
        const header = document.querySelector(`#tab-${tabName} .page-header`);
        if (header && ticker) {
            const h1 = header.querySelector('h1');
            if (h1 && h1.nextElementSibling) {
                header.insertBefore(ticker, h1.nextElementSibling);
            } else {
                header.appendChild(ticker);
            }
        }
    },

    /**
     * Show a toast notification.
     */
    toast(message, type = 'info') {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.textContent = message;
        container.appendChild(toast);
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 0.3s';
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    },

    /**
     * Show the loading overlay.
     */
    showLoading() {
        document.getElementById('loading').style.display = 'flex';
    },

    hideLoading() {
        document.getElementById('loading').style.display = 'none';
    },

    /**
     * Open a modal with custom HTML content.
     */
    openModal(html) {
        const overlay = document.getElementById('modal-overlay');
        const content = document.getElementById('modal-content');
        content.innerHTML = html;
        overlay.style.display = 'flex';

        // Close on overlay click, but only if mousedown also started on overlay
        // (prevents closing when selecting text in inputs and mouse drifts outside)
        let mouseDownTarget = null;
        overlay.onmousedown = (e) => { mouseDownTarget = e.target; };
        overlay.onclick = (e) => {
            if (e.target === overlay && mouseDownTarget === overlay) this.closeModal();
        };
    },

    closeModal() {
        document.getElementById('modal-overlay').style.display = 'none';
    },

    /**
     * Format a number as currency.
     */
    formatCurrency(val) {
        if (val === null || val === undefined) return '-';
        const num = parseFloat(val);
        return '$' + num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },

    /**
     * Format a datetime string for display.
     */
    formatDate(val) {
        if (!val) return '-';
        const d = new Date(val + 'T00:00:00');
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    },

    formatDatetime(val) {
        if (!val) return '-';
        // Handle both 'YYYY-MM-DD HH:MM:SS' and ISO formats
        const d = new Date(val.replace(' ', 'T'));
        return d.toLocaleDateString('en-US', {
            month: 'short', day: 'numeric', year: 'numeric',
            hour: 'numeric', minute: '2-digit'
        });
    },

    /**
     * Get CSS class for a status name.
     */
    statusClass(statusName) {
        if (!statusName) return '';
        return 'status-' + statusName.toLowerCase().replace(/\s+/g, '-');
    }
};

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => App.init());
