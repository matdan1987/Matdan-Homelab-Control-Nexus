# Matdan Control Nexus - Version 2.0 Features

## 🚀 Erweiterte Feature-Set Dokumentation

Dieses Dokument beschreibt alle erweiterten Features von Version 2.0 und gibt Implementierungsbeispiele.

---

## 📊 Implementierungsstatus

| Feature | Status | Priorität | Komplexität |
|---------|--------|-----------|-------------|
| ✅ Integration Clients | **Implementiert** | Hoch | Mittel |
| ✅ Notification System | **Implementiert** | Hoch | Mittel |
| ✅ Erweiterte DB Schema | **Komplett** | Hoch | Niedrig |
| 🔄 Dashboard Widgets v2 | In Arbeit | Hoch | Niedrig |
| 📋 API Keys System | Geplant | Hoch | Mittel |
| 📋 Monitoring Integration | Geplant | Hoch | Hoch |
| 📋 PBS Integration | Geplant | Mittel | Mittel |
| 📋 VM Provisioning | Geplant | Mittel | Hoch |
| 📋 Cost Tracking | Geplant | Mittel | Mittel |
| 📋 Multi-Tenancy | Geplant | Niedrig | Hoch |
| 📋 Compliance | Geplant | Niedrig | Mittel |
| 📋 Reporting Engine | Geplant | Mittel | Mittel |
| 📋 PWA | Geplant | Niedrig | Hoch |
| 📋 Workflow Engine | Geplant | Niedrig | Sehr Hoch |

---

## 🔌 1. Integration Clients

### 1.1 Nginx Proxy Manager

**Implementiert:** ✅ `src/Integrations/NginxProxyManagerClient.php`

**Features:**
- Proxy Hosts auslesen
- Automatische Zuordnung zu VMs/Services
- SSL Zertifikate verwalten
- Custom Locations

**Verwendung:**
```php
// Initialize client
$npm = new NginxProxyManagerClient(
    'http://npm.local/api',
    'admin@example.com',
    'password'
);

// Get all proxy hosts
$hosts = $npm->getProxyHosts();

// Create new proxy host
$npm->createProxyHost([
    'domain_names' => ['app.homelab.local'],
    'forward_scheme' => 'http',
    'forward_host' => '192.168.1.100',
    'forward_port' => 80,
]);

// Find hosts by IP
$vmHosts = $npm->findHostsByIP('192.168.1.100');
```

**API Endpoint:** `api/integrations.php?action=npm_sync`

**Dashboard Widget:**
```javascript
// Shows proxy hosts mapped to VMs
{
  type: 'npm_proxy_hosts',
  position: 5,
  size: 'medium'
}
```

### 1.2 AdGuard Home

**Implementiert:** ✅ `src/Integrations/AdGuardHomeClient.php`

**Features:**
- DNS Rewrites verwalten
- Automatische DNS Einträge für Services
- Query Statistiken
- Top Domains (allowed & blocked)

**Verwendung:**
```php
// Initialize client
$adguard = new AdGuardHomeClient(
    'http://adguard.local',
    'admin',
    'password'
);

// Add DNS rewrite
$adguard->addRewrite('service.homelab.local', '192.168.1.100');

// Get statistics
$stats = $adguard->getStats();
$topDomains = $adguard->getTopQueriedDomains();

// Auto-create DNS for service
$adguard->createDNSRecordsForService(1, 'myservice.local');
```

**Automatische Sync:**
- Beim Hinzufügen eines Service kann automatisch ein DNS Eintrag erstellt werden
- Integration Mapping in `integration_mappings` Tabelle

### 1.3 MeshCentral / RustDesk

**Geplant:** 📋 `src/Integrations/MeshCentralClient.php`

**Features (wenn implementiert):**
```php
class MeshCentralClient {
    // Get all devices
    public function getDevices(): array;

    // Get device status
    public function getDeviceStatus(string $deviceId): array;

    // Generate remote access link
    public function getRemoteAccessLink(string $deviceId): string;

    // Map devices to VMs
    public static function mapDevicesToVMs(int $integrationId): array;
}
```

**UI Integration:**
In VM Details würde dann angezeigt:
```
Remote Access: ✅ Available
[Open MeshCentral] [Open RustDesk]
```

---

## 🔔 2. Notification System

### 2.1 Kern-System

**Implementiert:** ✅ `src/Notifications/NotificationManager.php`

**Features:**
- Multi-Channel Support (Email, Slack, Discord, Telegram, Gotify)
- Severity-basiertes Routing
- Cooldown für wiederholte Benachrichtigungen
- Notification Rules

**Verwendung:**
```php
// Initialize (in bootstrap)
NotificationManager::init($config['notifications']);

// Send notification
NotificationManager::send(
    'VM Down',
    'VM 100 on node-01 is offline',
    'critical',
    ['vmid' => 100, 'node' => 'node-01']
);

// Convenience methods
NotificationManager::alert('vm_down', 'VM 100 is down');
NotificationManager::critical('Critical system failure!');
NotificationManager::success('Backup completed successfully');
```

### 2.2 Channels

**Email:** ✅ Implementiert
```sql
INSERT INTO notification_channels (type, name, config_json, enabled) VALUES
('email', 'Admin Email', '{"to": "admin@example.com", "from": "mcn@homelab.local"}', 1);
```

**Slack:** ✅ Implementiert
```sql
INSERT INTO notification_channels (type, name, config_json, enabled) VALUES
('slack', 'Ops Channel', '{"webhook_url": "https://hooks.slack.com/services/..."}', 1);
```

**Discord:** 📋 Template bereit
```php
class DiscordChannel {
    public function send(array $notification): bool {
        $webhookUrl = $this->config['webhook_url'];
        $payload = [
            'content' => $notification['title'],
            'embeds' => [[
                'description' => $notification['message'],
                'color' => $this->getSeverityColor($notification['severity']),
            ]],
        ];
        // Send via cURL
    }
}
```

### 2.3 Notification Rules

**Datenbank Setup:**
```sql
CREATE TABLE notification_rules (
  id INT AUTO_INCREMENT,
  name VARCHAR(100),
  event_type VARCHAR(50),  -- 'vm_down', 'backup_failed', etc.
  conditions_json JSON,
  min_severity ENUM('debug','info','warning','error','critical'),
  cooldown_minutes INT,
  enabled TINYINT(1)
);

-- Beispiel
INSERT INTO notification_rules VALUES
(1, 'Critical VM Alerts', 'vm_down', '{"criticality": "high"}', 'critical', 60, 1);
```

**Integration in Code:**
```php
// In health_check.php
if ($vmStatus === 'offline') {
    NotificationManager::alert('vm_down',
        "VM {$vm['name']} is offline",
        ['vmid' => $vm['vmid'], 'node' => $node['name']]
    );
}

// In backups_runner.php
if (!$backupSuccess) {
    NotificationManager::alert('backup_failed',
        "Backup failed for VM {$vmid}",
        ['vmid' => $vmid, 'error' => $errorMessage]
    );
}
```

---

## 🔑 3. API Keys System

### 3.1 Datenbankstruktur

**Schema:** ✅ In `schema_extensions.sql`

```sql
CREATE TABLE api_keys (
  id INT AUTO_INCREMENT,
  user_id INT,
  name VARCHAR(100),
  key_hash VARCHAR(255),
  key_prefix VARCHAR(20),
  permissions_json JSON,
  last_used_at DATETIME,
  expires_at DATETIME,
  enabled TINYINT(1)
);
```

### 3.2 Implementierung (Template)

```php
// src/ApiKeyManager.php
class ApiKeyManager {
    /**
     * Generate new API key
     */
    public static function generate(int $userId, string $name, array $permissions = [], ?DateTime $expiresAt = null): array {
        // Generate random key
        $key = 'mcn_' . bin2hex(random_bytes(32));
        $keyHash = password_hash($key, PASSWORD_BCRYPT);
        $keyPrefix = substr($key, 0, 12); // For identification

        $id = Database::insert(
            'INSERT INTO api_keys (user_id, name, key_hash, key_prefix, permissions_json, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $name, $keyHash, $keyPrefix, json_encode($permissions), $expiresAt?->format('Y-m-d H:i:s')]
        );

        // Return key only once!
        return [
            'id' => $id,
            'key' => $key,  // Show only once
            'prefix' => $keyPrefix,
        ];
    }

    /**
     * Verify API key
     */
    public static function verify(string $key): ?array {
        $keyPrefix = substr($key, 0, 12);

        $apiKey = Database::selectOne(
            'SELECT * FROM api_keys WHERE key_prefix = ? AND enabled = 1',
            [$keyPrefix]
        );

        if (!$apiKey) {
            return null;
        }

        // Verify hash
        if (!password_verify($key, $apiKey['key_hash'])) {
            return null;
        }

        // Check expiration
        if ($apiKey['expires_at'] && strtotime($apiKey['expires_at']) < time()) {
            return null;
        }

        // Update last used
        Database::update(
            'UPDATE api_keys SET last_used_at = NOW() WHERE id = ?',
            [$apiKey['id']]
        );

        return $apiKey;
    }
}
```

### 3.3 API Endpoint

```php
// api/api_keys.php
require_once __DIR__ . '/init.php';
Auth::require('admin');

$action = getParam('action');

switch ($action) {
    case 'create':
        $data = getJsonInput();
        $key = ApiKeyManager::generate(
            Auth::id(),
            $data['name'],
            $data['permissions'] ?? [],
            isset($data['expires_days']) ? new DateTime("+{$data['expires_days']} days") : null
        );
        sendJson(['success' => true, 'api_key' => $key]);
        break;

    case 'list':
        $keys = Database::select(
            'SELECT id, name, key_prefix, last_used_at, expires_at, enabled
             FROM api_keys WHERE user_id = ?',
            [Auth::id()]
        );
        sendJson(['api_keys' => $keys]);
        break;

    case 'revoke':
        $id = getParam('id');
        Database::update(
            'UPDATE api_keys SET enabled = 0 WHERE id = ? AND user_id = ?',
            [$id, Auth::id()]
        );
        sendJson(['success' => true]);
        break;
}
```

### 3.4 Verwendung in APIs

**Modifiziere `api/init.php`:**
```php
// Check for API key authentication
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? getParam('api_key');

if ($apiKey) {
    $keyData = ApiKeyManager::verify($apiKey);
    if ($keyData) {
        // Set user from API key
        $_SESSION['user_id'] = $keyData['user_id'];
        $_SESSION['api_key_auth'] = true;
    } else {
        sendError('Invalid API key', 401);
    }
}
```

**cURL Beispiel:**
```bash
curl -H "X-API-Key: mcn_abc123..." http://your-host/api/nodes.php
```

---

## 📊 4. Monitoring Integration

### 4.1 Prometheus Integration

**Template Implementation:**
```php
// src/Monitoring/PrometheusClient.php
class PrometheusClient {
    private string $apiUrl;

    public function __construct(string $apiUrl) {
        $this->apiUrl = rtrim($apiUrl, '/');
    }

    /**
     * Execute PromQL query
     */
    public function query(string $promql): array {
        $url = $this->apiUrl . '/api/v1/query?query=' . urlencode($promql);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true)['data']['result'] ?? [];
    }

    /**
     * Get CPU usage for VM
     */
    public function getVMCpuUsage(int $vmid): ?float {
        $result = $this->query("pve_cpu_usage{vmid=\"$vmid\"}");
        return $result[0]['value'][1] ?? null;
    }

    /**
     * Get memory usage for VM
     */
    public function getVMMemoryUsage(int $vmid): ?float {
        $result = $this->query("pve_mem_usage{vmid=\"$vmid\"}");
        return $result[0]['value'][1] ?? null;
    }
}
```

### 4.2 Alert Rules

**Datenbank Schema:** ✅ In `schema_extensions.sql`

**Implementierung:**
```php
// cron/monitor_alerts.php
#!/usr/bin/env php
<?php
require __DIR__ . '/../api/init.php';

$prometheus = new PrometheusClient('http://prometheus.local:9090');

$rules = Database::select(
    'SELECT * FROM alert_rules WHERE enabled = 1'
);

foreach ($rules as $rule) {
    // Get current metric value
    $value = $prometheus->query($rule['metric']);

    // Check condition
    $triggered = self::checkCondition(
        $value,
        $rule['condition'],
        $rule['threshold']
    );

    if ($triggered) {
        // Create alert
        Database::insert(
            'INSERT INTO alerts (alert_rule_id, message, value, status)
             VALUES (?, ?, ?, ?)',
            [$rule['id'], $rule['name'] . ' triggered', $value, 'firing']
        );

        // Send notification
        NotificationManager::alert(
            'metric_threshold',
            "{$rule['name']}: {$rule['metric']} is {$value} (threshold: {$rule['threshold']})"
        );
    }
}
```

**Dashboard Integration:**
```javascript
// Widget showing active alerts
{
  type: 'active_alerts',
  data_source: '/api/monitoring.php?action=active_alerts'
}
```

---

## 💰 5. Cost Tracking & Reporting

### 5.1 Cost Model Setup

```sql
-- Define cost model
INSERT INTO cost_models (name, cpu_core_hourly, memory_gb_hourly, storage_gb_monthly, effective_from) VALUES
('2025 Pricing', 0.015, 0.010, 0.05, '2025-01-01');

-- Start collecting usage snapshots (in health_check.php)
INSERT INTO resource_usage_snapshots (pve_node_id, vmid, cpu_cores, memory_gb, storage_gb, status)
SELECT pve_node_id, vmid, maxcpu, maxmem/1073741824, maxdisk/1073741824, status
FROM pve_resources_cache;
```

### 5.2 Cost Calculator

```php
// src/CostCalculator.php
class CostCalculator {
    /**
     * Calculate costs for period
     */
    public static function calculateForPeriod(DateTime $start, DateTime $end, ?int $teamId = null, ?int $serviceId = null): array {
        $costModel = Database::selectOne(
            'SELECT * FROM cost_models WHERE active = 1 ORDER BY effective_from DESC LIMIT 1'
        );

        $snapshots = Database::select(
            'SELECT * FROM resource_usage_snapshots
             WHERE snapshot_at BETWEEN ? AND ?',
            [$start->format('Y-m-d'), $end->format('Y-m-d')]
        );

        $totalCost = 0;
        $breakdown = [];

        foreach ($snapshots as $snapshot) {
            $hours = 1; // snapshots every hour

            $cpuCost = $snapshot['cpu_cores'] * $costModel['cpu_core_hourly'] * $hours;
            $memCost = $snapshot['memory_gb'] * $costModel['memory_gb_hourly'] * $hours;

            $vmCost = $cpuCost + $memCost;
            $totalCost += $vmCost;

            $breakdown[$snapshot['vmid']] = ($breakdown[$snapshot['vmid']] ?? 0) + $vmCost;
        }

        return [
            'total_cost' => round($totalCost, 2),
            'breakdown' => $breakdown,
            'period_start' => $start->format('Y-m-d'),
            'period_end' => $end->format('Y-m-d'),
        ];
    }
}
```

### 5.3 Cost Report API

```php
// api/costs.php
require_once __DIR__ . '/init.php';
Auth::require();

$action = getParam('action', 'monthly');

switch ($action) {
    case 'monthly':
        $month = getParam('month', date('Y-m'));
        $start = new DateTime($month . '-01');
        $end = (clone $start)->modify('last day of this month');

        $costs = CostCalculator::calculateForPeriod($start, $end);
        sendJson($costs);
        break;

    case 'by_service':
        $serviceId = getParam('service_id');
        // Calculate for specific service
        break;
}
```

**Dashboard Widget:**
```javascript
{
  type: 'monthly_costs',
  shows_breakdown: true,
  chart_type: 'pie'
}
```

---

## 🖥️ 6. VM/LXC Provisioning

### 6.1 Template Management

```sql
-- Register templates
INSERT INTO vm_templates (name, description, pve_node_id, template_vmid, type, category, config_json) VALUES
('Ubuntu 22.04 Server', 'Base Ubuntu server template', 1, 9000, 'qemu', 'linux',
 '{"cores": 2, "memory": 2048, "disk_size": "20G"}');
```

### 6.2 Provisioning Engine

```php
// src/ProvisioningEngine.php
class ProvisioningEngine {
    /**
     * Provision new VM from template
     */
    public static function provisionVM(int $templateId, array $config): int {
        $template = Database::selectOne(
            'SELECT * FROM vm_templates WHERE id = ?',
            [$templateId]
        );

        // Create provisioning job
        $jobId = Database::insert(
            'INSERT INTO provisioning_jobs
             (template_id, requested_by, target_node_id, vm_name, config_override_json, status)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $templateId,
                Auth::id(),
                $config['target_node_id'],
                $config['vm_name'],
                json_encode($config),
                'pending'
            ]
        );

        // Start provisioning async
        self::executeProvisioningJob($jobId);

        return $jobId;
    }

    private static function executeProvisioningJob(int $jobId): void {
        $job = Database::selectOne('SELECT * FROM provisioning_jobs WHERE id = ?', [$jobId]);
        $template = Database::selectOne('SELECT * FROM vm_templates WHERE id = ?', [$job['template_id']]);

        Database::update(
            'UPDATE provisioning_jobs SET status = ? WHERE id = ?',
            ['running', $jobId]
        );

        try {
            $node = NodesRepository::getById($job['target_node_id']);
            $client = NodesRepository::getClient($job['target_node_id']);

            // Get next available VMID
            $newVmid = self::getNextAvailableVmid($client);

            // Clone template
            $client->post("/nodes/{$node['name']}/qemu/{$template['template_vmid']}/clone", [
                'newid' => $newVmid,
                'name' => $job['vm_name'],
                'full' => 1,
            ]);

            // Apply config overrides
            $config = json_decode($job['config_override_json'], true);
            if (!empty($config['cores']) || !empty($config['memory'])) {
                $client->updateVMConfig($node['name'], $newVmid, 'qemu', $config);
            }

            // Mark complete
            Database::update(
                'UPDATE provisioning_jobs SET status = ?, new_vmid = ?, completed_at = NOW() WHERE id = ?',
                ['completed', $newVmid, $jobId]
            );

            NotificationManager::success("VM {$job['vm_name']} provisioned successfully (VMID: $newVmid)");

        } catch (Exception $e) {
            Database::update(
                'UPDATE provisioning_jobs SET status = ?, error_message = ? WHERE id = ?',
                ['failed', $e->getMessage(), $jobId]
            );

            NotificationManager::alert('provision_failed', "Failed to provision VM: {$e->getMessage()}");
        }
    }
}
```

### 6.3 Provisioning UI

```javascript
// public/provision.php - New page
async function provisionVM() {
    const data = {
        template_id: document.getElementById('template').value,
        target_node_id: document.getElementById('node').value,
        vm_name: document.getElementById('vm_name').value,
        cores: document.getElementById('cores').value,
        memory: document.getElementById('memory').value,
    };

    const response = await MCN.api('provisioning.php?action=create', 'POST', data);

    if (response.success) {
        MCN.showAlert('Provisioning started. Job ID: ' + response.job_id, 'success');
        // Poll for status
        pollProvisioningStatus(response.job_id);
    }
}
```

---

## 👥 7. Multi-Tenancy & Team Management

### 7.1 Setup

```sql
-- Create teams
INSERT INTO teams (name, description, quota_cpu, quota_memory_gb, quota_storage_gb) VALUES
('Development Team', 'Dev environment resources', 32, 64, 500);

-- Add team members
INSERT INTO team_members (team_id, user_id, role) VALUES
(1, 2, 'admin'),
(1, 3, 'member');

-- Assign resources to team
INSERT INTO team_resources (team_id, pve_node_id, vmid) VALUES
(1, 1, 100),
(1, 1, 101);
```

### 7.2 Team Dashboard

```php
// api/teams.php
Auth::require();

$action = getParam('action');

switch ($action) {
    case 'my_teams':
        $teams = Database::select(
            'SELECT t.* FROM teams t
             JOIN team_members tm ON t.id = tm.team_id
             WHERE tm.user_id = ?',
            [Auth::id()]
        );

        foreach ($teams as &$team) {
            $team['resources'] = Database::select(
                'SELECT tr.*, c.name, c.status
                 FROM team_resources tr
                 JOIN pve_resources_cache c ON tr.pve_node_id = c.pve_node_id AND tr.vmid = c.vmid
                 WHERE tr.team_id = ?',
                [$team['id']]
            );

            $team['usage'] = self::calculateTeamUsage($team['id']);
        }

        sendJson(['teams' => $teams]);
        break;
}
```

---

## 📱 8. Progressive Web App (PWA)

### 8.1 Manifest

```json
// public/manifest.json
{
  "name": "Matdan Control Nexus",
  "short_name": "MCN",
  "start_url": "/dashboard.php",
  "display": "standalone",
  "background_color": "#1a1d23",
  "theme_color": "#4a9eff",
  "icons": [
    {
      "src": "/assets/images/icon-192.png",
      "sizes": "192x192",
      "type": "image/png"
    },
    {
      "src": "/assets/images/icon-512.png",
      "sizes": "512x512",
      "type": "image/png"
    }
  ]
}
```

### 8.2 Service Worker

```javascript
// public/sw.js
const CACHE_NAME = 'mcn-v2.0';
const urlsToCache = [
  '/dashboard.php',
  '/assets/css/style.css',
  '/assets/js/app.js',
  '/assets/js/dashboard.js',
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => cache.addAll(urlsToCache))
  );
});

self.addEventListener('fetch', event => {
  event.respondWith(
    caches.match(event.request)
      .then(response => response || fetch(event.request))
  );
});
```

---

## 📋 Implementierungs-Roadmap

### Phase 1: Sofort einsatzbereit (✅ Komplett)
- ✅ SQL Schema Extensions
- ✅ Integration Clients (NPM, AdGuard)
- ✅ Notification System (Email, Slack)

### Phase 2: High Priority (2-3 Wochen)
1. API Keys System komplett implementieren
2. Monitoring Integration (Prometheus)
3. VM Provisioning System
4. Cost Tracking Basis

### Phase 3: Medium Priority (4-6 Wochen)
5. Multi-Tenancy erweitern
6. Reporting Engine
7. Advanced Dashboard Widgets
8. PBS Integration

### Phase 4: Advanced Features (6+ Wochen)
9. Workflow Engine
10. Compliance System
11. PWA Optimierung
12. Documentation Wiki

---

## 🎯 Quick Start für neue Features

### Neues Feature hinzufügen:

1. **Datenbank:** Schema bereits in `schema_extensions.sql`
2. **Backend:** Klasse in `src/` erstellen
3. **API:** Endpoint in `api/` erstellen
4. **Frontend:** Widget in `dashboard.js` oder neue Seite

### Beispiel: Neuen Notification Channel hinzufügen

```php
// 1. Erstelle src/Notifications/Channels/TelegramChannel.php
class TelegramChannel {
    public function send(array $notification): bool {
        $botToken = $this->config['bot_token'];
        $chatId = $this->config['chat_id'];

        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";

        $payload = [
            'chat_id' => $chatId,
            'text' => "{$notification['title']}\n\n{$notification['message']}",
            'parse_mode' => 'HTML',
        ];

        // cURL request
        return $this->sendRequest($url, $payload);
    }
}

// 2. Registriere in NotificationManager::loadChannels()
case 'telegram':
    self::$channels[] = new TelegramChannel($config);
    break;

// 3. Füge in DB hinzu
INSERT INTO notification_channels (type, name, config_json) VALUES
('telegram', 'Admin Bot', '{"bot_token": "...", "chat_id": "..."}');
```

---

## 🔧 Wartung & Support

### Datenbank Migration

```bash
# Apply extensions
mysql -u mcn_user -p matdan_control_nexus < sql/schema_extensions.sql

# Verify
mysql -u mcn_user -p matdan_control_nexus -e "SHOW TABLES LIKE '%notification%';"
```

### Backup vor Updates

```bash
mysqldump -u mcn_user -p matdan_control_nexus > backup_before_v2.sql
```

---

## 📚 Weitere Ressourcen

- **API Dokumentation:** Siehe README.md
- **Prometheus PromQL:** https://prometheus.io/docs/prometheus/latest/querying/basics/
- **Proxmox API:** https://pve.proxmox.com/pve-docs/api-viewer/
- **Nginx Proxy Manager API:** https://nginxproxymanager.com/

---

**Version:** 2.0.0-beta
**Last Updated:** Januar 2025
**Contributors:** Matdan, Claude AI

