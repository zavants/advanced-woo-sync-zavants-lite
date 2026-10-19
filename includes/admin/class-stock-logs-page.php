<?php
/**
 * Stock Logs Page
 *
 * @package SWOS
 */

namespace SWOS\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class Stock_Logs_Page {

    public function __construct() {
        // This class is instantiated in Admin class
    }

    public function render() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        if (!class_exists('SWOS\Log_List_Table')) {
            require_once SWOS_PLUGIN_DIR . 'includes/class-log-list-table.php';
        }

        $list_table = new \SWOS\Log_List_Table('stock');
        $list_table->prepare_items();
        ?>
        <div class="wrap woocommerce">
            <div class="swos-header">
                <h1 class="wp-heading-inline"><?php esc_html_e('Stock Logs - Synchronization', 'advanced-woo-sync-lite'); ?></h1>
                <hr class="wp-header-end">
            </div>

            <div class="swos-admin-wrapper">
            
            <style>
                /* Force widths on both header and body cells */
                th#level, td.column-level { width: 150px !important; }
                th#timestamp, td.column-timestamp { width: 180px !important; }
                th#object_id, td.column-object_id { width: 100px !important; }
                
                /* Ensure message column takes remaining space */
                th#message, td.column-message { 
                    width: auto !important; 
                }

                /* Ensure table layout is fixed so widths are respected */
                table.stats_table { table-layout: fixed; }
            </style>
            
            <form id="logs-filter" method="get">
                <input type="hidden" name="page" value="woo-sync-adv-stock-logs" />
                <?php $list_table->search_box(__('Search Logs', 'advanced-woo-sync-lite'), 'log'); ?>
                <?php $list_table->display(); ?>
            </form>
            
            <div class="clear"></div>
            
            <hr>
            
            <h2><?php esc_html_e('Maintenance', 'advanced-woo-sync-lite'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
                <input type="hidden" name="action" value="swos_clear_logs">
                <input type="hidden" name="log_type" value="stock">
                <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('swos-clear-logs')); ?>">
                <p>
                    <button type="button" class="button button-secondary swos-clear-logs-btn" data-type="stock">
                        <?php esc_html_e('Delete ALL stock logs', 'advanced-woo-sync-lite'); ?>
                    </button>
                    <span class="description"><?php esc_html_e('This action cannot be undone.', 'advanced-woo-sync-lite'); ?></span>
                </p>
            </form>
            <?php \SWOS\Admin::render_version_footer(); ?>
            </div>
        </div>
        <?php
    }
}
