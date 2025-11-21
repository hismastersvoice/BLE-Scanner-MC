# 🚀 **BLE Presence Daemon (v2.0)

**BLE Presence** ist die ideale Lösung, um die Anwesenheit von Bluetooth Low Energy (BLE)-Geräten in Ihrem Smart Home zu erfassen und deren Batteriestatus zu überwachen. Dieses Tool kombiniert einen performanten Python-Scan-Dienst mit einem komfortablen PHP-Webinterface und unterstützt nahtlos die Anbindung über **MQTT** und **UDP**.

----------

## I. 🛠️ Installation und System-Setup

Die Software ist für Debian-basierte Systeme (z.B. Raspberry Pi OS) optimiert.

### 1. Automatisierte Installation

Der mitgelieferte Shell-Installer (`installer.sh`) übernimmt alle notwendigen Schritte.

1.  **Vorbereitung:** Stellen Sie sicher, dass alle Projektdateien auf Ihrem Gerät vorhanden sind.
    
2.  **Ausführung:** Führen Sie das Installationsskript mit Root-Rechten aus:
    
    Bash
    

1.  ```
    sudo ./installer.sh
    
    ```
    

> ⚙️ **Was passiert im Hintergrund?**
> 
> -   Installation der Abhängigkeiten: **Apache2**, **PHP**, **Python3**, sowie die Bluetooth-Bibliotheken (`bluez`, `bleak`, `paho-mqtt`).
>     
> -   Konfiguration des **Systemd-Dienstes** (`ble_tool.service`): Dieser Daemon läuft 24/7 und sendet Anwesenheitsupdates.
>     
> -   Setzen der Berechtigungen: Spezielle **`cap_net_raw`**-Berechtigungen werden gesetzt, damit der Python-Dienst als `www-data` (oder unprivilegiert) BLE-Scans durchführen kann.
>     
> -   Einrichtung des Web-Verzeichnisses: Die UI ist unter `/var/www/html/ble/` abgelegt.
>     

3.  **Neustart:** Ein vollständiger Systemneustart ist **zwingend erforderlich**, um alle Bluetooth- und Gruppenberechtigungen wirksam zu machen.
    

### 2. Erster Login

Nach dem Neustart erreichen Sie das Webinterface über die IP-Adresse Ihres Geräts:

-   **URL:** `http://[Ihre-IP]/ble/`
    
-   **Standard-Zugang:** Benutzer: `admin`, Passwort: `admin`
    
----------

## II. 🔒 Konfiguration und Sicherheit

### 1. Sicherheit (`security.php`)

Ändern Sie das Standardpasswort sofort, um die WebUI zu schützen.

-   Navigieren Sie zu **Sicherheit**.
    
-   Geben Sie das **neue Passwort** für den Benutzer `admin` ein und speichern Sie es. Das System verschlüsselt das Passwort in der `.htpasswd`-Datei.
    

### 2. Hauptkonfiguration (`config.php`)

Definieren Sie hier das Verhalten des Scanners und die Zielsysteme.

#### A. Allgemeine Einstellungen (`[General]`)

Parameter

Beschreibung

Empfohlene Einstellung

**`scan_interval`**

Wartezeit (in Sekunden) zwischen zwei vollständigen Scans.

**15 - 30**

**`battery_retries`**

Wie oft versucht der Dienst, den Batteriestand auszulesen.

**2**

**`report_offline`**

Sollen Geräte als **`0` (offline)** gemeldet werden, wenn sie nicht gefunden werden?
**true**

**`report_offline_battery`**

Sollen fehlschlagende Batterie-Scans als `0%` gemeldet werden?
**false**


Es tut mir leid, wenn das Format in der vorherigen Antwort nicht Ihren Erwartungen an ein schönes Markdown-Format entsprochen hat! Manchmal führen die internen Konvertierungen zu Abweichungen.

Hier ist die umfassende Anleitung, **strikt in Markdown formatiert** und mit einer noch stärkeren Struktur, Emojis, Fettdruck und klaren Listen, wie von Ihnen gewünscht.

----------

# 🚀 **BLE Presence Daemon (v2.0) – Die Umfassende Anleitung**

**BLE Presence** ist die ideale Lösung, um die Anwesenheit von Bluetooth Low Energy (BLE)-Geräten in Ihrem Smart Home zu erfassen und deren Batteriestatus zu überwachen. Dieses Tool kombiniert einen performanten Python-Scan-Dienst mit einem komfortablen PHP-Webinterface und unterstützt nahtlos die Anbindung über **MQTT** und **UDP**.

----------

## I. 🛠️ Installation und System-Setup

Die Software ist für Debian-basierte Systeme (z.B. Raspberry Pi OS) optimiert.

### 1. Automatisierte Installation

Der mitgelieferte Shell-Installer (`installer.sh`) übernimmt alle notwendigen Schritte.

1.  **Vorbereitung:** Stellen Sie sicher, dass alle Projektdateien auf Ihrem Gerät vorhanden sind.
    
2.  **Ausführung:** Führen Sie das Installationsskript mit Root-Rechten aus:
    
    Bash
    

1.  ```
    sudo ./installer.sh
    
    ```
    

> ⚙️ **Was passiert im Hintergrund?**
> 
> -   Installation der Abhängigkeiten: **Apache2**, **PHP**, **Python3**, sowie die Bluetooth-Bibliotheken (`bluez`, `bleak`, `paho-mqtt`).
>     
> -   Konfiguration des **Systemd-Dienstes** (`ble_tool.service`): Dieser Daemon läuft 24/7 und sendet Anwesenheitsupdates.
>     
> -   Setzen der Berechtigungen: Spezielle **`cap_net_raw`**-Berechtigungen werden gesetzt, damit der Python-Dienst als `www-data` (oder unprivilegiert) BLE-Scans durchführen kann.
>     
> -   Einrichtung des Web-Verzeichnisses: Die UI ist unter `/var/www/html/ble/` abgelegt.
>     

3.  **Neustart:** Ein vollständiger Systemneustart ist **zwingend erforderlich**, um alle Bluetooth- und Gruppenberechtigungen wirksam zu machen.
    

### 2. Erster Login

Nach dem Neustart erreichen Sie das Webinterface über die IP-Adresse Ihres Geräts:

-   **URL:** `http://[Ihre-IP]/ble/`
    
-   **Standard-Zugang:** Benutzer: `admin`, Passwort: `admin`
    

----------

## II. 🔒 Konfiguration und Sicherheit

### 1. Sicherheit (`security.php`)

Ändern Sie das Standardpasswort sofort, um die WebUI zu schützen.

-   Navigieren Sie zu **Sicherheit**.
    
-   Geben Sie das **neue Passwort** für den Benutzer `admin` ein und speichern Sie es. Das System verschlüsselt das Passwort in der `.htpasswd`-Datei.
    

### 2. Hauptkonfiguration (`config.php`)

Definieren Sie hier das Verhalten des Scanners und die Zielsysteme.

#### A. Allgemeine Einstellungen (`[General]`)

Parameter

Beschreibung

Empfohlene Einstellung

**`scan_interval`**

Wartezeit (in Sekunden) zwischen zwei vollständigen Scans.

**15 - 30**

**`battery_retries`**

Wie oft versucht der Dienst, den Batteriestand auszulesen.

**2**

**`report_offline`**

Sollen Geräte als **`0` (offline)** gemeldet werden, wenn sie nicht gefunden werden?

**true**

**`report_offline_battery`**

Sollen fehlschlagende Batterie-Scans als `0%` gemeldet werden?

**false**

#### B. Smart Home Anbindung

Protokoll

Einstellungsblock

Zweck & Details

**UDP**

`[UDP]`

Ideal für die direkte Kommunikation mit Systemen wie **Loxone**. Geben Sie `host` (IP) und `port` (z.B. 7001) an.

**MQTT**

`[MQTT]`

Standard für **ioBroker, Home Assistant, FHEM**. Konfigurieren Sie `broker`, `port`, `user`/`password` und die Basis-Topics (`base_topic_scan`, `base_topic_battery`).

> 💾 **Wichtiger Hinweis:** Jede Speicherung der Konfiguration über `config.php` löst einen Neustart des **`ble_tool.service`** aus, um die neuen Parameter im Python-Dienst zu laden.

----------

## III. 📱 Geräteverwaltung (`devices.php`)

Dies ist der zentrale Ort zum Hinzufügen, Bearbeiten und Überwachen Ihrer Tracker.

### 1. Neue Geräte entdecken

1.  Klicken Sie auf **`Starte X-Sekunden-Scan...`**.
    
2.  Der Python-Dienst wird kurz gestoppt, scannt nach allen erreichbaren BLE-Geräten und speichert die Ergebnisse in `scan_results.json`.
    
3.  Wählen Sie die gewünschten MAC-Adressen aus der Liste, vergeben Sie einen **eindeutigen Alias** (z.B. `Schluessel_Anna`) und klicken Sie auf **`Markierte Geräte hinzufügen`**.
    
    -   Der Alias dient als eindeutiger Bezeichner in UDP/MQTT-Nachrichten.
        

### 2. Bekannte Geräte verwalten

Die Liste der bekannten Geräte wird in `known_devices.txt` geführt.

-   **Alias bearbeiten:** Sie können den Namen direkt in der Tabelle anpassen und mit **`💾`** speichern.
    
-   **Batterie-Scan (`🔋 Ein/Aus`):** Schalten Sie hier, ob dieses Gerät in den **automatischen, nächtlichen Batterie-Scan** (`action.php?cmd=read_enabled_batteries`, via Cronjob) aufgenommen werden soll.
    
-   **Manueller Sofort-Scan (`🔄`):** Löst einen **ad-hoc** Batterie-Scan nur für dieses Gerät aus (ideal zum Testen).
    
-   **Batterie-Info:** Zeigt den zuletzt erkannten Stand und den Zeitstempel des erfolgreichen Scans (gespeichert in `battery_status.json`).
    

----------

## IV. 🌐 Master/Client-Modus

Der Master/Client-Betrieb ermöglicht die zentrale Verwaltung einer Geräte-Datenbank über mehrere räumlich verteilte Scanner hinweg.

### 1. Master-Einrichtung

1.  **Konfiguration:** Setzen Sie `[MasterClient] -> mode = master`.
    
2.  **Clients-Seite (`master.php`):** Fügen Sie Clients hinzu. Sie benötigen die **URL** des Clients und generieren einen **API-Key** für die sichere Kommunikation.
    

### 2. Client-Einrichtung

1.  **Konfiguration:** Setzen Sie `[MasterClient] -> mode = client`.
    
2.  Tragen Sie die **`master_url`** und den vom Master erhaltenen **`api_key`** ein.
    
3.  **Fallback-Poll:** Setzen Sie `fallback_poll_interval` (z.B. `1800` für 30 Minuten). Dies richtet einen Cronjob ein (`sync_client.php`), der vom Client gestartet wird, um Geräte-Updates vom Master zu ziehen, falls der Push fehlschlägt.
    

### 3. Synchronisation (Push & Pull)

-   **Push (Master → Client):** Änderungen an Geräten auf dem Master (Alias, Batterie-Scan-Flag) werden automatisch oder manuell über die **Clients-Seite** an die Clients gepusht.
    
-   **Neustart beim Client:** Der Client empfängt die Daten über `api.php` und startet sofort seinen lokalen **`ble_tool.service`** neu, um die neue `known_devices.txt` zu verwenden.
    

----------

## V. 📂 Logs und Wartung

-   **Logs (`logs.php`):** Einsehen von Echtzeit-Logs für die Diagnose:
    
    -   `ble_tool.log`: Haupt-Log des 24/7-Scan-Dienstes.
        
    -   `ble_battery.log`: Log für den nächtlichen Batterie-Scan.
        
    -   `ble_webhook.log`: Protokollierung der Master/Client-Kommunikation.
        
-   **Netzwerk (`network.php`):** Ermöglicht die Änderung von Hostname und Netzwerkeinstellungen (DHCP/Statische IP) über die WebUI.
    

----------
