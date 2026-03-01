/* global jQuery, qwAdmin */
(function ($) {
    'use strict';

    var refreshInterval = null;

    function updateStatus() {
        $.post(qwAdmin.ajaxUrl, {
            action: 'qw_worker_status',
            nonce: qwAdmin.nonce
        }, function (response) {
            if (!response.success) {
                return;
            }

            var d = response.data;
            var card = $('#qw-status-card');

            if (!d.running) {
                card.find('.qw-indicator')
                    .removeClass('qw-indicator-running')
                    .addClass('qw-indicator-stopped')
                    .text('Stopped');
                card.find('.qw-info-table, .qw-running-list, h4').hide();
                return;
            }

            card.find('.qw-indicator')
                .removeClass('qw-indicator-stopped')
                .addClass('qw-indicator-running')
                .text('Running');

            $('#qw-pid').text(d.pid || '');
            $('#qw-uptime').text(d.uptime || '');
            $('#qw-memory').text(d.memory || '');
            $('#qw-pending').text(d.pending_timers || 0);
            $('#qw-running').text(d.running_jobs || 0);
            card.find('.qw-info-table').show();

            // Running details
            var list = $('#qw-running-details');
            if (d.running_details && d.running_details.length > 0) {
                var html = '';
                for (var i = 0; i < d.running_details.length; i++) {
                    var r = d.running_details[i];
                    html += '<li>Site ' + r.site_id + ': <code>' +
                        $('<span>').text(r.hook).html() + '</code> (' +
                        r.count + ' jobs, ' + r.elapsed + 's)</li>';
                }
                list.html(html).show();
                list.prev('h4').show();
            } else {
                list.hide();
                list.prev('h4').hide();
            }

            // Stats
            if (d.stats) {
                var s = d.stats;
                var cards = card.find('.qw-stat-value');
                $(cards[0]).text(s.total || 0);
                $(cards[1]).text(s.failed || 0);
                // Format avg duration
                var avg = s.avg_duration_ms || 0;
                $(cards[2]).text(formatDuration(avg));
                $(cards[3]).text((s.error_rate || 0) + '%');
            }
        });
    }

    function formatDuration(ms) {
        if (ms < 1000) return ms + 'ms';
        if (ms < 60000) return (ms / 1000).toFixed(1) + 's';
        var mins = Math.floor(ms / 60000);
        var secs = Math.round((ms % 60000) / 1000);
        return mins + 'm ' + secs + 's';
    }

    function startPolling() {
        if (refreshInterval) return;
        refreshInterval = setInterval(function () {
            if (document.visibilityState === 'visible') {
                updateStatus();
            }
        }, 10000);
    }

    function stopPolling() {
        if (refreshInterval) {
            clearInterval(refreshInterval);
            refreshInterval = null;
        }
    }

    $(function () {
        var checkbox = $('#qw-auto-refresh');

        checkbox.on('change', function () {
            if (this.checked) {
                startPolling();
            } else {
                stopPolling();
            }
        });

        // Start polling if checkbox is checked (default)
        if (checkbox.is(':checked')) {
            startPolling();
        }
    });
})(jQuery);
