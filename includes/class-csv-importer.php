<?php

if (!defined('ABSPATH')) {
    exit;
}

class ImportProducts_CSV_Importer
{

    private static $instance = null;
    private $current_import_file = null; // Track current file being imported

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        // Hook into WordPress actions if needed
    }

    /**
     * Import products from CSV file
     */
    public function import_csv($file_path, $is_initial = false)
    {
        // Set current import file for logging
        $this->current_import_file = basename($file_path);

        $this->log_detailed("Starting CSV import", 'info', array(
            'file' => basename($file_path),
            'is_initial' => $is_initial,
            'file_exists' => file_exists($file_path),
            'file_size' => file_exists($file_path) ? filesize($file_path) : 0
        ));

        if (!file_exists($file_path)) {
            $this->log_detailed("CSV file not found", 'error', array('file_path' => $file_path));
            $this->send_failure_notification("CSV file not found: " . basename($file_path));
            return new WP_Error('file_not_found', __('CSV file not found.', 'import-products'));
        }

        $results = array(
            'imported' => 0,
            'updated' => 0,
            'failed' => 0,
            'errors' => array()
        );

        // Open CSV file
        if (($handle = fopen($file_path, "r")) !== FALSE) {
            $header = fgetcsv($handle); // Get header row
            $this->log_detailed("CSV header parsed", 'info', array(
                'header_count' => count($header),
                'first_10_columns' => array_slice($header, 0, 10)
            ));

            // Validate CSV structure
            $validation_result = $this->validate_csv_structure($header);
            if (is_wp_error($validation_result)) {
                fclose($handle);
                $this->log_detailed("CSV structure validation failed", 'error', array(
                    'error' => $validation_result->get_error_message(),
                    'header' => $header
                ));
                $this->send_failure_notification("Invalid CSV structure in " . basename($file_path) . ": " . $validation_result->get_error_message());
                return $validation_result;
            }

            $row_number = 1;
            $processed_skus = array();

            while (($data = fgetcsv($handle)) !== FALSE) {
                $row_number++;

                try {
                    $product_data = $this->parse_csv_row($header, $data);

                    if ($product_data) {
                        $sku = $product_data['sku'];

                        $this->log_detailed("Processing product row {$row_number}", 'info', array(
                            'sku' => $sku,
                            'name' => $product_data['name'],
                            'brand' => $product_data['brand_name'],
                            'size' => $product_data['size'],
                            'color' => $product_data['color'],
                            'stock' => $product_data['stock'],
                            'price' => $product_data['price']
                        ));

                        // Track processed SKUs
                        if (!isset($processed_skus[$sku])) {
                            $processed_skus[$sku] = 0;
                        }
                        $processed_skus[$sku]++;

                        $result = $this->process_product($product_data, $is_initial);

                        if (is_wp_error($result)) {
                            $results['failed']++;
                            $results['errors'][] = "Row {$row_number}: " . $result->get_error_message();
                            $this->log_detailed("Product processing failed for row {$row_number}", 'error', array(
                                'error' => $result->get_error_message(),
                                'sku' => $sku
                            ));
                        } else {
                            if ($result === 'updated') {
                                $results['updated']++;
                                $this->log_detailed("Product updated for row {$row_number}", 'success', array('sku' => $sku));
                            } elseif (is_numeric($result)) {
                                // Product was created, $result is the product ID
                                $results['imported']++;
                                $this->log_detailed("Product created for row {$row_number}", 'success', array(
                                    'sku' => $sku,
                                    'product_id' => $result
                                ));

                                // Send new product notification if it's not an initial import
                                if (!$is_initial) {
                                    $this->send_new_product_notification($result, $product_data);
                                }
                            }
                        }
                    } else {
                        $this->log_detailed("Skipping invalid row {$row_number}", 'warning', array(
                            'data_count' => count($data),
                            'header_count' => count($header)
                        ));
                    }
                } catch (Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = "Row {$row_number}: " . $e->getMessage();
                    $this->log_detailed("Exception in row {$row_number}", 'error', array(
                        'exception' => $e->getMessage(),
                        'line' => $e->getLine(),
                        'file' => $e->getFile()
                    ));
                }
            }

            fclose($handle);

            $this->log_detailed("SKU processing summary", 'info', array(
                'unique_skus' => count($processed_skus),
                'total_rows' => $row_number - 1,
                'sku_counts' => $processed_skus
            ));
        }

        $this->log_detailed("CSV import completed", 'info', $results);

        // Send notification if there were errors during import
        if (!empty($results['errors'])) {
            $error_summary = sprintf(
                __('Import completed with %d errors out of %d total products processed', 'import-products'),
                $results['failed'],
                ($results['imported'] + $results['updated'] + $results['failed'])
            );
            $this->send_failure_notification($error_summary . "\n\nFirst few errors:\n" . implode("\n", array_slice($results['errors'], 0, 5)));
        }

        // Log the import
        $this->log_import(basename($file_path), $results);

        // Clear current import file
        $this->current_import_file = null;

        return $results;
    }

    /**
     * Parse CSV row into product data
     */
    private function parse_csv_row($header, $data)
    {
        if (count($header) !== count($data)) {
            throw new Exception('Header and data column count mismatch');
        }

        $row_data = array_combine($header, $data);

        // Handle scientific notation in CodArticolo (SKU Variable)
        $sku_variable = $this->clean_string($row_data['CodArticolo'] ?? '');
        if (strpos($sku_variable, 'E+') !== false || strpos($sku_variable, 'e+') !== false) {
            // Convert scientific notation to full number
            $sku_variable = number_format((float)$sku_variable, 0, '', '');
            $this->log_detailed("Converted scientific notation SKU", 'info', array(
                'original' => $row_data['CodArticolo'],
                'converted' => $sku_variable
            ));
        }

        // Also handle scientific notation in BarCode if present
        $barcode = $this->clean_string($row_data['BarCode'] ?? '');
        if (strpos($barcode, 'E+') !== false || strpos($barcode, 'e+') !== false) {
            $barcode = number_format((float)$barcode, 0, '', '');
        }

        // Extract key product information based on exact field mapping requirements
        $product_data = array(
            'sku' => $this->clean_string($row_data['Modello'] ?? ''), // SKU Main Product
            'sku_variable' => $sku_variable, // SKU Variable (fixed for scientific notation)
            'name' => $this->clean_string($row_data['DSArticoloAgg'] ?? ''), // Product Name
            'description' => $this->clean_string($row_data['ArticoloDescrizionePers'] ?? ''), // Product Description
            'brand_name' => $this->clean_string($row_data['DSLinea'] ?? ''), // Brand Name
            'brand_id' => $this->clean_string($row_data['IGULinea'] ?? ''), // Brand ID
            'parent_category' => $this->clean_string($row_data['DSRepartoWeb'] ?? ''), // Parent Category
            'category' => $this->clean_string($row_data['DSCategoriaMerceologicaWeb'] ?? ''), // Category
            'gender' => $this->clean_string($row_data['DSSessoWeb'] ?? ''), // Gender (main Category)
            'price' => floatval($row_data['PrezzoIvato'] ?? 0), // Price
            'regular_price' => floatval($row_data['PrezzoIvato'] ?? 0),
            'stock' => intval($row_data['Disponibilita'] ?? 0), // Quantity
            'weight' => floatval($row_data['Peso'] ?? 0), // Product Weight
            'model_no' => $this->clean_string($row_data['Modello'] ?? ''), // Attributes 1: Model No.
            'size' => $this->clean_string($row_data['Taglia'] ?? ''), // Attributes 2: Size
            'color' => $this->clean_string($row_data['DSColoreWeb'] ?? ''), // Attributes 3: Color
            'material' => $this->clean_string($row_data['DSMateriale'] ?? ''), // Attributes 4: Material
            'season' => $this->clean_string($row_data['DSStagioneWeb'] ?? $row_data['DSStagione'] ?? ''),
            'images' => $this->extract_images($row_data),
            'external_code' => $this->clean_string($row_data['CodEsterno'] ?? ''),
            'barcode' => $barcode,
        );

        // Skip if no SKU or name
        if (empty($product_data['sku']) || empty($product_data['name'])) {
            return false;
        }

        return $product_data;
    }

    /**
     * Process individual product (create or update)
     */
    private function process_product($product_data, $is_initial = false)
    {
        // Check if product exists by SKU (main product SKU)
        $existing_product = wc_get_product_id_by_sku($product_data['sku']);

        if ($existing_product) {
            // Check if this is a variation by SKU Variable
            if (!empty($product_data['sku_variable']) && $product_data['sku_variable'] !== $product_data['sku']) {
                return $this->create_or_update_variation($existing_product, $product_data);
            } else {
                // Update existing main product
                return $this->update_product($existing_product, $product_data);
            }
        } else {
            // Create new product (variable if has variations, simple otherwise)
            return $this->create_product($product_data);
        }
    }

    /**
     * Create new product
     */
    private function create_product($product_data)
    {
        // Determine if this should be a variable product
        $has_variations = !empty($product_data['sku_variable']) && $product_data['sku_variable'] !== $product_data['sku'];
        $has_variation_attributes = !empty($product_data['size']) || !empty($product_data['color']);

        if ($has_variations || $has_variation_attributes) {
            return $this->create_variable_product($product_data);
        } else {
            return $this->create_simple_product($product_data);
        }
    }

    /**
     * Create simple product
     */
    private function create_simple_product($product_data)
    {
        $product = new WC_Product_Simple();

        // Set basic product data
        $product->set_name($product_data['name']);
        $product->set_sku($product_data['sku']);
        $product->set_description($product_data['description']);
        $product->set_regular_price($product_data['regular_price']);
        $product->set_price($product_data['price']);
        $product->set_weight($product_data['weight']);

        // Set stock management
        $product->set_manage_stock(true);
        $product->set_stock_quantity($product_data['stock']);
        $product->set_stock_status($product_data['stock'] > 0 ? 'instock' : 'outofstock');

        // Set categories
        $this->set_product_categories($product, $product_data);

        // Set attributes (for simple products, these are just informational)
        $this->set_product_attributes($product, $product_data);

        // Set images
        $this->set_product_images($product, $product_data['images']);

        // Save product
        $product_id = $product->save();

        if ($product_id) {
            $this->set_product_terms_and_meta($product_id, $product_data);
            return $product_id; // Return product ID for notifications
        }

        return new WP_Error('product_creation_failed', __('Failed to create simple product.', 'import-products'));
    }

    /**
     * Create variable product
     */
    private function create_variable_product($product_data)
    {
        $this->log_detailed("Creating variable product", 'info', array(
            'sku' => $product_data['sku'],
            'name' => $product_data['name'],
            'has_size' => !empty($product_data['size']),
            'has_color' => !empty($product_data['color']),
            'sku_variable' => $product_data['sku_variable']
        ));

        $product = new WC_Product_Variable();

        // Set basic product data
        $product->set_name($product_data['name']);
        $product->set_sku($product_data['sku']);
        $product->set_description($product_data['description']);
        $product->set_weight($product_data['weight']);

        // Set categories
        $this->set_product_categories($product, $product_data);

        // Set variation attributes
        $this->set_variable_product_attributes($product, $product_data);

        // Set images
        $this->set_product_images($product, $product_data['images']);

        // Save parent product
        $product_id = $product->save();

        if ($product_id) {
            // Set brands and meta data
            $this->set_brands_and_meta($product_id, $product_data);

            // Create the first variation
            $this->create_product_variation($product_id, $product_data);

            return $product_id; // Return product ID for notifications
        }

        return new WP_Error('variable_product_creation_failed', __('Failed to create variable product.', 'import-products'));
    }

    /**
     * Set product terms and meta data
     */
    private function set_product_terms_and_meta($product_id, $product_data)
    {
        // Set brand
        if (!empty($product_data['brand_name'])) {
            $brand_id = $this->get_or_create_brand($product_data['brand_name']);
            if ($brand_id) {
                wp_set_object_terms($product_id, array($brand_id), 'product_brand');
            }
        }

        // Set attribute terms
        $this->log_detailed("Setting attribute terms for product {$product_id}", 'info', array(
            'model_no' => $product_data['model_no'],
            'size' => $product_data['size'],
            'color' => $product_data['color'],
            'material' => $product_data['material'],
            'season' => $product_data['season']
        ));

        $saved_product = wc_get_product($product_id);
        if (!empty($product_data['model_no'])) {
            $this->set_attribute_terms($saved_product, 'pa_model-no', array($product_data['model_no']));
        }
        if (!empty($product_data['size'])) {
            $this->set_attribute_terms($saved_product, 'pa_size', array($product_data['size']));
        }
        if (!empty($product_data['color'])) {
            $this->set_attribute_terms($saved_product, 'pa_color', array($product_data['color']));
        }
        if (!empty($product_data['material'])) {
            $this->set_attribute_terms($saved_product, 'pa_material', array($product_data['material']));
        }
        if (!empty($product_data['season'])) {
            $this->set_attribute_terms($saved_product, 'pa_season', array($product_data['season']));
        }

        // Force save attributes to product
        $saved_product->save();

        // Add custom meta data
        update_post_meta($product_id, '_sku_variable', $product_data['sku_variable']);
        update_post_meta($product_id, '_brand_name', $product_data['brand_name']);
        update_post_meta($product_id, '_brand_id', $product_data['brand_id']);
        update_post_meta($product_id, '_model_no', $product_data['model_no']);
        update_post_meta($product_id, '_external_code', $product_data['external_code']);
    }

    /**
     * Set variable product attributes
     */
    private function set_variable_product_attributes($product, $product_data)
    {
        $attributes = array();

        // First, ensure all global attributes exist and create terms
        $this->ensure_attributes_and_terms($product_data);

        // Add size attribute if present (for variations)
        if (!empty($product_data['size'])) {
            $attribute = new WC_Product_Attribute();
            $attribute->set_id(wc_attribute_taxonomy_id_by_name('pa_size'));
            $attribute->set_name('pa_size');
            $attribute->set_options(array($product_data['size']));
            $attribute->set_position(0);
            $attribute->set_visible(true);
            $attribute->set_variation(true);
            $attributes['pa_size'] = $attribute;
        }

        // Add color attribute if present (for variations)
        if (!empty($product_data['color'])) {
            $attribute = new WC_Product_Attribute();
            $attribute->set_id(wc_attribute_taxonomy_id_by_name('pa_color'));
            $attribute->set_name('pa_color');
            $attribute->set_options(array($product_data['color']));
            $attribute->set_position(1);
            $attribute->set_visible(true);
            $attribute->set_variation(true);
            $attributes['pa_color'] = $attribute;
        }

        // Add non-variation attributes
        if (!empty($product_data['model_no'])) {
            $attribute = new WC_Product_Attribute();
            $attribute->set_id(wc_attribute_taxonomy_id_by_name('pa_model-no'));
            $attribute->set_name('pa_model-no');
            $attribute->set_options(array($product_data['model_no']));
            $attribute->set_position(2);
            $attribute->set_visible(true);
            $attribute->set_variation(false);
            $attributes['pa_model-no'] = $attribute;
        }

        if (!empty($product_data['material'])) {
            $attribute = new WC_Product_Attribute();
            $attribute->set_id(wc_attribute_taxonomy_id_by_name('pa_material'));
            $attribute->set_name('pa_material');
            $attribute->set_options(array($product_data['material']));
            $attribute->set_position(3);
            $attribute->set_visible(true);
            $attribute->set_variation(false);
            $attributes['pa_material'] = $attribute;
        }

        if (!empty($product_data['season'])) {
            $attribute = new WC_Product_Attribute();
            $attribute->set_id(wc_attribute_taxonomy_id_by_name('pa_season'));
            $attribute->set_name('pa_season');
            $attribute->set_options(array($product_data['season']));
            $attribute->set_position(4);
            $attribute->set_visible(true);
            $attribute->set_variation(false);
            $attributes['pa_season'] = $attribute;
        }

        $product->set_attributes($attributes);

        $this->log_detailed("Variable product attributes set", 'info', array(
            'attributes_count' => count($attributes),
            'attribute_names' => array_keys($attributes)
        ));
    }

    /**
     * Ensure attributes and terms exist before setting them on products
     */
    private function ensure_attributes_and_terms($product_data)
    {
        // Create attributes if they don't exist
        $attributes_to_create = array(
            'pa_size' => 'Size',
            'pa_color' => 'Color',
            'pa_model-no' => 'Model No.',
            'pa_material' => 'Material',
            'pa_season' => 'Season'
        );

        foreach ($attributes_to_create as $attr_name => $attr_label) {
            $this->get_or_create_attribute($attr_name, $attr_label);
        }

        // Create terms for each attribute
        if (!empty($product_data['size'])) {
            $this->create_attribute_term('pa_size', $product_data['size']);
        }
        if (!empty($product_data['color'])) {
            $this->create_attribute_term('pa_color', $product_data['color']);
        }
        if (!empty($product_data['model_no'])) {
            $this->create_attribute_term('pa_model-no', $product_data['model_no']);
        }
        if (!empty($product_data['material'])) {
            $this->create_attribute_term('pa_material', $product_data['material']);
        }
        if (!empty($product_data['season'])) {
            $this->create_attribute_term('pa_season', $product_data['season']);
        }
    }

    /**
     * Create attribute term if it doesn't exist
     */
    private function create_attribute_term($attribute_name, $term_name)
    {
        $term_name = trim($term_name);
        if (empty($term_name)) {
            return false;
        }

        // Check if term already exists
        $term = get_term_by('name', $term_name, $attribute_name);
        if ($term) {
            $this->log_detailed("Using existing attribute term", 'info', array(
                'attribute' => $attribute_name,
                'term' => $term_name,
                'term_id' => $term->term_id
            ));
            return $term->term_id;
        }

        // Create new term
        $term_result = wp_insert_term($term_name, $attribute_name);
        if (!is_wp_error($term_result)) {
            $this->log_detailed("Created attribute term", 'success', array(
                'attribute' => $attribute_name,
                'term' => $term_name,
                'term_id' => $term_result['term_id']
            ));
            return $term_result['term_id'];
        } else {
            $this->log_detailed("Failed to create attribute term", 'error', array(
                'attribute' => $attribute_name,
                'term' => $term_name,
                'error' => $term_result->get_error_message()
            ));
            return false;
        }
    }

    /**
     * Set brands and meta data for variable products (without interfering with attributes)
     */
    private function set_brands_and_meta($product_id, $product_data)
    {
        // Set brand
        if (!empty($product_data['brand_name'])) {
            $brand_id = $this->get_or_create_brand($product_data['brand_name']);
            if ($brand_id) {
                wp_set_object_terms($product_id, array($brand_id), 'product_brand');
                $this->log_detailed("Brand set for product", 'success', array(
                    'product_id' => $product_id,
                    'brand_name' => $product_data['brand_name'],
                    'brand_id' => $brand_id
                ));
            }
        }

        // Add custom meta data
        update_post_meta($product_id, '_sku_variable', $product_data['sku_variable']);
        update_post_meta($product_id, '_brand_name', $product_data['brand_name']);
        update_post_meta($product_id, '_brand_id', $product_data['brand_id']);
        update_post_meta($product_id, '_model_no', $product_data['model_no']);
        update_post_meta($product_id, '_external_code', $product_data['external_code']);

        $this->log_detailed("Meta data set for product", 'info', array(
            'product_id' => $product_id,
            'meta_fields' => array(
                '_sku_variable' => $product_data['sku_variable'],
                '_brand_name' => $product_data['brand_name'],
                '_model_no' => $product_data['model_no']
            )
        ));
    }

    /**
     * Create product variation
     */
    private function create_product_variation($parent_id, $product_data)
    {
        $this->log_detailed("Creating product variation", 'info', array(
            'parent_id' => $parent_id,
            'sku_variable' => $product_data['sku_variable'],
            'size' => $product_data['size'],
            'color' => $product_data['color']
        ));

        $variation = new WC_Product_Variation();
        $variation->set_parent_id($parent_id);

        // Set variation SKU
        if (!empty($product_data['sku_variable'])) {
            $variation->set_sku($product_data['sku_variable']);
        }

        // Set price and stock
        $variation->set_regular_price($product_data['regular_price']);
        $variation->set_price($product_data['price']);
        $variation->set_manage_stock(true);
        $variation->set_stock_quantity($product_data['stock']);
        $variation->set_stock_status($product_data['stock'] > 0 ? 'instock' : 'outofstock');

        // Set variation attributes - these must match the parent product's variation attributes
        $variation_attributes = array();

        if (!empty($product_data['size'])) {
            // Ensure the term exists first
            $size_term_id = $this->create_attribute_term('pa_size', $product_data['size']);
            $size_term = get_term($size_term_id, 'pa_size');
            if ($size_term && !is_wp_error($size_term)) {
                $variation_attributes['pa_size'] = $size_term->slug;
                $this->log_detailed("Set size attribute for variation", 'info', array(
                    'term_slug' => $size_term->slug,
                    'term_name' => $product_data['size']
                ));
            }
        }

        if (!empty($product_data['color'])) {
            // Ensure the term exists first
            $color_term_id = $this->create_attribute_term('pa_color', $product_data['color']);
            $color_term = get_term($color_term_id, 'pa_color');
            if ($color_term && !is_wp_error($color_term)) {
                $variation_attributes['pa_color'] = $color_term->slug;
                $this->log_detailed("Set color attribute for variation", 'info', array(
                    'term_slug' => $color_term->slug,
                    'term_name' => $product_data['color']
                ));
            }
        }

        $variation->set_attributes($variation_attributes);

        // Save variation
        $variation_id = $variation->save();

        if ($variation_id) {
            $this->log_detailed("Variation created successfully", 'success', array(
                'variation_id' => $variation_id,
                'parent_id' => $parent_id,
                'attributes' => $variation_attributes,
                'sku' => $product_data['sku_variable']
            ));

            // Sync the parent product to update price ranges and stock status
            WC_Product_Variable::sync($parent_id);
        } else {
            $this->log_detailed("Failed to create variation", 'error', array(
                'parent_id' => $parent_id,
                'product_data' => $product_data
            ));
        }

        return $variation_id;
    }

    /**
     * Create or update variation
     */
    private function create_or_update_variation($parent_id, $product_data)
    {
        // First try to find variation by SKU
        $existing_variation = wc_get_product_id_by_sku($product_data['sku_variable']);

        if ($existing_variation) {
            // Update existing variation
            $this->log_detailed("Updating existing variation", 'info', array(
                'variation_id' => $existing_variation,
                'parent_id' => $parent_id,
                'sku_variable' => $product_data['sku_variable']
            ));

            $variation = wc_get_product($existing_variation);
            if ($variation && $variation->get_parent_id() == $parent_id) {
                $variation->set_regular_price($product_data['regular_price']);
                $variation->set_price($product_data['price']);
                $variation->set_stock_quantity($product_data['stock']);
                $variation->set_stock_status($product_data['stock'] > 0 ? 'instock' : 'outofstock');
                $variation->save();

                // Sync parent product
                WC_Product_Variable::sync($parent_id);
                return 'updated';
            }
        }

        // If no existing variation found by SKU, try to find by attributes
        $parent_product = wc_get_product($parent_id);
        if ($parent_product && $parent_product->is_type('variable')) {
            $variations = $parent_product->get_children();

            foreach ($variations as $variation_id) {
                $variation = wc_get_product($variation_id);
                if (!$variation) continue;

                $attributes = $variation->get_attributes();
                $size_match = true;
                $color_match = true;

                // Check if size matches
                if (!empty($product_data['size'])) {
                    $size_term = get_term_by('name', $product_data['size'], 'pa_size');
                    $size_slug = $size_term ? $size_term->slug : sanitize_title($product_data['size']);
                    $size_match = isset($attributes['pa_size']) && $attributes['pa_size'] === $size_slug;
                }

                // Check if color matches
                if (!empty($product_data['color'])) {
                    $color_term = get_term_by('name', $product_data['color'], 'pa_color');
                    $color_slug = $color_term ? $color_term->slug : sanitize_title($product_data['color']);
                    $color_match = isset($attributes['pa_color']) && $attributes['pa_color'] === $color_slug;
                }

                if ($size_match && $color_match) {
                    // Found matching variation, update it
                    $this->log_detailed("Found existing variation by attributes", 'info', array(
                        'variation_id' => $variation_id,
                        'size' => $product_data['size'],
                        'color' => $product_data['color']
                    ));

                    // Update SKU if it's different
                    if ($variation->get_sku() !== $product_data['sku_variable']) {
                        $variation->set_sku($product_data['sku_variable']);
                    }

                    $variation->set_regular_price($product_data['regular_price']);
                    $variation->set_price($product_data['price']);
                    $variation->set_stock_quantity($product_data['stock']);
                    $variation->set_stock_status($product_data['stock'] > 0 ? 'instock' : 'outofstock');
                    $variation->save();

                    // Sync parent product
                    WC_Product_Variable::sync($parent_id);
                    return 'updated';
                }
            }
        }

        // Create new variation if none found
        $this->log_detailed("Creating new variation", 'info', array(
            'parent_id' => $parent_id,
            'sku_variable' => $product_data['sku_variable'],
            'size' => $product_data['size'],
            'color' => $product_data['color']
        ));

        $this->create_product_variation($parent_id, $product_data);
        return 'created';
    }

    /**
     * Update existing product
     */
    private function update_product($product_id, $product_data)
    {
        $product = wc_get_product($product_id);

        if (!$product) {
            return new WP_Error('product_not_found', __('Product not found.', 'import-products'));
        }

        // Update price
        $product->set_regular_price($product_data['regular_price']);
        $product->set_price($product_data['price']);

        // Update stock
        $product->set_stock_quantity($product_data['stock']);
        $product->set_stock_status($product_data['stock'] > 0 ? 'instock' : 'outofstock');

        // Update other fields if they have values
        if (!empty($product_data['description'])) {
            $product->set_description($product_data['description']);
        }

        if ($product_data['weight'] > 0) {
            $product->set_weight($product_data['weight']);
        }

        // Update categories
        $this->set_product_categories($product, $product_data);

        // Update attributes
        $this->set_product_attributes($product, $product_data);

        // Update images if provided
        if (!empty($product_data['images'])) {
            $this->set_product_images($product, $product_data['images']);
        }

        // Save product
        $result = $product->save();

        // Set brand and attribute terms after product is saved
        if ($result) {
            // Set brand
            if (!empty($product_data['brand_name'])) {
                $brand_id = $this->get_or_create_brand($product_data['brand_name']);
                if ($brand_id) {
                    wp_set_object_terms($product_id, array($brand_id), 'product_brand');
                }
            }

            // Set attribute terms
            if (!empty($product_data['model_no'])) {
                $this->set_attribute_terms($product, 'pa_model-no', array($product_data['model_no']));
            }
            if (!empty($product_data['size'])) {
                $this->set_attribute_terms($product, 'pa_size', array($product_data['size']));
            }
            if (!empty($product_data['color'])) {
                $this->set_attribute_terms($product, 'pa_color', array($product_data['color']));
            }
            if (!empty($product_data['material'])) {
                $this->set_attribute_terms($product, 'pa_material', array($product_data['material']));
            }
            if (!empty($product_data['season'])) {
                $this->set_attribute_terms($product, 'pa_season', array($product_data['season']));
            }

            // Update custom meta data based on field mapping
            update_post_meta($product_id, '_sku_variable', $product_data['sku_variable']);
            update_post_meta($product_id, '_brand_name', $product_data['brand_name']);
            update_post_meta($product_id, '_brand_id', $product_data['brand_id']);
            update_post_meta($product_id, '_model_no', $product_data['model_no']);
            update_post_meta($product_id, '_external_code', $product_data['external_code']);

            return 'updated';
        }

        return new WP_Error('product_update_failed', __('Failed to update product.', 'import-products'));
    }

    /**
     * Set product categories
     */
    private function set_product_categories($product, $product_data)
    {
        $category_ids = array();

        // Create/get gender category (main category)
        if (!empty($product_data['gender'])) {
            $gender_id = $this->get_or_create_category($product_data['gender']);
            if ($gender_id) {
                $category_ids[] = $gender_id;
            }
        }

        // Create/get parent category
        if (!empty($product_data['parent_category'])) {
            $parent_id = !empty($category_ids) ? $category_ids[0] : 0;
            $parent_category_id = $this->get_or_create_category($product_data['parent_category'], $parent_id);
            if ($parent_category_id) {
                $category_ids[] = $parent_category_id;
            }
        }

        // Create/get category (subcategory)
        if (!empty($product_data['category'])) {
            $parent_id = !empty($category_ids) ? end($category_ids) : 0;
            $category_id = $this->get_or_create_category($product_data['category'], $parent_id);
            if ($category_id) {
                $category_ids[] = $category_id;
            }
        }

        if (!empty($category_ids)) {
            $product->set_category_ids($category_ids);
        }
    }

    /**
     * Get or create product category
     */
    private function get_or_create_category($name, $parent_id = 0)
    {
        $term = get_term_by('name', $name, 'product_cat');

        if ($term) {
            return $term->term_id;
        }

        // Create new category
        $result = wp_insert_term($name, 'product_cat', array('parent' => $parent_id));

        if (is_wp_error($result)) {
            return false;
        }

        return $result['term_id'];
    }

    /**
     * Set product attributes based on exact field mapping requirements
     */
    private function set_product_attributes($product, $product_data)
    {
        $attributes = array();

        // Create/get brand taxonomy
        $this->create_brand_taxonomy($product_data);

        // Attributes 1: Model No. (Global Attribute)
        if (!empty($product_data['model_no'])) {
            $this->get_or_create_attribute('pa_model-no', 'Model No.');
            $attributes['pa_model-no'] = array(
                'name' => 'pa_model-no',
                'value' => '',
                'is_visible' => 1,
                'is_variation' => 0,
                'is_taxonomy' => 1
            );
        }

        // Attributes 2: Size (Global Attribute for variations)
        if (!empty($product_data['size'])) {
            $this->get_or_create_attribute('pa_size', 'Size');
            $attributes['pa_size'] = array(
                'name' => 'pa_size',
                'value' => '',
                'is_visible' => 1,
                'is_variation' => 1,
                'is_taxonomy' => 1
            );
        }

        // Attributes 3: Color (Global Attribute for variations)
        if (!empty($product_data['color'])) {
            $this->get_or_create_attribute('pa_color', 'Color');
            $attributes['pa_color'] = array(
                'name' => 'pa_color',
                'value' => '',
                'is_visible' => 1,
                'is_variation' => 1,
                'is_taxonomy' => 1
            );
        }

        // Attributes 4: Material (Global Attribute)
        if (!empty($product_data['material'])) {
            $this->get_or_create_attribute('pa_material', 'Material');
            $attributes['pa_material'] = array(
                'name' => 'pa_material',
                'value' => '',
                'is_visible' => 1,
                'is_variation' => 0,
                'is_taxonomy' => 1
            );
        }

        // Additional attributes for internal use
        if (!empty($product_data['season'])) {
            $this->get_or_create_attribute('pa_season', 'Season');
            $attributes['pa_season'] = array(
                'name' => 'pa_season',
                'value' => '',
                'is_visible' => 1,
                'is_variation' => 0,
                'is_taxonomy' => 1
            );
        }

        if (!empty($attributes)) {
            $product->set_attributes($attributes);
        }
    }

    /**
     * Create or get WooCommerce product attribute
     */
    private function get_or_create_attribute($attribute_name, $attribute_label)
    {
        global $wpdb;

        $attribute_id = wc_attribute_taxonomy_id_by_name($attribute_name);

        if (!$attribute_id) {
            $this->log_detailed("Creating new attribute", 'info', array(
                'name' => $attribute_label,
                'slug' => $attribute_name
            ));

            $attribute_id = wc_create_attribute(array(
                'name' => $attribute_label,
                'slug' => $attribute_name,
                'type' => 'select',
                'order_by' => 'menu_order',
                'has_archives' => true,
            ));

            if (is_wp_error($attribute_id)) {
                $this->log_detailed("Failed to create attribute", 'error', array(
                    'name' => $attribute_label,
                    'error' => $attribute_id->get_error_message()
                ));
                return false;
            }

            // Clear cache and register taxonomy
            delete_transient('wc_attribute_taxonomies');
            WC_Cache_Helper::invalidate_cache_group('woocommerce-attributes');

            // Register the taxonomy immediately
            register_taxonomy(
                $attribute_name,
                apply_filters('woocommerce_taxonomy_objects_' . $attribute_name, array('product')),
                apply_filters('woocommerce_taxonomy_args_' . $attribute_name, array(
                    'labels' => array(
                        'name' => $attribute_label,
                    ),
                    'hierarchical' => false,
                    'show_ui' => false,
                    'query_var' => true,
                    'rewrite' => false,
                ))
            );

            $this->log_detailed("Attribute created successfully", 'success', array(
                'attribute_id' => $attribute_id,
                'name' => $attribute_label
            ));
        }

        return $attribute_id;
    }

    /**
     * Set attribute terms for product
     */
    private function set_attribute_terms($product, $attribute_name, $terms)
    {
        $product_id = $product->get_id();

        $this->log_detailed("Setting attribute terms", 'info', array(
            'product_id' => $product_id,
            'attribute_name' => $attribute_name,
            'terms' => $terms
        ));

        // If product is not saved yet, we'll set terms after save
        if (!$product_id) {
            $this->log_detailed("Product not saved yet, skipping attribute terms", 'warning');
            return;
        }

        // First ensure the attribute exists
        $attribute_id = wc_attribute_taxonomy_id_by_name($attribute_name);
        if (!$attribute_id) {
            $this->log_detailed("Attribute taxonomy not found", 'error', array(
                'attribute_name' => $attribute_name
            ));
            return;
        }

        $term_ids = array();
        foreach ($terms as $term_name) {
            $term_name = trim($term_name);
            if (empty($term_name)) {
                continue;
            }

            // Check if term exists
            $term = get_term_by('name', $term_name, $attribute_name);
            if (!$term) {
                // Create new term
                $this->log_detailed("Creating new term", 'info', array(
                    'term_name' => $term_name,
                    'attribute' => $attribute_name
                ));

                $term_result = wp_insert_term($term_name, $attribute_name);
                if (!is_wp_error($term_result)) {
                    $term_ids[] = $term_result['term_id'];
                    $this->log_detailed("Term created successfully", 'success', array(
                        'term_id' => $term_result['term_id'],
                        'term_name' => $term_name
                    ));
                } else {
                    $this->log_detailed("Failed to create term", 'error', array(
                        'term_name' => $term_name,
                        'error' => $term_result->get_error_message()
                    ));
                }
            } else {
                $term_ids[] = $term->term_id;
                $this->log_detailed("Using existing term", 'info', array(
                    'term_id' => $term->term_id,
                    'term_name' => $term_name
                ));
            }
        }

        if (!empty($term_ids)) {
            // Set the terms for the product
            $result = wp_set_object_terms($product_id, $term_ids, $attribute_name);

            if (is_wp_error($result)) {
                $this->log_detailed("Failed to set object terms", 'error', array(
                    'product_id' => $product_id,
                    'term_ids' => $term_ids,
                    'attribute' => $attribute_name,
                    'error' => $result->get_error_message()
                ));
            } else {
                $this->log_detailed("Set object terms result", 'success', array(
                    'product_id' => $product_id,
                    'term_ids' => $term_ids,
                    'attribute' => $attribute_name,
                    'result' => $result
                ));

                // Also update the product's attribute data
                $product_attributes = $product->get_attributes();
                if (!isset($product_attributes[$attribute_name])) {
                    $product_attributes[$attribute_name] = array(
                        'name' => $attribute_name,
                        'value' => '',
                        'position' => count($product_attributes),
                        'is_visible' => 1,
                        'is_variation' => in_array($attribute_name, array('pa_size', 'pa_color')) ? 1 : 0,
                        'is_taxonomy' => 1
                    );
                    $product->set_attributes($product_attributes);
                    $product->save();
                }
            }
        } else {
            $this->log_detailed("No term IDs to set", 'warning', array(
                'attribute' => $attribute_name,
                'terms' => $terms
            ));
        }
    }

    /**
     * Create brand taxonomy and assign to product
     */
    private function create_brand_taxonomy($product_data)
    {
        if (empty($product_data['brand_name'])) {
            return;
        }

        // Create brand taxonomy if it doesn't exist
        if (!taxonomy_exists('product_brand')) {
            register_taxonomy('product_brand', 'product', array(
                'label' => __('Brands', 'import-products'),
                'hierarchical' => false,
                'public' => true,
                'show_ui' => true,
                'show_admin_column' => true,
                'show_in_nav_menus' => true,
                'show_tagcloud' => true,
                'rewrite' => array('slug' => 'brand'),
            ));
        }

        return $this->get_or_create_brand($product_data['brand_name']);
    }

    /**
     * Get or create brand term
     */
    private function get_or_create_brand($brand_name)
    {
        $term = get_term_by('name', $brand_name, 'product_brand');

        if (!$term) {
            $result = wp_insert_term($brand_name, 'product_brand');
            if (!is_wp_error($result)) {
                return $result['term_id'];
            }
        } else {
            return $term->term_id;
        }

        return false;
    }

    /**
     * Set product images
     */
    private function set_product_images($product, $image_urls)
    {
        if (empty($image_urls)) {
            return;
        }

        $image_ids = array();

        foreach ($image_urls as $url) {
            if (!empty($url) && filter_var($url, FILTER_VALIDATE_URL)) {
                $attachment_id = $this->upload_image_from_url($url);
                if ($attachment_id) {
                    $image_ids[] = $attachment_id;
                }
            }
        }

        if (!empty($image_ids)) {
            $product->set_image_id($image_ids[0]); // Set first image as featured
            if (count($image_ids) > 1) {
                $product->set_gallery_image_ids(array_slice($image_ids, 1)); // Set rest as gallery
            }
        }
    }

    /**
     * Upload image from URL
     */
    private function upload_image_from_url($url)
    {
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $tmp = download_url($url);

        if (is_wp_error($tmp)) {
            return false;
        }

        $file_array = array(
            'name' => basename($url),
            'tmp_name' => $tmp
        );

        $attachment_id = media_handle_sideload($file_array, 0);

        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            return false;
        }

        return $attachment_id;
    }

    /**
     * Extract image URLs from CSV row
     */
    private function extract_images($row_data)
    {
        $images = array();

        for ($i = 1; $i <= 10; $i++) {
            $key = 'URLImg' . $i;
            if (!empty($row_data[$key])) {
                $images[] = $row_data[$key];
            }
        }

        return $images;
    }

    /**
     * Clean string data
     */
    private function clean_string($string)
    {
        return trim(strip_tags($string));
    }

    /**
     * Find next CSV file to import
     */
    public function get_next_csv_file()
    {
        $last_file = get_option('import_products_last_file', 0);
        $next_file_number = $last_file + 1;

        $csv_file = IMPORT_PRODUCTS_CSV_DIR . $next_file_number . '.csv';

        if (file_exists($csv_file)) {
            return array(
                'file' => $csv_file,
                'number' => $next_file_number
            );
        }

        return false;
    }

    /**
     * Import initial CSV (1.csv)
     */
    public function import_initial_csv()
    {
        // Increase time limit for large imports
        set_time_limit(300); // 5 minutes
        ini_set('memory_limit', '512M');

        $csv_file = IMPORT_PRODUCTS_CSV_DIR . '1.csv';

        if (!file_exists($csv_file)) {
            return new WP_Error('initial_file_not_found', __('Initial CSV file (1.csv) not found.', 'import-products'));
        }

        $result = $this->import_csv($csv_file, true);

        if (!is_wp_error($result)) {
            update_option('import_products_last_file', 1);
            update_option('import_products_status', 'completed');
            update_option('import_products_last_import_time', time());
        }

        return $result;
    }

    /**
     * Import next update CSV
     */
    public function import_next_update()
    {
        // Increase time limit for large imports
        set_time_limit(180); // 3 minutes
        ini_set('memory_limit', '256M');

        $next_file = $this->get_next_csv_file();

        if (!$next_file) {
            return new WP_Error('no_update_file', __('No update file available.', 'import-products'));
        }

        $result = $this->import_csv($next_file['file'], false);

        if (!is_wp_error($result)) {
            update_option('import_products_last_file', $next_file['number']);
            update_option('import_products_status', 'completed');
            update_option('import_products_last_import_time', time());
        }

        return $result;
    }

    /**
     * Log import results
     */
    private function log_import($file_name, $results)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'import_products_log';

        $wpdb->insert(
            $table_name,
            array(
                'file_name' => $file_name,
                'products_imported' => $results['imported'],
                'products_updated' => $results['updated'],
                'products_failed' => $results['failed'],
                'status' => empty($results['errors']) ? 'completed' : 'completed_with_errors',
                'error_message' => !empty($results['errors']) ? implode("\n", $results['errors']) : null
            ),
            array('%s', '%d', '%d', '%d', '%s', '%s')
        );
    }

    /**
     * Enhanced logging for detailed tracking with file-specific logs
     */
    private function log_detailed($message, $level = 'info', $data = null)
    {
        $log_dir = IMPORT_PRODUCTS_PLUGIN_DIR . 'logs/';
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }

        // Create file-specific log name
        $log_filename = 'import-details-' . date('Y-m-d');
        if ($this->current_import_file) {
            // Extract filename without extension for cleaner log names
            $file_base = pathinfo($this->current_import_file, PATHINFO_FILENAME);
            $log_filename .= '-' . $file_base;
        }
        $log_file = $log_dir . $log_filename . '.log';

        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[{$timestamp}] [{$level}] {$message}";

        if ($data) {
            $log_entry .= " | Data: " . print_r($data, true);
        }

        $log_entry .= "\n" . str_repeat('-', 80) . "\n";

        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Validate CSV structure - check for required columns
     */
    private function validate_csv_structure($header)
    {
        // Required columns for the import to work properly
        $required_columns = array(
            'Modello',           // SKU Main Product
            'DSArticoloAgg',     // Product Name
            'PrezzoIvato',       // Price
            'Disponibilita'      // Stock quantity
        );

        // Important columns (will log warning if missing but won't fail)
        $important_columns = array(
            'CodArticolo',       // SKU Variable
            'DSLinea',           // Brand Name
            'DSRepartoWeb',      // Parent Category
            'DSCategoriaMerceologicaWeb', // Category
            'Taglia',            // Size
            'DSColoreWeb'        // Color
        );

        $missing_required = array();
        $missing_important = array();

        // Check required columns
        foreach ($required_columns as $column) {
            if (!in_array($column, $header)) {
                $missing_required[] = $column;
            }
        }

        // Check important columns
        foreach ($important_columns as $column) {
            if (!in_array($column, $header)) {
                $missing_important[] = $column;
            }
        }

        // Log missing important columns as warnings
        if (!empty($missing_important)) {
            $this->log_detailed("CSV structure warning - missing important columns", 'warning', array(
                'missing_important' => $missing_important,
                'available_columns' => $header
            ));
        }

        // Fail if required columns are missing
        if (!empty($missing_required)) {
            return new WP_Error(
                'invalid_csv_structure',
                sprintf(
                    __('CSV file is missing required columns: %s', 'import-products'),
                    implode(', ', $missing_required)
                )
            );
        }

        // Log successful validation
        $this->log_detailed("CSV structure validation passed", 'info', array(
            'total_columns' => count($header),
            'required_found' => count($required_columns),
            'important_found' => count($important_columns) - count($missing_important),
            'missing_important' => $missing_important
        ));

        return true;
    }

    /**
     * Send email notification for import failures
     */
    private function send_failure_notification($error_message)
    {
        // Check if email notifications are enabled
        if (
            !get_option('import_products_email_notifications_enabled', 1) ||
            !get_option('import_products_notify_on_failures', 1)
        ) {
            return;
        }

        $notification_email = get_option('import_products_notification_email', get_option('admin_email'));
        $site_name = get_bloginfo('name');

        $subject = sprintf(__('[%s] Product Import Failed', 'import-products'), $site_name);

        $message = sprintf(
            __('
Hello,

A scheduled product import has failed on your website %s.

Error Details:
%s

Time: %s
File: %s

Please check the import logs in your WordPress admin panel for more details.

Best regards,
Import Products Plugin
        ', 'import-products'),
            $site_name,
            $error_message,
            date('Y-m-d H:i:s'),
            $this->current_import_file ?: 'Unknown'
        );

        wp_mail($notification_email, $subject, $message);

        $this->log_detailed("Failure notification sent", 'info', array(
            'email' => $notification_email,
            'error' => $error_message
        ));
    }

    /**
     * Send email notification for new products added
     */
    private function send_new_product_notification($product_id, $product_data)
    {
        // Check if email notifications are enabled
        if (
            !get_option('import_products_email_notifications_enabled', 1) ||
            !get_option('import_products_notify_on_new_products', 1)
        ) {
            return;
        }

        $notification_email = get_option('import_products_notification_email', get_option('admin_email'));
        $site_name = get_bloginfo('name');

        $subject = sprintf(__('[%s] New Product Added', 'import-products'), $site_name);

        $product_url = admin_url('post.php?post=' . $product_id . '&action=edit');

        $message = sprintf(
            __('
Hello,

A new product has been automatically added to your website %s during the scheduled import.

Product Details:
- Name: %s
- SKU: %s
- Brand: %s
- Price: %s
- Stock: %d

Edit Product: %s

Time: %s
Source File: %s

Best regards,
Import Products Plugin
        ', 'import-products'),
            $site_name,
            $product_data['name'],
            $product_data['sku'],
            $product_data['brand_name'],
            wc_price($product_data['price']),
            $product_data['stock'],
            $product_url,
            date('Y-m-d H:i:s'),
            $this->current_import_file ?: 'Unknown'
        );

        wp_mail($notification_email, $subject, $message);

        $this->log_detailed("New product notification sent", 'info', array(
            'email' => $notification_email,
            'product_id' => $product_id,
            'product_name' => $product_data['name']
        ));
    }
}
