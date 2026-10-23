/* ChatMaxima sync meta box: "Sync Now" on the post editor */
jQuery(document).ready(function($) {
    $('.chatmaxima-sync-now-btn').on('click', function() {
        var $btn = $(this);
        var $message = $btn.siblings('.chatmaxima-sync-now-message');
        var strings = chatmaximaMetaBox.strings;

        $message.removeClass('is-error').text('');
        $btn.prop('disabled', true).text(strings.syncing);

        $.ajax({
            url: chatmaximaMetaBox.ajaxUrl,
            type: 'POST',
            data: {
                action: 'chatmaxima_sync_single_post',
                post_id: $btn.data('post-id'),
                nonce: chatmaximaMetaBox.nonce
            },
            success: function(response) {
                if (response.success) {
                    $btn.text(strings.synced);
                    location.reload();
                } else {
                    var text = strings.failed;
                    if (response.data && response.data.message) {
                        text = response.data.message;
                    }
                    $btn.prop('disabled', false).text(strings.syncNow);
                    $message.addClass('is-error').text(text);
                }
            },
            error: function() {
                $btn.prop('disabled', false).text(strings.syncNow);
                $message.addClass('is-error').text(strings.failed);
            }
        });
    });
});
