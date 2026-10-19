<?php
/**
 * WooCommerce API Client
 *
 * @package SWOS
 */

namespace SWOS;

if (!defined('ABSPATH')) {
    exit;
}

class API_Client {

    private $store_url;
    private $consumer_key;
    private $consumer_secret;

    public function __construct($store_url, $consumer_key, $consumer_secret) {
        $this->store_url = trailingslashit($store_url);
        $this->consumer_key = $consumer_key;
        $this->consumer_secret = $consumer_secret;
    }

    /**
     * Make a request to the WooCommerce API
     *
     * @param string $endpoint API endpoint
     * @param string $method HTTP method
     * @param array $data Request data
     * @param bool $is_sync_request If true, adds X-SWOS-Sync-By header to prevent ping-pong loops
     * @return array|WP_Error Response data or error
     */
    public function request($endpoint, $method = 'GET', $data = array(), $is_sync_request = false) {
        // Plugin routes (swos/v1/...) live outside the wc/v3 namespace
        $url = strpos($endpoint, 'swos/v1/') === 0
            ? $this->store_url . 'wp-json/' . $endpoint
            : $this->store_url . 'wp-json/wc/v3/' . $endpoint;

        $headers = array(
            'Authorization' => $this->get_auth_header(),
            'Content-Type' => 'application/json',
        );

        // Add anti-ping-pong header for sync requests
        if ($is_sync_request) {
            $headers['X-SWOS-Sync-By'] = get_site_url();
        }

        $args = array(
            'method' => $method,
            'timeout' => 30,
            'headers' => $headers,
        );

        if ($method !== 'GET' && !empty($data)) {
            $args['body'] = wp_json_encode($data);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            Logger::log('error', 'API request error: ' . $response->get_error_message(), null, array(
                'url' => $url,
                'method' => $method
            ));
            return $response; // Return WP_Error
        }

        $body = wp_remote_retrieve_body($response);
        $code = wp_remote_retrieve_response_code($response);

        if ($code < 200 || $code >= 300) {
            $error_message = 'API response error (Code ' . $code . ')';
            Logger::log('error', $error_message . ': ' . $body, null, array(
                'url' => $url,
                'method' => $method,
                'code' => $code
            ));
            return new \WP_Error('api_error', $error_message, array('code' => $code, 'body' => $body));
        }

        return json_decode($body, true);
    }

    /**
     * Basic Authorization header with the WooCommerce consumer key/secret
     *
     * @return string
     */
    private function get_auth_header() {
        return 'Basic ' . base64_encode($this->consumer_key . ':' . $this->consumer_secret);
    }

    /**
     * Test connection to the store
     */
    public function test_connection() {
        $result = $this->request('system_status');
        return !is_wp_error($result);
    }

    /**
     * Get product by SKU
     */
    public function get_product_by_sku($sku) {
        $result = $this->request('products?sku=' . rawurlencode($sku));

        if (is_wp_error($result)) {
            return false;
        }

        if ($result && !empty($result)) {
            return $result[0];
        }

        return false;
    }

    /**
     * Create an order
     */
    public function create_order($order_data) {
        return $this->request('orders', 'POST', $order_data);
    }

    /**
     * Get order by ID
     */
    public function get_order($order_id) {
        return $this->request('orders/' . $order_id);
    }

    /**
     * Find an order this store already received from the given source order (idempotency check)
     *
     * @param int $source_order_id Order ID in the sending store
     * @param string $source_site Sending store home URL
     * @return int|false Destination order ID, or false if none (or the remote plugin is too old to tell)
     */
    public function find_synced_order($source_order_id, $source_site) {
        $result = $this->request('swos/v1/synced-order?' . http_build_query(array(
            'source_order_id' => (int) $source_order_id,
            'source_site' => $source_site,
        )));

        if (is_wp_error($result) || empty($result['id'])) {
            return false;
        }

        return (int) $result['id'];
    }

    /**
     * Update order (generic)
     */
    public function update_order($order_id, $data) {
        return $this->request('orders/' . $order_id, 'PUT', $data);
    }

    /**
     * Update order status
     */
    public function update_order_status($order_id, $status) {
        return $this->request('orders/' . $order_id, 'PUT', array('status' => $status));
    }

    /**
     * Get customer by email
     */
    public function get_customer_by_email($email) {
        $result = $this->request('customers?email=' . rawurlencode($email));

        if (is_wp_error($result)) {
            return false;
        }

        if ($result && !empty($result)) {
            return $result[0];
        }

        return false;
    }

    /**
     * Get customer by ID
     */
    public function get_customer($customer_id) {
        return $this->request('customers/' . intval($customer_id));
    }

    /**
     * Create customer
     */
    public function create_customer($customer_data) {
        return $this->request('customers', 'POST', $customer_data);
    }

    /**
     * Update product
     */
    public function update_product($product_id, $product_data) {
        return $this->request('products/' . intval($product_id), 'PUT', $product_data);
    }

    /**
     * Get multiple products by SKUs using bulk custom endpoint
     * Optimized for comparison operations - single request for multiple SKUs
     *
     * @param array $skus Array of SKUs to search for
     * @return array Products data indexed by SKU, or empty array on failure
     */
    public function get_products_by_skus($skus) {
        if (empty($skus) || !is_array($skus)) {
            return array();
        }

        /**
         * Credentials travel in the Authorization header (same as the wc/v3 calls), never in the URL,
         * so they don't end up in access logs or in our own debug logs.
         * The remote store authenticates swos/v1 routes through WooCommerce (see swos_is_wc_rest_request()).
         */
        $url = $this->store_url . 'wp-json/swos/v1/products-by-skus';

        $args = array(
            'method' => 'POST',
            'timeout' => 60, // Longer timeout for bulk operations
            'headers' => array(
                'Authorization' => $this->get_auth_header(),
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode(array('skus' => array_values($skus))),
        );

        Logger::log('debug', sprintf('Attempting bulk call to: %s with %d SKUs', $url, count($skus)), 0, array('source' => 'api_client'));

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            Logger::log('error', sprintf('WP error in bulk call: %s', $response->get_error_message()), 0, array(
                'source' => 'api_client',
                'url' => $url
            ));
            return array();
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        Logger::log('debug', sprintf('Bulk response - HTTP %d, Body length: %d bytes', $code, strlen($body)), 0, array('source' => 'api_client'));

        if ($code === 200) {
            $data = json_decode($body, true);

            if (is_array($data)) {
                Logger::log('debug', sprintf('Bulk SKU lookup successful - %d SKUs requested, %d found',
                    count($skus), count($data)), 0, array('source' => 'api_client'));
                return $data;
            } else {
                Logger::log('error', sprintf('Bulk response is not valid array. Type: %s, First 300 chars: %s',
                    gettype($data), substr($body, 0, 300)), 0, array('source' => 'api_client'));
            }
        } else {
            Logger::log('error', sprintf('Bulk endpoint HTTP %d. First 300 chars: %s',
                $code, substr($body, 0, 300)), 0, array('source' => 'api_client'));
        }

        return array();
    }

    /**
     * Get product or variation by SKU using custom endpoint
     * Falls back to standard WooCommerce endpoint if custom endpoint not available
     *
     * @param string $sku The product SKU
     * @return array|false Product information including type (simple/variation), id, parent_id, etc., or false if not found
     */
    public function get_product_or_variation_by_sku($sku) {
        // --- STRATEGY 1: BULK ENDPOINT (POST) ---
        // Safest option: Bypasses Apache URL path restrictions (slashes) and Cloudflare auth stripping.
        $bulk_result = $this->get_products_by_skus(array($sku));
        if (!empty($bulk_result) && is_array($bulk_result)) {
            foreach ($bulk_result as $found_sku => $data) {
                // Only accept the exact SKU (case/whitespace-insensitive, like MySQL's default collation)
                if (strcasecmp(trim((string)$found_sku), trim((string)$sku)) === 0) {
                    if (isset($data['id'])) {
                        Logger::log('debug', sprintf('Product found using BULK POST endpoint - SKU: %s, ID: %d', $sku, $data['id']), 0, array('source' => 'api_client'));
                        return $data;
                    }
                }
            }
        }

        // --- STRATEGY 2: SINGLE CUSTOM ENDPOINT (GET) ---
        // Try custom endpoint next (supports both products and variations)
        // IMPORTANT: Uses rawurlencode instead of urlencode to handle spaces correctly (%20 instead of +)
        $url = $this->store_url . 'wp-json/swos/v1/product-by-sku/' . rawurlencode($sku);

        $args = array(
            'method' => 'GET',
            'timeout' => 30,
            'headers' => array(
                'Authorization' => $this->get_auth_header(),
            ),
        );

        $response = wp_remote_request($url, $args);

        // If custom endpoint works, use it
        if (!is_wp_error($response)) {
            $code = wp_remote_retrieve_response_code($response);
            if ($code === 200) {
                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true);

                if ($data && isset($data['id'])) {
                    Logger::log('debug', sprintf('Product found using GET custom endpoint - SKU: %s, Type: %s, ID: %d',
                        $sku, $data['type'], $data['id']), 0, array('source' => 'api_client'));
                    return $data;
                }
            }
        }

        // --- STRATEGY 3: FALLBACK TO WOOCOMMERCE STANDARD API ---
        Logger::log('debug', sprintf('Custom endpoints not available/failed for SKU "%s", using fallback to standard WooCommerce API', $sku), 0, array('source' => 'api_client'));

        $result = $this->get_product_by_sku($sku);

        // If not found, try searching in all variations (WooCommerce standard API doesn't search variations by SKU)

        if ($result) {
            // Transform standard response to match custom endpoint format
            return array(
                'type' => isset($result['type']) && $result['type'] === 'variation' ? 'variation' : (isset($result['parent_id']) && $result['parent_id'] > 0 ? 'variation' : 'simple'),
                'id' => $result['id'],
                'parent_id' => isset($result['parent_id']) ? $result['parent_id'] : null,
                'sku' => $result['sku'],
                'name' => isset($result['name']) ? $result['name'] : '',
                'stock_quantity' => isset($result['stock_quantity']) ? $result['stock_quantity'] : null,
                'manage_stock' => isset($result['manage_stock']) ? $result['manage_stock'] : false,
                'stock_status' => isset($result['stock_status']) ? $result['stock_status'] : 'instock',
                'attributes' => isset($result['attributes']) ? $result['attributes'] : array(),
            );
        }

        return false;
    }

    /**
     * Update product stock (handles both simple products and variations)
     *
     * @param array $product_info Product information from get_product_or_variation_by_sku()
     * @param array $stock_data Stock data to update (stock_quantity, manage_stock, etc.)
     * @return array|\WP_Error Update result or WP_Error on failure
     */
    public function update_product_stock($product_info, $stock_data) {
        if (!isset($product_info['type']) || !isset($product_info['id'])) {
            return new \WP_Error('invalid_product_info', __('Invalid product information provided', 'advanced-woo-sync-lite'));
        }

        $type = $product_info['type'];
        $product_id = $product_info['id'];

        // For variations, use the variation endpoint
        if ($type === 'variation') {
            if (!isset($product_info['parent_id'])) {
                return new \WP_Error('missing_parent_id', __('Parent ID required for variation updates', 'advanced-woo-sync-lite'));
            }

            $parent_id = $product_info['parent_id'];
            $endpoint = sprintf('products/%d/variations/%d', $parent_id, $product_id);

            Logger::log('debug', sprintf('Updating variation stock - Parent: #%d, Variation: #%d', $parent_id, $product_id), 0, array('source' => 'api_client'));

            // Pass true for $is_sync_request to add anti-ping-pong header
            return $this->request($endpoint, 'PUT', $stock_data, true);
        }

        // For simple products, use the standard product endpoint
        Logger::log('debug', sprintf('Updating simple product stock - ID: #%d', $product_id), 0, array('source' => 'api_client'));

        // Pass true for $is_sync_request to add anti-ping-pong header
        return $this->request('products/' . intval($product_id), 'PUT', $stock_data, true);
    }
}
