# heyWebTeam - Product Image Migrator

A WooCommerce plugin that exports and imports product images (featured + gallery) between sites, matched by SKU.

Built for migrating product images when products have been moved to a new WooCommerce site but images didn't carry over.

## How It Works

```
Source Site (live)                    Target Site (staging/new)
─────────────────                    ────────────────────────
1. Export tab                        3. Import tab
   → Generates CSV with                → Upload the CSV
     SKUs + image URLs                 → Plugin downloads images
                                         from source URLs
2. Download CSV to                     → Attaches to products
   your computer                         matched by SKU
                                       → Done
```

## Features

- **CSV Export/Import** — No API keys needed, fully file-based
- **SKU Matching** — Products are matched by their WooCommerce SKU field
- **Featured + Gallery Images** — Imports both the main product image and all gallery images
- **Batch Processing** — AJAX-based, processes 5 products per batch to avoid timeouts
- **Pause/Resume** — Pause the import anytime, resume where you left off
- **Duplicate Prevention** — Won't re-download images that have already been imported
- **Overwrite Mode** — Optional toggle to replace existing images
- **Scan Missing** — Detect which products on the target site are missing images
- **SKU Filtering** — Export/import only specific SKUs (paste from a spreadsheet)
- **Diagnostics** — Built-in diagnostic tool to inspect site configuration
- **Cleanup** — One-click removal of all imported attachments if needed
- **Multisite Support** — Auto-detects upload directory configuration on WordPress multisite
- **HPOS Compatible** — Uses WooCommerce's product API, works with High-Performance Order Storage
- **Detailed Logging** — Downloadable log file after every import

## Requirements

- WordPress 5.8+
- WooCommerce 5.0+
- PHP 7.4+
- `manage_woocommerce` capability (Shop Manager or Administrator)

## Installation

1. Download the latest release ZIP
2. Go to **Plugins → Add New → Upload Plugin**
3. Upload the ZIP and activate
4. Find it under **WooCommerce → Image Migrator**

## Usage

### Exporting (on the source site)

1. Go to **WooCommerce → Image Migrator → Export**
2. Optionally paste specific SKUs to filter (one per line)
3. Click **Download CSV**

### Importing (on the target site)

1. Go to **WooCommerce → Image Migrator → Import**
2. Upload the CSV from the source site
3. Optionally paste SKUs to limit the import
4. Toggle **Overwrite existing** if you want to replace current images
5. Click **Start Import**

### Scanning for Missing Images

1. Go to **WooCommerce → Image Migrator → Scan Missing**
2. Choose what to scan for (missing featured, gallery, or any)
3. Click **Scan Products**
4. Click **Use in Import Tab** to auto-fill the SKU filter

## CSV Format

The exported CSV contains these columns:

| Column | Description |
|---|---|
| `sku` | Product SKU |
| `product_id` | WordPress product post ID |
| `product_title` | Product name (for reference) |
| `featured_image_url` | Full URL to the featured image |
| `gallery_image_urls` | Pipe-delimited (`\|`) list of gallery image URLs |

## Security

- All AJAX handlers verify nonces and `manage_woocommerce` capability
- Log files are protected with `.htaccess` deny rules
- CSV uploads are validated for extension, size (max 10MB), and required columns
- No external API calls — images are downloaded directly from the source URLs

## License

GPL-2.0+

## Author

[heyWebTeam](https://heywebteam.com)
