// =====================================================================
// Salam Gate — status/settings (standalone Salam Gate pages only)
// =====================================================================
jQuery(function ($) {
  if (window.WoosalamGateBoot && !window.WoosalamGateBoot.claim("status")) return;
  if (!document.querySelector(".salam-gate-page")) return;

  function sgNotice(message, ok) {
    if (typeof window.woosalamNotify === "function") {
      window.woosalamNotify(message, ok ? "success" : "error");
      return;
    }
    $(".salam-gate-page .salam-gate-notice").remove();
    $(".salam-gate-page")
      .first()
      .prepend(
        $("<div>")
          .addClass("salam-gate-notice")
          .addClass(ok ? "salam-gate-notice--success" : "salam-gate-notice--error")
          .text(message)
      );
  }

  function sgBusy($btn, busy) {
    if (!$btn || !$btn.length) return;
    $btn.prop("disabled", busy);
    if (busy) {
      $btn.data("sg-label", $btn.text()).text("لطفاً صبر کنید...");
    } else if ($btn.data("sg-label")) {
      $btn.text($btn.data("sg-label"));
    }
  }

  function sgRequest($btn, action, data, onSuccess) {
    sgBusy($btn, true);

    $.post(
      basalam_ajax.ajaxurl,
      $.extend({ _wpnonce: basalam_ajax.nonce, action: action }, data || {})
    )
      .done(function (res) {
        if (res && res.success) {
          onSuccess(res.data || {});
          return;
        }
        sgBusy($btn, false);
        sgNotice((res && res.data && res.data.message) || "خطا در انجام عملیات.", false);
      })
      .fail(function () {
        sgBusy($btn, false);
        sgNotice("ارتباط با سرور برقرار نشد.", false);
      });
  }

  $(".salam-gate-refresh").on("click", function () {
    sgRequest($(this), "salam_gate_refresh_shops", null, function () {
      sgNotice("وضعیت سلام‌گیت به‌روز شد.", true);
      setTimeout(function () { window.location.reload(); }, 600);
    });
  });

  function closeDeactivation(dialog) {
    if (dialog && dialog.open && dialog.getAttribute("aria-busy") !== "true") dialog.close();
  }

  $(document).on("click", "[data-sg-deactivate-open]", function () {
    var dialog = document.getElementById("sg-site-deactivate-dialog");
    if (!dialog || dialog.open) return;
    $(dialog).find("[data-sg-site-activation-message]").prop("hidden", true).text("");
    dialog.showModal();
    document.body.classList.add("sg-site-deactivate-open");
    dialog.onclose = function () { document.body.classList.remove("sg-site-deactivate-open"); };
    // Native dialog confines focus, handles Escape and restores trigger focus.
    dialog.oncancel = function (event) {
      if (dialog.getAttribute("aria-busy") === "true") event.preventDefault();
    };
  });
  $(document).on("click", "[data-sg-deactivate-close]", function () {
    closeDeactivation(this.closest(".sg-site-deactivate-dialog"));
  });
  $(document).on("click", ".sg-site-deactivate-dialog", function (event) {
    if (event.target !== this) return;
    var bounds = this.getBoundingClientRect();
    if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) closeDeactivation(this);
  });

  $(document).on("click", "[data-sg-site-enabled]", function () {
    var $button = $(this), enabled = $button.attr("data-sg-site-enabled");
    if ($button.prop("disabled")) return;
    var dialog = $button.closest(".sg-site-deactivate-dialog")[0];
    if (enabled === "0" && (!dialog || !dialog.open || dialog.getAttribute("aria-busy") === "true")) return;
    var $message = $button.closest(".sg-state, .sg-settings__activation").find("[data-sg-site-activation-message]");
    $message.prop("hidden", true).text("");
    if (dialog) $(dialog).attr("aria-busy", "true").find("[data-sg-deactivate-close]").prop("disabled", true);
    sgBusy($button, true);
    $.ajax({ url: basalam_ajax.ajaxurl, type: "POST", dataType: "json", timeout: 30000,
      data: { action: "salam_gate_site_activation", enabled: enabled, _wpnonce: basalam_ajax.nonce }
    }).done(function (result) {
      if (result && result.success && result.data) {
        window.location.assign(result.data.redirect);
        return;
      }
      fail(result && result.data && result.data.message);
    }).fail(function (xhr) {
      var result = xhr.responseJSON;
      fail(result && result.data && result.data.message);
    });
    function fail(message) {
      sgBusy($button, false);
      if (dialog) $(dialog).attr("aria-busy", "false").find("[data-sg-deactivate-close]").prop("disabled", false);
      $message.text(message || "نتیجه تغییر وضعیت دریافت نشد؛ صفحه را تازه‌سازی کنید. ثبت یا پرداخت جدیدی انجام نمی‌شود.").prop("hidden", false);
    }
  });

  $(document).on("click", ".salam-gate-activate", function () {
    // shop_id روی خود دکمه صفحه router است — فال‌بک به والد data-shop-id.
    var shopId = $(this).data("shop-id") || $(this).closest("[data-shop-id]").data("shop-id");
    sgRequest(
      $(this),
      "salam_gate_shop_activate",
      { shop_id: shopId },
      function () {
        sgNotice("سلام‌گیت فعال شد.", true);
        setTimeout(function () { window.location.reload(); }, 600);
      }
    );
  });

  $(document).on("click", ".salam-gate-deactivate", function () {
    var shopId = $(this).data("shop-id") || $(this).closest("[data-shop-id]").data("shop-id");
    sgRequest(
      $(this),
      "salam_gate_shop_deactivate",
      { shop_id: shopId },
      function () {
        sgNotice("سلام‌گیت غیرفعال شد.", true);
        setTimeout(function () { window.location.reload(); }, 600);
      }
    );
  });

  $(".salam-gate-flush-refs").on("click", function () {
    sgRequest($(this), "salam_gate_flush_references", null, function () {
      sgNotice("اطلاعات مرجع تازه شد.", true);
      setTimeout(function () { window.location.reload(); }, 600);
    });
  });
});
