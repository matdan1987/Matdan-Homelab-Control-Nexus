/**
 * Dashboard JavaScript
 *
 * Handles dashboard widgets, layout, and data refresh.
 */

const Dashboard = {
    widgets: [],
    refreshInterval: 30000, // 30 seconds
    refreshTimers: {},

    /**
     * Initialize dashboard
     */
    async init() {
        await this.loadLayout();
        this.setupWidgets();
        this.startAutoRefresh();
    },

    /**
     * Load dashboard layout
     */
    async loadLayout() {
        try {
            const response = await MCN.api('dashboard.php?action=layout');
            this.widgets = response.layout || [];

            if (this.widgets.length === 0) {
                // Load default layout
                this.widgets = await this.getDefaultLayout();
            }

            this.renderWidgets();
        } catch (error) {
            console.error('Failed to load layout:', error);
            MCN.showAlert('Failed to load dashboard layout', 'danger');
        }
    },

    /**
     * Get default layout
     */
    async getDefaultLayout() {
        return [
            { type: 'nodes_overview', position: 0, size: 'large' },
            { type: 'critical_services', position: 1, size: 'medium' },
            { type: 'recent_events', position: 2, size: 'medium' },
            { type: 'top_cpu_consumers', position: 3, size: 'medium' },
        ];
    },

    /**
     * Render widgets on the dashboard
     */
    renderWidgets() {
        const container = document.getElementById('dashboard-grid');
        if (!container) return;

        container.innerHTML = '';

        // Sort widgets by position
        this.widgets.sort((a, b) => a.position - b.position);

        this.widgets.forEach((widget, index) => {
            const widgetEl = this.createWidgetElement(widget, index);
            container.appendChild(widgetEl);
        });
    },

    /**
     * Create widget DOM element
     */
    createWidgetElement(widget, index) {
        const div = document.createElement('div');
        div.className = `widget ${widget.size === 'large' ? 'widget-large' : ''}`;
        div.dataset.widgetType = widget.type;
        div.dataset.widgetIndex = index;

        div.innerHTML = `
            <div class="widget-header">
                <h3 class="widget-title">${this.getWidgetTitle(widget.type)}</h3>
                <div class="widget-actions">
                    <button class="btn btn-sm btn-secondary" data-action="refresh-widget" data-index="${index}">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </div>
            </div>
            <div class="widget-body" id="widget-body-${index}">
                <div class="spinner"></div>
            </div>
        `;

        return div;
    },

    /**
     * Get widget title
     */
    getWidgetTitle(type) {
        const titles = {
            'nodes_overview': 'Nodes Overview',
            'critical_services': 'Critical Services',
            'recent_events': 'Recent Events',
            'top_cpu_consumers': 'Top CPU Consumers',
            'top_memory_consumers': 'Top Memory Consumers',
            'maintenance_windows': 'Maintenance Windows',
            'backup_status': 'Backup Status',
        };

        return titles[type] || type;
    },

    /**
     * Setup widget event listeners
     */
    setupWidgets() {
        document.addEventListener('click', async (e) => {
            if (e.target.closest('[data-action="refresh-widget"]')) {
                const index = e.target.closest('[data-action="refresh-widget"]').dataset.index;
                await this.refreshWidget(parseInt(index));
            }
        });

        // Load widget data
        this.widgets.forEach((widget, index) => {
            this.refreshWidget(index);
        });
    },

    /**
     * Refresh widget data
     */
    async refreshWidget(index) {
        const widget = this.widgets[index];
        if (!widget) return;

        const bodyEl = document.getElementById(`widget-body-${index}`);
        if (!bodyEl) return;

        try {
            const response = await MCN.api(`dashboard.php?action=widget_data&widget_type=${widget.type}`);
            this.renderWidgetData(widget.type, response.data, bodyEl);
        } catch (error) {
            console.error(`Failed to load widget ${widget.type}:`, error);
            bodyEl.innerHTML = `<p class="text-danger">Failed to load data</p>`;
        }
    },

    /**
     * Render widget data
     */
    renderWidgetData(type, data, element) {
        switch (type) {
            case 'nodes_overview':
                this.renderNodesOverview(data, element);
                break;
            case 'critical_services':
                this.renderCriticalServices(data, element);
                break;
            case 'recent_events':
                this.renderRecentEvents(data, element);
                break;
            case 'top_cpu_consumers':
                this.renderTopCpuConsumers(data, element);
                break;
            case 'top_memory_consumers':
                this.renderTopMemoryConsumers(data, element);
                break;
            case 'maintenance_windows':
                this.renderMaintenanceWindows(data, element);
                break;
            case 'backup_status':
                this.renderBackupStatus(data, element);
                break;
            default:
                element.innerHTML = '<p>Unknown widget type</p>';
        }
    },

    /**
     * Widget renderers
     */
    renderNodesOverview(data, element) {
        element.innerHTML = `
            <div class="d-flex justify-content-between gap-3">
                <div class="stat-widget">
                    <div class="stat-value">${data.total_nodes}</div>
                    <div class="stat-label">Total Nodes</div>
                </div>
                <div class="stat-widget">
                    <div class="stat-value text-success">${data.online_nodes}</div>
                    <div class="stat-label">Online</div>
                </div>
                <div class="stat-widget">
                    <div class="stat-value">${data.total_vms}</div>
                    <div class="stat-label">Total VMs</div>
                </div>
                <div class="stat-widget">
                    <div class="stat-value text-success">${data.running_vms}</div>
                    <div class="stat-label">Running</div>
                </div>
            </div>
        `;
    },

    renderCriticalServices(data, element) {
        if (!data.services || data.services.length === 0) {
            element.innerHTML = '<p class="text-muted">No critical services found</p>';
            return;
        }

        const html = data.services.map(service => `
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div>
                    <strong>${service.name}</strong>
                    <div class="text-muted">${service.environment || 'N/A'}</div>
                </div>
                <div>
                    ${this.getHealthBadge(service.health.overall_status)}
                    <span class="text-muted">${service.health.running_instances}/${service.health.total_instances}</span>
                </div>
            </div>
        `).join('');

        element.innerHTML = html;
    },

    renderRecentEvents(data, element) {
        if (!data.events || data.events.length === 0) {
            element.innerHTML = '<p class="text-muted">No recent events</p>';
            return;
        }

        const html = data.events.map(event => `
            <div class="mb-2">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="badge badge-${this.getSeverityClass(event.severity)}">${event.severity}</span>
                    <span class="text-muted" style="font-size: 0.75rem">${MCN.formatDate(event.created_at)}</span>
                </div>
                <div class="mt-1">${event.message}</div>
            </div>
        `).join('<hr style="border-color: var(--border-color); margin: 0.5rem 0;">');

        element.innerHTML = html;
    },

    renderTopCpuConsumers(data, element) {
        if (!data.consumers || data.consumers.length === 0) {
            element.innerHTML = '<p class="text-muted">No running VMs</p>';
            return;
        }

        const html = data.consumers.map(vm => `
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div>
                    <strong>${vm.name || `VM ${vm.vmid}`}</strong>
                    <div class="text-muted">${vm.node}</div>
                </div>
                <div class="text-right">
                    <div><strong>${(vm.cpu * 100).toFixed(1)}%</strong></div>
                    <div class="text-muted">${vm.maxcpu} cores</div>
                </div>
            </div>
        `).join('');

        element.innerHTML = html;
    },

    renderTopMemoryConsumers(data, element) {
        if (!data.consumers || data.consumers.length === 0) {
            element.innerHTML = '<p class="text-muted">No running VMs</p>';
            return;
        }

        const html = data.consumers.map(vm => `
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div>
                    <strong>${vm.name || `VM ${vm.vmid}`}</strong>
                    <div class="text-muted">${vm.node}</div>
                </div>
                <div class="text-right">
                    <div><strong>${vm.mem_percent}%</strong></div>
                    <div class="text-muted">${vm.mem_gb} / ${vm.maxmem_gb} GB</div>
                </div>
            </div>
        `).join('');

        element.innerHTML = html;
    },

    renderMaintenanceWindows(data, element) {
        if (!data.windows || data.windows.length === 0) {
            element.innerHTML = '<p class="text-muted">No maintenance windows scheduled</p>';
            return;
        }

        const html = data.windows.map(window => `
            <div class="mb-2">
                <strong>${window.name}</strong>
                <div class="text-muted">${window.description || 'No description'}</div>
                <div class="text-muted">Next: ${MCN.formatDate(window.next_occurrence)}</div>
            </div>
        `).join('<hr style="border-color: var(--border-color); margin: 0.5rem 0;">');

        element.innerHTML = html;
    },

    renderBackupStatus(data, element) {
        element.innerHTML = `
            <div class="mb-3">
                <div class="d-flex justify-content-between">
                    <span>Success: <strong class="text-success">${data.success_count}</strong></span>
                    <span>Failed: <strong class="text-danger">${data.fail_count}</strong></span>
                </div>
            </div>
            <div class="text-muted">Recent backups:</div>
            ${data.recent_backups.slice(0, 5).map(backup => `
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span>VM ${backup.vmid}</span>
                    <span>${backup.success ? '<span class="badge badge-success">OK</span>' : '<span class="badge badge-danger">Failed</span>'}</span>
                </div>
            `).join('')}
        `;
    },

    /**
     * Helper methods
     */
    getHealthBadge(status) {
        const badges = {
            'healthy': '<span class="badge badge-success">Healthy</span>',
            'degraded': '<span class="badge badge-warning">Degraded</span>',
            'down': '<span class="badge badge-danger">Down</span>',
            'unknown': '<span class="badge badge-secondary">Unknown</span>',
        };

        return badges[status] || badges['unknown'];
    },

    getSeverityClass(severity) {
        const classes = {
            'debug': 'secondary',
            'info': 'info',
            'warning': 'warning',
            'error': 'danger',
            'critical': 'danger',
        };

        return classes[severity] || 'secondary';
    },

    /**
     * Start auto-refresh
     */
    startAutoRefresh() {
        setInterval(() => {
            this.widgets.forEach((widget, index) => {
                this.refreshWidget(index);
            });
        }, this.refreshInterval);
    },
};

// Initialize dashboard when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => Dashboard.init());
} else {
    Dashboard.init();
}
