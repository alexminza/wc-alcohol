<?php

/**
 * Plugin Name: Products Sale Restrictions for WooCommerce
 * Description: Products sale limitations during restriction hours
 * Plugin URI: https://wordpress.org/plugins/wc-alcohol/
 * Version: 1.2.0
 * Author: Alexander Minza
 * Author URI: https://profiles.wordpress.org/alexminza
 * Developer: Alexander Minza
 * Developer URI: https://profiles.wordpress.org/alexminza
 * Text Domain: wc-alcohol
 * Domain Path: /languages
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires PHP: 7.2.5
 * Requires at least: 4.8
 * Tested up to: 6.9
 * WC requires at least: 3.3
 * WC tested up to: 10.5.0
 * Requires Plugins: woocommerce
 *
 * @package wc-alcohol
 */

// Looking to contribute code to this plugin? Go ahead and fork the repository over at GitHub https://github.com/alexminza/wc-alcohol

declare(strict_types=1);

namespace AlexMinza\WC_Alcohol;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

add_action('plugins_loaded', __NAMESPACE__ . '\alcohol_plugins_loaded_init');

function alcohol_plugins_loaded_init()
{
    if (!class_exists('WC_Settings_API')) {
        return;
    }

    require_once plugin_dir_path(__FILE__) . 'includes/class-wc-alcohol.php';

    WC_Alcohol::get_instance();

    if (is_admin()) {
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array(WC_Alcohol::class, 'plugin_action_links'));
    }
}

//region Declare WooCommerce compatibility
add_action(
    'before_woocommerce_init',
    function () {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            // WooCommerce HPOS compatibility
            // https://developer.woocommerce.com/docs/features/high-performance-order-storage/recipe-book/#declaring-extension-incompatibility
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);

            // WooCommerce Cart Checkout Blocks compatibility
            // https://github.com/woocommerce/woocommerce/pull/36426
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);

            // WooCommerce Product Object Caching compatibility
            // https://developer.woocommerce.com/2026/01/19/experimental-product-object-caching-in-woocommerce-10-5/
            // https://github.com/woocommerce/woocommerce/pull/62041
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('product_instance_caching', __FILE__, true);
        }
    }
);
//endregion
