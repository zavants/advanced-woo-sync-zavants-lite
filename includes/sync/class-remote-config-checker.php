<?php
/**
 * Remote Config Checker
 *
 * Fetches and caches configuration from connected remote store.
 *
 * @package SWOS\Sync
 */

namespace SWOS\Sync;

use SWOS\API_Client;
use SWOS\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class Remote_Config_Checker {

    /**
     * Option key for cached remote config
     */
    const CACHE_KEY = 'swos_remote_config_cache';

    /**
     * @var array Plugin settings
     */
    private $settings;

    /**
     * Constructor
     */
    public function __construct() {
        $this->settings = get_option('swos_settings', array());
    }

    /**
     * Fetch config from remote store and cache it
     *
     * @return array|WP_Error Remote config or error
     */
    public function fetch_and_cache_remote_config() {
        $url = isset($this->settings['remote_store_url']) ? untrailingslashit($this->settings['remote_store_url']) : '';
        $key = isset($this->settings['remote_store_consumer_key']) ? $this->settings['remote_store_consumer_key'] : '';
        $secret = isset($this->settings['remote_store_consumer_secret']) ? $this->settings['remote_store_consumer_secret'] : '';

        if (empty($url) || empty($key) || empty($secret)) {
            return new \WP_Error('missing_credentials', __('Remote store credentials not configured', 'advanced-woo-sync-lite'));
        }

        $api_client = new API_Client($url, $key, $secret);
        
        // Call the custom config endpoint
        $response = $api_client->request('swos/v1/config', 'GET', array(), false);

        if (is_wp_error($response)) {
            Logger::log('error', sprintf('Failed to fetch remote config: %s', $response->get_error_message()), 0, array('source' => 'remote_config'));
            return $response;
        }

        // Validate response structure
        if (!isset($response['store_role'])) {
            return new \WP_Error('invalid_response', __('Invalid response from remote store. Plugin may be outdated.', 'advanced-woo-sync-lite'));
        }

        // Cache the config
        $cache_data = array(
            'config' => $response,
            'fetched_at' => current_time('mysql'),
            'remote_url' => $url,
        );
        
        update_option(self::CACHE_KEY, $cache_data);

        Logger::log('info', sprintf('Remote config fetched and cached from %s', $url), 0, array('source' => 'remote_config'));

        return $response;
    }

    /**
     * Get cached remote config
     *
     * @return array|null Cached config or null if not available
     */
    public function get_cached_config() {
        $cached = get_option(self::CACHE_KEY);
        
        if (!$cached || !isset($cached['config'])) {
            return null;
        }

        return $cached['config'];
    }

    /**
     * Get full cache data including metadata
     *
     * @return array|null
     */
    public function get_cache_data() {
        return get_option(self::CACHE_KEY);
    }

    /**
     * Clear cached config
     */
    public function clear_cache() {
        delete_option(self::CACHE_KEY);
        Logger::log('info', 'Remote config cache cleared', 0, array('source' => 'remote_config'));
    }

    /**
     * Check if remote store has stock sync enabled
     *
     * @return bool
     */
    public function is_remote_stock_sync_enabled() {
        $config = $this->get_cached_config();
        return $config && !empty($config['stock_sync_enabled']);
    }

    /**
     * Check if remote store has order sync enabled
     *
     * @return bool
     */
    public function is_remote_order_sync_enabled() {
        $config = $this->get_cached_config();
        return $config && !empty($config['order_sync_enabled']);
    }

    /**
     * Get remote store role
     *
     * @return string|null 'central' or 'remote' or null
     */
    public function get_remote_store_role() {
        $config = $this->get_cached_config();
        return $config ? ($config['store_role'] ?? null) : null;
    }

    /**
     * Check if credentials are configured
     *
     * @return bool
     */
    public function has_credentials() {
        return !empty($this->settings['remote_store_url']) 
            && !empty($this->settings['remote_store_consumer_key']) 
            && !empty($this->settings['remote_store_consumer_secret']);
    }
}
