<?php

if (!defined('ABSPATH')) {
    exit;
}

class ImportProducts_Scheduler
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
        add_action('import_products_cron_hook', array($this, 'run_scheduled_import'));
        add_filter('cron_schedules', array($this, 'add_custom_cron_schedule'));
    }

    /**
     * Add custom cron schedule for 30 minutes
     */
    public function add_custom_cron_schedule($schedules)
    {
        $schedules['thirty_minutes'] = array(
            'interval' => 30 * 60, // 30 minutes in seconds
            'display' => __('Every 30 Minutes', 'import-products')
        );
        return $schedules;
    }

    /**
     * Schedule the import cron job
     */
    public static function schedule_import()
    {
        if (!wp_next_scheduled('import_products_cron_hook')) {
            wp_schedule_event(time(), 'thirty_minutes', 'import_products_cron_hook');
        }
    }

    /**
     * Clear the scheduled import
     */
    public static function clear_schedule()
    {
        $timestamp = wp_next_scheduled('import_products_cron_hook');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'import_products_cron_hook');
        }
    }

    /**
     * Run the scheduled import
     */
    public function run_scheduled_import()
    {
        // Check if auto-import is disabled after reset
        if (get_option('import_products_prevent_auto_import', 0)) {
            $this->log_success('Skipping scheduled import - auto-import is disabled after reset');
            return;
        }

        // Prevent multiple imports running simultaneously
        if (get_option('import_products_status') === 'running') {
            $this->log_error('Import already running, skipping scheduled import');
            return;
        }

        // Check if a manual import was run recently (within last 5 minutes)
        $last_import_time = get_option('import_products_last_import_time', 0);
        $current_time = time();
        $time_since_last_import = $current_time - $last_import_time;

        if ($time_since_last_import < 300) { // 5 minutes = 300 seconds
            $this->log_success("Skipping scheduled import - manual import was run {$time_since_last_import} seconds ago");
            return;
        }

        // Set status to running
        update_option('import_products_status', 'running');
        $this->log_success('Starting scheduled import');

        try {
            $importer = ImportProducts_CSV_Importer::get_instance();
            $result = $importer->import_next_update();

            if (is_wp_error($result)) {
                update_option('import_products_status', 'error');
                $this->log_error('Scheduled import failed: ' . $result->get_error_message());
            } else {
                update_option('import_products_status', 'completed');
                update_option('import_products_last_import_time', $current_time);
                $this->log_success('Scheduled import completed successfully');
            }
        } catch (Exception $e) {
            update_option('import_products_status', 'error');
            $this->log_error('Scheduled import exception: ' . $e->getMessage());
        }
    }

    /**
     * Log success message
     */
    private function log_success($message)
    {
        if (function_exists('error_log')) {
            error_log('[Import Products] ' . $message);
        }
    }

    /**
     * Log error message
     */
    private function log_error($message)
    {
        if (function_exists('error_log')) {
            error_log('[Import Products ERROR] ' . $message);
        }
    }
}
