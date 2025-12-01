<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proxmox Nodes - Matdan Control Nexus</title>
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
                    <a href="nodes.php" class="active">
                        <i class="fas fa-network-wired"></i> Nodes
                    </a>
                    <a href="services.php">
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
                <h1 style="margin: 0;">Proxmox Nodes</h1>
                <div>
                    <button class="btn btn-primary" onclick="showAddNodeModal()">
                        <i class="fas fa-plus"></i> Add Node
                    </button>
                    <button class="btn btn-secondary" onclick="loadNodes()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
            </div>

            <!-- Nodes List -->
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table" id="nodes-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>API URL</th>
                                    <th>Status</th>
                                    <th>Last Check</th>
                                    <th>VMs</th>
                                    <th>Running</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="nodes-tbody">
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
        async function loadNodes() {
            const tbody = document.getElementById('nodes-tbody');
            tbody.innerHTML = '<tr><td colspan="7" style="text-align: center;"><div class="spinner"></div> Loading...</td></tr>';

            try {
                const response = await MCN.api('nodes.php');
                const nodes = response.nodes || [];

                if (nodes.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="7" style="text-align: center;">No nodes configured yet.</td></tr>';
                    return;
                }

                tbody.innerHTML = nodes.map(node => `
                    <tr>
                        <td><strong>${node.name}</strong></td>
                        <td><code>${node.api_url}</code></td>
                        <td>${MCN.getStatusBadge(node.last_status || 'unknown')}</td>
                        <td>${MCN.formatDate(node.last_check)}</td>
                        <td>${node.statistics?.total_vms || 0}</td>
                        <td>${node.statistics?.running_vms || 0}</td>
                        <td>
                            <button class="btn btn-sm btn-secondary" onclick="testNode(${node.id})" title="Test Connection">
                                <i class="fas fa-plug"></i>
                            </button>
                            <button class="btn btn-sm btn-secondary" onclick="refreshNode(${node.id})" title="Refresh Cache">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                            <button class="btn btn-sm btn-danger" onclick="deleteNode(${node.id}, '${node.name}')" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                `).join('');
            } catch (error) {
                console.error('Failed to load nodes:', error);
                tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; color: var(--danger);">Failed to load nodes</td></tr>';
            }
        }

        async function testNode(nodeId) {
            try {
                MCN.showAlert('Testing connection...', 'info');
                const response = await MCN.api(`nodes.php?id=${nodeId}&action=test`, 'POST');

                if (response.success) {
                    MCN.showAlert(`Node is ${response.status}`, 'success');
                } else {
                    MCN.showAlert('Connection test failed', 'danger');
                }

                loadNodes();
            } catch (error) {
                MCN.showAlert('Connection test failed: ' + error.message, 'danger');
            }
        }

        async function refreshNode(nodeId) {
            try {
                MCN.showAlert('Refreshing cache...', 'info');
                const response = await MCN.api(`nodes.php?id=${nodeId}&action=refresh`, 'POST');
                MCN.showAlert(`Cached ${response.resources_cached} resources`, 'success');
                loadNodes();
            } catch (error) {
                MCN.showAlert('Cache refresh failed: ' + error.message, 'danger');
            }
        }

        async function deleteNode(nodeId, nodeName) {
            if (!MCN.confirm(`Are you sure you want to delete node "${nodeName}"?`)) {
                return;
            }

            try {
                await MCN.api(`nodes.php?id=${nodeId}`, 'DELETE');
                MCN.showAlert('Node deleted successfully', 'success');
                loadNodes();
            } catch (error) {
                MCN.showAlert('Failed to delete node: ' + error.message, 'danger');
            }
        }

        function showAddNodeModal() {
            MCN.showAlert('Add Node modal not implemented yet. Please use the API or database directly.', 'info');
        }

        // Load nodes on page load
        document.addEventListener('DOMContentLoaded', loadNodes);
    </script>
</body>
</html>
