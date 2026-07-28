(function ($) {
    $('.webruno-bulk-block').each(function () {
        const $block = $(this);
        const kind = $block.data('kind');
        const $btn = $block.find('.webruno-bulk-start');
        const $progress = $block.find('.webruno-bulk-progress');
        const $fill = $block.find('.webruno-bulk-fill');
        const $status = $block.find('.webruno-bulk-status');

        $btn.on('click', function () {
            $btn.prop('disabled', true);
            $progress.show();
            $status.text(webrunoCrmBulk.i18n.starting);

            $.post(webrunoCrmBulk.ajaxUrl, { action: 'webruno_crm_get_' + kind + '_count', security: webrunoCrmBulk.nonce }, function (res) {
                if (!res.success) { $status.text(res.data); $btn.prop('disabled', false); return; }
                const total = res.data.total;
                if (total === 0) { $status.text(webrunoCrmBulk.i18n.nothing); $btn.prop('disabled', false); return; }
                runBatch(0, total);
            });

            function runBatch(offset, total) {
                $.post(webrunoCrmBulk.ajaxUrl, { action: 'webruno_crm_export_' + kind, offset: offset, security: webrunoCrmBulk.nonce }, function (res) {
                    if (!res.success) { $status.text(res.data); $btn.prop('disabled', false); return; }
                    const next = res.data.next_offset || total;
                    const pct = Math.min(100, Math.round((next / total) * 100));
                    $fill.css('width', pct + '%');
                    $status.text(res.data.message + ' (' + pct + '%)');
                    if (res.data.complete) {
                        $btn.prop('disabled', false);
                        $status.text(webrunoCrmBulk.i18n.done);
                    } else {
                        setTimeout(function () { runBatch(next, total); }, 800);
                    }
                }).fail(function () {
                    $status.text(webrunoCrmBulk.i18n.networkError);
                    $btn.prop('disabled', false);
                });
            }
        });
    });
})(jQuery);
