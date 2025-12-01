<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Events - Matdan Control Nexus</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="container-fluid">
            <div class="header-content">
                <div class="logo">
                    <i class="fas fa-server"></i>
                    Matdan Control Nexus
                </div>

                <nav class="nav">
                    <a href="dashboard.php">
                        <i class="fas fa-th-large"></i> Dashboard
                    </a>
                    <a href="nodes.php">
                        <i class="fas fa-network-wired"></i> Nodes
                    </a>
                    <a href="services.php">
                        <i class="fas fa-cubes"></i> Services
                    </a>
                    <a href="events.php" class="active">
                        <i class="fas fa-history"></i> Events
                    </a>
                </nav>

                <div class="user-menu">
                    <span class="user-info"></span>
                    <button class="btn btn-secondary btn-sm" data-action="logout">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </button>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="main-content">
        <div class="container-fluid">
            <div style="margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center;">
                <h1 style="margin: 0;">Event Log</h1>
                <div>
                    <select id="severity-filter" class="form-control" style="display: inline-block; width: auto;">
                        <option value="">All Severities</option>
                        <option value="debug">Debug</option>
                        <option value="info">Info</option>
                        <option value="warning">Warning</option>
                        <option value="error">Error</option>
                        <option value="critical">Critical</option>
                    </select>
                    <select id="type-filter" class="form-control" style="display: inline-block; width: auto; margin-left: 0.5rem;">
                        <option value="">All Types</option>
                        <option value="user_action">User Actions</option>
                        <option value="policy_action">Policy Actions</option>
                        <option value="backup">Backups</option>
                        <option value="vm_action">VM Actions</option>
                        <option value="api_error">API Errors</option>
                    </select>
                    <button class="btn btn-secondary" onclick="loadEvents()" style="margin-left: 0.5rem;">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
            </div>

            <!-- Events List -->
            <div class="card">
                <div class="card-body">
                    <div id="events-container">
                        <div style="text-align: center;">
                            <div class="spinner"></div> Loading...
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Matdan Control Nexus - Homelab Control Center</p>
        </div>
    </footer>

    <!-- Scripts -->
    <script src="assets/js/app.js"></script>
    <script>
        async function loadEvents() {
            const container = document.getElementById('events-container');
            container.innerHTML = '<div style="text-align: center;"><div class="spinner"></div> Loading...</div>';

            const severityFilter = document.getElementById('severity-filter').value;
            const typeFilter = document.getElementById('type-filter').value;

            let url = 'events.php?limit=100';
            if (severityFilter) url += `&severity=${severityFilter}`;
            if (typeFilter) url += `&type=${typeFilter}`;

            try {
                const response = await MCN.api(url);
                const events = response.events || [];

                if (events.length === 0) {
                    container.innerHTML = '<p style="text-align: center; color: var(--text-muted);">No events found.</p>';
                    return;
                }

                container.innerHTML = events.map(event => `
                    <div class="event-item" style="border-left: 4px solid ${getSeverityColor(event.severity)}; padding: 1rem; margin-bottom: 1rem; background-color: var(--bg-tertiary); border-radius: 4px;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.5rem;">
                            <div>
                                <span class="badge badge-${getSeverityClass(event.severity)}">${event.severity}</span>
                                <span class="badge badge-info" style="margin-left: 0.5rem;">${event.type}</span>
                                ${event.username ? `<span class="text-muted" style="margin-left: 0.5rem;">by ${event.username}</span>` : ''}
                            </div>
                            <div class="text-muted" style="font-size: 0.875rem;">
                                ${MCN.formatDate(event.created_at)}
                            </div>
                        </div>
                        <div style="color: var(--text-primary);">
                            ${event.message}
                        </div>
                        ${event.context_json ? `
                            <details style="margin-top: 0.5rem;">
                                <summary style="cursor: pointer; color: var(--text-secondary);">Context</summary>
                                <pre style="margin-top: 0.5rem; padding: 0.5rem; background-color: var(--bg-primary); border-radius: 4px; overflow-x: auto;">${JSON.stringify(JSON.parse(event.context_json), null, 2)}</pre>
                            </details>
                        ` : ''}
                    </div>
                `).join('');
            } catch (error) {
                console.error('Failed to load events:', error);
                container.innerHTML = '<p style="text-align: center; color: var(--danger);">Failed to load events</p>';
            }
        }

        function getSeverityClass(severity) {
            const classes = {
                'debug': 'secondary',
                'info': 'info',
                'warning': 'warning',
                'error': 'danger',
                'critical': 'danger'
            };
            return classes[severity] || 'secondary';
        }

        function getSeverityColor(severity) {
            const colors = {
                'debug': 'var(--text-muted)',
                'info': 'var(--info)',
                'warning': 'var(--warning)',
                'error': 'var(--danger)',
                'critical': 'var(--danger)'
            };
            return colors[severity] || 'var(--text-muted)';
        }

        // Load events on page load
        document.addEventListener('DOMContentLoaded', loadEvents);

        // Add event listeners for filters
        document.getElementById('severity-filter').addEventListener('change', loadEvents);
        document.getElementById('type-filter').addEventListener('change', loadEvents);
    </script>
</body>
</html>
