<div class="wrap">
    <h1><?php _e('Import Products', 'import-products'); ?></h1>

    <div class="import-products-dashboard">
        <div class="card">
            <h2><?php _e('Current Status', 'import-products'); ?></h2>
            <table class="form-table">
                <tr>
                    <th><?php _e('Last Imported File:', 'import-products'); ?></th>
                    <td>
                        <?php if ($last_file > 0): ?>
                            <?php echo esc_html($last_file . '.csv'); ?>
                        <?php else: ?>
                            <span style="color: #d63638;"><?php _e('No files imported yet', 'import-products'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('Current Status:', 'import-products'); ?></th>
                    <td>
                        <span class="status-<?php echo esc_attr($status); ?>">
                            <?php echo esc_html(ucfirst($status)); ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('Next Available File:', 'import-products'); ?></th>
                    <td>
                        <?php if ($next_file): ?>
                            <span style="color: #00a32a;"><?php echo esc_html($next_file['number'] . '.csv'); ?></span>
                        <?php else: ?>
                            <span style="color: #d63638;"><?php _e('No update files available', 'import-products'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('Next Scheduled Import:', 'import-products'); ?></th>
                    <td>
                        <?php
                        $next_scheduled = wp_next_scheduled('import_products_cron_hook');
                        if ($next_scheduled) {
                            echo esc_html(date('Y-m-d H:i:s', $next_scheduled));
                        } else {
                            echo '<span style="color: #d63638;">' . __('Not scheduled', 'import-products') . '</span>';
                        }
                        ?>
                    </td>
                </tr>
            </table>
        </div>

        <div class="card">
            <h2><?php _e('Manual Import', 'import-products'); ?></h2>
            <p><?php _e('Use these buttons to manually trigger imports when needed.', 'import-products'); ?></p>

            <p>
                <button type="button" class="button button-secondary" id="initial-import-btn"
                    <?php echo ($last_file > 0) ? 'disabled' : ''; ?>>
                    <?php _e('Import Initial File (1.csv)', 'import-products'); ?>
                </button>
                <?php if ($last_file > 0): ?>
                    <span class="description"><?php _e('Initial import already completed', 'import-products'); ?></span>
                <?php endif; ?>
            </p>

            <p>
                <button type="button" class="button button-primary" id="manual-import-btn"
                    <?php echo (!$next_file) ? 'disabled' : ''; ?>>
                    <?php _e('Import Next Update File', 'import-products'); ?>
                </button>
                <?php if (!$next_file): ?>
                    <span class="description"><?php _e('No update files available', 'import-products'); ?></span>
                <?php endif; ?>
            </p>

            <hr style="margin: 20px 0;">

            <p>
                <button type="button" class="button button-link-delete" id="reset-btn" style="background: #d63638; color: white; border-color: #d63638;">
                    <?php _e('🗑️ Complete Reset - Delete ALL Products', 'import-products'); ?>
                </button>
                <br><br>
                <span class="description" style="color: #d63638; font-weight: bold;">
                    ⚠️ <?php _e('DANGER: This will permanently delete ALL WooCommerce products, categories, attributes, and brands!', 'import-products'); ?>
                </span>
                <br>
                <span class="description">
                    <?php _e('This action will completely clean your WooCommerce store and cannot be undone. Use only when you want to start fresh.', 'import-products'); ?>
                </span>
            </p>

            <?php
            $auto_import_disabled = get_option('import_products_prevent_auto_import', 0);
            $reset_time = get_option('import_products_reset_performed', 0);
            if ($auto_import_disabled):
            ?>
                <div class="notice notice-warning" style="margin-top: 20px;">
                    <p>
                        <strong><?php _e('⚠️ Auto-Import is Currently Disabled', 'import-products'); ?></strong><br>
                        <?php
                        if ($reset_time) {
                            $reset_date = date('Y-m-d H:i:s', $reset_time);
                            printf(__('Auto-import was disabled after complete reset on %s.', 'import-products'), $reset_date);
                        } else {
                            _e('Auto-import is currently disabled.', 'import-products');
                        }
                        ?>
                    </p>
                    <p>
                        <button type="button" class="button button-secondary" id="enable-auto-import-btn">
                            <?php _e('🔄 Enable Auto-Import', 'import-products'); ?>
                        </button>
                        <span class="description" style="margin-left: 10px;">
                            <?php _e('This will enable the scheduled imports every 30 minutes.', 'import-products'); ?>
                        </span>
                    </p>
                </div>
            <?php endif; ?>

            <div id="import-results" style="display: none;">
                <h3><?php _e('Import Results', 'import-products'); ?></h3>
                <div id="import-results-content"></div>
            </div>
        </div>

        <div class="card">
            <h2><?php _e('Detailed Logs', 'import-products'); ?></h2>
            <p><?php _e('View detailed import logs for troubleshooting and tracking. Logs are now separated by CSV file for easier debugging.', 'import-products'); ?></p>
            <p>
                <select id="log-file-selector" style="margin-right: 10px; min-width: 300px;">
                    <option value=""><?php _e('Select a log file...', 'import-products'); ?></option>
                    <?php
                    $available_logs = $this->get_available_log_files();
                    foreach ($available_logs as $log_info) {
                        $file_size = round($log_info['size'] / 1024, 1); // Size in KB
                        $display_text = $log_info['display_name'] . ' (' . $file_size . ' KB)';
                        echo '<option value="' . esc_attr($log_info['filename']) . '">' . esc_html($display_text) . '</option>';
                    }
                    ?>
                </select>
                <button id="view-logs-btn" class="button button-secondary">
                    <?php _e('View Logs', 'import-products'); ?>
                </button>
            </p>
            <div id="log-content" style="display: none; margin-top: 20px;">
                <h4 id="log-content-title"><?php _e('Log Content:', 'import-products'); ?></h4>
                <div style="margin-bottom: 10px;">
                    <button id="download-log-btn" class="button button-small" style="display: none;">
                        <?php _e('Download Log', 'import-products'); ?>
                    </button>
                    <span id="log-file-info" style="margin-left: 10px; color: #666; font-size: 12px;"></span>
                </div>
                <pre id="log-text" style="background: #f1f1f1; padding: 15px; border: 1px solid #ccc; max-height: 400px; overflow-y: auto; white-space: pre-wrap; font-size: 12px; line-height: 1.4;"></pre>
            </div>
        </div>

        <div class="card">
            <h2><?php _e('Recent Import Logs', 'import-products'); ?></h2>
            <?php if (!empty($logs)): ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('File Name', 'import-products'); ?></th>
                            <th><?php _e('Date', 'import-products'); ?></th>
                            <th><?php _e('Imported', 'import-products'); ?></th>
                            <th><?php _e('Updated', 'import-products'); ?></th>
                            <th><?php _e('Failed', 'import-products'); ?></th>
                            <th><?php _e('Status', 'import-products'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo esc_html($log->file_name); ?></td>
                                <td><?php echo esc_html($log->import_date); ?></td>
                                <td><?php echo esc_html($log->products_imported); ?></td>
                                <td><?php echo esc_html($log->products_updated); ?></td>
                                <td>
                                    <?php if ($log->products_failed > 0): ?>
                                        <span style="color: #d63638;"><?php echo esc_html($log->products_failed); ?></span>
                                    <?php else: ?>
                                        <?php echo esc_html($log->products_failed); ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-<?php echo esc_attr($log->status); ?>">
                                        <?php echo esc_html(ucfirst(str_replace('_', ' ', $log->status))); ?>
                                    </span>
                                    <?php if (!empty($log->error_message)): ?>
                                        <button type="button" class="button-link view-errors"
                                            data-errors="<?php echo esc_attr($log->error_message); ?>">
                                            <?php _e('View Errors', 'import-products'); ?>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p><?php _e('No import logs found.', 'import-products'); ?></p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2><?php _e('Email Notification Settings', 'import-products'); ?></h2>
            <p><?php _e('Configure email notifications for import events. Notifications will be sent to help you stay informed about import activities.', 'import-products'); ?></p>

            <?php $email_settings = $this->get_email_settings(); ?>

            <form id="email-settings-form">
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Enable Email Notifications', 'import-products'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="email_notifications_enabled" value="1"
                                    <?php checked($email_settings['email_notifications_enabled'], 1); ?> />
                                <?php _e('Enable email notifications for import events', 'import-products'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Notification Email', 'import-products'); ?></th>
                        <td>
                            <input type="email" name="notification_email"
                                value="<?php echo esc_attr($email_settings['notification_email']); ?>"
                                class="regular-text" />
                            <p class="description"><?php _e('Email address to receive notifications. Defaults to admin email.', 'import-products'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Failure Notifications', 'import-products'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="notify_on_failures" value="1"
                                    <?php checked($email_settings['notify_on_failures'], 1); ?> />
                                <?php _e('Send notifications when imports fail or have errors', 'import-products'); ?>
                            </label>
                            <p class="description"><?php _e('Get notified about CSV structure issues, import failures, and processing errors.', 'import-products'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('New Product Notifications', 'import-products'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="notify_on_new_products" value="1"
                                    <?php checked($email_settings['notify_on_new_products'], 1); ?> />
                                <?php _e('Send notifications when new products are added during scheduled imports', 'import-products'); ?>
                            </label>
                            <p class="description"><?php _e('Get notified when new products are automatically created (not during initial import).', 'import-products'); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary"><?php _e('Save Settings', 'import-products'); ?></button>
                </p>
            </form>

            <div id="settings-message" style="display: none; margin-top: 10px;"></div>
        </div>
    </div>
</div>

<style>
    .import-products-dashboard .card {
        background: #fff;
        border: 1px solid #c3c4c7;
        box-shadow: 0 1px 1px rgba(0, 0, 0, .04);
        margin-bottom: 20px;
        padding: 20px;
    }

    .import-products-dashboard .card h2 {
        margin-top: 0;
    }

    .status-idle {
        color: #646970;
    }

    .status-running {
        color: #d63638;
    }

    .status-completed {
        color: #00a32a;
    }

    .status-completed_with_errors {
        color: #dba617;
    }

    .status-error {
        color: #d63638;
    }

    #import-results {
        margin-top: 20px;
        padding: 15px;
        background: #f0f0f1;
        border-left: 4px solid #72aee6;
    }

    .import-success {
        color: #00a32a;
    }

    .import-error {
        color: #d63638;
    }

    .import-warning {
        color: #dba617;
    }
</style>

<script type="text/javascript">
    jQuery(document).ready(function($) {
        // Handle manual import
        $('#manual-import-btn').on('click', function() {
            var $btn = $(this);
            var originalText = $btn.text();

            $btn.prop('disabled', true).text('<?php _e('Importing...', 'import-products'); ?>');
            $('#import-results').hide();

            // Show progress message
            $('#import-results-content').html(
                '<div class="import-warning">' +
                '<p><strong><?php _e('Import in progress...', 'import-products'); ?></strong></p>' +
                '<p><?php _e('Please wait while processing the CSV file...', 'import-products'); ?></p>' +
                '</div>'
            );
            $('#import-results').show();

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                timeout: 180000, // 3 minutes timeout
                data: {
                    action: 'import_products_manual_import',
                    nonce: '<?php echo wp_create_nonce('import_products_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        $('#import-results-content').html(
                            '<div class="import-success">' +
                            '<p><strong><?php _e('Import completed successfully!', 'import-products'); ?></strong></p>' +
                            '<ul>' +
                            '<li><?php _e('Products imported:', 'import-products'); ?> ' + response.data.imported + '</li>' +
                            '<li><?php _e('Products updated:', 'import-products'); ?> ' + response.data.updated + '</li>' +
                            '<li><?php _e('Products failed:', 'import-products'); ?> ' + response.data.failed + '</li>' +
                            '</ul>' +
                            '</div>'
                        );

                        // Reload page after 3 seconds
                        setTimeout(function() {
                            location.reload();
                        }, 3000);
                    } else {
                        $('#import-results-content').html(
                            '<div class="import-error">' +
                            '<p><strong><?php _e('Import failed:', 'import-products'); ?></strong></p>' +
                            '<p>' + response.data + '</p>' +
                            '</div>'
                        );
                    }

                    $('#import-results').show();
                },
                error: function(xhr, status, error) {
                    var errorMessage = '';
                    if (status === 'timeout') {
                        errorMessage = '<?php _e('The import process took longer than expected. Please check the logs to verify if the import completed successfully.', 'import-products'); ?>';
                    } else {
                        errorMessage = '<?php _e('An error occurred during the import. Please check the logs for details.', 'import-products'); ?>';
                    }

                    $('#import-results-content').html(
                        '<div class="import-error">' +
                        '<p><strong><?php _e('Import Error:', 'import-products'); ?></strong></p>' +
                        '<p>' + errorMessage + '</p>' +
                        '<p><em><?php _e('Note: The import may have completed successfully despite this error. Check the logs and your products list.', 'import-products'); ?></em></p>' +
                        '</div>'
                    );
                    $('#import-results').show();
                },
                complete: function() {
                    $btn.prop('disabled', false).text(originalText);
                }
            });
        });

        // Handle initial import
        $('#initial-import-btn').on('click', function() {
            var $btn = $(this);
            var originalText = $btn.text();

            $btn.prop('disabled', true).text('<?php _e('Importing...', 'import-products'); ?>');
            $('#import-results').hide();

            // Show progress message
            $('#import-results-content').html(
                '<div class="import-warning">' +
                '<p><strong><?php _e('Import in progress...', 'import-products'); ?></strong></p>' +
                '<p><?php _e('This may take several minutes for large CSV files. Please wait...', 'import-products'); ?></p>' +
                '</div>'
            );
            $('#import-results').show();

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                timeout: 300000, // 5 minutes timeout
                data: {
                    action: 'import_products_initial_import',
                    nonce: '<?php echo wp_create_nonce('import_products_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        $('#import-results-content').html(
                            '<div class="import-success">' +
                            '<p><strong><?php _e('Initial import completed successfully!', 'import-products'); ?></strong></p>' +
                            '<ul>' +
                            '<li><?php _e('Products imported:', 'import-products'); ?> ' + response.data.imported + '</li>' +
                            '<li><?php _e('Products updated:', 'import-products'); ?> ' + response.data.updated + '</li>' +
                            '<li><?php _e('Products failed:', 'import-products'); ?> ' + response.data.failed + '</li>' +
                            '</ul>' +
                            '</div>'
                        );

                        // Reload page after 3 seconds
                        setTimeout(function() {
                            location.reload();
                        }, 3000);
                    } else {
                        $('#import-results-content').html(
                            '<div class="import-error">' +
                            '<p><strong><?php _e('Initial import failed:', 'import-products'); ?></strong></p>' +
                            '<p>' + response.data + '</p>' +
                            '</div>'
                        );
                    }

                    $('#import-results').show();
                },
                error: function(xhr, status, error) {
                    var errorMessage = '';
                    if (status === 'timeout') {
                        errorMessage = '<?php _e('The import process took longer than expected. Please check the logs to verify if the import completed successfully.', 'import-products'); ?>';
                    } else {
                        errorMessage = '<?php _e('An error occurred during the initial import. Please check the logs for details.', 'import-products'); ?>';
                    }

                    $('#import-results-content').html(
                        '<div class="import-error">' +
                        '<p><strong><?php _e('Import Error:', 'import-products'); ?></strong></p>' +
                        '<p>' + errorMessage + '</p>' +
                        '<p><em><?php _e('Note: The import may have completed successfully despite this error. Check the logs and your products list.', 'import-products'); ?></em></p>' +
                        '</div>'
                    );
                    $('#import-results').show();
                },
                complete: function() {
                    $btn.prop('disabled', false).text(originalText);
                }
            });
        });

        // Handle view errors
        $('.view-errors').on('click', function() {
            var errors = $(this).data('errors');
            alert(errors);
        });

        // Handle view logs
        $('#view-logs-btn').on('click', function() {
            var selectedFile = $('#log-file-selector').val();
            if (!selectedFile) {
                alert('<?php _e('Please select a log file first.', 'import-products'); ?>');
                return;
            }

            var $btn = $(this);
            var originalText = $btn.text();

            $btn.prop('disabled', true).text('<?php _e('Loading...', 'import-products'); ?>');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'import_products_view_logs',
                    nonce: '<?php echo wp_create_nonce('import_products_nonce'); ?>',
                    log_file: selectedFile
                },
                success: function(response) {
                    if (response.success) {
                        $('#log-text').text(response.data.content);
                        $('#log-content-title').text('<?php _e('Log Content:', 'import-products'); ?> ' + response.data.file_name);

                        // Show file info
                        var fileSize = (response.data.content.length / 1024).toFixed(1);
                        $('#log-file-info').text('File size: ' + fileSize + ' KB | Lines: ' + response.data.content.split('\n').length);

                        // Show download button
                        $('#download-log-btn').show().off('click').on('click', function() {
                            var blob = new Blob([response.data.content], {
                                type: 'text/plain'
                            });
                            var url = window.URL.createObjectURL(blob);
                            var a = document.createElement('a');
                            a.href = url;
                            a.download = response.data.file_name;
                            document.body.appendChild(a);
                            a.click();
                            window.URL.revokeObjectURL(url);
                            document.body.removeChild(a);
                        });

                        $('#log-content').show();
                    } else {
                        alert('<?php _e('Error loading logs:', 'import-products'); ?> ' + response.data);
                    }
                },
                error: function() {
                    alert('<?php _e('An error occurred while loading the logs.', 'import-products'); ?>');
                },
                complete: function() {
                    $btn.prop('disabled', false).text(originalText);
                }
            });
        });

        // Handle complete reset with product deletion
        $('#reset-btn').on('click', function() {
            // First warning
            if (!confirm('<?php _e('⚠️ WARNING: This will permanently delete ALL WooCommerce products, categories, attributes, and brands from your site!\n\nThis includes:\n• All products and variations\n• All product categories\n• All product attributes\n• All product brands\n• All import logs\n\nThis action CANNOT be undone!\n\nAre you absolutely sure you want to proceed?', 'import-products'); ?>')) {
                return;
            }

            // Second confirmation for safety
            var userInput = prompt('<?php _e('FINAL CONFIRMATION: Type "DELETE ALL" (without quotes) to confirm you want to delete all WooCommerce data:', 'import-products'); ?>');
            if (userInput !== 'DELETE ALL') {
                alert('<?php _e('Reset cancelled. You must type "DELETE ALL" exactly to proceed.', 'import-products'); ?>');
                return;
            }

            var $btn = $(this);
            var originalText = $btn.text();

            $btn.prop('disabled', true).text('<?php _e('Performing Complete Reset...', 'import-products'); ?>');
            $('#import-results').hide();

            // Show progress message
            $('#import-results-content').html(
                '<div class="import-warning">' +
                '<p><strong><?php _e('🔄 Performing complete reset...', 'import-products'); ?></strong></p>' +
                '<p><?php _e('This may take several minutes. Please do not close this page.', 'import-products'); ?></p>' +
                '<p><em><?php _e('Deleting all products, categories, attributes, and brands...', 'import-products'); ?></em></p>' +
                '</div>'
            );
            $('#import-results').show();

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                timeout: 300000, // 5 minutes timeout for cleanup
                data: {
                    action: 'import_products_reset',
                    nonce: '<?php echo wp_create_nonce('import_products_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        var results = response.data.cleanup_results;
                        var message = '<div class="import-success">';
                        message += '<h3><?php _e('✅ Complete Reset Successful!', 'import-products'); ?></h3>';
                        message += '<p><strong><?php _e('All WooCommerce data has been permanently deleted:', 'import-products'); ?></strong></p>';
                        message += '<ul style="list-style: disc; margin-left: 20px;">';
                        message += '<li><?php _e('Products deleted:', 'import-products'); ?> <strong>' + results.products_deleted + '</strong></li>';
                        message += '<li><?php _e('Categories deleted:', 'import-products'); ?> <strong>' + results.categories_deleted + '</strong></li>';
                        message += '<li><?php _e('Attributes deleted:', 'import-products'); ?> <strong>' + results.attributes_deleted + '</strong></li>';
                        message += '<li><?php _e('Brands deleted:', 'import-products'); ?> <strong>' + results.brands_deleted + '</strong></li>';
                        message += '<li><?php _e('Logs cleared:', 'import-products'); ?> <strong>' + (results.logs_cleared ? '✅ Yes' : '❌ No') + '</strong></li>';
                        message += '<li><?php _e('Settings reset:', 'import-products'); ?> <strong>' + (results.options_reset ? '✅ Yes' : '❌ No') + '</strong></li>';
                        message += '<li><?php _e('Auto-import disabled:', 'import-products'); ?> <strong>' + (results.cron_disabled ? '✅ Yes' : '❌ No') + '</strong></li>';
                        message += '</ul>';
                        message += '<p><strong style="color: green;"><?php _e('🎉 Your WooCommerce store is now completely clean and ready for fresh import!', 'import-products'); ?></strong></p>';
                        message += '<div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; margin: 10px 0; border-radius: 4px;">';
                        message += '<p><strong><?php _e('📋 Next Steps:', 'import-products'); ?></strong></p>';
                        message += '<ul style="margin-left: 20px;">';
                        message += '<li><?php _e('Auto import has been disabled to prevent immediate import', 'import-products'); ?></li>';
                        message += '<li><?php _e('Run "Initial Import" when you are ready to import fresh data', 'import-products'); ?></li>';
                        message += '<li><?php _e('Use "Enable Auto-Import" button to resume scheduled imports', 'import-products'); ?></li>';
                        message += '</ul>';
                        message += '</div>';
                        message += '<p><em><?php _e('Page will reload in 5 seconds...', 'import-products'); ?></em></p>';
                        message += '</div>';

                        $('#import-results-content').html(message);

                        // Reload page after 5 seconds to see the clean state
                        setTimeout(function() {
                            location.reload();
                        }, 5000);
                    } else {
                        $('#import-results-content').html(
                            '<div class="import-error">' +
                            '<p><strong><?php _e('Reset failed:', 'import-products'); ?></strong></p>' +
                            '<p>' + response.data + '</p>' +
                            '</div>'
                        );
                    }

                    $('#import-results').show();
                },
                error: function(xhr, status, error) {
                    var errorMessage = '';
                    if (status === 'timeout') {
                        errorMessage = '<?php _e('The reset process took longer than expected. Some items may have been deleted. Please check your products list.', 'import-products'); ?>';
                    } else {
                        errorMessage = '<?php _e('An error occurred during the reset process.', 'import-products'); ?>';
                    }

                    $('#import-results-content').html(
                        '<div class="import-error">' +
                        '<p><strong><?php _e('Reset Error:', 'import-products'); ?></strong></p>' +
                        '<p>' + errorMessage + '</p>' +
                        '</div>'
                    );
                    $('#import-results').show();
                },
                complete: function() {
                    $btn.prop('disabled', false).text(originalText);
                }
            });
        });

        // Function to check import status
        function checkImportStatus() {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'import_products_check_status',
                    nonce: '<?php echo wp_create_nonce('import_products_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success && response.data.recent_log) {
                        var log = response.data.recent_log;
                        var logTime = new Date(log.import_date);
                        var now = new Date();
                        var timeDiff = (now - logTime) / 1000; // seconds

                        // If there's a recent log (within last 2 minutes)
                        if (timeDiff < 120) {
                            $('#import-results-content').html(
                                '<div class="import-success">' +
                                '<p><strong><?php _e('Import completed successfully!', 'import-products'); ?></strong></p>' +
                                '<p><?php _e('The import completed despite the timeout error.', 'import-products'); ?></p>' +
                                '<ul>' +
                                '<li><?php _e('Products imported:', 'import-products'); ?> ' + log.products_imported + '</li>' +
                                '<li><?php _e('Products updated:', 'import-products'); ?> ' + log.products_updated + '</li>' +
                                '<li><?php _e('Products failed:', 'import-products'); ?> ' + log.products_failed + '</li>' +
                                '</ul>' +
                                '</div>'
                            );

                            // Reload page after 3 seconds
                            setTimeout(function() {
                                location.reload();
                            }, 3000);
                        }
                    }
                }
            });
        }

        // Add status check to timeout errors
        $(document).on('ajaxComplete', function(event, xhr, settings) {
            if (settings.data && settings.data.indexOf('import_products_initial_import') > -1) {
                if ($('#import-results-content').find('.import-error').length > 0) {
                    setTimeout(function() {
                        checkImportStatus();
                    }, 5000); // Check after 5 seconds
                }
            }
        });

        // Handle enable auto-import
        $('#enable-auto-import-btn').on('click', function() {
            if (!confirm('<?php _e('Are you sure you want to enable auto import? This will resume scheduled imports every 30 minutes.', 'import-products'); ?>')) {
                return;
            }

            var $btn = $(this);
            var originalText = $btn.text();

            $btn.prop('disabled', true).text('<?php _e('Enabling...', 'import-products'); ?>');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'import_products_enable_auto_import',
                    nonce: '<?php echo wp_create_nonce('import_products_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        $('#import-results-content').html(
                            '<div class="import-success">' +
                            '<p><strong><?php _e('✅ Auto-Import Enabled!', 'import-products'); ?></strong></p>' +
                            '<p>' + response.data.message + '</p>' +
                            '<p><em><?php _e('Page will reload in 3 seconds...', 'import-products'); ?></em></p>' +
                            '</div>'
                        );
                        $('#import-results').show();

                        // Reload page after 3 seconds to update the interface
                        setTimeout(function() {
                            location.reload();
                        }, 3000);
                    } else {
                        alert('<?php _e('Error enabling auto import:', 'import-products'); ?> ' + response.data);
                    }
                },
                error: function() {
                    alert('<?php _e('An error occurred while enabling auto import.', 'import-products'); ?>');
                },
                complete: function() {
                    $btn.prop('disabled', false).text(originalText);
                }
            });
        });

        // Handle email settings form submission
        $('#email-settings-form').on('submit', function(e) {
            e.preventDefault();

            var $form = $(this);
            var $submitBtn = $form.find('button[type="submit"]');
            var originalText = $submitBtn.text();

            $submitBtn.prop('disabled', true).text('<?php _e('Saving...', 'import-products'); ?>');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'import_products_save_settings',
                    nonce: '<?php echo wp_create_nonce('import_products_nonce'); ?>',
                    email_notifications_enabled: $form.find('input[name="email_notifications_enabled"]').is(':checked') ? 1 : 0,
                    notify_on_failures: $form.find('input[name="notify_on_failures"]').is(':checked') ? 1 : 0,
                    notify_on_new_products: $form.find('input[name="notify_on_new_products"]').is(':checked') ? 1 : 0,
                    notification_email: $form.find('input[name="notification_email"]').val()
                },
                success: function(response) {
                    if (response.success) {
                        $('#settings-message').html(
                            '<div class="notice notice-success is-dismissible">' +
                            '<p>' + response.data.message + '</p>' +
                            '</div>'
                        ).show();

                        // Hide message after 5 seconds
                        setTimeout(function() {
                            $('#settings-message').fadeOut();
                        }, 5000);
                    } else {
                        $('#settings-message').html(
                            '<div class="notice notice-error is-dismissible">' +
                            '<p><?php _e('Error saving settings:', 'import-products'); ?> ' + response.data + '</p>' +
                            '</div>'
                        ).show();
                    }
                },
                error: function() {
                    $('#settings-message').html(
                        '<div class="notice notice-error is-dismissible">' +
                        '<p><?php _e('An error occurred while saving settings.', 'import-products'); ?></p>' +
                        '</div>'
                    ).show();
                },
                complete: function() {
                    $submitBtn.prop('disabled', false).text(originalText);
                }
            });
        });
    });
</script>