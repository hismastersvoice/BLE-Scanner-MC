<?php
require_once __DIR__ . '/php_logger.php'; 
// --- PFADE ---
$INI_FILE = '/var/www/html/ble/config.ini';
$PYTHON_SCRIPT_PATH = '/var/www/html/ble/ble_tool.py';
$KNOWN_DEVICES_FILE = '/var/www/html/ble/known_devices.txt';
$DISCOVER_FILE = '/var/www/html/ble/scan_results.json';

// --- TOOLTIP-WÖRTERBUCH ---
$tooltips = [
    'General' => [
        'report_offline_battery' => 'Soll bei einem FEHLGESCHLAGENEN Batterie-Scan (offline) eine Nachricht (mit Batterie 0) gesendet werden? (Ja/Nein)',
        'battery_retries' => 'Anzahl der Versuche (1 = kein Wiederholungsversuch), die Batterie zu lesen, falls das Gerät nicht sofort antwortet.',
        'battery_retry_delay' => 'Zeit in Sekunden, die das Skript zwischen den Wiederholungsversuchen wartet.',
        'battery_connect_timeout' => 'Maximale Zeit in Sekunden, die das Skript auf eine Verbindung wartet (Standard: 10).',
        'battery_post_connect_delay' => 'Künstliche Pause (in Sekunden) NACH der Verbindung, aber VOR dem Auslesen. Wichtig für "träge" Geräte (Standard: 1).',
    ],
    'UDP' => [
        'enabled' => 'Schaltet den UDP-Versand global an (true) oder aus (false).',
        'host' => 'Die IP-Adresse oder der Hostname deines UDP-Zielservers (z.B. Loxone Miniserver).',
        'port' => 'Der UDP-Port, an den die Daten gesendet werden sollen (z.B. 7002).',
    ],
    'MQTT' => [
        'enabled' => 'Schaltet den MQTT-Versand global an (true) oder aus (false).',
        'broker' => 'Die IP-Adresse oder der Hostname deines MQTT-Brokers (z.B. Mosquitto, ioBroker).',
        'port' => 'Der MQTT-Port deines Brokers (Standard: 1883).',
        'scan_topic' => 'Das MQTT-Basis-Topic, unter dem "Online/Offline"-Meldungen (vom Scan) veröffentlicht werden.',
        'battery_topic' => 'Das MQTT-Basis-Topic, unter dem Batterie-Berichte (vom Read) veröffentlicht werden.',
        'username' => '(Optional) Benutzername für die MQTT-Broker-Anmeldung.',
        'password' => '(Optional) Passwort für die MQTT-Broker-Anmeldung.',
    ],
    'discover' => [
        'timeout' => 'Dauer des "Discover"-Scans in Sekunden (der Scan, der alle Geräte findet).'
    ],
    'Cron' => [
        'battery_scan_time' => 'Uhrzeit (HH:MM), zu der der tägliche Batterie-Scan startet.',
    ],
    'Logging' => [
        'log_level' => 'Log-Level für Log-Dateien: DEBUG (alles), INFO (normal), WARNING (Warnungen), ERROR (nur Fehler), CRITICAL (kritisch)',
        'console_level' => 'Log-Level für die Konsolen-Ausgabe beim Ausführen des Scripts',
        'bleak_level' => 'Log-Level für die Bleak-Bluetooth-Bibliothek (empfohlen: ERROR, da sehr verbose)'
    ],
    'MasterClient' => [
        'mode' => 'Modus: standalone (Standard), master (verteilt Daten an Clients), client (empfängt Daten vom Master)',
        'master_url' => '(Nur im Client-Modus) URL des Master-Servers (z.B. http://192.168.1.100/ble)',
        'api_key' => 'API-Key: Im Client-Modus vom Master kopieren und hier einfügen. Im Master-Modus wird automatisch ein Key generiert.',
        'fallback_poll_interval' => '(Nur im Client-Modus) Fallback-Poll-Intervall in Sekunden. 0 = deaktiviert, 300 = 5 Min, 1800 = 30 Min, etc.'
    ]
];

// --- NEU: Log-Level-Optionen ---
$log_level_options = ['DEBUG', 'INFO', 'WARNING', 'ERROR', 'CRITICAL'];
$mode_options = ['standalone', 'master', 'client'];

// --- HELFER-FUNKTION ---
function write_ini_file($file, $array) {
    $content = "";
    foreach ($array as $section => $values) {
        $content .= "[$section]\n";
        foreach ($values as $key => $value) {
            $content .= "$key = $value\n";
        }
        $content .= "\n";
    }
    if (file_put_contents($file, $content) === false) {
        return false;
    }
    return true;
}

function restartBleService() {
    $logFile = '/var/log/ble/service_restart.log';
    $timestamp = date('Y-m-d H:i:s');
    $user = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
    
    BLELogger::info("Service restart requested", [ 
        'user_ip' => $user,
        'timestamp' => $timestamp
    ]);
    
    file_put_contents(
        $logFile, 
        "[$timestamp] Service-Neustart angefordert von: $user\n", 
        FILE_APPEND
    );
    
    $output = [];
    $return_var = 0;
    exec('sudo /bin/systemctl restart ble_tool.service 2>&1', $output, $return_var);
    
    $status = $return_var === 0 ? 'ERFOLG' : 'FEHLER';
    file_put_contents(
        $logFile, 
        "[$timestamp] Status: $status (Return Code: $return_var)\n", 
        FILE_APPEND
    );
    
    if ($return_var === 0) {
        BLELogger::info("BLE service restarted successfully", [
            'return_code' => $return_var
        ]);
        
        return [
            'success' => true,
            'message' => 'BLE Service erfolgreich neu gestartet'
        ];
    } else {
        $error_output = implode("\n", $output);
        
        BLELogger::error("Failed to restart BLE service", [
            'return_code' => $return_var,
            'output' => $error_output
        ]);
        
        file_put_contents(
            $logFile, 
            "[$timestamp] Fehlerausgabe: $error_output\n", 
            FILE_APPEND
        );
        
        return [
            'success' => false,
            'message' => 'Fehler beim Neustart: ' . $error_output
        ];
    }
}

// --- NEU: API-Key generieren ---
function generate_api_key() {
    return bin2hex(random_bytes(32));
}

// --- NEU: Cron-Job für Fallback-Poll aktualisieren ---
function update_sync_cron($poll_interval, $mode) {
    $WEB_USER = 'www-data';
    $CRON_FILTER = "sync_client.php";
    
    // Wenn Client-Modus UND Interval > 0, Cron setzen
    if ($mode === 'client' && $poll_interval > 0) {
        $interval_minutes = max(1, intval($poll_interval / 60));
        $CRON_COMMAND = "*/$interval_minutes * * * * /usr/bin/php /var/www/html/ble/sync_client.php > /dev/null 2>&1";
        $NEW_CRON_JOB = $CRON_COMMAND;
        
        $cmd_cron = "(sudo /usr/bin/crontab -u {$WEB_USER} -l 2>/dev/null | grep -v " . escapeshellarg($CRON_FILTER) . "; echo " . escapeshellarg($NEW_CRON_JOB) . ") | sudo /usr/bin/crontab -u {$WEB_USER} -";
    } else {
        // Cron entfernen
        $cmd_cron = "sudo /usr/bin/crontab -u {$WEB_USER} -l 2>/dev/null | grep -v " . escapeshellarg($CRON_FILTER) . " | sudo /usr/bin/crontab -u {$WEB_USER} -";
    }
    
    shell_exec($cmd_cron);
}

// --- LOGIK: SPEICHERN ---
$config = [];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['config'])) {
    $config_data = $_POST['config'];
    $old_config = @parse_ini_file($INI_FILE, true, INI_SCANNER_RAW) ?? [];
    $old_mode = $old_config['MasterClient']['mode'] ?? 'standalone';
    
    foreach ($config_data as $section => &$values) {
        foreach ($values as $key => &$value) {
            if ($value === 'true') $value = 'true';
            if ($value === 'false') $value = 'false';
            if ($section === 'Cron' && $key === 'battery_scan_time' && !preg_match('/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/', $value)) {
                 $value = '03:00';
            }
        }
    }
    
    
    if (write_ini_file($INI_FILE, $config_data)) {
        BLELogger::info("Configuration saved successfully");
        
        // Cron-Job für Batterie-Scan aktualisieren
        $cron_update_context = stream_context_create(['http' => ['timeout' => 5]]);
        @file_get_contents("http://localhost/ble/action.php?cmd=update_cron", false, $cron_update_context);
        
        // Cron-Job für Sync aktualisieren (nur wenn Client-Modus)
        $poll_interval = intval($config_data['MasterClient']['fallback_poll_interval'] ?? 1800);
        update_sync_cron($poll_interval, $new_mode);
        
        // BLE Service neu starten
        $restart_result = restartBleService();
        
        if ($restart_result['success']) {
            $message .= '<div style="color: green;">✅ Konfiguration gespeichert! Service wurde neu gestartet.</div>';
        } else {
            $message .= '<div style="color: orange;">⚠️ Konfiguration gespeichert, aber Service-Neustart fehlgeschlagen:<br>' . 
                       htmlspecialchars($restart_result['message']) . '</div>';
        }
    } else {
        BLELogger::error("Failed to write config.ini", ['file' => $INI_FILE]);
        $message = '<div style="color: red;">❌ Fehler beim Schreiben!</div>';
    }
}


// --- LOGIK: INI-DATEI LESEN ---
$config = @parse_ini_file($INI_FILE, true, INI_SCANNER_RAW) ?? [];

// --- SICHERSTELLEN, DASS ALLE TOOLTIP-WERTE EXISTIEREN ---
foreach ($tooltips as $section => $keys) {
    if (!isset($config[$section])) {
        $config[$section] = [];
    }
    foreach ($keys as $key => $tip) {
        if (!isset($config[$section][$key])) {
            if (in_array(strtolower($key), ['enabled', 'report_offline_battery'])) {
                $default = 'false';
            } elseif (strtolower($key) === 'battery_scan_time') {
                $default = '03:00';
            } elseif ($key === 'log_level' || $key === 'bleak_level') {
                $default = 'ERROR';
            } elseif ($key === 'console_level') {
                $default = 'INFO';
            } elseif ($key === 'mode') {
                $default = 'standalone';
            } elseif ($key === 'fallback_poll_interval') {
                $default = '1800';
            } else {
                $default = '0';
            }
            $config[$section][$key] = $default;
        }
    }
}

$current_hostname = gethostname();
$current_mode = $config['MasterClient']['mode'] ?? 'standalone';
$cron_time_hhmm = $config['Cron']['battery_scan_time'] ?? '03:00';
if (!preg_match('/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/', $cron_time_hhmm)) {
    $cron_time_hhmm = '03:00';
}
?>
<!DOCTYPE html>
<html lang="de" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BLE Konfiguration</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css"/>
    <style>
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
        
        fieldset { margin-bottom: 15px; }
        fieldset > div {
            display: grid; grid-template-columns: 200px 1fr; 
            gap: 10px; margin-bottom: 8px; align-items: center;
        }
        label { 
            font-weight: bold; text-decoration: underline dashed 1px #888; cursor: help; 
        }
        fieldset div.radio-group label {
            font-weight: normal; margin-right: 15px; text-decoration: none; cursor: default;
        }
        fieldset div.radio-group input {
             margin-bottom: 0;
        }
        
        .mode-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 3px;
            font-size: 0.9rem;
            font-weight: bold;
            margin-left: 10px;
        }
        .mode-badge.standalone { background: #999; color: white; }
        .mode-badge.master { background: #2196F3; color: white; }
        .mode-badge.client { background: #4CAF50; color: white; }
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
        <h2>Globale Konfiguration</h2>

        <?php if ($message): ?>
            <article role="alert" class="success-message" style="padding: 1rem;">
                <?php echo $message; ?>
            </article>
        <?php endif; ?>

        <form action="config.php" method="POST">
            <?php foreach ($config as $section_name => $section): ?>
                <fieldset>
                    <legend><strong>[<?php echo htmlspecialchars($section_name); ?>]</strong></legend>
                    
                    <?php foreach ($section as $key => $value): ?>
                        <?php 
                            // Client-spezifische Felder nur im Client-Modus anzeigen
                            $is_client_only = ($section_name === 'MasterClient' && in_array($key, ['master_url', 'api_key', 'fallback_poll_interval']));
                            $field_class = $is_client_only ? 'client-only-field' : '';
                            // Felder nur verstecken wenn NICHT im Client-Modus
                            $should_hide = $is_client_only && $current_mode !== 'client';
                        ?>
                        <div class="<?php echo $field_class; ?>"<?php if ($should_hide): ?> style="display: none;"<?php endif; ?>>
                            <?php
                                $tooltip_text = $tooltips[$section_name][$key] ?? "Keine Beschreibung für '[$section_name] $key' vorhanden.";
                            ?>
                            
                            <label for="<?php echo $key; ?>" title="<?php echo htmlspecialchars($tooltip_text); ?>">
                                <?php echo htmlspecialchars($key); ?>:
                            </label>
                            
                            <?php if (strtolower($key) === 'battery_scan_time'): ?>
                                <!-- Zeitfeld -->
                                <input type="time" id="<?php echo $key; ?>" name="config[<?php echo $section_name; ?>][<?php echo $key; ?>]" value="<?php echo htmlspecialchars($cron_time_hhmm); ?>" required>
                            
                            <?php elseif ($key === 'mode'): ?>
                                <!-- Mode-Auswahl -->
                                <select id="<?php echo $key; ?>" name="config[<?php echo $section_name; ?>][<?php echo $key; ?>]" onchange="toggleMasterClientFields(this.value)">
                                    <?php foreach ($mode_options as $mode): ?>
                                        <option value="<?php echo $mode; ?>" <?php echo ($value === $mode) ? 'selected' : ''; ?>>
                                            <?php echo ucfirst($mode); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            
                            <?php elseif (in_array($key, ['log_level', 'console_level', 'bleak_level'])): ?>
                                <!-- Dropdown für Log-Level -->
                                <select id="<?php echo $key; ?>" name="config[<?php echo $section_name; ?>][<?php echo $key; ?>]">
                                    <?php foreach ($log_level_options as $level): ?>
                                        <option value="<?php echo $level; ?>" <?php echo (strtoupper($value) === $level) ? 'selected' : ''; ?>>
                                            <?php echo $level; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            
                            <?php elseif ($key === 'api_key' && $section_name === 'MasterClient'): ?>
                                <!-- API-Key Input (editierbar) -->
                                <input type="text" 
                                       name="config[<?php echo $section_name; ?>][<?php echo $key; ?>]" 
                                       value="<?php echo htmlspecialchars($value); ?>" 
                                       placeholder="API-Key vom Master hier einfügen"
                                       style="width: 100%;">
                            
                            <?php elseif (strtolower($value) === 'true' || strtolower($value) === 'false'): ?>
                                <!-- Boolean-Felder (Radio-Buttons) -->
                                <div class="radio-group">
                                    <input type="radio" id="<?php echo $key; ?>_true" name="config[<?php echo $section_name; ?>][<?php echo $key; ?>]" value="true" <?php echo (strtolower($value) === 'true') ? 'checked' : ''; ?>>
                                    <label for="<?php echo $key; ?>_true">Ja (True)</label>
                                    
                                    <input type="radio" id="<?php echo $key; ?>_false" name="config[<?php echo $section_name; ?>][<?php echo $key; ?>]" value="false" <?php echo (strtolower($value) === 'false') ? 'checked' : ''; ?>>
                                    <label for="<?php echo $key; ?>_false">Nein (False)</label>
                                </div>
                            
                            <?php elseif (is_numeric($value)): ?>
                                <!-- Zahlenfeld -->
                                <input type="number" id="<?php echo $key; ?>" name="config[<?php echo $section_name; ?>][<?php echo $key; ?>]" value="<?php echo htmlspecialchars($value); ?>" step="any">
                            
                            <?php elseif (strtolower($key) === 'password'): ?>
                                <!-- Passwortfeld -->
                                <input type="password" id="<?php echo $key; ?>" name="config[<?php echo $section_name; ?>][<?php echo $key; ?>]" value="<?php echo htmlspecialchars($value); ?>" autocomplete="off">
                            
                            <?php else: ?>
                                <!-- Standard-Textfeld -->
                                <input type="text" id="<?php echo $key; ?>" name="config[<?php echo $section_name; ?>][<?php echo $key; ?>]" value="<?php echo htmlspecialchars($value); ?>">
                            
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    
                </fieldset>
            <?php endforeach; ?>
            
            <button type="submit" class="primary" style="width: 100%;">💾 Konfiguration speichern</button>
        </form>

    </main>

    <script>
        function toggleMasterClientFields(mode) {
            // Client-spezifische Felder nur im Client-Modus anzeigen
            const clientOnlyFields = document.querySelectorAll('.client-only-field');
            clientOnlyFields.forEach(field => {
                if (mode === 'client') {
                    field.style.display = '';
                } else {
                    field.style.display = 'none';
                }
            });
        }
        
        // Initial call
        toggleMasterClientFields('<?php echo $current_mode; ?>');
    </script>
</body>
</html>