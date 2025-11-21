<?php
require_once __DIR__ . '/php_logger.php';

$INI_FILE = '/var/www/html/ble/config.ini';
$config = parse_ini_file($INI_FILE, true, INI_SCANNER_RAW);
$current_mode = $config['MasterClient']['mode'] ?? 'standalone';
$is_client_mode = ($current_mode === 'client');
$is_master_mode = ($current_mode === 'master');

$current_hostname = gethostname();



// Verfügbare Log-Dateien
$log_files = [
    'ble_tool.log' => 'Haupt-Log (Python)',
    'bluetooth.log' => 'Bluetooth-Operationen',
    'scan.log' => 'Scan-Ergebnisse',
    'service_restart.log' => 'Service-Neustarts',
    'ble_errors.log' => 'Python Fehler',
    'php.log' => 'PHP Log',
    'php_errors.log' => 'PHP Fehler',
    'webhook.log' => 'Master-Client Webhook-Log'
];

$selected_log = $_GET['log'] ?? 'ble_tool.log';
$lines = isset($_GET['lines']) ? intval($_GET['lines']) : 100;
$auto_refresh = isset($_GET['auto_refresh']) && $_GET['auto_refresh'] === '1';

// --- NEU: Log-Datei löschen ---
if (isset($_GET['action']) && $_GET['action'] === 'clear' && isset($_GET['log'])) {
    $log_to_clear = $_GET['log'];
    
    // Validierung
    if (isset($log_files[$log_to_clear])) {
        $log_path_to_clear = "/var/log/ble/$log_to_clear";
        
        if (file_exists($log_path_to_clear)) {
            // Leere die Log-Datei mit sudo truncate
            $cmd = "sudo /usr/bin/truncate -s 0 " . escapeshellarg($log_path_to_clear) . " 2>&1";
            $output = shell_exec($cmd);
            
            if ($output === null || trim($output) === '') {
                // Erfolgreich geleert
                BLELogger::warning("Log file cleared", ['file' => $log_to_clear, 'client_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
            } else {
                // Fehler beim Leeren
                BLELogger::error("Failed to clear log file", ['file' => $log_to_clear, 'error' => $output, 'client_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
            }
        }
    }
    
    // Redirect zurück ohne action-Parameter
    header("Location: logs.php?log=$log_to_clear&lines=$lines");
    exit;
}
// --- ENDE NEU ---

// Validierung
if (!isset($log_files[$selected_log])) {
    $selected_log = 'ble_tool.log';
}
if ($lines < 10) $lines = 10;
if ($lines > 1000) $lines = 1000;

$log_path = "/var/log/ble/$selected_log";
$log_content = '';

if (file_exists($log_path)) {
    $file_size = filesize($log_path);
    
    if ($file_size > 0) {
        // Letzte N Zeilen lesen (effizient)
        $log_content = shell_exec("tail -n $lines " . escapeshellarg($log_path));
    } else {
        // Datei existiert, ist aber leer
        $log_content = "(Log-Datei ist leer - noch keine Einträge)";
    }
} else {
    // Datei existiert nicht
    $log_content = "(Log-Datei noch nicht erstellt - wird beim ersten Log-Eintrag angelegt)";
}

BLELogger::info("Log-Viewer accessed", ['log' => $selected_log, 'lines' => $lines]);
?>
<!DOCTYPE html>
<html lang="de" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BLE Logs</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css"/>
    <style>
        /* === IDENTISCHES STYLING WIE config.php === */
        html { font-size: 14px; }
        body { padding-top: 20px; }
        button, input[type="text"], input[type="number"], input[type="submit"], [role="button"], input[type="password"], input[type="time"], select {
            font-size: 0.9rem; height: auto; margin-bottom: 0;
            box-sizing: border-box; padding-top: 5px; padding-bottom: 5px;
            padding-left: 8px; padding-right: 8px;
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
        [role="button"].secondary:hover, button.secondary:hover {
            background-color: #0d47a1; border-color: #0d47a1;
        }
        
        nav, section { margin-bottom: 1rem; }
        .container { max-width: 960px; }
        
        nav ul:first-child li { 
            display: flex; align-items: center; gap: 10px; padding: 0;
        }
        .nav-logo {
            height: 100px; width: 100px; border-radius: 5px; flex-shrink: 0; 
        }
        .nav-title-group {
             display: flex; flex-direction: column;
        }
        nav h1 {
            font-size: 1.5rem; line-height: 1.1; margin: 0; color: var(--pico-h1-color);
        }
        nav h3 {
            font-size: 1rem; color: var(--pico-muted-color, #555); line-height: 1; margin: 0; font-weight: 400; 
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
        
        /* === LOG-SPEZIFISCHES STYLING === */
        
        /* Formular-Grid für Log-Auswahl */
        .log-controls {
            display: grid;
            grid-template-columns: 1fr 1fr auto auto auto;
            gap: 10px;
            align-items: end;
            margin-bottom: 1rem;
        }
        
        .log-controls > div {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .log-controls label {
            font-weight: bold;
            font-size: 0.9rem;
            margin-bottom: 0;
        }
        
        /* WICHTIG: Alle Buttons in log-controls gleich groß */
        .log-controls button {
            white-space: nowrap;
            min-width: 100px;
            height: auto;
            padding: 8.5px 12px !important;  /* Exakt wie andere Pico-Buttons */
            margin: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            line-height: 1.5;
        }
        
        /* Log-Anzeige */
        #log-content {
            background-color: #1e1e1e;
            color: #d4d4d4;
            padding: 15px;
            border-radius: 5px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.4;
            overflow-x: auto;
            white-space: pre;
            max-height: 600px;
            overflow-y: auto;
        }
        
        /* Syntax-Highlighting */
        .log-info { color: #4ec9b0; }
        .log-warning { color: #dcdcaa; font-weight: bold; }
        .log-error { color: #f48771; font-weight: bold; }
        .log-debug { color: #858585; }
        .log-critical { color: #ff6b6b; font-weight: bold; }
        
        /* Service-Restart-Log spezifische Highlighting */
        .log-success { color: #4caf50; font-weight: bold; }
        .log-failed { color: #f44336; font-weight: bold; }
        .log-timestamp { color: #9cdcfe; }
        .log-ip { color: #ce9178; }
        
        /* Responsive Anpassung */
        @media (max-width: 768px) {
            .log-controls {
                grid-template-columns: 1fr;
            }
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

        <h2>Log-Viewer</h2>

        <form method="GET" action="logs.php" id="log-form">
            <div class="log-controls">
                <div>
                    <label for="log">Log-Datei:</label>
                    <select name="log" id="log" onchange="document.getElementById('log-form').submit()">
                        <?php foreach ($log_files as $file => $description): ?>
                            <option value="<?php echo $file; ?>" <?php echo ($selected_log === $file) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($description); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label for="lines">Anzahl Zeilen:</label>
                    <input type="number" name="lines" id="lines" value="<?php echo $lines; ?>" min="10" max="1000">
                </div>
                
                <div style="align-self: end;">
                    <button type="submit" class="primary">Aktualisieren</button>
                </div>
                
                <div style="align-self: end;">
                    <button type="button" class="secondary" id="auto-refresh-btn">Auto-Refresh</button>
                </div>
                
                <div style="align-self: end;">
                    <a href="logs.php?action=clear&log=<?php echo urlencode($selected_log); ?>&lines=<?php echo $lines; ?>" 
                       class="contrast" 
                       role="button"
                       onclick="return confirm('Möchten Sie die Log-Datei \'<?php echo htmlspecialchars($log_files[$selected_log]); ?>\' wirklich leeren?')">
                       🗑️ Leeren
                    </a>
                </div>
            </div>
        </form>

        <article>
            <header>
                <strong><?php echo $log_files[$selected_log]; ?></strong>
                <small style="float: right;">Letzte <?php echo $lines; ?> Zeilen</small>
            </header>
            <div id="log-content"><?php 
                // Syntax-Highlighting
                $highlighted = htmlspecialchars($log_content);
                
                // Standard Log-Level Highlighting
                $highlighted = preg_replace('/\| INFO\s+\|/', '| <span class="log-info">INFO    </span>|', $highlighted);
                $highlighted = preg_replace('/\| WARNING\s+\|/', '| <span class="log-warning">WARNING </span>|', $highlighted);
                $highlighted = preg_replace('/\| ERROR\s+\|/', '| <span class="log-error">ERROR   </span>|', $highlighted);
                $highlighted = preg_replace('/\| DEBUG\s+\|/', '| <span class="log-debug">DEBUG   </span>|', $highlighted);
                $highlighted = preg_replace('/\| CRITICAL\s+\|/', '| <span class="log-critical">CRITICAL</span>|', $highlighted);
                
                // Service-Restart-Log spezifisches Highlighting
                if ($selected_log === 'service_restart.log') {
                    // Timestamps hervorheben
                    $highlighted = preg_replace('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', '[<span class="log-timestamp">$1</span>]', $highlighted);
                    
                    // ERFOLG grün hervorheben
                    $highlighted = preg_replace('/Status: (ERFOLG)/', 'Status: <span class="log-success">$1</span>', $highlighted);
                    
                    // FEHLER rot hervorheben
                    $highlighted = preg_replace('/Status: (FEHLER)/', 'Status: <span class="log-failed">$1</span>', $highlighted);
                    
                    // IP-Adressen hervorheben
                    $highlighted = preg_replace('/(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/', '<span class="log-ip">$1</span>', $highlighted);
                    
                    // Return Codes hervorheben
                    $highlighted = preg_replace('/Return Code: (\d+)/', 'Return Code: <span class="log-info">$1</span>', $highlighted);
                }
                
                // Webhook-Log spezifisches Highlighting
                if ($selected_log === 'webhook.log') {
                    // JSON-Struktur parsen und formatieren
                    $lines = explode("\n", $highlighted);
                    $formatted_lines = [];
                    
                    foreach ($lines as $line) {
                        if (empty(trim($line))) continue;
                        
                        $entry = json_decode($line, true);
                        if ($entry) {
                            $timestamp = date('d.m.Y H:i:s', $entry['timestamp']);
                            $client = htmlspecialchars($entry['client']);
                            $action = htmlspecialchars($entry['action']);
                            $success = $entry['success'] ? 'ERFOLG' : 'FEHLER';
                            $ip = htmlspecialchars($entry['ip'] ?? 'unknown');
                            $http_code = isset($entry['http_code']) ? ' | HTTP: ' . $entry['http_code'] : '';
                            
                            $status_class = $entry['success'] ? 'log-success' : 'log-failed';
                            
                            $formatted = sprintf(
                                '<span class="log-timestamp">%s</span> | Client: <strong>%s</strong> | Aktion: %s%s | <span class="%s">%s</span> | IP: <span class="log-ip">%s</span>',
                                $timestamp,
                                $client,
                                $action,
                                $http_code,
                                $status_class,
                                $success,
                                $ip
                            );
                            
                            $formatted_lines[] = $formatted;
                        } else {
                            $formatted_lines[] = htmlspecialchars($line);
                        }
                    }
                    
                    $highlighted = implode("\n", $formatted_lines);
                }
                
                echo $highlighted;
            ?></div>
        </article>

        <script>
            // Auto-Refresh Funktion
            const urlParams = new URLSearchParams(window.location.search);
            const autoRefreshActive = urlParams.get('auto_refresh') === '1';
            
            const autoRefreshBtn = document.getElementById('auto-refresh-btn');
            const logContent = document.getElementById('log-content');
            
            // Initialer State vom URL-Parameter
            if (autoRefreshActive) {
                autoRefreshBtn.textContent = 'Stop Auto-Refresh';
                autoRefreshBtn.classList.remove('secondary');
                autoRefreshBtn.classList.add('primary');
                
                // Starte Auto-Refresh nach 5 Sekunden
                setTimeout(function() {
                    window.location.reload();
                }, 5000);
            }
            
            autoRefreshBtn.addEventListener('click', function() {
                const currentUrl = new URL(window.location.href);
                
                if (currentUrl.searchParams.get('auto_refresh') === '1') {
                    // Deaktiviere Auto-Refresh
                    currentUrl.searchParams.delete('auto_refresh');
                } else {
                    // Aktiviere Auto-Refresh
                    currentUrl.searchParams.set('auto_refresh', '1');
                }
                
                window.location.href = currentUrl.toString();
            });
            
            // Auto-Scroll nach unten
            logContent.scrollTop = logContent.scrollHeight;
        </script>
    </main>
</body>
</html>
