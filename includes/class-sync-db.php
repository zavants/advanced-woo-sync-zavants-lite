<?php
/**
 * Database Management for Sync State
 *
 * @package SWOS
 */

namespace SWOS;

if (!defined('ABSPATH')) {
    exit;
}

class Sync_DB {

    const TABLE_NAME = 'swos_sync_state';
    const DB_VERSION = '1.0';

    /**
     * Create/update the sync state table
     */
    public static function create_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            sku varchar(100) NOT NULL,
            last_stock int(11) DEFAULT NULL,
            last_synced_at datetime DEFAULT NULL,
            sync_source varchar(50) DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY product_id (product_id),
            UNIQUE KEY sku (sku),
            KEY last_synced_at (last_synced_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        update_option('swos_db_version', self::DB_VERSION);
    }

    /**
     * Get sync state for a product
     *
     * @param int $product_id Product ID
     * @return object|null Database row or null
     */
    public static function get_sync_state($product_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE product_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
            $product_id
        ));
    }

    /**
     * Get sync state by SKU
     *
     * @param string $sku Product SKU
     * @return object|null Database row or null
     */
    public static function get_sync_state_by_sku($sku) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE sku = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
            $sku
        ));
    }

    /**
     * Update sync state for a product
     *
     * @param int $product_id Product ID
     * @param string $sku Product SKU
     * @param int $stock Stock quantity
     * @param string $source Sync source (local, remote, api)
     * @return bool Success
     */
    public static function update_sync_state($product_id, $sku, $stock, $source = 'local') {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $existing = self::get_sync_state($product_id);

        // SKU is unique in this table: if the SKU moved to another product, drop the stale row
        // so the insert/update below doesn't fail and leave this product without state.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_name WHERE sku = %s AND product_id <> %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
            $sku,
            $product_id
        ));

        if ($existing) {
            // Update existing record
            return $wpdb->update(
                $table_name,
                array(
                    'last_stock' => $stock,
                    'last_synced_at' => current_time('mysql', true), // GMT
                    'sync_source' => $source,
                    'sku' => $sku, // Update SKU in case it changed
                ),
                array('product_id' => $product_id),
                array('%d', '%s', '%s', '%s'),
                array('%d')
            ) !== false;
        } else {
            // Insert new record
            return $wpdb->insert(
                $table_name,
                array(
                    'product_id' => $product_id,
                    'sku' => $sku,
                    'last_stock' => $stock,
                    'last_synced_at' => current_time('mysql', true), // GMT
                    'sync_source' => $source,
                ),
                array('%d', '%s', '%d', '%s', '%s')
            ) !== false;
        }
    }

    /**
     * Check if product should sync based on last sync
     *
     * Only skips when the remote already holds this exact stock. Bursts of changes are debounced
     * by delaying the scheduled action (see Stock_Sync), never by dropping a change.
     *
     * @param int $product_id Product ID
     * @param int|float|null $new_stock New stock quantity
     * @return bool True if should sync
     */
    public static function should_sync($product_id, $new_stock) {
        $state = self::get_sync_state($product_id);

        if (!$state || $state->last_stock === null) {
            // No previous sync, should sync
            return true;
        }

        return (int) $state->last_stock !== (int) $new_stock;
    }

    /**
     * Clean up old records (optional maintenance)
     *
     * @param int $days Delete records older than X days
     * @return int Number of deleted rows
     */
    public static function cleanup_old_records($days = 30) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        return $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_name WHERE last_synced_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
            gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS))
        ));
    }
}
