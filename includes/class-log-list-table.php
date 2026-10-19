<?php
/**
 * Log List Table
 *
 * @package SWOS
 */

namespace SWOS;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class Log_List_Table extends \WP_List_Table {

    private $log_type;

    public function __construct($log_type = 'order') {
        parent::__construct(array(
            'singular' => 'log',
            'plural'   => 'logs',
            'ajax'     => false
        ));
        $this->log_type = $log_type;
    }

    public function no_items() {
        esc_html_e('No items found.', 'advanced-woo-sync-lite');
    }

    public function get_columns() {
        return array(
            'timestamp' => __('Date', 'advanced-woo-sync-lite'),
            'level'     => __('Level', 'advanced-woo-sync-lite'),
            'object_id' => __('Object ID', 'advanced-woo-sync-lite'),
            'message'   => __('Message', 'advanced-woo-sync-lite')
        );
    }

    public function get_sortable_columns() {
        return array(); // Logging files are naturally sorted by time usually
    }

    public function prepare_items() {
        $per_page = 50;
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- list table filters, read-only.
        $search = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '';
        $level_filter = isset($_REQUEST['level_filter']) ? sanitize_text_field(wp_unslash($_REQUEST['level_filter'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        
        // Define columns explicitely to avoid rendering issues
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = array($columns, $hidden, $sortable);

        // Fetch logs using our new enhanced Logger method
        $data = Logger::read_log_entries($this->log_type, $per_page, $offset, $level_filter, $search);

        $this->items = $data['entries'];
        
        $this->set_pagination_args(array(
            'total_items' => $data['total'],
            'per_page'    => $per_page,
            'total_pages' => ceil($data['total'] / $per_page)
        ));
    }

    public function column_default($item, $column_name) {
        return isset($item[$column_name]) ? esc_html((string) $item[$column_name]) : '';
    }

    public function column_timestamp($item) {
        return esc_html($item['timestamp']);
    }

    public function column_level($item) {
        $level = $item['level'];
        $color = '#777';
        
        switch ($level) {
            case 'error': $color = '#dc3232'; break;
            case 'warning': $color = '#ffb900'; break;
            case 'success': $color = '#46b450'; break;
            case 'info': $color = '#0073aa'; break;
            case 'debug': $color = '#999'; break;
        }

        return sprintf(
            '<span style="color: %s; font-weight: bold; text-transform: uppercase;">%s</span>',
            esc_attr($color),
            esc_html($level)
        );
    }

    public function column_object_id($item) {
        if (!empty($item['order_id'])) {
            return sprintf(
                '<a href="%s" target="_blank">#%d</a>',
                get_edit_post_link($item['order_id']),
                $item['order_id']
            );
        }
        return '-';
    }

    public function column_message($item) {
        return esc_html($item['message']);
    }
    
    public function extra_tablenav($which) {
        if ($which == 'top') {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list table filter, read-only.
            $current_level = isset($_REQUEST['level_filter']) ? sanitize_text_field(wp_unslash($_REQUEST['level_filter'])) : '';
            ?>
            <div class="alignleft actions">
                <select name="level_filter">
                    <option value=""><?php esc_html_e('All levels', 'advanced-woo-sync-lite'); ?></option>
                    <option value="error" <?php selected($current_level, 'error'); ?>>Error</option>
                    <option value="warning" <?php selected($current_level, 'warning'); ?>>Warning</option>
                    <option value="info" <?php selected($current_level, 'info'); ?>>Info</option>
                    <option value="success" <?php selected($current_level, 'success'); ?>>Success</option>
                    <option value="debug" <?php selected($current_level, 'debug'); ?>>Debug</option>
                </select>
                <?php submit_button(__('Filter', 'advanced-woo-sync-lite'), '', 'filter_action', false); ?>
            </div>
            <?php
        }
    }
}
