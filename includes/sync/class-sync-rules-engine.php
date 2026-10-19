<?php
/**
 * Sync Rules Engine
 *
 * Determines sync behavior based on local and remote configuration.
 *
 * @package SWOS\Sync
 */

namespace SWOS\Sync;

if (!defined('ABSPATH')) {
    exit;
}

class Sync_Rules_Engine {

    /**
     * @var array Plugin settings
     */
    private $settings;

    /**
     * @var Remote_Config_Checker
     */
    private $remote_checker;

    /**
     * Constructor
     *
     * @param array|null $settings Optional settings override
     */
    public function __construct($settings = null) {
        $this->settings = $settings ?? get_option('swos_settings', array());
        $this->remote_checker = new Remote_Config_Checker();
    }

    /**
     * Check if a received order must NOT reduce stock in this store
     *
     * Stock sync is one-way: the store with Stock Sync enabled is the stock master and pushes absolute
     * quantities to the other store. So:
     * - Order from the master to us (we are the slave): the master already reduced its stock and will push
     *   the new quantity. Reducing here too would discount the sale twice -> skip.
     * - Order from the slave to us (we are the master): we are the source of truth and must reduce,
     *   then our push updates the slave -> reduce.
     *
     * @param \WC_Order $order Received order
     * @return bool True if stock reduction should be skipped
     */
    public function should_skip_stock_reduction_for_received_order($order) {
        if (!$order || $order->get_meta('_swos_is_synced') !== 'yes') {
            return false;
        }

        // We are the stock master: always reduce
        if ($this->is_local_stock_sync_enabled()) {
            return false;
        }

        // Senders from this version say whether they push stock to us
        $sender_stock_sync = $order->get_meta('_swos_sender_stock_sync');
        if ($sender_stock_sync !== '') {
            return $sender_stock_sync === 'yes';
        }

        // Older senders: fall back to the cached remote configuration
        return $this->remote_checker->is_remote_stock_sync_enabled();
    }

    /**
     * Check if this store can enable stock sync
     *
     * Rule: If connected remote store already has stock sync enabled pointing to us,
     * this store should not enable its own stock sync (would cause loop).
     *
     * @return bool True if stock sync can be enabled
     */
    public function can_enable_stock_sync() {
        // If remote store already has stock sync enabled, we cannot enable ours
        if ($this->remote_checker->is_remote_stock_sync_enabled()) {
            return false;
        }

        return true;
    }

    /**
     * Check if stock is managed by remote store
     *
     * @return bool
     */
    public function is_stock_managed_by_remote() {
        return $this->remote_checker->is_remote_stock_sync_enabled();
    }

    /**
     * Check if local stock sync is enabled
     *
     * @return bool
     */
    public function is_local_stock_sync_enabled() {
        return !empty($this->settings['stock_sync_enabled']);
    }

    /**
     * Check if local order sync is enabled
     *
     * @return bool
     */
    public function is_local_order_sync_enabled() {
        if (empty($this->settings['enabled'])) {
            return false;
        }

        // Check if any trigger status is configured
        $trigger_keys = array('trigger_status', 'trigger_status_2', 'trigger_status_3', 'trigger_status_4');
        foreach ($trigger_keys as $key) {
            if (!empty($this->settings[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get warning messages for current configuration
     *
     * @return array Array of warning messages
     */
    public function get_configuration_warnings() {
        $warnings = array();
        $remote_url = isset($this->settings['remote_store_url']) ? $this->settings['remote_store_url'] : '';

        // Warning: Stock sync + Order sync active
        if ($this->is_local_stock_sync_enabled() && $this->is_local_order_sync_enabled()) {
            $warnings[] = array(
                'type' => 'info',
                'context' => 'order_sync',
                'message' => sprintf(
                    /* translators: %s: URL of the connected store */
                    __('Stock Sync is active. Orders will sync WITHOUT reducing stock at %s to prevent double reduction.', 'advanced-woo-sync-lite'),
                    $remote_url
                )
            );
        }

        // Warning: Remote has stock sync, local cannot enable
        if ($this->remote_checker->is_remote_stock_sync_enabled()) {
            $warnings[] = array(
                'type' => 'warning',
                'context' => 'stock_sync',
                'message' => sprintf(
                    /* translators: %s: URL of the connected store */
                    __('Stock is managed by %s. Stock Sync is disabled for this store.', 'advanced-woo-sync-lite'),
                    $remote_url
                )
            );
        }

        // Warning: Both have stock sync (potential loop) - should not happen if validation works
        if ($this->is_local_stock_sync_enabled() && $this->remote_checker->is_remote_stock_sync_enabled()) {
            $warnings[] = array(
                'type' => 'error',
                'context' => 'stock_sync',
                'message' => __('Configuration conflict: Both stores have Stock Sync enabled. This may cause sync loops.', 'advanced-woo-sync-lite')
            );
        }

        return $warnings;
    }

    /**
     * Get warnings for a specific context
     *
     * @param string $context 'stock_sync' or 'order_sync'
     * @return array
     */
    public function get_warnings_for_context($context) {
        $all_warnings = $this->get_configuration_warnings();
        return array_filter($all_warnings, function($w) use ($context) {
            return $w['context'] === $context;
        });
    }
}
