# Matdan Control Nexus

**Homelab Control Center for Proxmox VE**

Ein umfassendes Control Center zur Verwaltung von Proxmox VE Clustern, VMs und LXCs mit integrierter CMDB Light, Policy Engine, Backup Management und Integration zu verschiedenen Homelab-Diensten.

## Features

- ✅ **Multi-Node Proxmox VE Management** - Verwaltung mehrerer Proxmox Nodes/Cluster
- ✅ **CMDB Light** - Service-Katalog mit Instanzen und Abhängigkeiten
- ✅ **Policy Engine** - Automatisierte Start/Stop und Ressourcen-Anpassungen
- ✅ **Backup Management** - Orchestrierung von Backups und Snapshots
- ✅ **Dashboard** - Anpassbares Widget-Dashboard mit Masonry Layout
- ✅ **Event Logging** - Umfassendes Audit-Log aller Aktionen
- ✅ **Rollenbasierte Zugriffskontrolle** - Admin, Operator, Viewer Rollen
- ✅ **Integration Ready** - Vorbereitete Integration für NPM, AdGuard, MeshCentral, etc.

## Technologie Stack

- **Backend:** PHP 8 (plain, ohne Framework)
- **Frontend:** HTML5, CSS3, Vanilla JavaScript
- **Datenbank:** MariaDB / MySQL
- **Webserver:** nginx oder Apache
- **CDN Libraries:** FontAwesome, Chart.js

## Anforderungen

### System

- Debian 13 LXC (oder kompatibles Linux-System)
- PHP 8.0 oder höher
- MariaDB 10.5+ oder MySQL 8.0+
- nginx oder Apache mit PHP-FPM
- Zugriff auf Proxmox VE API (API-Token erforderlich)

### PHP Erweiterungen

```bash
sudo apt install php8.2-fpm php8.2-mysql php8.2-curl php8.2-json php8.2-mbstring
```

## Installation

### 1. Projekt herunterladen

```bash
cd /var/www
git clone https://github.com/your-repo/Matdan-Homelab-Control-Nexus.git
cd Matdan-Homelab-Control-Nexus
```

### 2. Datenbank erstellen

```bash
# MySQL/MariaDB einloggen
mysql -u root -p

# Datenbank und Benutzer erstellen
CREATE DATABASE matdan_control_nexus CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'mcn_user'@'localhost' IDENTIFIED BY 'your_secure_password';
GRANT ALL PRIVILEGES ON matdan_control_nexus.* TO 'mcn_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;

# Schema importieren
mysql -u mcn_user -p matdan_control_nexus < sql/schema.sql
```

### 3. Konfiguration anpassen

```bash
cp config/config.php config/config.php.example
nano config/config.php
```

Wichtige Einstellungen:

```php
'database' => [
    'host' => 'localhost',
    'database' => 'matdan_control_nexus',
    'username' => 'mcn_user',
    'password' => 'your_secure_password',
],

'app' => [
    'timezone' => 'Europe/Berlin',
    'debug' => false, // In Produktion auf false setzen!
],
```

### 4. Webserver konfigurieren

#### nginx Beispielkonfiguration

```nginx
server {
    listen 80;
    server_name matdan-control-nexus.local;
    root /var/www/Matdan-Homelab-Control-Nexus/public;

    index index.php index.html;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;

    # Logging
    access_log /var/log/nginx/mcn_access.log;
    error_log /var/log/nginx/mcn_error.log;

    # Main location
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP-FPM
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Deny access to sensitive files
    location ~ /\. {
        deny all;
    }

    location ~ ^/(config|src|sql|cron)/ {
        deny all;
    }
}
```

#### Apache Beispielkonfiguration

```apache
<VirtualHost *:80>
    ServerName matdan-control-nexus.local
    DocumentRoot /var/www/Matdan-Homelab-Control-Nexus/public

    <Directory /var/www/Matdan-Homelab-Control-Nexus/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted

        <IfModule mod_rewrite.c>
            RewriteEngine On
            RewriteCond %{REQUEST_FILENAME} !-f
            RewriteCond %{REQUEST_FILENAME} !-d
            RewriteRule ^ index.php [L]
        </IfModule>
    </Directory>

    # Deny access to sensitive directories
    <DirectoryMatch "^/.*/\.(git|svn)/">
        Require all denied
    </DirectoryMatch>

    <DirectoryMatch "^/.*/(config|src|sql|cron)/">
        Require all denied
    </DirectoryMatch>

    ErrorLog ${APACHE_LOG_DIR}/mcn_error.log
    CustomLog ${APACHE_LOG_DIR}/mcn_access.log combined
</VirtualHost>
```

### 5. Berechtigungen setzen

```bash
# Webserver-Benutzer (www-data) Zugriff geben
sudo chown -R www-data:www-data /var/www/Matdan-Homelab-Control-Nexus
sudo chmod -R 755 /var/www/Matdan-Homelab-Control-Nexus

# Log-Verzeichnis erstellen
sudo mkdir -p /var/log/matdan-control-nexus
sudo chown www-data:www-data /var/log/matdan-control-nexus
```

### 6. Cron Jobs einrichten

```bash
# Crontab für www-data Benutzer bearbeiten
sudo crontab -u www-data -e

# Folgende Zeilen hinzufügen:

# Health Check - alle 5 Minuten
*/5 * * * * /usr/bin/php /var/www/Matdan-Homelab-Control-Nexus/cron/health_check.php >> /var/log/matdan-control-nexus/cron.log 2>&1

# Policy Runner - jede Minute
* * * * * /usr/bin/php /var/www/Matdan-Homelab-Control-Nexus/cron/policies_runner.php >> /var/log/matdan-control-nexus/cron.log 2>&1

# Backup Runner - täglich um 2 Uhr
0 2 * * * /usr/bin/php /var/www/Matdan-Homelab-Control-Nexus/cron/backups_runner.php >> /var/log/matdan-control-nexus/cron.log 2>&1
```

## Erste Schritte

### 1. Login

Standard-Zugangsdaten:
- **Username:** `admin`
- **Password:** `admin123`

⚠️ **WICHTIG:** Ändern Sie das Standard-Passwort sofort nach dem ersten Login!

### 2. Proxmox Node hinzufügen

1. Gehen Sie zu "Nodes"
2. Klicken Sie auf "Add Node"
3. Füllen Sie folgende Felder aus:
   - **Name:** Beschreibender Name für den Node
   - **API URL:** `https://your-proxmox.local:8006/api2/json`
   - **Token ID:** `root@pam!api-token`
   - **Token Secret:** Ihr API Token Secret
   - **Verify SSL:** Deaktivieren bei Self-Signed Zertifikaten

#### Proxmox API Token erstellen

```bash
# Auf dem Proxmox Node:
pveum user token add root@pam api-token --privsep 0

# Output:
# ┌──────────────┬──────────────────────────────────────┐
# │ key          │ value                                │
# ╞══════════════╪══════════════════════════════════════╡
# │ full-tokenid │ root@pam!api-token                   │
# ├──────────────┼──────────────────────────────────────┤
# │ info         │ {"privsep":"0"}                      │
# ├──────────────┼──────────────────────────────────────┤
# │ value        │ xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx │
# └──────────────┴──────────────────────────────────────┘
```

### 3. Services erstellen (CMDB Light)

1. Gehen Sie zu "Services"
2. Erstellen Sie einen neuen Service:
   - **Name:** z.B. "Home Assistant"
   - **Environment:** production
   - **Criticality:** critical
   - **Tags:** ["home-automation", "docker"]
3. Fügen Sie Instanzen hinzu:
   - Wählen Sie Node und VMID
   - Verknüpfen Sie VMs/LXCs mit dem Service

### 4. Policies erstellen

#### Beispiel: Zeitbasierte Policy

```json
{
  "schedule": {
    "days_of_week": [1, 2, 3, 4, 5],
    "time": "18:00"
  },
  "action": "start"
}
```

#### Beispiel: Load-basierte Policy

```json
{
  "cpu_high_threshold": 80,
  "cpu_low_threshold": 20,
  "min_cpu_cores": 2,
  "max_cpu_cores": 8
}
```

### 5. Backup Policies erstellen

1. Gehen Sie zu "Backups"
2. Erstellen Sie eine neue Backup Policy:
   - **Name:** "Daily VM Backups"
   - **Scope:** Service oder Tag
   - **Schedule:** `0 2 * * *` (täglich um 2 Uhr)
   - **Retention:** 7 (letzte 7 Backups behalten)

## Projektstruktur

```
Matdan-Homelab-Control-Nexus/
├── api/                          # REST API Endpoints
│   ├── init.php                  # Bootstrap für API
│   ├── auth.php                  # Authentifizierung
│   ├── nodes.php                 # Node Management
│   ├── pve_overview.php          # Proxmox Übersicht
│   ├── pve_vms.php               # VM/LXC Operations
│   ├── services.php              # CMDB Services
│   ├── events.php                # Event Log
│   └── dashboard.php             # Dashboard API
├── config/
│   └── config.php                # Hauptkonfiguration
├── cron/                         # Cron Scripts
│   ├── health_check.php          # Health Check (*/5)
│   ├── policies_runner.php       # Policy Execution (*/1)
│   └── backups_runner.php        # Backup Execution (täglich)
├── public/                       # Web Root
│   ├── index.php                 # Entry Point
│   ├── login.php                 # Login Seite
│   ├── dashboard.php             # Hauptdashboard
│   └── assets/
│       ├── css/
│       │   └── style.css         # Haupt-Stylesheet
│       └── js/
│           ├── app.js            # Basis JavaScript
│           └── dashboard.js      # Dashboard Logik
├── src/                          # PHP Klassen
│   ├── Database.php              # PDO Wrapper
│   ├── Auth.php                  # Authentifizierung
│   ├── ProxmoxApiClient.php      # Proxmox API Client
│   ├── NodesRepository.php       # Node Verwaltung
│   ├── ServicesRepository.php    # CMDB Repository
│   ├── PoliciesEngine.php        # Policy Logik
│   ├── BackupManager.php         # Backup Logik
│   ├── EventLogger.php           # Event Logging
│   ├── DashboardLayout.php       # Dashboard Layout
│   └── Integrations/             # Integration Clients
└── sql/
    └── schema.sql                # Datenbank Schema
```

## API Dokumentation

### Authentifizierung

```bash
# Login
curl -X POST http://your-host/api/auth.php?action=login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"admin123"}'

# Session Check
curl http://your-host/api/auth.php?action=check \
  --cookie "MCN_SESSION=..."

# Logout
curl -X POST http://your-host/api/auth.php?action=logout \
  --cookie "MCN_SESSION=..."
```

### Nodes Management

```bash
# Liste aller Nodes
curl http://your-host/api/nodes.php

# Node hinzufügen
curl -X POST http://your-host/api/nodes.php \
  -H "Content-Type: application/json" \
  -d '{
    "name": "pve-node-01",
    "api_url": "https://pve01.local:8006/api2/json",
    "token_id": "root@pam!api-token",
    "token_secret": "secret",
    "verify_ssl": 0
  }'

# Verbindung testen
curl -X POST http://your-host/api/nodes.php?id=1&action=test

# Cache aktualisieren
curl -X POST http://your-host/api/nodes.php?id=1&action=refresh
```

### VM/LXC Operations

```bash
# VM starten
curl -X POST http://your-host/api/pve_vms.php?action=start \
  -H "Content-Type: application/json" \
  -d '{
    "node_id": 1,
    "vmid": 100,
    "type": "qemu"
  }'

# VM stoppen
curl -X POST http://your-host/api/pve_vms.php?action=stop \
  -H "Content-Type: application/json" \
  -d '{
    "node_id": 1,
    "vmid": 100,
    "type": "qemu"
  }'
```

## Sicherheit

### Empfehlungen

1. **Passwörter ändern:** Ändern Sie das Standard-Admin-Passwort sofort
2. **HTTPS verwenden:** Konfigurieren Sie SSL/TLS für die Weboberfläche
3. **Firewall:** Beschränken Sie den Zugriff auf vertrauenswürdige IPs
4. **Updates:** Halten Sie PHP und alle Pakete aktuell
5. **Backups:** Erstellen Sie regelmäßige Backups der Datenbank

### IP Whitelist

In `config/config.php`:

```php
'security' => [
    'ip_whitelist' => ['192.168.1.0/24', '10.0.0.0/8'],
],
```

## Troubleshooting

### Problem: "Database connection failed"

**Lösung:**
- Überprüfen Sie die Datenbankzugangsdaten in `config/config.php`
- Stellen Sie sicher, dass MySQL/MariaDB läuft: `systemctl status mariadb`
- Testen Sie die Verbindung: `mysql -u mcn_user -p matdan_control_nexus`

### Problem: "Proxmox API connection failed"

**Lösung:**
- Überprüfen Sie die API URL (sollte mit `/api2/json` enden)
- Stellen Sie sicher, dass der API Token gültig ist
- Testen Sie die Verbindung: `curl -k https://pve.local:8006/api2/json/version`
- Deaktivieren Sie SSL-Verifizierung bei Self-Signed Zertifikaten

### Problem: Cron Jobs laufen nicht

**Lösung:**
- Überprüfen Sie die Cron-Logs: `tail -f /var/log/matdan-control-nexus/cron.log`
- Stellen Sie sicher, dass die Scripts ausführbar sind: `chmod +x cron/*.php`
- Testen Sie die Scripts manuell: `php cron/health_check.php`

### Problem: Keine Widgets im Dashboard

**Lösung:**
- Öffnen Sie die Browser-Console (F12) und prüfen Sie auf JavaScript-Fehler
- Stellen Sie sicher, dass die API Endpoints erreichbar sind
- Löschen Sie den Browser-Cache und laden Sie die Seite neu

## Entwicklung

### Neue API Endpoints hinzufügen

1. Erstellen Sie eine neue Datei in `api/`
2. Inkludieren Sie `init.php` am Anfang
3. Implementieren Sie die gewünschte Logik
4. Verwenden Sie `sendJson()` für Responses

Beispiel:

```php
<?php
require_once __DIR__ . '/init.php';
Auth::require('operator');

$data = getJsonInput();
// Ihre Logik hier...

sendJson(['success' => true, 'data' => $result]);
```

### Neue Widgets hinzufügen

1. Fügen Sie den Widget-Typ in `api/dashboard.php` hinzu
2. Implementieren Sie die Render-Funktion in `public/assets/js/dashboard.js`
3. Fügen Sie den Widget-Typ zur Liste in `config/config.php` hinzu

## Support & Kontakt

Bei Fragen oder Problemen:
- GitHub Issues: https://github.com/your-repo/Matdan-Homelab-Control-Nexus/issues
- Email: support@your-domain.com

## Lizenz

Dieses Projekt ist unter der MIT-Lizenz lizenziert.

## Credits

Entwickelt von Matdan für die Homelab-Community.

Verwendet folgende Open-Source Projekte:
- Font Awesome (Icons)
- Chart.js (Diagramme)
- Proxmox VE API

---

## 🚀 Enterprise Features (Version 2.0)

Version 2.0 erweitert Matdan Control Nexus um umfassende Enterprise-Features für professionelle Homelab- und kleine Rechenzentrumsumgebungen.

### ✅ Implementierte Enterprise Features

#### 1. **API Key Management System**
- Sichere API-Schlüssel-Generierung mit bcrypt-Hashing
- Granulare Berechtigungsverwaltung pro API-Schlüssel
- Nutzungsverfolgung und Statistiken
- Ablaufdatum-Management
- **API Endpoint:** `api/api_keys.php`
- **Class:** `src/ApiKeyManager.php`

```php
// API Key Authentifizierung
// Header: X-API-Key: mcn_[your-key-here]
```

#### 2. **Cost Tracking & Calculator**
- Ressourcenkosten-Tracking (CPU, RAM, Storage)
- Kosten-Berechnung nach Team/Service/Zeitraum
- Automatische Cost-Snapshots
- Kosten-Prognosen und Trend-Analysen
- Detaillierte Breakdown-Reports
- **API Endpoint:** `api/costs.php`
- **Class:** `src/CostCalculator.php`

**Features:**
- Stündliche CPU-Core-Kosten
- GB/Stunde Memory-Kosten
- GB/Monat Storage-Kosten
- Automatische Report-Generierung
- Kosten-Alarme bei Schwellwerten

#### 3. **VM Provisioning Engine**
- Template-basierte VM-Bereitstellung
- Automatische Node-Auswahl nach Ressourcen
- Team-Quota-Validierung vor Provisionierung
- Cloud-init Integration
- IPAM-Integration für automatische IP-Zuweisung
- Job-Tracking mit Status-Updates
- **API Endpoint:** `api/provisioning.php`
- **Class:** `src/ProvisioningEngine.php`

**Features:**
- Custom VM-Konfigurationen
- Template-Verwaltung
- Automated Node Selection
- Resource Validation
- Rollback bei Fehlern

#### 4. **Monitoring & Alert System**
- Vollständige Prometheus-Integration
- Alertmanager Webhook-Verarbeitung
- Custom Alert-Regeln mit Aktionen
- Alert-Historie und Acknowledgment
- Automatische Reaktionen (VM-Neustart, Skalierung, Webhooks)
- **API Endpoint:** `api/monitoring.php`
- **Classes:** `src/AlertManager.php`, `src/Integrations/PrometheusClient.php`

**Alert Actions:**
- VM restart
- Policy execution
- Scale-up triggers
- External webhooks
- Notification routing

#### 5. **Multi-Tenancy System (Teams & Quotas)**
- Team-Management mit hierarchischen Rollen
- Ressourcen-Quotas (VMs, CPU, RAM, Storage)
- Team-Mitglieder-Verwaltung
- Ressourcen-Zuordnung zu Teams
- Quota-Verletzungs-Tracking
- Nutzungs- und Auslastungs-Reports
- **API Endpoint:** `api/teams.php`
- **Class:** `src/TeamManager.php`

**Team-Rollen:**
- Owner (volle Kontrolle)
- Admin (Management)
- Member (Nutzung)
- Viewer (nur Lesezugriff)

#### 6. **Notification Channels**
Vollständige Multi-Channel-Benachrichtigungen:

- **Discord** - Rich Embeds mit Severity-Farben
  - Class: `src/Notifications/Channels/DiscordChannel.php`
- **Telegram** - Markdown-formatierte Nachrichten
  - Class: `src/Notifications/Channels/TelegramChannel.php`
- **Gotify** - Self-hosted Notifications
  - Class: `src/Notifications/Channels/GotifyChannel.php`
- **Email** (bereits in v1.0)
- **Slack** (bereits in v1.0)

Alle Channels unterstützen Severity-Levels und Context-Fields.

#### 7. **Network Management (IPAM)**
- Vollständiges IP Address Management
- CIDR-basierte Netzwerk-Verwaltung
- Automatische IP-Allokation
- Manuelle IP-Reservierung
- Network Scanning (Ping Sweep)
- Nutzungs-Tracking und Statistiken
- **API Endpoint:** `api/networks.php`
- **Class:** `src/NetworkManager.php`

**Features:**
- Subnet-Verwaltung
- Gateway-Konfiguration
- VLAN-Support
- DNS-Server-Verwaltung
- IP-Release Management

#### 8. **Storage Management**
- Storage-Pool-Registrierung und Tracking
- Proxmox Storage-Synchronisation
- Nutzungs-Monitoring
- Low-Space-Detection
- Health-Status-Überwachung
- Best-Pool-Selection für Allokationen
- **API Endpoint:** `api/storage.php`
- **Class:** `src/StorageManager.php`

**Features:**
- Multi-Node Storage-Übersicht
- Kapazitäts-Planung
- Storage-Trends
- Automatische Sync von Proxmox
- Content-Type-Management

#### 9. **Remote Access Integrations**
Vollständige Integration-Clients für Remote-Access-Lösungen:

- **MeshCentral** - Device Management & Remote Control
  - Class: `src/Integrations/MeshCentralClient.php`
  - Features: Device-Liste, Power-Management, Gruppen

- **RustDesk** - Self-hosted Remote Desktop
  - Class: `src/Integrations/RustDeskClient.php`
  - Features: Peer-Management, Connection-Logs, Tokens

- **Wireguard** - VPN Management
  - Class: `src/Integrations/WireguardClient.php`
  - Features: Interface-Status, Peer-Config, Key-Generation

### 📊 API-Übersicht (Alle Endpoints)

#### Core APIs
- `api/auth.php` - Authentifizierung & Sessions
- `api/nodes.php` - Proxmox Node Management
- `api/pve_overview.php` - PVE Cluster Overview
- `api/pve_vms.php` - VM/LXC Management
- `api/services.php` - CMDB Service Catalog
- `api/events.php` - Event Log & Audit Trail
- `api/dashboard.php` - Dashboard Widgets

#### Enterprise APIs (v2.0)
- `api/api_keys.php` - API Key Management ✨ NEW
- `api/costs.php` - Cost Tracking & Reports ✨ NEW
- `api/provisioning.php` - VM Provisioning ✨ NEW
- `api/monitoring.php` - Alerts & Prometheus ✨ NEW
- `api/teams.php` - Multi-Tenancy & Quotas ✨ NEW
- `api/networks.php` - IPAM & Network Management ✨ NEW
- `api/storage.php` - Storage Pool Management ✨ NEW

### 🔧 Erweiterte Konfiguration (v2.0)

#### Cost Tracking aktivieren

```php
// config/config.php
'cost_tracking' => [
    'enabled' => true,
    'cpu_core_hourly' => 0.02,      // € pro CPU-Core/Stunde
    'memory_gb_hourly' => 0.01,      // € pro GB RAM/Stunde
    'storage_gb_monthly' => 0.10,    // € pro GB Storage/Monat
],
```

#### Teams & Quotas konfigurieren

```php
'teams' => [
    'enabled' => true,
    'default_quotas' => [
        'max_vms' => 10,
        'max_cpu_cores' => 20,
        'max_memory_gb' => 64,
        'max_storage_gb' => 500,
    ],
],
```

#### Monitoring Integration

```php
'monitoring' => [
    'prometheus_url' => 'http://prometheus:9090',
    'alertmanager_url' => 'http://alertmanager:9093',
    'scrape_interval' => 300, // Sekunden
],
```

#### Notification Channels

```php
'notifications' => [
    'discord' => [
        'webhook_url' => 'https://discord.com/api/webhooks/...',
    ],
    'telegram' => [
        'bot_token' => 'your_bot_token',
        'chat_id' => 'your_chat_id',
    ],
    'gotify' => [
        'server_url' => 'https://gotify.yourdomain.com',
        'app_token' => 'your_app_token',
    ],
],
```

### 📈 Neue Cron Jobs (v2.0)

```bash
# Cost Snapshot - stündlich
0 * * * * /usr/bin/php /var/www/Matdan-Homelab-Control-Nexus/cron/cost_snapshot.php

# Quota Check - alle 15 Minuten
*/15 * * * * /usr/bin/php /var/www/Matdan-Homelab-Control-Nexus/cron/quota_check.php

# Storage Sync - alle 30 Minuten
*/30 * * * * /usr/bin/php /var/www/Matdan-Homelab-Control-Nexus/cron/storage_sync.php

# Monitoring Sync - alle 5 Minuten
*/5 * * * * /usr/bin/php /var/www/Matdan-Homelab-Control-Nexus/cron/monitoring_sync.php
```

### 🔐 Sicherheits-Features

- **API Key Authentication** - Sichere API-Zugriffe
- **Permission-Based Access Control** - Granulare Berechtigungen
- **bcrypt Password Hashing** - Sichere Passwort-Speicherung
- **SQL Injection Protection** - Prepared Statements überall
- **XSS Protection** - Input Sanitization
- **CSRF Protection** - Token-basierte Absicherung
- **Audit Logging** - Vollständige Event-Historie

### 📦 Datenbank-Migration auf v2.0

```bash
# Schema-Erweiterungen importieren
mysql -u mcn_user -p matdan_control_nexus < sql/schema_extensions.sql
```

Dies erstellt 25+ neue Tabellen für alle Enterprise-Features.

### 🎯 Use Cases

#### Beispiel: VM mit Budget-Tracking provisionieren

```bash
# 1. API Key erstellen
curl -X POST http://your-server/api/api_keys.php?action=create \
  -H "Cookie: PHPSESSID=..." \
  -d '{"name":"automation","permissions":["provisioning.create"]}'

# 2. VM provisionieren
curl -X POST http://your-server/api/provisioning.php?action=provision_from_template \
  -H "X-API-Key: mcn_..." \
  -d '{"template_id":1,"config":{"name":"web-01","cpu_cores":4,"memory_gb":8},"team_id":1}'

# 3. Kosten abrufen
curl http://your-server/api/costs.php?action=calculate \
  -H "X-API-Key: mcn_..." \
  -d '{"start_date":"2025-01-01","end_date":"2025-01-31","team_id":1}'
```

#### Beispiel: Team mit Quotas erstellen

```bash
curl -X POST http://your-server/api/teams.php?action=create \
  -H "Cookie: PHPSESSID=..." \
  -d '{
    "name":"Development Team",
    "max_vms":20,
    "max_cpu_cores":40,
    "max_memory_gb":128,
    "max_storage_gb":1000
  }'
```

---

**Version:** 2.0.0 🚀
**Letzte Aktualisierung:** Dezember 2025
**Neue Features:** 9 Enterprise-Systeme, 7 neue APIs, 25+ Datenbank-Tabellen, 3000+ Zeilen Code
