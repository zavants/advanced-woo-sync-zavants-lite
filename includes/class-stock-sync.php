<?php
/**
 * Stock Synchronization with Action Scheduler
 *
 * @package SWOS
 */

namespace SWOS;

if (!defined('ABSPATH')) {
    exit;
}

class Stock_Sync {

    const SYNC_ACTION = 'swos_sync_stock_action';
    const SYNC_HEADER = 'X-SWOS-Sync-By';

    /**
     * Seconds to wait before pushing a change, so bursts of changes collapse into one request
     */
    const DEBOUNCE_SECONDS = 5;

    /**
     * Retries after a failed push (transient errors only)
     */
    const MAX_RETRIES = 3;

    private $settings;

    /**
     * Hooks are registered once per request even if the class is instantiated several times
     * (bulk sync, AJAX handlers and the comparison table create their own instances).
     * @var bool
     */
    private static $hooks_registered = false;

    /**
     * Track products being updated from remote to prevent recursion during date update
     * @var array
     */
    private static $updating_from_remote = array();

    /**
     * Track products whose local date is being updated to prevent recursion
     * @var array
     */
    private static $updating_local_date = array();

    public function __construct() {
        $this->settings = get_option('swos_settings');

        // Check if stock sync is enabled
        if (empty($this->settings['stock_sync_enabled']) || self::$hooks_registered) {
            return;
        }
        self::$hooks_registered = true;

        // Hook into stock changes - PRIMARY hooks (WooCommerce native)
        add_action('woocommerce_product_set_stock', array($this, 'on_stock_changed'), 10, 1);
        add_action('woocommerce_variation_set_stock', array($this, 'on_stock_changed'), 10, 1);

        // Hook into stock status changes
        add_action('woocommerce_product_set_stock_status', array($this, 'on_stock_status_changed'), 10, 3);

        // ADDITIONAL hooks to catch ALL stock changes (manual edits, bulk edits, programmatic)
        add_action('woocommerce_update_product', array($this, 'on_product_updated'), 20, 1);
        add_action('woocommerce_update_product_variation', array($this, 'on_product_updated'), 20, 1);

        // Hook for meta updates (catches bulk edits and programmatic changes)
        add_action('updated_post_meta', array($this, 'on_stock_meta_updated'), 10, 4);

        // Register Action Scheduler hook for background processing
        add_action(self::SYNC_ACTION, array($this, 'process_sync_action'), 10, 3);
    }

    /**
     * Triggered when product stock quantity changes
     * Instead of syncing immediately, schedule an async action
     */
    public function on_stock_changed($product) {
        $this->schedule_sync_if_needed($product, 'woocommerce_product_set_stock');
    }

    /**
     * Handles stock status transitions (e.g., in-stock to out-of-stock).
     *
     * @param int $product_id Product ID
     * @param string $stock_status New stock status
     * @param \WC_Product $product Product object
     */
    public function on_stock_status_changed($product_id, $stock_status, $product) {
        // Load product if not provided (some hooks don't pass it)
        if (!$product || !is_a($product, 'WC_Product')) {
            $product = wc_get_product($product_id);
        }

        if (!$product) {
            return;
        }

        $this->schedule_sync_if_needed($product, 'woocommerce_product_set_stock_status');
    }

    /**
     * Schedule sync action if needed (used by all hooks)
     * This is the ONLY method that schedules syncs - keeps all logic centralized
     *
     * @param \WC_Product $product Product object
     * @param string $hook_name Name of the hook that triggered this (for logging)
     */
    private function schedule_sync_if_needed($product, $hook_name = '') {
        // Prevent sync loops: if this update comes from our own API request
        if ($this->is_sync_from_remote()) {
            $product_id = $product->get_id();

            // Prevent recursion if this method is triggered again by $product->save()
            if (isset(self::$updating_from_remote[$product_id])) {
                return;
            }

            Logger::log('debug', sprintf('Update detected from remote sync (header X-SWOS-Sync-By) for product #%d. Updating modification date.', $product_id), 0, array('source' => 'stock_sync'));
            
            // Mark as updating to prevent recursion
            self::$updating_from_remote[$product_id] = true;

            try {
                // Update product modification date to current time
                $product->set_date_modified(current_time('mysql'));
                $product->save();
                
                Logger::log('info', sprintf('✅ Product #%d modification date updated due to remote sync.', $product_id), 0, array('source' => 'stock_sync'));
            } catch (\Exception $e) {
                Logger::log('error', sprintf('Failed to update modification date for product #%d: %s', $product_id, $e->getMessage()), 0, array('source' => 'stock_sync'));
            }

            // Unset after processing
            unset(self::$updating_from_remote[$product_id]);
            return;
        }

        // Get product ID and SKU
        $product_id = $product->get_id();
        $sku = $product->get_sku();
        $new_stock = $product->get_stock_quantity();

        Logger::log('debug', sprintf('Stock hook triggered [%s]: Product ID #%d, SKU: %s, New stock: %s',
            $hook_name, $product_id, $sku, $new_stock), 0, array('source' => 'stock_sync'));

        // Check if product should be synced based on config
        if (!$this->should_sync_product($product)) {
            Logger::log('debug', sprintf('Product #%d (SKU: %s) does not meet sync criteria', $product_id, $sku), 0, array('source' => 'stock_sync'));
            return;
        }

        // Nothing to push if the remote already holds this stock
        if (!Sync_DB::should_sync($product_id, $new_stock)) {
            Logger::log('debug', sprintf('Product #%d (SKU: %s) sync skipped: stock unchanged since last sync', $product_id, $sku), 0, array('source' => 'stock_sync'));
            return;
        }

        // Schedule async action using Action Scheduler.
        // Changes are never dropped: one pending action per product, delayed a few seconds so bursts
        // collapse into one push. The action reads the CURRENT stock when it runs.
        if (function_exists('as_schedule_single_action')) {
            $existing = as_get_scheduled_actions(array(
                'hook' => self::SYNC_ACTION,
                'args' => array($product_id),
                'status' => \ActionScheduler_Store::STATUS_PENDING,
            ), 'ids');

            if (empty($existing)) {
                as_schedule_single_action(time() + self::DEBOUNCE_SECONDS, self::SYNC_ACTION, array($product_id), 'swos_sync');
                Logger::log('debug', sprintf('Sync action scheduled for product #%d (SKU: %s) [hook: %s]', $product_id, $sku, $hook_name), 0, array('source' => 'stock_sync'));
            } else {
                Logger::log('debug', sprintf('Sync already scheduled for product #%d (SKU: %s)', $product_id, $sku), 0, array('source' => 'stock_sync'));
            }
        } else {
            // Fallback to immediate sync if Action Scheduler not available
            Logger::log('warning', 'Action Scheduler not available, executing immediate sync', 0, array('source' => 'stock_sync'));
            $this->sync_stock_to_remote($product_id, $sku, $new_stock);
        }
    }

    /**
     * Process scheduled sync action (called by Action Scheduler)
     *
     * @param int $product_id Product ID
     * @param int|float|null $stock Stock at scheduling time (legacy actions only, the current stock is used)
     * @param int $attempt Retry attempt number
     * @throws \Exception When the push failed, so Action Scheduler records the action as failed
     */
    public function process_sync_action($product_id, $stock = null, $attempt = 0) {
        // Get fresh product data
        $product = wc_get_product($product_id);

        if (!$product) {
            Logger::log('error', sprintf('Product #%d not found when processing sync action', $product_id), 0, array('source' => 'stock_sync'));
            return;
        }

        $sku = $product->get_sku();
        $current_stock = $product->get_stock_quantity();

        Logger::log('debug', sprintf('Processing sync action: Product #%d (SKU: %s), Current stock: %s, Attempt: %d',
            $product_id, $sku, $current_stock, $attempt), 0, array('source' => 'stock_sync'));

        // Refresh the local modification date here (async) instead of saving the product inside the stock hooks
        $this->touch_local_date_modified($product);

        // Use current stock (it may have changed since scheduling)
        $result = $this->sync_stock_to_remote($product_id, $sku, $current_stock, true);

        if (isset($result['status']) && $result['status'] === 'success') {
            return;
        }

        $reason = isset($result['reason']) ? $result['reason'] : 'unknown';

        // Configuration problems will not fix themselves; transient ones (network, remote errors, lookup misses) are retried
        $permanent = in_array($reason, array('no_sku', 'local_not_found', 'config_incomplete'), true);

        if (!$permanent && $attempt < self::MAX_RETRIES && function_exists('as_schedule_single_action')) {
            $delay = 60 * pow(2, $attempt); // 1, 2, 4 minutes
            as_schedule_single_action(time() + $delay, self::SYNC_ACTION, array($product_id, null, $attempt + 1), 'swos_sync');
            Logger::log('warning', sprintf('Stock sync for product #%d (SKU: %s) failed (%s). Retry %d/%d in %d seconds.',
                $product_id, $sku, $reason, $attempt + 1, self::MAX_RETRIES, $delay), 0, array('source' => 'stock_sync'));
        }

        throw new \Exception(esc_html(sprintf('Stock sync failed for product #%d (SKU: %s): %s', $product_id, $sku,
            isset($result['message']) ? $result['message'] : $reason)));
    }

    /**
     * Update the product modification date without re-triggering our own hooks
     *
     * @param \WC_Product $product
     */
    private function touch_local_date_modified($product) {
        $product_id = $product->get_id();

        if (isset(self::$updating_local_date[$product_id])) {
            return;
        }

        self::$updating_local_date[$product_id] = true;
        try {
            $product->set_date_modified(current_time('timestamp', true));
            $product->save();
        } catch (\Exception $e) {
            Logger::log('error', sprintf('Failed to update local modification date for product #%d: %s', $product_id, $e->getMessage()), 0, array('source' => 'stock_sync'));
        }
        unset(self::$updating_local_date[$product_id]);
    }

    /**
     * Validates request origin to prevent circular synchronization loops (anti-echo).
     *
     * @return bool True if from remote sync (SWOS sync only)
     */
    private function is_sync_from_remote() {
        // Check if we're in a REST request
        if (!defined('REST_REQUEST') || !REST_REQUEST) {
            return false;
        }

        // Check for our custom header (X-SWOS-Sync-By)
        $headers = function_exists('getallheaders') ? getallheaders() : array();
        if ($headers && isset($headers[self::SYNC_HEADER])) {
            return true;
        }

        // Also check Apache-style header
        $apache_header = 'HTTP_' . str_replace('-', '_', strtoupper(self::SYNC_HEADER));
        if (isset($_SERVER[$apache_header])) {
            return true;
        }

        return false;
    }

    /**
     * Triggered when product is updated (catches manual edits)
     *
     * @param int $product_id Product ID
     */
    public function on_product_updated($product_id) {
        // Our own date_modified refresh saves the product: don't schedule again for it
        if (isset(self::$updating_local_date[$product_id])) {
            return;
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }

        // Only sync if product manages stock
        if (!$product->get_manage_stock()) {
            return;
        }

        $this->schedule_sync_if_needed($product, 'woocommerce_update_product');
    }

    /**
     * Triggered when post meta is updated (catches bulk edits and programmatic changes)
     *
     * @param int $meta_id Meta ID
     * @param int $post_id Post ID
     * @param string $meta_key Meta key
     * @param mixed $meta_value Meta value
     */
    public function on_stock_meta_updated($meta_id, $post_id, $meta_key, $meta_value) {
        // Only process stock-related meta keys
        if ($meta_key !== '_stock') {
            return;
        }

        // Get post type
        $post_type = get_post_type($post_id);

        // Only process products and variations
        if (!in_array($post_type, array('product', 'product_variation'))) {
            return;
        }

        $product = wc_get_product($post_id);
        if (!$product) {
            return;
        }

        // Only sync if product manages stock
        if (!$product->get_manage_stock()) {
            return;
        }

        $this->schedule_sync_if_needed($product, 'updated_post_meta');
    }

    /**
     * Option holding the products that use a Lite slot (parent IDs for variations)
     */
    const LITE_SLOTS_OPTION = 'swos_lite_product_slots';

    /**
     * Option set while the Lite product limit is blocking products
     */
    const LITE_LIMIT_FLAG = 'swos_lite_limit_reached';

    /**
     * @return int[] Product IDs using a slot
     */
    public static function get_lite_slots() {
        $slots = get_option(self::LITE_SLOTS_OPTION, array());
        return is_array($slots) ? array_map('intval', $slots) : array();
    }

    /**
     * Free every slot (e.g. when the synced category changes)
     */
    public static function reset_lite_slots() {
        update_option(self::LITE_SLOTS_OPTION, array(), false);
        delete_option(self::LITE_LIMIT_FLAG);
    }

    /**
     * Lite keeps up to SWOS_LITE_MAX_PRODUCTS products in sync. A product takes a slot the first
     * time it has to be synced and keeps it; a variable product and its variations share one slot.
     *
     * @param int $slot_id Product ID (parent ID for variations)
     * @return bool True if the product has (or just got) a slot
     */
    private function claim_lite_slot($slot_id) {
        $slots = self::get_lite_slots();

        if (in_array($slot_id, $slots, true)) {
            return true;
        }

        if (count($slots) >= SWOS_LITE_MAX_PRODUCTS) {
            if (!get_option(self::LITE_LIMIT_FLAG)) {
                update_option(self::LITE_LIMIT_FLAG, 1, false);
            }
            return false;
        }

        $slots[] = $slot_id;
        update_option(self::LITE_SLOTS_OPTION, $slots, false);
        return true;
    }

    /**
     * Validates if the product meets the synchronization criteria: Lite syncs the products of one
     * category, up to SWOS_LITE_MAX_PRODUCTS products.
     * For variations, checks against the parent product's categories.
     */
    private function should_sync_product($product) {
        $sync_value = isset($this->settings['stock_sync_value']) ? $this->settings['stock_sync_value'] : '';

        if (empty($sync_value)) {
            Logger::log('debug', 'Stock sync configuration empty: no category selected', 0, array('source' => 'stock_sync'));
            return false;
        }

        $product_id = $product->get_id();
        $sku = $product->get_sku();
        $is_variation = $product->is_type('variation');

        // For variations, get parent product ID for category checks
        $check_product_id = $is_variation ? $product->get_parent_id() : $product_id;

        $categories = wp_get_post_terms($check_product_id, 'product_cat', array('fields' => 'slugs'));
        $in_category = !is_wp_error($categories) && in_array($sync_value, $categories, true);

        Logger::log('debug', sprintf('Checking category "%s" in product #%d%s - Categories: %s - Result: %s',
            $sync_value,
            $check_product_id,
            $is_variation ? ' (parent of variation #' . $product_id . ')' : '',
            !empty($categories) && !is_wp_error($categories) ? implode(', ', $categories) : 'none',
            $in_category ? 'Yes sync' : 'No sync'
        ), 0, array('source' => 'stock_sync'));

        if (!$in_category) {
            return false;
        }

        // Products without SKU can never be matched in the other store: don't spend a slot on them
        if (empty($sku)) {
            return false;
        }

        if (!$this->claim_lite_slot($check_product_id)) {
            Logger::log('warning', sprintf('Product #%d (SKU: %s) not synced: Lite limit of %d products reached. Upgrade to Premium for unlimited products.',
                $product_id, $sku, SWOS_LITE_MAX_PRODUCTS), 0, array('source' => 'stock_sync'));
            return false;
        }

        return true;
    }

    /**
     * Sync stock to remote store (supports simple products and variations)
     *
     * @param int $product_id Local product ID
     * @param string $sku Product SKU
     * @param int|float|null $new_stock New stock quantity
     * @param bool $return_status If true, return status array instead of void
     * @return array|void Status array if $return_status is true
     */
    private function sync_stock_to_remote($product_id, $sku, $new_stock, $return_status = false) {
        if (empty($sku)) {
            Logger::log('error', sprintf('Product #%d has no SKU, cannot sync stock', $product_id), 0, array('source' => 'stock_sync'));
            return $return_status ? array('status' => 'error', 'reason' => 'no_sku') : null;
        }

        // Get product for type detection
        $product = wc_get_product($product_id);
        if (!$product) {
            Logger::log('error', sprintf('Product #%d not found', $product_id), 0, array('source' => 'stock_sync'));
            return $return_status ? array('status' => 'error', 'reason' => 'local_not_found') : null;
        }

        $is_variation = $product->is_type('variation');
        $product_type = $is_variation ? 'variation' : 'simple';

        // Get remote store credentials
        $destination_url = isset($this->settings['remote_store_url']) ? untrailingslashit($this->settings['remote_store_url']) : '';
        $destination_key = isset($this->settings['remote_store_consumer_key']) ? $this->settings['remote_store_consumer_key'] : '';
        $destination_secret = isset($this->settings['remote_store_consumer_secret']) ? $this->settings['remote_store_consumer_secret'] : '';

        if (empty($destination_url) || empty($destination_key) || empty($destination_secret)) {
            Logger::log('error', 'Incomplete remote store configuration for stock sync', 0, array('source' => 'stock_sync'));
            return $return_status ? array('status' => 'error', 'reason' => 'config_incomplete') : null;
        }

        $api_client = new API_Client($destination_url, $destination_key, $destination_secret);

        // Find product or variation in remote store by SKU
        Logger::log('info', sprintf('Searching for product with SKU "%s" in remote store (local type: %s)', $sku, $product_type), 0, array('source' => 'stock_sync'));

        $remote_product = $api_client->get_product_or_variation_by_sku($sku);

        if (!$remote_product) {
            Logger::log('error', sprintf('Product with SKU "%s" not found in remote store', $sku), 0, array('source' => 'stock_sync'));
            return $return_status ? array('status' => 'error', 'reason' => 'remote_not_found', 'sku' => $sku) : null;
        }

        $remote_product_id = $remote_product['id'];
        $remote_product_type = isset($remote_product['type']) ? $remote_product['type'] : 'simple';
        $remote_parent_id = isset($remote_product['parent_id']) ? $remote_product['parent_id'] : null;
        $old_remote_stock = isset($remote_product['stock_quantity']) ? $remote_product['stock_quantity'] : 0;

        $log_msg = sprintf('Product found in remote - Type: %s, ID: #%d', $remote_product_type, $remote_product_id);
        if ($remote_parent_id) {
            $log_msg .= sprintf(', Parent: #%d', $remote_parent_id);
        }
        $log_msg .= sprintf(', Current stock: %s', $old_remote_stock);

        Logger::log('info', $log_msg, 0, array('source' => 'stock_sync'));

        $manage_stock = (bool) $product->get_manage_stock();
        $backorders = $product->get_backorders();

        if ($manage_stock) {
            // Status derived from quantity (as WooCommerce does), honoring backorders
            if ($new_stock !== null && $new_stock > 0) {
                $calculated_stock_status = 'instock';
            } elseif ($backorders !== 'no') {
                $calculated_stock_status = 'onbackorder';
            } else {
                $calculated_stock_status = 'outofstock';
            }
        } else {
            // No quantity to derive from: replicate the local status as is
            $calculated_stock_status = $product->get_stock_status();
        }

        // Prepare complete stock data from local product to replicate to remote
        $update_data = array(
            'manage_stock' => $manage_stock,
            'stock_status' => $calculated_stock_status,
            'backorders' => $backorders,
        );

        if ($manage_stock) {
            $update_data['stock_quantity'] = $new_stock;
        }

        // Add low stock amount if set
        $low_stock_amount = $product->get_low_stock_amount();
        if ($low_stock_amount !== '') {
            $update_data['low_stock_amount'] = $low_stock_amount;
        }

        $log_data_summary = sprintf('manage_stock=%s, stock_quantity=%s, stock_status=%s, backorders=%s',
            $update_data['manage_stock'] ? 'true' : 'false',
            $manage_stock ? $new_stock : 'n/a',
            $update_data['stock_status'],
            $update_data['backorders']
        );

        Logger::log('info', sprintf('Updating stock in remote store - Type: %s, ID: #%d - Config: %s',
            $remote_product_type, $remote_product_id, $log_data_summary), 0, array('source' => 'stock_sync'));

        $result = $api_client->update_product_stock($remote_product, $update_data);

        if (is_wp_error($result)) {
            Logger::log('error', sprintf('Error syncing stock for SKU "%s": %s', $sku, $result->get_error_message()), 0, array(
                'source' => 'stock_sync',
                'sku' => $sku,
                'local_product_id' => $product_id,
                'local_product_type' => $product_type,
                'remote_product_id' => $remote_product_id,
                'remote_product_type' => $remote_product_type,
                'remote_parent_id' => $remote_parent_id,
                'new_stock' => $new_stock
            ));
            return $return_status ? array('status' => 'error', 'reason' => 'api_error', 'message' => $result->get_error_message()) : null;
        }

        if ($result && isset($result['id'])) {
            $final_stock = isset($result['stock_quantity']) ? $result['stock_quantity'] : $new_stock;

            // Update our database with successful sync
            Sync_DB::update_sync_state($product_id, $sku, $final_stock, 'local');

            $success_msg = sprintf('✅ Stock synced successfully - SKU: %s, Type: %s, Local product: #%d, Remote product: #%d',
                $sku,
                $remote_product_type,
                $product_id,
                $remote_product_id
            );

            if ($remote_parent_id) {
                $success_msg .= sprintf(' (Parent: #%d)', $remote_parent_id);
            }

            $success_msg .= sprintf(', Old remote stock: %s, New stock: %s', $old_remote_stock, $final_stock);

            Logger::log('success', $success_msg, 0, array(
                'source' => 'stock_sync',
                'sku' => $sku,
                'local_product_id' => $product_id,
                'local_product_type' => $product_type,
                'remote_product_id' => $remote_product_id,
                'remote_product_type' => $remote_product_type,
                'remote_parent_id' => $remote_parent_id,
                'old_stock' => $old_remote_stock,
                'new_stock' => $final_stock,
                'stock_difference' => $final_stock - $old_remote_stock
            ));

            return $return_status ? array('status' => 'success', 'sku' => $sku, 'stock' => $final_stock) : null;
        } else {
            Logger::log('warning', sprintf('Unexpected response when syncing stock for SKU "%s"', $sku), 0, array(
                'source' => 'stock_sync',
                'response' => $result
            ));

            return $return_status ? array('status' => 'error', 'reason' => 'unexpected_response') : null;
        }
    }
}
