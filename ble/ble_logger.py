#!/usr/bin/env python3
"""
Zentrales Logging-Modul für BLE Tool
Logs gehen nach /var/log/ble/
"""

import time
import logging
import sys
import os
import grp
from pathlib import Path
from logging.handlers import RotatingFileHandler

_LOG_DIR = Path("/var/log/ble")

def get_log_dir():
    return _LOG_DIR

def ensure_log_dir():
    global _LOG_DIR
    
    if _LOG_DIR.exists():
        return _LOG_DIR
    
    try:
        _LOG_DIR.mkdir(parents=True, exist_ok=True)
        
        if os.geteuid() == 0:
            try:
                www_data_gid = grp.getgrnam('www-data').gr_gid
                os.chown(_LOG_DIR, 0, www_data_gid)
                os.chmod(_LOG_DIR, 0o775)
                print(f"Log-Verzeichnis erstellt: {_LOG_DIR}")
            except (KeyError, PermissionError) as e:
                print(f"Konnte Rechte nicht setzen: {e}")
        
        return _LOG_DIR
        
    except PermissionError:
        print(f"FEHLER: Keine Berechtigung für {_LOG_DIR}")
        print(f"Fallback auf /tmp/ble_logs")
        _LOG_DIR = Path("/tmp/ble_logs")
        _LOG_DIR.mkdir(exist_ok=True)
        return _LOG_DIR


# Konverter für lokale Zeit
def local_time(*args):
    return time.localtime()

# Bei der Formatter-Erstellung:
formatter = logging.Formatter(
    '%(asctime)s | %(levelname)-8s | %(message)s',
    datefmt='%Y-%m-%d %H:%M:%S'
)
formatter.converter = local_time



class BLELogger:
    
    _instance = None
    _initialized = False
    
    def __new__(cls):
        if cls._instance is None:
            cls._instance = super().__new__(cls)
        return cls._instance
    
    def __init__(self):
        if not self._initialized:
            self._setup_logging()
            self.__class__._initialized = True
    
    def _setup_logging(self):
        log_dir = ensure_log_dir()
        
        config_file = Path(__file__).parent / "config.ini"
        log_level = logging.ERROR
        console_level = logging.INFO
        bleak_level = logging.ERROR
        
        try:
            import configparser
            config = configparser.ConfigParser()
            if config.read(config_file):
                log_level_str = config.get('Logging', 'log_level', fallback='ERROR').upper()
                console_level_str = config.get('Logging', 'console_level', fallback='INFO').upper()
                bleak_level_str = config.get('Logging', 'bleak_level', fallback='ERROR').upper()
                
                log_level = getattr(logging, log_level_str, logging.ERROR)
                console_level = getattr(logging, console_level_str, logging.INFO)
                bleak_level = getattr(logging, bleak_level_str, logging.ERROR)
        except Exception as e:
            print(f"Warnung: Konnte Log-Level nicht aus config.ini lesen: {e}")
        
        detailed_format = logging.Formatter(
            '%(asctime)s | %(levelname)-8s | %(name)s | %(funcName)s:%(lineno)d | %(message)s',
            datefmt='%Y-%m-%d %H:%M:%S'
        )
        
        simple_format = logging.Formatter(
            '%(asctime)s | %(levelname)-8s | %(message)s',
            datefmt='%H:%M:%S'
        )
        
        root_logger = logging.getLogger()
        root_logger.setLevel(logging.DEBUG)
        root_logger.handlers.clear()
        
        bleak_effective_level = max(bleak_level, log_level)
        
        for bleak_logger_name in ['bleak', 'bleak.backends', 'bleak.backends.bluezdbus', 
                                   'bleak.backends.bluezdbus.manager', 'bleak.backends.scanner']:
            bleak_logger = logging.getLogger(bleak_logger_name)
            bleak_logger.setLevel(bleak_effective_level)
            bleak_logger.propagate = False
        
        asyncio_level = max(logging.WARNING, log_level)
        asyncio_logger = logging.getLogger('asyncio')
        asyncio_logger.setLevel(asyncio_level)
        asyncio_logger.propagate = False
        
        console = logging.StreamHandler(sys.stdout)
        console.setLevel(console_level)
        console.setFormatter(simple_format)
        root_logger.addHandler(console)
        
        main_log = RotatingFileHandler(
            log_dir / "ble_tool.log",
            maxBytes=10*1024*1024,
            backupCount=5,
            encoding='utf-8'
        )
        main_log.setLevel(log_level)
        main_log.setFormatter(detailed_format)
        root_logger.addHandler(main_log)
        
        error_log = RotatingFileHandler(
            log_dir / "ble_errors.log",
            maxBytes=5*1024*1024,
            backupCount=3,
            encoding='utf-8'
        )
        error_log.setLevel(logging.ERROR)
        error_log.setFormatter(detailed_format)
        root_logger.addHandler(error_log)
        
        self.bt_logger = logging.getLogger('bluetooth')
        self.bt_logger.setLevel(logging.DEBUG)
        self.bt_logger.propagate = True
        
        bt_log = RotatingFileHandler(
            log_dir / "bluetooth.log",
            maxBytes=10*1024*1024,
            backupCount=5,
            encoding='utf-8'
        )
        bt_log.setLevel(log_level)
        bt_log.setFormatter(detailed_format)
        self.bt_logger.addHandler(bt_log)
        
        self.scan_logger = logging.getLogger('scan')
        self.scan_logger.setLevel(logging.DEBUG)
        self.scan_logger.propagate = True
        
        scan_log = RotatingFileHandler(
            log_dir / "scan.log",
            maxBytes=5*1024*1024,
            backupCount=3,
            encoding='utf-8'
        )
        scan_log.setLevel(log_level)
        scan_log.setFormatter(detailed_format)
        self.scan_logger.addHandler(scan_log)
        
        root_logger.info("=" * 70)
        root_logger.info("BLE Tool gestartet - Logs in: %s", log_dir)
        root_logger.info("Log-Level: File=%s, Console=%s, Bleak=%s", 
                         logging.getLevelName(log_level),
                         logging.getLevelName(console_level),
                         logging.getLevelName(bleak_effective_level))
        root_logger.info("=" * 70)
    
    @staticmethod
    def get_logger(name=None):
        BLELogger()
        return logging.getLogger(name or __name__)
    
    @staticmethod
    def get_bluetooth_logger():
        instance = BLELogger()
        return instance.bt_logger
    
    @staticmethod
    def get_scan_logger():
        instance = BLELogger()
        return instance.scan_logger


def get_logger(name=None):
    return BLELogger.get_logger(name)

def get_bluetooth_logger():
    return BLELogger.get_bluetooth_logger()

def get_scan_logger():
    return BLELogger.get_scan_logger()