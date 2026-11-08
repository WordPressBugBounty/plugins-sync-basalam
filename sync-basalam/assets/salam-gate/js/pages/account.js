// =====================================================================
// Salam Gate — account state router page (part 3)
// =====================================================================
jQuery(function ($) {
  if (window.WoosalamGateBoot && !window.WoosalamGateBoot.claim("account")) return;
  var $root = $(".sg-router[data-sg-root]");
  if (!$root.length) return;

  var $container = $("#sg-state-container", $root);
  var $msg = $(".sg-refresh-state__msg", $root);
  var refreshInFlight = false, navigating = false;
  var accountStates = ["not_connected", "error_network", "error_auth", "error_forbidden", "not_registered", "pending", "rejected", "inactive", "active", "disabled"];

  function sgRouterBusy(on) {
    // Query again after each partial replacement: the retry button may be new.
    $root.find("[data-sg-refresh]").prop("disabled", on);
    $root.find(".sg-refresh-state__spinner").toggle(on);
    $container.attr("aria-busy", String(on));
  }

  function sgRefreshError(message) {
    if (typeof window.woosalamNotify === "function") {
      $msg.text("");
      window.woosalamNotify(message, "error");
    } else $msg.text(message);
  }

  $root.on("click", "[data-sg-refresh]", function (event) {
    event.preventDefault();
    if (refreshInFlight || navigating) return;
    refreshInFlight = true;
    var previousState = String($root.attr("data-sg-account-state") || $container.find("[data-state]").first().attr("data-state") || "");
    sgRouterBusy(true);
    // Keep the current content and retry control available if the request fails.
    $msg.text("در حال دریافت اطلاعات…");

    $.post(basalam_ajax.ajaxurl, {
      _wpnonce: basalam_ajax.nonce,
      action: "salam_gate_account_state",
      fresh: "1"
    })
      .done(function (res) {
        var data = res && res.success === true && $.isPlainObject(res.data) ? res.data : null;
        if (!data || accountStates.indexOf(data.state) === -1 || typeof data.html !== "string" || !data.html.trim()) {
          sgRefreshError("بررسی وضعیت ناموفق بود؛ دوباره تلاش کنید.");
          return;
        }
        if (data.state !== previousState && data.state.indexOf("error_") !== 0) {
          // A full render initializes the newly available dashboard and navigation.
          // The fresh endpoint has already saved this connection's latest cache.
          navigating = true;
          $msg.text("وضعیت به‌روز شد؛ در حال بارگذاری صفحه…");
          window.location.reload();
          return;
        }
        $root.attr("data-sg-account-state", data.state);
        $container.html(data.html);
        if (data.state.indexOf("error_") === 0) {
          var errors = { error_network: "دریافت وضعیت حساب سلام‌گیت انجام نشد؛ کمی بعد دوباره تلاش کنید.",
            error_auth: "اتصال حساب سلام‌گیت معتبر نیست؛ اتصال باسلام را دوباره بررسی کنید.",
            error_forbidden: "دسترسی‌های حساب را تأیید کنید." };
          sgRefreshError(typeof data.message === "string" && /[\u0600-\u06ff]/.test(data.message) ? data.message : errors[data.state]);
          return;
        }
        var message = data.state === "pending" ? "وضعیت تازه دریافت شد؛ حساب هنوز در انتظار تأیید است."
          : (data.state.indexOf("error_") === 0 ? "" : "وضعیت حساب به‌روز شد.");
        if (message && typeof window.woosalamNotify === "function") {
          $msg.text(""); window.woosalamNotify(message, "success");
        } else $msg.text(message);
      })
      .fail(function () {
        sgRefreshError("دریافت وضعیت تازه انجام نشد؛ اتصال را بررسی و دوباره تلاش کنید.");
      })
      .always(function () {
        if (navigating) return;
        refreshInFlight = false;
        sgRouterBusy(false);
      });
  });
});
