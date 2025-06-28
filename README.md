# Import Products - WooCommerce CSV Import Plugin

A comprehensive WooCommerce plugin that handles automated product data import and updates using CSV files with scheduled 30-minute import cycles.

## Features

-   **Initial Product Import**: Import all products from the initial CSV file (1.csv)
-   **Automated Updates**: Automatically check for and import new CSV files every 30 minutes
-   **Stock & Price Updates**: Update existing product stock levels and prices
-   **New Product Creation**: Automatically create new products found in update files
-   **Category Management**: Automatically create product categories and subcategories
-   **Product Attributes**: Handle product attributes like brand, color, size, material, season, and gender
-   **Image Import**: Download and assign product images from URLs
-   **File-Specific Logging**: Track all import activities with detailed, file-specific logs
-   **Admin Interface**: User-friendly admin panel for monitoring and manual imports
-   **CSV Structure Validation**: Automatically detect and prevent invalid CSV imports
-   **Email Notifications**: Configurable email alerts for import failures and new products
-   **Complete Reset Function**: Permanently delete all WooCommerce products, categories, attributes, and brands
-   **Error Handling**: Comprehensive error tracking and reporting with automatic fixes

## CSV File Structure

The plugin uses exact field mapping from CSV columns to WooCommerce fields:

| **WooCommerce Field**   | **CSV Column**               | **Description**                      |
| ----------------------- | ---------------------------- | ------------------------------------ |
| Product Name            | `DSArticoloAgg`              | Product display name                 |
| SKU Main Product        | `Modello`                    | Main product SKU (unique identifier) |
| SKU Variable            | `CodArticolo`                | Variable product SKU/Barcode         |
| Brand Name              | `DSLinea`                    | Brand/Line name                      |
| Brand ID                | `IGULinea`                   | Brand identifier                     |
| Parent Category         | `DSRepartoWeb`               | Main category (Department)           |
| Category                | `DSCategoriaMerceologicaWeb` | Subcategory                          |
| Gender (Main Category)  | `DSSessoWeb`                 | Gender classification                |
| Price                   | `PrezzoIvato`                | Product price (including tax)        |
| Quantity                | `Disponibilita`              | Stock quantity                       |
| Product Weight          | `Peso`                       | Product weight                       |
| Attributes 1: Model No. | `Modello`                    | Model number attribute               |
| Attributes 2: Size      | `Taglia`                     | Size attribute                       |
| Attributes 3: Color     | `DSColoreWeb`                | Color attribute                      |
| Attributes 4: Material  | `DSMateriale`                | Material attribute                   |

**Additional supported columns:**

-   `URLImg1` to `URLImg10`: Product image URLs
-   `DSStagioneWeb`/`DSStagione`: Season information
-   `CodEsterno`: External reference code
-   `ArticoloDescrizionePers`: Product description

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Ensure WooCommerce is installed and activated
4. Place your CSV files in the `csv files/` directory within the plugin folder

## Usage

### Initial Setup

1. Place your initial product file as `1.csv` in the `csv files/` directory
2. Go to **WooCommerce > Import Products** in the WordPress admin
3. Click "Import Initial File (1.csv)" to import all products

### Automated Updates

1. Upload update files with incremental names: `2.csv`, `3.csv`, `4.csv`, etc.
2. The plugin will automatically check for new files every 30 minutes
3. When found, it will import updates and new products automatically

### Manual Import

Use the admin interface to manually trigger imports:

-   **Import Initial File**: For the first-time import of all products
-   **Import Next Update File**: To manually import the next available update file

### Reset Functionality

The plugin includes a reset feature that allows you to return to the initial state:

-   **Reset Plugin**: Clears all import history and resets file counter to 0
-   **Confirmation Required**: Displays a confirmation dialog before proceeding
-   **Complete Reset**: Removes all import logs and reschedules the cron job
-   **Use Case**: Useful for testing, troubleshooting, or starting fresh with new data

## File Naming Convention

-   Initial file: `1.csv`
-   Update files: `2.csv`, `3.csv`, `4.csv`, etc. (sequential numbering)

## Admin Interface

The admin page provides:

-   **Current Status**: Shows last imported file, current status, and next scheduled import
-   **Manual Import**: Buttons to trigger imports manually
-   **Detailed Logs Viewer**: View comprehensive import logs by date for troubleshooting
-   **Reset Function**: Reset plugin to initial state (clears all history)
-   **Import Logs**: Detailed history of all import activities with success/failure counts

### Detailed Logging

The plugin creates comprehensive logs in the `logs/` directory with **file-specific logging** for better organization and debugging:

#### **File-Specific Log Structure**

Each CSV import creates its own separate log file:

-   **File-Specific Logs**: `import-details-YYYY-MM-DD-[filename].log`
    -   `import-details-2025-06-28-1.log` (for 1.csv import)
    -   `import-details-2025-06-28-2.log` (for 2.csv import)
    -   `import-details-2025-06-28-3.log` (for 3.csv import)
-   **General Logs**: `import-details-YYYY-MM-DD.log` (legacy format)

#### **Enhanced Log Viewer Features**

-   **📋 Separate Log Files**: Each CSV import is logged independently
-   **📊 File Information**: Shows log file size, CSV source, and modification time
-   **💾 Download Capability**: Direct download of log files for external analysis
-   **🔍 Quick Selection**: Dropdown shows all available logs with file details
-   **📈 File Metrics**: Display file size in KB and line count for each log

#### **Log Content Details**

Each log file contains comprehensive information:

-   Import start/completion times and file processing details
-   Product creation/update operations with SKU tracking
-   Scientific notation conversion tracking (for Excel-exported CSVs)
-   Duplicate SKU detection and processing counts
-   Variation creation and update operations
-   Attribute and brand assignment operations
-   Error messages with detailed debugging information
-   Performance metrics and timing data for optimization

#### **Benefits of File-Specific Logging**

-   **🎯 Easier Debugging**: Find logs for specific CSV files instantly
-   **📊 Better Organization**: No more mixed logs from different imports
-   **🔍 Quick Issue Identification**: See which CSV file caused specific issues
-   **💾 Export Capability**: Download logs for sharing or external analysis
-   **📈 File Insights**: See log file sizes and content overview at a glance

## Product Data Handling

### Product Type Detection

The plugin automatically determines whether to create simple or variable products based on the CSV data:

**Variable Products are created when:**

-   `CodArticolo` (SKU Variable) differs from `Modello` (Main SKU)
-   Product has size (`Taglia`) or color (`DSColoreWeb`) attributes
-   Multiple rows share the same `Modello` but have different `CodArticolo` values

**Simple Products are created when:**

-   `CodArticolo` is empty or identical to `Modello`
-   Product has no size or color variations
-   Single product entry without variations

### New Products

-   **Variable Products**: Creates variable products when SKU Variable differs from main SKU or has size/color variations
-   **Simple Products**: Creates simple products for items without variations
-   Sets up stock management for both parent products and variations
-   Assigns categories (creates if they don't exist)
-   Adds product attributes with proper term assignment
-   Downloads and assigns product images
-   Stores additional metadata (barcode, brand, model, etc.)
-   Creates product variations with individual SKUs, prices, and stock levels

### Existing Products (Updates)

-   **Variable Product Updates**: Creates new variations or updates existing ones based on SKU Variable
-   **Simple Product Updates**: Updates stock quantities, prices, and attributes
-   Updates stock quantities and stock status for both products and variations
-   Updates prices for products and individual variations
-   Updates product descriptions and attributes
-   Updates categories and images if provided
-   Maintains existing product data not in CSV

### Categories

-   Automatically creates main categories and subcategories
-   Maintains category hierarchy (subcategories under main categories)
-   Uses web-friendly names when available

### Brands

The plugin automatically creates a **Product Brand** taxonomy:

-   **Brand Name** (from `DSLinea` column) - Creates brand terms automatically
-   **Brand ID** (from `IGULinea` column) - Stored as custom meta
-   Brands appear in **Products > Brands** in WordPress admin
-   Brands can be used for filtering and product organization

### Attributes

The plugin creates the following **global WooCommerce attributes** based on exact field mapping:

-   **Model No.** (from `Modello` column) - Global attribute `pa_model-no`
-   **Size** (from `Taglia` column) - Global attribute `pa_size` (variation-enabled)
-   **Color** (from `DSColoreWeb` column) - Global attribute `pa_color` (variation-enabled)
-   **Material** (from `DSMateriale` column) - Global attribute `pa_material`
-   **Season** (from `DSStagioneWeb`/`DSStagione` column) - Global attribute `pa_season`

**Note:** Size and Color attributes are enabled for variations, allowing for product variants.

### Custom Meta Fields

The plugin also stores additional data as custom meta fields:

-   **SKU Variable** (from `CodArticolo` column) - `_sku_variable`
-   **Brand Name** (from `DSLinea` column) - `_brand_name`
-   **Brand ID** (from `IGULinea` column) - `_brand_id`
-   **Model No.** (from `Modello` column) - `_model_no`
-   **External Code** (from `CodEsterno` column) - `_external_code`

## Logging and Monitoring

The plugin maintains detailed logs including:

-   File name and import date
-   Number of products imported, updated, and failed
-   Error messages for failed imports
-   Import status (completed, completed with errors, failed)

## Cron Job

The plugin uses WordPress cron to schedule imports every 30 minutes. The cron job:

-   Checks for the next sequential CSV file
-   Imports the file if found
-   Updates the system status
-   Logs all activities

## Error Handling

-   Validates CSV file structure
-   Handles missing or malformed data gracefully
-   **Scientific Notation Conversion**: Automatically converts Excel-exported scientific notation (e.g., `2.00001E+12`) to full numbers
-   **Duplicate SKU Prevention**: Enhanced variation matching to prevent duplicate SKU errors
-   Logs detailed error messages for troubleshooting
-   Continues processing even if individual products fail
-   Provides error summaries in the admin interface

### Common Issues and Solutions

#### **Scientific Notation in CSV Files**

**Problem**: When CSV files are edited in Excel, large numbers like barcodes may be converted to scientific notation (e.g., `2.00001E+12`), causing duplicate SKU errors.

**Solution**: The plugin automatically detects and converts scientific notation back to full numbers:

-   Converts `2.00001E+12` → `2000010000000`
-   Logs conversion details for tracking
-   Prevents duplicate SKU errors caused by identical scientific notation values

#### **Duplicate SKU Errors**

**Problem**: "Invalid or duplicated SKU" errors when updating existing products.

**Solution**: Enhanced variation matching logic:

-   First attempts to find variations by SKU
-   Falls back to matching by Size + Color attributes
-   Updates existing variations instead of creating duplicates
-   Properly syncs parent products after variation updates

#### **Invalid CSV Structure**

**Problem**: CSV files missing required columns or having incorrect format.

**Solution**: Automatic structure validation:

-   Validates required columns before processing
-   Provides detailed error messages for missing columns
-   Sends email notifications for structure issues
-   Logs validation results for troubleshooting

## CSV Structure Validation

The plugin automatically validates CSV structure before processing:

### **Required Columns**

-   `Modello` (SKU Main Product)
-   `DSArticoloAgg` (Product Name)
-   `PrezzoIvato` (Price)
-   `Disponibilita` (Stock quantity)

### **Important Columns** (warnings if missing)

-   `CodArticolo` (SKU Variable)
-   `DSLinea` (Brand Name)
-   `DSRepartoWeb` (Parent Category)
-   `DSCategoriaMerceologicaWeb` (Category)
-   `Taglia` (Size)
-   `DSColoreWeb` (Color)

**Validation Features:**

-   ✅ **Automatic Detection**: Checks for required columns before processing
-   ✅ **Error Prevention**: Stops import if critical columns are missing
-   ✅ **Warning System**: Logs warnings for missing important columns
-   ✅ **Email Alerts**: Sends notifications for structure validation failures

## Email Notification System

The plugin includes a comprehensive email notification system:

### **Notification Types**

-   **📧 Import Failures**: Get notified when imports fail or have errors
-   **🆕 New Products**: Receive alerts when new products are automatically added
-   **⚠️ CSV Issues**: Notifications for structure validation failures

### **Configurable Settings**

-   **Enable/Disable**: Turn notifications on/off globally
-   **Custom Email**: Set a specific email address for notifications
-   **Selective Alerts**: Choose which events trigger notifications
-   **Admin Interface**: Easy configuration through WordPress admin panel

### **Email Content**

-   **Detailed Information**: Error messages, product details, timestamps
-   **Direct Links**: Quick access to edit products or view logs
-   **Context**: Source CSV file and import type information

### **Default Settings**

All email notifications are **enabled by default** with the following settings:

-   **Email Address**: WordPress admin email
-   **Failure Notifications**: ✅ Enabled
-   **New Product Notifications**: ✅ Enabled
-   **Global Notifications**: ✅ Enabled

## Complete Reset Function

The plugin includes a powerful **Complete Reset** feature for situations where you need to start completely fresh:

### **⚠️ WARNING: DESTRUCTIVE OPERATION**

This function **permanently deletes ALL WooCommerce data** from your site:

#### **What Gets Deleted**

-   ✅ **All Products**: Including variations and their data
-   ✅ **All Categories**: Product categories and subcategories
-   ✅ **All Attributes**: Product attributes and their terms
-   ✅ **All Brands**: Product brands (if using brand plugins)
-   ✅ **All Import Logs**: Complete import history
-   ✅ **Plugin Settings**: Reset to initial state

#### **Safety Features**

-   **Double Confirmation**: Requires typing "DELETE ALL" to proceed
-   **Clear Warnings**: Multiple warning dialogs before execution
-   **Detailed Results**: Shows exactly what was deleted
-   **No Undo**: This action cannot be reversed

#### **When to Use**

-   🔄 **Fresh Start**: When you want to completely restart your product catalog
-   🧪 **Testing**: After testing imports and want to clean up
-   🚫 **Data Corruption**: When product data becomes corrupted
-   📝 **Migration**: Before importing from a different source

#### **How to Use**

1. Navigate to the Import Products admin page
2. Scroll to the red "🗑️ Complete Reset - Delete ALL Products" button
3. Read the warnings carefully
4. Confirm by typing "DELETE ALL" exactly
5. Wait for the process to complete (may take several minutes)

#### **Results Display**

After completion, you'll see:

-   Number of products deleted
-   Number of categories deleted
-   Number of attributes deleted
-   Number of brands deleted
-   Status of logs and settings reset
-   **Auto-import disabled status**: Prevents immediate re-import

#### **Auto-Import Control**

After a complete reset:

-   **🛑 Auto-import is automatically disabled** to prevent immediate re-import of data you just deleted
-   **⚠️ Warning notification** appears showing auto-import is disabled with timestamp
-   **🔄 Re-enable button** allows you to resume scheduled imports when ready
-   **Manual control** gives you full control over when imports resume

**To resume auto-imports:**

1. Click the "🔄 Re-enable Auto-Import" button
2. Confirm you want to resume scheduled imports
3. Auto-imports will resume every 30 minutes as normal

## Requirements

-   WordPress 5.0 or higher
-   WooCommerce 5.0 or higher
-   PHP 7.4 or higher
-   Sufficient server resources for processing large CSV files

## File Structure

```
import-products/
├── import-products.php          # Main plugin file
├── includes/
│   ├── class-csv-importer.php   # CSV import functionality
│   ├── class-admin-page.php     # Admin interface
│   └── class-scheduler.php      # Cron job management
├── templates/
│   └── admin-page.php          # Admin page template
├── csv files/                  # Directory for CSV files
│   ├── 1.csv                   # Initial product file
│   ├── 2.csv                   # First update file
│   └── ...                     # Additional update files
├── logs/                       # Detailed import logs (auto-created)
│   ├── import-details-YYYY-MM-DD-1.log    # File-specific log for 1.csv
│   ├── import-details-YYYY-MM-DD-2.log    # File-specific log for 2.csv
│   ├── import-details-YYYY-MM-DD-3.log    # File-specific log for 3.csv
│   ├── import-details-YYYY-MM-DD.log      # General/legacy logs
│   └── ...                     # Additional log files
└── README.md                   # This file
```

## Support

For support and troubleshooting:

1. Check the import logs in the admin interface
2. Verify CSV file format and naming
3. Ensure proper file permissions
4. Check WordPress error logs for detailed error messages

## License

GPL v2 or later
