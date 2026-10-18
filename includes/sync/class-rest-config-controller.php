<?php
/**
 * REST API Controller for SWOS Config
 *
 * Exposes sync configuration for remote stores to query.
 *
 * @package SWOS\Sync
 */

namespace SWOS\Sync;

if (!defined('ABSPATH')) {
    exit;
}

class REST_Config_Controller {

    /**
     * Namespace
     */
    const NAMESPACE = 'swos/v1';

    /**
     * Register REST routes
     */
    public function register_routes() {
        register_rest_route(self::NAMESPACE, '/config', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_config'),
            'permission_callback' => array($this, 'check_permission'),
        ));
    }

    /**
     * Requires a valid WooCommerce API key (authenticated by WooCommerce, see swos_is_wc_rest_request())
     * or a logged-in shop manager.
     *
     * @param \WP_REST_Request $request
     * @return bool
     */
    public function check_permission($request) {
        return swos_rest_permission_check();
    }

    /**
     * Get sync configuration
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function get_config($request) {
        $settings = get_option('swos_settings', array());

        // Only expose necessary config - never expose secrets
        $config = array(
            'store_role' => isset($settings['store_role']) ? $settings['store_role'] : 'central',
            'stock_sync_enabled' => !empty($settings['stock_sync_enabled']),
            'order_sync_enabled' => $this->is_order_sync_enabled($settings),
            'stock_sync_type' => isset($settings['stock_sync_type']) ? $settings['stock_sync_type'] : '',
            'plugin_version' => defined('SWOS_VERSION') ? SWOS_VERSION : '0.0.0',
        );

        return new \WP_REST_Response($config, 200);
    }

    /**
     * Check if order sync is enabled
     *
     * @param array $settings
     * @return bool
     */
    private function is_order_sync_enabled($settings) {
        if (empty($settings['enabled'])) {
            return false;
        }

        $trigger_keys = array('trigger_status', 'trigger_status_2', 'trigger_status_3', 'trigger_status_4');
        foreach ($trigger_keys as $key) {
            if (!empty($settings[$key])) {
                return true;
            }
        }

        return false;
    }
}
