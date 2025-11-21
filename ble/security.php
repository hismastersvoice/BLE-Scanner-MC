<?php
require_once __DIR__ . '/php_logger.php';

// --- PFADE ---
$INI_FILE = '/var/www/html/ble/config.ini';
$PYTHON_SCRIPT_PATH = '/var/www/html/ble/ble_tool.py';
$KNOWN_DEVICES_FILE = '/var/www/html/ble/known_devices.txt';
$DISCOVER_FILE = '/var/www/html/ble/scan_results.json';
$HTPASSWD_FILE = '/var/www/html/ble/.htpasswd';

$message = '';
$error_message = '';

$config = parse_ini_file($INI_FILE, true, INI_SCANNER_RAW);
$current_mode = $config['MasterClient']['mode'] ?? 'standalone';
$is_client_mode = ($current_mode === 'client');
$is_master_mode = ($current_mode === 'master');

$current_hostname = gethostname();

// --- HELFER: htpasswd-Datei lesen (nur Benutzernamen) ---
function get_htpasswd_users($file) {
    $users = [];
    if (!file_exists($file)) return $users;
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $users[] = trim($parts[0]);
        }
    }
    sort($users);
    return $users;
}

// --- AKTIONEN (Passwort ändern/hinzufügen) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['username']) && isset($_POST['new_password']) && isset($_POST['confirm_password'])) {
        
        $username = trim($_POST['username']);
        $new_pass = trim($_POST['new_password']);
        $confirm_pass = trim($_POST['confirm_password']);
        $client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        // --- Validierung ---
        if (empty($username) || empty($new_pass)) {
            BLELogger::warning("Password change failed - empty fields", ['username' => $username, 'client_ip' => $client_ip]);
            $error_message = "Benutzername und Passwort dürfen nicht leer sein.";
        } elseif ($new_pass !== $confirm_pass) {
            BLELogger::warning("Password change failed - password mismatch", ['username' => $username, 'client_ip' => $client_ip]);
            $error_message = "Die Passwörter stimmen nicht überein.";
        } elseif (preg_match('/[^a-zA-Z0-9_-]/', $username)) {
            BLELogger::warning("Password change failed - invalid username", ['username' => $username, 'client_ip' => $client_ip]);
            $error_message = "Benutzername darf nur Buchstaben, Zahlen, - und _ enthalten.";
        } elseif (strpos($new_pass, '"') !== false || strpos($new_pass, "'") !== false || strpos($new_pass, '`') !== false) {
            BLELogger::warning("Password change failed - invalid characters in password", ['username' => $username, 'client_ip' => $client_ip]);
            $error_message = "Passwort darf keine Anführungszeichen oder Backticks enthalten.";
        } else {
            // --- Befehl ausführen ---
            
            $existing_users = get_htpasswd_users($HTPASSWD_FILE);
            $create_flag = in_array($username, $existing_users) ? '' : '-c';
            
            if (!empty($existing_users) && !in_array($username, $existing_users)) {
                 $create_flag = '';
            }
            if (empty($existing_users)) {
                 $create_flag = '-c';
            }

            $HTPASSWD_PATH = trim(shell_exec("which htpasswd"));
            if(empty($HTPASSWD_PATH)) $HTPASSWD_PATH = "/usr/bin/htpasswd";

            $command = "sudo $HTPASSWD_PATH $create_flag -b " . 
                       escapeshellarg($HTPASSWD_FILE) . " " . 
                       escapeshellarg($username) . " " . 
                       escapeshellarg($new_pass);
            
            BLELogger::info("Attempting password change", ['username' => $username, 'client_ip' => $client_ip, 'is_new_user' => !in_array($username, $existing_users)]);
            
            $output = shell_exec($command . " 2>&1");
            
            if (strpos($output, 'Adding password') !== false || strpos($output, 'Updating password') !== false) {
                BLELogger::info("Password changed successfully", ['username' => $username, 'client_ip' => $client_ip]);
                $message = "Passwort für Benutzer '" . htmlspecialchars($username) . "' erfolgreich gesetzt/geändert.";
            } else {
                BLELogger::error("Password change failed - htpasswd error", ['username' => $username, 'output' => substr($output, 0, 200), 'client_ip' => $client_ip]);
                $error_message = "Fehler beim Ausführen von htpasswd. (Stimmt die sudoers-Regel?)";
                $error_message .= "<pre>" . htmlspecialchars($output) . "</pre>";
            }
        }
    }
}

$current_users = get_htpasswd_users($HTPASSWD_FILE);
?>

<!DOCTYPE html>
<html lang="de" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BLE Sicherheit</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css"/>
    <style>
        html { font-size: 14px; }
        body { padding-top: 20px; }
        button, input[type="text"], input[type="number"], input[type="submit"], [role="button"], input[type="password"] {
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
        
        [role="button"].known-device-battery-toggle {
            display: inline-block; line-height: 1.5;
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
        
        fieldset { margin-bottom: 15px; }
        fieldset > div {
            display: grid; grid-template-columns: 200px 1fr; 
            gap: 10px; margin-bottom: 8px; align-items: center;
        }
        label { font-weight: bold; }
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
        <h2>WebUI-Zugang verwalten</h2>

        <?php if ($message): ?>
            <article role="alert" class="success-message" style="background-color: #d4edda; border-color: #c3e6cb; color: #155724; padding: 1rem;">
                <?php echo htmlspecialchars($message); ?>
            </article>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <article role="alert" class="error-message" style="background-color: #f8d7da; border-color: #f5c6cb; color: #721c24; padding: 1rem;">
                <?php echo $error_message; ?>
            </article>
        <?php endif; ?>
	

        <?php if (empty($current_users)): ?>
             <article role="alert" style="background-color: #fff3cd; border-color: #ffeeba; color: #856404; padding: 1rem;">
                <strong>Warnung:</strong> Es wurde noch kein Passwort gesetzt. Die UI ist ungeschützt, bis du einen Benutzer (z.B. 'admin') anlegst.
            </article>
        <?php elseif (in_array('admin', $current_users) && !$message): ?>
             <article role="alert" style="background-color: #fff3cd; border-color: #ffeeba; color: #856404; padding: 1rem;">
                <strong>Empfehlung:</strong> Ändere das Standard-Passwort für 'admin', falls du das noch nicht getan hast.
            </article>
        <?php endif; ?>


        <form action="security.php" method="POST">
            <fieldset>
                <legend><strong>Benutzer hinzufügen / Passwort ändern</strong></legend>
                
                <div>
                    <label for="username">Benutzername</label>
                    <input type="text" id="username" name="username" value="admin" required>
                </div>
                
                <div>
                    <label for="new_password">Neues Passwort</label>
                    <input type="password" id="new_password" name="new_password" required autocomplete="new-password">
                </div>
                
                <div>
                    <label for="confirm_password">Passwort bestätigen</label>
                    <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">
                </div>
                
            </fieldset>
            
            <button type="submit" class="primary" style="width: 100%;">💾 Speichern</button>
        </form>
    </main>
</body>
</html>