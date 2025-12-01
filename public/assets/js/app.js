/**
 * Matdan Control Nexus - Main Application JavaScript
 *
 * Provides common functionality across all pages.
 */

const MCN = {
    apiBase: '/api',
    currentUser: null,

    /**
     * Initialize the application
     */
    init() {
        this.checkAuth();
        this.setupEventListeners();
    },

    /**
     * Check authentication status
     */
    async checkAuth() {
        try {
            const response = await this.api('auth.php?action=check');

            if (response.authenticated) {
                this.currentUser = response.user;
                this.updateUserUI();
            } else {
                // Redirect to login if not on login page
                if (!window.location.pathname.includes('login.php')) {
                    window.location.href = 'login.php';
                }
            }
        } catch (error) {
            console.error('Auth check failed:', error);
        }
    },

    /**
     * Update user interface with current user info
     */
    updateUserUI() {
        const userInfoEl = document.querySelector('.user-info');
        if (userInfoEl && this.currentUser) {
            userInfoEl.textContent = `${this.currentUser.username} (${this.currentUser.role})`;
        }
    },

    /**
     * Setup global event listeners
     */
    setupEventListeners() {
        // Logout button
        document.addEventListener('click', (e) => {
            if (e.target.matches('[data-action="logout"]')) {
                e.preventDefault();
                this.logout();
            }
        });

        // Form submissions
        document.addEventListener('submit', (e) => {
            if (e.target.matches('[data-ajax-form]')) {
                e.preventDefault();
                this.handleAjaxForm(e.target);
            }
        });
    },

    /**
     * Logout
     */
    async logout() {
        try {
            await this.api('auth.php?action=logout', 'POST');
            window.location.href = 'login.php';
        } catch (error) {
            this.showAlert('Logout failed', 'danger');
        }
    },

    /**
     * Generic API request helper
     */
    async api(endpoint, method = 'GET', data = null) {
        const url = `${this.apiBase}/${endpoint}`;

        const options = {
            method: method,
            headers: {
                'Content-Type': 'application/json',
            },
        };

        if (data && (method === 'POST' || method === 'PUT')) {
            options.body = JSON.stringify(data);
        }

        try {
            const response = await fetch(url, options);
            const result = await response.json();

            if (!response.ok) {
                throw new Error(result.error || 'API request failed');
            }

            return result;
        } catch (error) {
            console.error('API Error:', error);
            throw error;
        }
    },

    /**
     * Handle AJAX form submissions
     */
    async handleAjaxForm(form) {
        const formData = new FormData(form);
        const data = Object.fromEntries(formData.entries());
        const endpoint = form.getAttribute('data-endpoint');
        const method = form.getAttribute('data-method') || 'POST';

        try {
            const result = await this.api(endpoint, method, data);

            if (result.success) {
                this.showAlert('Operation successful', 'success');

                // Trigger custom event
                form.dispatchEvent(new CustomEvent('ajax-success', { detail: result }));
            }
        } catch (error) {
            this.showAlert(error.message, 'danger');
        }
    },

    /**
     * Show alert message
     */
    showAlert(message, type = 'info') {
        const alertContainer = document.querySelector('.alert-container') || this.createAlertContainer();

        const alert = document.createElement('div');
        alert.className = `alert alert-${type}`;
        alert.textContent = message;

        alertContainer.appendChild(alert);

        // Auto-remove after 5 seconds
        setTimeout(() => {
            alert.remove();
        }, 5000);
    },

    /**
     * Create alert container if it doesn't exist
     */
    createAlertContainer() {
        const container = document.createElement('div');
        container.className = 'alert-container';
        container.style.position = 'fixed';
        container.style.top = '20px';
        container.style.right = '20px';
        container.style.zIndex = '9999';
        container.style.maxWidth = '400px';

        document.body.appendChild(container);
        return container;
    },

    /**
     * Show loading spinner
     */
    showLoading(element) {
        if (typeof element === 'string') {
            element = document.querySelector(element);
        }

        if (element) {
            element.innerHTML = '<div class="spinner"></div>';
        }
    },

    /**
     * Format bytes to human-readable
     */
    formatBytes(bytes, decimals = 2) {
        if (bytes === 0) return '0 Bytes';

        const k = 1024;
        const dm = decimals < 0 ? 0 : decimals;
        const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];

        const i = Math.floor(Math.log(bytes) / Math.log(k));

        return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
    },

    /**
     * Format date to local string
     */
    formatDate(date) {
        if (!date) return '-';

        const d = new Date(date);
        return d.toLocaleString();
    },

    /**
     * Format uptime in seconds to human-readable
     */
    formatUptime(seconds) {
        if (!seconds) return '-';

        const days = Math.floor(seconds / 86400);
        const hours = Math.floor((seconds % 86400) / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);

        const parts = [];
        if (days > 0) parts.push(`${days}d`);
        if (hours > 0) parts.push(`${hours}h`);
        if (minutes > 0) parts.push(`${minutes}m`);

        return parts.join(' ') || '0m';
    },

    /**
     * Get status badge HTML
     */
    getStatusBadge(status) {
        const badges = {
            'online': '<span class="badge badge-success">Online</span>',
            'offline': '<span class="badge badge-secondary">Offline</span>',
            'error': '<span class="badge badge-danger">Error</span>',
            'running': '<span class="badge badge-success">Running</span>',
            'stopped': '<span class="badge badge-secondary">Stopped</span>',
        };

        return badges[status] || `<span class="badge badge-secondary">${status}</span>`;
    },

    /**
     * Debounce function
     */
    debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    },

    /**
     * Confirm dialog
     */
    confirm(message) {
        return window.confirm(message);
    },
};

// Initialize on DOM ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => MCN.init());
} else {
    MCN.init();
}
