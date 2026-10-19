<?php
/**
 * Admin Interface (Lite)
 *
 * @package SWOS
 */

namespace SWOS;

if (!defined('ABSPATH')) {
    exit;
}

class Admin {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_swos_clear_logs', array($this, 'ajax_clear_logs'));
        add_action('wp_ajax_swos_test_log', array($this, 'ajax_test_log'));
        add_action('wp_ajax_swos_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_swos_revoke_credentials', array($this, 'ajax_revoke_credentials'));
        add_action('wp_ajax_swos_refresh_remote_config', array($this, 'ajax_refresh_remote_config'));
        add_action('admin_post_swos_lite_reset_slots', array($this, 'handle_reset_slots'));
        add_action('admin_notices', array($this, 'limit_reached_notice'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_filter('plugin_action_links_' . SWOS_PLUGIN_BASENAME, array($this, 'plugin_action_links'));
    }

    public function add_menu() {
        add_menu_page(
            __('Woo Sync Lite', 'advanced-woo-sync-lite'),
            __('Woo Sync Lite', 'advanced-woo-sync-lite'),
            'manage_woocommerce',
            'woo-sync-adv',
            array($this, 'settings_page'),
            'dashicons-update',
            56 // Position after WooCommerce (55)
        );

        add_submenu_page(
            'woo-sync-adv',
            __('Settings', 'advanced-woo-sync-lite'),
            __('Settings', 'advanced-woo-sync-lite'),
            'manage_woocommerce',
            'woo-sync-adv',
            array($this, 'settings_page')
        );

        add_submenu_page(
            'woo-sync-adv',
            __('Stock Logs', 'advanced-woo-sync-lite'),
            __('Stock Logs', 'advanced-woo-sync-lite'),
            'manage_woocommerce',
            'woo-sync-adv-stock-logs',
            array($this, 'stock_logs_page')
        );

        // Premium features, shown as locked previews
        foreach (Premium::get_locked_pages() as $slug => $page) {
            add_submenu_page(
                'woo-sync-adv',
                $page['title'],
                $page['title'] . ' <span class="swos-pro-badge">PRO</span>',
                'manage_woocommerce',
                $slug,
                array($this, 'locked_page')
            );
        }

        add_submenu_page(
            'woo-sync-adv',
            __('Buy Premium', 'advanced-woo-sync-lite'),
            '<span class="swos-menu-buy">' . __('Buy Premium', 'advanced-woo-sync-lite') . '</span>',
            'manage_woocommerce',
            'woo-sync-adv-premium',
            array($this, 'locked_page')
        );
    }

    public function register_settings() {
        register_setting('swos_settings_group', 'swos_settings', array($this, 'sanitize_settings'));
    }

    /**
     * Only Lite fields are read from the form. Every other stored setting (e.g. kept from a
     * previous Premium install) is left untouched, so upgrading back to Premium loses nothing.
     */
    public function sanitize_settings($input) {
        $previous = get_option('swos_settings', array());
        $sanitized = is_array($previous) ? $previous : array();
        $input = is_array($input) ? $input : array();

        if (isset($input['store_role'])) {
            $sanitized['store_role'] = sanitize_text_field($input['store_role']);
        }
        $sanitized['remote_store_url'] = isset($input['remote_store_url']) ? esc_url_raw($input['remote_store_url']) : '';
        $sanitized['remote_store_consumer_key'] = isset($input['remote_store_consumer_key']) ? sanitize_text_field($input['remote_store_consumer_key']) : '';
        $secret = isset($input['remote_store_consumer_secret']) ? sanitize_text_field($input['remote_store_consumer_secret']) : '';

        // The secret is never printed back into the form: an empty field keeps the stored secret,
        // as long as the consumer key did not change (revoking credentials clears the key too).
        if ($secret === ''
            && !empty($sanitized['remote_store_consumer_key'])
            && isset($previous['remote_store_consumer_key'], $previous['remote_store_consumer_secret'])
            && $previous['remote_store_consumer_key'] === $sanitized['remote_store_consumer_key']) {
            $secret = $previous['remote_store_consumer_secret'];
        }
        $sanitized['remote_store_consumer_secret'] = $secret;

        $sanitized['log_level'] = isset($input['log_level']) ? sanitize_text_field($input['log_level']) : 'simple';
        $sanitized['debug_mode'] = $sanitized['log_level'] === 'detallado';

        // Order sync is a Premium feature
        $sanitized['enabled'] = false;

        // Stock sync: by category only
        $sanitized['stock_sync_enabled'] = !empty($input['stock_sync_enabled']);
        if ($sanitized['stock_sync_enabled']) {
            $rules_engine = new Sync\Sync_Rules_Engine($sanitized);
            if (!$rules_engine->can_enable_stock_sync()) {
                $sanitized['stock_sync_enabled'] = false;
                add_settings_error('swos_settings', 'swos_stock_sync_conflict', __('Stock Sync was not enabled: the connected store already has Stock Sync enabled towards this store.', 'advanced-woo-sync-lite'), 'error');
            }
        }
        $sanitized['stock_sync_type'] = 'category';
        $category = isset($input['stock_sync_value_category']) ? sanitize_title($input['stock_sync_value_category']) : '';

        // A different category means a different set of products: free the slots
        $previous_category = (isset($previous['stock_sync_type'], $previous['stock_sync_value']) && $previous['stock_sync_type'] === 'category')
            ? $previous['stock_sync_value'] : '';
        if ($category !== $previous_category) {
            Stock_Sync::reset_lite_slots();
        }
        $sanitized['stock_sync_value'] = $category;

        return $sanitized;
    }

    public function enqueue_scripts($hook) {
        // Menu badges are shown on every admin page
        wp_add_inline_style('wp-admin', '.swos-pro-badge{display:inline-block;margin-left:4px;padding:0 5px;border-radius:3px;background:#dba617;color:#1d2327;font-size:9px;font-weight:700;line-height:16px;vertical-align:middle}.swos-menu-buy{color:#f0c33c;font-weight:600}');

        if (strpos($hook, 'woo-sync-adv') === false) {
            return;
        }

        wp_enqueue_style('swos-admin-css', SWOS_PLUGIN_URL . 'assets/css/admin.css', array(), SWOS_VERSION);
        wp_enqueue_script('swos-admin-js', SWOS_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), SWOS_VERSION, true);

        // Add nonce for AJAX requests
        wp_localize_script('swos-admin-js', 'swosAdmin', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('swos-test-connection'),
            'clearLogsNonce' => wp_create_nonce('swos-clear-logs'),
            'testLogNonce' => wp_create_nonce('swos-test-log'),
            'revokeNonce' => wp_create_nonce('swos-revoke-credentials'),
            'refreshConfigNonce' => wp_create_nonce('swos-refresh-remote-config'),
            'clear_logs_confirm' => __('Are you sure you want to delete all logs? This action cannot be undone.', 'advanced-woo-sync-lite'),
            'revoke_confirm' => __('Are you sure you want to revoke credentials? This will unlock role selection but keep other settings intact.', 'advanced-woo-sync-lite')
        ));
    }

    public function settings_page() {
        if (!class_exists('SWOS\\Admin\\Settings_Page')) {
            require_once SWOS_PLUGIN_DIR . 'includes/admin/class-settings-page.php';
        }
        $page = new Admin\Settings_Page();
        $page->render();
    }

    /**
     * Stock logs page
     */
    public function stock_logs_page() {
        if (!class_exists('SWOS\Admin\Stock_Logs_Page')) {
            require_once SWOS_PLUGIN_DIR . 'includes/admin/class-stock-logs-page.php';
        }
        
        $page = new Admin\Stock_Logs_Page();
        $page->render();
    }

    /**
     * Locked Premium page (mockup + Buy Premium)
     */
    public function locked_page() {
        $slug = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- admin page slug.
        Premium::render_locked_page($slug);
    }

    /**
     * Free the product slots so other products of the category can be synced
     */
    public function handle_reset_slots() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'advanced-woo-sync-lite'));
        }
        check_admin_referer('swos_lite_reset_slots');

        Stock_Sync::reset_lite_slots();

        wp_safe_redirect(admin_url('admin.php?page=woo-sync-adv&tab=stock&swos_slots_reset=1'));
        exit;
    }

    /**
     * Warn once the product limit is reached (products beyond it are not synced)
     */
    public function limit_reached_notice() {
        if (!current_user_can('manage_woocommerce') || !get_option(Stock_Sync::LITE_LIMIT_FLAG)) {
            return;
        }
        printf(
            '<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s" class="button button-primary" target="_blank" rel="noopener">%4$s</a></p></div>',
            esc_html__('Advanced Woo Sync Lite:', 'advanced-woo-sync-lite'),
            /* translators: %d: maximum products */
            esc_html(sprintf(__('the limit of %d synced products has been reached. Stock changes in other products are not being synced.', 'advanced-woo-sync-lite'), SWOS_LITE_MAX_PRODUCTS)),
            esc_url(Premium::url('limit_notice')),
            esc_html__('Buy Premium (unlimited)', 'advanced-woo-sync-lite')
        );
    }

    public function plugin_action_links($links) {
        array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=woo-sync-adv')) . '">' . esc_html__('Settings', 'advanced-woo-sync-lite') . '</a>');
        $links[] = '<a href="' . esc_url(Premium::url('plugins_list')) . '" target="_blank" rel="noopener" style="color:#d63638;font-weight:600">' . esc_html__('Buy Premium', 'advanced-woo-sync-lite') . '</a>';
        return $links;
    }

    /**
     * AJAX handler to generate test logs
     */
    public function ajax_test_log() {

        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'swos-test-log')) {
            wp_send_json_error(array('message' => __('Security verification failed.', 'advanced-woo-sync-lite')));
            return;
        }


        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'advanced-woo-sync-lite')));
            return;
        }


        // Generate test logs
        $timestamp = current_time('mysql');
        Logger::log('debug', 'Test log DEBUG - ' . $timestamp);
        Logger::log('info', 'Test log INFO - ' . $timestamp);
        Logger::log('success', 'Test log SUCCESS - ' . $timestamp);
        Logger::log('warning', 'Test log WARNING - ' . $timestamp);
        Logger::log('error', 'Test log ERROR - ' . $timestamp);


        wp_send_json_success(array(
            'message' => __('✅ Generated 5 test logs (DEBUG, INFO, SUCCESS, WARNING, ERROR). Reload the page to see them.', 'advanced-woo-sync-lite')
        ));
    }

    /**
     * AJAX handler to clear all logs
     */
    public function ajax_clear_logs() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'swos-clear-logs')) {
            wp_send_json_error(array('message' => __('Security verification failed. Please reload the page and try again.', 'advanced-woo-sync-lite')));
            return;
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'advanced-woo-sync-lite')));
            return;
        }

        $log_type = isset($_POST['log_type']) ? sanitize_key(wp_unslash($_POST['log_type'])) : 'all';

        // Clear log files
        $result = Logger::clear_logs($log_type);

        if ($result['deleted'] > 0) {
            wp_send_json_success(array(
                'message' => sprintf(
                    /* translators: %d: number of deleted files */
                    __('Successfully deleted %d log file(s).', 'advanced-woo-sync-lite'),
                    $result['deleted']
                )
            ));
        } else if ($result['success'] && $result['deleted'] === 0) {
            wp_send_json_success(array(
                'message' => __('No log files to delete. Logs are empty.', 'advanced-woo-sync-lite')
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Error deleting logs: ', 'advanced-woo-sync-lite') . implode(', ', $result['errors'])
            ));
        }
    }

    /**
     * AJAX handler to test connection with remote store
     */
    public function ajax_test_connection() {
        check_ajax_referer('swos-test-connection', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'advanced-woo-sync-lite')));
        }

        $settings = get_option('swos_settings');

        // Override with POST data if available (allows testing before saving)
        if (!empty($_POST['url'])) $settings['remote_store_url'] = esc_url_raw(wp_unslash($_POST['url']));
        if (!empty($_POST['key'])) $settings['remote_store_consumer_key'] = sanitize_text_field(wp_unslash($_POST['key']));
        if (!empty($_POST['secret'])) $settings['remote_store_consumer_secret'] = sanitize_text_field(wp_unslash($_POST['secret']));

        if (empty($settings['remote_store_url']) || empty($settings['remote_store_consumer_key']) || empty($settings['remote_store_consumer_secret'])) {
            wp_send_json_error(array('message' => __('Please complete all connection fields.', 'advanced-woo-sync-lite')));
        }

        // Test API connection
        $api_client = new API_Client(
            $settings['remote_store_url'],
            $settings['remote_store_consumer_key'],
            $settings['remote_store_consumer_secret']
        );

        $test_result = $api_client->request('system_status');

        if (is_wp_error($test_result)) {
            /* translators: %s: error message */
            wp_send_json_error(array('message' => sprintf(__('Connection error: %s', 'advanced-woo-sync-lite'), $test_result->get_error_message())));
        }

        // Validate remote store role (and plugin existence)
        $remote_role_response = wp_remote_get(
            trailingslashit($settings['remote_store_url']) . 'wp-json/swos/v1/store-role',
            array(
                'timeout' => 15,
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode($settings['remote_store_consumer_key'] . ':' . $settings['remote_store_consumer_secret'])
                )
            )
        );

        // Strict Plugin Verification
        if (is_wp_error($remote_role_response)) {
            wp_send_json_error(array('message' => __('Connection successful to WooCommerce, BUT our plugin is NOT detected on the remote site. Please install/activate it.', 'advanced-woo-sync-lite')));
        }
        
        $response_code = wp_remote_retrieve_response_code($remote_role_response);
        if ($response_code !== 200) {
            /* translators: %d: HTTP status code */
            wp_send_json_error(array('message' => sprintf(__('Remote plugin endpoint check failed (HTTP %d). Please ensure the plugin is active on the remote site.', 'advanced-woo-sync-lite'), $response_code)));
        }

        $remote_data = json_decode(wp_remote_retrieve_body($remote_role_response), true);

        if (isset($remote_data['role'])) {
            $local_role = $settings['store_role'];
            $remote_role = $remote_data['role'];

            // Validate role compatibility
            if ($local_role === 'central' && $remote_role !== 'remote') {
                wp_send_json_error(array('message' => __('Configuration error: This store is CENTRAL but the remote store is not configured as REMOTE.', 'advanced-woo-sync-lite')));
            }

            if ($local_role === 'remote' && $remote_role !== 'central') {
                wp_send_json_error(array('message' => __('Configuration error: This store is REMOTE but the remote store is not configured as CENTRAL.', 'advanced-woo-sync-lite')));
            }

            // Get URLs for display
            $local_url = get_site_url();
            $remote_url = untrailingslashit($settings['remote_store_url']);

            wp_send_json_success(array('message' => sprintf(
                /* translators: 1: local store role, 2: local store URL, 3: remote store role, 4: remote store URL */
                __('Connection established successfully. Local store: %1$s (%2$s), Remote store: %3$s (%4$s)', 'advanced-woo-sync-lite'),
                strtoupper($local_role),
                $local_url,
                strtoupper($remote_role),
                $remote_url
            )));
        } else {
            wp_send_json_success(array('message' => __('Connection established successfully.', 'advanced-woo-sync-lite')));
        }
    }

    /**
     * AJAX handler for revoking credentials
     * Clears URL, CK, CS and cached remote config
     */
    public function ajax_revoke_credentials() {
        check_ajax_referer('swos-revoke-credentials', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'advanced-woo-sync-lite')));
        }

        $settings = get_option('swos_settings');
        
        // Clear credentials
        $settings['remote_store_url'] = '';
        $settings['remote_store_consumer_key'] = '';
        $settings['remote_store_consumer_secret'] = '';
        
        update_option('swos_settings', $settings);
        
        // Clear cached remote config
        $remote_checker = new Sync\Remote_Config_Checker();
        $remote_checker->clear_cache();

        wp_send_json_success(array(
            'message' => __('Credentials revoked successfully. Role selection is now unlocked.', 'advanced-woo-sync-lite')
        ));
    }

    /**
     * AJAX handler for refreshing remote config
     * Fetches current config from remote store and caches it
     */
    public function ajax_refresh_remote_config() {
        check_ajax_referer('swos-refresh-remote-config', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'advanced-woo-sync-lite')));
        }

        $remote_checker = new Sync\Remote_Config_Checker();
        $result = $remote_checker->fetch_and_cache_remote_config();

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array(
            'message' => __('Remote configuration refreshed successfully.', 'advanced-woo-sync-lite'),
            'config' => $result
        ));
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        register_rest_route('swos/v1', '/store-role', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_store_role'),
            'permission_callback' => '__return_true', // Public endpoint for testing
        ));

        // Endpoint to get product or variation by SKU
        // Updated regex to accept spaces and more special characters
        register_rest_route('swos/v1', '/product-by-sku/(?P<sku>.+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_product_by_sku_endpoint'),
            'permission_callback' => 'swos_rest_permission_check', // WooCommerce API key auth
            'args' => array(
                'sku' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                )
            )
        ));

        // Bulk endpoint to get multiple products by SKUs (for comparison)
        register_rest_route('swos/v1', '/products-by-skus', array(
            'methods' => 'POST',
            'callback' => array($this, 'get_products_by_skus_endpoint'),
            'permission_callback' => 'swos_rest_permission_check', // WooCommerce API key auth
            'args' => array(
                'skus' => array(
                    'required' => true,
                    'type' => 'array',
                    'items' => array('type' => 'string'),
                    'sanitize_callback' => function($skus) {
                        return array_map('sanitize_text_field', $skus);
                    }
                )
            )
        ));
    }

    /**
     * Get store role via REST API
     */
    public function get_store_role() {
        $settings = get_option('swos_settings');
        $role = isset($settings['store_role']) ? $settings['store_role'] : 'central';

        return array(
            'role' => $role,
            'site_url' => home_url(),
            'plugin_version' => SWOS_VERSION
        );
    }

    /**
     * Get product or variation by SKU via REST API
     * Searches both simple products and product variations
     *
     * @param \WP_REST_Request $request The REST request
     * @return array|\WP_Error Product data or error
     */
    public function get_product_by_sku_endpoint($request) {
        global $wpdb;

        $sku = $request->get_param('sku');

        if (empty($sku)) {
            return new \WP_Error('missing_sku', __('SKU parameter is required', 'advanced-woo-sync-lite'), array('status' => 400));
        }

        // Search for product or variation by SKU using direct database query.
        // Post status is not restricted (non-standard statuses must still be found), but trashed
        // products and revisions are excluded and published products win when a SKU is duplicated.
        $product_id = $wpdb->get_var($wpdb->prepare(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm
            LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = '_sku'
            AND pm.meta_value = %s
            AND (p.ID IS NULL OR (p.post_status NOT IN ('trash', 'auto-draft') AND p.post_type <> 'revision'))
            ORDER BY (p.post_status = 'publish') DESC, pm.post_id ASC
            LIMIT 1",
            $sku
        ));

        if (!$product_id) {
            /* translators: %s: product SKU */
            return new \WP_Error('product_not_found', sprintf(__('Product with SKU "%s" not found', 'advanced-woo-sync-lite'), $sku), array('status' => 404));
        }

        // Get product object using WooCommerce function
        $product = wc_get_product($product_id);

        if (!$product) {
            return new \WP_Error('product_load_error', __('Failed to load product', 'advanced-woo-sync-lite'), array('status' => 500));
        }

        // Determine product type
        $is_variation = $product->is_type('variation');
        $type = $is_variation ? 'variation' : 'simple';

        // Prepare response data
        $response = array(
            'type' => $type,
            'id' => $product->get_id(),
            'sku' => $product->get_sku(),
            'name' => $product->get_name(),
            'stock_quantity' => $product->get_stock_quantity(),
            'manage_stock' => $product->get_manage_stock(),
            'stock_status' => $product->get_stock_status(),
            // Price data
            'price' => $product->get_price(),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'on_sale' => $product->is_on_sale(),
        );

        // Add parent_id and attributes for variations
        if ($is_variation) {
            $response['parent_id'] = $product->get_parent_id();
            $response['attributes'] = $product->get_attributes();
        }

        return $response;
    }

    /**
     * Get multiple products by SKUs via REST API (bulk endpoint)
     * Optimized for comparison operations - single query for multiple SKUs
     *
     * @param \WP_REST_Request $request The REST request
     * @return array Products data indexed by SKU
     */
    /**
     * Get multiple products by SKUs via REST API (bulk endpoint)
     * Direct postmeta search strategy.
     * Removes JOINs with wp_posts to avoid corrupted post_type/status products
     * being filtered in SQL. Validation is delegated to wc_get_product().
     */
    public function get_products_by_skus_endpoint($request) {
        global $wpdb;

        $skus = $request->get_param('skus');

        if (empty($skus) || !is_array($skus)) {
            return new \WP_Error('missing_skus', __('SKUs array is required', 'advanced-woo-sync-lite'), array('status' => 400));
        }

        // Safety limit
        if (count($skus) > 200) {
            $skus = array_slice($skus, 0, 200);
        }

        // Basic type and space sanitization
        $skus = array_map('trim', array_map('strval', $skus));

        // Prepare placeholders for IN query
        $placeholders = implode(',', array_fill(0, count($skus), '%s'));

        // Direct postmeta search (non-standard statuses must still be found), excluding trashed
        // products and revisions. Published products come first so they win on duplicated SKUs.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is a list of %s, one per SKU.
        $query = $wpdb->prepare(
            "SELECT pm.post_id, pm.meta_value as sku
             FROM {$wpdb->postmeta} pm
             LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_sku'
             AND pm.meta_value IN ($placeholders)
             AND (p.ID IS NULL OR (p.post_status NOT IN ('trash', 'auto-draft') AND p.post_type <> 'revision'))
             ORDER BY (p.post_status = 'publish') DESC, pm.post_id ASC",
            ...$skus
        );
        // phpcs:enable

        $results = $wpdb->get_results($query); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.

        $response = array();

        foreach ($results as $row) {
            $product_id = intval($row->post_id);
            // We use the SKU coming from DB to map
            $sku_key = trim($row->sku);

            // Load WooCommerce object.
            // This function internally handles validation of whether the post is a valid product.
            $product = wc_get_product($product_id);

            if (!$product) {
                // Only if WC says it's not a product, we discard it.
                continue;
            }

            // Get fresh data from object
            $real_sku = trim($product->get_sku());
            $is_variation = $product->is_type('variation');
            $type = $is_variation ? 'variation' : 'simple';

            $product_data = array(
                'type'           => $type,
                'id'             => $product->get_id(),
                'sku'            => $real_sku,
                'name'           => $product->get_name(),
                'stock_quantity' => $product->get_stock_quantity(),
                'manage_stock'   => $product->get_manage_stock(),
                'stock_status'   => $product->get_stock_status(),
            );

            if ($is_variation) {
                $product_data['parent_id'] = $product->get_parent_id();
                $product_data['attributes'] = $product->get_attributes();
            }

            // Include price data in bulk response
            $product_data['price'] = $product->get_price();
            $product_data['regular_price'] = $product->get_regular_price();
            $product_data['sale_price'] = $product->get_sale_price();
            $product_data['on_sale'] = $product->is_on_sale();

            // Use found SKU as array key (first match wins: published products are ordered first)
            if (!isset($response[$sku_key])) {
                $response[$sku_key] = $product_data;
            }
        }

        return $response;
    }

    /**
     * Render plugin version footer
     */
    public static function render_version_footer() {
        ?>
        <div class="swos-footer">
            <?php do_action('swos_before_admin_footer'); ?>
            <p>
                <strong><?php esc_html_e('Advanced Woo Sync Lite', 'advanced-woo-sync-lite'); ?></strong> -
                <?php
                /* translators: %s: plugin version */
                echo esc_html(sprintf(__('Version %s', 'advanced-woo-sync-lite'), SWOS_VERSION));
                ?> -
                <?php esc_html_e('Developed by Zavants', 'advanced-woo-sync-lite'); ?> -
                <a href="<?php echo esc_url(Premium::url('footer')); ?>" target="_blank" rel="noopener"><?php esc_html_e('Buy Premium', 'advanced-woo-sync-lite'); ?></a>
            </p>
            <?php do_action('swos_after_admin_footer'); ?>
        </div>
        <?php
    }
}
