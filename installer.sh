#!/bin/bash

# === KONFIGURATION ===
ZIP_FILE="ble.zip"
WEB_DIR="/var/www/html"
APP_DIR_NAME="ble"
APP_DIR="$WEB_DIR/$APP_DIR_NAME"
WEB_USER="www-data"
LOG_DIR="/var/log/ble"

# === 1. ROOT-PRÜFUNG ===
if [ "$EUID" -ne 0 ]; then
  echo "Fehler: Dieses Skript muss mit sudo ausgeführt werden."
  exit 1
fi

echo "--- (1/15) Starte BLE WebUI Installation (Master-Client Version 2.0) ---"

# === 2. SYSTEM-PAKETE INSTALLIEREN ===
echo "--- (2/15) Aktualisiere Paketlisten (apt update) ---"
apt update

echo "--- (3/15) Installiere System-Abhängigkeiten ---"
apt install -y \
    sudo \
    python3-pip \
    python3-dev \
    python3-dbus \
    libffi-dev \
    python3-bleak \
    python3-paho-mqtt \
    apache2 \
    php \
    libapache2-mod-php \
    php-curl \
    apache2-utils \
    unzip \
    bluez-tools \
    pi-bluetooth \
    curl

# === 3b. PYTHON-MODULE PRÜFEN ===
echo "--- (3b/15) Prüfe Python-Module ---"
python3 -c "import paho.mqtt.client; print('✓ paho-mqtt installiert')" 2>/dev/null || echo "✗ paho-mqtt fehlt!"
python3 -c "import bleak; print('✓ bleak installiert')" 2>/dev/null || echo "✗ bleak fehlt!"

# === 4. DATEIEN KOPIEREN UND VORBEREITEN ===
echo "--- (4/15) Erstelle Verzeichnisse und entpacke Dateien ---"
if [ ! -f "$ZIP_FILE" ]; then
    echo "FEHLER: Die Datei '$ZIP_FILE' wurde nicht gefunden."
    exit 1
fi

# Backup alte Installation
if [ -d "$APP_DIR" ]; then
    echo "Sichere alte Installation..."
    BACKUP_DIR="$APP_DIR.backup.$(date +%Y%m%d_%H%M%S)"
    cp -r "$APP_DIR" "$BACKUP_DIR"
    echo "Backup erstellt: $BACKUP_DIR"
fi

mkdir -p "$APP_DIR"
unzip -o "$ZIP_FILE" -d "$APP_DIR"

echo "<?php header('Location: /$APP_DIR_NAME/devices.php'); exit; ?>" > "$WEB_DIR/index.php"
echo "Entferne Standard-Apache-Seite (index.html)..."
rm -f "$WEB_DIR/index.html"

# === 4b. LOG-VERZEICHNIS ERSTELLEN ===
echo "--- (4b/15) Erstelle Log-Verzeichnis ---"
mkdir -p "$LOG_DIR"
chown "$WEB_USER:$WEB_USER" "$LOG_DIR"
chmod 755 "$LOG_DIR"
echo "✓ Log-Verzeichnis erstellt: $LOG_DIR"

# === 4c. STANDARD-KONFIGURATIONSDATEIEN ERSTELLEN ===
echo "--- (4c/15) Erstelle Standard-Konfigurationsdateien (falls fehlend) ---"

# config.ini (MIT MasterClient Sektion)
CONFIG_FILE="$APP_DIR/config.ini"
if [ ! -f "$CONFIG_FILE" ]; then
    echo "Erstelle Standard-config.ini..."
    cat > "$CONFIG_FILE" << 'EOL'
[General]
report_offline_battery = false
battery_retries = 2
battery_retry_delay = 10
battery_post_connect_delay = 3
battery_connect_timeout = 20

[UDP]
enabled = false
host = 192.168.xxx.xxx
port = 7002

[MQTT]
enabled = false
broker = 192.168.xxx.xxx
port = 1883
scan_topic = ble/discovery
battery_topic = ble/battery
username = 
password = 

[discover]
timeout = 20

[Cron]
battery_scan_time = 03:00

[Logging]
log_level = ERROR
console_level = INFO
bleak_level = WARNING

[MasterClient]
mode = standalone
master_url = 
api_key = 
fallback_poll_interval = 1800

EOL
    chown "$WEB_USER:$WEB_USER" "$CONFIG_FILE"
    chmod 664 "$CONFIG_FILE"
    echo "✓ config.ini erstellt (mit Master-Client Support)"
else
    # Prüfe ob [MasterClient] Sektion existiert, falls nicht hinzufügen
    if ! grep -q "\[MasterClient\]" "$CONFIG_FILE"; then
        echo "Ergänze [MasterClient] Sektion in bestehender config.ini..."
        cat >> "$CONFIG_FILE" << 'EOL'

[MasterClient]
mode = standalone
master_url = 
api_key = 
fallback_poll_interval = 1800
EOL
        echo "✓ [MasterClient] Sektion hinzugefügt"
    else
        echo "✓ config.ini existiert bereits (mit Master-Client Support)"
    fi
fi

# known_devices.txt
KNOWN_DEVICES="$APP_DIR/known_devices.txt"
if [ ! -f "$KNOWN_DEVICES" ]; then
    echo "Erstelle leere known_devices.txt..."
    cat > "$KNOWN_DEVICES" << 'EOL'
# Format: MAC-Adresse,Alias,Batterie-Scan aktiviert (1=ja, 0=nein)
# Beispiel:
# AA:BB:CC:DD:EE:FF,Mein Bluetooth Gerät,1
EOL
    chown "$WEB_USER:$WEB_USER" "$KNOWN_DEVICES"
    chmod 664 "$KNOWN_DEVICES"
    echo "✓ known_devices.txt erstellt"
else
    echo "✓ known_devices.txt existiert bereits"
fi

# clients.json (NEU für Master-Client)
CLIENTS_FILE="$APP_DIR/clients.json"
if [ ! -f "$CLIENTS_FILE" ]; then
    echo "Erstelle leere clients.json für Master-Client-Verwaltung..."
    echo "[]" > "$CLIENTS_FILE"
    chown "$WEB_USER:$WEB_USER" "$CLIENTS_FILE"
    chmod 664 "$CLIENTS_FILE"
    echo "✓ clients.json erstellt"
else
    echo "✓ clients.json existiert bereits"
fi

# === 5. HELFER-SKRIPTE ERSTELLEN ===
echo "--- (5/15) Erstelle Helfer-Skripte (Hostname, Netzwerk, Reboot) ---"
HOSTNAME_SCRIPT="/usr/local/bin/ble_set_hostname.sh"
NETWORK_SCRIPT="/usr/local/bin/ble_set_network.sh"
REBOOT_SCRIPT="/usr/local/bin/ble_reboot.sh"
REBOOT_PATH=$(which reboot || echo "/sbin/reboot")

# 5a. Hostname-Skript (mit /etc/hosts fix)
cat > "$HOSTNAME_SCRIPT" << "EOL"
#!/bin/bash
NEW_HOSTNAME=$1
OLD_HOSTNAME=$(hostname)
if [[ -z "$NEW_HOSTNAME" ]] || ! [[ "$NEW_HOSTNAME" =~ ^[a-zA-Z0-9][a-zA-Z0-9-]{0,61}[a-zA-Z0-9]$ ]]; then
    echo "Fehler: Ungültiger Hostname."
    exit 1
fi
echo "Setze Hostname auf: $NEW_HOSTNAME"
hostnamectl set-hostname "$NEW_HOSTNAME"
if [ -f /etc/hosts ]; then
    echo "Passe /etc/hosts an..."
    sed -i "s/$OLD_HOSTNAME/$NEW_HOSTNAME/g" /etc/hosts
else
    echo "Warnung: /etc/hosts nicht gefunden."
fi
echo "Hostname geändert. Ein Neustart ist erforderlich."
EOL
chmod +x "$HOSTNAME_SCRIPT"

# 5b. Reboot-Skript (mit nohup fix)
cat > "$REBOOT_SCRIPT" << EOL
#!/bin/bash
echo "Reboot-Befehl wurde an das System übergeben."
nohup bash -c "sleep 2 && $REBOOT_PATH -f" > /dev/null 2>&1 &
exit 0
EOL
chmod +x "$REBOOT_SCRIPT"

# 5c. Netzwerk-Skript (für /etc/network/interfaces)
cat > "$NETWORK_SCRIPT" << "EOL"
#!/bin/bash
# BLE-Manager Network Script (für /etc/network/interfaces)
INTERFACE=$1
MODE=$2
CONF_FILE="/etc/network/interfaces"

if [[ -z "$INTERFACE" ]] || [[ -z "$MODE" ]]; then
    echo "Fehler: Interface oder Modus nicht angegeben."
    exit 1
fi

# Erstelle ein Backup
cp "$CONF_FILE" "$CONF_FILE.bak"

# Schreibe die Basis-Konfig (Loopback)
cat > "$CONF_FILE" << EOF
# Von BLE-Manager verwaltet
auto lo
iface lo inet loopback

EOF

# Füge eth0 hinzu
if [ "$INTERFACE" = "eth0" ]; then
    if [ "$MODE" = "static" ]; then
        IP_WITH_MASK=$3 # z.B. 192.168.1.100/24
        GW=$4
        DNS=$5
        
        IP=$(echo $IP_WITH_MASK | cut -d'/' -f1)
        CIDR=$(echo $IP_WITH_MASK | cut -d'/' -f2)
        if [ "$CIDR" = "24" ]; then MASK="255.255.255.0"; fi
        if [ "$CIDR" = "16" ]; then MASK="255.255.0.0"; fi
        if [ -z "$MASK" ]; then 
            echo "Fehler: Nur /24 oder /16 Masken werden unterstützt."
            exit 1
        fi

        echo "Setze eth0 auf Static IP $IP..."
        cat >> "$CONF_FILE" << EOF
auto eth0
iface eth0 inet static
    address $IP
    netmask $MASK
    gateway $GW
    dns-nameservers $DNS

EOF
    else
        echo "Setze eth0 auf DHCP..."
        cat >> "$CONF_FILE" << EOF
auto eth0
iface eth0 inet dhcp

EOF
    fi
    echo "Füge wlan0 als DHCP hinzu..."
    cat >> "$CONF_FILE" << EOF
allow-hotplug wlan0
iface wlan0 inet dhcp
    pre-up wpa_supplicant -B -i wlan0 -c /etc/wpa_supplicant/wpa_supplicant.conf
    post-down killall -q wpa_supplicant

EOF

elif [ "$INTERFACE" = "wlan0" ]; then
    echo "Füge eth0 als DHCP hinzu..."
    cat >> "$CONF_FILE" << EOF
auto eth0
iface eth0 inet dhcp

EOF
    if [ "$MODE" = "static" ]; then
        IP_WITH_MASK=$3
        GW=$4
        DNS=$5
        IP=$(echo $IP_WITH_MASK | cut -d'/' -f1)
        CIDR=$(echo $IP_WITH_MASK | cut -d'/' -f2)
        if [ "$CIDR" = "24" ]; then MASK="255.255.255.0"; fi
        if [ "$CIDR" = "16" ]; then MASK="255.255.0.0"; fi
        if [ -z "$MASK" ]; then 
            echo "Fehler: Nur /24 oder /16 Masken werden unterstützt."
            exit 1
        fi

        echo "Setze wlan0 auf Static IP $IP..."
        cat >> "$CONF_FILE" << EOF
allow-hotplug wlan0
iface wlan0 inet static
    address $IP
    netmask $MASK
    gateway $GW
    dns-nameservers $DNS
    pre-up wpa_supplicant -B -i wlan0 -c /etc/wpa_supplicant/wpa_supplicant.conf
    post-down killall -q wpa_supplicant

EOF
    else
        echo "Setze wlan0 auf DHCP..."
        cat >> "$CONF_FILE" << EOF
allow-hotplug wlan0
iface wlan0 inet dhcp
    pre-up wpa_supplicant -B -i wlan0 -c /etc/wpa_supplicant/wpa_supplicant.conf
    post-down killall -q wpa_supplicant

EOF
    fi
fi

echo "Netzwerkeinstellungen geändert. Neustart erforderlich."
exit 0
EOL
chmod +x "$NETWORK_SCRIPT"

# === 6. BERECHTIGUNGEN SETZEN ===
echo "--- (6/15) Setze Datei- und Gruppenberechtigungen ---"
echo "Setze Eigentümer auf $WEB_USER..."
chown -R "$WEB_USER:$WEB_USER" "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 755 {} +
find "$APP_DIR" -type f -exec chmod 644 {} +           
find "$APP_DIR" -type f \( -name "*.txt" -o -name "*.json" -o -name "*.ini" \) -exec chmod 664 {} +
find "$APP_DIR" -type f -name "*.py" -exec chmod 755 {} +

# NEU: Spezielle Berechtigungen für Master-Client Dateien
if [ -f "$APP_DIR/api.php" ]; then
    chmod 755 "$APP_DIR/api.php"
    echo "✓ api.php ausführbar gemacht"
fi
if [ -f "$APP_DIR/sync_client.php" ]; then
    chmod 755 "$APP_DIR/sync_client.php"
    echo "✓ sync_client.php ausführbar gemacht"
fi

echo "Füge $WEB_USER zur 'bluetooth' Gruppe hinzu..."
usermod -a -G bluetooth "$WEB_USER"
usermod -a -G "$WEB_USER" root 

echo "Erstelle sudoers-Regeln..."
SUDOERS_FILE="/etc/sudoers.d/99-ble-manager"
PYTHON_PATH=$(which python3)
PHP_PATH=$(which php)
HTPASSWD_PATH=$(which htpasswd)
SCRIPT_PATH=$(readlink -f "$APP_DIR/ble_tool.py")
SYSTEMCTL_PATH=$(which systemctl)
CRONTAB_PATH=$(which crontab)
CURL_PATH=$(which curl) 
TRUNCATE_PATH=$(which truncate) 

{
    echo "$WEB_USER ALL=(ALL) NOPASSWD: $PYTHON_PATH $SCRIPT_PATH *"
    echo "$WEB_USER ALL=(ALL) NOPASSWD: $HTPASSWD_PATH *"
    echo "$WEB_USER ALL=(ALL) NOPASSWD: $HOSTNAME_SCRIPT *"
    echo "$WEB_USER ALL=(ALL) NOPASSWD: $REBOOT_SCRIPT" 
    echo "$WEB_USER ALL=(ALL) NOPASSWD: $NETWORK_SCRIPT *"
    echo "$WEB_USER ALL=(ALL) NOPASSWD: $SYSTEMCTL_PATH restart ble_tool.service"
    echo "$WEB_USER ALL=(ALL) NOPASSWD: $SYSTEMCTL_PATH reload ble_tool.service"
    echo "$WEB_USER ALL=(ALL) NOPASSWD: $SYSTEMCTL_PATH stop ble_tool.service"
    echo "$WEB_USER ALL=(ALL) NOPASSWD: $SYSTEMCTL_PATH start ble_tool.service"
    echo "$WEB_USER ALL=(ALL) NOPASSWD: $SYSTEMCTL_PATH status ble_tool.service"
    echo "$WEB_USER ALL=(ALL) NOPASSWD: $CRONTAB_PATH"
    echo "$WEB_USER ALL=(ALL) NOPASSWD: $TRUNCATE_PATH -s 0 $LOG_DIR/*.log"
} > "$SUDOERS_FILE"
chmod 0440 "$SUDOERS_FILE"
echo "✓ sudoers konfiguriert (inkl. crontab für Master-Client Sync)"

# === 7. APACHE KONFIGURIEREN ===
echo "--- (7/15) Konfiguriere Apache (AllowOverride All) ---"
APACHE_CONF="/etc/apache2/sites-available/000-default.conf"
if ! grep -q "AllowOverride All" "$APACHE_CONF"; then
    echo "Füge 'AllowOverride All' zur Apache-Konfiguration hinzu..."
    sed -i '/DocumentRoot \/var\/www\/html/a \    <Directory /var/www/html>\n        Options Indexes FollowSymLinks\n        AllowOverride All\n        Require all granted\n    </Directory>' "$APACHE_CONF"
else
    echo "✓ Apache 'AllowOverride All' ist bereits gesetzt."
fi

# === 8. DIENSTE AKTIVIEREN ===
echo "--- (8/15) Aktiviere Dienste (Apache & Bluetooth) ---"
a2enmod rewrite
a2enmod authz_core
a2enmod authn_file
systemctl restart apache2

echo "Aktiviere Bluetooth-Dienste..."
sed -i '/^[[:blank:]]*dtoverlay=disable-bt/d' /boot/config.txt 2>/dev/null || true
rm -f /etc/modprobe.d/dietpi-disable_bluetooth.conf
systemctl enable --now bluetooth

if systemctl list-unit-files | grep -q hciuart.service; then
    echo "Info: hciuart.service gefunden. Aktiviere es..."
    systemctl enable hciuart
else
    echo "Info: hciuart.service nicht gefunden. Überspringe."
fi

# === 8b. BLUETOOTH-ADAPTER TESTEN ===
echo "--- (8b/15) Teste Bluetooth-Adapter ---"
if hciconfig hci0 > /dev/null 2>&1; then
    echo "✓ Bluetooth-Adapter hci0 gefunden"
else
    echo "✗ WARNUNG: Bluetooth-Adapter hci0 nicht gefunden!"
    echo "  Möglicherweise unterstützt dieses System kein Bluetooth."
fi

# === 9. SYSTEMD SERVICE ERSTELLEN ===
echo "--- (9/15) Erstelle ble_tool systemd Service ---"
SERVICE_FILE="/etc/systemd/system/ble_tool.service"
PYTHON_PATH=$(which python3)
SCRIPT_PATH=$(readlink -f "$APP_DIR/ble_tool.py")

if [ ! -f "$SCRIPT_PATH" ]; then
    echo "FEHLER: Die Datei '$SCRIPT_PATH' wurde nicht gefunden."
    exit 1
fi

cat > "$SERVICE_FILE" << EOL
[Unit]
Description=BLE Presence Scan Daemon
Wants=bluetooth.target
After=bluetooth.target network.target

[Service]
Type=simple
User=root
Group=root
ExecStart=$PYTHON_PATH $SCRIPT_PATH run_scan_daemon
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
EOL

echo "Aktiviere und starte ble_tool Service..."
systemctl daemon-reload
systemctl enable ble_tool.service
systemctl start ble_tool.service

# === 10. CRON JOB ERSTELLEN ===
echo "--- (10/15) Erstelle Cron-Job für nächtlichen Batterie-Scan ---"
(crontab -u $WEB_USER -l 2>/dev/null | grep -v "action.php?cmd=scan") | crontab -u $WEB_USER -
(crontab -u $WEB_USER -l 2>/dev/null | grep -v "action.php?cmd=smart_scan") | crontab -u $WEB_USER -
(crontab -u $WEB_USER -l 2>/dev/null | grep -v "action.php?cmd=read_enabled_batteries") | crontab -u $WEB_USER -
CRON_JOB="0 3 * * * $CURL_PATH 'http://localhost/ble/action.php?cmd=read_enabled_batteries' > /dev/null 2>&1"
CRON_FILTER="action.php?cmd=read_enabled_batteries"
(crontab -u "$WEB_USER" -l 2>/dev/null | grep -v "$CRON_FILTER"; echo "$CRON_JOB") | crontab -u "$WEB_USER" -
echo "✓ Cron-Job für 03:00 Uhr erstellt"
echo "  (Fallback-Poll-Cron wird bei Client-Konfiguration automatisch erstellt)"

# === 11. STANDARD-BENUTZER ERSTELLEN ===
echo "--- (11/15) Erstelle Standard-WebUI-Benutzer (admin/admin) ---"
htpasswd -c -b "$APP_DIR/.htpasswd" admin admin
chown "$WEB_USER:$WEB_USER" "$APP_DIR/.htpasswd"
chmod 640 "$APP_DIR/.htpasswd"
echo "✓ Benutzer 'admin' erstellt"

# === 12. ABSCHLIESSENDE BERECHTIGUNGEN ===
echo "--- (12/15) Setze abschließende Berechtigungen ---"
touch "$APP_DIR/read_enabled_batteries.lock"
chown "$WEB_USER:$WEB_USER" "$APP_DIR/read_enabled_batteries.lock"
chmod 664 "$APP_DIR/read_enabled_batteries.lock"
rm -f "$APP_DIR/read_enabled_batteries.lock"

# === 13. SERVICE-STATUS PRÜFEN ===
echo "--- (13/15) Prüfe Service-Status ---"
systemctl is-active --quiet ble_tool.service && echo "✓ ble_tool.service läuft" || echo "✗ ble_tool.service läuft NICHT"
systemctl is-active --quiet apache2 && echo "✓ Apache läuft" || echo "✗ Apache läuft NICHT"
systemctl is-active --quiet bluetooth && echo "✓ Bluetooth läuft" || echo "✗ Bluetooth läuft NICHT"

# === 14. MASTER-CLIENT FEATURES INFO ===
echo "--- (14/15) Master-Client System Status ---"
if [ -f "$APP_DIR/master.php" ]; then
    echo "✓ Master-Client Dateien gefunden:"
    [ -f "$APP_DIR/api.php" ] && echo "  ✓ api.php"
    [ -f "$APP_DIR/master.php" ] && echo "  ✓ master.php"
    [ -f "$APP_DIR/sync_client.php" ] && echo "  ✓ sync_client.php"
    [ -f "$CLIENTS_FILE" ] && echo "  ✓ clients.json"
    echo ""
    echo "Master-Client Features aktiviert!"
    echo "  • Konfiguration: http://$(hostname -I | awk '{print $1}')/ble/config.php"
    echo "  • Master-Verwaltung: http://$(hostname -I | awk '{print $1}')/ble/master.php"
else
    echo "ℹ Standard-Installation (ohne Master-Client Features)"
fi

# === 15. INSTALLATIONS-ZUSAMMENFASSUNG ===
echo ""
echo "╔══════════════════════════════════════════════════════════════╗"
echo "║    BLE PRESENCE INSTALLATION ABGESCHLOSSEN (v2.0)            ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo ""
echo "WebUI:           http://$(hostname -I | awk '{print $1}')/ble/"
echo "Standard-Login:  admin / admin"
echo ""
echo "Verzeichnisse:"
echo "  App:           $APP_DIR"
echo "  Logs:          $LOG_DIR"
echo "  Konfiguration: $CONFIG_FILE"
echo ""
echo "Services:"
echo "  ble_tool:      systemctl status ble_tool.service"
echo "  Apache:        systemctl status apache2"
echo "  Bluetooth:     systemctl status bluetooth"
echo ""
echo "Cron-Job:        03:00 Uhr (Batterie-Scan)"
echo ""
echo "HINWEIS: Stelle sicher, dass Port 80 in der Firewall geöffnet ist."
echo "         sudo ufw allow 80/tcp  # (falls UFW aktiv)"
echo ""
echo "Master-Client Features:"
echo "  • Standalone-Modus (Standard)"
echo "  • Master-Modus: Verwaltet Clients"
echo "  • Client-Modus: Empfängt Daten vom Master"
echo "  • Konfigurierbar in: config.php → [MasterClient]"
echo ""
echo "═══════════════════════════════════════════════════════════════"
echo ""
echo "Das System muss jetzt neu gestartet werden, damit alle"
echo "Gruppen-Berechtigungen wirksam werden."
echo ""

read -p "Soll das System jetzt neu gestartet werden? (j/n) " REBOOT_NOW
if [ "$REBOOT_NOW" = "j" ] || [ "$REBOOT_NOW" = "J" ]; then
    echo "System wird neu gestartet..."
    reboot
else
    echo "Bitte starte das System manuell neu: sudo reboot"
fi
