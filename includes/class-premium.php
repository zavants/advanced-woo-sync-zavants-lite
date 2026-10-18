<?php
/**
 * Premium upsell: locked previews of the features only available in Premium
 *
 * @package SWOS
 */

namespace SWOS;

if (!defined('ABSPATH')) {
    exit;
}

class Premium {

    /**
     * Premium page URL with campaign parameters
     *
     * @param string $source Where the link is shown
     * @return string
     */
    public static function url($source = 'lite') {
        $url = apply_filters('swos_premium_url', SWOS_PREMIUM_URL);
        return add_query_arg(array(
            'utm_source' => 'awsz-lite',
            'utm_medium' => 'plugin',
            'utm_campaign' => sanitize_key($source),
        ), $url);
    }

    /**
     * Premium-only admin pages shown in the Lite menu as locked previews
     *
     * @return array slug => title, description, image
     */
    public static function get_locked_pages() {
        return array(
            'woo-sync-adv-sync-stock' => array(
                'title' => __('Mass Sync', 'advanced-woo-sync-lite'),
                'description' => __('Push the stock of your whole catalog to the other store in one click, with real-time progress and a report of every skipped product.', 'advanced-woo-sync-lite'),
                'image' => 'img_12_mass_sync.png',
            ),
            'woo-sync-adv-compare-stocks' => array(
                'title' => __('Compare Stocks', 'advanced-woo-sync-lite'),
                'description' => __('Compare thousands of products between both stores, find stock differences and fix them with one click.', 'advanced-woo-sync-lite'),
                'image' => 'img_10_stock_comparison.png',
            ),
        );
    }

    /**
     * Features list shown on every locked screen
     *
     * @return string[]
     */
    public static function get_features() {
        return array(
            __('Unlimited products (Lite: up to 50)', 'advanced-woo-sync-lite'),
            __('Sync by SKU, tag, category or the whole catalog', 'advanced-woo-sync-lite'),
            __('Bidirectional order sync with up to 4 status triggers', 'advanced-woo-sync-lite'),
            __('Price adjustments, coupons and customer mapping for received orders', 'advanced-woo-sync-lite'),
            __('Mass stock sync and stock comparison between stores', 'advanced-woo-sync-lite'),
            __('Order sync status column, order logs and diagnostics', 'advanced-woo-sync-lite'),
            __('Priority support and automatic updates', 'advanced-woo-sync-lite'),
        );
    }

    /**
     * "Buy Premium" call to action box
     *
     * @param string $title
     * @param string $description
     * @param string $source
     */
    public static function render_cta($title, $description, $source) {
        ?>
        <div class="swos-premium-cta">
            <span class="swos-pro-badge">PRO</span>
            <h2><?php echo esc_html($title); ?></h2>
            <p><?php echo esc_html($description); ?></p>
            <ul>
                <?php foreach (self::get_features() as $feature) : ?>
                    <li>✔ <?php echo esc_html($feature); ?></li>
                <?php endforeach; ?>
            </ul>
            <a href="<?php echo esc_url(self::url($source)); ?>" class="button button-primary button-hero" target="_blank" rel="noopener">
                <?php _e('Buy Premium', 'advanced-woo-sync-lite'); ?>
            </a>
        </div>
        <?php
    }

    /**
     * Full-page locked preview (screenshot behind the call to action)
     *
     * @param string $slug Admin page slug
     */
    public static function render_locked_page($slug) {
        $pages = self::get_locked_pages();
        if (isset($pages[$slug])) {
            $page = $pages[$slug];
        } else {
            $page = array(
                'title' => __('Advanced Woo Sync Premium', 'advanced-woo-sync-lite'),
                'description' => __('Everything in Lite, without limits, plus order synchronization and advanced stock tools.', 'advanced-woo-sync-lite'),
                'image' => 'img_07_order_sync_settings.png',
            );
        }
        ?>
        <div class="wrap woocommerce">
            <div class="swos-header">
                <h1 class="wp-heading-inline"><?php echo esc_html($page['title']); ?> <span class="swos-pro-badge">PRO</span></h1>
                <hr class="wp-header-end">
            </div>
            <div class="swos-admin-wrapper">
                <div class="swos-locked">
                    <img class="swos-locked-preview" src="<?php echo esc_url(SWOS_PLUGIN_URL . 'assets/images/' . $page['image']); ?>" alt="" aria-hidden="true">
                    <?php self::render_cta($page['title'], $page['description'], $slug); ?>
                </div>
                <?php Admin::render_version_footer(); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Turns rendered settings markup into a read-only mockup: fields lose their name
     * (nothing is submitted) and are disabled.
     *
     * @param string $html
     * @return string
     */
    public static function lock_fields($html) {
        $html = preg_replace('/\sname="[^"]*"/', '', $html);
        $html = preg_replace('/<(input|select|textarea|button)\b(?![^>]*\bdisabled\b)/i', '<$1 disabled', $html);
        $html = preg_replace('/\sid="(swos-[^"]*)"/', ' id="$1-locked"', $html);
        return $html;
    }
}
