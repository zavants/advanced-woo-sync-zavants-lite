<?php
/**
 * Logger
 *
 * @package SWOS
 */

namespace SWOS;

if (!defined('ABSPATH')) {
    exit;
}

class Logger {

    private static $instance = null;
    private static $wc_logger = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
            if (function_exists('wc_get_logger')) {
                self::$wc_logger = \wc_get_logger();
            }
        }
        return self::$instance;
    }

    /**
     * Log a message to file
     *
     * @param string $level info, success, warning, error, debug
     * @param string $message The log message
     * @param int $order_id Optional order ID
     * @param array $context Optional additional context
     */
    public static function log($level, $message, $order_id = null, $context = array()) {
        // Get settings for log level
        $settings = get_option('swos_settings', array());
        $log_level = isset($settings['log_level']) ? $settings['log_level'] : 'simple';

        // Maintain backward compatibility with legacy debug_mode setting
        if (!isset($settings['log_level']) && isset($settings['debug_mode']) && $settings['debug_mode']) {
            $log_level = 'detallado';
        }

        // Determine if we should log based on level
        $should_log = false;

        switch ($log_level) {
            case 'simple':
                // Only info (syncs), success, and error
                $should_log = in_array($level, array('info', 'success', 'error'));
                break;

            case 'normal':
                // Info, success, warning, and error (no debug)
                $should_log = in_array($level, array('info', 'success', 'warning', 'error'));
                break;

            case 'detallado':
                // All levels including debug
                $should_log = true;
                break;
        }

        if ($should_log) {
            if (self::$wc_logger === null && function_exists('wc_get_logger')) {
                self::$wc_logger = \wc_get_logger();
            }

            if (self::$wc_logger !== null) {
                $wc_level = self::get_wc_log_level($level);

                // Determine log source based on context
                $log_source = 'advanced-woo-sync-lite';
                if (isset($context['source']) && $context['source'] === 'stock_sync') {
                    $log_source = 'sync-woo-orders-stores-stock';
                }

                $context_data = array('source' => $log_source);

                if ($order_id) {
                    $context_data['order_id'] = $order_id;
                }

                if (!empty($context)) {
                    $context_data['extra'] = $context;
                }

                $log_message = $message;
                if ($order_id) {
                    $log_message = "[Order #{$order_id}] " . $log_message;
                }
                if (!empty($context)) {
                    $log_message .= ' | Context: ' . json_encode($context);
                }

                try {
                    self::$wc_logger->log($wc_level, $log_message, $context_data);
                } catch (\Exception $e) {
                    // Silently fail if WooCommerce logger is not available
                }
            }
        }
    }

    /**
     * Convert internal log level to WC log level
     */
    private static function get_wc_log_level($level) {
        $map = array(
            'error' => 'error',
            'warning' => 'warning',
            'success' => 'info',
            'info' => 'info',
            'debug' => 'debug'
        );

        return isset($map[$level]) ? $map[$level] : 'info';
    }

    /**
     * Get log files for order logs
     *
     * @return array Array of log file info
     */
    public static function get_order_log_files() {
        return self::get_log_files_by_source('advanced-woo-sync-lite');
    }

    /**
     * Get log files for stock logs
     *
     * @return array Array of log file info
     */
    public static function get_stock_log_files() {
        return self::get_log_files_by_source('sync-woo-orders-stores-stock');
    }

    /**
     * Get log files by source
     *
     * @param string $source Log source name
     * @return array Array of log file info with 'filename' and 'path'
     */
    private static function get_log_files_by_source($source) {
        $log_files = array();

        // Get WooCommerce logs directory
        $logs_dir = '';

        if (defined('WC_LOG_DIR')) {
            $logs_dir = WC_LOG_DIR;
        } else {
            // Fallback to default WooCommerce logs location
            $upload_dir = wp_upload_dir(null, false);
            $logs_dir = $upload_dir['basedir'] . '/wc-logs/';
        }

        if (!is_dir($logs_dir)) {
            return $log_files;
        }

        $files = @scandir($logs_dir);

        if ($files === false) {
            return $log_files;
        }

        foreach ($files as $file) {
            // Match files like: sync-woo-orders-stores-2025-11-17-abc123.log
            // or sync-woo-orders-stores-stock-2025-11-17-abc123.log
            if (strpos($file, $source) === 0 && substr($file, -4) === '.log') {
                // Ensure exact source match: if looking for 'advanced-woo-sync-lite' (order logs),
                // don't match 'sync-woo-orders-stores-stock' (stock logs)
                // Check that after the source name comes a date pattern (YYYY-MM-DD) not "-stock"
                $after_source = substr($file, strlen($source));

                // For order logs source, exclude stock files
                if ($source === 'advanced-woo-sync-lite' && strpos($after_source, '-stock') === 0) {
                    continue; // Skip stock files when looking for order files
                }

                $log_files[] = array(
                    'filename' => $file,
                    'path' => trailingslashit($logs_dir) . $file
                );
            }
        }

        // Sort by filename (which includes date) descending
        usort($log_files, function($a, $b) {
            return strcmp($b['filename'], $a['filename']);
        });

        return $log_files;
    }

    /**
     * Read log entries from files
     *
     * @param string $log_type 'order' or 'stock'
     * @param int $limit Number of entries to return
     * @param int $offset Offset for pagination
     * @param string $level_filter Filter by level (optional)
     * @param string $search_query Search query (optional)
     * @return array Array of log entries
     */
    public static function read_log_entries($log_type = 'order', $limit = 50, $offset = 0, $level_filter = '', $search_query = '') {
        $log_files = ($log_type === 'stock') ? self::get_stock_log_files() : self::get_order_log_files();

        $all_entries = array();

        // If searching, we need to scan MORE files or all of them, because we don't know where the result is.
        // For performance, we limit to the last 20 files if no search is active, but if searching, we attempt all (with implicit memory/time limits).
        // Note: Reading all files can be resource intensive.
        
        foreach ($log_files as $log_file) {
            if (file_exists($log_file['path'])) {
                // Optimization: If searching and file is huge, ideally read streams. For now, read file.
                $file_content = file_get_contents($log_file['path']);
                $lines = explode("\n", $file_content);

                // Reverse lines to get newest first within file
                $lines = array_reverse($lines);

                foreach ($lines as $line) {
                    if (empty(trim($line))) {
                        continue;
                    }

                    // Pre-filter by search query to avoid parsing if not needed (faster)
                    if (!empty($search_query)) {
                        if (stripos($line, $search_query) === false) {
                            continue;
                        }
                    }

                    $entry = self::parse_log_line($line);
                    if ($entry) {
                        // Apply level filter if specified
                        if (!empty($level_filter) && $entry['level'] !== $level_filter) {
                            continue;
                        }
                        
                        // Apply search query again on parsed fields if needed (though raw line check covers most)
                         if (!empty($search_query)) {
                             // Already checked raw line, but ensure we check context if json encoded
                             // Raw line check covers context usually.
                         }

                        $all_entries[] = $entry;
                    }
                }
            }
        }

        // Sort by timestamp descending (newest first)
        // Note: Files are iterated locally, but we still sort to ensure correct order across files if timestamps overlap or file modified times are weird
        usort($all_entries, function($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });

        // Apply pagination
        $total_entries = count($all_entries);
        $paginated = array_slice($all_entries, $offset, $limit);

        return array(
            'entries' => $paginated,
            'total' => $total_entries
        );
    }

    /**
     * Parse a log line into structured data
     * Supports multiple WooCommerce log formats for maximum compatibility
     *
     * @param string $line Log line
     * @return array|false Parsed log entry or false
     */
    private static function parse_log_line($line) {
        $timestamp = null;
        $wc_level = null;
        $message = null;
        $matched_format = null;

        // Try multiple timestamp formats for compatibility with different WP/WC versions

        // Format 1: ISO 8601 with timezone and optional milliseconds
        // Examples: 2025-11-18T00:22:56+00:00 or 2025-11-18T00:22:56.123+00:00
        if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{3})?[+-]\d{2}:\d{2})\s+(\w+)\s+(.+)$/i', $line, $matches)) {
            // Convert UTC timestamp to WordPress local timezone
            $utc_timestamp = strtotime($matches[1]);
            $timestamp = wp_date('Y-m-d H:i:s', $utc_timestamp);
            $wc_level = strtoupper($matches[2]);
            $message = $matches[3];
            $matched_format = 'ISO 8601 with timezone';
        }
        // Format 2: ISO 8601 without timezone
        // Example: 2025-11-18T00:22:56
        elseif (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})\s+(\w+)\s+(.+)$/i', $line, $matches)) {
            // Convert UTC timestamp to WordPress local timezone
            $utc_timestamp = strtotime($matches[1]);
            $timestamp = wp_date('Y-m-d H:i:s', $utc_timestamp);
            $wc_level = strtoupper($matches[2]);
            $message = $matches[3];
            $matched_format = 'ISO 8601 without timezone';
        }
        // Format 3: Standard datetime format (legacy)
        // Example: 2025-11-17 22:30:45
        elseif (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s+(\w+)\s+(.+)$/i', $line, $matches)) {
            // Convert UTC timestamp to WordPress local timezone
            $utc_timestamp = strtotime($matches[1]);
            $timestamp = wp_date('Y-m-d H:i:s', $utc_timestamp);
            $wc_level = strtoupper($matches[2]);
            $message = $matches[3];
            $matched_format = 'Standard Datetime (legacy)';
        }
        // Format 4: Timestamp at start, any format
        // Fallback for unusual formats
        elseif (preg_match('/^([\d\-T:+\.]+)\s+(\w+)\s+(.+)$/i', $line, $matches)) {
            // Try to parse the timestamp
            $ts = strtotime($matches[1]);
            if ($ts !== false) {
                // Convert UTC timestamp to WordPress local timezone
                $timestamp = wp_date('Y-m-d H:i:s', $ts);
                $wc_level = strtoupper($matches[2]);
                $message = $matches[3];
                $matched_format = 'Generic Fallback';
            }
        }

        // If we couldn't parse the line, return false
        if ($timestamp === null || $wc_level === null || $message === null) {
            return false;
        }

        // Validate that wc_level is a known log level
        $valid_levels = array('DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY');
        if (!in_array($wc_level, $valid_levels)) {
            return false;
        }

        // Convert WC level to our level
        $level_map = array(
            'DEBUG' => 'debug',
            'INFO' => 'info',
            'NOTICE' => 'info',
            'WARNING' => 'warning',
            'ERROR' => 'error',
            'CRITICAL' => 'error',
            'ALERT' => 'error',
            'EMERGENCY' => 'error'
        );

        $level = isset($level_map[$wc_level]) ? $level_map[$wc_level] : 'info';

        // Try to detect 'success' from message content
        if (stripos($message, 'success') !== false ||
            stripos($message, 'synced successfully') !== false) {
            $level = 'success';
        }

        // Extract order ID if present
        $order_id = null;
        if (preg_match('/\[Order #(\d+)\]/', $message, $order_matches)) {
            $order_id = intval($order_matches[1]);
        } elseif (preg_match('/Pedido #(\d+)/', $message, $order_matches)) {
            $order_id = intval($order_matches[1]);
        }

        return array(
            'timestamp' => $timestamp,
            'level' => $level,
            'message' => $message,
            'order_id' => $order_id
        );
    }

    /**
     * Clear all log files
     *
     * @param string $log_type 'order', 'stock', or 'all'
     * @return array Array with 'success' bool and 'deleted' count
     */
    public static function clear_logs($log_type = 'all') {
        $deleted = 0;
        $errors = array();

        if ($log_type === 'all' || $log_type === 'order') {
            $order_files = self::get_order_log_files();
            foreach ($order_files as $file) {
                if (file_exists($file['path'])) {
                    if (@unlink($file['path'])) {
                        $deleted++;
                    } else {
                        $errors[] = 'Could not delete: ' . $file['filename'];
                    }
                }
            }
        }

        if ($log_type === 'all' || $log_type === 'stock') {
            $stock_files = self::get_stock_log_files();
            foreach ($stock_files as $file) {
                if (file_exists($file['path'])) {
                    if (@unlink($file['path'])) {
                        $deleted++;
                    } else {
                        $errors[] = 'Could not delete: ' . $file['filename'];
                    }
                }
            }
        }

        return array(
            'success' => empty($errors),
            'deleted' => $deleted,
            'errors' => $errors
        );
    }
}
