<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Services - Matdan Control Nexus</title>
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
                    <a href="services.php" class="active">
                        <i class="fas fa-cubes"></i> Services
                    </a>
                    <a href="events.php">
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
                <h1 style="margin: 0;">Services (CMDB Light)</h1>
                <div>
                    <button class="btn btn-primary" onclick="showAddServiceModal()">
                        <i class="fas fa-plus"></i> Add Service
                    </button>
                    <button class="btn btn-secondary" onclick="loadServices()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
            </div>

            <!-- Services List -->
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table" id="services-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Environment</th>
                                    <th>Criticality</th>
                                    <th>Health</th>
                                    <th>Instances</th>
                                    <th>Tags</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="services-tbody">
                                <tr>
                                    <td colspan="7" style="text-align: center;">
                                        <div class="spinner"></div> Loading...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
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
        async function loadServices() {
            const tbody = document.getElementById('services-tbody');
            tbody.innerHTML = '<tr><td colspan="7" style="text-align: center;"><div class="spinner"></div> Loading...</td></tr>';

            try {
                const response = await MCN.api('services.php?with_health=1');
                const services = response.services || [];

                if (services.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="7" style="text-align: center;">No services defined yet.</td></tr>';
                    return;
                }

                tbody.innerHTML = services.map(service => `
                    <tr>
                        <td>
                            <strong>${service.name}</strong>
                            ${service.description ? `<br><small class="text-muted">${service.description}</small>` : ''}
                        </td>
                        <td><span class="badge badge-info">${service.environment || 'N/A'}</span></td>
                        <td><span class="badge badge-${getCriticalityClass(service.criticality)}">${service.criticality}</span></td>
                        <td>${getHealthBadge(service.health)}</td>
                        <td>${service.health.running_instances} / ${service.health.total_instances}</td>
                        <td>${(service.tags || []).map(tag => `<span class="badge badge-secondary">${tag}</span>`).join(' ')}</td>
                        <td>
                            <button class="btn btn-sm btn-secondary" onclick="viewService(${service.id})" title="View Details">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button class="btn btn-sm btn-danger" onclick="deleteService(${service.id}, '${service.name}')" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                `).join('');
            } catch (error) {
                console.error('Failed to load services:', error);
                tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; color: var(--danger);">Failed to load services</td></tr>';
            }
        }

        function getCriticalityClass(criticality) {
            const classes = {
                'low': 'secondary',
                'medium': 'info',
                'high': 'warning',
                'critical': 'danger'
            };
            return classes[criticality] || 'secondary';
        }

        function getHealthBadge(health) {
            const badges = {
                'healthy': '<span class="badge badge-success">Healthy</span>',
                'degraded': '<span class="badge badge-warning">Degraded</span>',
                'down': '<span class="badge badge-danger">Down</span>',
                'unknown': '<span class="badge badge-secondary">Unknown</span>'
            };
            return badges[health.overall_status] || badges['unknown'];
        }

        async function deleteService(serviceId, serviceName) {
            if (!MCN.confirm(`Are you sure you want to delete service "${serviceName}"?`)) {
                return;
            }

            try {
                await MCN.api(`services.php?id=${serviceId}`, 'DELETE');
                MCN.showAlert('Service deleted successfully', 'success');
                loadServices();
            } catch (error) {
                MCN.showAlert('Failed to delete service: ' + error.message, 'danger');
            }
        }

        function viewService(serviceId) {
            MCN.showAlert('Service details view not implemented yet. Please use the API.', 'info');
        }

        function showAddServiceModal() {
            MCN.showAlert('Add Service modal not implemented yet. Please use the API or database directly.', 'info');
        }

        // Load services on page load
        document.addEventListener('DOMContentLoaded', loadServices);
    </script>
</body>
</html>
