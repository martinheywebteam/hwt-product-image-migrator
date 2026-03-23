<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HWT_Admin {

    private $exporter;
    private $importer;
    private $logger;
    private $hook_suffix = '';

    public function __construct( HWT_Exporter $exporter, HWT_Importer $importer, HWT_Logger $logger ) {
        $this->exporter = $exporter;
        $this->importer = $importer;
        $this->logger   = $logger;

        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // AJAX handlers.
        add_action( 'wp_ajax_hwt_export_csv', array( $this, 'ajax_export_csv' ) );
        add_action( 'wp_ajax_hwt_upload_csv', array( $this, 'ajax_upload_csv' ) );
        add_action( 'wp_ajax_hwt_process_batch', array( $this, 'ajax_process_batch' ) );
        add_action( 'wp_ajax_hwt_download_log', array( $this, 'ajax_download_log' ) );
        add_action( 'wp_ajax_hwt_scan_missing', array( $this, 'ajax_scan_missing' ) );
        add_action( 'wp_ajax_hwt_diagnostics', array( $this, 'ajax_diagnostics' ) );
        add_action( 'wp_ajax_hwt_cleanup', array( $this, 'ajax_cleanup' ) );
    }

    /**
     * Register the admin menu page under WooCommerce.
     */
    public function register_menu() {
        $this->hook_suffix = add_submenu_page(
            'woocommerce',
            'Product Image Migrator',
            'Image Migrator',
            'manage_woocommerce',
            'hwt-image-migrator',
            array( $this, 'render_page' )
        );
    }

    /**
     * Enqueue assets only on our admin page.
     */
    public function enqueue_assets( $hook ) {
        if ( $hook !== $this->hook_suffix ) {
            return;
        }

        wp_enqueue_style(
            'hwt-admin-css',
            HWT_PIM_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            HWT_PIM_VERSION
        );

        wp_enqueue_script(
            'hwt-admin-js',
            HWT_PIM_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            HWT_PIM_VERSION,
            true
        );

        // Store the current blog ID so AJAX handlers can switch to the correct blog in multisite.
        $blog_id = is_multisite() ? get_current_blog_id() : 0;

        wp_localize_script( 'hwt-admin-js', 'hwtPIM', array(
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'exportNonce'   => wp_create_nonce( 'hwt_export' ),
            'uploadNonce'   => wp_create_nonce( 'hwt_upload' ),
            'batchNonce'    => wp_create_nonce( 'hwt_batch' ),
            'downloadNonce' => wp_create_nonce( 'hwt_download_log' ),
            'scanNonce'     => wp_create_nonce( 'hwt_scan' ),
            'blogId'        => $blog_id,
        ) );
    }

    /**
     * Render the admin page.
     */
    public function render_page() {
        $logo_url = HWT_PIM_PLUGIN_URL . 'assets/img/heywebteam-logo.png';
        ?>
        <div class="wrap">
            <h1 class="hwt-screen-title">Product Image Migrator</h1>
        </div>

        <div class="hwt-wrap">
            <!-- Header bar -->
            <div class="hwt-header">
                <div class="hwt-header-left">
                    <div class="hwt-logo-badge">
                        <img src="<?php echo esc_url( $logo_url ); ?>" alt="heyWebTeam" width="28" height="28">
                    </div>
                    <div class="hwt-header-text">
                        <h2 class="hwt-title">Product Image Migrator</h2>
                        <span class="hwt-subtitle">by heyWebTeam</span>
                    </div>
                </div>
                <div class="hwt-header-right">
                    <span class="hwt-version">v<?php echo HWT_PIM_VERSION; ?></span>
                </div>
            </div>

            <!-- How it works - step indicator -->
            <div class="hwt-steps">
                <div class="hwt-step" data-tab="scan">
                    <div class="hwt-step-num">1</div>
                    <div class="hwt-step-text">
                        <strong>Scan</strong>
                        <span>Find missing images</span>
                    </div>
                </div>
                <div class="hwt-step-arrow">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M4 10h12m0 0l-4-4m4 4l-4 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <div class="hwt-step" data-tab="export">
                    <div class="hwt-step-num">2</div>
                    <div class="hwt-step-text">
                        <strong>Export</strong>
                        <span>Download image CSV</span>
                    </div>
                </div>
                <div class="hwt-step-arrow">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M4 10h12m0 0l-4-4m4 4l-4 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <div class="hwt-step" data-tab="import">
                    <div class="hwt-step-num">3</div>
                    <div class="hwt-step-text">
                        <strong>Import</strong>
                        <span>Attach to products</span>
                    </div>
                </div>
            </div>

            <!-- Tab navigation -->
            <div class="hwt-tabs">
                <button class="hwt-tab active" data-tab="guide">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    How It Works
                </button>
                <button class="hwt-tab" data-tab="export">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    Export
                </button>
                <button class="hwt-tab" data-tab="import">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Import
                </button>
                <button class="hwt-tab" data-tab="scan">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    Scan Missing
                </button>
                <button class="hwt-tab" data-tab="diagnostics">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>
                    Diagnostics
                </button>
            </div>

            <!-- ============================================================ -->
            <!-- HOW IT WORKS TAB -->
            <!-- ============================================================ -->
            <div class="hwt-tab-content active" id="hwt-tab-guide">
                <div class="hwt-card">
                    <div class="hwt-guide-intro">
                        <h2>Migrate Product Images in 3 Steps</h2>
                        <p>This plugin helps you move WooCommerce product images from one site to another, matched by SKU. Install it on both sites and follow the steps below.</p>
                    </div>
                    <div class="hwt-guide-flow">
                        <div class="hwt-guide-card">
                            <div class="hwt-guide-num">1</div>
                            <h3>Export</h3>
                            <p>Generate a CSV file from the <strong>source site</strong> (the site that has the images). The CSV contains each product's SKU and its image URLs.</p>
                            <span class="hwt-guide-where hwt-guide-where--source">Source site</span>
                        </div>
                        <div class="hwt-guide-arrow">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                        </div>
                        <div class="hwt-guide-card">
                            <div class="hwt-guide-num">2</div>
                            <h3>Import</h3>
                            <p>Upload the CSV on the <strong>target site</strong> (the new site). The plugin downloads each image and attaches it to the matching product by SKU.</p>
                            <span class="hwt-guide-where hwt-guide-where--target">Target site</span>
                        </div>
                        <div class="hwt-guide-arrow">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                        </div>
                        <div class="hwt-guide-card">
                            <div class="hwt-guide-num">3</div>
                            <h3>Verify</h3>
                            <p>Use the <strong>Scan Missing</strong> tab to check which products still need images. Re-run the import if needed &mdash; duplicates are skipped automatically.</p>
                            <span class="hwt-guide-where hwt-guide-where--either">Either site</span>
                        </div>
                    </div>
                    <div class="hwt-guide-tips">
                        <div class="hwt-guide-tips-card">
                            <h3>
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
                                Tips &amp; Good to Know
                            </h3>
                            <ul class="hwt-tip-list">
                                <li>
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/></svg>
                                    Products are matched by <strong>SKU</strong> &mdash; make sure SKUs are identical on both sites
                                </li>
                                <li>
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    The source site must be <strong>online</strong> during import (images are downloaded from its URLs)
                                </li>
                                <li>
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                    Use the <strong>SKU filter</strong> on the Export or Import tab to process only specific products
                                </li>
                                <li>
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 114 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
                                    Toggle <strong>Overwrite existing</strong> on the Import tab to replace images that are already set
                                </li>
                                <li>
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="10 8 16 12 10 16"/></svg>
                                    You can <strong>pause and resume</strong> the import at any time &mdash; progress is saved
                                </li>
                                <li>
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                    Download the <strong>log file</strong> after import for a full record of what happened
                                </li>
                                <li>
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>
                                    Use the <strong>Diagnostics</strong> tab to troubleshoot site configuration issues
                                </li>
                                <li>
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
                                    Works on <strong>single sites and multisite networks</strong> &mdash; upload paths are auto-detected
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- EXPORT TAB -->
            <!-- ============================================================ -->
            <div class="hwt-tab-content" id="hwt-tab-export">
                <div class="hwt-card hwt-card--export">
                    <div class="hwt-card-header">
                        <div class="hwt-card-icon hwt-card-icon--export">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        </div>
                        <div>
                            <h2>Export Product Images</h2>
                            <p>Generate a CSV containing SKUs and image URLs from the <strong>source site</strong>.</p>
                        </div>
                    </div>

                    <div class="hwt-card-body">
                        <div class="hwt-field">
                            <label for="hwt-export-skus">
                                Filter by SKUs
                                <span class="hwt-tooltip" data-tip="Leave empty to export all products with images. Or paste specific SKUs (one per line) to only export those.">?</span>
                            </label>
                            <span class="hwt-field-hint">Optional &mdash; leave empty to export all products</span>
                            <textarea id="hwt-export-skus" rows="5" placeholder="PROD-001&#10;PROD-002&#10;PROD-003"></textarea>
                        </div>

                        <div class="hwt-card-footer">
                            <button id="hwt-export-btn" class="hwt-btn hwt-btn-primary">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                                <span class="hwt-btn-text">Download CSV</span>
                                <span class="hwt-spinner"></span>
                            </button>
                            <span class="hwt-btn-note">Generates a .csv file download</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- IMPORT TAB -->
            <!-- ============================================================ -->
            <div class="hwt-tab-content" id="hwt-tab-import">
                <div class="hwt-card hwt-card--import">
                    <div class="hwt-card-header">
                        <div class="hwt-card-icon hwt-card-icon--import">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        </div>
                        <div>
                            <h2>Import Product Images</h2>
                            <p>Upload a CSV from the source site. Images are downloaded and attached to products by <strong>SKU</strong>.</p>
                        </div>
                    </div>

                    <div class="hwt-card-body">
                        <!-- File upload -->
                        <div class="hwt-field">
                            <label for="hwt-import-file">
                                CSV File
                                <span class="hwt-tooltip" data-tip="Upload the CSV exported from the source site. Must contain columns: sku, featured_image_url, gallery_image_urls.">?</span>
                            </label>
                            <div class="hwt-file-upload">
                                <input type="file" id="hwt-import-file" accept=".csv">
                                <div class="hwt-file-label">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                    <span class="hwt-file-name">Drop CSV here or click to browse...</span>
                                </div>
                            </div>
                        </div>

                        <!-- SKU filter -->
                        <div class="hwt-field">
                            <label for="hwt-import-skus">
                                Filter by SKUs
                                <span class="hwt-tooltip" data-tip="Paste SKUs from the Scan Missing tab to only import images for products that need them. Leave empty to process the entire CSV.">?</span>
                            </label>
                            <span class="hwt-field-hint">Optional &mdash; paste from Scan Missing tab, or leave empty for all</span>
                            <textarea id="hwt-import-skus" rows="4" placeholder="Paste SKUs here (one per line)..."></textarea>
                        </div>

                        <!-- Options row -->
                        <div class="hwt-options-row">
                            <div class="hwt-option">
                                <label class="hwt-toggle">
                                    <input type="checkbox" id="hwt-overwrite">
                                    <span class="hwt-toggle-slider"></span>
                                </label>
                                <div class="hwt-option-text">
                                    <span>Overwrite existing</span>
                                    <span class="hwt-tooltip" data-tip="When OFF (default), products that already have images are skipped. When ON, existing images are replaced with the imported ones.">?</span>
                                </div>
                            </div>
                            <div class="hwt-option">
                                <label for="hwt-batch-size" class="hwt-option-label">Batch size</label>
                                <input type="number" id="hwt-batch-size" value="5" min="1" max="20" class="hwt-input-mini">
                                <span class="hwt-tooltip" data-tip="Products processed per request. Lower = slower but safer for weak servers. Default 5 is good for most hosts.">?</span>
                            </div>
                        </div>

                        <div class="hwt-card-footer">
                            <button id="hwt-import-btn" class="hwt-btn hwt-btn-primary">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0018 9h-1.26A8 8 0 103 16.3"/></svg>
                                <span class="hwt-btn-text">Start Import</span>
                                <span class="hwt-spinner"></span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Progress area (hidden until import starts) -->
                <div id="hwt-progress-area" class="hwt-card hwt-card--progress hwt-hidden">
                    <div class="hwt-progress-header">
                        <h3>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                            Live Progress
                        </h3>
                        <button id="hwt-pause-btn" class="hwt-btn hwt-btn-secondary hwt-btn-sm hwt-hidden">
                            <span class="hwt-btn-text">Pause</span>
                        </button>
                    </div>

                    <div id="hwt-current-sku" class="hwt-current-sku"></div>

                    <div class="hwt-progress-bar-wrap">
                        <div class="hwt-progress-bar">
                            <div id="hwt-progress-fill" class="hwt-progress-fill" style="width:0%"></div>
                        </div>
                        <span id="hwt-progress-text" class="hwt-progress-text">0 / 0</span>
                    </div>

                    <div id="hwt-stats" class="hwt-stats">
                        <div class="hwt-stat-card hwt-stat-card--success">
                            <div class="hwt-stat-num" id="hwt-stat-imported">0</div>
                            <div class="hwt-stat-label">Imported</div>
                        </div>
                        <div class="hwt-stat-card hwt-stat-card--skip">
                            <div class="hwt-stat-num" id="hwt-stat-skipped">0</div>
                            <div class="hwt-stat-label">Skipped</div>
                        </div>
                        <div class="hwt-stat-card hwt-stat-card--error">
                            <div class="hwt-stat-num" id="hwt-stat-failed">0</div>
                            <div class="hwt-stat-label">Failed</div>
                        </div>
                    </div>

                    <div id="hwt-log" class="hwt-log"></div>

                    <div id="hwt-complete" class="hwt-complete hwt-hidden">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        <span>Import complete!</span>
                        <a id="hwt-download-log" href="#" class="hwt-btn hwt-btn-secondary hwt-btn-sm">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                            Download Log
                        </a>
                    </div>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- SCAN MISSING TAB -->
            <!-- ============================================================ -->
            <div class="hwt-tab-content" id="hwt-tab-scan">
                <div class="hwt-card hwt-card--scan">
                    <div class="hwt-card-header">
                        <div class="hwt-card-icon hwt-card-icon--scan">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        </div>
                        <div>
                            <h2>Scan for Missing Images</h2>
                            <p>Detect products on <strong>this site</strong> that are missing featured or gallery images.</p>
                        </div>
                    </div>

                    <div class="hwt-card-body">
                        <div class="hwt-field">
                            <label>
                                What to look for
                                <span class="hwt-tooltip" data-tip="Choose which type of missing image to scan for. 'Any images' will catch products missing either type.">?</span>
                            </label>
                            <div class="hwt-radio-cards">
                                <label class="hwt-radio-card active">
                                    <input type="radio" name="hwt-scan-type" value="no_featured" checked>
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                                    <span>Missing featured image</span>
                                </label>
                                <label class="hwt-radio-card">
                                    <input type="radio" name="hwt-scan-type" value="no_gallery">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="16" height="16" rx="2"/><rect x="6" y="6" width="16" height="16" rx="2"/></svg>
                                    <span>Missing gallery images</span>
                                </label>
                                <label class="hwt-radio-card">
                                    <input type="radio" name="hwt-scan-type" value="no_any">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                    <span>Missing any images</span>
                                </label>
                            </div>
                        </div>

                        <div class="hwt-card-footer">
                            <button id="hwt-scan-btn" class="hwt-btn hwt-btn-primary">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                <span class="hwt-btn-text">Scan Products</span>
                                <span class="hwt-spinner"></span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Scan results -->
                <div id="hwt-scan-results" class="hwt-card hwt-card--results hwt-hidden">
                    <div class="hwt-scan-results-header">
                        <h3>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                            Scan Results
                        </h3>
                        <span id="hwt-scan-count" class="hwt-badge hwt-badge--warn"></span>
                    </div>
                    <p id="hwt-scan-summary" class="hwt-scan-summary"></p>

                    <div class="hwt-scan-skus-wrap">
                        <label for="hwt-scan-skus-output">SKUs missing images:</label>
                        <textarea id="hwt-scan-skus-output" rows="8" readonly></textarea>
                    </div>

                    <div class="hwt-scan-actions">
                        <button id="hwt-scan-copy" class="hwt-btn hwt-btn-secondary hwt-btn-sm">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                            <span class="hwt-btn-text">Copy to Clipboard</span>
                        </button>
                        <button id="hwt-scan-use-import" class="hwt-btn hwt-btn-primary hwt-btn-sm">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                            <span class="hwt-btn-text">Use in Import Tab</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- DIAGNOSTICS TAB -->
            <!-- ============================================================ -->
            <div class="hwt-tab-content" id="hwt-tab-diagnostics">
                <div class="hwt-card">
                    <div class="hwt-card-header">
                        <div class="hwt-card-icon hwt-card-icon--scan">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>
                        </div>
                        <div>
                            <h2>Site Diagnostics</h2>
                            <p>Run this to see exactly how WordPress is configured on this site. Share the output if you need support.</p>
                        </div>
                    </div>
                    <div class="hwt-card-body">
                        <button id="hwt-diag-btn" class="hwt-btn hwt-btn-primary">
                            <span class="hwt-btn-text">Run Diagnostics</span>
                            <span class="hwt-spinner"></span>
                        </button>

                        <div style="margin-top:20px; padding:16px; background:#fff3cd; border:1px solid #ffc107; border-radius:6px;">
                            <h3 style="margin:0 0 8px; font-size:14px; color:#856404;">Cleanup Imported Attachments</h3>
                            <p style="margin:0 0 12px; font-size:13px; color:#856404;">Delete ALL attachments created by this plugin (tagged with <code>_hwt_source_url</code>). Also clears product image assignments for affected products. Use this before a fresh re-import.</p>
                            <button id="hwt-cleanup-trigger" class="hwt-btn hwt-btn-secondary">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                                <span>Delete All Imported Attachments</span>
                            </button>
                            <span id="hwt-cleanup-result" style="margin-left:12px; font-size:13px;"></span>
                        </div>

            <!-- Cleanup confirmation modal -->
            <div id="hwt-modal-overlay" class="hwt-modal-overlay hwt-hidden">
                <div class="hwt-modal">
                    <div class="hwt-modal-icon">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#dc3545" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    </div>
                    <h3>Delete All Imported Attachments?</h3>
                    <p>This will <strong>permanently delete</strong> all image attachments created by this plugin and clear product image assignments for affected products.</p>
                    <p>This action <strong>cannot be undone</strong>. Only proceed if you want a fresh start before re-importing.</p>
                    <div class="hwt-modal-actions">
                        <button id="hwt-modal-cancel" class="hwt-btn hwt-btn-secondary">Cancel</button>
                        <button id="hwt-cleanup-btn" class="hwt-btn" style="background:#dc3545; color:#fff;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                            <span class="hwt-btn-text">Yes, Delete Everything</span>
                            <span class="hwt-spinner"></span>
                        </button>
                    </div>
                </div>
            </div>

                        <div id="hwt-diag-results" class="hwt-hidden" style="margin-top:16px;">
                            <textarea id="hwt-diag-output" rows="25" readonly style="width:100%;font-family:monospace;font-size:12px;background:#f8f9fa;padding:12px;border:1px solid #dee2e6;border-radius:6px;"></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Export CSV.
     */
    public function ajax_export_csv() {
        check_ajax_referer( 'hwt_export', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Unauthorized' );
        }

        $skus = isset( $_POST['skus'] ) ? sanitize_textarea_field( wp_unslash( $_POST['skus'] ) ) : '';
        $sku_filter = array_filter( array_map( 'trim', explode( "\n", $skus ) ) );

        $this->exporter->export_csv( $sku_filter );
        // export_csv calls exit().
    }

    /**
     * AJAX: Upload and validate CSV.
     */
    public function ajax_upload_csv() {
        check_ajax_referer( 'hwt_upload', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        if ( empty( $_FILES['csv_file'] ) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( 'File upload failed. Please try again.' );
        }

        $skus = isset( $_POST['skus'] ) ? sanitize_textarea_field( wp_unslash( $_POST['skus'] ) ) : '';
        $sku_filter  = array_filter( array_map( 'trim', explode( "\n", $skus ) ) );
        $overwrite   = ! empty( $_POST['overwrite'] );
        $batch_size  = isset( $_POST['batch_size'] ) ? intval( $_POST['batch_size'] ) : 5;

        $result = $this->importer->setup_import(
            $_FILES['csv_file']['tmp_name'],
            $_FILES['csv_file']['name'],
            $sku_filter,
            $overwrite,
            $batch_size
        );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( array(
            'total'      => $result['total'],
            'batch_size' => $result['batch_size'],
        ) );
    }

    /**
     * AJAX: Process a batch.
     */
    public function ajax_process_batch() {
        check_ajax_referer( 'hwt_batch', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $offset = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;
        $result = $this->importer->process_batch( $offset );

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( $result['error'] );
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX: Scan for products missing images.
     */
    public function ajax_scan_missing() {
        check_ajax_referer( 'hwt_scan', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $scan_type = isset( $_POST['scan_type'] ) ? sanitize_text_field( $_POST['scan_type'] ) : 'no_featured';
        $missing_skus = array();
        $total_scanned = 0;
        $page = 1;

        while ( true ) {
            $products = wc_get_products( array(
                'limit'   => 100,
                'page'    => $page,
                'status'  => 'publish',
                'type'    => array( 'simple', 'variable', 'variation' ),
                'orderby' => 'ID',
                'order'   => 'ASC',
                'return'  => 'objects',
            ) );

            if ( empty( $products ) ) {
                break;
            }

            foreach ( $products as $product ) {
                $sku = $product->get_sku();
                if ( empty( $sku ) ) {
                    continue;
                }

                $total_scanned++;
                $product_id    = $product->get_id();
                $has_featured  = has_post_thumbnail( $product_id );
                $gallery_ids   = $product->get_gallery_image_ids();
                $has_gallery   = ! empty( $gallery_ids );

                $is_missing = false;

                switch ( $scan_type ) {
                    case 'no_featured':
                        $is_missing = ! $has_featured;
                        break;
                    case 'no_gallery':
                        $is_missing = ! $has_gallery;
                        break;
                    case 'no_any':
                        $is_missing = ! $has_featured || ! $has_gallery;
                        break;
                }

                if ( $is_missing ) {
                    $detail = array();
                    if ( ! $has_featured ) {
                        $detail[] = 'no featured';
                    }
                    if ( ! $has_gallery ) {
                        $detail[] = 'no gallery';
                    }
                    $missing_skus[] = array(
                        'sku'    => $sku,
                        'detail' => implode( ', ', $detail ),
                    );
                }
            }

            $page++;
        }

        wp_send_json_success( array(
            'total_scanned' => $total_scanned,
            'missing_count' => count( $missing_skus ),
            'missing_skus'  => $missing_skus,
        ) );
    }

    /**
     * AJAX: Download log file.
     */
    public function ajax_download_log() {
        check_ajax_referer( 'hwt_download_log', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Unauthorized' );
        }

        $filename = isset( $_GET['file'] ) ? sanitize_file_name( $_GET['file'] ) : '';
        $filepath = HWT_PIM_LOG_DIR . '/' . $filename;

        if ( empty( $filename ) || ! file_exists( $filepath ) || pathinfo( $filepath, PATHINFO_EXTENSION ) !== 'log' ) {
            wp_die( 'Log file not found.' );
        }

        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        header( 'Content-Length: ' . filesize( $filepath ) );
        readfile( $filepath );
        exit;
    }

    /**
     * AJAX: Run diagnostics — dump all relevant config for debugging.
     */
    public function ajax_diagnostics() {
        check_ajax_referer( 'hwt_export', 'nonce' ); // Reuse export nonce.

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        global $wpdb;

        $upload_dir = wp_upload_dir();
        $diag = array();

        $diag[] = '=== heyWebTeam Product Image Migrator — Diagnostics ===';
        $diag[] = 'Date: ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC';
        $diag[] = 'Plugin version: ' . HWT_PIM_VERSION;
        $diag[] = '';

        // WordPress.
        $diag[] = '--- WordPress ---';
        $diag[] = 'WP version: ' . get_bloginfo( 'version' );
        $diag[] = 'is_multisite(): ' . ( is_multisite() ? 'YES' : 'NO' );
        $diag[] = 'get_current_blog_id(): ' . get_current_blog_id();
        if ( is_multisite() ) {
            $diag[] = 'get_current_site()->id: ' . get_current_site()->id;
            $diag[] = 'get_current_site()->domain: ' . get_current_site()->domain;
            $diag[] = 'get_current_site()->path: ' . get_current_site()->path;
            $blog_details = get_blog_details();
            if ( $blog_details ) {
                $diag[] = 'Current blog domain: ' . $blog_details->domain;
                $diag[] = 'Current blog path: ' . $blog_details->path;
                $diag[] = 'Current blog siteurl: ' . $blog_details->siteurl;
            }
            $diag[] = 'BLOG_ID_CURRENT_SITE: ' . ( defined( 'BLOG_ID_CURRENT_SITE' ) ? BLOG_ID_CURRENT_SITE : 'not defined' );
        }
        $diag[] = 'site_url(): ' . site_url();
        $diag[] = 'home_url(): ' . home_url();
        $diag[] = 'admin_url(): ' . admin_url();
        $diag[] = 'admin_url(admin-ajax.php): ' . admin_url( 'admin-ajax.php' );
        $diag[] = '';

        // Database.
        $diag[] = '--- Database ---';
        $diag[] = '$wpdb->prefix: ' . $wpdb->prefix;
        $diag[] = '$wpdb->base_prefix: ' . $wpdb->base_prefix;
        $diag[] = '$wpdb->posts table: ' . $wpdb->posts;
        $diag[] = '$wpdb->postmeta table: ' . $wpdb->postmeta;
        $diag[] = '';

        // Upload directory.
        $diag[] = '--- wp_upload_dir() (NATURAL context) ---';
        $diag[] = 'basedir: ' . $upload_dir['basedir'];
        $diag[] = 'baseurl: ' . $upload_dir['baseurl'];
        $diag[] = 'path: ' . $upload_dir['path'];
        $diag[] = 'url: ' . $upload_dir['url'];
        $diag[] = 'subdir: ' . $upload_dir['subdir'];
        $diag[] = 'error: ' . ( $upload_dir['error'] ? $upload_dir['error'] : 'none' );
        $diag[] = 'basedir exists: ' . ( is_dir( $upload_dir['basedir'] ) ? 'YES' : 'NO' );
        $diag[] = 'basedir writable: ' . ( wp_is_writable( $upload_dir['basedir'] ) ? 'YES' : 'NO' );
        $diag[] = 'path exists: ' . ( is_dir( $upload_dir['path'] ) ? 'YES' : 'NO' );
        $diag[] = '';

        // Test with switch_to_blog if multisite.
        if ( is_multisite() ) {
            $blog_id = get_current_blog_id();
            $passed_blog_id = isset( $_POST['blog_id'] ) ? intval( $_POST['blog_id'] ) : 0;
            $diag[] = '--- Blog ID from JS ---';
            $diag[] = 'Passed blog_id: ' . $passed_blog_id;
            $diag[] = '';

            // Test switch_to_blog effect.
            if ( $passed_blog_id && $passed_blog_id !== $blog_id ) {
                switch_to_blog( $passed_blog_id );
                $switched_upload = wp_upload_dir();
                $diag[] = '--- wp_upload_dir() AFTER switch_to_blog(' . $passed_blog_id . ') ---';
                $diag[] = 'get_current_blog_id(): ' . get_current_blog_id();
                $diag[] = '$wpdb->prefix: ' . $wpdb->prefix;
                $diag[] = '$wpdb->posts table: ' . $wpdb->posts;
                $diag[] = 'basedir: ' . $switched_upload['basedir'];
                $diag[] = 'baseurl: ' . $switched_upload['baseurl'];
                $diag[] = 'path: ' . $switched_upload['path'];
                $diag[] = 'url: ' . $switched_upload['url'];
                restore_current_blog();
            } else {
                $diag[] = '--- switch_to_blog test ---';
                $diag[] = 'Skipped (already on target blog or no blog_id passed).';
            }
            $diag[] = '';
        }

        // WooCommerce.
        $diag[] = '--- WooCommerce ---';
        $diag[] = 'WC version: ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : 'N/A' );
        if ( class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
            $diag[] = 'HPOS enabled: ' . ( wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled() ? 'YES' : 'NO' );
        } else {
            $diag[] = 'HPOS: N/A (older WC version)';
        }
        $diag[] = '';

        // PHP.
        $diag[] = '--- PHP ---';
        $diag[] = 'PHP version: ' . phpversion();
        $diag[] = 'GD loaded: ' . ( extension_loaded( 'gd' ) ? 'YES' : 'NO' );
        $diag[] = 'Imagick loaded: ' . ( extension_loaded( 'imagick' ) ? 'YES' : 'NO' );
        $diag[] = 'max_execution_time: ' . ini_get( 'max_execution_time' );
        $diag[] = 'memory_limit: ' . ini_get( 'memory_limit' );
        $diag[] = 'upload_max_filesize: ' . ini_get( 'upload_max_filesize' );
        $diag[] = '';

        // Test: find a working attachment to compare.
        $diag[] = '--- Last 3 Attachments in DB ---';
        $recent = $wpdb->get_results(
            "SELECT ID, post_title, guid FROM {$wpdb->posts} WHERE post_type = 'attachment' ORDER BY ID DESC LIMIT 3"
        );
        if ( $recent ) {
            foreach ( $recent as $att ) {
                $file = get_post_meta( $att->ID, '_wp_attached_file', true );
                $diag[] = "ID {$att->ID}: _wp_attached_file = {$file}";
                $diag[] = "  guid = {$att->guid}";
                $diag[] = "  wp_get_attachment_url() = " . wp_get_attachment_url( $att->ID );
                $full_path = get_attached_file( $att->ID );
                $diag[] = "  get_attached_file() = {$full_path}";
                $diag[] = "  file_exists: " . ( file_exists( $full_path ) ? 'YES (' . size_format( filesize( $full_path ) ) . ')' : 'NO' );
            }
        } else {
            $diag[] = 'No attachments found.';
        }
        $diag[] = '';

        // Constants.
        $diag[] = '--- Relevant Constants ---';
        $diag[] = 'ABSPATH: ' . ABSPATH;
        $diag[] = 'WP_CONTENT_DIR: ' . WP_CONTENT_DIR;
        $diag[] = 'WP_CONTENT_URL: ' . WP_CONTENT_URL;
        $diag[] = 'UPLOADS: ' . ( defined( 'UPLOADS' ) ? UPLOADS : 'not defined' );
        $diag[] = 'BLOGUPLOADDIR: ' . ( defined( 'BLOGUPLOADDIR' ) ? BLOGUPLOADDIR : 'not defined' );

        wp_send_json_success( implode( "\n", $diag ) );
    }

    /**
     * AJAX: Cleanup all imported attachments and clear product image assignments.
     */
    public function ajax_cleanup() {
        check_ajax_referer( 'hwt_export', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        global $wpdb;

        // Find all attachment IDs with _hwt_source_url meta (created by our plugin).
        $attachment_ids = $wpdb->get_col(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_hwt_source_url'"
        );

        $deleted  = 0;
        $products_cleared = 0;

        if ( ! empty( $attachment_ids ) ) {
            // First, find which products reference these attachments and clear them.
            $id_list = implode( ',', array_map( 'intval', $attachment_ids ) );

            // Clear featured images (products using these as thumbnails).
            $product_ids_featured = $wpdb->get_col(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_thumbnail_id'
                   AND meta_value IN ({$id_list})"
            );

            foreach ( $product_ids_featured as $pid ) {
                $product = wc_get_product( $pid );
                if ( $product ) {
                    $product->set_image_id( 0 );
                    $product->save();
                    $products_cleared++;
                }
            }

            // Clear gallery images that reference our attachments.
            $all_products_with_gallery = $wpdb->get_results(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta}
                 WHERE meta_key = '_product_image_gallery'
                   AND meta_value != ''"
            );

            foreach ( $all_products_with_gallery as $row ) {
                $gallery_ids = array_map( 'intval', explode( ',', $row->meta_value ) );
                $clean_ids   = array_diff( $gallery_ids, array_map( 'intval', $attachment_ids ) );

                if ( count( $clean_ids ) !== count( $gallery_ids ) ) {
                    $product = wc_get_product( $row->post_id );
                    if ( $product ) {
                        $product->set_gallery_image_ids( array_values( $clean_ids ) );
                        $product->save();
                    }
                }
            }

            // Now delete all the attachments (files + DB records).
            foreach ( $attachment_ids as $att_id ) {
                wp_delete_attachment( intval( $att_id ), true );
                $deleted++;
            }
        }

        // Also clean up orphaned import transients.
        delete_transient( 'hwt_import_job' );

        wp_send_json_success( array(
            'deleted'          => $deleted,
            'products_cleared' => $products_cleared,
        ) );
    }
}
