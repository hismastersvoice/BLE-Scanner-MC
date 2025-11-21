#!/usr/bin/env python3
import asyncio
import sys
import json
import socket
import configparser
import time
import argparse
import os 
import subprocess 
import logging
from bleak import BleakScanner, BleakClient, BleakError
import paho.mqtt.client as paho_mqtt
from ble_logger import get_logger, get_bluetooth_logger, get_scan_logger

# Logger initialisieren
logger = get_logger(__name__)
bt_logger = get_bluetooth_logger()
scan_logger = get_scan_logger()

SYSTEM_HOSTNAME = socket.gethostname()

# --- Globale Konstanten ---
BASE_DIR = os.path.dirname(os.path.abspath(__file__))

BATTERY_LEVEL_UUID = "00002a19-0000-1000-8000-00805f9b34fb"
CONFIG_FILE = os.path.join(BASE_DIR, "config.ini")
KNOWN_DEVICES_FILE = os.path.join(BASE_DIR, "known_devices.txt")
DISCOVER_RESULTS_FILE = os.path.join(BASE_DIR, "scan_results.json") 
BATTERY_STATUS_FILE = os.path.join(BASE_DIR, "battery_status.json") 
LAST_BATTERY_SCAN_FILE = os.path.join(BASE_DIR, "last_battery_scan.txt")

# --- Daemon-Koordination ---
BATTERY_JOB_LOCK = os.path.join(BASE_DIR, "read_enabled_batteries.lock")
PAUSE_FILE = "/tmp/ble_read.pause" # (Im RAM)
MAX_PAUSE_WAIT_SECONDS = 15 # Maximal 15s warten, dann ist das Lock veraltet

# --- Locks ---
file_lock = asyncio.Lock()
pause_lock = asyncio.Lock()


# --- NEUE SYNCHRONE HILFSFUNKTION (Defensiver Check) ---
def cleanup_stale_pause_file():
    """Entfernt synchron die PAUSE_FILE, wenn sie älter als 60 Sekunden ist."""
    if os.path.exists(PAUSE_FILE):
        try:
            file_age_seconds = time.time() - os.path.getmtime(PAUSE_FILE)
            
            # Wenn die Datei älter als 60 Sekunden ist, ist sie definitiv veraltet
            if file_age_seconds > 60: 
                logger.info("Pre-Cleanup der Pause-Datei (%s) ist %ds alt. Lösche veraltetes Lock.", PAUSE_FILE, int(file_age_seconds))
                os.remove(PAUSE_FILE)
                return True
            else:
                logger.info("Pause-Datei ist %ds alt. Lasse die ASYNC-Timeout-Logik arbeiten.", int(file_age_seconds))
                return False
        except Exception as e:
            # Fehler beim Löschen deutet auf Berechtigungsprobleme hin
            logger.error("FEHLER KRITISCH: Konnte %s nicht prüfen/löschen. Läuft das Skript als root? Fehler: %s", PAUSE_FILE, e)
            return False
    return False
# --- ENDE NEUE HILFSFUNKTION ---


# --- HILFSFUNKTIONEN (Rest) ---
def reset_bluetooth_stack():
    """Führt einen Hardware-Reset des Bluetooth-Adapters über die Shell aus."""
    HCICONFIG_COMMAND = "hciconfig" 
    
    try:
        bt_logger.info("Führe Reset mit %s aus...", HCICONFIG_COMMAND)
        subprocess.run(f"{HCICONFIG_COMMAND} hci0 down", shell=True, check=True, capture_output=True)
        subprocess.run(f"{HCICONFIG_COMMAND} hci0 up", shell=True, check=True, capture_output=True)
        
        bt_logger.info("HARD RESET: Bluetooth-Adapter kurz zurückgesetzt.")
        return True
    except subprocess.CalledProcessError as e:
        logger.error("Konnte hci0 nicht zurücksetzen. (Exit-Code: %d)", e.returncode)
        return False
    except Exception as e:
        logger.error("Unerwarteter Fehler beim Reset: %s", e)
        return False

async def update_battery_status_file(mac, data):
    """Liest, aktualisiert und speichert die Status-JSON-Datei (Thread-sicher)."""
    async with file_lock: 
        try:
            if os.path.exists(BATTERY_STATUS_FILE):
                with open(BATTERY_STATUS_FILE, "r") as f:
                    all_status_data = json.load(f)
            else:
                all_status_data = {}
        except Exception as e:
            logger.warning("Konnte %s nicht lesen: %s", BATTERY_STATUS_FILE, e)
            all_status_data = {}
            
        all_status_data[mac] = data
        
        try:
            with open(BATTERY_STATUS_FILE, "w") as f:
                json.dump(all_status_data, f, indent=4)
            logger.debug("Status-Datei für %s aktualisiert.", mac)
        except Exception as e:
            logger.error("Fehler beim Schreiben der Status-Datei: %s", e)

def load_config():
    """Lädt die Konfiguration aus der config.ini."""
    try:
        config = configparser.ConfigParser()
        if not config.read(CONFIG_FILE):
            logger.warning("%s nicht gefunden. Nur 'discover' wird ohne MQTT/UDP funktionieren.", CONFIG_FILE)
            return config 
        return config
    except Exception as e:
        logger.error("Fehler beim Lesen der Konfiguration: %s", e)
        sys.exit(1)

def load_known_devices():
    """Lädt die MACs und Aliase aus der known_devices.txt."""
    devices = {} # { "MAC": "Alias" }
    try:
        with open(KNOWN_DEVICES_FILE, "r") as f:
            for line in f:
                line = line.strip()
                if line and "," in line:
                    parts = line.split(",", 2)
                    if len(parts) >= 2:
                        mac = parts[0].upper().strip()
                        alias = parts[1].strip() 
                        devices[mac] = alias
        
        if not devices:
            logger.warning("%s ist leer oder im falschen Format (MAC,Alias,Flag).", KNOWN_DEVICES_FILE)
        return devices
    except FileNotFoundError:
        logger.warning("%s nicht gefunden. 'scan' wird keine Geräte melden.", KNOWN_DEVICES_FILE)
        return {}

def setup_mqtt_client(config):
    """Initialisiert und verbindet den MQTT-Client."""
    if not config.getboolean('MQTT', 'enabled', fallback=False):
        return None
    try:
        client = paho_mqtt.Client(paho_mqtt.CallbackAPIVersion.VERSION2)
        user = config.get('MQTT', 'username', fallback=None)
        pw = config.get('MQTT', 'password', fallback=None)
        if user:
            logger.info("MQTT-Authentifizierung mit Benutzer '%s' wird verwendet.", user)
            client.username_pw_set(user, pw)
        else:
            logger.info("MQTT-Authentifizierung ist anonym (kein Benutzer/Passwort in config.ini).")
        broker = config.get('MQTT', 'broker')
        port = config.getint('MQTT', 'port')
        logger.info("Verbinde mit MQTT-Broker %s:%d...", broker, port)
        client.connect(broker, port, 60)
        client.loop_start() 
        return client
    except Exception as e:
        logger.error("MQTT-Verbindung fehlgeschlagen: %s", e)
        return None

def send_udp(data_payload, config):
    if not config.getboolean('UDP', 'enabled', fallback=False):
        return
    try:
        host = config.get('UDP', 'host')
        port = config.getint('UDP', 'port')
        with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as sock:
            sock.sendto(data_payload.encode('utf-8'), (host, port))
        logger.debug("UDP-Daten gesendet an %s:%d", host, port)
    except Exception as e:
        logger.error("Fehler beim Senden von UDP: %s", e)

def send_mqtt(data_payload, topic, config, mqtt_client):
    if not config.getboolean('MQTT', 'enabled', fallback=False) or mqtt_client is None:
        return
    try:
        if not mqtt_client.is_connected():
             logger.warning("MQTT-Client nicht verbunden. Überspringe Senden.")
             return
        mqtt_client.publish(topic, data_payload, qos=0)
        logger.debug("MQTT-Daten gesendet an Topic '%s'", topic)
    except Exception as e:
        logger.error("Fehler beim Senden von MQTT: %s", e)

def disconnect_mqtt(mqtt_client):
    if mqtt_client:
        mqtt_client.loop_stop()
        mqtt_client.disconnect()
        logger.info("MQTT getrennt.")


# --- 3. Kernfunktion: SCAN (für Bekannte) ---
async def publish_device_status(device, advertisement_data, config, mqtt_client, alias):
    """Erstellt die JSON-Payload und sendet sie per UDP/MQTT."""
    
    scan_logger.info("Sende 'Online'-Status für %s (%s)", device.address, alias)
    
    last_battery_percent = -1 
    async with file_lock: 
        try:
            if os.path.exists(BATTERY_STATUS_FILE):
                with open(BATTERY_STATUS_FILE, "r") as f:
                    all_status_data = json.load(f)
                    if device.address in all_status_data:
                        last_battery_percent = all_status_data[device.address].get('battery_percent', -1)
        except Exception as e:
            logger.warning("Konnte Batteriestatus für %s nicht lesen: %s", device.address, e)
    
    data = {
        "hostname": SYSTEM_HOSTNAME,
        "address": device.address,
        "is_online": 1,
        "last_battery_percent": last_battery_percent,
        "name": device.name or "Unknown", 
        "alias": alias, 
        "rssi": advertisement_data.rssi,
        "timestamp": int(time.time())         
    }
    data_payload = json.dumps(data)
    
    base_topic = config.get('MQTT', 'scan_topic', fallback='ble/scan/discovery')
    safe_mac = device.address.replace(":", "")
    full_topic = f"{base_topic}/{safe_mac}"
    
    send_udp(data_payload, config)
    send_mqtt(data_payload, full_topic, config, mqtt_client)

async def scan_and_report(scan_duration, config, known_devices, mqtt_client):
    """Führt einen Scan-Durchlauf durch (wird vom Daemon aufgerufen)."""
    
    scan_logger.info("Setze Bluetooth-Adapter vor Scan zurück.")
    reset_bluetooth_stack() 
    scan_logger.info("Warte 3 Sekunden, bis der Adapter initialisiert ist...")
    await asyncio.sleep(3) 
    
    scan_logger.info("Suche nach bekannten Geräten für %d Sekunden (Sofort-Meldung aktiv)", scan_duration)
    
    processed_devices = set() 

    def detection_callback(device, advertisement_data):
        mac = device.address.upper()
        
        if mac not in known_devices:
            return 
            
        if mac in processed_devices:
            return 
            
        alias = known_devices[mac] 
        scan_logger.info("Bekanntes Gerät gefunden (Online): %s (%s)", mac, alias)
        processed_devices.add(mac)

        asyncio.create_task(
            publish_device_status(device, advertisement_data, config, mqtt_client, alias) 
        )

    scanner = BleakScanner(detection_callback=detection_callback)
    
    try:
        await scanner.start()
        await asyncio.sleep(float(scan_duration))
        await scanner.stop()
    except BleakError as e:
        logger.error("Fehler beim Scannen: %s", e) 
        return

    scan_logger.info("Scan-Phase beendet. Prüfe auf Offline-Geräte...")
    
    offline_devices_macs = set(known_devices.keys()) - processed_devices 

    if not offline_devices_macs:
        scan_logger.info("Alle bekannten Geräte wurden gefunden (online).")
    else:
        scan_logger.info("Sende %d 'Offline'-Berichte", len(offline_devices_macs))
        
        base_topic = config.get('MQTT', 'scan_topic', fallback='ble/scan/discovery')

        for mac in offline_devices_macs:
            alias = known_devices[mac] 
            data = {
                "hostname": SYSTEM_HOSTNAME,
                "address": mac,
                "is_online": 0,
                "name": "N/A (Offline)",
                "alias": alias, 
                "rssi": -100,         
                "timestamp": int(time.time())
            }
            data_payload = json.dumps(data)
            
            safe_mac = mac.replace(":", "")
            full_topic = f"{base_topic}/{safe_mac}"
            
            send_udp(data_payload, config)
            send_mqtt(data_payload, full_topic, config, mqtt_client)

    scan_logger.info("Scan-Bericht abgeschlossen. %d online, %d offline.", len(processed_devices), len(offline_devices_macs))

# --- 4. Kernfunktion: READ-BATTERY (KORRIGIERT MIT TIMEOUT) ---
async def read_battery_and_report(mac_address, config, mqtt_client, known_devices, semaphore): 
    
    async with semaphore: 
        
        # --- KORRIGIERTE PAUSE-LOGIK MIT TIMEOUT ---
        wait_start_time = time.time()
        while os.path.exists(PAUSE_FILE):
            # Prüfe auf Timeout (15s sind mehr als die 10s Scan-Dauer des Daemons)
            if (time.time() - wait_start_time) > MAX_PAUSE_WAIT_SECONDS:
                logger.info("Timeout (>%ds) erreicht. Lösche die veraltete Pause-Datei (%s).", MAX_PAUSE_WAIT_SECONDS, PAUSE_FILE)
                try:
                    os.remove(PAUSE_FILE)
                except OSError as e:
                    logger.error("Konnte %s nicht löschen: %s", PAUSE_FILE, e)
                break # Lock ist alt, breche die Schleife ab
            
            logger.debug("Scan-Daemon (%s) ist aktiv. Warte 5s...", PAUSE_FILE)
            await asyncio.sleep(5)
        # --- ENDE KORRIGIERTE LOGIK ---
        
        mac_address = mac_address.upper()
        alias = known_devices.get(mac_address, "N/A (Read-Befehl)")
        
        battery_level = -1 
        device_name = "N/A (Direct-Read)" 
        rssi = -100 
        status = "offline" 

        # Lade Timing-Einstellungen (Sicherheitscheck)
        retries = 1
        retry_delay = 5
        connect_timeout = 10.0
        post_connect_delay = 1.0
        report_offline = False

        if config.has_section('General'):
            retries = config.getint('General', 'battery_retries', fallback=1) 
            retry_delay = config.getint('General', 'battery_retry_delay', fallback=5) 
            connect_timeout = config.getfloat('General', 'battery_connect_timeout', fallback=10.0)
            post_connect_delay = config.getfloat('General', 'battery_post_connect_delay', fallback=1.0)
            report_offline = config.getboolean('General', 'report_offline_battery', fallback=False)
        else:
            logger.warning("Sektion [General] in %s nicht gefunden. Verwende Standard-Timings.", CONFIG_FILE)
        
        bt_logger.info("Lese Batterie von %s (%s) (Max. %d Versuch(e))", mac_address, alias, retries)

        for attempt in range(retries):
            bt_logger.info("Versuch %d/%d mit %s", attempt + 1, retries, mac_address)
            try:
                async with BleakClient(mac_address, timeout=connect_timeout) as client:
                    if not client.is_connected:
                        raise BleakError("Client konnte sich nicht verbinden.")
                    bt_logger.info("Erfolgreich verbunden (Versuch %d)", attempt + 1)
                    if post_connect_delay > 0:
                        bt_logger.debug("Warte %.1fs (Post-Connect-Delay)", post_connect_delay)
                        await asyncio.sleep(post_connect_delay)
                    
                    value_bytes = await client.read_gatt_char(BATTERY_LEVEL_UUID)
                    battery_level = int(value_bytes[0]) 
                    status = "online" 
                    bt_logger.info("Batterie von %s gelesen: %d%%", mac_address, battery_level)
                    break 
            except (asyncio.TimeoutError, BleakError) as e:
                bt_logger.warning("Fehler (Versuch %d): Verbindung fehlgeschlagen", attempt + 1)
            except Exception as e:
                logger.error("Allgemeiner Fehler (Versuch %d): %s", attempt + 1, e, exc_info=True)

            if status == "offline" and (attempt + 1) < retries:
                bt_logger.info("Warte %ds vor dem nächsten Versuch", retry_delay)
                await asyncio.sleep(retry_delay) 
        
        # --- DATEN-PAKET ERSTELLEN ---
        current_timestamp = int(time.time())
        
        if status == "offline":
            bt_logger.info("Gerät %s ist offline. Lese alten Batteriewert aus Status-Datei", mac_address)
            old_battery_percent = -1 
            async with file_lock: 
                try:
                    if os.path.exists(BATTERY_STATUS_FILE):
                        with open(BATTERY_STATUS_FILE, "r") as f:
                            all_status_data = json.load(f)
                            if mac_address in all_status_data:
                                old_battery_percent = all_status_data[mac_address].get('battery_percent', -1) 
                                bt_logger.debug("Alter Batteriewert gefunden: %d%%", old_battery_percent)
                except Exception as e:
                    logger.warning("Konnte alten Batteriestatus nicht lesen: %s", e)
            
            battery_level = old_battery_percent 

        data_payload_dict = {
            "hostname": SYSTEM_HOSTNAME,
            "address": mac_address,
            "is_online": 1 if status == "online" else 0,
            "battery_percent": battery_level, 
            "name": device_name, 
            "alias": alias, 
            "rssi": rssi, 
            "timestamp": current_timestamp
        }
        
        status_file_data = {
            "timestamp": current_timestamp,
            "battery_percent": battery_level, 
            "status": status 
        }
        await update_battery_status_file(mac_address, status_file_data)
        
        if status == "offline" and not report_offline:
            bt_logger.info("Gerät ist offline. Senden wird (gemäß config.ini) übersprungen.")
        else:
            bt_logger.debug("Sende Status-Update an MQTT/UDP")
            data_payload_json = json.dumps(data_payload_dict)
            base_topic = config.get('MQTT', 'battery_topic', fallback='ble/scan/battery')
            safe_mac = mac_address.replace(":", "")
            full_topic = f"{base_topic}/{safe_mac}"

            send_udp(data_payload_json, config)
            send_mqtt(data_payload_json, full_topic, config, mqtt_client)

    # Hardware-Reset
    bt_logger.debug("%s Abfrage beendet. Erzwungener Hardware-Reset", mac_address)
    await asyncio.sleep(0.1) 
    reset_bluetooth_stack()
    
# --- 5. Kernfunktion: DISCOVER (MIT TIMEOUT)---
# --- 5. Kernfunktion: DISCOVER (MIT TIMEOUT)---
async def discover_and_save(scan_duration):
    """Führt einen Discovery-Scan durch und speichert alle gefundenen Geräte in scan_results.json."""
    
    # --- KORRIGIERTE PAUSE-LOGIK MIT TIMEOUT ---
    scan_logger.info("Discover: Warte, bis der Scan-Daemon pausiert...")
    wait_start_time = time.time()
    while os.path.exists(PAUSE_FILE):
        if (time.time() - wait_start_time) > MAX_PAUSE_WAIT_SECONDS:
            scan_logger.info("FIX: Timeout (>%ds) erreicht. Lösche die veraltete Pause-Datei (%s).", MAX_PAUSE_WAIT_SECONDS, PAUSE_FILE)
            try:
                os.remove(PAUSE_FILE)
            except OSError as e:
                logger.error("FEHLER: Konnte %s nicht löschen: %s", PAUSE_FILE, e)
            break
            
        scan_logger.info("PAUSE: Scan-Daemon (%s) ist aktiv. Warte 5s...", PAUSE_FILE)
        await asyncio.sleep(5)
    
    scan_logger.info("Discover: Scan-Daemon ist pausiert. Übernehme Bluetooth-Adapter...")
    reset_bluetooth_stack() 
    scan_logger.info("Warte 3 Sekunden, bis der Adapter initialisiert ist...")
    await asyncio.sleep(3) 
    # --- ENDE KORRIGIERTE LOGIK ---
    
    scan_logger.info("Suche nach ALLEN Geräten für %d Sekunden...", scan_duration)
    
    found_devices = {} 
    
    def detection_callback(device, advertisement_data):
        current_rssi = advertisement_data.rssi
        
        if device.address not in found_devices or current_rssi > found_devices[device.address]["rssi"]:
            found_devices[device.address] = {
                "name": device.name or "Unknown",
                "rssi": current_rssi 
            }
        scan_logger.debug("Gefunden: %s (Name: %s, RSSI: %d)", device.address, device.name, current_rssi)
    
    scanner = BleakScanner(detection_callback=detection_callback)
    
    try:
        await scanner.start()
        await asyncio.sleep(float(scan_duration))
        await scanner.stop()
    except BleakError as e:
        logger.error("Fehler beim Scannen: %s", e)
        return
    
    scan_logger.info("Scan beendet. %d einzigartige Geräte gefunden.", len(found_devices))
    
    scan_logger.info("Sortiere Ergebnisse nach MAC-Adresse...")
    sorted_devices = dict(sorted(found_devices.items()))
    
    output_data = {
        "scan_timestamp": int(time.time()),
        "scan_duration_seconds": scan_duration,
        "devices_found": len(found_devices),
        "devices": sorted_devices
    }
    
    try:
        with open(DISCOVER_RESULTS_FILE, "w") as f:
            json.dump(output_data, f, indent=4)
        scan_logger.info("Ergebnisse erfolgreich in '%s' gespeichert.", DISCOVER_RESULTS_FILE)
    except IOError as e:
        logger.error("Fehler beim Schreiben der Datei '%s': %s", DISCOVER_RESULTS_FILE, e)


# --- 6. NEUE KERNFUNKTION: DER DAEMON (KORRIGIERT FÜR SOFORTIGES RELOAD) ---
async def run_scan_daemon():
    """
    Der permanente Scan-Dienst. Lädt bei jedem Durchlauf Konfiguration und Geräteliste neu.
    """
    logger.info("--- BLE Scan Daemon wird gestartet (PID: %d) ---", os.getpid())
    
    # Lade MQTT initial (dient nur als Platzhalter für die erste Konfiguration)
    config = load_config()
    mqtt_client = setup_mqtt_client(config)
    
    scan_timeout = 10 
    reload_interval_seconds = 300 # Nur für MQTT-Check und langlebige Config
    last_reload_time = time.time()
    
    while True:
        try:
            # --- 0. KONFIGURATION UND GERÄTELISTE BEI JEDEM DURCHLAUF NEU LADEN ---
            # (Löst das Problem des Settings-Neustarts)
            config = load_config()
            known_devices = load_known_devices()

            # Lade die Pausenzeit (Sicherheitscheck, basiert auf der FRISCHEN Config)
            battery_pause_duration = 30 
            if config.has_section('General'):
                battery_pause_duration = config.getint('General', 'battery_pause_duration', fallback=30)
            
            # --- 1. MQTT/schwere Dienste regelmäßig neu laden ---
            current_time = time.time()
            if (current_time - last_reload_time) > reload_interval_seconds:
                logger.info("[Daemon] Prüfe MQTT-Verbindung und langlebige Konfiguration")
                last_reload_time = current_time
                
                # MQTT-Verbindung prüfen/neu aufbauen
                if mqtt_client is None or not mqtt_client.is_connected():
                    logger.warning("[Daemon] MQTT-Verbindung verloren oder noch nicht vorhanden. Versuche Reconnect")
                    disconnect_mqtt(mqtt_client)
                    # Verwende die FRISCH geladene Config von oben
                    mqtt_client = setup_mqtt_client(config)

            # --- 2. Pausendauer bestimmen ---
            if os.path.exists(BATTERY_JOB_LOCK):
                pause_duration = battery_pause_duration
                logger.info("[Daemon] BATTERIE-MODUS: %ds Pause nach Scan", pause_duration)
            else:
                pause_duration = 5
            
            # --- 3. Den PHP-Batterie-Job (falls er läuft) pausieren ---
            async with pause_lock:
                if not os.path.exists(PAUSE_FILE):
                    open(PAUSE_FILE, 'a').close()

            # --- 4. Scannen ---
            await scan_and_report(scan_timeout, config, known_devices, mqtt_client)
            
            # --- 5. Pause für PHP-Job aufheben ---
            async with pause_lock:
                if os.path.exists(PAUSE_FILE):
                    os.remove(PAUSE_FILE)
            
            # --- 6. Warten (5s oder 30s) ---
            logger.debug("[Daemon] Warte für %d Sekunden", pause_duration)
            await asyncio.sleep(pause_duration)
            
        except Exception as e:
            logger.critical("FATALER FEHLER in der Daemon-Hauptschleife: %s", e, exc_info=True)
            await asyncio.sleep(30)

# --- 7. HAUPTFUNKTION (Argumenten-Logik) (KORRIGIERT MIT PRE-CHECK) ---
async def main():
    parser = argparse.ArgumentParser(
        description="Python BLE-Tool: Scannen, Batterie-Auslesen oder Entdecken.",
        epilog="Beispiele:\n"
               "  sudo python3 %(prog)s scan -t 5    (Meldet bekannte Geräte an MQTT/UDP)\n"
               "  sudo python3 %(prog)s read -m AA:BB... -m CC:DD... (Liest Batterie(n) und meldet an MQTT/UDP)\n"
               "  sudo python3 %(prog)s discover -t 10 (Findet alle Geräte und speichert sie in scan_results.json)\n"
               "  sudo python3 %(prog)s run_scan_daemon (Startet den 24/7 Scan-Dienst)",
        formatter_class=argparse.RawTextHelpFormatter 
    )
    
    parser.add_argument(
        '--log-level',
        choices=['DEBUG', 'INFO', 'WARNING', 'ERROR', 'CRITICAL'],
        default=None,
        help='Überschreibt Log-Level aus config.ini (nur für diesen Lauf)'
    )
    
    subparsers = parser.add_subparsers(dest="command", required=True, help="Auszuführende Aktion")

    parser_scan = subparsers.add_parser("scan", help="Nach bekannten BLE-Geräten suchen und Fund melden (nutzt config.ini).")
    parser_scan.add_argument(
        "-t", "--timeout", type=int, default=10, 
        help="Dauer des Scans in Sekunden (Standard: 10)"
    )
    parser_read = subparsers.add_parser("read", help="Den Batteriestand (0x2a19) eines Geräts auslesen und melden (nutzt config.init).")
    parser_read.add_argument(
        "-m", "--mac", 
        required=True,
        nargs='+', 
        help="Eine oder mehrere MAC-Adressen (getrennt durch Leerzeichen)."
    )
    parser_discover = subparsers.add_parser("discover", help="Alle Geräte in Reichweite finden und in 'scan_results.json' speichern.")
    parser_discover.add_argument(
        "-t", "--timeout", type=int, default=10, 
        help="Dauer des Scans in Sekunden (Standard: 10)"
    )
    parser_read_enabled = subparsers.add_parser("read_enabled_batteries", help="Liest alle in der Konfigurationsdatei aktivierten Batteriestände.")
    parser_daemon = subparsers.add_parser("run_scan_daemon", help="Startet den permanenten 24/7 Scan-Dienst.")

    args = parser.parse_args()

    if args.command == "run_scan_daemon":
        await run_scan_daemon()
        return 

    if args.log_level:
        level = getattr(logging, args.log_level)
        logging.getLogger().setLevel(level)
        for handler in logging.getLogger().handlers:
            handler.setLevel(level)
        logger.info("Log-Level überschrieben auf: %s", args.log_level)
        
    if args.command in ["read", "read_enabled_batteries", "discover"]:
        # Dieser Check läuft synchron, bevor asyncio startet, um das hängende Lock zu entfernen
        cleanup_stale_pause_file() 

    config = None
    mqtt_client = None
    known_devices = {}
    
    if args.command in ["scan", "read", "read_enabled_batteries"]:
        config = load_config()
        if not config.sections(): 
            logger.error("Befehl '%s' benötigt eine gültige '%s'.", args.command, CONFIG_FILE)
            sys.exit(1)
        mqtt_client = setup_mqtt_client(config)
        known_devices = load_known_devices() 

    try:
        if args.command == "scan":
            if not known_devices:
                logger.warning("%s ist leer. Scan-Befehl wird keine Geräte melden.", KNOWN_DEVICES_FILE)
            await scan_and_report(args.timeout, config, known_devices, mqtt_client)
            
        elif args.command == "read":
            macs_to_process = args.mac
            parallel_limit = 1 
            read_semaphore = asyncio.Semaphore(parallel_limit) 
            
            logger.info("Starte seriellen Batterie-Scan für %d Gerät(e) (Manuell). Max. %d gleichzeitig", len(macs_to_process), parallel_limit)
            
            tasks = []
            for mac in macs_to_process:
                tasks.append(
                    read_battery_and_report(mac, config, mqtt_client, known_devices, read_semaphore)
                )
            
            await asyncio.gather(*tasks)
            logger.info("Batterie-Scan für alle angeforderten Geräte abgeschlossen.")
            
        elif args.command == "read_enabled_batteries":
      
            current_timestamp = int(time.time())
            try:
                with open(LAST_BATTERY_SCAN_FILE, "w") as f:
                    f.write(str(current_timestamp))
                logger.info(f"Zeitstempel für Start des Batterie-Scans gespeichert: {current_timestamp}")
            except IOError as e:
                logger.error("Konnte Zeitstempeldatei {LAST_BATTERY_SCAN_FILE} nicht schreiben: {e}")

            macs_to_process = []
            try:
                with open(KNOWN_DEVICES_FILE, "r") as f:
                    for line in f:
                        parts = line.strip().split(",", 2)
                        if len(parts) == 3 and parts[2].strip() == '1':
                            macs_to_process.append(parts[0].upper().strip())
            except FileNotFoundError:
                logger.error("%s nicht gefunden.", KNOWN_DEVICES_FILE)
                return

            if not macs_to_process:
                logger.info("Keine Geräte für den Batterie-Scan aktiviert.")
                return

            parallel_limit = 1
            if config.has_section('General'):
                parallel_limit = config.getint('General', 'max_parallel_reads', fallback=1)
            
            read_semaphore = asyncio.Semaphore(parallel_limit) 

            logger.info("[INFO Starte parallelen Batterie-Scan für {len(macs_to_process)} Gerät(e) (Aktiviert). Max. {parallel_limit} gleichzeitig...")

            tasks = []
            for mac in macs_to_process:
                tasks.append(
                    read_battery_and_report(mac, config, mqtt_client, known_devices, read_semaphore)
                )

            await asyncio.gather(*tasks)
            logger.info("[INFO Batterie-Scan für alle angeforderten Geräte abgeschlossen.")

        elif args.command == "discover":
            await discover_and_save(args.timeout)
            
    finally:
        if mqtt_client:
            disconnect_mqtt(mqtt_client)
        logger.info("[INFO] Skript beendet.")


# --- Skript-Start ---
if __name__ == "__main__":
    
    if os.geteuid() != 0:
        if any(cmd in sys.argv for cmd in ['run_scan_daemon', 'read', 'scan', 'read_enabled_batteries']):
            logger.error("[FEHLER] Diese Befehle müssen mit sudo/als root gestartet werden (wegen hciconfig).")
            sys.exit(1)

    if len(sys.argv) == 1:
        parser = argparse.ArgumentParser(description="Python BLE-Tool")
        print("Fehler: Bitte einen Befehl angeben (run_scan_daemon, scan, read, discover).")
        print("Führe das Skript mit -h aus, um Hilfe zu sehen.")
        sys.exit(1)
        
    asyncio.run(main()) 