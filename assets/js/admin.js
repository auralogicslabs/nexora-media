/* global nxmAdmin, jQuery */
(function ($) {
    'use strict';

    const NXM = {
        timer: null,
        manualRunActive: false,
        reloadOnQueueComplete: false,

        init() {
            this.hydrateInitialStats();
            this.bindFormSubmit();
            this.bindToggles();
            this.bindNumberSettings();
            this.bindRuleSave();
            this.bindRetroButton();
            this.bindStopQueue();
            this.bindOptimizeOne();
            this.bindSyncOne();
            this.bindDeliveryMode();
            this.bindCssCache();
            this.bindWizard();
            this.bindDiagnostic();
            this.bindRailNav();
            this.resumeQueueStateOnLoad();
            this.pollQueueStatus();
        },

        resumeQueueStateOnLoad() {
            const pending = Number(nxmAdmin.queue_count || 0);
            const paused = Boolean(nxmAdmin.processing_paused);

            if (paused || pending <= 0) {
                return;
            }

            $('#nxm-progress-panel').addClass('is-active');
            if (NXM.autoProcessEnabled() || nxmAdmin.queue_running) {
                NXM.manualRunActive = true;
            }
            if (NXM.autoProcessEnabled()) {
                this.kickWorker();
            }
        },

        hydrateInitialStats() {
            if (!nxmAdmin.stats) {
                return;
            }
            $('#nxm-stat-webp').text(nxmAdmin.stats.webp_count || 0);
            $('#nxm-stat-processed').text(nxmAdmin.stats.processed || 0);
        },

        bindFormSubmit() {
            $(document).on('submit', '.nxm-control-form', function (event) {
                event.preventDefault();
                NXM.setStatus('Controls save automatically.', 'info');
            });
        },

        bindToggles() {
            $(document).on('change', '.nxm-toggle-live', function () {
                const $input = $(this);
                const enabled = $input.is(':checked') ? 1 : 0;
                const $scope = $input.closest('.nxm-switch-row, .nxm-setting-row, .nxm-format-card');
                const $label = $scope.find('.nxm-toggle-label').first();

                if ($label.length) {
                    $label.text(enabled ? 'ON' : 'OFF')
                        .removeClass('nxm-on nxm-off')
                        .addClass(enabled ? 'nxm-on' : 'nxm-off');
                }

                $.post(nxmAdmin.ajax_url, {
                    action: 'nxm_save_option',
                    nonce: nxmAdmin.nonce,
                    option: $input.data('option'),
                    value: enabled
                }, function (res) {
                    if (res && res.success) {
                        NXM.setStatus(res.data && res.data.message ? res.data.message : 'Control saved.', 'success');
                    } else {
                        NXM.setStatus('Setting could not be saved. Please refresh and try again.', 'error');
                    }
                }).fail(function () {
                    NXM.setStatus('Setting could not be saved. Please refresh and try again.', 'error');
                });
            });
        },

        bindNumberSettings() {
            let saveTimer = null;
            $(document).on('input change', '.nxm-number-live', function () {
                const $input = $(this);
                window.clearTimeout(saveTimer);
                saveTimer = window.setTimeout(function () {
                    $.post(nxmAdmin.ajax_url, {
                        action: 'nxm_save_option',
                        nonce: nxmAdmin.nonce,
                        option: $input.data('option'),
                        value: $input.val()
                    }, function (res) {
                        if (res && res.success) {
                            if (res.data && typeof res.data.value !== 'undefined') {
                                $input.val(res.data.value);
                            }
                            NXM.setStatus(res.data && res.data.message ? res.data.message : 'Control saved.', 'success');
                        } else {
                            NXM.setStatus('Control could not be saved.', 'error');
                        }
                    }).fail(function () {
                        NXM.setStatus('Control could not be saved. Please refresh and try again.', 'error');
                    });
                }, 500);
            });
        },

        bindRuleSave() {
            $(document).on('click', '.nxm-save-rule', function () {
                $('.nxm-number-live').trigger('change');
                NXM.setStatus('Optimization rules saved.', 'success');
            });
        },

        bindRetroButton() {
            $('#nxm-retro-btn').on('click', function () {
                const $btn = $(this);
                $btn.prop('disabled', true).text('Scanning library...');
                NXM.setStatus('Scanning the media library and adding images to the optimization queue...', 'info');

                $.post(nxmAdmin.ajax_url, {
                    action: 'nxm_queue_all',
                    nonce: nxmAdmin.nonce
                }, function (res) {
                    if (res.success) {
                        NXM.manualRunActive = true;
                        $btn.text('Optimizing...');
                        const queued = Number(res.data.count || 0);
                        const skipped = Number(res.data.skipped || 0);
                        if (queued > 0) {
                            NXM.reloadOnQueueComplete = true;
                            NXM.setStatus('Queued ' + queued + ' images. ' + skipped + ' already optimized images were skipped.', 'success');
                            NXM.kickWorker();
                            NXM.pollQueueStatus(true);
                        } else {
                            $btn.prop('disabled', false).text('Optimize Images');
                            NXM.manualRunActive = false;
                            NXM.setStatus('No new images need optimization. Existing optimized images were skipped.', 'success');
                            NXM.pollQueueStatus(true);
                        }
                    } else {
                        $btn.prop('disabled', false).text('Optimize Images');
                        NXM.setStatus('Could not queue images for optimization.', 'error');
                    }
                }).fail(function () {
                    $btn.prop('disabled', false).text('Optimize Images');
                    NXM.setStatus('Queue request failed. Check your WordPress session and try again.', 'error');
                });
            });
        },

        bindStopQueue() {
            $('#nxm-stop-queue').on('click', function () {
                const $btn = $(this);
                $btn.prop('disabled', true).text('Stopping...');
                NXM.manualRunActive = false;
                NXM.setStatus('Stopping optimization and clearing the current queue...', 'info');

                $.post(nxmAdmin.ajax_url, {
                    action: 'nxm_stop_queue',
                    nonce: nxmAdmin.nonce
                }, function (res) {
                    $btn.prop('disabled', false).text('Stop');
                    $('#nxm-retro-btn').prop('disabled', false).text('Optimize Images');
                    $('#nxm-stat-pending').text('0');
                    NXM.updateProgressUI({
                        pending: 0,
                        total: 0,
                        done: 0,
                        percent: 0,
                        paused: true,
                        running: false,
                        current_label: '',
                        failed: 0
                    });
                    if (NXM.timer) {
                        clearInterval(NXM.timer);
                        NXM.timer = null;
                    }
                    NXM.setStatus(res && res.success && res.data && res.data.message ? res.data.message : 'Optimization stopped.', 'success');
                    NXM.reloadDashboard('Optimization stopped. Refreshing the media report...');
                }).fail(function () {
                    $btn.prop('disabled', false).text('Stop');
                    NXM.setStatus('Stop request failed. Refresh the admin page and try again.', 'error');
                });
            });
        },

        bindOptimizeOne() {
            $(document).on('click', '.nxm-optimize-one', function () {
                const $btn = $(this);
                const id = $btn.data('id');
                const $row = $btn.closest('[data-attachment-id]');
                const originalLabel = $btn.text();

                const isRecheck = /recheck/i.test(originalLabel);
                $btn.prop('disabled', true).text(isRecheck ? 'Checking...' : 'Optimizing...');
                NXM.setStatus(isRecheck ? 'Checking optimized variant status...' : 'Optimizing selected image and checking generated delivery variants...', 'info');

                $.ajax({
                    url: nxmAdmin.ajax_url,
                    method: 'POST',
                    timeout: 30000,
                    data: {
                    action: 'nxm_optimize_attachment',
                    nonce: nxmAdmin.nonce,
                    attachment_id: id
                    }
                }).done(function (res) {
                    if (!res.success || !res.data || !res.data.row) {
                        $btn.prop('disabled', false).text(originalLabel || 'Optimize');
                        NXM.setStatus('Selected image could not be optimized.', 'error');
                        return;
                    }

                    const item = res.data.row;
                    NXM.updateCardShell($row, item);
                    $row.find('.nxm-media-status')
                        .removeClass('nxm-media-status--pending nxm-media-status--optimized nxm-media-status--needs-optimization nxm-media-status--original-delivery')
                        .addClass('nxm-media-status--' + item.status)
                        .text(item.status_label || item.status);
                    $row.find('.nxm-best-size').text(item.best_bytes_label || 'Not ready');
                    $row.find('.nxm-source-size').text(item.source_bytes_label || item.working_bytes_label || '-');
                    $row.find('.nxm-saved-size').text(item.saved_label || '-');
                    $row.find('.nxm-format-list').text(item.formats_label || 'No variant yet');
                    NXM.updateSyncState($row, item);
                    $row.find('.nxm-updated-label').text(item.updated_label || 'Just now');
                    if (item.optimize_enabled) {
                        $btn.prop('disabled', false).text(item.optimize_label || 'Optimize');
                    } else {
                        $btn.prop('disabled', true).text(item.optimize_label || 'Optimized');
                    }
                    $row.find('.nxm-toggle-delivery').text(item.delivery_label || 'Use original');

                    if (res.data.queued) {
                        NXM.manualRunActive = true;
                    }
                    NXM.setStatus(res.data.message || 'Image optimized. Refresh the frontend and check the Network Images filter for WebP delivery.', res.data.queued ? 'info' : 'success');
                    NXM.pollQueueStatus(true);
                }).fail(function (xhr) {
                    $btn.prop('disabled', false).text(originalLabel || 'Optimize');
                    const message = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
                        ? xhr.responseJSON.data.message
                        : 'Request timed out or failed. No image files were changed.';
                    NXM.setStatus(message, 'error');
                });
            });
        },

        bindSyncOne() {
            $(document).on('click', '.nxm-sync-one', function () {
                const $btn = $(this);
                const id = $btn.data('id');
                const $row = $btn.closest('[data-attachment-id]');
                const originalLabel = $btn.text();

                $btn.prop('disabled', true).text('Syncing...');
                NXM.setStatus('Syncing optimized delivery with the public frontend...', 'info');

                $.post(nxmAdmin.ajax_url, {
                    action: 'nxm_sync_attachment',
                    nonce: nxmAdmin.nonce,
                    attachment_id: id
                }, function (res) {
                    if (!res.success || !res.data || !res.data.row) {
                        $btn.prop('disabled', false).text(originalLabel || 'Sync');
                        NXM.setStatus('Frontend sync could not be completed.', 'error');
                        return;
                    }

                    const item = res.data.row;
                    NXM.updateSyncState($row, item);
                    $row.find('.nxm-toggle-delivery').text(item.delivery_label || 'Use original');
                    NXM.setStatus(res.data.message || 'Frontend sync is ready.', 'success');
                }).fail(function (xhr) {
                    $btn.prop('disabled', false).text(originalLabel || 'Sync');
                    const message = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
                        ? xhr.responseJSON.data.message
                        : 'Frontend sync request failed.';
                    NXM.setStatus(message, 'error');
                });
            });
        },

        bindDeliveryMode() {
            $(document).on('click', '.nxm-toggle-delivery', function () {
                const $btn = $(this);
                const id = $btn.data('id');
                const $row = $btn.closest('[data-attachment-id]');

                $btn.prop('disabled', true).text('Updating...');
                NXM.setStatus('Updating image delivery mode...', 'info');

                $.post(nxmAdmin.ajax_url, {
                    action: 'nxm_toggle_delivery_mode',
                    nonce: nxmAdmin.nonce,
                    attachment_id: id
                }, function (res) {
                    if (!res.success || !res.data || !res.data.row) {
                        $btn.prop('disabled', false).text('Use original');
                        NXM.setStatus('Delivery mode could not be updated.', 'error');
                        return;
                    }

                    const item = res.data.row;
                    NXM.updateCardShell($row, item);
                    $row.find('.nxm-media-status')
                        .removeClass('nxm-media-status--pending nxm-media-status--optimized nxm-media-status--needs-optimization nxm-media-status--original-delivery')
                        .addClass('nxm-media-status--' + item.status)
                        .text(item.status_label || item.status);
                    NXM.updateSyncState($row, item);
                    const $optimize = $row.find('.nxm-optimize-one');
                    if (item.optimize_enabled) {
                        $optimize.prop('disabled', false).text(item.optimize_label || 'Optimize');
                    } else {
                        $optimize.prop('disabled', true).text(item.optimize_label || 'Optimized');
                    }
                    $btn.prop('disabled', false).text(item.delivery_label || 'Use original');
                    NXM.setStatus(res.data.message || 'Delivery mode updated.', 'success');
                }).fail(function () {
                    $btn.prop('disabled', false).text('Use original');
                    NXM.setStatus('Delivery mode request failed.', 'error');
                });
            });
        },

        bindWizard() {
            $('#nxm-finish-wizard, #nxm-start-first-build').on('click', function () {
                const $btn = $(this);
                const startBuild = $btn.attr('id') === 'nxm-start-first-build';
                const originalText = $btn.text();
                $btn.prop('disabled', true).text(startBuild ? 'Preparing...' : 'Applying...');

                $.post(nxmAdmin.ajax_url, {
                    action: 'nxm_complete_wizard',
                    nonce: nxmAdmin.nonce,
                    apply_recommended: 1
                }, function (res) {
                    if (res.success) {
                        NXM.revealWorkspace();
                        NXM.setStatus(res.data && res.data.message ? res.data.message : 'Recommended setup applied.', 'success');
                        if (startBuild) {
                            NXM.reloadOnQueueComplete = true;
                            window.setTimeout(function () {
                                $('#nxm-retro-btn').trigger('click');
                            }, 250);
                        } else {
                            NXM.reloadDashboard('Recommended settings applied. Opening your media dashboard...');
                        }
                    } else {
                        $btn.prop('disabled', false).text(originalText);
                    }
                }).fail(function () {
                    $btn.prop('disabled', false).text(originalText);
                    NXM.setStatus('Setup could not be applied. Please refresh and try again.', 'error');
                });
            });
        },

        bindCssCache() {
            $('#nxm-purge-css-cache').on('click', function () {
                const $btn = $(this);
                $btn.prop('disabled', true).text('Purging...');
                NXM.setStatus('Purging CSS Optimization Cache...', 'info');

                $.post(nxmAdmin.ajax_url, {
                    action: 'nxm_purge_css_cache',
                    nonce: nxmAdmin.nonce
                }, function (res) {
                    $btn.prop('disabled', false).text('Purge CSS Cache');
                    if (res.success) {
                        NXM.setStatus(res.data.message, 'success');
                    } else {
                        NXM.setStatus('CSS cache could not be purged.', 'error');
                    }
                }).fail(function () {
                    $btn.prop('disabled', false).text('Purge CSS Cache');
                    NXM.setStatus('CSS cache purge request failed.', 'error');
                });
            });
        },

        bindDiagnostic() {
            $('#nxm-test-worker').on('click', function () {
                const $btn = $(this);
                const originalText = $btn.text();
                $btn.prop('disabled', true).text('Running...');
                NXM.setStatus('Running image engine diagnostic...', 'info', false);

                $.post(nxmAdmin.ajax_url, {
                    action: 'nxm_diagnostic',
                    nonce: nxmAdmin.nonce
                }, function (res) {
                    $btn.prop('disabled', false).text(originalText);
                    if (!res.success || !res.data) {
                        NXM.setStatus('Diagnostic failed.', 'error');
                        return;
                    }

                    NXM.renderDiagnosticPanel(res.data);
                    const d = res.data;
                    const type = d.engine !== 'none' ? 'success' : 'error';
                    NXM.setStatus('Engine: ' + d.engine + ' - WebP: ' + (d.webp ? 'ready' : 'unavailable') + ' - Memory: ' + d.memory, type, false);
                }).fail(function () {
                    $btn.prop('disabled', false).text(originalText);
                    NXM.setStatus('Diagnostic request failed.', 'error');
                });
            });

            $('#nxm-diagnostic-close').on('click', function () {
                $('#nxm-diagnostic-panel').prop('hidden', true);
            });
        },

        bindRailNav() {
            $(document).on('click', '.nxm-rail-nav a', function () {
                $('.nxm-rail-nav a').removeClass('active');
                $(this).addClass('active');
            });
        },

        renderDiagnosticPanel(data) {
            const rows = [
                ['Engine', data.engine || 'none'],
                ['WebP support', data.webp ? 'Available' : 'Unavailable'],
                ['AVIF support', data.avif ? 'Available' : 'Unavailable'],
                ['PHP version', data.php_version || '-'],
                ['Memory in use', data.memory || '-'],
                ['Memory limit', data.memory_limit || '-'],
                ['Plugin version', data.plugin_version || '-'],
                ['Queue pending', String(data.queue_pending || 0)],
                ['Worker locked', data.queue_locked ? 'Yes' : 'No'],
                ['Optimization paused', data.queue_paused ? 'Yes' : 'No']
            ];

            const $body = $('#nxm-diagnostic-body').empty();
            rows.forEach(function (row) {
                $('<dt></dt>').text(row[0]).appendTo($body);
                $('<dd></dd>').text(row[1]).appendTo($body);
            });

            $('#nxm-diagnostic-panel').prop('hidden', false);
        },

        updateProgressUI(d) {
            const pending = Number(d.pending || 0);
            const total = Number(typeof d.total !== 'undefined' ? d.total : (d.current_total || pending));
            const done = Number(typeof d.done !== 'undefined' ? d.done : Math.max(0, total - pending));
            const pct = Number(typeof d.percent !== 'undefined' ? d.percent : (total > 0 ? Math.round((done / total) * 100) : (pending > 0 ? 0 : 100)));
            const paused = Boolean(d.paused);
            const running = Boolean(d.running || d.locked);
            const failed = Number(d.failed || 0);

            $('#nxm-progress-panel').toggleClass('is-active', pending > 0 || running);
            $('#nxm-queue-bar-fill').css('width', paused ? '0%' : pct + '%');
            $('#nxm-queue-pct').text(
                paused ? 'Paused' : (pending > 0 ? done + '/' + total + ' - ' + pct + '%' : 'Idle')
            );

            const $indicator = $('#nxm-progress-indicator');
            $indicator.toggleClass('is-running', !paused && running);

            let currentText = 'No active batch';
            if (d.current_label) {
                currentText = 'Processing ' + d.current_label;
            } else if (pending > 0 && running) {
                currentText = 'Starting next batch...';
            } else if (pending > 0) {
                currentText = 'Waiting for your confirmation';
            }
            $('#nxm-progress-current').text(currentText);

            const $failed = $('#nxm-progress-failed');
            if (failed > 0) {
                $failed.text(failed + ' failed (lifetime)').prop('hidden', false);
            } else {
                $failed.prop('hidden', true);
            }
        },

        kickWorker() {
            NXM.setStatus('Processing one safe image batch. Large uploads may take a little longer on the first item.', 'info', false);
            $.ajax({
                url: nxmAdmin.ajax_url,
                method: 'POST',
                timeout: 120000,
                data: {
                    action: 'nxm_async_process',
                    nonce: nxmAdmin.nonce
                }
            }).done(function (res) {
                if (res.success && res.data && res.data.locked) {
                    NXM.setStatus('Optimization worker is already running. Queue status will update shortly.', 'info');
                }
            }).fail(function () {
                NXM.manualRunActive = false;
                $('#nxm-retro-btn').prop('disabled', false).text('Optimize Images');
                NXM.setStatus('The current image took too long or the server stopped the request. The queue was not continued automatically; click Optimize Images again to resume safely.', 'error');
            });
        },

        autoProcessEnabled() {
            return Boolean(nxmAdmin && (nxmAdmin.auto_process === true || nxmAdmin.auto_process === '1' || nxmAdmin.auto_process === 1));
        },

        updateSyncState($row, item) {
            $row.find('.nxm-sync-status')
                .removeClass('nxm-sync-status--synced nxm-sync-status--active nxm-sync-status--native nxm-sync-status--safe-mode nxm-sync-status--not-synced nxm-sync-status--blocked')
                .addClass('nxm-sync-status--' + (item.sync_status || 'blocked'))
                .text(item.sync_label || 'Not ready');
            $row.find('.nxm-sync-note').text(item.sync_note || '');

            const $actions = $row.find('.nxm-row-actions');
            let $sync = $actions.find('.nxm-sync-one');
            let $state = $actions.find('.nxm-action-state');

            if (item.sync_enabled) {
                if (!$sync.length) {
                    $sync = $('<button type="button" class="button nxm-sync-one"></button>');
                    $sync.attr('data-id', $row.data('attachment-id'));
                    if ($state.length) {
                        $state.replaceWith($sync);
                    } else {
                        $actions.prepend($sync);
                    }
                }
                $sync.prop('disabled', false).text(item.sync_button_label || 'Sync');
            } else {
                if ($sync.length) {
                    $sync.remove();
                }
                if ($state.length) {
                    $state.remove();
                }
            }
        },

        updateCardShell($row, item) {
            if (!$row || !$row.length || !item || !item.status) {
                return;
            }
            $row.removeClass('nxm-media-card--pending nxm-media-card--optimized nxm-media-card--needs-optimization nxm-media-card--original-delivery')
                .addClass('nxm-media-card--' + item.status);
        },

        pollQueueStatus(force) {
            if (this.timer) {
                clearInterval(this.timer);
            }

            const poll = function () {
                $.post(nxmAdmin.ajax_url, {
                    action: 'nxm_queue_status',
                    nonce: nxmAdmin.nonce
                }, function (res) {
                    if (!res.success) {
                        return;
                    }

                    const d = res.data;
                    if (d.paused) {
                        NXM.manualRunActive = false;
                        $('#nxm-stat-pending').text('0');
                        NXM.updateProgressUI(d);
                        $('#nxm-retro-btn').prop('disabled', false).text('Optimize Images');
                        $('#nxm-build-copy').text('Optimization is paused. Start the build when the site is ready.');
                        NXM.setStatus('Optimization is paused. No background worker is running.', 'info', false);
                        clearInterval(NXM.timer);
                        NXM.timer = null;
                        return;
                    }

                    const pending = Number(d.pending || 0);
                    const total = Number(d.total || pending);
                    const done = Number(typeof d.done !== 'undefined' ? d.done : Math.max(0, total - pending));
                    const pct = Number(typeof d.percent !== 'undefined' ? d.percent : (total > 0 ? Math.round((done / total) * 100) : 100));

                    $('#nxm-stat-pending').text(pending);
                    $('#nxm-stat-processed').text(done);
                    $('#nxm-stat-processed-note').text(total > 0 ? 'of ' + total + ' in this run' : 'No active run');
                    $('#nxm-stat-webp').text(d.webp_count || 0);
                    NXM.updateProgressUI(d);
                    if (typeof d.library_total !== 'undefined') {
                        const libraryTotal = Number(d.library_total || 0);
                        const readyCount = Number(d.webp_count || 0);
                        const optimizedCount = Number(typeof d.optimized_count !== 'undefined' ? d.optimized_count : readyCount);
                        const optimizedPct = Number(typeof d.optimized_percent !== 'undefined' ? d.optimized_percent : (libraryTotal > 0 ? Math.round((optimizedCount / libraryTotal) * 100) : 0));
                        const libraryPending = Math.max(0, libraryTotal - optimizedCount);
                        $('#nxm-stat-webp-note').text('of ' + libraryTotal + ' library images');
                        $('#nxm-library-ready-ring').css('--nxm-ring-value', optimizedPct + '%');
                        $('#nxm-library-ready-pct').text(optimizedPct + '%');
                        $('#nxm-library-ready-text').text(optimizedCount + ' optimized - ' + libraryPending + ' pending - ' + libraryTotal + ' total');
                    }

                    if (pending > 0) {
                        const manualMessage = pending + ' images are queued. Click Optimize Images to continue this build.';
                        const autoMessage = d.locked && done === 0
                            ? 'Processing the first image. Large uploads can take longer, but the worker is active.'
                            : 'Current build: ' + done + ' of ' + total + ' complete. ' + pending + ' remaining.';
                        const shouldProcess = NXM.autoProcessEnabled() || NXM.manualRunActive;
                        $('#nxm-retro-btn').prop('disabled', shouldProcess).text(shouldProcess ? 'Optimizing ' + pct + '%' : 'Optimize Images');
                        $('#nxm-build-copy').text(shouldProcess ? 'Low-impact optimization is running. You can keep working while Nexora processes one safe batch at a time.' : 'Optimization is queued and waiting for your confirmation.');
                        NXM.setStatus(shouldProcess ? autoMessage : manualMessage, 'info', false);
                        if (shouldProcess && !d.locked) {
                            NXM.kickWorker();
                        }
                        return;
                    }

                    const shouldReload = NXM.reloadOnQueueComplete || NXM.manualRunActive;
                    NXM.manualRunActive = false;
                    NXM.reloadOnQueueComplete = false;
                    $('#nxm-retro-btn').prop('disabled', false).text('Optimize Images');
                    $('#nxm-build-copy').text('Queue is clear. New images wait for your next manual run.');
                    NXM.updateProgressUI({ pending: 0, total: 0, done: 0, percent: 0, running: false, current_label: '', failed: d.failed || 0 });
                    if (force || Number(nxmAdmin.queue_count || 0) > 0) {
                        NXM.setStatus('Queue is clear. All available images are optimized.', 'success', Boolean(force));
                    }
                    clearInterval(NXM.timer);
                    NXM.timer = null;
                    if (shouldReload) {
                        NXM.reloadDashboard('Optimization complete. Refreshing the media report...');
                    }
                }).fail(function () {
                    NXM.manualRunActive = false;
                    $('#nxm-retro-btn').prop('disabled', false).text('Optimize Images');
                    $('#nxm-queue-pct').text('Check failed');
                    NXM.setStatus('Queue status could not be reached. Optimization was not continued; refresh the admin page and try again.', 'error', false);
                    clearInterval(NXM.timer);
                    NXM.timer = null;
                });
            };

            poll();
            this.timer = setInterval(poll, 5000);
        },

        revealWorkspace() {
            $('#nxm-media-wizard').slideUp(180);
            $('#nxm-dashboard-layout')
                .removeClass('nxm-dashboard-layout--locked')
                .hide()
                .slideDown(220, function () {
                    $(this).css('display', '');
                });
        },

        reloadDashboard(message) {
            NXM.setStatus(message || 'Refreshing media report...', 'success', false);
            window.setTimeout(function () {
                window.location.reload();
            }, 900);
        },

        setStatus(msg, type, showToast) {
            $('#nxm-status-message')
                .removeClass('nxm-status--info nxm-status--success nxm-status--error')
                .addClass('nxm-status--' + (type || 'info'))
                .text(msg)
                .show();
            if (showToast !== false) {
                NXM.toast(msg, type || 'info');
            }
        },

        toast(msg, type) {
            let $stack = $('.nxm-toast-stack');
            if (!$stack.length) {
                $stack = $('<div class="nxm-toast-stack" aria-live="polite" aria-atomic="true"></div>').appendTo('body');
            }
            const $last = $stack.children().last();
            if ($last.length && $last.text() === msg) {
                return;
            }
            const $toast = $('<div class="nxm-toast"></div>')
                .addClass('nxm-toast--' + (type || 'info'))
                .text(msg)
                .appendTo($stack);
            window.setTimeout(function () {
                $toast.addClass('is-hiding');
                window.setTimeout(function () {
                    $toast.remove();
                }, 240);
            }, 3600);
        }
    };

    $(function () {
        if (typeof nxmAdmin !== 'undefined') {
            NXM.init();
        }
    });
}(jQuery));
