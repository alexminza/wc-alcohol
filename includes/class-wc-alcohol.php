<?php

/**
 * @package wc-alcohol
 */

declare(strict_types=1);

namespace AlexMinza\WC_Alcohol;

defined('ABSPATH') || exit;

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

    const DEFAULT_RESTRICTION_START = '22:00';
    const DEFAULT_RESTRICTION_END   = '09:00';
    //endregion

    /**
     * Instance of this class.
     *
     * @var self|null
     */
    protected static $instance = null;

    protected $enabled, $mod_title, $restriction_start, $restriction_end, $restriction_start_value, $restriction_end_value;
    protected $restricted_categories, $warning_template, $warn_product, $warn_category;

    private function __construct()
    {
        $this->enabled               = wc_string_to_bool(get_option(self::MOD_SETTINGS_ENABLED, 'no'));
        $this->restriction_start     = strval(get_option(self::MOD_SETTINGS_RESTRICTION_START, self::DEFAULT_RESTRICTION_START));
        $this->restriction_end       = strval(get_option(self::MOD_SETTINGS_RESTRICTION_END, self::DEFAULT_RESTRICTION_END));
        $this->restricted_categories = (array) get_option(self::MOD_SETTINGS_CATEGORY, array());
        $this->warning_template      = strval(get_option(self::MOD_SETTINGS_WARNING));
        $this->warn_product          = wc_string_to_bool(get_option(self::MOD_SETTINGS_WARN_PRODUCT, 'yes'));
        $this->warn_category         = wc_string_to_bool(get_option(self::MOD_SETTINGS_WARN_CATEGORY, 'yes'));

        add_action('init', array($this, 'init'));
    }

    /**
     * Return an instance of this class.
     *
     * @return self A single instance of this class.
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
        $this->mod_title = __('Products sale restrictions', 'wc-alcohol');

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
        }

        if ($this->enabled) {
            add_filter('woocommerce_add_to_cart_validation', array($this, 'validate_add_to_cart'), 10, 2);
            add_action('woocommerce_check_cart_items', array($this, 'check_cart_items'));
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
            // Missing restriction categories definition
            return false;
        }

        if (empty($this->restriction_start) || empty($this->restriction_end)) {
            // Missing restriction hours
            return false;
        }

        if (!isset($this->restriction_start_value, $this->restriction_end_value) || $this->restriction_start_value === $this->restriction_end_value) {
            // Incorrect restriction hours
            return false;
        }

        return true;
    }

    //region Plugin settings
    public static function plugin_action_links($links)
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
        // https://docs.woocommerce.com/document/adding-a-section-to-a-settings-tab/
        $sections[self::MOD_SETTINGS_SECTION] = $this->mod_title;
        return $sections;
    }

    public function get_settings_products($settings, $current_section)
    {
        // https://github.com/woocommerce/woocommerce/blob/master/includes/admin/settings/class-wc-settings-products.php
        if (self::MOD_SETTINGS_SECTION === $current_section) {
            $settings_mod = array();

            $settings_mod[] = array(
                'id'   => self::MOD_SETTINGS_SECTION,
                'name' => $this->mod_title,
                'type' => 'title',
                'desc' => __('Products sale limitations during restriction hours.', 'wc-alcohol'),
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
                'type'     => 'time',
                'default'  => self::DEFAULT_RESTRICTION_START,
                'custom_attributes' => array(
                    'required' => 'required',
                ),
            );

            $settings_mod[] = array(
                'id'       => self::MOD_SETTINGS_RESTRICTION_END,
                'name'     => __('Restriction time end', 'wc-alcohol'),
                'desc'     => __('Example: 09:00', 'wc-alcohol'),
                'type'     => 'time',
                'default'  => self::DEFAULT_RESTRICTION_END,
                'custom_attributes' => array(
                    'required' => 'required',
                ),
            );

            $settings_mod[] = array(
                'id'       => self::MOD_SETTINGS_CATEGORY,
                'name'     => __('Restricted categories', 'wc-alcohol'),
                'type'     => 'multiselect',
                'class'    => 'wc-enhanced-select',
                'options'  => $this->get_categories_list(),
                'custom_attributes' => array(
                    'data-placeholder' => __('Select restricted categories', 'wc-alcohol'),
                ),
            );

            $settings_mod[] = array(
                'id'       => self::MOD_SETTINGS_WARNING,
                'type'     => 'textarea',
                'name'     => __('Warning message', 'wc-alcohol'),
                'desc_tip' => __('Warning message displayed to the customers when trying to purchase products from the selected categories during restriction hours.', 'wc-alcohol'),
                /* translators: 1: Example placeholders shown to user */
                'desc'     => __('Format: <code>%1$s</code> - Category, <code>%2$s</code> - Restriction time start, <code>%3$s</code> - Restriction time end', 'wc-alcohol'),
                /* translators: 1: Example placeholders shown to user */
                'default'  => __('The sale of products in the "%1$s" category is prohibited from %2$s to %3$s.', 'wc-alcohol'),
                'custom_attributes' => array(
                    'required' => 'required',
                ),
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

        // https://developer.wordpress.org/reference/functions/get_categories/
        $categories = get_categories($args);

        if (empty($categories) || is_wp_error($categories)) {
            return array();
        }

        return $categories;
    }
    //endregion

    protected function validate_product(int $product_id, bool $notify = true)
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
        } catch (\Exception $ex) {
            $this->log(
                $ex->getMessage(),
                \WC_Log_Levels::ERROR,
                array(
                    'product_id' => $product_id,
                    'notify' => $notify,
                    'exception' => (string) $ex,
                    'backtrace' => true,
                )
            );
        }

        // Fail-open on exception
        return true;
    }

    protected function get_product_restricted_category(int $product_id)
    {
        // https://developer.wordpress.org/reference/functions/get_the_terms/
        $categories = get_the_terms($product_id, 'product_cat');

        if (empty($categories) || is_wp_error($categories)) {
            return null;
        }

        foreach ($categories as $category) {
            if ($this->is_restricted_category($category->slug)) {
                return $category->slug; // Return first found restricted product category
            }
        }

        return null;
    }

    protected function validate_category(\WP_Term $category)
    {
        try {
            if ($this->validate()) {
                return true;
            }

            if ($this->is_restricted_category($category->slug)) {
                return false;
            }
        } catch (\Exception $ex) {
            $this->log(
                $ex->getMessage(),
                \WC_Log_Levels::ERROR,
                array(
                    'category' => $category,
                    'exception' => (string) $ex,
                    'backtrace' => true,
                )
            );
        }

        // Fail-open on exception
        return true;
    }

    protected function validate()
    {
        if (!$this->enabled) {
            return true; // Not restricted.
        }

        $current_hour = intval(current_time('Hi'));

        // Overnight restriction (e.g., 22:00 - 09:00).
        if ($this->restriction_start_value > $this->restriction_end_value) {
            // Allowed if the current time is between the end and start time.
            return $current_hour >= $this->restriction_end_value && $current_hour < $this->restriction_start_value;
        }

        // Intraday restriction (e.g., 09:00 - 17:00).
        // Allowed if the current time is outside the start and end time.
        return $current_hour < $this->restriction_start_value || $current_hour >= $this->restriction_end_value;
    }

    //region WooCommerce hooks
    public function is_purchasable(bool $is_purchasable, \WC_Product $product)
    {
        if (!$this->validate_product($product->get_id(), false)) {
            $is_purchasable = false;
        }

        return $is_purchasable;
    }

    public function validate_add_to_cart(bool $passed, int $product_id)
    {
        if (!$this->validate_product($product_id, true)) {
            $passed = false;
        }

        return $passed;
    }

    public function check_cart_items()
    {
        if (WC()->cart->is_empty()) {
            return;
        }

        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            $product_id = intval($cart_item['product_id']);

            if (!$this->validate_product($product_id, true)) {
                WC()->cart->remove_cart_item($cart_item_key);
            }
        }
    }

    /**
     * Display a warning message on the single product page if the product is restricted.
     */
    public function single_product_summary()
    {
        if (!$this->warn_product) {
            return;
        }

        $product_id = get_the_ID();
        if (empty($product_id) || $this->validate()) {
            return;
        }

        $restricted_category = $this->get_product_restricted_category($product_id);
        if (!empty($restricted_category)) {
            $warning_message = $this->get_warning_message($restricted_category);

            if (!empty($warning_message)) {
                echo wp_kses_post(sprintf('<p class="stock out-of-stock">%1$s</p>', wc_format_content($warning_message)));
            }
        }
    }

    /**
     * Display a warning message on the category page if the category is restricted.
     */
    public function archive_description()
    {
        if (!$this->warn_category) {
            return;
        }

        if (is_product_category()) {
            $category = get_queried_object();

            if (empty($category) || is_wp_error($category)) {
                return;
            }

            if (!$this->validate_category($category)) {
                $warning_message = $this->get_warning_message($category->slug);

                if (!empty($warning_message)) {
                    echo wp_kses_post(sprintf('<div class="term-description">%1$s</div>', wc_format_content($warning_message)));
                }
            }
        }
    }
    //endregion

    //region Utility
    protected function get_categories_list()
    {
        $categories = $this->get_product_categories();
        return wp_list_pluck($categories, 'name', 'slug');
    }

    protected function is_restricted_category(string $category_slug)
    {
        return in_array($category_slug, $this->restricted_categories, true);
    }

    protected function get_warning_message(string $category_slug)
    {
        try {
            $term = get_term_by('slug', $category_slug, 'product_cat');
            $category_name = $term ? $term->name : $category_slug;

            return do_shortcode(sprintf($this->warning_template, $category_name, $this->restriction_start, $this->restriction_end));
        } catch (\Exception $ex) {
            $this->log(
                $ex->getMessage(),
                \WC_Log_Levels::ERROR,
                array(
                    'category_slug' => $category_slug,
                    'exception' => (string) $ex,
                    'backtrace' => true,
                )
            );
        }

        return null;
    }

    protected function log(string $message, string $level = \WC_Log_Levels::DEBUG, ?array $additional_context = null)
    {
        // https://developer.woocommerce.com/docs/best-practices/data-management/logging/
        // https://stackoverflow.com/questions/1423157/print-php-call-stack
        $logger = wc_get_logger();
        $log_context = array('source' => self::MOD_ID);
        if (!empty($additional_context)) {
            $log_context = array_merge($log_context, $additional_context);
        }

        $logger->log($level, $message, $log_context);
    }
    //endregion
}
