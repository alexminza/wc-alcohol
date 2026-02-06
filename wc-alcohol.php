<?php

/**
 * Plugin Name: Alcohol Sale Restrictions for WooCommerce
 * Description: Alcohol sale limitations during restriction hours
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
 * Tested up to: 6.9.1
 * WC requires at least: 3.3
 * WC tested up to: 10.5.0
 * Requires Plugins: woocommerce
 *
 * @package wc-alcohol
 */

// Looking to contribute code to this plugin? Go ahead and fork the repository over at GitHub https://github.com/alexminza/wc-alcohol

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

if (!class_exists(WC_Alcohol::class)):
    class WC_Alcohol
    {
        //region Constants
        const MOD_ID = 'wc-alcohol';

        const MOD_SETTINGS_SECTION           = self::MOD_ID;
        const MOD_SETTINGS_PREFIX            = self::MOD_SETTINGS_SECTION . '_';
        const MOD_SETTINGS_ENABLED           = self::MOD_SETTINGS_PREFIX . 'enabled';
        const MOD_SETTINGS_RESTRICTION_START = self::MOD_SETTINGS_PREFIX . 'restriction_start';
        const MOD_SETTINGS_RESTRICTION_END   = self::MOD_SETTINGS_PREFIX . 'restriction_end';
        const MOD_SETTINGS_CATEGORY          = self::MOD_SETTINGS_PREFIX . 'category';
        const MOD_SETTINGS_WARNING           = self::MOD_SETTINGS_PREFIX . 'warning';
        const MOD_SETTINGS_WARN_PRODUCT      = self::MOD_SETTINGS_PREFIX . 'warn_product';
        const MOD_SETTINGS_WARN_CATEGORY     = self::MOD_SETTINGS_PREFIX . 'warn_category';

        const RESTRICTION_START    = '22:00';
        const RESTRICTION_END      = '09:00';
        const RESTRICTION_CATEGORY = '';
        //endregion

        /**
         * Instance of this class.
         *
         * @var object
         */
        protected static $instance = null;

        protected $enabled, $mod_title, $categories_list, $restriction_start, $restriction_end, $restriction_start_value, $restriction_end_value;
        protected $restricted_categories, $warning_template, $warn_product, $warn_category;

        private function __construct()
        {
            $this->enabled               = wc_string_to_bool(get_option(self::MOD_SETTINGS_ENABLED, 'no'));
            $this->restriction_start     = get_option(self::MOD_SETTINGS_RESTRICTION_START, self::RESTRICTION_START);
            $this->restriction_end       = get_option(self::MOD_SETTINGS_RESTRICTION_END, self::RESTRICTION_END);
            $this->restricted_categories = get_option(self::MOD_SETTINGS_CATEGORY, self::RESTRICTION_CATEGORY);
            $this->warning_template      = get_option(self::MOD_SETTINGS_WARNING);
            $this->warn_product          = wc_string_to_bool(get_option(self::MOD_SETTINGS_WARN_PRODUCT, 'yes'));
            $this->warn_category         = wc_string_to_bool(get_option(self::MOD_SETTINGS_WARN_CATEGORY, 'yes'));

            add_action('init', array($this, 'init'));
        }

        /**
         * Return an instance of this class.
         *
         * @return object A single instance of this class.
         */
        public static function get_instance()
        {
            // If the single instance hasn't been set, set it now.
            if (null === self::$instance) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        public function init()
        {
            load_plugin_textdomain('wc-alcohol', false, dirname(plugin_basename(__FILE__)) . '/languages');

            $this->mod_title = esc_html__('Alcohol sale restrictions', 'wc-alcohol');

            //region Init categories
            $categories = $this->get_product_categories();
            $this->categories_list = wp_list_pluck($categories, 'name', 'slug');
            //endregion

            //region Parse restriction times strings
            $restriction_start_string = str_replace(':', '', $this->restriction_start);
            if (is_numeric($restriction_start_string)) {
                $this->restriction_start_value = intval($restriction_start_string);
            }

            $restriction_end_string = str_replace(':', '', $this->restriction_end);
            if (is_numeric($restriction_end_string)) {
                $this->restriction_end_value = intval($restriction_end_string);
            }
            //endregion

            if (!$this->validate_settings()) {
                $this->enabled = false;
            }

            //region Add WooCommerce hooks
            if (is_admin()) {
                add_filter('woocommerce_get_sections_products', array($this, 'get_sections_products'));
                add_filter('woocommerce_get_settings_products', array($this, 'get_settings_products'), 10, 2);
                add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'plugin_links'));
            }

            if ($this->enabled) {
                add_filter('woocommerce_is_purchasable', array($this, 'is_purchasable'), 10, 2);

                if ($this->warn_product) {
                    add_action('woocommerce_single_product_summary', array($this, 'single_product_summary'), 20);
                }

                if ($this->warn_category) {
                    add_action('woocommerce_archive_description', array($this, 'archive_description'), 10);
                }
            }
            //endregion
        }

        protected function validate_settings()
        {
            if (empty($this->restricted_categories)) {
                //missing restriction categories definition
                return false;
            }

            if (empty($this->restriction_start) || empty($this->restriction_end)) {
                //missing restriction hours
                return false;
            }

            if (!isset($this->restriction_start_value, $this->restriction_end_value) || $this->restriction_start_value === $this->restriction_end_value) {
                //incorrect restriction hours
                return false;
            }

            return true;
        }

        //region Plugin settings
        public function plugin_links($links)
        {
            $settings_url = add_query_arg(
                array(
                    'page'    => 'wc-settings',
                    'tab'     => 'products',
                    'section' => self::MOD_SETTINGS_SECTION,
                ),
                admin_url('admin.php')
            );

            $plugin_links = array(
                sprintf('<a href="%1$s">%2$s</a>', esc_url($settings_url), esc_html__('Settings', 'wc-alcohol')),
            );

            return array_merge($plugin_links, $links);
        }

        public function get_sections_products($sections)
        {
            //https://docs.woocommerce.com/document/adding-a-section-to-a-settings-tab/
            $sections[self::MOD_SETTINGS_SECTION] = $this->mod_title;
            return $sections;
        }

        public function get_settings_products($settings, $current_section)
        {
            //https://github.com/woocommerce/woocommerce/blob/master/includes/admin/settings/class-wc-settings-products.php
            if (self::MOD_SETTINGS_SECTION === $current_section) {
                $settings_mod = array();

                $settings_mod[] = array(
                    'id'   => self::MOD_SETTINGS_SECTION,
                    'name' => $this->mod_title,
                    'type' => 'title',
                    'desc' => __('Alcohol sale limitations during restriction hours', 'wc-alcohol'),
                );

                $settings_mod[] = array(
                    'id'       => self::MOD_SETTINGS_ENABLED,
                    'type'     => 'checkbox',
                    'name'     => __('Enable restrictions', 'wc-alcohol'),
                    'desc'     => __('Enable sale limitations during restriction hours', 'wc-alcohol'),
                    'default'  => 'no',
                );

                $settings_mod[] = array(
                    'id'       => self::MOD_SETTINGS_RESTRICTION_START,
                    'name'     => __('Restriction time start', 'wc-alcohol'),
                    'desc'     => __('Example: 22:00', 'wc-alcohol'),
                    'type'     => 'text',
                    'default'  => self::RESTRICTION_START,
                );

                $settings_mod[] = array(
                    'id'       => self::MOD_SETTINGS_RESTRICTION_END,
                    'name'     => __('Restriction time end', 'wc-alcohol'),
                    'desc'     => __('Example: 09:00', 'wc-alcohol'),
                    'type'     => 'text',
                    'default'  => self::RESTRICTION_END,
                );

                $settings_mod[] = array(
                    'id'       => self::MOD_SETTINGS_CATEGORY,
                    'name'     => __('Restricted categories', 'wc-alcohol'),
                    'type'     => 'multiselect',
                    'class'    => 'wc-enhanced-select',
                    'options'  => $this->categories_list,
                    'default'  => self::RESTRICTION_CATEGORY,
                    'custom_attributes' => array(
                        'data-placeholder' => esc_html__('Select restricted categories', 'wc-alcohol'),
                    ),
                );

                $settings_mod[] = array(
                    'id'       => self::MOD_SETTINGS_WARNING,
                    'type'     => 'textarea',
                    'name'     => __('Warning message', 'wc-alcohol'),
                    'desc_tip' => __('Warning message displayed to the customers when trying to purchase products from the selected categories during restriction hours.', 'wc-alcohol'),
                    'desc'     => __('Format: <code>%1$s</code> - Category, <code>%2$s</code> - Restriction time start, <code>%3$s</code> - Restriction time end', 'wc-alcohol'),
                    'default'  => esc_html__('The sale of products in the "%1$s" category is prohibited from %2$s to %3$s.', 'wc-alcohol'),
                );

                $settings_mod[] = array(
                    'id'       => self::MOD_SETTINGS_WARN_PRODUCT,
                    'type'     => 'checkbox',
                    'title'    => __('Show warning on', 'wc-alcohol'),
                    'desc'     => __('Product pages', 'wc-alcohol'),
                    'default'  => 'yes',
                    'checkboxgroup' => 'start',
                );

                $settings_mod[] = array(
                    'id'       => self::MOD_SETTINGS_WARN_CATEGORY,
                    'type'     => 'checkbox',
                    'desc'     => __('Category pages', 'wc-alcohol'),
                    'default'  => 'yes',
                    'checkboxgroup' => 'end',
                );

                $settings_mod[] = array(
                    'type' => 'sectionend',
                    'id'   => self::MOD_SETTINGS_SECTION,
                );

                return $settings_mod;
            } else {
                return $settings;
            }
        }

        protected function get_product_categories()
        {
            $args = array(
                'type'         => 'product',
                'taxonomy'     => 'product_cat',
                'hierarchical' => true,
                'hide_empty'   => 0,
            );

            //https://developer.wordpress.org/reference/functions/get_categories/
            $categories = get_categories($args);

            if (empty($categories) || is_wp_error($categories)) {
                return array();
            }

            return $categories;
        }
        //endregion

        protected function validate_product($product_id, $notify = true)
        {
            try {
                if ($this->validate()) {
                    return true;
                }

                $restricted_category = $this->get_product_restricted_category($product_id);

                if (!empty($restricted_category)) {
                    if ($notify) {
                        $warning_message = $this->get_warning_message($restricted_category);
                        if (!empty($warning_message)) {
                            wc_add_notice($warning_message, 'error');
                        }
                    }

                    return false;
                }
            } catch (Exception $ex) {
                $this->log($ex, WC_Log_Levels::ERROR);
            }

            return true;
        }

        protected function get_product_restricted_category($product_id)
        {
            //https://developer.wordpress.org/reference/functions/get_the_terms/
            $categories = get_the_terms($product_id, 'product_cat');

            if (empty($categories) || is_wp_error($categories)) {
                return null;
            }

            foreach ($categories as $category) {
                if ($this->is_restricted_category($category->slug)) {
                    return $category->slug; //return first found restricted product category
                }
            }

            return null;
        }

        protected function validate_category($category)
        {
            try {
                if ($this->validate()) {
                    return true;
                }

                if ($this->is_restricted_category($category->slug)) {
                    return false;
                }
            } catch (Exception $ex) {
                $this->log($ex, WC_Log_Levels::ERROR);
            }

            return true;
        }

        protected function validate()
        {
            if (!$this->enabled) {
                return true;
            }

            $current_hour = intval(current_time('Hi'));
            if ($this->restriction_start_value > $this->restriction_end_value) {
                // Overnight restriction
                if ($current_hour < $this->restriction_start_value && $current_hour >= $this->restriction_end_value) {
                    return true;
                }
            }

            // Intraday restrction
            if ($current_hour < $this->restriction_start_value || $current_hour >= $this->restriction_end_value) {
                return true;
            }

            return false;
        }

        //region WooCommerce hooks
        public function is_purchasable($is_purchasable, $product)
        {
            if (!$this->validate_product($product->get_id(), false)) {
                $is_purchasable = false;
            }

            return $is_purchasable;
        }

        public function single_product_summary()
        {
            if (!$this->warn_product) {
                return;
            }

            global $product;
            $product_id = $product->get_id();

            if (!$this->validate_product($product_id, false)) {
                $restricted_category = $this->get_product_restricted_category($product_id);
                $warning_message = $this->get_warning_message($restricted_category);

                if (!empty($warning_message)) {
                    echo wp_kses_post(sprintf('<p class="stock out-of-stock">%1$s</p>', wc_format_content($warning_message)));
                }
            }
        }

        public function archive_description()
        {
            if (!$this->warn_category) {
                return;
            }

            if (is_product_category()) {
                global $wp_query;
                $category = $wp_query->get_queried_object();

                if (empty($category) || is_wp_error($category)) {
                    return;
                }

                if (!$this->validate_category($category)) {
                    $warning_message = $this->get_warning_message($category->slug);

                    if (!empty($warning_message)) {
                        echo wp_kses_post(sprintf('<div class="term-description">%1$s</div>', wc_format_content($warning_message)));
                        // wc_add_notice($warning_message, 'error');
                    }
                }
            }
        }
        //endregion

        protected function is_restricted_category($category_slug)
        {
            return in_array($category_slug, $this->restricted_categories, true);
        }

        protected function get_warning_message($category_slug)
        {
            $category_name = $this->categories_list[$category_slug];
            $warning_message = do_shortcode(wp_kses_post(sprintf($this->warning_template, $category_name, $this->restriction_start, $this->restriction_end)));

            return $warning_message;
        }

        protected function log($message, $level = WC_Log_Levels::DEBUG)
        {
            //https://woocommerce.wordpress.com/2017/01/26/improved-logging-in-woocommerce-2-7/
            //https://stackoverflow.com/questions/1423157/print-php-call-stack
            $logger = wc_get_logger();
            $log_context = array('source' => self::MOD_ID);
            $logger->log($level, $message, $log_context);
        }
    }

    //https://docs.woocommerce.com/document/query-whether-woocommerce-is-activated/
    if (!class_exists('WooCommerce')) {
        add_action('plugins_loaded', array(WC_Alcohol::class, 'get_instance'));
    }
endif;

//region WooCommerce HPOS compatibility
//https://github.com/woocommerce/woocommerce/wiki/High-Performance-Order-Storage-Upgrade-Recipe-Book#declaring-extension-incompatibility
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});
//endregion
