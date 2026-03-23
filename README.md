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

The plugin includes a built-in **How It Works** tab that walks you through the process step by step.

## Features

### Core
- **CSV Export/Import** — No API keys needed, fully file-based
- **SKU Matching** — Products are matched by their WooCommerce SKU field
- **Featured + Gallery Images** — Imports both the main product image and all gallery images
- **Variation Image Support** — Exports and imports variation-specific images (each variation with its own SKU and image)
- **Batch Processing** — AJAX-based, processes 5 products per batch to avoid timeouts
- **Pause/Resume** — Pause the import anytime, resume where you left off
- **Duplicate Prevention** — Won't re-download images that have already been imported
- **Overwrite Mode** — Optional toggle to replace existing images
- **Dry Run Mode** — Simulate an import without downloading any images to preview what would happen
- **SKU Filtering** — Export/import only specific SKUs (paste from a spreadsheet)

### Scan & Verify
- **Scan Missing** — Detect which products are missing featured images, gallery images, or both
- **Detail Badges** — Each scanned SKU shows exactly what's missing (red `no featured`, yellow `no gallery`)
- **Gallery-only Info Note** — Automatic note explaining that "no gallery" may be normal if the source product doesn't have gallery images
- **Use in Import** — One-click transfer of scanned SKUs to the Import tab's filter
- **Copy to Clipboard** — Copy scanned SKU lists for use elsewhere

### Import History
- **Cumulative History** — Keeps the last 10 import runs (not just the latest)
- **Run Selector** — Dropdown to switch between previous import runs
- **Product Thumbnails** — Visual preview of each product's featured image in the history table
- **Product Table** — Full list of imported products with SKU, name, status, and image count
- **Edit Links** — Click to open any product directly in the WooCommerce editor
- **Search** — Instant search by SKU or product name (accent-insensitive — `Hermes` finds `Hermès`)
- **Status Filters** — Filter by All / Success / Skipped / Failed with count badges
- **Dry Run Labels** — Clearly marks simulation runs vs real imports
- **Auto-rebuild** — If you installed the plugin after an import, history is rebuilt from existing data

### Diagnostics & Cleanup
- **Site Diagnostics** — Inspect WordPress version, upload paths, database prefixes, HPOS status, PHP config
- **Copy to Clipboard** — Share diagnostics output for support
- **Cleanup Tool** — One-click removal of all imported attachments with confirmation modal
- **Side-by-side Layout** — Diagnostics and cleanup displayed as separate cards

### Compatibility
- **Multisite Support** — Auto-detects upload directory configuration (single site, subdirectory multisite, custom upload paths)
- **HPOS Compatible** — Uses WooCommerce's product API (`set_image_id`, `set_gallery_image_ids`, `save`), works with High-Performance Order Storage
- **Detailed Logging** — Downloadable log file after every import with per-image details

### UI/UX
- **How It Works Tab** — Visual 3-step guide with tips for new users
- **Modern Design** — Card-based layout, smooth tab transitions, animated progress bar
- **Tooltips** — Context-sensitive help on every option, JS-positioned to stay within viewport
- **Responsive** — Works on smaller screens and mobile admin
- **Full-width Centered Layout** — Makes use of available screen space
- **In-page Modal** — Cleanup confirmation without browser popups

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

## Tabs Overview

| Tab | Purpose |
|---|---|
| **How It Works** | Visual guide explaining the 3-step workflow with tips |
| **Export** | Generate a CSV of product SKUs and image URLs from the source site |
| **Import** | Upload a CSV and download images to the target site, matched by SKU |
| **Scan Missing** | Detect products missing featured or gallery images |
| **Import History** | View results from the last import with search, filters, and edit links |
| **Diagnostics** | Inspect site config and clean up imported attachments |

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
6. Monitor progress in real-time — pause/resume as needed
7. Download the log file when complete

### Scanning for Missing Images

1. Go to **WooCommerce → Image Migrator → Scan Missing**
2. Choose what to scan for (missing featured, gallery, or any)
3. Click **Scan Products**
4. Click **Use in Import Tab** to auto-fill the SKU filter, or **Copy to Clipboard**

### Reviewing Import History

1. Go to **WooCommerce → Image Migrator → Import History**
2. View stats: products updated, skipped, and failed
3. Search by SKU or product name (accent-insensitive)
4. Filter by status (All / Success / Skipped / Failed)
5. Click **Edit** to open any product in the WooCommerce editor

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
- Cleanup requires explicit confirmation via in-page modal

## License

GPL-2.0+

## Author

[heyWebTeam](https://heywebteam.com)
