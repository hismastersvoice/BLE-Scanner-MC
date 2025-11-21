<?php
require_once __DIR__ . '/php_logger.php';
// --- PFADE ---
$SET_HOST_SCRIPT = '/usr/local/bin/ble_set_hostname.sh';
$SET_NET_SCRIPT = '/usr/local/bin/ble_set_network.sh';
$REBOOT_SCRIPT = '/usr/local/bin/ble_reboot.sh'; 
$INTERFACES_FILE = '/etc/network/interfaces'; // Die KORREKTE Datei
$INI_FILE = '/var/www/html/ble/config.ini';

$message = '';
$error_message = '';
$reboot_needed = false; 

// --- AKTUELLE WERTE LESEN ---
$config = parse_ini_file($INI_FILE, true, INI_SCANNER_RAW);
$current_mode = $config['MasterClient']['mode'] ?? 'standalone';
$is_client_mode = ($current_mode === 'client');
$is_master_mode = ($current_mode === 'master');

$current_hostname = gethostname();
$current_ip = $_SERVER['SERVER_ADDR'] ?? 'Unbekannt'; 
session_start(); 

// --- HELFER: Liest die aktuelle Konfig aus /etc/network/interfaces ---
function get_interface_config_from_interfaces($interface, $conf_file) {
    // Standard-Antwort (DHCP)
    $config = ['mode' => 'dhcp', 'ip' => '', 'gw' => '', 'dns' => ''];
    
    if (!file_exists($conf_file)) return $config;

    $content = @file_get_contents($conf_file);
    if ($content === false) return $config; 

    $pattern = "/iface " . preg_quote($interface) . " inet (\w+)/";
    
    if (preg_match($pattern, $content, $matches)) {
        $mode = $matches[1]; // $matches[1] ist 'dhcp' or 'static'
        
        if ($mode === 'static') {
            $config['mode'] = 'static';
            
            $block_start = strpos($content, $matches[0]);
            $next_iface = preg_match('/iface|auto/', $content, $next_matches, PREG_OFFSET_CAPTURE, $block_start + strlen($matches[0]));
            
            if ($next_iface) {
                $block = substr($content, $block_start, $next_matches[0][1] - $block_start);
            } else {
                $block = substr($content, $block_start);
            }

            if (preg_match('/address\s+([^\s\/]+)/', $block, $m)) {
                $config['ip'] = $m[1]; 
            }
            if (preg_match('/netmask\s+([^\s]+)/', $block, $m)) {
                if($m[1] == '255.255.255.0') $config['ip'] .= '/24';
                elseif($m[1] == '255.255.0.0') $config['ip'] .= '/16';
            }
            
            if (preg_match('/gateway\s+([^\s]+)/', $block, $m)) {
                $config['gw'] = $m[1];
            }
            if (preg_match('/dns-nameservers\s+([^\s]+)/', $block, $m)) {
                $config['dns'] = $m[1];
            }
        }
    }
    
    return $config;
}

// --- Lese die aktuellen Konfigurationen (nur für die, die existieren) ---
$eth0_exists = file_exists('/sys/class/net/eth0');
$wlan0_exists = file_exists('/sys/class/net/wlan0');

if ($eth0_exists) {
    $eth0_config = get_interface_config_from_interfaces('eth0', $INTERFACES_FILE);
}
if ($wlan0_exists) {
    $wlan0_config = get_interface_config_from_interfaces('wlan0', $INTERFACES_FILE);
}


// --- AKTIONEN (Speichern) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $output_log = '';

    // 1. Hostname ändern
    if (isset($_POST['hostname']) && !empty($_POST['hostname'])) {
        $new_host_string = trim($_POST['hostname']); 
        
        if ($new_host_string !== $current_hostname) {
            
			BLELogger::info("Changing hostname", ['old' => $current_hostname, 'new' => $new_host_string, 'user_ip' => $_SERVER['REMOTE_ADDR']]);
            
            $output_log .= shell_exec("sudo $SET_HOST_SCRIPT $new_host_arg 2>&1");
            $reboot_needed = true;
        }
    }

    // 2. Netzwerk ändern
    if (isset($_POST['net_mode']) && isset($_POST['interface'])) {
        $mode = $_POST['net_mode'];
        $interface = escapeshellarg($_POST['interface']); 
		
		BLELogger::info("Changing network settings", ['interface' => $interface, 'mode' => $mode, 'user_ip' => $_SERVER['REMOTE_ADDR']]);
        
        if ($mode === 'dhcp') {
            $output_log .= shell_exec("sudo $SET_NET_SCRIPT $interface dhcp 2>&1");
            $reboot_needed = true;
        } elseif ($mode === 'static') {
            $ip = escapeshellarg($_POST['static_ip']); 
            $gw = escapeshellarg($_POST['static_gateway']);
            $dns = escapeshellarg($_POST['static_dns']);
            
            $output_log .= shell_exec("sudo $SET_NET_SCRIPT $interface static $ip $gw $dns 2>&1");
            $reboot_needed = true;
        }
        
        if ($eth0_exists) { $eth0_config = get_interface_config_from_interfaces('eth0', $INTERFACES_FILE); }
        if ($wlan0_exists) { $wlan0_config = get_interface_config_from_interfaces('wlan0', $INTERFACES_FILE); }
    }
    
    if ($reboot_needed && empty($message)) { 
         $message = "Einstellungen wurden gespeichert. Ein Neustart ist erforderlich.<br><pre>$output_log</pre>";
    } elseif ($reboot_needed) {
         $message .= "<br>Netzwerk-Einstellungen wurden gespeichert. Ein Neustart ist erforderlich.<br><pre>$output_log</pre>";
    }
}

// 3. Neustart auslösen
if (isset($_GET['action']) && $_GET['action'] === 'reboot') {
    if (!isset($_SESSION['reboot_triggered'])) { 
        $_SESSION['reboot_triggered'] = true;
		
		BLELogger::warning("System reboot triggered", ['user_ip' => $_SERVER['REMOTE_ADDR']]);
		
        $message = "System wird neu gestartet... Verbindung wird in Kürze getrennt.";
        shell_exec("sudo $REBOOT_SCRIPT");
    } else {
        $message = "Neustart wurde bereits ausgelöst.";
    }
} else {
    unset($_SESSION['reboot_triggered']);
}

?>

<!DOCTYPE html>
<html lang="de" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BLE Netzwerk</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css"/>
    <style>
        /* (Kompaktes CSS) */
        html { font-size: 14px; }
        body { padding-top: 20px; }
        button, input[type="text"], input[type="number"], input[type="submit"], input[type="password"], [role="button"], select {
            padding: 4px 8px; font-size: 0.9rem; height: auto; margin-bottom: 0; box-sizing: border-box;
        }
        
        /* Deine Button-Farben */
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
        
        /* Logo in Nav */
        nav ul:first-child li { 
            display: flex; align-items: center; gap: 10px; padding: 0;
        }
        .nav-logo { height: 100px; width: 100px; border-radius: 5px; flex-shrink: 0; }
        nav .nav-title-group { display: flex; flex-direction: column; }
        nav h1 { font-size: 1.5rem; line-height: 1.1; margin: 0; color: var(--pico-h1-color); }
        nav h3 { font-size: 1rem; color: var(--pico-muted-color, #555); line-height: 1; margin: 0; font-weight: 400; }

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
        
        /* System-Seiten-Styling */
        fieldset { margin-bottom: 15px; }
        fieldset > div {
            display: grid; grid-template-columns: 200px 1fr; 
            gap: 10px; margin-bottom: 8px; align-items: center;
        }
        label { font-weight: bold; }
        .warning { background-color: #fff3cd; border-color: #ffeeba; color: #856404; padding: 1rem; }
        fieldset div.radio-group label {
            font-weight: normal; margin-right: 15px; text-decoration: none; cursor: default;
        }
        .input-with-note {
            display: flex; flex-direction: column; gap: 5px; 
        }
        .input-with-note small { margin: 0; font-size: 0.8rem; }
        
        /* NEU: Tooltip-Stil für bestimmte Labels */
        label.tooltip-label {
            text-decoration: underline dashed 1px #888; 
            cursor: help; 
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
        <h2>System-Einstellungen</h2>

        <?php if ($message): ?>
            <article role="alert" class="success-message" style="background-color: #d4edda; border-color: #c3e6cb; color: #155724; padding: 1rem;">
                <?php echo $message; // Zeigt den Log-Output an ?>
            </article>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <article role="alert" class="error-message" style="background-color: #f8d7da; border-color: #f5c6cb; color: #721c24; padding: 1rem;">
                <?php echo $error_message; ?>
            </article>
        <?php endif; ?>

        <?php if ($reboot_needed): ?>
            <article class="warning">
                <strong>Änderungen erfordern einen Neustart!</strong><br>
                <p>Die Einstellungen wurden gespeichert. Ein vollständiger Neustart wird empfohlen.</p>
                <a href="network.php?action=reboot" role="button" class="contrast" onclick="return confirm('System wirklich NEU STARTEN?')">Jetzt neu starten</a>
            </article>
        <?php endif; ?>

        <form action="network.php" method="POST">
            <fieldset>
                <legend><strong>Hostname</strong></legend>
                <article class="warning" style="margin-bottom: 1rem;">
                    Ändert den Namen des Geräts im Netzwerk. <strong>Achtung:</strong> Nach dem Speichern ist ein Neustart erforderlich.
                </article>
                <div>
                    <label for="hostname">Hostname</label>
                    <input type="text" id="hostname" name="hostname" value="<?php echo htmlspecialchars($current_hostname); ?>" required>
                </div>
            </fieldset>
            <button type="submit" class="primary" style="width: 100%; margin-bottom: 1rem;">💾 Hostname speichern</button>
        </form>

        <hr>

        <?php if ($eth0_exists): ?>
            <form action="network.php" method="POST">
                <input type="hidden" name="interface" value="eth0">
                <fieldset>
                    <legend><strong>Netzwerk eth0 (Kabel)</strong></legend>
                    
                    <div>
                        <label>Modus</label>
                        <div class="radio-group">
                            <input type="radio" id="eth0_mode_dhcp" name="net_mode" value="dhcp" <?php echo ($eth0_config['mode'] === 'dhcp') ? 'checked' : ''; ?>>
                            <label for="eth0_mode_dhcp">DHCP (Empfohlen)</label>
                            <br>
                            <input type="radio" id="eth0_mode_static" name="net_mode" value="static" <?php echo ($eth0_config['mode'] === 'static') ? 'checked' : ''; ?>>
                            <label for="eth0_mode_static">Statische IP</label>
                        </div>
                    </div>
                    
                    <div class="eth0_static_fields" style="display: <?php echo ($eth0_config['mode'] === 'static') ? 'block' : 'none'; ?>;"> 
                        <hr>
                        <div>
                            <label for="eth0_static_ip" class="tooltip-label" title="CIDR ist eine moderne Schreibweise für die Subnetzmaske. /24 entspricht 255.255.255.0 (Standard für Heimnetzwerke).">Statische IP / CIDR</label>
                            <div class="input-with-note"> 
                                <input type="text" id="eth0_static_ip" name="static_ip" value="<?php echo htmlspecialchars($eth0_config['ip']); ?>" placeholder="192.168.1.100/24" required>
                                <small>Muss mit /24 (oder /16 etc.) angegeben werden.</small>
                            </div>
                        </div>
                        <div>
                            <label for="eth0_static_gateway">Gateway (Router)</label>
                            <input type="text" id="eth0_static_gateway" name="static_gateway" value="<?php echo htmlspecialchars($eth0_config['gw']); ?>" placeholder="192.168.1.1" required>
                        </div>
                        <div>
                            <label for="eth0_static_dns">DNS-Server</label>
                            <input type="text" id="eth0_static_dns" name="static_dns" value="<?php echo htmlspecialchars($eth0_config['dns']); ?>" placeholder="192.168.1.1 (oder 8.8.8.8)" required>
                        </div>
                    </div>
                </fieldset>
                <button type="submit" class="primary" style="width: 100%;">💾 eth0 Einstellungen speichern</button>
            </form>
        <?php else: ?>
            <article>eth0 (Kabel) nicht gefunden.</article>
        <?php endif; ?>
        
        <?php if ($wlan0_exists): ?>
            <form action="network.php" method="POST" style="margin-top: 1.5rem;">
                <input type="hidden" name="interface" value="wlan0">
                <fieldset>
                    <legend><strong>Netzwerk wlan0 (WLAN)</strong></legend>
                    <article class="warning">
                        <strong>Hinweis:</strong> Dies ändert nur die IP-Einstellungen (DHCP/Static). Es verbindet das Gerät **nicht** mit einem neuen WLAN (SSID/Passwort).
                    </article>
                    
                    <div>
                        <label>Modus</label>
                        <div class="radio-group">
                            <input type="radio" id="wlan0_mode_dhcp" name="net_mode" value="dhcp" <?php echo ($wlan0_config['mode'] === 'dhcp') ? 'checked' : ''; ?>>
                            <label for="wlan0_mode_dhcp">DHCP (Empfohlen)</label>
                            <br>
                            <input type="radio" id="wlan0_mode_static" name="net_mode" value="static" <?php echo ($wlan0_config['mode'] === 'static') ? 'checked' : ''; ?>>
                            <label for="wlan0_mode_static">Statische IP</label>
                        </div>
                    </div>
                    
                    <div class="wlan0_static_fields" style="display: <?php echo ($wlan0_config['mode'] === 'static') ? 'block' : 'none'; ?>;"> 
                        <hr>
                        <div>
                            <label for="wlan0_static_ip" class="tooltip-label" title="CIDR ist eine moderne Schreibweise für die Subnetzmaske. /24 entspricht 255.255.255.0 (Standard für Heimnetzwerke).">Statische IP / CIDR</label>
                            <div class="input-with-note"> 
                                <input type="text" id="wlan0_static_ip" name="static_ip" value="<?php echo htmlspecialchars($wlan0_config['ip']); ?>" placeholder="192.168.1.101/24" required>
                                <small>Muss mit /24 (oder /16 etc.) angegeben werden.</small>
                            </div>
                        </div>
                        <div>
                            <label for="wlan0_static_gateway">Gateway (Router)</label>
                            <input type="text" id="wlan0_static_gateway" name="static_gateway" value="<?php echo htmlspecialchars($wlan0_config['gw']); ?>" placeholder="192.168.1.1" required>
                        </div>
                        <div>
                            <label for="wlan0_static_dns">DNS-Server</label>
                            <input type="text" id="wlan0_static_dns" name="static_dns" value="<?php echo htmlspecialchars($wlan0_config['dns']); ?>" placeholder="192.168.1.1 (oder 8.8.8.8)" required>
                        </div>
                    </div>
                </fieldset>
                <button type="submit" class="primary" style="width: 100%;">💾 wlan0 Einstellungen speichern</button>
            </form>
        <?php else: ?>
            <article style="margin-top: 1.5rem;">wlan0 (WLAN) nicht gefunden.</article>
        <?php endif; ?>

    </main>

    <script>
        // --- JAVASCRIPT ZUM AKTUALISIEREN DER FORMULARE ---
        
        // --- Logik für ETH0 ---
        <?php if ($eth0_exists): ?>
            const eth0_modeDhcp = document.getElementById('eth0_mode_dhcp');
            const eth0_modeStatic = document.getElementById('eth0_mode_static');
            const eth0_staticFields = document.querySelector('.eth0_static_fields');
            const eth0_staticIp = document.getElementById('eth0_static_ip');
            const eth0_staticGw = document.getElementById('eth0_static_gateway');
            const eth0_staticDns = document.getElementById('eth0_static_dns');

            function toggleEth0StaticFields() {
                if (eth0_modeStatic.checked) {
                    eth0_staticFields.style.display = 'block';
                    eth0_staticIp.required = true;
                    eth0_staticGw.required = true;
                    eth0_staticDns.required = true;
                } else {
                    eth0_staticFields.style.display = 'none';
                    eth0_staticIp.required = false;
                    eth0_staticGw.required = false;
                    eth0_staticDns.required = false;
                }
            }
            eth0_modeDhcp.addEventListener('change', toggleEth0StaticFields);
            eth0_modeStatic.addEventListener('change', toggleEth0StaticFields);
            // Initialer Check beim Laden
            toggleEth0StaticFields(); 
        <?php endif; ?>

        // --- Logik für WLAN0 ---
        <?php if ($wlan0_exists): ?>
            const wlan0_modeDhcp = document.getElementById('wlan0_mode_dhcp');
            const wlan0_modeStatic = document.getElementById('wlan0_mode_static');
            const wlan0_staticFields = document.querySelector('.wlan0_static_fields');
            const wlan0_staticIp = document.getElementById('wlan0_static_ip');
            const wlan0_staticGw = document.getElementById('wlan0_static_gateway');
            const wlan0_staticDns = document.getElementById('wlan0_static_dns');

            function toggleWlan0StaticFields() {
                if (wlan0_modeStatic.checked) {
                    wlan0_staticFields.style.display = 'block';
                    wlan0_staticIp.required = true;
                    wlan0_staticGw.required = true;
                    wlan0_staticDns.required = true;
                } else {
                    wlan0_staticFields.style.display = 'none';
                    wlan0_staticIp.required = false;
                    wlan0_staticGw.required = false;
                    wlan0_staticDns.required = false;
                }
            }
            wlan0_modeDhcp.addEventListener('change', toggleWlan0StaticFields);
            wlan0_modeStatic.addEventListener('change', toggleWlan0StaticFields);
            // Initialer Check beim Laden
            toggleWlan0StaticFields();
        <?php endif; ?>
        
    </script>
</body>
</html>