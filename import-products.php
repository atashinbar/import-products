<?php

/**
 * Plugin Name: Import Products
 * Plugin URI: https://yourwebsite.com
 * Description: A WooCommerce plugin that handles product data import and updates using CSV files with automated 30-minute import cycles.
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://yourwebsite.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: import-products
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>' . __('Import Products plugin requires WooCommerce to be installed and activated.', 'import-products') . '</p></div>';
    });
    return;
}

// Define plugin constants
define('IMPORT_PRODUCTS_VERSION', '1.0.0');
define('IMPORT_PRODUCTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('IMPORT_PRODUCTS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('IMPORT_PRODUCTS_CSV_DIR', IMPORT_PRODUCTS_PLUGIN_DIR . 'csv files/');

// Include required files
require_once IMPORT_PRODUCTS_PLUGIN_DIR . 'includes/class-csv-importer.php';
require_once IMPORT_PRODUCTS_PLUGIN_DIR . 'includes/class-admin-page.php';
require_once IMPORT_PRODUCTS_PLUGIN_DIR . 'includes/class-scheduler.php';

/**
 * Main plugin class
 */
class ImportProducts
{

    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    public function init()
    {
        // Register custom taxonomies
        $this->register_taxonomies();

        // Initialize plugin components
        ImportProducts_CSV_Importer::get_instance();
        ImportProducts_Admin_Page::get_instance();
        ImportProducts_Scheduler::get_instance();

        // Load text domain
        load_plugin_textdomain('import-products', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    /**
     * Register custom taxonomies
     */
    public function register_taxonomies()
    {
        // Register product brand taxonomy
        register_taxonomy('product_brand', 'product', array(
            'label' => __('Brands', 'import-products'),
            'labels' => array(
                'name' => __('Brands', 'import-products'),
                'singular_name' => __('Brand', 'import-products'),
                'menu_name' => __('Brands', 'import-products'),
                'all_items' => __('All Brands', 'import-products'),
                'edit_item' => __('Edit Brand', 'import-products'),
                'view_item' => __('View Brand', 'import-products'),
                'update_item' => __('Update Brand', 'import-products'),
                'add_new_item' => __('Add New Brand', 'import-products'),
                'new_item_name' => __('New Brand Name', 'import-products'),
                'search_items' => __('Search Brands', 'import-products'),
                'popular_items' => __('Popular Brands', 'import-products'),
                'not_found' => __('No brands found', 'import-products'),
            ),
            'hierarchical' => false,
            'public' => true,
            'show_ui' => true,
            'show_admin_column' => true,
            'show_in_nav_menus' => true,
            'show_tagcloud' => true,
            'show_in_rest' => true,
            'rewrite' => array(
                'slug' => 'brand',
                'with_front' => true,
            ),
            'query_var' => true,
            'capabilities' => array(
                'manage_terms' => 'manage_product_terms',
                'edit_terms' => 'edit_product_terms',
                'delete_terms' => 'delete_product_terms',
                'assign_terms' => 'assign_product_terms',
            ),
        ));
    }

    public function activate()
    {
        // Register taxonomies before creating tables
        $this->register_taxonomies();

        // Create necessary database tables
        $this->create_tables();

        // Schedule cron job
        ImportProducts_Scheduler::schedule_import();

        // Set default options
        if (!get_option('import_products_last_file')) {
            update_option('import_products_last_file', 0);
        }
        if (!get_option('import_products_status')) {
            update_option('import_products_status', 'idle');
        }

        // Flush rewrite rules to ensure custom taxonomies work
        flush_rewrite_rules();
    }

    public function deactivate()
    {
        // Clear scheduled cron job
        ImportProducts_Scheduler::clear_schedule();
    }

    private function create_tables()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'import_products_log';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            file_name varchar(255) NOT NULL,
            import_date datetime DEFAULT CURRENT_TIMESTAMP,
            products_imported int(11) DEFAULT 0,
            products_updated int(11) DEFAULT 0,
            products_failed int(11) DEFAULT 0,
            status varchar(50) DEFAULT 'completed',
            error_message text,
            PRIMARY KEY (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}

// Initialize the plugin
ImportProducts::get_instance();
