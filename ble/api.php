<?php
/**
 * API-Endpunkt für Master-Client Kommunikation
 * Endpoints:
 * - /api.php?action=sync - Client holt Daten vom Master
 * - /api.php?action=push - Master schickt Daten an Client
 * - /api.php?action=ping - Status-Check
 */

require_once __DIR__ . '/php_logger.php';

header('Content-Type: application/json');

// --- PFADE ---
$INI_FILE = '/var/www/html/ble/config.ini';
$KNOWN_DEVICES_FILE = '/var/www/html/ble/known_devices.txt';
$BATTERY_STATUS_FILE = '/var/www/html/ble/battery_status.json';
$CLIENTS_FILE = '/var/www/html/ble/clients.json';
$SERVICE_NAME = 'ble_tool.service';

// --- CONFIG LADEN ---
$config = @parse_ini_file($INI_FILE, true, INI_SCANNER_RAW) ?? [];
$mode = $config['MasterClient']['mode'] ?? 'standalone';

// --- SICHERHEIT: API-KEY & IP-WHITELIST PRÜFEN ---
function validate_api_key($provided_key, $mode, $config, $clients_file) {
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    // --- MASTER MODE ---
    if ($mode === 'master') {
        // Im Master-Modus prüfen wir gegen die clients.json (wie bisher)
        if (!file_exists($clients_file)) {
            return ['valid' => false, 'client' => null, 'reason' => 'No clients configured'];
        }
        
        $clients = json_decode(file_get_contents($clients_file), true) ?? [];
        
        foreach ($clients as $client) {
            if ($client['api_key'] === $provided_key) {
                // IP-Whitelist prüfen
                if (!empty($client['ip_whitelist'])) {
                    $allowed_ips = array_map('trim', explode(',', $client['ip_whitelist']));
                    if (!in_array($client_ip, $allowed_ips)) {
                        return [
                            'valid' => false, 
                            'client' => null, 
                            'reason' => 'IP not whitelisted'
                        ];
                    }
                }
                return ['valid' => true, 'client' => $client, 'reason' => ''];
            }
        }
        
        return ['valid' => false, 'client' => null, 'reason' => 'Invalid API key'];
    }
    
    // --- CLIENT MODE ---
    if ($mode === 'client') {
        // Im Client-Modus prüfen wir gegen den API-Key aus der config.ini
        $expected_key = trim($config['MasterClient']['api_key'] ?? '');
        
        if (empty($expected_key)) {
            return ['valid' => false, 'client' => null, 'reason' => 'No API key configured'];
        }
        
        if (trim($provided_key) === $expected_key) {
            return ['valid' => true, 'client' => ['name' => 'master'], 'reason' => ''];
        }
        
        return ['valid' => false, 'client' => null, 'reason' => 'Invalid API key'];
    }
    
    // --- STANDALONE MODE ---
    return ['valid' => false, 'client' => null, 'reason' => 'Standalone mode - no API access'];
}

// --- HELFER: Geräte laden ---
function get_devices_data($known_devices_file, $battery_status_file) {
    $devices = [];
    
    // Known devices
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
    
    // Battery status
    $battery_status = [];
    if (file_exists($battery_status_file)) {
        $battery_status = json_decode(file_get_contents($battery_status_file), true) ?? [];
    }
    
    return [
        'devices' => $devices,
        'battery_status' => $battery_status,
        'timestamp' => time()
    ];
}

// --- HELFER: Geräte speichern ---
function save_devices_data($known_devices_file, $devices) {
    uasort($devices, function($a, $b) { 
        return strcasecmp($a['alias'], $b['alias']); 
    });
    
    $lines = [];
    foreach ($devices as $mac => $device_data) {
        $lines[] = "$mac," . $device_data['alias'] . "," . 
                   ($device_data['battery_scan'] ? '1' : '0');
    }
    file_put_contents($known_devices_file, implode("\n", $lines));
}

// --- HELFER: Client-Status aktualisieren ---
function update_client_status($clients_file, $client_name, $status = 'online') {
    if (!file_exists($clients_file)) return;
    
    $clients = json_decode(file_get_contents($clients_file), true) ?? [];
    
    foreach ($clients as &$client) {
        if ($client['name'] === $client_name) {
            $client['last_sync'] = time();
            $client['status'] = $status;
            break;
        }
    }
    
    file_put_contents($clients_file, json_encode($clients, JSON_PRETTY_PRINT));
}

// --- HELFER: Service neu starten ---
function restart_ble_service($service_name) {
    $logFile = '/var/log/ble/service_restart.log';
    $timestamp = date('Y-m-d H:i:s');
    
    BLELogger::info("Service restart requested after push", ['service' => $service_name]);
    
    file_put_contents(
        $logFile, 
        "[$timestamp] Service-Neustart nach Push\n", 
        FILE_APPEND
    );
    
    $output = [];
    $return_var = 0;
    exec('sudo /bin/systemctl restart ' . escapeshellarg($service_name) . ' 2>&1', $output, $return_var);
    
    $status = $return_var === 0 ? 'ERFOLG' : 'FEHLER';
    file_put_contents(
        $logFile, 
        "[$timestamp] Status: $status (Return Code: $return_var)\n", 
        FILE_APPEND
    );
    
    if ($return_var === 0) {
        BLELogger::info("Service restarted successfully", ['service' => $service_name]);
        return ['restarted' => true, 'success' => true];
    } else {
        $error_output = implode("\n", $output);
        BLELogger::error("Failed to restart service", [
            'service' => $service_name,
            'return_code' => $return_var,
            'output' => $error_output
        ]);
        
        file_put_contents(
            $logFile, 
            "[$timestamp] Fehler: $error_output\n", 
            FILE_APPEND
        );
        
        return ['restarted' => false, 'success' => false, 'error' => $error_output];
    }
}

// --- MAIN LOGIC ---
$action = $_GET['action'] ?? $_POST['action'] ?? 'unknown';
$api_key = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? $_POST['api_key'] ?? '';

// --- PING (kein API-Key nötig) ---
if ($action === 'ping') {
    BLELogger::debug("API ping received");
    echo json_encode([
        'status' => 'ok',
        'mode' => $mode,
        'timestamp' => time()
    ]);
    exit;
}

// --- API-KEY VALIDIERUNG (für alle anderen Aktionen) ---
if (empty($api_key)) {
    http_response_code(401);
    BLELogger::warning("API request without API key", [
        'action' => $action,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
    echo json_encode([
        'status' => 'error',
        'message' => 'API key required'
    ]);
    exit;
}

$validation = validate_api_key($api_key, $mode, $config, $CLIENTS_FILE);
if (!$validation['valid']) {
    http_response_code(403);
    BLELogger::warning("API request with invalid key or IP", [
        'action' => $action,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'reason' => $validation['reason'],
        'mode' => $mode
    ]);
    echo json_encode([
        'status' => 'error',
        'message' => $validation['reason']
    ]);
    exit;
}

$client = $validation['client'];

// --- AKTIONEN ---
switch ($action) {
    
    // --- SYNC: Client holt Daten vom Master ---
    case 'sync':
        if ($mode !== 'master') {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'This instance is not in master mode'
            ]);
            BLELogger::warning("Sync requested but not in master mode", [
                'client' => $client['name']
            ]);
            exit;
        }
        
        $data = get_devices_data($KNOWN_DEVICES_FILE, $BATTERY_STATUS_FILE);
        
        update_client_status($CLIENTS_FILE, $client['name'], 'online');
        
        BLELogger::info("Data synced to client", [
            'client' => $client['name'],
            'devices_count' => count($data['devices'])
        ]);
        
        echo json_encode([
            'status' => 'success',
            'data' => $data
        ]);
        break;
    
    // --- PUSH: Master schickt Daten an Client (wird vom Master via CURL aufgerufen) ---
    case 'receive_push':
        if ($mode !== 'client') {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'This instance is not in client mode'
            ]);
            exit;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['devices']) || !isset($input['battery_status'])) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid push data'
            ]);
            exit;
        }
        
        // Daten speichern
        save_devices_data($KNOWN_DEVICES_FILE, $input['devices']);
        file_put_contents($BATTERY_STATUS_FILE, json_encode($input['battery_status']));
        
        BLELogger::info("Push data received from master", [
            'devices_count' => count($input['devices']),
            'timestamp' => $input['timestamp'] ?? 'unknown'
        ]);
        
        // BLE Service neu starten damit neue Geräte gescannt werden
        $service_result = restart_ble_service($SERVICE_NAME);
        
        echo json_encode([
            'status' => 'success',
            'message' => 'Data received and saved',
            'service_restarted' => $service_result['success']
        ]);
        break;
    
    default:
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Unknown action'
        ]);
        break;
}
?>
