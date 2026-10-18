<?php
/**
 * Settings Page
 *
 * @package SWOS\Admin
 */

namespace SWOS\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Settings_Page
 */
class Settings_Page {

    /**
     * Render the settings page
     */
    public function render() {
        $settings = get_option('swos_settings');
        $order_statuses = wc_get_order_statuses();
        $store_role = isset($settings['store_role']) ? $settings['store_role'] : 'central';
        $customer_handling = isset($settings['customer_handling']) ? $settings['customer_handling'] : 'create';

        // Initialize sync rules engine for warnings
        $rules_engine = new \SWOS\Sync\Sync_Rules_Engine($settings);
        $remote_checker = new \SWOS\Sync\Remote_Config_Checker();
        
        // Check if credentials are configured (locks role selection)
        $has_credentials = $remote_checker->has_credentials();
        $cached_config = $remote_checker->get_cache_data();
        
        // Get warnings for display
        $all_warnings = $rules_engine->get_configuration_warnings();
        $stock_warnings = $rules_engine->get_warnings_for_context('stock_sync');
        $order_warnings = $rules_engine->get_warnings_for_context('order_sync');
        
        // Check if stock sync can be enabled
        $can_enable_stock_sync = $rules_engine->can_enable_stock_sync();
        $is_stock_managed_by_remote = $rules_engine->is_stock_managed_by_remote();

        // Get current tab - default to connection options
        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'connection';
        
        // Define tabs
        $tabs = array(
            'connection' => __('Connection Options', 'advanced-woo-sync-lite'),
            'stock' => __('Stock Synchronization', 'advanced-woo-sync-lite'),
            'orders' => __('Orders Synchronization', 'advanced-woo-sync-lite'),
        );
        ?>
        <div class="wrap woocommerce">
            <div class="swos-header">
                <h1 class="wp-heading-inline"><?php _e('Woo Sync Lite - Settings', 'advanced-woo-sync-lite'); ?></h1>
                <a href="<?php echo esc_url(\SWOS\Premium::url('settings_header')); ?>" class="page-title-action" target="_blank" rel="noopener"><?php _e('Buy Premium', 'advanced-woo-sync-lite'); ?></a>
                <hr class="wp-header-end">
            </div>

            <div class="notice notice-info">
                <p><strong><?php _e('Role System:', 'advanced-woo-sync-lite'); ?></strong></p>

                <ul class="swos-role-list">
                    <li><strong><?php _e('Central Store (Parent):', 'advanced-woo-sync-lite'); ?></strong> <?php _e('Manages all synchronization settings and can send/receive orders.', 'advanced-woo-sync-lite'); ?></li>
                    <li><strong><?php _e('Remote Store (Child):', 'advanced-woo-sync-lite'); ?></strong> <?php _e('Simplified configuration, only sends orders to the central store. The remote store will sync product stocks according to the central store selection.', 'advanced-woo-sync-lite'); ?></li>
                </ul>
            </div>

                
                <!-- Tab Navigation -->
                <nav class="nav-tab-wrapper woo-nav-tab-wrapper swos-nav-tabs">
                    <?php
                    foreach ($tabs as $key => $label) {
                        $active_class = ($current_tab === $key) ? 'nav-tab-active' : '';
                        $url = admin_url('admin.php?page=woo-sync-adv&tab=' . $key);
                        $badge = $key === 'orders' ? ' <span class="swos-pro-badge">PRO</span>' : '';
                        echo '<a href="' . esc_url($url) . '" class="nav-tab ' . esc_attr($active_class) . '">' . esc_html($label) . $badge . '</a>';
                    }
                    ?>
                </nav>

            <div class="swos-admin-wrapper">



            <form method="post" action="options.php" id="mainform" enctype="multipart/form-data">

                <?php settings_fields('swos_settings_group'); ?>

                <!-- Style to hide non-active sections while keeping them in DOM for saving -->

                
                <?php 
                // Show "Settings Saved" messages
                settings_errors(); 
                ?>

                <table class="form-table">

                    <!-- TAB: Connection Options -->
                    <tbody id="swos-tab-connection" class="swos-tab-section swos-group-connection <?php echo ($current_tab === 'connection') ? 'swos-tab-active' : ''; ?>">
                        <tr>
                            <th colspan="2">
                                <h2><?php _e('Connection Options', 'advanced-woo-sync-lite'); ?></h2>
                            </th>
                        </tr>
                        
                        <!-- Store Role Selection -->
                        <tr>
                            <th scope="row"><?php _e('This Store Role', 'advanced-woo-sync-lite'); ?></th>
                            <td>
                                <?php if ($has_credentials) : ?>
                                    <select id="swos-store-role" disabled>
                                        <option value="central" <?php selected($store_role, 'central'); ?>><?php _e('Central Store (Parent)', 'advanced-woo-sync-lite'); ?></option>
                                        <option value="remote" <?php selected($store_role, 'remote'); ?>><?php _e('Remote Store (Child)', 'advanced-woo-sync-lite'); ?></option>
                                    </select>
                                    <input type="hidden" name="swos_settings[store_role]" value="<?php echo esc_attr($store_role); ?>">
                                    <p class="description" style="color: #d63638;">
                                        <?php _e('Role is locked while credentials are configured. Use "Revoke Credentials" button to change role.', 'advanced-woo-sync-lite'); ?>
                                    </p>
                                <?php else : ?>
                                    <select name="swos_settings[store_role]" id="swos-store-role">
                                        <option value="central" <?php selected($store_role, 'central'); ?>><?php _e('Central Store (Parent)', 'advanced-woo-sync-lite'); ?></option>
                                        <option value="remote" <?php selected($store_role, 'remote'); ?>><?php _e('Remote Store (Child)', 'advanced-woo-sync-lite'); ?></option>
                                    </select>
                                    <p class="description"><?php _e('Select the role of this store. Central store manages sync settings; Remote store connects to Central.', 'advanced-woo-sync-lite'); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        
                        <!-- Connected Store URL -->
                        <tr>
                            <th scope="row" id="swos-remote-label"><?php echo $store_role === 'central' ? __('Remote Store URL', 'advanced-woo-sync-lite') : __('Central Store URL', 'advanced-woo-sync-lite'); ?></th>
                            <td>
                                <input type="url" name="swos_settings[remote_store_url]" value="<?php echo esc_attr(isset($settings['remote_store_url']) ? $settings['remote_store_url'] : ''); ?>" class="regular-text" placeholder="https://other-store.com">
                                <p class="description" id="swos-url-description">
                                    <?php echo $store_role === 'central' ? __('Full URL of the remote store that will connect to this central store', 'advanced-woo-sync-lite') : __('Full URL of the central store where orders will be sent', 'advanced-woo-sync-lite'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Consumer Key', 'advanced-woo-sync-lite'); ?></th>
                            <td>
                                <input type="text" name="swos_settings[remote_store_consumer_key]" value="<?php echo esc_attr(isset($settings['remote_store_consumer_key']) ? $settings['remote_store_consumer_key'] : ''); ?>" class="regular-text">
                                <p class="description"><?php _e('WooCommerce REST API Consumer Key from the other store', 'advanced-woo-sync-lite'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Consumer Secret', 'advanced-woo-sync-lite'); ?></th>
                            <td>
                                <?php $has_secret = !empty($settings['remote_store_consumer_secret']); ?>
                                <input type="password" name="swos_settings[remote_store_consumer_secret]" value="" autocomplete="new-password" placeholder="<?php echo $has_secret ? esc_attr__('Saved (leave empty to keep it)', 'advanced-woo-sync-lite') : ''; ?>" class="regular-text">
                                <p class="description"><?php _e('WooCommerce REST API Consumer Secret from the other store', 'advanced-woo-sync-lite'); ?></p>
                            </td>
                        </tr>

                        <!-- Connection Test Area (Inside this tab now) -->
                        <tr>
                            <th colspan="2" class="swos-test-connection-table">
                                <div class="swos-test-connection">
                                    <h2><?php _e('Test Connection', 'advanced-woo-sync-lite'); ?></h2>
                                    <p id="swos-test-description">
                                        <?php echo $store_role === 'central' ? __('Verify the connection to the remote store:', 'advanced-woo-sync-lite') : __('Verify the connection to the central store:', 'advanced-woo-sync-lite'); ?>
                                        <br>
                                        <em style="color: #666; font-size: 0.9em; display: block; margin-top: 5px;">
                                            <?php _e('To perform the test, you must enter the API credentials and then save the changes.', 'advanced-woo-sync-lite'); ?>
                                        </em>
                                    </p>
                                    <button type="button" class="button" id="swos-test-connection-btn"><?php _e('Test Connection', 'advanced-woo-sync-lite'); ?></button>
                                    <?php if ($has_credentials) : ?>
                                        <button type="button" class="button" id="swos-refresh-remote-config-btn" style="margin-left: 10px;"><?php _e('Refresh Remote Config', 'advanced-woo-sync-lite'); ?></button>
                                    <?php endif; ?>
                                    <div id="swos-test-results"></div>
                                    
                                    <?php if ($cached_config) : ?>
                                        <div class="swos-remote-config-info" style="margin-top: 15px; padding: 10px; background: #f0f0f1; border-left: 4px solid #2271b1;">
                                            <strong><?php _e('Remote Store Configuration:', 'advanced-woo-sync-lite'); ?></strong><br>
                                            <?php 
                                            $remote_role_raw = $cached_config['config']['store_role'] ?? 'unknown';
                                            $remote_role = $remote_role_raw;
                                            if ($remote_role_raw === 'central') {
                                                $remote_role = __('Central Store (Parent)', 'advanced-woo-sync-lite');
                                            } elseif ($remote_role_raw === 'remote') {
                                                $remote_role = __('Remote Store (Child)', 'advanced-woo-sync-lite');
                                            } else {
                                                $remote_role = __('Unknown', 'advanced-woo-sync-lite');
                                            }
                                            $remote_stock = !empty($cached_config['config']['stock_sync_enabled']) ? __('Enabled', 'advanced-woo-sync-lite') : __('Disabled', 'advanced-woo-sync-lite');
                                            $remote_order = !empty($cached_config['config']['order_sync_enabled']) ? __('Enabled', 'advanced-woo-sync-lite') : __('Disabled', 'advanced-woo-sync-lite');
                                            ?>
                                            • <?php _e('Role:', 'advanced-woo-sync-lite'); ?> <strong><?php echo esc_html($remote_role); ?></strong><br>
                                            • <?php _e('Stock Sync:', 'advanced-woo-sync-lite'); ?> <strong><?php echo esc_html($remote_stock); ?></strong><br>
                                            • <?php _e('Order Sync:', 'advanced-woo-sync-lite'); ?> <strong><?php echo esc_html($remote_order); ?></strong><br>
                                            <small><?php echo sprintf(__('Last checked: %s', 'advanced-woo-sync-lite'), esc_html($cached_config['fetched_at'])); ?></small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </th>
                        </tr>
                        
                        <?php if ($has_credentials) : ?>
                        <!-- Revoke Credentials -->
                        <tr>
                            <th colspan="2">
                                <div class="swos-revoke-credentials" style="margin-top: 10px; padding: 15px; background: #fff8e5; border-left: 4px solid #dba617;">
                                    <h3 style="margin-top: 0;"><?php _e('Revoke Credentials', 'advanced-woo-sync-lite'); ?></h3>
                                    <p><?php _e('Clear credentials to unlock role selection. Other settings will be preserved.', 'advanced-woo-sync-lite'); ?></p>
                                    <button type="button" class="button button-secondary" id="swos-revoke-credentials-btn" style="color: #d63638;">
                                        <?php _e('Revoke Credentials', 'advanced-woo-sync-lite'); ?>
                                    </button>
                                </div>
                            </th>
                        </tr>
                        <?php endif; ?>
                        
                        <!-- Logging Section -->
                        <tr>
                            <th colspan="2">
                                <h3><?php _e('Logging', 'advanced-woo-sync-lite'); ?></h3>
                            </th>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Log Level', 'advanced-woo-sync-lite'); ?></th>
                            <td>
                                <?php
                                $log_level = isset($settings['log_level']) ? $settings['log_level'] : 'simple';
                                if (!isset($settings['log_level']) && isset($settings['debug_mode']) && $settings['debug_mode']) {
                                    $log_level = 'detallado';
                                }
                                ?>
                                <select name="swos_settings[log_level]" class="swos-w-300">
                                    <option value="simple" <?php selected($log_level, 'simple'); ?>>
                                        <?php _e('📝 Simple - Only syncs and errors', 'advanced-woo-sync-lite'); ?>
                                    </option>
                                    <option value="normal" <?php selected($log_level, 'normal'); ?>>
                                        <?php _e('📋 Normal - Includes warnings', 'advanced-woo-sync-lite'); ?>
                                    </option>
                                    <option value="detallado" <?php selected($log_level, 'detallado'); ?>>
                                        <?php _e('🔍 Detailed - Complete logs with debug', 'advanced-woo-sync-lite'); ?>
                                    </option>
                                </select>
                                <p class="description">
                                    <strong><?php _e('Simple:', 'advanced-woo-sync-lite'); ?></strong> <?php _e('Logs only successful syncs and error information.', 'advanced-woo-sync-lite'); ?><br>
                                    <strong><?php _e('Normal:', 'advanced-woo-sync-lite'); ?></strong> <?php _e('Includes warnings about skipped products, settings, etc.', 'advanced-woo-sync-lite'); ?><br>
                                    <strong><?php _e('Detailed:', 'advanced-woo-sync-lite'); ?></strong> <?php _e('Complete logs including technical debug info. Useful for troubleshooting.', 'advanced-woo-sync-lite'); ?>
                                </p>
                                <input type="hidden" name="swos_settings[debug_mode]" value="<?php echo $log_level === 'detallado' ? '1' : '0'; ?>">
                            </td>
                        </tr>
                    </tbody>



                    <!-- TAB: Price & Orders (Premium, read-only mockup) -->
                    <tbody id="swos-tab-orders" class="swos-tab-section swos-group-orders swos-locked-section <?php echo ($current_tab === 'orders') ? 'swos-tab-active' : ''; ?>">
                        <tr>
                            <th colspan="2">
                                <?php \SWOS\Premium::render_cta(
                                    __('Order Synchronization is a Premium feature', 'advanced-woo-sync-lite'),
                                    __('Send and receive orders automatically between your stores, with status triggers, price adjustments, coupons and customer mapping.', 'advanced-woo-sync-lite'),
                                    'orders_tab'
                                ); ?>
                            </th>
                        </tr>
                        <?php ob_start(); ?>
                        <!-- Enable Order Sync Toggle -->
                        <tr>
                            <th scope="row"><?php _e('Enable Order Sync', 'advanced-woo-sync-lite'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="swos_settings[enabled]" id="swos-order-sync-enabled" value="1" <?php checked(isset($settings['enabled']) ? $settings['enabled'] : false, true); ?>>
                                    <?php _e('Enable real-time order synchronization', 'advanced-woo-sync-lite'); ?>
                                </label>
                                <p class="description"><?php _e('When enabled, orders will be automatically synced to the connected store based on trigger statuses below.', 'advanced-woo-sync-lite'); ?></p>
                            </td>
                        </tr>

                        <!-- SENDING OPTIONS SECTION -->
                        <tr class="swos-sending-options">
                            <th colspan="2">
                                <h3 class="swos-mt-20"><?php _e('Order Sending Options', 'advanced-woo-sync-lite'); ?></h3>
                                <p class="description" style="font-style: italic;">
                                    <?php _e('Here you can modify order trigger statuses to synchronize with the remote store.', 'advanced-woo-sync-lite'); ?><br>
                                    <?php _e('If using a custom order status, it must be configured with the same slug and name in both stores.', 'advanced-woo-sync-lite'); ?>
                                </p>
                            </th>
                        </tr>

                        <!-- Sync Direction -->
                        <?php if ($store_role === 'central') : ?>
                        <tr class="swos-central-only swos-sending-options">
                            <th scope="row"><?php _e('Sync Direction', 'advanced-woo-sync-lite'); ?></th>
                            <td>
                                <select name="swos_settings[sync_direction]">
                                    <option value="bidirectional" <?php selected(isset($settings['sync_direction']) ? $settings['sync_direction'] : 'bidirectional', 'bidirectional'); ?>><?php _e('Bidirectional (Central ↔ Remote)', 'advanced-woo-sync-lite'); ?></option>
                                    <option value="to_remote" <?php selected(isset($settings['sync_direction']) ? $settings['sync_direction'] : '', 'to_remote'); ?>><?php _e('Central → Remote only', 'advanced-woo-sync-lite'); ?></option>
                                    <option value="from_remote" <?php selected(isset($settings['sync_direction']) ? $settings['sync_direction'] : '', 'from_remote'); ?>><?php _e('Remote → Central only', 'advanced-woo-sync-lite'); ?></option>
                                </select>
                                <p class="description"><?php _e('Defines the direction of order synchronization flow', 'advanced-woo-sync-lite'); ?></p>
                            </td>
                        </tr>
                        <?php endif; ?>

                        <!-- Standard Status Triggers (1-4) -->
                        <?php for ($i = 1; $i <= 4; $i++) : 
                            $suffix = $i === 1 ? '' : '_' . $i;
                            $label_suffix = $i === 1 ? ' 1' : ' ' . $i;
                            $trigger_key = 'trigger_status' . $suffix;
                            $dest_key = 'destination_status' . $suffix;
                            $default_dest = 'processing';
                        ?>
                        <tr class="swos-sending-options">
                            <th scope="row"><?php echo sprintf(__('Trigger Status%s', 'advanced-woo-sync-lite'), $label_suffix); ?></th>
                            <td>
                                <select name="swos_settings[<?php echo $trigger_key; ?>]">
                                    <option value=""><?php _e('-- Do not sync --', 'advanced-woo-sync-lite'); ?></option>
                                    <?php foreach ($order_statuses as $status_key => $status_label) : ?>
                                        <option value="<?php echo esc_attr(str_replace('wc-', '', $status_key)); ?>" <?php selected(isset($settings[$trigger_key]) ? $settings[$trigger_key] : ($i===1?'processing':''), str_replace('wc-', '', $status_key)); ?>>
                                            <?php echo esc_html($status_label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($i===1): ?>
                                    <p class="description"><?php _e('When an order reaches this status, it will be synced to the other store', 'advanced-woo-sync-lite'); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr class="swos-sending-options">
                            <th scope="row"><?php echo sprintf(__('Destination Status%s', 'advanced-woo-sync-lite'), $label_suffix); ?></th>
                            <td>
                                <select name="swos_settings[<?php echo $dest_key; ?>]">
                                    <?php foreach ($order_statuses as $status_key => $status_label) : ?>
                                        <option value="<?php echo esc_attr(str_replace('wc-', '', $status_key)); ?>" <?php selected(isset($settings[$dest_key]) ? $settings[$dest_key] : $default_dest, str_replace('wc-', '', $status_key)); ?>>
                                            <?php echo esc_html($status_label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($i===1): ?>
                                    <p class="description"><?php _e('Status the order will have when created in the destination store', 'advanced-woo-sync-lite'); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endfor; ?>


                        <!-- RECEIVING OPTIONS SECTION -->
                        <tr>
                            <th colspan="2">
                                <h3 class="swos-mt-40"><?php _e('Order Receiving Options', 'advanced-woo-sync-lite'); ?></h3>
                                <p class="description" style="font-style: italic;">
                                    <?php _e('Here you can modify how orders from the remote store will enter this store.', 'advanced-woo-sync-lite'); ?>
                                </p>
                            </th>
                        </tr>

                        <!-- Price Source -->
                        <tr class="swos-central-only">
                            <th scope="row"><?php _e('Use Price From:', 'advanced-woo-sync-lite'); ?></th>
                            <td>
                                <?php $price_source = isset($settings['price_source']) ? $settings['price_source'] : 'source'; ?>
                                <select name="swos_settings[price_source]" id="swos-price-source">
                                    <option value="source" <?php selected($price_source, 'source'); ?>><?php _e('Source Store (Price from the order)', 'advanced-woo-sync-lite'); ?></option>
                                    <option value="destination_regular" <?php selected($price_source, 'destination_regular'); ?>><?php _e('Destination Store - Regular Price', 'advanced-woo-sync-lite'); ?></option>
                                    <option value="destination_sale" <?php selected($price_source, 'destination_sale'); ?>><?php _e('Destination Store - Sale Price (if exists)', 'advanced-woo-sync-lite'); ?></option>
                                </select>
                                <p class="description">
                                    <strong><?php _e('Source Store:', 'advanced-woo-sync-lite'); ?></strong> <?php _e('Uses the exact price the customer paid in the other store.', 'advanced-woo-sync-lite'); ?><br>
                                    <strong><?php _e('Destination Store:', 'advanced-woo-sync-lite'); ?></strong> <?php _e('Ignores the original order price and uses the price configured in THIS store for the corresponding product.', 'advanced-woo-sync-lite'); ?>
                                </p>
                            </td>
                        </tr>

                        <!-- Price Adjustment -->
                        <tr class="swos-central-only">
                            <th scope="row"><?php _e('Modify Total Price', 'advanced-woo-sync-lite'); ?></th>
                            <td>
                                <?php $price_adj_enabled = isset($settings['price_adjustment_enabled']) ? $settings['price_adjustment_enabled'] : false; ?>
                                <label>
                                    <input type="checkbox" name="swos_settings[price_adjustment_enabled]" id="swos-price-adj-enabled" value="1" <?php checked($price_adj_enabled, true); ?>>
                                    <?php _e('Apply price adjustment to orders THIS STORE RECEIVES', 'advanced-woo-sync-lite'); ?>
                                </label>
                                <div id="swos-price-adj-options" class="swos-mt-15 <?php echo !$price_adj_enabled ? 'swos-hidden' : ''; ?>">
                                    <p>
                                        <?php $price_adj_type = isset($settings['price_adjustment_type']) ? $settings['price_adjustment_type'] : 'percentage'; ?>
                                        <select name="swos_settings[price_adjustment_type]" id="swos-price-adj-type">
                                            <option value="percentage" <?php selected($price_adj_type, 'percentage'); ?>><?php _e('Percentage (%)', 'advanced-woo-sync-lite'); ?></option>
                                            <option value="fixed" <?php selected($price_adj_type, 'fixed'); ?>><?php _e('Fixed Value', 'advanced-woo-sync-lite'); ?></option>
                                        </select>
                                        
                                        <?php $price_adj_value = isset($settings['price_adjustment_value']) ? $settings['price_adjustment_value'] : 0; ?>
                                        <input type="number" name="swos_settings[price_adjustment_value]" id="swos-price-adj-value" value="<?php echo esc_attr($price_adj_value); ?>" step="0.01" class="swos-w-150">
                                    </p>
                                    <p class="description"><?php _e('Allows applying a discount (negative) or increase (positive) to the total of incoming synced orders', 'advanced-woo-sync-lite'); ?></p>
                                </div>
                            </td>
                        </tr>

                        <!-- Coupons -->
                        <tr class="swos-central-only">
                            <th scope="row"><?php _e('Apply Coupon to Received Orders', 'advanced-woo-sync-lite'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="swos_settings[apply_coupon_to_received_orders]" value="1" <?php checked(isset($settings['apply_coupon_to_received_orders']) && $settings['apply_coupon_to_received_orders']); ?>>
                                    <?php _e('Automatically apply coupon', 'advanced-woo-sync-lite'); ?>
                                </label>
                                <div class="swos-mt-10">
                                    <?php
                                    $selected_received_coupon = isset($settings['received_orders_coupon_code']) ? $settings['received_orders_coupon_code'] : '';
                                    $coupons = get_posts(array(
                                        'post_type' => 'shop_coupon',
                                        'posts_per_page' => -1,
                                        'post_status' => 'publish',
                                        'orderby' => 'title',
                                        'order' => 'ASC'
                                    ));
                                    ?>
                                    <select name="swos_settings[received_orders_coupon_code]" class="swos-w-300">
                                        <option value=""><?php _e('-- Select coupon --', 'advanced-woo-sync-lite'); ?></option>
                                        <?php foreach ($coupons as $coupon):
                                            $coupon_obj = new \WC_Coupon($coupon->ID);
                                            echo '<option value="' . esc_attr($coupon_obj->get_code()) . '" ' . selected($selected_received_coupon, $coupon_obj->get_code(), false) . '>' . esc_html($coupon_obj->get_code()) . '</option>';
                                        endforeach; ?>
                                    </select>
                                </div>
                            </td>
                        </tr>

                        <!-- Sync Delay -->
                        <tr>
                            <th scope="row"><?php _e('Sync Delay:', 'advanced-woo-sync-lite'); ?></th>
                            <td>
                                <?php $sync_delay = isset($settings['sync_delay']) ? intval($settings['sync_delay']) : 0; ?>
                                <select name="swos_settings[sync_delay]" id="swos-sync-delay">
                                    <option value="0" <?php selected($sync_delay, 0); ?>><?php _e('No delay (immediate)', 'advanced-woo-sync-lite'); ?></option>
                                    <option value="3" <?php selected($sync_delay, 3); ?>><?php _e('3 seconds', 'advanced-woo-sync-lite'); ?></option>
                                    <option value="5" <?php selected($sync_delay, 5); ?>><?php _e('5 seconds', 'advanced-woo-sync-lite'); ?></option>
                                    <option value="10" <?php selected($sync_delay, 10); ?>><?php _e('10 seconds', 'advanced-woo-sync-lite'); ?></option>
                                </select>
                                <p class="description">
                                    <?php _e('Wait time before syncing the order. Useful for plugins that insert orders via API which may experience delays during order creation.', 'advanced-woo-sync-lite'); ?>
                                </p>
                            </td>
                        </tr>

                        <!-- Customer Settings (Integrated) -->
                        <tr class="swos-central-only">
                            <th scope="row"><?php _e('Customer Handling', 'advanced-woo-sync-lite'); ?></th>
                            <td>
                                <select name="swos_settings[customer_handling]" id="swos-customer-handling">
                                    <option value="create" <?php selected($customer_handling, 'create'); ?>><?php _e('Create customer automatically', 'advanced-woo-sync-lite'); ?></option>
                                    <option value="fixed_id" <?php selected($customer_handling, 'fixed_id'); ?>><?php _e('Assign to a fixed customer ID', 'advanced-woo-sync-lite'); ?></option>
                                </select>
                                <p class="description"><?php _e('Defines how to handle customers for orders THIS STORE RECEIVES from the other store', 'advanced-woo-sync-lite'); ?></p>

                                <div id="swos-fixed-id-wrapper" class="swos-mt-10 <?php echo $customer_handling === 'create' ? 'swos-hidden' : ''; ?>">
                                    <label>
                                        <?php _e('User ID in THIS store:', 'advanced-woo-sync-lite'); ?>
                                        <input type="number" name="swos_settings[customer_fixed_id]" id="swos-customer-fixed-id" value="<?php echo esc_attr(isset($settings['customer_fixed_id']) ? $settings['customer_fixed_id'] : ''); ?>" min="1" class="swos-w-100" <?php echo $customer_handling === 'create' ? 'disabled' : ''; ?>>
                                    </label>
                                    <p class="description"><strong><?php _e('IMPORTANT:', 'advanced-woo-sync-lite'); ?></strong> <?php _e('This is the user ID IN THIS STORE (destination store) to which received orders will be assigned. Check the user ID in Users → All Users.', 'advanced-woo-sync-lite'); ?></p>
                                </div>
                            </td>
                        </tr>

                        <!-- Shipping Settings (Integrated) -->
                        <tr class="swos-central-only">
                            <th scope="row"><?php _e('Include Shipping Costs', 'advanced-woo-sync-lite'); ?></th>
                            <td>
                                <?php $include_shipping = isset($settings['include_shipping']) ? $settings['include_shipping'] : true; ?>
                                <label>
                                    <input type="checkbox" name="swos_settings[include_shipping]" value="1" <?php checked($include_shipping, true); ?>>
                                    <?php _e('Include shipping costs in orders THIS STORE RECEIVES', 'advanced-woo-sync-lite'); ?>
                                </label>
                                <p class="description">
                                    <?php _e('If unchecked, received orders will NOT include shipping lines. Useful when the receiving store handles its own shipping.', 'advanced-woo-sync-lite'); ?>
                                </p>
                            </td>
                        </tr>
                        <?php echo \SWOS\Premium::lock_fields(ob_get_clean()); ?>
                    </tbody>

                    <!-- TAB: Stock Synchronization -->
                    <tbody id="swos-tab-stock" class="swos-tab-section swos-group-stock <?php echo ($current_tab === 'stock') ? 'swos-tab-active' : ''; ?>">
                        <tr>
                            <th colspan="2">
                                <h2><?php _e('Stock Synchronization', 'advanced-woo-sync-lite'); ?></h2>
                                
                                <?php if (!empty($stock_warnings)) : ?>
                                    <?php foreach ($stock_warnings as $warning) : ?>
                                        <div class="notice notice-<?php echo esc_attr($warning['type']); ?> inline" style="margin: 10px 0;">
                                            <p><?php echo esc_html($warning['message']); ?></p>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                
                                <?php if ($is_stock_managed_by_remote) : ?>
                                    <div class="notice notice-warning inline" style="margin: 10px 0;">
                                        <p>
                                            <strong><?php _e('Stock is managed by the remote store.', 'advanced-woo-sync-lite'); ?></strong><br>
                                            <?php echo sprintf(__('The connected store (%s) has Stock Sync enabled. Stock Sync is disabled for this store to prevent sync loops.', 'advanced-woo-sync-lite'), esc_html($settings['remote_store_url'] ?? '')); ?>
                                        </p>
                                    </div>
                                <?php endif; ?>
                            </th>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Enable Stock Synchronization', 'advanced-woo-sync-lite'); ?></th>
                            <td>
                                <?php $stock_sync_enabled = isset($settings['stock_sync_enabled']) ? $settings['stock_sync_enabled'] : false; ?>
                                <label>
                                    <input type="checkbox" name="swos_settings[stock_sync_enabled]" id="swos-stock-sync-enabled" value="1" 
                                        <?php checked($stock_sync_enabled, true); ?> 
                                        <?php echo !$can_enable_stock_sync ? 'disabled' : ''; ?>>
                                    <?php _e('Sync product stock between stores in real time', 'advanced-woo-sync-lite'); ?>
                                </label>
                                <?php if (!$can_enable_stock_sync) : ?>
                                    <input type="hidden" name="swos_settings[stock_sync_enabled]" value="0">
                                    <p class="description" style="color: #d63638;">
                                        <?php _e('Stock Sync is disabled because the remote store already has Stock Sync enabled.', 'advanced-woo-sync-lite'); ?>
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>


                        <!-- Stock Sync Options Rows (Visible only if enabled) -->
                        <tr class="swos-stock-option-row <?php echo !$stock_sync_enabled ? 'swos-hidden' : ''; ?>">
                                <th scope="row"><?php _e('Sync by:', 'advanced-woo-sync-lite'); ?></th>
                                <td>
                                    <input type="hidden" id="swos-stock-sync-type" value="category">
                                    <select disabled>
                                        <option selected><?php _e('Product category', 'advanced-woo-sync-lite'); ?></option>
                                    </select>
                                    <p class="description">
                                        <?php _e('Specific SKUs, product tag and whole-catalog sync are available in Premium.', 'advanced-woo-sync-lite'); ?>
                                        <a href="<?php echo esc_url(\SWOS\Premium::url('stock_sync_type')); ?>" target="_blank" rel="noopener"><?php _e('Buy Premium', 'advanced-woo-sync-lite'); ?></a>
                                    </p>
                                </td>
                            </tr>
                            <tr id="swos-stock-sync-value-row" class="swos-stock-option-row <?php echo !$stock_sync_enabled ? 'swos-hidden' : ''; ?>">
                                <th scope="row"><?php _e('Category:', 'advanced-woo-sync-lite'); ?></th>
                                <td>
                                    <?php
                                    $stock_sync_type = isset($settings['stock_sync_type']) ? $settings['stock_sync_type'] : '';
                                    $stock_sync_value = ($stock_sync_type === 'category' && isset($settings['stock_sync_value'])) ? $settings['stock_sync_value'] : '';
                                    ?>
                                    <div id="swos-stock-sync-category-input">
                                        <select name="swos_settings[stock_sync_value_category]">
                                            <option value=""><?php _e('-- Select category --', 'advanced-woo-sync-lite'); ?></option>
                                            <?php
                                            $categories = get_terms(array(
                                                'taxonomy' => 'product_cat',
                                                'hide_empty' => false,
                                            ));
                                            foreach ($categories as $category) {
                                                $selected = ($stock_sync_value === $category->slug) ? 'selected' : '';
                                                echo '<option value="' . esc_attr($category->slug) . '" ' . $selected . '>' . esc_html($category->name) . '</option>';
                                            }
                                            ?>
                                        </select>
                                        <p class="description"><?php _e('Products of this category (and their variations) are synced by SKU. Changing the category frees all product slots.', 'advanced-woo-sync-lite'); ?></p>
                                    </div>
                                </td>
                            </tr>
                            <tr class="swos-stock-option-row <?php echo !$stock_sync_enabled ? 'swos-hidden' : ''; ?>">
                                <th scope="row"><?php _e('Synced products:', 'advanced-woo-sync-lite'); ?></th>
                                <td>
                                    <?php
                                    $used_slots = count(\SWOS\Stock_Sync::get_lite_slots());
                                    $limit_reached = $used_slots >= SWOS_LITE_MAX_PRODUCTS;
                                    ?>
                                    <strong style="<?php echo $limit_reached ? 'color:#d63638' : ''; ?>"><?php printf(__('%1$d of %2$d', 'advanced-woo-sync-lite'), $used_slots, SWOS_LITE_MAX_PRODUCTS); ?></strong>
                                    <p class="description">
                                        <?php printf(__('Lite keeps up to %d products in sync. A product takes a slot the first time its stock changes; a variable product and its variations use one slot.', 'advanced-woo-sync-lite'), SWOS_LITE_MAX_PRODUCTS); ?>
                                    </p>
                                    <?php if (isset($_GET['swos_slots_reset'])) : ?>
                                        <p style="color:#00a32a"><?php _e('Product slots freed.', 'advanced-woo-sync-lite'); ?></p>
                                    <?php endif; ?>
                                    <p>
                                        <?php if ($used_slots > 0) : ?>
                                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=swos_lite_reset_slots'), 'swos_lite_reset_slots')); ?>" class="button"><?php _e('Free product slots', 'advanced-woo-sync-lite'); ?></a>
                                        <?php endif; ?>
                                        <a href="<?php echo esc_url(\SWOS\Premium::url('stock_limit')); ?>" class="button button-primary" target="_blank" rel="noopener"><?php _e('Buy Premium (unlimited products)', 'advanced-woo-sync-lite'); ?></a>
                                    </p>
                                </td>
                            </tr>
                    </tbody>

                </table>

                <?php if ($current_tab !== 'orders') : ?>
                    <?php submit_button(__('Save Settings', 'advanced-woo-sync-lite')); ?>
                <?php endif; ?>
            </form>

            <?php \SWOS\Admin::render_version_footer(); ?>
            </div><!-- /swos-admin-wrapper -->
        </div>
        <?php
    }
}
