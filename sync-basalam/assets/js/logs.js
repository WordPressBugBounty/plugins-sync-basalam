jQuery(document).ready(function($) {
    // Toggle context details
    $(document).on('click', '.log-basalam-context-toggle-modern', function() {
        var contextDiv = $(this).closest('.log-basalam-log-item-modern').find('.log-basalam-log-context-modern');
        contextDiv.toggleClass('log-basalam-show');
        
        if (contextDiv.hasClass('log-basalam-show')) {
            $(this).text('مخفی کردن');
        } else {
            $(this).text('جزئیات');
        }
    });

    // Legacy context toggle for backward compatibility
    $(document).on('click', '.basalam-context-toggle', function() {
        var contextDiv = $(this).closest('.basalam-log-item').find('.basalam-log-context');
        contextDiv.toggleClass('Basalam-show');
        
        if (contextDiv.hasClass('Basalam-show')) {
            $(this).text('مخفی کردن');
        } else {
            $(this).text('جزئیات');
        }
    });

    // Modal functionality
    var modal = $('#basalam-clear-logs-modal');
    var clearBtn = $('#basalam-clear-logs-btn');
    var cancelBtn = $('#basalam-cancel-clear');
    var confirmBtn = $('#basalam-confirm-clear');
    var closeBtn = $('.log-basalam-modal-close');

    // Show modal
    clearBtn.on('click', function() {
        modal.show();
    });

    // Hide modal
    function hideModal() {
        modal.hide();
    }

    cancelBtn.on('click', hideModal);
    closeBtn.on('click', hideModal);

    // Close modal when clicking outside
    modal.on('click', function(e) {
        if (e.target === this) {
            hideModal();
        }
    });

    // Clear logs functionality
    confirmBtn.on('click', function() {
        var nonce = clearBtn.data('nonce');
        var button = $(this);
        var originalText = button.text();
        
        // Disable button and show loading
        button.prop('disabled', true).text('در حال حذف...');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'basalam_clear_logs',
                _wpnonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotification(response.data.message, 'success');
                    // Reload page after a short delay
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    showNotification(response.data.message || 'خطا در حذف لاگ‌ها', 'error');
                }
            },
            error: function() {
                showNotification('خطا در ارتباط با سرور', 'error');
            },
            complete: function() {
                button.prop('disabled', false).text(originalText);
                hideModal();
            }
        });
    });

    // Notification system
    function showNotification(message, type) {
        // Remove existing notifications
        $('.basalam-notification').remove();
        
        var notificationClass = type === 'success' ? 'notice-success' : 'notice-error';
        var icon = type === 'success' ? '✓' : '✗';
        
        var notification = $('<div class="basalam-notification notice ' + notificationClass + ' is-dismissible">' +
            '<p><strong>' + icon + '</strong> ' + message + '</p>' +
            '<button type="button" class="notice-dismiss">' +
            '<span class="screen-reader-text">بستن این اعلان.</span>' +
            '</button>' +
            '</div>');
        
        // Insert at the top of the container
        $('.basalam-container').prepend(notification);
        
        // Auto dismiss after 5 seconds
        setTimeout(function() {
            notification.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
        
        // Manual dismiss
        notification.find('.notice-dismiss').on('click', function() {
            notification.fadeOut(function() {
                $(this).remove();
            });
        });
    }
});
