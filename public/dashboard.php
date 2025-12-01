<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Matdan Control Nexus</title>
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
                    <a href="dashboard.php" class="active">
                        <i class="fas fa-th-large"></i> Dashboard
                    </a>
                    <a href="nodes.php">
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
                <h1 style="margin: 0;">Dashboard</h1>
                <div>
                    <button class="btn btn-secondary btn-sm" onclick="Dashboard.init()">
                        <i class="fas fa-sync-alt"></i> Refresh All
                    </button>
                </div>
            </div>

            <!-- Dashboard Grid -->
            <div class="dashboard-grid" id="dashboard-grid">
                <!-- Widgets will be loaded here dynamically -->
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
    <script src="assets/js/dashboard.js"></script>
</body>
</html>
