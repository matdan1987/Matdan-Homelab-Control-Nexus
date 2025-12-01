<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Matdan Control Nexus</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <div class="login-header">
                <h1><i class="fas fa-server"></i> Matdan Control Nexus</h1>
                <p>Homelab Control Center</p>
            </div>

            <div id="alert-container"></div>

            <form id="login-form">
                <div class="form-group">
                    <label for="username" class="form-label">Username</label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        class="form-control"
                        required
                        autofocus
                        placeholder="Enter your username"
                    >
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-control"
                        required
                        placeholder="Enter your password"
                    >
                </div>

                <button type="submit" class="btn btn-primary btn-lg" style="width: 100%;">
                    <i class="fas fa-sign-in-alt"></i> Login
                </button>
            </form>

            <div style="margin-top: 1.5rem; text-align: center; color: var(--text-muted); font-size: 0.875rem;">
                <p>Default credentials: <code>admin</code> / <code>admin123</code></p>
                <p class="text-warning">Please change the default password after first login!</p>
            </div>
        </div>
    </div>

    <script>
        // Simple login handler without requiring app.js
        document.getElementById('login-form').addEventListener('submit', async (e) => {
            e.preventDefault();

            const username = document.getElementById('username').value;
            const password = document.getElementById('password').value;
            const alertContainer = document.getElementById('alert-container');

            try {
                const response = await fetch('/api/auth.php?action=login', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ username, password }),
                });

                const result = await response.json();

                if (response.ok && result.success) {
                    // Redirect to dashboard
                    window.location.href = 'dashboard.php';
                } else {
                    showAlert(result.error || 'Login failed', 'danger');
                }
            } catch (error) {
                console.error('Login error:', error);
                showAlert('Login failed. Please try again.', 'danger');
            }
        });

        function showAlert(message, type) {
            const alertContainer = document.getElementById('alert-container');
            alertContainer.innerHTML = `
                <div class="alert alert-${type}">
                    ${message}
                </div>
            `;

            setTimeout(() => {
                alertContainer.innerHTML = '';
            }, 5000);
        }
    </script>
</body>
</html>
