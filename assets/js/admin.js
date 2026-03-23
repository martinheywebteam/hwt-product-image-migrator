(function ($) {
    'use strict';

    // --- State ---
    var paused = false;
    var importing = false;
    var stats = { imported: 0, skipped: 0, failed: 0 };
    var totalRows = 0;
    var currentLogFile = '';

    // =========================================================================
    // Tab switching (AJAX-style, no page reload)
    // =========================================================================
    function switchTab(tab) {
        $('.hwt-tab').removeClass('active');
        $('.hwt-tab[data-tab="' + tab + '"]').addClass('active');

        $('.hwt-tab-content').removeClass('active');
        $('#hwt-tab-' + tab).addClass('active');
    }

    $(document).on('click', '.hwt-tab', function () {
        switchTab($(this).data('tab'));
    });

    // Step indicator clicks also switch tabs.
    $(document).on('click', '.hwt-step', function () {
        switchTab($(this).data('tab'));
    });

    // Radio card selection.
    $(document).on('change', '.hwt-radio-card input[type="radio"]', function () {
        $(this).closest('.hwt-radio-cards').find('.hwt-radio-card').removeClass('active');
        $(this).closest('.hwt-radio-card').addClass('active');
    });

    // =========================================================================
    // Tooltips — JS-positioned to stay within viewport
    // =========================================================================
    var $tooltipPopup = null;

    $(document).on('mouseenter', '.hwt-tooltip', function () {
        var tip = $(this).attr('data-tip');
        if (!tip) return;

        if (!$tooltipPopup) {
            $tooltipPopup = $('<div class="hwt-tooltip-popup"></div>').appendTo('body');
        }

        $tooltipPopup.text(tip).show();

        var rect = this.getBoundingClientRect();
        var popW = $tooltipPopup.outerWidth();
        var popH = $tooltipPopup.outerHeight();

        // Position above the ? icon, clamped to viewport.
        var top = rect.top - popH - 8;
        var left = rect.left + (rect.width / 2) - (popW / 2);

        // Clamp left so it doesn't go off-screen.
        if (left < 8) left = 8;
        if (left + popW > window.innerWidth - 8) left = window.innerWidth - popW - 8;

        // If no room above, show below.
        if (top < 8) top = rect.bottom + 8;

        $tooltipPopup.css({ top: top + 'px', left: left + 'px' });
    });

    $(document).on('mouseleave', '.hwt-tooltip', function () {
        if ($tooltipPopup) $tooltipPopup.hide();
    });

    // =========================================================================
    // File upload label
    // =========================================================================
    $('#hwt-import-file').on('change', function () {
        var name = this.files.length ? this.files[0].name : 'Choose a CSV file...';
        $(this).siblings('.hwt-file-label').find('.hwt-file-name').text(name);

        if (this.files.length) {
            $(this).siblings('.hwt-file-label').addClass('has-file');
        } else {
            $(this).siblings('.hwt-file-label').removeClass('has-file');
        }
    });

    // =========================================================================
    // Export
    // =========================================================================
    $('#hwt-export-btn').on('click', function (e) {
        e.preventDefault();
        var $btn = $(this);

        if ($btn.hasClass('loading')) return;
        $btn.addClass('loading');

        var skus = $('#hwt-export-skus').val();

        // Build a hidden form and submit it (to get a file download).
        var $form = $('<form>', {
            method: 'POST',
            action: hwtPIM.ajaxUrl,
        }).css('display', 'none');

        $form.append($('<input>', { type: 'hidden', name: 'action', value: 'hwt_export_csv' }));
        $form.append($('<input>', { type: 'hidden', name: 'nonce', value: hwtPIM.exportNonce }));
        $form.append($('<input>', { type: 'hidden', name: 'blog_id', value: hwtPIM.blogId }));
        // Use textarea (not input) to preserve newlines in SKU list.
        $form.append($('<textarea>', { name: 'skus' }).val(skus).css('display', 'none'));

        $('body').append($form);
        $form.submit();

        // Remove the form and spinner after a short delay.
        setTimeout(function () {
            $form.remove();
            $btn.removeClass('loading');
        }, 2000);
    });

    // =========================================================================
    // Import — Upload CSV
    // =========================================================================
    $('#hwt-import-btn').on('click', function (e) {
        e.preventDefault();
        var $btn = $(this);

        if ($btn.hasClass('loading') || importing) return;

        var fileInput = document.getElementById('hwt-import-file');
        if (!fileInput.files.length) {
            alert('Please select a CSV file first.');
            return;
        }

        $btn.addClass('loading');

        var formData = new FormData();
        formData.append('action', 'hwt_upload_csv');
        formData.append('nonce', hwtPIM.uploadNonce);
        formData.append('blog_id', hwtPIM.blogId);
        formData.append('csv_file', fileInput.files[0]);
        formData.append('skus', $('#hwt-import-skus').val());
        formData.append('overwrite', $('#hwt-overwrite').is(':checked') ? '1' : '0');
        formData.append('batch_size', $('#hwt-batch-size').val());
        formData.append('dry_run', $('#hwt-dry-run').is(':checked') ? '1' : '0');

        $.ajax({
            url: hwtPIM.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function (res) {
                $btn.removeClass('loading');

                if (!res.success) {
                    alert('Error: ' + res.data);
                    return;
                }

                totalRows = res.data.total;
                stats = { imported: 0, skipped: 0, failed: 0 };
                currentLogFile = '';

                // Show progress area.
                $('#hwt-progress-area').removeClass('hwt-hidden');
                $('#hwt-pause-btn').removeClass('hwt-hidden');
                $('#hwt-complete').addClass('hwt-hidden');
                $('#hwt-log').empty();
                updateStats();
                updateProgress(0, totalRows);
                $('#hwt-current-sku').text('Starting import...');

                // Disable import controls.
                $btn.prop('disabled', true).addClass('loading');
                importing = true;
                paused = false;

                // Start batch processing.
                processBatch(0);
            },
            error: function () {
                $btn.removeClass('loading');
                alert('Upload failed. Please try again.');
            },
        });
    });

    // =========================================================================
    // Pause / Resume
    // =========================================================================
    $('#hwt-pause-btn').on('click', function () {
        paused = !paused;

        if (paused) {
            $(this).find('.hwt-btn-text').text('Resume');
            $('#hwt-current-sku').text('Paused.');
        } else {
            $(this).find('.hwt-btn-text').text('Pause');
            // Resume from the current offset stored as data attribute.
            var nextOffset = parseInt($(this).data('next-offset'), 10) || 0;
            processBatch(nextOffset);
        }
    });

    // =========================================================================
    // Batch processing loop
    // =========================================================================
    function processBatch(offset, retries) {
        if (paused) {
            $('#hwt-pause-btn').data('next-offset', offset);
            return;
        }

        retries = retries || 0;

        $.ajax({
            url: hwtPIM.ajaxUrl,
            type: 'POST',
            data: {
                action: 'hwt_process_batch',
                nonce: hwtPIM.batchNonce,
                blog_id: hwtPIM.blogId,
                offset: offset,
            },
            success: function (res) {
                if (!res.success) {
                    if (retries < 2) {
                        setTimeout(function () {
                            processBatch(offset, retries + 1);
                        }, 3000);
                    } else {
                        importError('Batch failed after retries: ' + res.data);
                    }
                    return;
                }

                var d = res.data;
                currentLogFile = d.log_file || currentLogFile;

                // Process results.
                if (d.results && d.results.length) {
                    for (var i = 0; i < d.results.length; i++) {
                        var r = d.results[i];
                        addLogEntry(r);
                        updateStatsFromResult(r);
                    }
                }

                updateProgress(d.offset, d.total);

                if (d.done) {
                    importComplete();
                } else {
                    // Show current SKU hint.
                    if (d.results && d.results.length) {
                        var lastSku = d.results[d.results.length - 1].sku;
                        $('#hwt-current-sku').text('Processing... last processed SKU: ' + lastSku);
                    }

                    // Next batch.
                    processBatch(d.offset);
                }
            },
            error: function () {
                if (retries < 2) {
                    setTimeout(function () {
                        processBatch(offset, retries + 1);
                    }, 3000);
                } else {
                    importError('Network error after retries. Import paused.');
                }
            },
        });
    }

    // =========================================================================
    // Scan Missing Images
    // =========================================================================
    $('#hwt-scan-btn').on('click', function (e) {
        e.preventDefault();
        var $btn = $(this);

        if ($btn.hasClass('loading')) return;
        $btn.addClass('loading');

        var scanType = $('input[name="hwt-scan-type"]:checked').val();

        $.ajax({
            url: hwtPIM.ajaxUrl,
            type: 'POST',
            data: {
                action: 'hwt_scan_missing',
                nonce: hwtPIM.scanNonce,
                blog_id: hwtPIM.blogId,
                scan_type: scanType,
            },
            success: function (res) {
                $btn.removeClass('loading');

                if (!res.success) {
                    alert('Error: ' + res.data);
                    return;
                }

                var d = res.data;
                var skuList = [];
                var detailHtml = '';
                var hasGalleryOnly = false;

                for (var i = 0; i < d.missing_skus.length; i++) {
                    var item = d.missing_skus[i];
                    skuList.push(item.sku);

                    var badges = '';
                    var detail = item.detail || '';
                    if (detail.indexOf('no featured') !== -1) {
                        badges += '<span class="hwt-log-badge hwt-log-badge-error">no featured</span> ';
                    }
                    if (detail.indexOf('no gallery') !== -1) {
                        badges += '<span class="hwt-log-badge hwt-log-badge-skipped">no gallery</span> ';
                        if (detail.indexOf('no featured') === -1) {
                            hasGalleryOnly = true;
                        }
                    }

                    detailHtml += '<tr><td><strong>' + escHtml(item.sku) + '</strong></td><td>' + badges + '</td></tr>';
                }

                // Show results.
                $('#hwt-scan-results').removeClass('hwt-hidden');
                $('#hwt-scan-count').text(d.missing_count + ' products missing images');
                $('#hwt-scan-summary').text(
                    'Scanned ' + d.total_scanned + ' products. ' +
                    d.missing_count + ' are missing images.'
                );
                $('#hwt-scan-detail-body').html(detailHtml);
                $('#hwt-scan-detail-list').removeClass('hwt-hidden');
                $('#hwt-scan-skus-output').val(skuList.join('\n'));

                // Show info note if any products are missing only gallery.
                if (hasGalleryOnly) {
                    $('#hwt-scan-info').removeClass('hwt-hidden').css('display', 'flex');
                } else {
                    $('#hwt-scan-info').addClass('hwt-hidden');
                }
            },
            error: function () {
                $btn.removeClass('loading');
                alert('Scan failed. Please try again.');
            },
        });
    });

    // Copy SKUs to clipboard.
    $('#hwt-scan-copy').on('click', function (e) {
        e.preventDefault();
        var textarea = document.getElementById('hwt-scan-skus-output');
        textarea.select();
        document.execCommand('copy');

        var $btn = $(this);
        $btn.find('.hwt-btn-text').text('Copied!');
        setTimeout(function () {
            $btn.find('.hwt-btn-text').text('Copy to Clipboard');
        }, 2000);
    });

    // Use scanned SKUs in Import tab.
    $('#hwt-scan-use-import').on('click', function (e) {
        e.preventDefault();
        var skus = $('#hwt-scan-skus-output').val();
        $('#hwt-import-skus').val(skus);

        // Switch to import tab.
        switchTab('import');

        // Scroll to the SKU filter field and flash it.
        setTimeout(function () {
            $('#hwt-import-skus')[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
            $('#hwt-import-skus').css({ 'background-color': '#d1e7dd', 'border-color': '#198754' });
            setTimeout(function () {
                $('#hwt-import-skus').css({ 'background-color': '', 'border-color': '' });
            }, 2000);
        }, 300);
    });

    // Copy diagnostics to clipboard.
    $('#hwt-diag-copy').on('click', function (e) {
        e.preventDefault();
        var textarea = document.getElementById('hwt-diag-output');
        textarea.select();
        document.execCommand('copy');

        var $btn = $(this);
        $btn.find('.hwt-btn-text').text('Copied!');
        setTimeout(function () {
            $btn.find('.hwt-btn-text').text('Copy to Clipboard');
        }, 2000);
    });

    // =========================================================================
    // History: Run selector
    // =========================================================================
    $('#hwt-run-select').on('change', function () {
        var url = new URL(window.location.href);
        url.searchParams.set('hwt_run', $(this).val());
        window.location.href = url.toString();
    });

    // =========================================================================
    // History: Search & Filter
    // =========================================================================
    var activeFilter = 'all';

    // Strip accents: Hermès → hermes, Zürich → zurich, etc.
    function stripAccents(str) {
        return str.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }

    function filterHistoryTable() {
        var query = stripAccents(($('#hwt-history-search').val() || '').toLowerCase());
        var visible = 0;

        $('.hwt-history-table tbody tr').each(function () {
            var $row = $(this);
            var sku = stripAccents(String($row.attr('data-sku') || '').toLowerCase());
            var title = stripAccents(String($row.attr('data-title') || '').toLowerCase());
            var status = String($row.attr('data-status') || '').toLowerCase();

            var matchesSearch = !query || sku.indexOf(query) !== -1 || title.indexOf(query) !== -1;
            var matchesFilter = activeFilter === 'all' || status === activeFilter;

            if (matchesSearch && matchesFilter) {
                $row.show();
                visible++;
            } else {
                $row.hide();
            }
        });

        if (visible === 0) {
            $('#hwt-history-empty').show();
            $('.hwt-history-list').hide();
        } else {
            $('#hwt-history-empty').hide();
            $('.hwt-history-list').show();
        }
    }

    $('#hwt-history-search').on('input', filterHistoryTable);

    $(document).on('click', '.hwt-filter-btn', function (e) {
        e.preventDefault();
        $('.hwt-filter-btn').removeClass('active');
        $(this).addClass('active');
        activeFilter = $(this).data('filter');
        filterHistoryTable();
    });

    // =========================================================================
    // Cleanup (with in-page modal)
    // =========================================================================
    $('#hwt-cleanup-trigger').on('click', function (e) {
        e.preventDefault();
        $('#hwt-modal-overlay').removeClass('hwt-hidden');
    });

    $('#hwt-modal-cancel').on('click', function (e) {
        e.preventDefault();
        $('#hwt-modal-overlay').addClass('hwt-hidden');
    });

    // Close modal on overlay click.
    $('#hwt-modal-overlay').on('click', function (e) {
        if (e.target === this) {
            $(this).addClass('hwt-hidden');
        }
    });

    // Close modal on Escape key.
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && !$('#hwt-modal-overlay').hasClass('hwt-hidden')) {
            $('#hwt-modal-overlay').addClass('hwt-hidden');
        }
    });

    $('#hwt-cleanup-btn').on('click', function (e) {
        e.preventDefault();
        var $btn = $(this);
        if ($btn.hasClass('loading')) return;

        $btn.addClass('loading');

        $.ajax({
            url: hwtPIM.ajaxUrl,
            type: 'POST',
            data: {
                action: 'hwt_cleanup',
                nonce: hwtPIM.exportNonce,
            },
            success: function (res) {
                $btn.removeClass('loading');
                $('#hwt-modal-overlay').addClass('hwt-hidden');
                if (res.success) {
                    $('#hwt-cleanup-result').text(
                        'Done! Deleted ' + res.data.deleted + ' attachments, cleared ' + res.data.products_cleared + ' products.'
                    ).css('color', '#198754');
                } else {
                    $('#hwt-cleanup-result').text('Error: ' + res.data).css('color', '#dc3545');
                }
            },
            error: function () {
                $btn.removeClass('loading');
                $('#hwt-modal-overlay').addClass('hwt-hidden');
                $('#hwt-cleanup-result').text('Request failed.').css('color', '#dc3545');
            },
        });
    });

    // =========================================================================
    // Diagnostics
    // =========================================================================
    $('#hwt-diag-btn').on('click', function (e) {
        e.preventDefault();
        var $btn = $(this);
        if ($btn.hasClass('loading')) return;
        $btn.addClass('loading');

        $.ajax({
            url: hwtPIM.ajaxUrl,
            type: 'POST',
            data: {
                action: 'hwt_diagnostics',
                nonce: hwtPIM.exportNonce,
                blog_id: hwtPIM.blogId,
            },
            success: function (res) {
                $btn.removeClass('loading');
                if (res.success) {
                    $('#hwt-diag-output').val(res.data);
                    $('#hwt-diag-results').removeClass('hwt-hidden');
                } else {
                    alert('Error: ' + res.data);
                }
            },
            error: function () {
                $btn.removeClass('loading');
                alert('Diagnostics request failed.');
            },
        });
    });

    // =========================================================================
    // Helpers
    // =========================================================================
    function updateProgress(current, total) {
        var pct = total > 0 ? Math.round((current / total) * 100) : 0;
        $('#hwt-progress-fill').css('width', pct + '%');
        $('#hwt-progress-text').text(current + ' / ' + total);
    }

    function updateStatsFromResult(r) {
        if (r.status === 'success' || r.status === 'partial') {
            stats.imported += (r.images_imported || 0);
            stats.skipped += (r.images_skipped || 0);
            if (r.status === 'partial') stats.failed++;
        } else if (r.status === 'error') {
            stats.failed++;
        } else if (r.status === 'skipped') {
            stats.skipped++;
        }
        updateStats();
    }

    function updateStats() {
        $('#hwt-stat-imported').text(stats.imported);
        $('#hwt-stat-skipped').text(stats.skipped);
        $('#hwt-stat-failed').text(stats.failed);
    }

    function addLogEntry(r) {
        var badgeClass = 'hwt-log-badge-' + r.status;
        var badgeText = r.status;

        var $entry = $('<div class="hwt-log-entry">' +
            '<span class="hwt-log-badge ' + badgeClass + '">' + badgeText + '</span>' +
            '<span class="hwt-log-sku">' + escHtml(r.sku) + '</span>' +
            '<span class="hwt-log-msg">' + escHtml(r.message) + '</span>' +
            '</div>');

        var $log = $('#hwt-log');
        $log.append($entry);

        // Auto-scroll to bottom.
        $log.scrollTop($log[0].scrollHeight);
    }

    function importComplete() {
        importing = false;
        paused = false;
        $('#hwt-current-sku').text('');
        $('#hwt-import-btn').prop('disabled', false).removeClass('loading');
        $('#hwt-pause-btn').addClass('hwt-hidden');
        $('#hwt-complete').removeClass('hwt-hidden');

        // Set up log download link.
        if (currentLogFile) {
            var logUrl = hwtPIM.ajaxUrl + '?action=hwt_download_log&nonce=' +
                encodeURIComponent(hwtPIM.downloadNonce) + '&file=' +
                encodeURIComponent(currentLogFile);
            $('#hwt-download-log').attr('href', logUrl);
        }
    }

    function importError(msg) {
        paused = true;
        $('#hwt-current-sku').text('Error: ' + msg);
        $('#hwt-pause-btn').find('.hwt-btn-text').text('Resume');
    }

    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

})(jQuery);
