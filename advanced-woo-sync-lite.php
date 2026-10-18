<?php
/**
 * Plugin Name: Advanced Woo Sync Lite
 * Description: Real-time stock sync between two WooCommerce stores, by product category (up to 50 products). Upgrade to Premium for order sync, unlimited products and more.
 * Version: 1.0.0
 * Author: Zavants
 * Author URI: https://zavants.com/plugin-advanced-woo-sync
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: advanced-woo-sync-lite
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 8.5
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Premium includes everything in Lite and uses the same classes and settings:
 * when it is active, Lite stays dormant so both can be installed while upgrading.
 */
function swos_lite_premium_is_active() {
    $active = (array) get_option('active_plugins', array());
    if (is_multisite()) {
        $active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', array())));
    }
    foreach ($active as $plugin) {
        if (substr($plugin, -strlen('/sync-woo-orders-stores.php')) === '/sync-woo-orders-stores.php') {
            return true;
        }
    }
    return defined('SWOS_VERSION') && !defined('SWOS_LITE');
}

if (swos_lite_premium_is_active()) {
    return;
}

// Define plugin constants
define('SWOS_LITE', true);
define('SWOS_VERSION', '1.0.0');

/**
 * Maximum number of products (a variable product and its variations count as one) Lite keeps in sync
 */
define('SWOS_LITE_MAX_PRODUCTS', 50);

/**
 * Where "Buy Premium" buttons point to
 */
define('SWOS_PREMIUM_URL', 'https://zavants.com/plugin-advanced-woo-sync');
define('SWOS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SWOS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SWOS_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Check if WooCommerce is active
 */
$swos_active_plugins = (array) apply_filters('active_plugins', get_option('active_plugins', array()));
if (is_multisite()) {
    // Network-activated plugins are stored as keys of active_sitewide_plugins
    $swos_active_plugins = array_merge($swos_active_plugins, array_keys((array) get_site_option('active_sitewide_plugins', array())));
}
if (!in_array('woocommerce/woocommerce.php', $swos_active_plugins, true)) {
    add_action('admin_notices', 'swos_woocommerce_missing_notice');
    return;
}

function swos_woocommerce_missing_notice() {
    ?>
    <div class="error">
        <p><?php _e('Advanced Woo Sync Lite requires WooCommerce to be installed and activated.', 'advanced-woo-sync-lite'); ?></p>
    </div>
    <?php
}

/**
 * Let WooCommerce authenticate our swos/v1 REST routes with consumer key/secret
 * (Basic Auth header, or query string over HTTPS), exactly like wc/v3 routes.
 * Without this, swos/v1 requests never get a WooCommerce API user and the endpoints
 * would have to be public.
 *
 * @param bool $is_request_to_rest_api
 * @return bool
 */
function swos_is_wc_rest_request($is_request_to_rest_api) {
    if ($is_request_to_rest_api || empty($_SERVER['REQUEST_URI'])) {
        return $is_request_to_rest_api;
    }

    $request_uri = esc_url_raw(wp_unslash($_SERVER['REQUEST_URI']));
    $rest_prefix = trailingslashit(rest_get_url_prefix());

    if (strpos($request_uri, $rest_prefix . 'swos/v1/') !== false) {
        return true;
    }

    // Plain permalinks: ?rest_route=/swos/v1/...
    if (isset($_GET['rest_route']) && is_string($_GET['rest_route']) && strpos(wp_unslash($_GET['rest_route']), '/swos/v1/') === 0) {
        return true;
    }

    return $is_request_to_rest_api;
}
add_filter('woocommerce_rest_is_request_to_rest_api', 'swos_is_wc_rest_request');

/**
 * Permission callback for swos/v1 routes: requires a WooCommerce API key (or logged-in admin)
 * whose user can manage products.
 *
 * @return bool
 */
function swos_rest_permission_check() {
    return current_user_can('manage_woocommerce') || current_user_can('edit_products');
}

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'SWOS\\';
    $base_dir = SWOS_PLUGIN_DIR . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    
    // Support for sub-namespaces mapping to sub-directories
    // SWOS\Admin\Diagnostics -> includes/admin/class-diagnostics.php
    $parts = explode('\\', $relative_class);
    $class_name = array_pop($parts);
    $path = implode('/', array_map(function($p) { return strtolower(str_replace('_', '-', $p)); }, $parts));
    
    $file_name = 'class-' . strtolower(str_replace('_', '-', $class_name)) . '.php';
    $file = $base_dir . ($path ? $path . '/' : '') . $file_name;

    if (file_exists($file)) {
        require $file;
    }
});

/**
 * Main plugin class
 */
class Sync_WooCommerce_Orders_Stores {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
        $this->init_classes();
    }

    private function init_hooks() {
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        add_action('plugins_loaded', array($this, 'check_db_version'));
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    /**
     * Register custom REST API routes
     */
    public function register_rest_routes() {
        $config_controller = new SWOS\Sync\REST_Config_Controller();
        $config_controller->register_routes();
    }

    /**
     * Check and update database if needed
     */
    public function check_db_version() {
        $current_version = get_option('swos_db_version', '0');
        if (version_compare($current_version, SWOS\Sync_DB::DB_VERSION, '<')) {
            SWOS\Sync_DB::create_table();
        }
    }

    /**
     * Declare compatibility with WooCommerce HPOS (High-Performance Order Storage)
     */
    public function declare_hpos_compatibility() {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }

    private function init_classes() {
        new SWOS\Admin();

        // Real-time stock sync by category (limited to SWOS_LITE_MAX_PRODUCTS products)
        new SWOS\Stock_Sync();

        SWOS\Logger::instance();
    }

    public function load_textdomain() {
        load_plugin_textdomain('advanced-woo-sync-lite', false, dirname(SWOS_PLUGIN_BASENAME) . '/languages/');
    }

    public function activate() {
        // Set default options with new role-based structure
        $default_options = array(
            'store_role' => 'central', // central, remote
            'remote_store_url' => '',
            'remote_store_consumer_key' => '',
            'remote_store_consumer_secret' => '',
            'enabled' => false, // Order sync is a Premium feature
            'debug_mode' => false,
            'stock_sync_enabled' => false,
            'stock_sync_type' => 'category', // Lite syncs by category only
            'stock_sync_value' => '',
        );

        add_option('swos_settings', $default_options);

        // Create sync state table
        SWOS\Sync_DB::create_table();

        // Logs are now stored in files in wp-content/uploads/wc-logs/
        // No database table needed
    }

    public function deactivate() {
        // Cleanup tasks if needed
    }
}

// Initialize the plugin
function swos_init() {
    return Sync_WooCommerce_Orders_Stores::instance();
}

// Start the plugin
swos_init();
