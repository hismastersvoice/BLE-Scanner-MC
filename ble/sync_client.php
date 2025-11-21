<?php
/**
 * Client-Sync Script
 * Wird via Cron ausgeführt für Fallback-Poll
 * Holt Daten vom Master wenn interval > 0
 */

require_once __DIR__ . '/php_logger.php';

// --- PFADE ---
$INI_FILE = '/var/www/html/ble/config.ini';
$KNOWN_DEVICES_FILE = '/var/www/html/ble/known_devices.txt';
$BATTERY_STATUS_FILE = '/var/www/html/ble/battery_status.json';

// --- CONFIG LADEN ---
$config = @parse_ini_file($INI_FILE, true, INI_SCANNER_RAW) ?? [];
$mode = $config['MasterClient']['mode'] ?? 'standalone';
$master_url = $config['MasterClient']['master_url'] ?? '';
$api_key = $config['MasterClient']['api_key'] ?? '';
$poll_interval = intval($config['MasterClient']['fallback_poll_interval'] ?? 1800);

// Nur im Client-Modus aktiv
if ($mode !== 'client') {
    BLELogger::debug("Sync skipped - not in client mode");
    exit;
}

// Interval 0 = deaktiviert
if ($poll_interval <= 0) {
    BLELogger::debug("Fallback poll disabled (interval = 0)");
    exit;
}

// Validierung
if (empty($master_url) || empty($api_key)) {
    BLELogger::error("Sync failed - missing master_url or api_key");
    exit;
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

// --- SYNC VOM MASTER HOLEN ---
BLELogger::info("Starting fallback poll from master", ['master_url' => $master_url]);

$ch = curl_init($master_url . '/api.php?action=sync');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'X-API-Key: ' . $api_key
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

if ($http_code !== 200) {
    BLELogger::error("Sync failed - HTTP error", [
        'http_code' => $http_code,
        'curl_error' => $curl_error,
        'response' => substr($response, 0, 200)
    ]);
    exit;
}

$data = json_decode($response, true);

if (!$data || $data['status'] !== 'success') {
    BLELogger::error("Sync failed - invalid response", [
        'response' => substr($response, 0, 200)
    ]);
    exit;
}

// Daten speichern
save_devices_data($KNOWN_DEVICES_FILE, $data['data']['devices']);
file_put_contents($BATTERY_STATUS_FILE, json_encode($data['data']['battery_status']));

BLELogger::info("Sync successful", [
    'devices_count' => count($data['data']['devices']),
    'master_timestamp' => $data['data']['timestamp']
]);

echo "Sync erfolgreich - " . count($data['data']['devices']) . " Geräte synchronisiert.\n";
?>