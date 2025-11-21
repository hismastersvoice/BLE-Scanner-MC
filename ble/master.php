<?php
/**
 * Master-Client Verwaltung
 * Nur verfügbar wenn mode = master
 */

require_once __DIR__ . '/php_logger.php';

// --- PFADE ---
$INI_FILE = '/var/www/html/ble/config.ini';
$CLIENTS_FILE = '/var/www/html/ble/clients.json';
$KNOWN_DEVICES_FILE = '/var/www/html/ble/known_devices.txt';
$BATTERY_STATUS_FILE = '/var/www/html/ble/battery_status.json';
$WEBHOOK_LOG_FILE = '/var/log/ble/webhook.log';

// --- CONFIG LADEN ---
$config = @parse_ini_file($INI_FILE, true, INI_SCANNER_RAW) ?? [];
$current_mode = $config['MasterClient']['mode'] ?? 'standalone';
$is_client_mode = ($current_mode === 'client');
$is_master_mode = ($current_mode === 'master');


// Redirect wenn nicht Master
if ($current_mode !== 'master') {
    header('Location: config.php');
    exit;
}

$current_hostname = gethostname();
$message = '';
$error_message = '';

// --- HELFER: Clients laden ---
function get_clients($file) {
    if (!file_exists($file)) {
        return [];
    }
    return json_decode(file_get_contents($file), true) ?? [];
}

// --- HELFER: Clients speichern ---
function save_clients($file, $clients) {
    file_put_contents($file, json_encode($clients, JSON_PRETTY_PRINT));
}

// --- HELFER: API-Key generieren ---
function generate_api_key() {
    return bin2hex(random_bytes(32));
}

// --- HELFER: Push an Client ---
function push_to_client($client, $known_devices_file, $battery_status_file) {
    // Daten vorbereiten
    $devices = [];
    if (file_exists($known_devices_file)) {
        $lines = file($known_devices_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $parts = explode(',', $line, 3);
            if (count($parts) >= 2) {
                $devices[strtoupper(trim($parts[0]))] = [
                    'alias' => trim($parts[1]),
                    'battery_scan' => (isset($parts[2]) && (int)$parts[2] === 1)
                ];
            }
        }
    }
    
    $battery_status = [];
    if (file_exists($battery_status_file)) {
        $battery_status = json_decode(file_get_contents($battery_status_file), true) ?? [];
    }
    
    $data = [
        'devices' => $devices,
        'battery_status' => $battery_status,
        'timestamp' => time()
    ];
    
    // CURL-Request an Client
    $ch = curl_init($client['url'] . '/api.php?action=receive_push');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-API-Key: ' . $client['api_key']
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [
        'success' => ($http_code === 200),
        'http_code' => $http_code,
        'response' => $response
    ];
}

// --- HELFER: Webhook-Log laden ---
function get_webhook_log($file, $limit = 50) {
    if (!file_exists($file)) return [];
    
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $logs = [];
    
    foreach (array_reverse($lines) as $line) {
        if (count($logs) >= $limit) break;
        $entry = json_decode($line, true);
        if ($entry) {
            $logs[] = $entry;
        }
    }
    
    return $logs;
}

// --- AKTIONEN ---
$clients = get_clients($CLIENTS_FILE);

// Client hinzufügen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_client') {
    $name = trim($_POST['name'] ?? '');
    $url = trim($_POST['url'] ?? '');
    $ip_whitelist = trim($_POST['ip_whitelist'] ?? '');
    
    if (!empty($name) && !empty($url)) {
        $api_key = generate_api_key();
        
        $clients[] = [
            'name' => $name,
            'url' => rtrim($url, '/'),
            'api_key' => $api_key,
            'ip_whitelist' => $ip_whitelist,
            'status' => 'unknown',
            'last_sync' => null,
            'created' => time()
        ];
        
        save_clients($CLIENTS_FILE, $clients);
        
        BLELogger::info("Client added", [
            'name' => $name,
            'url' => $url,
            'user_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        
        $message = "Client '$name' erfolgreich hinzugefügt!";
    } else {
        $error_message = "Name und URL sind erforderlich!";
    }
}

// Client löschen
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['index'])) {
    $index = intval($_GET['index']);
    if (isset($clients[$index])) {
        $deleted_name = $clients[$index]['name'];
        array_splice($clients, $index, 1);
        save_clients($CLIENTS_FILE, $clients);
        
        BLELogger::info("Client deleted", [
            'name' => $deleted_name,
            'user_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        
        $message = "Client gelöscht!";
    }
}

// Client bearbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_client') {
    $index = intval($_POST['index']);
    if (isset($clients[$index])) {
        $clients[$index]['name'] = trim($_POST['name'] ?? '');
        $clients[$index]['url'] = rtrim(trim($_POST['url'] ?? ''), '/');
        $clients[$index]['ip_whitelist'] = trim($_POST['ip_whitelist'] ?? '');
        
        save_clients($CLIENTS_FILE, $clients);
        
        BLELogger::info("Client updated", [
            'name' => $clients[$index]['name'],
            'user_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        
        $message = "Client aktualisiert!";
    }
}

// Push an einzelnen Client
if (isset($_GET['action']) && $_GET['action'] === 'push' && isset($_GET['index'])) {
    $index = intval($_GET['index']);
    if (isset($clients[$index])) {
        $result = push_to_client($clients[$index], $KNOWN_DEVICES_FILE, $BATTERY_STATUS_FILE);
        
        // Status aktualisieren
        $clients[$index]['last_sync'] = time();
        $clients[$index]['status'] = $result['success'] ? 'online' : 'error';
        save_clients($CLIENTS_FILE, $clients);
        
        // Webhook-Log
        $log_entry = [
            'timestamp' => time(),
            'client' => $clients[$index]['name'],
            'action' => 'manual_push',
            'success' => $result['success'],
            'http_code' => $result['http_code'],
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ];
        file_put_contents($WEBHOOK_LOG_FILE, json_encode($log_entry) . "\n", FILE_APPEND);
        
        BLELogger::info("Manual push to client", [
            'client' => $clients[$index]['name'],
            'success' => $result['success'],
            'http_code' => $result['http_code']
        ]);
        
        if ($result['success']) {
            $message = "Push an '" . $clients[$index]['name'] . "' erfolgreich!";
        } else {
            $error_message = "Push fehlgeschlagen! HTTP-Code: " . $result['http_code'];
        }
    }
}

// Push an ALLE Clients
if (isset($_GET['action']) && $_GET['action'] === 'push_all') {
    $success_count = 0;
    $fail_count = 0;
    
    foreach ($clients as &$client) {
        $result = push_to_client($client, $KNOWN_DEVICES_FILE, $BATTERY_STATUS_FILE);
        
        $client['last_sync'] = time();
        $client['status'] = $result['success'] ? 'online' : 'error';
        
        if ($result['success']) {
            $success_count++;
        } else {
            $fail_count++;
        }
        
        // Webhook-Log
        $log_entry = [
            'timestamp' => time(),
            'client' => $client['name'],
            'action' => 'push_all',
            'success' => $result['success'],
            'http_code' => $result['http_code'],
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ];
        file_put_contents($WEBHOOK_LOG_FILE, json_encode($log_entry) . "\n", FILE_APPEND);
    }
    
    save_clients($CLIENTS_FILE, $clients);
    
    BLELogger::info("Push to all clients", [
        'success_count' => $success_count,
        'fail_count' => $fail_count,
        'user_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
    
    $message = "Push an alle Clients: $success_count erfolgreich, $fail_count fehlgeschlagen";
}

// Webhook-Log laden
$webhook_logs = get_webhook_log($WEBHOOK_LOG_FILE, 50);

?>
<!DOCTYPE html>
<html lang="de" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master-Client Verwaltung</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css"/>
    <style>
        html { font-size: 14px; }
        body { padding-top: 20px; }
        button, input[type="text"], input[type="url"], [role="button"] {
            font-size: 0.9rem; height: auto; margin-bottom: 0;
            padding: 5px 8px;
        }
        
        [role="button"].primary, button.primary {
            background-color: #76b852; border-color: #76b852; color: #FFF;
        }
        [role="button"].primary:hover, button.primary:hover {
            background-color: #388e3c; border-color: #388e3c;
        }
        
		 nav, section { margin-bottom: 1rem; }
        .container { max-width: 960px; }
        
        nav ul:first-child li { 
            display: flex; align-items: center; gap: 10px; padding: 0;
        }
        .nav-logo {
            height: 100px; width: 100px; border-radius: 5px;
        }
        nav h1 {
            font-size: 1.5rem; margin: 0;
        }
        nav h3 {
            font-size: 1rem; color: #666; margin: 0; font-weight: 400;
        }
		
        .mode-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 3px;
            font-size: 0.9rem;
            font-weight: bold;
            margin-left: 10px;
        }
        .mode-badge.client { background: #4CAF50; color: white; }
        .mode-badge.master { background: #2196F3; color: white; }		
        
        .client-card {
            border: 2px solid #ddd;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .client-card.online { border-color: green; }
        .client-card.error { border-color: red; }
        .client-card.unknown { border-color: #999; }
        
        .client-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .client-info {
            display: grid;
            grid-template-columns: 150px 1fr;
            gap: 5px;
            font-size: 0.9rem;
            margin-bottom: 10px;
        }
        
        .client-actions {
            display: flex;
            gap: 10px;
        }
        
        .api-key-display {
            font-family: monospace;
            background: #f5f5f5;
            padding: 5px;
            border-radius: 3px;
            font-size: 0.85rem;
            word-break: break-all;
        }
        
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 0.85rem;
            font-weight: bold;
        }
        .status-badge.online { background: #4caf50; color: white; }
        .status-badge.error { background: #f44336; color: white; }
        .status-badge.unknown { background: #999; color: white; }
        
        .webhook-log {
            max-height: 400px;
            overflow-y: auto;
            font-size: 0.85rem;
        }
        
        .webhook-entry {
            padding: 5px;
            border-bottom: 1px solid #eee;
        }
        .webhook-entry.success { background: #e8f5e9; }
        .webhook-entry.failed { background: #ffebee; }
    </style>
</head>
<body>
    <main class="container">
        <nav>
            <ul>
                <li>
                    <img src="logo.jpg" alt="Logo" class="nav-logo">
                    <div class="nav-title-group">
                        <h1>BLE Presence</h1>
                        <h3>
                            <?php echo 'Host: http://'.$current_hostname; ?>
                            <span class="mode-badge <?php echo $current_mode; ?>">
                                <?php echo strtoupper($current_mode); ?>
                            </span>
                        </h3>
                    </div>
                </li>
            </ul>
            <ul>
                <li><a href="devices.php" class="primary" role="button">Geräte-Übersicht</a></li>
                <li><a href="config.php" class="primary" role="button">Konfiguration</a></li>
                <?php if ($current_mode === 'master'): ?>
                    <li><a href="master.php" class="primary" role="button">Clients</a></li>
                <?php endif; ?>
                <li><a href="security.php" class="primary" role="button">Sicherheit</a></li>
                <li><a href="network.php" class="primary" role="button">System</a></li>
				<li><a href="logs.php" class="primary" role="button">Logs</a></li>
                <li><a href="network.php?action=reboot" 
                        class="contrast" 
                        role="button" 
                        onclick="return confirm('Möchten Sie das System wirklich neu starten?')">🔄 Neustart</a></li>
            </ul>
        </nav>
<h2>Client-Verwaltung</h2>
        <?php if ($message): ?>
            <article style="background: #d4edda; color: #155724; padding: 1rem;">
                <?php echo htmlspecialchars($message); ?>
            </article>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            <article style="background: #f8d7da; color: #721c24; padding: 1rem;">
                <?php echo htmlspecialchars($error_message); ?>
            </article>
        <?php endif; ?>

        

        <!-- Clients Liste -->
        <section>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <h3>Registrierte Clients (<?php echo count($clients); ?>)</h3>
                <?php if (count($clients) > 0): ?>
                    <a href="master.php?action=push_all" class="primary" role="button" onclick="return confirm('Wirklich an ALLE Clients pushen?')">
                        📤 An ALLE pushen
                    </a>
                <?php endif; ?>
            </div>

            <?php if (empty($clients)): ?>
                <article>Noch keine Clients registriert.</article>
            <?php else: ?>
                <?php foreach ($clients as $index => $client): ?>
                    <div class="client-card <?php echo $client['status']; ?>">
                        <div class="client-header">
                            <strong><?php echo htmlspecialchars($client['name']); ?></strong>
                            <span class="status-badge <?php echo $client['status']; ?>">
                                <?php echo strtoupper($client['status']); ?>
                            </span>
                        </div>
                        
                        <div class="client-info">
                            <span><strong>URL:</strong></span>
                            <span><?php echo htmlspecialchars($client['url']); ?></span>
                            
                            <span><strong>API-Key:</strong></span>
                            <span class="api-key-display"><?php echo htmlspecialchars($client['api_key']); ?></span>
                            
                            <span><strong>IP-Whitelist:</strong></span>
                            <span><?php echo !empty($client['ip_whitelist']) ? htmlspecialchars($client['ip_whitelist']) : '<em>Alle IPs erlaubt</em>'; ?></span>
                            
                            <span><strong>Letzter Sync:</strong></span>
                            <span><?php echo $client['last_sync'] ? date('d.m.Y H:i:s', $client['last_sync']) : 'Noch nie'; ?></span>
                        </div>
                        
                        <div class="client-actions">
                            <a href="master.php?action=push&index=<?php echo $index; ?>" class="primary" role="button">
                                📤 Push
                            </a>
                            <button onclick="editClient(<?php echo $index; ?>)" class="secondary">✏️ Bearbeiten</button>
                            <a href="master.php?action=delete&index=<?php echo $index; ?>" 
                               class="contrast" 
                               role="button"
                               onclick="return confirm('Client wirklich löschen?')">
                                🗑️ Löschen
                            </a>
                        </div>
                        
                        <!-- Bearbeiten-Formular (hidden) -->
                        <form method="POST" id="edit-form-<?php echo $index; ?>" style="display:none; margin-top: 15px; border-top: 1px solid #ddd; padding-top: 15px;">
                            <input type="hidden" name="action" value="edit_client">
                            <input type="hidden" name="index" value="<?php echo $index; ?>">
                            
                            <label>Name:
                                <input type="text" name="name" value="<?php echo htmlspecialchars($client['name']); ?>" required>
                            </label>
                            
                            <label>URL:
                                <input type="url" name="url" value="<?php echo htmlspecialchars($client['url']); ?>" required>
                            </label>
                            
                            <label>IP-Whitelist (komma-separiert, leer = alle):
                                <input type="text" name="ip_whitelist" value="<?php echo htmlspecialchars($client['ip_whitelist']); ?>" placeholder="z.B. 192.168.1.10, 192.168.1.20">
                            </label>
                            
                            <button type="submit" class="primary">💾 Speichern</button>
                            <button type="button" onclick="document.getElementById('edit-form-<?php echo $index; ?>').style.display='none'">Abbrechen</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <!-- Client hinzufügen -->
        <section>
            <details>
                <summary><strong>➕ Neuen Client hinzufügen</strong></summary>
                <form method="POST" style="margin-top: 15px;">
                    <input type="hidden" name="action" value="add_client">
                    
                    <label>Name:
                        <input type="text" name="name" placeholder="z.B. Standort Büro" required>
                    </label>
                    
                    <label>URL:
                        <input type="url" name="url" placeholder="http://192.168.1.100/ble" required>
                    </label>
                    
                    <label>IP-Whitelist (komma-separiert, leer = alle):
                        <input type="text" name="ip_whitelist" placeholder="z.B. 192.168.1.10, 192.168.1.20">
                    </label>
                    
                    <button type="submit" class="primary">✅ Client hinzufügen</button>
                </form>
            </details>
        </section>

        <!-- Webhook-Log -->
        <section>
            <h3>Webhook-Log (letzte 50 Einträge)</h3>
            <div class="webhook-log">
                <?php if (empty($webhook_logs)): ?>
                    <p><em>Noch keine Webhook-Aktivitäten</em></p>
                <?php else: ?>
                    <?php foreach ($webhook_logs as $log): ?>
                        <div class="webhook-entry <?php echo $log['success'] ? 'success' : 'failed'; ?>">
                            <strong><?php echo date('d.m.Y H:i:s', $log['timestamp']); ?></strong> | 
                            Client: <strong><?php echo htmlspecialchars($log['client']); ?></strong> | 
                            Aktion: <?php echo htmlspecialchars($log['action']); ?> | 
                            <?php if (isset($log['http_code'])): ?>
                                HTTP: <?php echo $log['http_code']; ?> |
                            <?php endif; ?>
                            Status: <strong><?php echo $log['success'] ? '✅ Erfolg' : '❌ Fehler'; ?></strong>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

    </main>

    <script>
        function editClient(index) {
            const form = document.getElementById('edit-form-' + index);
            form.style.display = form.style.display === 'none' ? 'block' : 'none';
        }
    </script>

</body>
</html>
