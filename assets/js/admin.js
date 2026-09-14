/**
 * Synka Auto SEO - Admin JS Interactivity with Progressive Batch Processing
 */

(function($) {
    'use strict';

    $(document).ready(function() {

        // 1. Provider Toggle Rows
        function updateProviderRows() {
            var provider = $('#ai_provider').val();
            if (provider === 'gemini') {
                $('.row-gemini').show();
                $('.row-openai').hide();
            } else {
                $('.row-gemini').hide();
                $('.row-openai').show();
            }
        }
        $(document).on('change', '#ai_provider', updateProviderRows);
        updateProviderRows();

        // 2. Guide Modal
        $(document).on('click', '#btn-show-script-modal', function(e) {
            e.preventDefault();
            $('#synka-script-modal').fadeIn(200);
        });

        $(document).on('click', '.synka-modal-close, #synka-script-modal', function(e) {
            if (e.target === this) {
                $('#synka-script-modal').fadeOut(200);
            }
        });

        // Helper Alert
        function showAlert(msg, type) {
            var $box = $('#synka-alert-box');
            $box.removeClass('success error info')
                .addClass(type)
                .html(msg)
                .fadeIn(200);

            $('html, body').animate({
                scrollTop: $box.offset().top - 100
            }, 300);
        }

        // 3. Test Connection
        $(document).on('click', '#btn-test-connection', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var origText = $btn.html();

            $btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none; margin:0 6px 0 0;"></span> Memeriksa...');

            $.ajax({
                url: synkaAutoSeo.ajax_url,
                type: 'POST',
                data: {
                    action: 'synka_auto_seo_test_connection',
                    nonce: synkaAutoSeo.nonce
                },
                success: function(res) {
                    if (res.success) {
                        showAlert('✅ ' + (res.data && res.data.message ? res.data.message : 'Koneksi Berhasil!'), 'success');
                        if (res.data && res.data.count !== undefined) {
                            $('#btn-generate-all strong').text('Generate Semua (' + res.data.count + ' Ready)');
                        }
                    } else {
                        showAlert('❌ ' + (res.data && res.data.message ? res.data.message : 'Koneksi gagal.'), 'error');
                    }
                },
                error: function(xhr, status, error) {
                    showAlert('❌ Terjadi kesalahan jaringan saat mencoba koneksi. Status: ' + status, 'error');
                },
                complete: function() {
                    $btn.prop('disabled', false).html(origText);
                }
            });
        });

        // 4. Generate 1 Single Item
        $(document).on('click', '#btn-manual-run', function(e) {
            e.preventDefault();
            if (!confirm('Apakah Anda ingin memproses 1 artikel siap/pending dari Spreadsheet sekarang?')) {
                return;
            }

            var $btn = $(this);
            var origText = $btn.html();

            $btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none; margin:0 6px 0 0;"></span> Sedang Generate 1 Post...');
            showAlert('⏳ AI sedang menulis 1 artikel SEO dan menyiapkan gambar... Mohon tunggu sekitar 20-40 detik.', 'info');

            $.ajax({
                url: synkaAutoSeo.ajax_url,
                type: 'POST',
                timeout: 180000,
                data: {
                    action: 'synka_auto_seo_manual_run',
                    limit: 1,
                    nonce: synkaAutoSeo.nonce
                },
                success: function(res) {
                    if (res.success) {
                        var msg = res.data && res.data.message ? res.data.message : 'Artikel berhasil dibuat!';
                        if (res.data && res.data.results && res.data.results.length > 0) {
                            var item = res.data.results[0];
                            msg = '🎉 Artikel "' + (item.title || item.keyword) + '" berhasil di-generate! <br><a href="' + item.post_url + '" target="_blank" style="font-weight:bold; color:#065f46; text-decoration:underline;">Lihat Hasil Artikel &raquo;</a>';
                        }
                        showAlert(msg, 'success');
                        setTimeout(function() {
                            location.reload();
                        }, 2500);
                    } else {
                        showAlert('❌ ' + (res.data && res.data.message ? res.data.message : 'Gagal memproses artikel.'), 'error');
                    }
                },
                error: function(xhr, status, error) {
                    var errorMsg = 'Terjadi kesalahan pada server saat generate artikel.';
                    if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        errorMsg = xhr.responseJSON.data.message;
                    }
                    showAlert('❌ ' + errorMsg, 'error');
                },
                complete: function() {
                    $btn.prop('disabled', false).html(origText);
                }
            });
        });

        // 5. Generate SEMUA Artikel Ready (Progressive Sequential Batch)
        $(document).on('click', '#btn-generate-all', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var origText = $btn.html();

            $btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none; margin:0 6px 0 0;"></span> Membaca Spreadsheet...');
            showAlert('🔍 Mengambil data baris berstatus Ready dari Google Spreadsheet...', 'info');

            // Step 1: Get list of all pending rows from Spreadsheet
            $.ajax({
                url: synkaAutoSeo.ajax_url,
                type: 'POST',
                data: {
                    action: 'synka_auto_seo_get_pending_list',
                    nonce: synkaAutoSeo.nonce
                },
                success: function(res) {
                    if (!res || !res.success) {
                        $btn.prop('disabled', false).html(origText);
                        var errMsg = (res && res.data && res.data.message) ? res.data.message : 'Tidak dapat membaca data spreadsheet.';
                        showAlert('❌ ' + errMsg, 'error');
                        return;
                    }

                    var rows = [];
                    if (res.data && Array.isArray(res.data.rows)) {
                        rows = res.data.rows;
                    } else if (res.data && Array.isArray(res.data)) {
                        rows = res.data;
                    }

                    var total = rows.length;

                    if (total === 0) {
                        $btn.prop('disabled', false).html(origText);
                        showAlert('ℹ️ Tidak ditemukan artikel berstatus "Ready" atau "Pending" di Spreadsheet saat ini.', 'info');
                        return;
                    }

                    if (!confirm('Ditemukan ' + total + ' artikel berstatus Ready di Spreadsheet.\n\nApakah Anda ingin men-generate dan mempublikasikan SEMUA (' + total + ') artikel sekarang?')) {
                        $btn.prop('disabled', false).html(origText);
                        showAlert('Generate massal dibatalkan.', 'info');
                        return;
                    }

                    // Step 2: Show Progress Box & start sequential execution
                    $('#synka-progress-box').slideDown(200);
                    $('#synka-progress-log').html('');
                    $('html, body').animate({ scrollTop: $('#synka-progress-box').offset().top - 80 }, 300);

                    var currentIndex = 0;
                    var successCount = 0;
                    var failCount = 0;

                    function updateProgress(curr, tot, currentTitle) {
                        var percent = tot > 0 ? Math.round((curr / tot) * 100) : 0;
                        $('#synka-progress-percent').text(percent + '% (' + curr + '/' + tot + ')');
                        $('#synka-progress-bar-fill').css('width', percent + '%');
                        if (curr < tot) {
                            $('#synka-progress-title').html('⚡ Sedang Memproses [' + (curr + 1) + '/' + tot + ']: <strong>' + currentTitle + '</strong>...');
                        } else {
                            $('#synka-progress-title').html('🎉 Selesai memproses seluruh artikel (' + successCount + ' berhasil, ' + failCount + ' gagal)');
                        }
                    }

                    function processNext() {
                        if (currentIndex >= total) {
                            // All items finished!
                            $btn.prop('disabled', false).html(origText);
                            showAlert('🎉 <strong>Selesai!</strong> Berhasil mempublikasikan ' + successCount + ' dari ' + total + ' artikel.', 'success');
                            setTimeout(function() {
                                location.reload();
                            }, 3500);
                            return;
                        }

                        var rowItem = rows[currentIndex];
                        var itemKeyword = (rowItem && rowItem.keyword) ? rowItem.keyword : ('Baris #' + (rowItem ? rowItem.row_index : currentIndex));
                        updateProgress(currentIndex, total, itemKeyword);

                        $.ajax({
                            url: synkaAutoSeo.ajax_url,
                            type: 'POST',
                            timeout: 240000, // 4 mins per article
                            data: {
                                action: 'synka_auto_seo_process_single_row',
                                row: rowItem,
                                nonce: synkaAutoSeo.nonce
                            },
                            success: function(itemRes) {
                                if (itemRes && itemRes.success && itemRes.data) {
                                    successCount++;
                                    var logItem = '<div class="log-row" style="color:#065f46;">✅ [' + (currentIndex + 1) + '/' + total + '] <strong>' + (itemRes.data.title || itemKeyword) + '</strong> &rarr; <a href="' + itemRes.data.post_url + '" target="_blank" style="text-decoration:underline; font-weight:bold;">Lihat Post</a></div>';
                                    $('#synka-progress-log').append(logItem);
                                } else {
                                    failCount++;
                                    var errMsg = (itemRes && itemRes.data && itemRes.data.message) ? itemRes.data.message : 'Error pada proses AI/Post.';
                                    var logErr = '<div class="log-row" style="color:#b91c1c;">❌ [' + (currentIndex + 1) + '/' + total + '] <strong>' + itemKeyword + '</strong>: ' + errMsg + '</div>';
                                    $('#synka-progress-log').append(logErr);
                                }
                            },
                            error: function(xhr, status, error) {
                                failCount++;
                                var logErr = '<div class="log-row" style="color:#b91c1c;">❌ [' + (currentIndex + 1) + '/' + total + '] <strong>' + itemKeyword + '</strong>: Timeout atau Server Error.</div>';
                                $('#synka-progress-log').append(logErr);
                            },
                            complete: function() {
                                currentIndex++;
                                updateProgress(currentIndex, total, '');
                                $('#synka-progress-log').scrollTop($('#synka-progress-log')[0].scrollHeight);
                                // Small delay before next item to respect API rate limits
                                setTimeout(processNext, 1000);
                            }
                        });
                    }

                    // Kick off sequential processing
                    processNext();
                },
                error: function(xhr, status, error) {
                    $btn.prop('disabled', false).html(origText);
                    showAlert('❌ Terjadi kesalahan saat menghubungi server WordPress. Status: ' + status, 'error');
                }
            });
        });

        // 6. Clear Logs
        $(document).on('click', '#btn-clear-logs', function(e) {
            e.preventDefault();
            if (!confirm('Bersihkan semua riwayat log?')) return;

            $.ajax({
                url: synkaAutoSeo.ajax_url,
                type: 'POST',
                data: {
                    action: 'synka_auto_seo_clear_logs',
                    nonce: synkaAutoSeo.nonce
                },
                success: function(res) {
                    $('.synka-log-list').html('<p class="synka-empty-state">Log aktivitas telah dibersihkan.</p>');
                }
            });
        });

    });

})(jQuery);
