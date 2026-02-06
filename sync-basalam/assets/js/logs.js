jQuery(document).ready(function ($) {
  $(document).on("click", ".log-basalam-context-toggle-modern", function () {
    var contextDiv = $(this)
      .closest(".log-basalam-log-item-modern")
      .find(".log-basalam-log-context-modern");
    contextDiv.toggleClass("log-basalam-show");

    if (contextDiv.hasClass("log-basalam-show")) {
      $(this).text("مخفی کردن");
    } else {
      $(this).text("جزئیات");
    }
  });

  $(document).on("click", ".basalam-context-toggle", function () {
    var contextDiv = $(this)
      .closest(".basalam-log-item")
      .find(".basalam-log-context");
    contextDiv.toggleClass("Basalam-show");

    if (contextDiv.hasClass("Basalam-show")) {
      $(this).text("مخفی کردن");
    } else {
      $(this).text("جزئیات");
    }
  });

  var modal = $("#basalam-clear-logs-modal");
  var clearBtn = $("#basalam-clear-logs-btn");
  var cancelBtn = $("#basalam-cancel-clear");
  var confirmBtn = $("#basalam-confirm-clear");
  var closeBtn = $(".log-basalam-modal-close");

  clearBtn.on("click", function () {
    modal.show();
  });

  function hideModal() {
    modal.hide();
  }

  cancelBtn.on("click", hideModal);
  closeBtn.on("click", hideModal);

  modal.on("click", function (e) {
    if (e.target === this) {
      hideModal();
    }
  });

  confirmBtn.on("click", function () {
    var nonce = clearBtn.data("nonce");
    var button = $(this);
    var originalText = button.text();

    button.prop("disabled", true).text("در حال حذف...");

    $.ajax({
      url: ajaxurl,
      type: "POST",
      data: {
        action: "basalam_clear_logs",
        _wpnonce: nonce,
      },
      success: function (response) {
        if (response.success) {
          showNotification(response.data.message, "success");
          setTimeout(function () {
            location.reload();
          }, 1500);
        } else {
          showNotification(
            response.data.message || "خطا در حذف لاگ‌ها",
            "error"
          );
        }
      },
      error: function () {
        showNotification("خطا در ارتباط با سرور", "error");
      },
      complete: function () {
        button.prop("disabled", false).text(originalText);
        hideModal();
      },
    });
  });

  function showNotification(message, type) {
    $(".basalam-notification").remove();

    var notificationClass =
      type === "success" ? "notice-success" : "notice-error";
    var icon = type === "success" ? "✓" : "✗";

    var notification = $(
      '<div class="basalam-notification notice ' +
        notificationClass +
        ' is-dismissible">' +
        "<p><strong>" +
        icon +
        "</strong> " +
        message +
        "</p>" +
        '<button type="button" class="notice-dismiss">' +
        '<span class="screen-reader-text">بستن این اعلان.</span>' +
        "</button>" +
        "</div>"
    );

    $(".basalam-container").prepend(notification);

    setTimeout(function () {
      notification.fadeOut(function () {
        $(this).remove();
      });
    }, 5000);

    notification.find(".notice-dismiss").on("click", function () {
      notification.fadeOut(function () {
        $(this).remove();
      });
    });
  }
});
