<?php

if (!defined('ABSPATH')) {
    exit;
}

class ImportProducts_Admin_Page
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
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_ajax_import_products_manual_import', array($this, 'handle_manual_import'));
        add_action('wp_ajax_import_products_initial_import', array($this, 'handle_initial_import'));
        add_action('wp_ajax_import_products_reset', array($this, 'handle_reset'));
        add_action('wp_ajax_import_products_view_logs', array($this, 'handle_view_logs'));
        add_action('wp_ajax_import_products_check_status', array($this, 'handle_check_status'));
        add_action('wp_ajax_import_products_save_settings', array($this, 'handle_save_settings'));
        add_action('wp_ajax_import_products_enable_auto_import', array($this, 'handle_enable_auto_import'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        add_submenu_page(
            'woocommerce',
            __('Import Products', 'import-products'),
            __('Import Products', 'import-products'),
            'manage_woocommerce',
            'import-products',
            array($this, 'admin_page')
        );
    }

    /**
     * Admin page content
     */
    public function admin_page()
    {
        $last_file = get_option('import_products_last_file', 0);
        $status = get_option('import_products_status', 'idle');
        $next_file = ImportProducts_CSV_Importer::get_instance()->get_next_csv_file();
        $logs = $this->get_recent_logs();

        include IMPORT_PRODUCTS_PLUGIN_DIR . 'templates/admin-page.php';
    }

    /**
     * Handle manual import AJAX request
     */
    public function handle_manual_import()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'import_products_nonce')) {
            wp_die(__('Security check failed', 'import-products'));
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions', 'import-products'));
        }

        $importer = ImportProducts_CSV_Importer::get_instance();
        $result = $importer->import_next_update();

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * Handle initial import AJAX request
     */
    public function handle_initial_import()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'import_products_nonce')) {
            wp_die(__('Security check failed', 'import-products'));
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions', 'import-products'));
        }

        // Send immediate response to prevent timeout issues
        if (!wp_doing_ajax()) {
            wp_die(__('Invalid request', 'import-products'));
        }

        $importer = ImportProducts_CSV_Importer::get_instance();
        $result = $importer->import_initial_csv();

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * Handle reset AJAX request with complete cleanup
     */
    public function handle_reset()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'import_products_nonce')) {
            wp_die(__('Security check failed', 'import-products'));
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions', 'import-products'));
        }

        // Start cleanup process
        $cleanup_results = $this->perform_complete_cleanup();

        wp_send_json_success(array(
            'message' => __('Complete reset performed successfully.', 'import-products'),
            'cleanup_results' => $cleanup_results
        ));
    }

    /**
     * Perform complete cleanup of all imported data
     */
    private function perform_complete_cleanup()
    {
        $results = array(
            'products_deleted' => 0,
            'categories_deleted' => 0,
            'attributes_deleted' => 0,
            'brands_deleted' => 0,
            'logs_cleared' => false,
            'options_reset' => false
        );

        try {
            // 1. Delete all WooCommerce products
            $results['products_deleted'] = $this->delete_all_woocommerce_products();

            // 2. Delete all product categories
            $results['categories_deleted'] = $this->delete_all_product_categories();

            // 3. Delete all product attributes
            $results['attributes_deleted'] = $this->delete_all_product_attributes();

            // 4. Delete all product brands (if using a brand plugin)
            $results['brands_deleted'] = $this->delete_all_product_brands();

            // 5. Clear import logs
            global $wpdb;
            $table_name = $wpdb->prefix . 'import_products_log';
            $wpdb->query("TRUNCATE TABLE $table_name");
            $results['logs_cleared'] = true;

            // 6. Reset plugin options to initial state
            update_option('import_products_last_file', 0);
            update_option('import_products_status', 'idle');
            delete_option('import_products_last_import_time');

            // Set a flag to prevent automatic re-import after reset
            update_option('import_products_reset_performed', time());
            update_option('import_products_prevent_auto_import', 1);
            $results['options_reset'] = true;

            // 7. Clear scheduled cron job completely (don't reschedule)
            ImportProducts_Scheduler::clear_schedule();
            $results['cron_disabled'] = true;

            // 8. Clear WooCommerce caches
            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients();
            }
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }
        } catch (Exception $e) {
            error_log('Import Products Reset Error: ' . $e->getMessage());
        }

        return $results;
    }

    /**
     * Delete all WooCommerce products
     */
    private function delete_all_woocommerce_products()
    {
        $deleted_count = 0;

        // Get all product IDs (including variations)
        $product_ids = get_posts(array(
            'post_type' => array('product', 'product_variation'),
            'post_status' => array('publish', 'private', 'draft', 'trash'),
            'numberposts' => -1,
            'fields' => 'ids'
        ));

        foreach ($product_ids as $product_id) {
            // Force delete (bypass trash)
            if (wp_delete_post($product_id, true)) {
                $deleted_count++;
            }
        }

        return $deleted_count;
    }

    /**
     * Delete all product categories
     */
    private function delete_all_product_categories()
    {
        $deleted_count = 0;

        // Get all product categories
        $categories = get_terms(array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'fields' => 'ids'
        ));

        if (!is_wp_error($categories)) {
            foreach ($categories as $category_id) {
                if (wp_delete_term($category_id, 'product_cat')) {
                    $deleted_count++;
                }
            }
        }

        return $deleted_count;
    }

    /**
     * Delete all product attributes
     */
    private function delete_all_product_attributes()
    {
        $deleted_count = 0;

        // Get all product attributes
        if (function_exists('wc_get_attribute_taxonomies')) {
            $attributes = wc_get_attribute_taxonomies();

            foreach ($attributes as $attribute) {
                // Delete attribute terms first
                $terms = get_terms(array(
                    'taxonomy' => 'pa_' . $attribute->attribute_name,
                    'hide_empty' => false,
                    'fields' => 'ids'
                ));

                if (!is_wp_error($terms)) {
                    foreach ($terms as $term_id) {
                        wp_delete_term($term_id, 'pa_' . $attribute->attribute_name);
                    }
                }

                // Delete the attribute itself
                if (function_exists('wc_delete_attribute')) {
                    if (wc_delete_attribute($attribute->attribute_id)) {
                        $deleted_count++;
                    }
                }
            }
        }

        return $deleted_count;
    }

    /**
     * Delete all product brands
     */
    private function delete_all_product_brands()
    {
        $deleted_count = 0;

        // Check for common brand taxonomies
        $brand_taxonomies = array('product_brand', 'pwb-brand', 'yith_product_brand');

        foreach ($brand_taxonomies as $taxonomy) {
            if (taxonomy_exists($taxonomy)) {
                $brands = get_terms(array(
                    'taxonomy' => $taxonomy,
                    'hide_empty' => false,
                    'fields' => 'ids'
                ));

                if (!is_wp_error($brands)) {
                    foreach ($brands as $brand_id) {
                        if (wp_delete_term($brand_id, $taxonomy)) {
                            $deleted_count++;
                        }
                    }
                }
            }
        }

        return $deleted_count;
    }

    /**
     * Handle view logs AJAX request
     */
    public function handle_view_logs()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'import_products_nonce')) {
            wp_die(__('Security check failed', 'import-products'));
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions', 'import-products'));
        }

        $log_file_name = sanitize_text_field($_POST['log_file'] ?? '');

        if (empty($log_file_name)) {
            wp_send_json_error(__('No log file specified.', 'import-products'));
            return;
        }

        $log_file = IMPORT_PRODUCTS_PLUGIN_DIR . 'logs/' . $log_file_name;

        // Security check: ensure the file is in the logs directory and has .log extension
        if (!file_exists($log_file) || pathinfo($log_file, PATHINFO_EXTENSION) !== 'log') {
            wp_send_json_error(__('Log file not found or invalid.', 'import-products'));
            return;
        }

        $log_content = file_get_contents($log_file);
        wp_send_json_success(array(
            'content' => $log_content,
            'file_name' => $log_file_name
        ));
    }

    /**
     * Get recent import logs
     */
    private function get_recent_logs($limit = 10)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'import_products_log';

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name ORDER BY import_date DESC LIMIT %d",
            $limit
        ));

        return $results;
    }

    /**
     * Handle status check AJAX request
     */
    public function handle_check_status()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'import_products_nonce')) {
            wp_die(__('Security check failed', 'import-products'));
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions', 'import-products'));
        }

        $last_file = get_option('import_products_last_file', 0);
        $status = get_option('import_products_status', 'idle');
        $last_import_time = get_option('import_products_last_import_time', 0);

        // Get the most recent import log
        global $wpdb;
        $table_name = $wpdb->prefix . 'import_products_log';
        $recent_log = $wpdb->get_row("SELECT * FROM $table_name ORDER BY import_date DESC LIMIT 1");

        wp_send_json_success(array(
            'last_file' => $last_file,
            'status' => $status,
            'last_import_time' => $last_import_time,
            'recent_log' => $recent_log
        ));
    }

    /**
     * Get available log files with enhanced file-specific information
     */
    public function get_available_log_files()
    {
        $log_dir = IMPORT_PRODUCTS_PLUGIN_DIR . 'logs/';
        $log_files = array();

        if (is_dir($log_dir)) {
            $files = glob($log_dir . 'import-details-*.log');
            foreach ($files as $file) {
                $basename = basename($file);
                $file_info = array(
                    'filename' => $basename,
                    'path' => $file,
                    'size' => filesize($file),
                    'modified' => filemtime($file)
                );

                // Parse different log file formats
                if (preg_match('/import-details-(\d{4}-\d{2}-\d{2})-(.+)\.log/', $basename, $matches)) {
                    // File-specific log: import-details-2025-06-28-2.log
                    $file_info['date'] = $matches[1];
                    $file_info['csv_file'] = $matches[2] . '.csv';
                    $file_info['type'] = 'file-specific';
                    $file_info['display_name'] = $matches[1] . ' - ' . $matches[2] . '.csv';
                } elseif (preg_match('/import-details-(\d{4}-\d{2}-\d{2})\.log/', $basename, $matches)) {
                    // General log: import-details-2025-06-28.log
                    $file_info['date'] = $matches[1];
                    $file_info['csv_file'] = 'General';
                    $file_info['type'] = 'general';
                    $file_info['display_name'] = $matches[1] . ' - All imports';
                } else {
                    // Unknown format
                    continue;
                }

                $log_files[] = $file_info;
            }

            // Sort by modification time (newest first)
            usort($log_files, function ($a, $b) {
                return $b['modified'] - $a['modified'];
            });
        }

        return $log_files;
    }

    /**
     * Handle save settings AJAX request
     */
    public function handle_save_settings()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'import_products_nonce')) {
            wp_die(__('Security check failed', 'import-products'));
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions', 'import-products'));
        }

        $settings = array(
            'email_notifications_enabled' => isset($_POST['email_notifications_enabled']) ? 1 : 0,
            'notify_on_failures' => isset($_POST['notify_on_failures']) ? 1 : 0,
            'notify_on_new_products' => isset($_POST['notify_on_new_products']) ? 1 : 0,
            'notification_email' => sanitize_email($_POST['notification_email'] ?? get_option('admin_email'))
        );

        foreach ($settings as $key => $value) {
            update_option('import_products_' . $key, $value);
        }

        wp_send_json_success(array(
            'message' => __('Settings saved successfully.', 'import-products')
        ));
    }

    /**
     * Get email notification settings
     */
    public function get_email_settings()
    {
        return array(
            'email_notifications_enabled' => get_option('import_products_email_notifications_enabled', 1),
            'notify_on_failures' => get_option('import_products_notify_on_failures', 1),
            'notify_on_new_products' => get_option('import_products_notify_on_new_products', 1),
            'notification_email' => get_option('import_products_notification_email', get_option('admin_email'))
        );
    }

    /**
     * Handle enable auto-import AJAX request
     */
    public function handle_enable_auto_import()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'import_products_nonce')) {
            wp_die(__('Security check failed', 'import-products'));
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions', 'import-products'));
        }

        // Re-enable auto-import
        delete_option('import_products_prevent_auto_import');
        delete_option('import_products_reset_performed');

        // Re-schedule the cron job
        ImportProducts_Scheduler::schedule_import();

        wp_send_json_success(array(
            'message' => __('Auto-import has been re-enabled successfully. Scheduled imports will resume every 30 minutes.', 'import-products')
        ));
    }
}
