<?php
require_once __DIR__ . '/php_logger.php';
// --- PFADE ---
$INI_FILE = '/var/www/html/ble/config.ini';
$PYTHON_SCRIPT_PATH = '/var/www/html/ble/ble_tool.py'; 
$KNOWN_DEVICES_FILE = '/var/www/html/ble/known_devices.txt';
$DISCOVER_FILE = '/var/www/html/ble/scan_results.json';
$LAST_BATTERY_SCAN_FILE = '/var/www/html/ble/last_battery_scan.txt';
$BATTERY_STATUS_FILE = '/var/www/html/ble/battery_status.json';
$CLIENTS_FILE = '/var/www/html/ble/clients.json';
$WEBHOOK_LOG_FILE = '/var/log/ble/webhook.log';

$config = parse_ini_file($INI_FILE, true, INI_SCANNER_RAW);
$current_mode = $config['MasterClient']['mode'] ?? 'standalone';
$is_client_mode = ($current_mode === 'client');
$is_master_mode = ($current_mode === 'master');

$message = '';
$error_message = '';
$terminal_output = '';
$meta_refresh = null;

$current_hostname = gethostname();

// --- HELFER: Push an alle Clients (nur im Master-Modus) ---
function push_to_all_clients($clients_file, $known_devices_file, $battery_status_file, $webhook_log_file) {
    if (!file_exists($clients_file)) return;
    
    $clients = json_decode(file_get_contents($clients_file), true) ?? [];
    
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
    
    // Push an jeden Client
    foreach ($clients as &$client) {
        $ch = curl_init($client['url'] . '/api.php?action=receive_push');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-API-Key: ' . $client['api_key']
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $success = ($http_code === 200);
        
        // Status aktualisieren
        $client['last_sync'] = time();
        $client['status'] = $success ? 'online' : 'error';
        
        // Webhook-Log
        $log_entry = [
            'timestamp' => time(),
            'client' => $client['name'],
            'action' => 'auto_push',
            'success' => $success,
            'http_code' => $http_code,
            'ip' => 'system'
        ];
        file_put_contents($webhook_log_file, json_encode($log_entry) . "\n", FILE_APPEND);
        
        BLELogger::info("Auto-push to client", [
            'client' => $client['name'],
            'success' => $success,
            'http_code' => $http_code
        ]);
    }
    
    file_put_contents($clients_file, json_encode($clients, JSON_PRETTY_PRINT));
}

// --- HELFER: Known-Devices laden ---
function get_known_devices($file) {
    $devices = []; 
    if (!file_exists($file)) return $devices;
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $parts = explode(',', $line, 3); 
        if (count($parts) >= 2) {
            $devices[strtoupper(trim($parts[0]))] = [
                'alias' => trim($parts[1]),
                'battery_scan' => (isset($parts[2]) && (int)$parts[2] === 1) 
            ];
        }
    }
    uasort($devices, function($a, $b) { return strcasecmp($a['alias'], $b['alias']); });
    return $devices;
}

// --- HELFER: Known-Devices speichern ---
function save_known_devices($file, $devices) {
    uasort($devices, function($a, $b) { return strcasecmp($a['alias'], $b['alias']); });
    $lines = [];
    foreach ($devices as $mac => $device_data) {
        $lines[] = "$mac," . $device_data['alias'] . "," . ($device_data['battery_scan'] ? '1' : '0');
    }
    file_put_contents($file, implode("\n", $lines));
}

// --- HELFER: Batterie-Status laden ---
function get_battery_status($file) {
    if (!file_exists($file)) return [];
    try {
        $json_data = file_get_contents($file);
        return json_decode($json_data, true);
    } catch (Exception $e) {
        return []; 
    }
}


// --- AKTIONEN (Löschen, Hinzufügen, Editieren) ---
$known_devices = get_known_devices($KNOWN_DEVICES_FILE);

// --- BATTERIE-SCAN UMSCHALTEN (via GET) - FUNKTIONIERT AUCH IM CLIENT-MODUS ---
if (isset($_GET['toggle_battery'])) {
    $mac_to_toggle = strtoupper(trim($_GET['toggle_battery']));
    BLELogger::info("Change automatic Battery read", ['mac' => $mac_to_toggle, 'user_ip' => $_SERVER['REMOTE_ADDR'], 'mode' => $current_mode]);
    
    if (isset($known_devices[$mac_to_toggle])) {
        $known_devices[$mac_to_toggle]['battery_scan'] = !$known_devices[$mac_to_toggle]['battery_scan'];
        save_known_devices($KNOWN_DEVICES_FILE, $known_devices);
        $status_text = $known_devices[$mac_to_toggle]['battery_scan'] ? "aktiviert" : "deaktiviert";
        $message = "Batterie-Scan für " . $known_devices[$mac_to_toggle]['alias'] . " $status_text.";
        
        // Push an Clients (nur im Master-Modus)
        if ($is_master_mode) {
            push_to_all_clients($CLIENTS_FILE, $KNOWN_DEVICES_FILE, $BATTERY_STATUS_FILE, $WEBHOOK_LOG_FILE);
        }
        
        header("Location: devices.php#known-devices");
        exit;
    }
}

// --- ALLE BATTERIEN LESEN (via GET) - FUNKTIONIERT AUCH IM CLIENT-MODUS ---
if (isset($_GET['action']) && $_GET['action'] === 'read_all') {
    BLELogger::info("User triggered battery scan for all enabled devices", ['user_ip' => $_SERVER['REMOTE_ADDR'], 'mode' => $current_mode]);
    $context = stream_context_create(['http' => ['timeout' => 5]]);
    @file_get_contents("http://localhost/ble/action.php?cmd=read_enabled_batteries", false, $context); 
    $message = "Befehl zum Lesen aller aktivierten Batterien wurde ausgelöst. Die Daten kommen (je nach Anzahl) in Kürze via MQTT/UDP an.";
    $meta_refresh = '<meta http-equiv="refresh" content="5; url=devices.php#known-devices">';
}

// --- EINZELNES GERÄT LESEN (via GET) - FUNKTIONIERT AUCH IM CLIENT-MODUS ---
if (isset($_GET['action']) && $_GET['action'] === 'read_single' && isset($_GET['mac'])) {
    $mac_to_read = trim($_GET['mac']);
    BLELogger::info("User triggered single battery read", ['mac' => $mac_to_read, 'user_ip' => $_SERVER['REMOTE_ADDR'], 'mode' => $current_mode]);
    $context = stream_context_create(['http' => ['timeout' => 5]]); 
    @file_get_contents("http://localhost/ble/action.php?cmd=read&mac=" . urlencode($mac_to_read), false, $context);
    
    $alias_name = $known_devices[strtoupper($mac_to_read)]['alias'] ?? $mac_to_read;
    $message = "Befehl zum Lesen der Batterie von '" . htmlspecialchars($alias_name) . "' wurde ausgelöst.";
    
    $meta_refresh = '<meta http-equiv="refresh" content="5; url=devices.php#known-devices">';
}


// --- POST-AKTIONEN (nur wenn NICHT im Client-Modus) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_client_mode) {
    
    $push_needed = false;
    
    if (isset($_POST['delete_mac'])) {
        $mac_to_delete = strtoupper(trim($_POST['delete_mac']));
        BLELogger::info("Deleting device", ['mac' => $mac_to_delete, 'user_ip' => $_SERVER['REMOTE_ADDR']]);
        unset($known_devices[$mac_to_delete]);
        save_known_devices($KNOWN_DEVICES_FILE, $known_devices);
        $message = 'Gerät ' . htmlspecialchars($mac_to_delete) . ' gelöscht.';
        $push_needed = true;
    }
    
    if (isset($_POST['edit_mac']) && isset($_POST['edit_alias'])) {
        $mac_to_edit = strtoupper(trim($_POST['edit_mac']));
        BLELogger::info("Edit device", ['mac' => $mac_to_edit, 'user_ip' => $_SERVER['REMOTE_ADDR']]);
        $new_alias = trim($_POST['edit_alias']);
        if (isset($known_devices[$mac_to_edit]) && !empty($new_alias)) {
            $known_devices[$mac_to_edit]['alias'] = $new_alias; 
            save_known_devices($KNOWN_DEVICES_FILE, $known_devices);
            $message = 'Alias für ' . htmlspecialchars($mac_to_edit) . ' aktualisiert.';
            $push_needed = true;
        }
    }
    
    if (isset($_POST['add_devices']) && is_array($_POST['add_devices'])) {
        $added_count = 0;
        foreach ($_POST['add_devices'] as $mac_to_add) {
            $mac_to_add = strtoupper(trim($mac_to_add));
            $alias_key = 'alias_' . str_replace(':', '', $mac_to_add);
            $alias = trim($_POST[$alias_key] ?? 'Neues Gerät');
            if (!isset($known_devices[$mac_to_add])) {
                $known_devices[$mac_to_add] = ['alias' => $alias, 'battery_scan' => false];
                $added_count++;
            }
        }
        if ($added_count > 0) {
            save_known_devices($KNOWN_DEVICES_FILE, $known_devices);
            BLELogger::info("Added devices", ['count' => $added_count, 'user_ip' => $_SERVER['REMOTE_ADDR']]);
            $message = $added_count . ' Gerät(e) erfolgreich hinzugefügt.';
            $push_needed = true;
        }
    }
    
    // Push an alle Clients wenn Änderung (nur im Master-Modus)
    if ($push_needed && $is_master_mode) {
        push_to_all_clients($CLIENTS_FILE, $KNOWN_DEVICES_FILE, $BATTERY_STATUS_FILE, $WEBHOOK_LOG_FILE);
    }
}

// --- AKTION: DISCOVER SCAN STARTEN (via GET-Parameter) - NUR WENN NICHT CLIENT-MODUS ---
$discover_results = null;
if (isset($_GET['action']) && $_GET['action'] === 'discover' && !$is_client_mode) {
    $discover_timeout = $config['discover']['timeout'] ?? 10;
    BLELogger::info("User triggered discover scan", ['timeout' => $discover_timeout, 'user_ip' => $_SERVER['REMOTE_ADDR']]);
    $SCRIPT_DIR = dirname($PYTHON_SCRIPT_PATH);
    $command = "cd " . escapeshellarg($SCRIPT_DIR) . " && sudo /usr/bin/python3 " . escapeshellarg($PYTHON_SCRIPT_PATH) . " discover -t " . intval($discover_timeout);
    
    $terminal_output = shell_exec($command . " 2>&1");
    
    if (file_exists($DISCOVER_FILE) && filesize($DISCOVER_FILE) > 0) {
        $discover_results = json_decode(file_get_contents($DISCOVER_FILE), true);
    }
}

// Batterie-Status laden
$battery_status = get_battery_status($BATTERY_STATUS_FILE);
$last_scan_file_content = file_exists($LAST_BATTERY_SCAN_FILE) ? trim(file_get_contents($LAST_BATTERY_SCAN_FILE)) : null;
?>
<!DOCTYPE html>
<html lang="de" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BLE Geräte</title>
    <?php if ($meta_refresh) echo $meta_refresh; ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css"/>
    <style>
        html { font-size: 14px; }
        body { padding-top: 20px; }
        button, input[type="text"], input[type="number"], input[type="submit"], [role="button"] {
            font-size: 0.9rem; height: auto; margin-bottom: 0;
            padding: 5px 8px;
        }
        
        [role="button"].primary, button.primary {
            background-color: #76b852; border-color: #76b852; color: #FFF;
        }
        [role="button"].primary:hover, button.primary:hover {
            background-color: #388e3c; border-color: #388e3c;
        }
        [role="button"].secondary, button.secondary {
            background-color: #ccc; border-color: #000; color: #FFF;
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
        
        .known-devices-grid {
            display: grid;
            gap: 10px;
        }
        .known-device-row {
            display: grid;
            grid-template-columns: 140px 100px 1fr auto;
            gap: 10px;
            align-items: center;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        .known-device-mac {
            font-family: monospace;
            font-size: 0.85rem;
        }
        .known-device-battery-info {
            padding: 5px;
            text-align: center;
            border-radius: 3px;
        }
        .known-device-edit-form input {
            margin: 0;
        }
        .known-device-buttons {
            display: flex;
            gap: 5px;
        }
        .known-device-buttons button, 
        .known-device-buttons a {
            padding: 5px 8px;
            min-width: auto;
        }
        
        /* Fix: Normalize forms within button container */
        .known-device-buttons form {
            margin: 0 !important;
            padding: 0 !important;
            display: inline-block !important;
        }
        
        .battery-button {
            min-width: 80px !important;
        }
        
        .discover-table td, .discover-table th {
            padding: 8px;
        }
        .discover-table tr.disabled {
            opacity: 0.5;
        }
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

        <?php if ($message): ?>
            <article style="background: #d4edda; color: #155724; padding: 1rem;">
                <?php echo $message; ?>
            </article>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            <article style="background: #f8d7da; color: #721c24; padding: 1rem;">
                <?php echo htmlspecialchars($error_message); ?>
            </article>
        <?php endif; ?>
        
        <?php if ($is_client_mode): ?>
            <article style="background: #d1ecf1; color: #0c5460; padding: 1rem; margin-bottom: 1rem;">
                ℹ️ <strong>Client-Modus:</strong> Geräteverwaltung ist deaktiviert. Daten werden vom Master synchronisiert. 
                Batterie-Scans sind weiterhin möglich.
            </article>
        <?php endif; ?>

        <section id="known-devices">
            <h2>Bekannte Geräte (<?php echo count($known_devices); ?>)</h2>
            
			<?php if ($last_scan_file_content && is_numeric($last_scan_file_content)): ?>
                <p><small>Letzter automatischer Batterie-Scan: <?php echo date('d.m.Y H:i:s', (int)$last_scan_file_content); ?></small></p>
            <?php endif; ?>
            
            <p>
                <a href="devices.php?action=read_all#known-devices" 
                   role="button" 
                   class="primary"
                   <?php if (isset($_GET['action']) && $_GET['action'] === 'read_all') echo 'aria-busy="true"'; ?>
                   onclick="this.setAttribute('aria-busy', 'true');">
                    <?php 
                        if (isset($_GET['action']) && $_GET['action'] === 'read_all') {
                            echo '🔄 Scanne...';
                        } else {
                            echo '🔋 Alle Batterien scannen';
                        }
                    ?>
                </a>
            </p>

            <div class="known-devices-grid">
                <?php if (empty($known_devices)): ?>
                    <p><em>Noch keine Geräte hinzugefügt.</em></p>
                <?php endif; ?>
                
                <?php foreach ($known_devices as $mac => $device_info): ?>
                    <?php
                        $alias = $device_info['alias'];
                        $battery_scan_enabled = $device_info['battery_scan'];
                        $row_anchor_id = 'device-' . str_replace(':', '', $mac);
                        $form_id = 'form-' . str_replace(':', '', $mac);
                        
                        $battery_perc = 'N/A';
                        $last_seen_text = 'Noch nicht gescannt';
                        $status_info = $battery_status[$mac] ?? null;
                        $status_color = '#999'; 
                        
                        if ($battery_scan_enabled) {
                            if ($status_info) {
                                $battery_perc = $status_info['battery_percent'];
                                $last_seen_text = date('d.m H:i', $status_info['timestamp']);
                                if ($status_info['status'] === 'online') {
                                    if ($battery_perc > 70) $status_color = 'green';
                                    elseif ($battery_perc > 30) $status_color = 'orange';
                                    else $status_color = 'red';
                                } else {
                                    $battery_perc = 'N/A';
                                    $status_color = 'red'; 
                                }
                            }
                        } else {
                            $battery_perc = '---';
                            $last_seen_text = 'Deaktiviert';
                            $status_color = '#ccc'; 
                        }
                    ?>
                    <article class="known-device-row" id="<?php echo $row_anchor_id; ?>"> 
                        
                        <span class="known-device-mac"><?php echo htmlspecialchars($mac); ?></span>
                        
                        <div class="known-device-battery-info" style="border-left: 3px solid <?php echo $status_color; ?>;">
                            <strong><?php echo htmlspecialchars($battery_perc); ?><?php echo is_numeric($battery_perc) ? '%' : ''; ?></strong>
                            <small><?php echo $last_seen_text; ?></small>
                        </div>
                        
                        <?php if ($is_client_mode): ?>
                            <!-- Client-Modus: Nur Anzeige des Alias -->
                            <div style="padding: 5px;">
                                <strong><?php echo htmlspecialchars($alias); ?></strong>
                            </div>
                        <?php else: ?>
                            <!-- Normaler Modus: Editierbar -->
                            <form action="devices.php" method="POST" class="known-device-edit-form" id="<?php echo $form_id; ?>">
                                <input type="hidden" name="edit_mac" value="<?php echo htmlspecialchars($mac); ?>">
                                <input type="text" name="edit_alias" value="<?php echo htmlspecialchars($alias); ?>" required>
                            </form>
                        <?php endif; ?>
                        
                        <div class="known-device-buttons">
                            
                            <?php if (!$is_client_mode): ?>
                                <button type="submit" class="secondary save-button" title="Alias speichern" form="<?php echo $form_id; ?>">💾</button>
                            <?php endif; ?>
                            
                            <form action="devices.php#<?php echo $row_anchor_id; ?>" method="GET" style="display: inline;">
                                <input type="hidden" name="action" value="read_single">
                                <input type="hidden" name="mac" value="<?php echo htmlspecialchars($mac); ?>">
                                <button type="submit"
                                   class="secondary outline single-scan-button" 
                                   title="Batterie jetzt sofort lesen"
                                   <?php if (isset($_GET['action']) && $_GET['action'] === 'read_single' && ($_GET['mac'] ?? '') === $mac) echo 'aria-busy="true"'; ?>
                                   onclick="this.setAttribute('aria-busy', 'true');">
                                   🔄
                                </button>
                            </form>
                            
                            <form action="devices.php" method="GET">
                                <?php if (!$is_client_mode): ?>
								<input type="hidden" name="toggle_battery" value="<?php echo htmlspecialchars($mac); ?>">
                                <button type="submit"
                                   class="battery-button <?php echo $battery_scan_enabled ? 'primary' : 'secondary outline'; ?>" 
                                   title="<?php echo $battery_scan_enabled ? 'Batterie-Scan ist AKTIV' : 'Batterie-Scan ist INAKTIV'; ?>">
                                   <?php echo $battery_scan_enabled ? '🔋 Ein' : '🔋 Aus'; ?>
                                </button>
								<?php endif; ?>
                            </form>

                            <?php if (!$is_client_mode): ?>
                                <form action="devices.php" method="POST" class="known-device-delete-form" onsubmit="return confirm('Gerät <?php echo htmlspecialchars($alias); ?> wirklich löschen?')">
                                    <input type="hidden" name="delete_mac" value="<?php echo htmlspecialchars($mac); ?>">
                                    <button type="submit" class="contrast outline delete-button" title="Löschen">🗑️</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
 <hr>
        <?php if (!$is_client_mode): ?>
           

            <section id="discover">
                <h2>Geräte entdecken</h2>
                <p>
                    <a href="devices.php?action=discover#discover-results" 
                       role="button" 
                       class="primary" 
                       <?php if (isset($_GET['action']) && $_GET['action'] === 'discover') echo 'aria-busy="true"'; ?>
                       onclick="this.setAttribute('aria-busy', 'true'); this.textContent = 'Scanne... Bitte Warten...';">
                        <?php 
                            if (isset($_GET['action']) && $_GET['action'] === 'discover') {
                                echo 'Scanne... Bitte Warten...';
                            } else {
                                echo '🚀 Starte ' . ($config['discover']['timeout'] ?? 10) . '-Sekunden-Scan...';
                            }
                        ?>
                    </a>
                </p>

                <?php if ($discover_results && !empty($discover_results['devices'])): ?>
                    <h3 id="discover-results">Scan-Ergebnisse (<?php echo $discover_results['devices_found']; ?> Geräte)</h3>
                    
                    <form action="devices.php" method="POST">
                        <figure>
                            <table role="grid" class="discover-table">
                                <thead>
                                    <tr>
                                        <th scope="col"><input type="checkbox" onclick="toggleAll(this)"></th>
                                        <th scope="col">MAC-Adresse</th>
                                        <th scope="col">Name (vom Gerät)</th>
                                        <th scope="col">RSSI</th>
                                        <th scope="col">Wunschname (Alias)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($discover_results['devices'] as $mac => $dev): ?>
                                    <?php $is_known = isset($known_devices[strtoupper($mac)]); ?>
                                    <tr <?php echo $is_known ? 'class="disabled"' : ''; ?>>
                                        <td>
                                            <input type="checkbox" name="add_devices[]" value="<?php echo htmlspecialchars($mac); ?>"
                                                   aria-label="Gerät auswählen" <?php echo $is_known ? 'disabled' : ''; ?>>
                                        </td>
                                        <td><code><?php echo htmlspecialchars($mac); ?></code></td>
                                        <td><?php echo htmlspecialchars($dev['name']); ?></td>
                                        <td><?php echo htmlspecialchars($dev['rssi']); ?></td>
                                        <td>
                                            <?php if ($is_known): ?>
                                                <em>(Bereits bekannt)</em>
                                            <?php else: ?>
                                                <input type="text" 
                                                       name="alias_<?php echo str_replace(':', '', htmlspecialchars($mac)); ?>" 
                                                       value="<?php echo htmlspecialchars($dev['name'] !== 'Unknown' ? $dev['name'] : ''); ?>"
                                                       aria-label="Wunschname">
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </figure>
                        <button type="submit" class="primary">Markierte Geräte hinzufügen</button>
                    </form>
                <?php elseif (isset($_GET['action'])): ?>
                    <p>Keine neuen Geräte gefunden.</p>
                <?php endif; ?>
            </section>
        <?php endif; ?>

    </main>

    <script>
        function toggleAll(source) {
            let checkboxes = document.querySelectorAll('.discover-table input[type="checkbox"]:not([disabled])');
            for (let i = 0, n = checkboxes.length; i < n; i++) {
                checkboxes[i].checked = source.checked;
            }
        }
    </script>
</body>
</html>