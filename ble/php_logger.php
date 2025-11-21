<?php
/**
 * PHP Logging für BLE Tool
 * Schreibt nach /var/log/ble/php.log
 */
 
date_default_timezone_set('Europe/Berlin'); 

class BLELogger {
    private static $log_file = '/var/log/ble/php.log';
    private static $error_log_file = '/var/log/ble/php_errors.log';
    private static $max_size = 10485760; // 10 MB
    
    /**
     * Schreibt eine Log-Nachricht
     */
    public static function log($message, $level = 'INFO', $context = []) {
        $timestamp = date('Y-m-d H:i:s');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
        $script = basename($_SERVER['SCRIPT_NAME'] ?? 'unknown');
        
        // Context als JSON anhängen
        $context_str = !empty($context) ? ' | ' . json_encode($context) : '';
        
        $log_entry = sprintf(
            "[%s] | %-8s | %s | %s | %s%s\n",
            $timestamp,
            $level,
            $ip,
            $script,
            $message,
            $context_str
        );
        
        // Rotation prüfen
        if (file_exists(self::$log_file) && filesize(self::$log_file) > self::$max_size) {
            self::rotate_log(self::$log_file);
        }
        
        // In Haupt-Log schreiben
        @file_put_contents(self::$log_file, $log_entry, FILE_APPEND | LOCK_EX);
        
        // ERROR/CRITICAL zusätzlich in Error-Log
        if (in_array($level, ['ERROR', 'CRITICAL'])) {
            @file_put_contents(self::$error_log_file, $log_entry, FILE_APPEND | LOCK_EX);
        }
    }
    
    /**
     * Rotiert Log-Datei
     */
    private static function rotate_log($file) {

		if (!file_exists(self::$log_file)) {
			@touch(self::$log_file);
			@chmod(self::$log_file, 0664);
		}
		
		if (!file_exists(self::$error_log_file)) {
			@touch(self::$error_log_file);
			@chmod(self::$error_log_file, 0664);
		}		
        for ($i = 4; $i >= 0; $i--) {
            $old = $file . '.' . $i;
            $new = $file . '.' . ($i + 1);
            if (file_exists($old)) {
                @rename($old, $new);
            }
        }
        @rename($file, $file . '.0');
    }
    
    // Praktische Shortcuts
    public static function info($message, $context = []) {
        self::log($message, 'INFO', $context);
    }
    
    public static function warning($message, $context = []) {
        self::log($message, 'WARNING', $context);
    }
    
    public static function error($message, $context = []) {
        self::log($message, 'ERROR', $context);
    }
    
    public static function debug($message, $context = []) {
        self::log($message, 'DEBUG', $context);
    }
}

// Globale Shortcuts (optional)
function ble_log_info($msg, $ctx = []) { BLELogger::info($msg, $ctx); }
function ble_log_error($msg, $ctx = []) { BLELogger::error($msg, $ctx); }
function ble_log_warning($msg, $ctx = []) { BLELogger::warning($msg, $ctx); }
?>