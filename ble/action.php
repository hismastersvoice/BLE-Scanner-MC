<?php
require_once __DIR__ . '/php_logger.php';

// --- PFADE ---
$PYTHON_SCRIPT_PATH = '/var/www/html/ble/ble_tool.py';
$SERVICE_NAME = 'ble_tool.service';
$INI_FILE = '/var/www/html/ble/config.ini';

// --- LOCK-DATEIEN ---
$BATTERY_JOB_LOCK = '/var/www/html/ble/read_enabled_batteries.lock';
$DISCOVER_JOB_LOCK = '/var/www/html/ble/discover.lock';
$PAUSE_FILE = '/tmp/ble_read.pause'; 

// --- PHP-TIMEOUTS ---
set_time_limit(3600);
ignore_user_abort(true); 

// --- HELFER-FUNKTIONEN ---

function create_lock($lock_file, $max_age_seconds = 3600) {
    if (file_exists($lock_file)) {
        if (time() - filemtime($lock_file) > $max_age_seconds) {
            BLELogger::warning("Removing stale lock file", ['file' => $lock_file, 'age' => time() - filemtime($lock_file)]);
            unlink($lock_file); 
        } else {
            BLELogger::warning("Lock file already exists", ['file' => $lock_file]);
            return false;
        }
    }
    file_put_contents($lock_file, getmypid());
    BLELogger::debug("Lock file created", ['file' => $lock_file, 'pid' => getmypid()]);
    return true;
}

function remove_lock($lock_file) {
    if (file_exists($lock_file)) {
        unlink($lock_file);
        BLELogger::debug("Lock file removed", ['file' => $lock_file]);
    }
}

function run_shell_command($cmd) {
    $output = [];
    $return_var = 0;
    exec($cmd . " 2>&1", $output, $return_var); 
    
    return [
        'output' => implode("\n", $output),
        'exit_code' => $return_var
    ];
}

function run_python_command($command_name, $args = []) {
    global $PYTHON_SCRIPT_PATH;
    $cmd = "sudo /usr/bin/python3 " . escapeshellarg($PYTHON_SCRIPT_PATH) . " " . escapeshellarg($command_name);
    
    foreach ($args as $key => $value) {
        if (is_array($value)) {
            foreach ($value as $val) {
                $cmd .= " " . escapeshellarg($key) . " " . escapeshellarg($val);
            }
        } else {
            $cmd .= " " . escapeshellarg($key) . " " . escapeshellarg($value);
        }
    }

    BLELogger::debug("Executing Python command", ['command' => $command_name, 'args' => $args]);
    return run_shell_command($cmd);
}

function stop_service($service_name) {
    $status = shell_exec("sudo systemctl status " . escapeshellarg($service_name));
    if (strpos($status, "Active: active (running)") !== false) {
        shell_exec("sudo systemctl stop " . escapeshellarg($service_name));
        BLELogger::info("Service stopped", ['service' => $service_name]);
        return true; 
    }
    return false; 
}

function start_service($service_name) {
    shell_exec("sudo systemctl start " . escapeshellarg($service_name));
    BLELogger::info("Service started", ['service' => $service_name]);
}

// --- API-STEUERUNG (Main) ---

$cmd = $_GET['cmd'] ?? '';
$client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

BLELogger::info("API request received", ['cmd' => $cmd, 'client_ip' => $client_ip]);

switch ($cmd) {
    
    // --- DER BATTERIE-JOB (3 Uhr Cron) ---
    case 'read_enabled_batteries':
        BLELogger::info("Starting battery scan job");
        
        if (!create_lock($BATTERY_JOB_LOCK, 3600)) {
            BLELogger::error("Battery job already running - aborting");
            die("Battery job is already running.");
        }
        
        echo "Starting battery scan job (Python)... Lock file created.\n";
        flush();
        
        $result = run_python_command('read_enabled_batteries');
        
        echo "Python job finished.\n";
        echo "<pre>{$result['output']}</pre>";

        if ($result['exit_code'] !== 0) {
            BLELogger::error("Battery scan job failed", ['exit_code' => $result['exit_code'], 'output' => substr($result['output'], 0, 500)]);
            echo "<b style='color:red;'>FEHLER: Python-Skript ist mit Code {$result['exit_code']} beendet!</b>\n";
        } else {
            BLELogger::info("Battery scan job completed successfully");
        }

        remove_lock($BATTERY_JOB_LOCK);
        echo "Battery job complete. Lock file removed.";
        break;

    
    // --- DISCOVER (mit Stop/Start-Logik) ---
    case 'discover':
        $timeout = $_GET['timeout'] ?? 10;
        BLELogger::info("Starting discover scan", ['timeout' => $timeout, 'client_ip' => $client_ip]);
        
        if (!create_lock($DISCOVER_JOB_LOCK, 600)) { 
            BLELogger::error("Discover scan already running - aborting");
            die("FEHLER: Ein anderer Discover-Scan läuft bereits. Bitte warten.");
        }
        
        echo "Halte den 24/7 Scan-Dienst ($SERVICE_NAME) an...\n";
        flush();
        
        $service_was_running = stop_service($SERVICE_NAME);
        sleep(2); 
        
        echo "Scan-Dienst angehalten. Starte Discover-Scan...\n";
        flush();

        $result = run_python_command('discover', ['--timeout' => $timeout]);
        echo "<pre>{$result['output']}</pre>";
        
        if ($result['exit_code'] !== 0) {
            BLELogger::error("Discover scan failed", ['exit_code' => $result['exit_code'], 'timeout' => $timeout]);
            echo "<b style='color:red;'>FEHLER: Python-Skript ist mit Code {$result['exit_code']} beendet!</b>\n";
        } else {
            BLELogger::info("Discover scan completed successfully", ['timeout' => $timeout]);
        }

        if ($service_was_running) {
            echo "Starte den 24/7 Scan-Dienst ($SERVICE_NAME) neu...\n";
            start_service($SERVICE_NAME);
        }

        remove_lock($DISCOVER_JOB_LOCK);
        echo "Discover beendet.";
        break;
        
    // --- MANUELLES LESEN ---
    case 'read':
        $mac_string = $_GET['mac'] ?? '';
        
        if (empty($mac_string)) {
            BLELogger::error("Manual read failed - no MAC address provided", ['client_ip' => $client_ip]);
            die("No mac address provided.");
        }
        
        $mac_list = explode(' ', trim($mac_string));
        BLELogger::info("Starting manual battery read", ['macs' => $mac_list, 'client_ip' => $client_ip]);
        
        if (!create_lock($BATTERY_JOB_LOCK, 600)) { 
            BLELogger::error("Manual read failed - lock exists");
            die("FEHLER: Ein anderer Batterie-Job (oder Discover) läuft bereits. Bitte warten.");
        }
        
        echo "Lock-Datei erstellt ($BATTERY_JOB_LOCK). Daemon wird pausieren.\n";
        echo "Starte manuelles Lesen für: $mac_string\n";
        flush(); 
        
        $result = run_python_command('read', ['--mac' => $mac_list]);
        
        echo "<pre>Python-Ausgabe:\n{$result['output']}</pre>";
        
        if ($result['exit_code'] !== 0) {
            BLELogger::error("Manual battery read failed", ['exit_code' => $result['exit_code'], 'macs' => $mac_list]);
            echo "<b style='color:red;'>FEHLER: Python-Skript ist mit Code {$result['exit_code']} beendet!</b>\n";
        } else {
            BLELogger::info("Manual battery read completed", ['macs' => $mac_list]);
        }

        remove_lock($BATTERY_JOB_LOCK);
        echo "Manuelles Lesen beendet. Lock-Datei entfernt.";
        break;

    // --- CRON-UHRZEIT AKTUALISIEREN ---
    case 'update_cron':
        BLELogger::info("Updating cron job");
        
        global $INI_FILE;
        $config = @parse_ini_file($INI_FILE, true, INI_SCANNER_RAW) ?? [];
        
        $cron_time = $config['Cron']['battery_scan_time'] ?? '03:00';
        
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $cron_time, $matches)) {
            BLELogger::warning("Invalid cron time format, using default", ['cron_time' => $cron_time]);
            $cron_time = '03:00';
            $matches = [0, 3, 0]; 
        }
        
        $h = (int)$matches[1];
        $m = (int)$matches[2];
        $WEB_USER = 'www-data'; 

        $CRON_COMMAND = "/usr/bin/curl 'http://localhost/ble/action.php?cmd=read_enabled_batteries' > /dev/null 2>&1";
        $CRON_FILTER = "action.php?cmd=read_enabled_batteries";
        $NEW_CRON_JOB = "{$m} {$h} * * * {$CRON_COMMAND}"; 
        
        $cmd_cron = "(sudo /usr/bin/crontab -u {$WEB_USER} -l 2>/dev/null | grep -v " . escapeshellarg($CRON_FILTER) . "; echo " . escapeshellarg($NEW_CRON_JOB) . ") | sudo /usr/bin/crontab -u {$WEB_USER} -";
        
        $result = run_shell_command($cmd_cron);

        if ($result['exit_code'] !== 0) {
            BLELogger::error("Cron update failed", ['exit_code' => $result['exit_code'], 'output' => $result['output'], 'cron_time' => $cron_time]);
            error_log("CRON FAILED: Code {$result['exit_code']}. Output: {$result['output']}");
            echo "CRON_UPDATE_FAILED: Siehe PHP Error Log für Details. (Exit Code: {$result['exit_code']}).";
        } else {
            BLELogger::info("Cron job updated successfully", ['cron_time' => $cron_time, 'cron_job' => $NEW_CRON_JOB]);
            echo "Cron-Job erfolgreich auf {$cron_time} ({$NEW_CRON_JOB}) aktualisiert.";
        }
        break;
		
		case 'restart_service':
			BLELogger::info("Request to restart service received");
			$service_was_running = stop_service($SERVICE_NAME);
			
			if ($service_was_running) {
				start_service($SERVICE_NAME);
				BLELogger::info("Service successfully restarted");
				echo "Service restarted.";
			} else { // <- KORREKTUR: Das 'n' ist entfernt!
				start_service($SERVICE_NAME);
				BLELogger::info("Service was inactive but was started to load new config");
				echo "Service was inactive but was started.";
			}
			break;	
		
    default:
        BLELogger::warning("Unknown command received", ['cmd' => $cmd, 'client_ip' => $client_ip]);
        echo "Unknown command. Scan is now automatic (ble_tool.service).";
        break;
}
?>