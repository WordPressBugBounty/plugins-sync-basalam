// =====================================================================
// Salam Gate — status/settings (standalone Salam Gate pages only)
// =====================================================================
jQuery(function ($) {
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

// =====================================================================
// Salam Gate — account state router page (part 3)
// =====================================================================
jQuery(function ($) {
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

// =====================================================================
// Salam Gate — onboarding wizard (part 4)
// =====================================================================
jQuery(function ($) {
  function initActivation(scope) {
  var $wizard = $(scope).find(".sg-wizard[data-sg-wizard]").addBack(".sg-wizard[data-sg-wizard]").not("[data-sg-wizard-ready]");
  if (!$wizard.length) return;
  $wizard.attr("data-sg-wizard-ready", "true");

  var $form = $wizard.find("[data-sg-wizard-form]");
  var $notice = $wizard.find("[data-wizard-notice]");
  var $steps = $wizard.find(".sg-wizard__step");
  var $navItems = $wizard.find("[data-step-nav]");
  var $btnPrev = $wizard.find("[data-wizard-prev]");
  var $btnNext = $wizard.find("[data-wizard-next]");
  var $btnSubmit = $wizard.find("[data-wizard-submit]");

  var currentStep = 1;
  var TOTAL_STEPS = 4;
  var submitting = false;
  var bootstrapReady = false;
  var registrationBlocked = false;
  var locationsVersion = 0, nodesVersion = 0;
  var locationsRequest = null, nodesRequest = null;
  var bootstrapVersion = 0;
  var activationBalances = {}, balanceVersion = 0, balancePending = false, serialHelpTrigger = null, paymentMethodManual = false;
  var statusUrl = $wizard.attr("data-status-url") || window.location.href.split("&view=")[0].replace(/#.*$/, "");
  var $checkStatus = $wizard.find("[data-wizard-check]");
  var bootstrap = { provinces: [], provider: null, payMethods: [], leapYears: [], currentJy: 1400, minimumJy: 1301, birthBounds: null };
  var provinceTitle = "";
  var cityTitle = "";
  var locationTitle = "";
  var nodeTitle = "";
  var nodeAddress = "";

  var MONTHS = ["فروردین", "اردیبهشت", "خرداد", "تیر", "مرداد", "شهریور", "مهر", "آبان", "آذر", "دی", "بهمن", "اسفند"];

  function sgWPost(action, data) {
    var request = window.WoosalamGateRequests ? window.WoosalamGateRequests.post : $.post;
    return request(basalam_ajax.ajaxurl, $.extend({ _wpnonce: basalam_ajax.nonce, action: action }, data || {}));
  }

  function toEnDigits(v) {
    return String(v == null ? "" : v)
      .replace(/[۰-۹]/g, function (d) { return String.fromCharCode(d.charCodeAt(0) - 0x06F0 + 48); })
      .replace(/[٠-٩]/g, function (d) { return String.fromCharCode(d.charCodeAt(0) - 0x0660 + 48); });
  }

  function faNum(n) {
    return String(n).replace(/\d/g, function (d) { return "۰۱۲۳۴۵۶۷۸۹"[+d]; });
  }

  function faThousands(n) {
    return faNum(String(Math.floor(Number(n))).replace(/\B(?=(\d{3})+(?!\d))/g, ","));
  }

  function field(name) { return $form.find('[name="' + name + '"]'); }

  function setFieldError(name, message) {
    var $err = $wizard.find('[data-error-for="' + name + '"]');
    if ($err.length) { $err.text(message || "").toggle(!!message); }
    var $input = field(name);
    if (name === "birth_date") $input = $form.find("[data-birth]");
    if ($input.length) {
      $input.toggleClass("sg-field__input--invalid", !!message).attr("aria-invalid", message ? "true" : "false");
      if ($err.length) {
        var errorId = $err.attr("id") || "sgw-error-" + name;
        $err.attr("id", errorId);
        $input.each(function () {
          var ids = ($(this).attr("aria-describedby") || "").split(/\s+/).filter(function (id) { return id && id !== errorId; });
          if (message) ids.push(errorId);
          if (ids.length) $(this).attr("aria-describedby", ids.join(" "));
          else $(this).removeAttr("aria-describedby");
        });
      }
    }
  }

  function clearAllErrors() {
    $wizard.find("[data-error-for]").each(function () { setFieldError($(this).attr("data-error-for"), ""); });
  }

  function notice(text, ok, announce) {
    $notice.text(text || "").toggleClass("sg-wizard__notice--ok", !!ok).toggle(!!text)
      .toggleClass("sg-feedback-toast-only", typeof window.woosalamNotify === "function");
    if (typeof window.woosalamNotify === "function") {
      if (announce !== false) window.woosalamNotify(text, ok ? "success" : "error");
      return;
    }
  }

  function syncButtons() {
    var blocked = !bootstrapReady || registrationBlocked || submitting;
    $btnNext.prop("disabled", blocked);
    $btnPrev.prop("disabled", submitting || registrationBlocked);
    $btnSubmit.prop("disabled", blocked || !activationFunded() || !$form.find("[data-accept-rules]").prop("checked"));
    renderActivationFunding();
    $form.attr("aria-busy", submitting ? "true" : "false");
  }

  function blockRegistration(message) {
    registrationBlocked = true;
    $checkStatus.prop("hidden", false).attr("href", statusUrl);
    $btnSubmit.text(message.indexOf("تأیید پرداخت هنوز دریافت نشده") !== -1 ? "در حال بررسی" : "ابتدا وضعیت فعال‌سازی را بررسی کنید");
    notice(message, false);
    syncButtons();
  }

  function activationFee() {
    var fee = bootstrap.provider && bootstrap.provider.activation_cost;
    return Number.isSafeInteger(fee) && fee >= 0 ? fee : null;
  }

  function activationMethod() { return Number($form.find("[name=pay_method]:checked").val()); }

  function activationFunded() {
    var method = activationMethod(), fee = activationFee(), record = activationBalances[method];
    return [8, 2].includes(method) && fee !== null && (fee === 0
      || !!record && record.state === "ready" && record.rial >= fee);
  }

  function activationFundingMessage() {
    var method = activationMethod(), fee = activationFee(), record = activationBalances[method];
    if (![8, 2].includes(method) || fee === null || fee === 0 || activationFunded()) return "";
    if (!record || record.state === "loading") return "در حال دریافت موجودی روش پرداخت انتخاب‌شده…";
    if (record.state !== "ready") return "موجودی روش انتخاب‌شده دریافت نشد؛ موجودی را تازه‌سازی کنید.";
    return method === 8 ? "موجودی تراز غرفه برای فعال‌سازی کافی نیست. کیف پول باسلام را انتخاب کنید و در صورت نیاز آن را شارژ کنید."
      : "موجودی کیف پول برای فعال‌سازی کافی نیست. ابتدا کیف پول باسلام را شارژ کنید.";
  }

  function balanceRecord(data, method) {
    var rial = data && data.balance_rial;
    if (typeof rial === "string" && /^-?\d+$/.test(rial)) rial = Number(rial);
    return data && Number(data.method) === method && Number.isSafeInteger(rial)
      && (!data.state || data.state === "ready") ? {state: "ready", rial: rial} : {state: "unavailable"};
  }

  function selectActivationMethod() {
    if (paymentMethodManual || submitting || registrationBlocked) return;
    if ([8, 2].some(function (id) { return !activationBalances[id] || activationBalances[id].state === "loading"; })) return;
    var choices = [8, 2].filter(function (id) { return activationBalances[id].state === "ready"; });
    choices.sort(function (a, b) { return activationBalances[b].rial - activationBalances[a].rial; });
    if (!choices.length || activationMethod() === choices[0]) return;
    $form.find("[name=pay_method]").prop("checked", false).filter("[value='" + choices[0] + "']").prop("checked", true);
    $form.find("[data-accept-rules]").prop("checked", false);
    $form.find(".sg-activation__pay-card").each(function () { $(this).toggleClass("is-selected", $(this).find("input").prop("checked")); });
    if (currentStep === TOTAL_STEPS) renderReview();
  }

  function renderActivationFunding() {
    [8, 2].forEach(function (method) {
      var record = activationBalances[method];
      $wizard.find("[data-activation-balance='" + method + "']").text(record && record.state === "ready"
        ? "موجودی: " + faThousands(Math.max(0, record.rial) / 10) + " تومان"
        : record && record.state === "loading" ? "در حال دریافت موجودی…" : "موجودی در دسترس نیست");
    });
    var message = activationFundingMessage();
    $wizard.find("[data-wizard-balance-hint]").text(message).prop("hidden", !message);
    $wizard.find("[data-wizard-balance-refresh]").prop("disabled", submitting || registrationBlocked || balancePending)
      .attr("aria-busy", balancePending ? "true" : "false").toggleClass("is-refreshing", balancePending)
      .find("[data-wizard-balance-refresh-label]").text(balancePending ? "در حال به‌روزرسانی…" : "تازه‌سازی موجودی");
    var wallet = activationBalances[2], fee = activationFee();
    $wizard.find("[data-wizard-topup]").prop("hidden", !(bootstrapReady && activationMethod() === 2
      && wallet && wallet.state === "ready" && fee !== null && wallet.rial < fee));
  }

  function refreshActivationBalances() {
    if (submitting || registrationBlocked || !bootstrapReady || balancePending) return;
    var version = ++balanceVersion, remaining = 2;
    balancePending = true;
    activationBalances = {8: {state: "loading"}, 2: {state: "loading"}};
    $form.find("[data-accept-rules]").prop("checked", false);
    syncButtons();
    [8, 2].forEach(function (method) {
      var parcelMethod = method === 8 ? 20 : 21;
      sgWPost("salam_gate_payment_balance", {method: parcelMethod, fresh: 1}).done(function (res) {
        if (version !== balanceVersion) return;
        var data = res && res.success === true && res.data;
        var record = balanceRecord(data, parcelMethod);
        if (record.state === "ready" && (!Number.isFinite(Number(data.balance_toman))
          || Math.abs(Number(data.balance_toman) - record.rial / 10) > .001)) record = {state: "unavailable"};
        activationBalances[method] = record;
      }).fail(function () { if (version === balanceVersion) activationBalances[method] = {state: "unavailable"}; })
        .always(function () {
          if (version !== balanceVersion) return;
          if (--remaining === 0) { balancePending = false; selectActivationMethod(); }
          syncButtons();
        });
    });
  }

  function isLeapJy(jy) { return bootstrap.leapYears.indexOf(Number(jy)) !== -1; }

  function monthLength(jy, jm) {
    if (jm <= 6) return 31;
    if (jm <= 11) return 30;
    return !jy || isLeapJy(jy) ? 30 : 29;
  }

  function fillBirthDays() {
    var jy = Number(field("birth_jy").val() || 0);
    var jm = Number(field("birth_jm").val() || 0);
    var len = jm ? monthLength(jy, jm) : 31;
    var cur = Number(field("birth_jd").val() || 0);
    var $d = field("birth_jd").empty().append($("<option>").val("").text("انتخاب"));
    for (var day = 1; day <= len; day++) {
      $d.append($("<option>").val(String(day)).text(faNum(day)));
    }
    if (cur >= 1 && cur <= len) { $d.val(String(cur)); }
  }

  function setState(name, text, isRetry) {
    var $state = $wizard.find('[data-state-for="' + name + '"]');
    var floating = !!text && !!isRetry && typeof window.woosalamNotify === "function";
    $state.text(text || "").toggleClass("sg-field__state--error", !!isRetry).toggleClass("sg-feedback-toast-only", floating);
    if (floating) window.woosalamNotify(text, "error");
  }

  // ---- اعتبارسنجی سمت کلاینت (نقشه سرور؛ ارقام فارسی قبل از اعتبارسنجی نرمال می‌شوند) ----
  function validateStep(step) {
    clearAllErrors();
    var errors = [];

    var mobile = $.trim(toEnDigits(field("mobile").val() || ""));
    field("mobile").val(mobile);
    var national = $.trim(toEnDigits(field("national_code").val() || ""));
    field("national_code").val(national);
    var serial = $.trim(toEnDigits(field("national_code_serial").val() || "")).toUpperCase();
    field("national_code_serial").val(serial);
    var postal = $.trim(toEnDigits(field("postal_code").val() || ""));
    field("postal_code").val(postal);

    if (step === 1) {
      if (!/^09\d{9}$/.test(mobile)) errors.push(["mobile", "شماره موبایل باید با 09 شروع شود و ۱۱ رقم باشد."]);
      if (!/^\d{10}$/.test(national)) errors.push(["national_code", "کد ملی باید دقیقاً ۱۰ رقم باشد."]);
      if (!/^[A-Z0-9]{10}$/.test(serial)) errors.push(["national_code_serial", "سریال باید دقیقاً ۱۰ کاراکتر لاتین حرف/عدد باشد."]);
      var jy = Number(field("birth_jy").val() || 0);
      var jm = Number(field("birth_jm").val() || 0);
      var jd = Number(field("birth_jd").val() || 0);
      if (!Number.isSafeInteger(jy) || jy < bootstrap.minimumJy || jy > bootstrap.currentJy
        || !Number.isSafeInteger(jm) || jm < 1 || jm > 12 || !Number.isSafeInteger(jd) || jd < 1 || jd > monthLength(jy, jm)) {
        errors.push(["birth_date", "تاریخ تولد را کامل و معتبر انتخاب کنید."]);
      } else if (bootstrap.birthBounds) {
        var birthKey = jy * 10000 + jm * 100 + jd;
        var maximum = bootstrap.birthBounds.maximum, minimum = bootstrap.birthBounds.excludedMinimum;
        if (birthKey > maximum.jy * 10000 + maximum.jm * 100 + maximum.jd
          || birthKey <= minimum.jy * 10000 + minimum.jm * 100 + minimum.jd) errors.push(["birth_date", "سن صاحب حساب باید بین ۱۸ تا ۱۰۰ سال باشد."]);
      }
    }

    if (step === 2) {
      var province = (bootstrap.provinces || []).find(function (item) { return String(item.id) === String(field("province_id").val()); });
      if (!province || !(province.children || []).some(function (city) { return String(city.id) === String(field("city_id").val()); })) {
        errors.push(["city_id", "استان و شهر فرستنده را انتخاب کنید."]);
      }
      var fullName = $.trim(field("full_name").val() || "");
      if (fullName.length < 3) errors.push(["full_name", "نام و نام خانوادگی فرستنده را کامل وارد کنید."]);
      var address = $.trim(field("address").val() || ""), detail = $.trim(field("address_detail").val() || "");
      if (address.length < 10 || address.length > 512) errors.push(["address", "نشانی کامل باید بین ۱۰ تا ۵۱۲ کاراکتر باشد."]);
      if (field("address_detail").length && (detail.length < 3 || detail.length > 255)) errors.push(["address_detail", "جزئیات آدرس باید بین ۳ تا ۲۵۵ کاراکتر باشد."]);
      if (!/^\d{10}$/.test(postal)) errors.push(["postal_code", "کد پستی باید دقیقاً ۱۰ رقم باشد."]);
    }

    if (step === 3) {
      if (field("provider_location_id").prop("disabled") || !Number(field("provider_location_id").val() || 0)) errors.push(["provider_location_id", "منطقه پستی را انتخاب کنید."]);
      if (field("provider_node_id").prop("disabled") || !Number(field("provider_node_id").val() || 0)) errors.push(["provider_node_id", "مرکز تحویل را انتخاب کنید."]);
    }

    if (step === 4) {
      if (!bootstrapReady || !bootstrap.provider || !Number.isSafeInteger(bootstrap.provider.activation_cost) || bootstrap.provider.activation_cost < 0) {
        errors.push(["pay_method", "هزینه فعال‌سازی دریافت نشده است؛ ابتدا اطلاعات را دوباره دریافت کنید."]);
      }
      if (!$form.find("[data-pay-methods] input[type=radio]:checked").length) {
        errors.push(["pay_method", "روش پرداخت هزینه فعال‌سازی را انتخاب کنید."]);
      } else if (!activationFunded()) {
        errors.push(["pay_method", activationFundingMessage() || "موجودی کافی برای هزینه فعال‌سازی تأیید نشده است."]);
      }
      if (!$form.find("[data-accept-rules]").is(":checked")) {
        errors.push(["accept_rules", "پذیرش قوانین برای ثبت الزامی است."]);
      }
    }

    clearAllErrors();
    var firstErrorStep = null;
    $.each(errors, function (i, pair) {
      setFieldError(pair[0], pair[1]);
      if (firstErrorStep === null) {
        firstErrorStep = stepOfField(pair[0]);
      }
    });

    if (errors.length) notice(errors[0][1], false);
    if (!errors.length && step === currentStep && window.SalamGateValidation
      && !window.SalamGateValidation.validate($steps.filter('[data-step="' + step + '"]'))) return false;

    return firstErrorStep === null || firstErrorStep === step ? errors.length === 0 : false;
  }

  function stepOfField(name) {
    if (["mobile", "national_code", "national_code_serial", "birth_date"].indexOf(name) !== -1) return 1;
    if (["city_id", "full_name", "address", "address_detail", "postal_code", "phone"].indexOf(name) !== -1) return 2;
    if (["provider_location_id", "provider_node_id"].indexOf(name) !== -1) return 3;
    return 4;
  }

  function goToStep(step) {
    currentStep = step;
    $steps.prop("hidden", true).removeClass("is-active").filter('[data-step="' + step + '"]').prop("hidden", false).addClass("is-active");
    $navItems.removeClass("is-active is-done").removeAttr("aria-current");
    $navItems.filter(function () { return Number($(this).data("step-nav")) === step; }).addClass("is-active").attr("aria-current", "step");
    $navItems.filter(function () { return Number($(this).data("step-nav")) < step; }).addClass("is-done");
    $btnPrev.toggle(step > 1);
    $btnNext.toggle(step < TOTAL_STEPS);
    $btnSubmit.toggle(step === TOTAL_STEPS);
    if (step === TOTAL_STEPS) { renderReview(); refreshActivationBalances(); }
    $wizard.find("[data-wizard-progress]").text("مرحله " + faNum(step) + " از " + faNum(TOTAL_STEPS));
    syncButtons();
    $steps.filter('[data-step="' + step + '"]').find("h4[tabindex]").trigger("focus");
  }

  function renderReview() {
    var payId = Number($form.find("[data-pay-methods] input[type=radio]:checked").val() || 0);
    var payTitle = "";
    $form.find("[data-pay-methods] input[type=radio]").each(function () {
      if (Number($(this).val()) === payId) { payTitle = $(this).data("title") || ""; }
    });
    var costRial = bootstrap.provider && bootstrap.provider.activation_cost != null ? bootstrap.provider.activation_cost : null;
    var rows = [
      ["نام فرستنده", field("full_name").val() || "—"],
      ["استان / شهر", (provinceTitle || "—") + " / " + (cityTitle || "—")],
      ["نشانی", [$.trim(field("address").val() || ""), $.trim(field("address_detail").val() || "")].filter(Boolean).join("، ") || "—"],
      ["کد پستی", field("postal_code").val() || "—"],
      ["موبایل فرستنده", field("mobile").val() || "—"],
      ["موبایل", field("mobile").val() || "—"],
      ["کد ملی", field("national_code").val() || "—"],
      ["سریال کارت ملی", field("national_code_serial").val() || "—"],
      ["تاریخ تولد", (field("birth_jy").val() ? faNum(field("birth_jd").val()) + " " + MONTHS[Number(field("birth_jm").val()) - 1] + " " + faNum(field("birth_jy").val()) : "—")],
      ["سرویس‌دهنده", bootstrap.provider ? bootstrap.provider.title : "پست"],
      ["منطقه پستی", locationTitle || "—"],
      ["مرکز تحویل", nodeTitle || "—"],
      ["نشانی مرکز", nodeAddress || "—"],
      ["روش پرداخت هزینه", payTitle || "—"]
    ];
    var $review = $wizard.find("[data-review]").empty();
    var $identityReview = $wizard.find("[data-review-identity]").empty();
    $.each(rows, function (i, row) {
      var $target = i >= 5 && i <= 8 ? $identityReview : $review;
      $target.append($("<div class='sg-wizard__review-row'>").append(
        $("<dt>").text(row[0]),
        $("<dd>").text(row[1])
      ));
    });
    $wizard.find("[data-cost-display]").text(costRial != null ? faThousands(costRial / 10) : "—");
  }

  function fillProvinces() {
    var $p = field("province_id").empty().append($("<option>").val("").text("انتخاب استان"));
    $.each(bootstrap.provinces || [], function (i, province) {
      $p.append($("<option>").val(String(province.id)).text(province.title));
    });
  }

  function fillCities(provinceId, selectedCityId) {
    var $c = field("city_id").empty().append($("<option>").val("").text("انتخاب شهر"));
    provinceTitle = "";
    cityTitle = "";
    $.each(bootstrap.provinces || [], function (i, province) {
      if (String(province.id) === String(provinceId)) {
        provinceTitle = province.title;
        $.each(province.children || [], function (j, city) {
          $c.append($("<option>").val(String(city.id)).text(city.title));
          if (String(city.id) === String(selectedCityId)) { cityTitle = city.title; }
        });
      }
    });
    if (selectedCityId) { $c.val(String(selectedCityId)); }
    $c.prop("disabled", $c.find("option").length < 2);
  }

  function resetLocationAndNode() {
    locationsVersion++; nodesVersion++;
    if (locationsRequest && locationsRequest.abort) locationsRequest.abort();
    if (nodesRequest && nodesRequest.abort) nodesRequest.abort();
    locationTitle = "";
    nodeTitle = "";
    nodeAddress = "";
    field("provider_location_id").prop("disabled", true).empty().append($("<option>").val("").text("ابتدا استان را انتخاب کنید"));
    field("provider_node_id").prop("disabled", true).empty().append($("<option>").val("").text("ابتدا منطقه پستی را انتخاب کنید"));
    $wizard.find("[data-retry-locations], [data-retry-nodes], [data-selected-center]").prop("hidden", true);
    setState("provider_location_id", ""); setState("provider_node_id", "");
  }

  function loadProviderLocations(provinceId) {
    resetLocationAndNode();
    var version = locationsVersion;
    function current() { return version === locationsVersion && String(field("province_id").val()) === String(provinceId); }
    function failed(message) { if (!current()) return; setState("provider_location_id", message, true); $wizard.find("[data-retry-locations]").prop("hidden", false); }
    setState("provider_location_id", "در حال دریافت مناطق پستی…");
    setState("provider_node_id", "");
    locationsRequest = sgWPost("salam_gate_provider_locations", { province_id: provinceId })
      .done(function (res) {
        if (!current()) return;
        if (res && res.success && res.data) {
          if (!Array.isArray(res.data.locations)) { failed("اطلاعات مناطق پستی کامل نیست؛ دوباره دریافت کنید."); return; }
          if (!res.data.locations.length) {
            setState("provider_location_id", "برای این استان منطقه تحت پوشش سلام‌گیت پیدا نشد؛ استان فرستنده را بررسی کنید.", true);
            return;
          }
          setState("provider_location_id", "");
          var $l = field("provider_location_id").empty().append($("<option>").val("").text("انتخاب منطقه پستی"));
          $.each(res.data.locations || [], function (i, loc) {
            $l.append($("<option>").val(String(loc.id)).text(loc.title));
          });
          $l.prop("disabled", false);
          return;
        }
        failed((res && res.data && res.data.message) || "دریافت مناطق پستی انجام نشد؛ دوباره تلاش کنید.");
      })
      .fail(function () {
        failed("دریافت مناطق پستی انجام نشد؛ دوباره تلاش کنید.");
      });
  }

  function loadProviderNodes(locationId) {
    nodesVersion++;
    if (nodesRequest && nodesRequest.abort) nodesRequest.abort();
    var version = nodesVersion;
    function current() { return version === nodesVersion && String(field("provider_location_id").val()) === String(locationId); }
    function failed(message) { if (!current()) return; setState("provider_node_id", message, true); $wizard.find("[data-retry-nodes]").prop("hidden", false); }
    nodeTitle = "";
    nodeAddress = "";
    field("provider_node_id").prop("disabled", true).empty().append($("<option>").val("").text("در حال دریافت…"));
    $wizard.find("[data-retry-nodes], [data-selected-center]").prop("hidden", true);
    setState("provider_node_id", "در حال دریافت مراکز تحویل…");
    nodesRequest = sgWPost("salam_gate_provider_nodes", { provider_location_id: locationId })
      .done(function (res) {
        if (!current()) return;
        if (res && res.success && res.data) {
          if (!Array.isArray(res.data.nodes)) { failed("اطلاعات مراکز تحویل کامل نیست؛ دوباره دریافت کنید."); return; }
          setState("provider_node_id", "");
          var $n = field("provider_node_id").empty().append($("<option>").val("").text("انتخاب مرکز تحویل"));
          $.each(res.data.nodes || [], function (i, node) {
            $n.append($("<option>").val(String(node.id)).text(node.title).data("address", node.address || ""));
          });
          $n.prop("disabled", !res.data.nodes.length);
          if ((res.data.nodes || []).length === 0) {
            setState("provider_node_id", "برای این منطقه مرکز تحویلی ثبت نشده است.", true);
          }
          return;
        }
        failed((res && res.data && res.data.message) || "دریافت مراکز تحویل انجام نشد؛ دوباره تلاش کنید.");
      })
      .fail(function () {
        failed("دریافت مراکز تحویل انجام نشد؛ دوباره تلاش کنید.");
      });
  }

  function fillBirthSelectors() {
    var $y = field("birth_jy").empty().append($("<option>").val("").text("انتخاب"));
    for (var y = bootstrap.currentJy; y >= bootstrap.minimumJy; y--) {
      $y.append($("<option>").val(String(y)).text(faNum(y)));
    }
    var $m = field("birth_jm").empty().append($("<option>").val("").text("انتخاب"));
    $.each(MONTHS, function (i, m) { $m.append($("<option>").val(String(i + 1)).text(m)); });
    fillBirthDays();
  }

  function fillPayMethods() {
    var previous = $form.find("[name='pay_method']:checked").val();
    var $wrap = $form.find("[data-pay-methods]").empty();
    $.each(bootstrap.payMethods || [], function (i, method) {
      var id = "sgw-pay-" + method.id;
      $wrap.append(
        $("<label class='sg-radio sg-activation__pay-card'>").append(
          $("<input>").attr({ type: "radio", name: "pay_method", value: method.id, id: id }).data("title", method.title),
          $("<span class='sg-activation__pay-icon dashicons' aria-hidden='true'>").addClass(Number(method.id) === 2 ? "dashicons-money-alt" : "dashicons-store"),
          $("<span class='sg-activation__pay-content'>").append($("<strong>").text(method.title), $("<small>").attr("data-activation-balance", method.id).text("در حال دریافت موجودی…"))
        )
      );
    });
    if (previous) $wrap.find("input").filter(function () { return this.value === String(previous); }).prop("checked", true);
    updatePayCards();
  }

  function updatePayCards() {
    $form.find(".sg-activation__pay-card").each(function () { $(this).toggleClass("is-selected", $(this).find("input").prop("checked")); });
    renderActivationFunding();
  }

  // ---- راه‌اندازی ----
  try {
    var $cfg = $wizard.find("[data-sg-wizard-config]");
    if ($cfg.length) {
      var cfg = JSON.parse($cfg.text() || "{}");
      bootstrap.leapYears = cfg.leapYears || [];
      bootstrap.currentJy = cfg.currentJy || 1400;
      bootstrap.minimumJy = Number.isSafeInteger(cfg.minimumJy) ? cfg.minimumJy : 1301;
      bootstrap.birthBounds = cfg.birthBounds || null;
    }
  } catch (e) { /* پیکربندی سال کبیسه ضروری نیست — سرور مرز روز را نهایی می‌سنجد */ }

  fillBirthSelectors();
  resetLocationAndNode();
  goToStep(1);

  function loadActivationBootstrap() {
  if (submitting || registrationBlocked) return;
  var version = ++bootstrapVersion;
  bootstrapReady = false;
  ++balanceVersion; balancePending = false; activationBalances = {};
  $form.find("[data-accept-rules]").prop("checked", false);
  syncButtons();
  $wizard.find("[data-wizard-retry]").prop("hidden", true);
  var previousProvince = field("province_id").val(), previousCity = field("city_id").val();
  field("province_id").prop("disabled", true);
  var activationLoading = window.WoosalamGateLoading && window.WoosalamGateLoading.begin($form[0], "bootstrap", loadActivationBootstrap);
  sgWPost("salam_gate_onboarding_bootstrap", { purpose: "activation" })
    .done(function (res) {
      if (version !== bootstrapVersion) return;
      if (!res || !res.success || !res.data) {
        $wizard.find("[data-wizard-retry]").prop("hidden", false);
        var message = (res && res.data && res.data.message) || "اطلاعات فعال‌سازی دریافت نشد؛ دوباره تلاش کنید.";
        if (activationLoading) activationLoading.fail(message);
        notice(message, false, !activationLoading);
        return;
      }
      var data = res.data;
      if (data.registration_state === "unknown" || data.registration_state === "pending"
        || (Array.isArray(data.shops) && data.shops.some(function (shop) { return shop.provider && Number(shop.provider.id) === 1; }))) {
        if (activationLoading) activationLoading.ready();
        blockRegistration(data.registration_state === "unknown"
          ? "در حال بررسی پرداخت؛ تأیید پرداخت هنوز دریافت نشده؛ وضعیت فعال‌سازی را بررسی کنید. دوباره پرداخت نکنید."
          : "درخواست فعال‌سازی این حساب قبلاً ثبت شده است؛ وضعیت آن را بررسی کنید.");
        $steps.prop("hidden", true).removeClass("is-active");
        $btnNext.hide(); $btnPrev.hide(); $btnSubmit.hide();
        return;
      }
      var validProvinces = Array.isArray(data.provinces) && data.provinces.length && data.provinces.every(function (province) {
        return Number.isSafeInteger(province.id) && province.id > 0 && typeof province.title === "string" && Array.isArray(province.children)
          && province.children.every(function (city) { return Number.isSafeInteger(city.id) && city.id > 0 && typeof city.title === "string"; });
      }) && data.provinces.some(function (province) { return province.children.length; });
      var validProvider = data.provider && data.provider.id === 1 && typeof data.provider.title === "string"
        && Number.isSafeInteger(data.provider.activation_cost) && data.provider.activation_cost >= 0;
      var validMethods = Array.isArray(data.pay_methods) && data.pay_methods.length === 2
        && data.pay_methods.some(function (method) { return method.id === 8; }) && data.pay_methods.some(function (method) { return method.id === 2; });
      if (!validProvinces || !validProvider || !validMethods || !Array.isArray(data.shops)) {
        var message = "اطلاعات لازم برای فعال‌سازی کامل دریافت نشد؛ دوباره تلاش کنید. هنوز ثبت یا پرداختی انجام نشده است.";
        if (activationLoading) activationLoading.fail(message);
        notice(message, false, !activationLoading);
        $wizard.find("[data-wizard-retry]").prop("hidden", false);
        return;
      }
      bootstrap.provinces = res.data.provinces || [];
      bootstrap.provider = res.data.provider;
      bootstrap.payMethods = res.data.pay_methods || [];
      [8, 2].forEach(function (method) {
        activationBalances[method] = balanceRecord(data.balances && data.balances[method], method);
      });

      fillProvinces();
      field("province_id").prop("disabled", false);
      if (previousProvince) {
        field("province_id").val(previousProvince);
        fillCities(field("province_id").val(), previousCity);
      }
      fillPayMethods();

      var user = data.profile && data.profile.user || {}, identity = data.identity || {
        mobile: user.mobile, national_code: user.national_code, national_code_serial: user.national_card_serial
      };
      ["mobile", "national_code_serial", "full_name"].forEach(function (name) {
        if (typeof identity[name] === "string" && !field(name).val()) field(name).val(identity[name]);
      });
      // The national code belongs to the connected account; edits to other fields survive a read retry.
      field("national_code").val(identity.national_code || "").prop("readOnly", true);
      var birth = identity.birth_date;
      if (birth && !field("birth_jy").val() && !field("birth_jm").val() && !field("birth_jd").val()) {
        field("birth_jy").val(String(birth.jy)); field("birth_jm").val(String(birth.jm));
        fillBirthDays(); field("birth_jd").val(String(birth.jd));
      }
      if (!identity.national_code) {
        setFieldError("national_code", "کد ملی از حساب باسلام دریافت نشد؛ اطلاعات حساب باسلام را بررسی و دریافت اطلاعات را دوباره امتحان کنید.");
        $wizard.find("[data-wizard-retry]").prop("hidden", false);
      }
      selectActivationMethod();
      bootstrapReady = true;
      syncButtons();
      if (currentStep === TOTAL_STEPS) renderReview();
      if (activationLoading) activationLoading.ready();
    })
    .fail(function () {
      if (version !== bootstrapVersion) return;
      $wizard.find("[data-wizard-retry]").prop("hidden", false);
      var message = "ارتباط برای دریافت اطلاعات فعال‌سازی برقرار نشد؛ دوباره تلاش کنید.";
      if (activationLoading) activationLoading.fail(message);
      notice(message, false, !activationLoading);
    });
  }
  loadActivationBootstrap();

  // ---- رویدادها ----
  $form.on("change", '[name="province_id"]', function () {
    var pid = $(this).val();
    field("city_id").prop("disabled", true).empty().append($("<option>").val("").text("انتخاب شهر"));
    cityTitle = ""; provinceTitle = "";
    if (pid) { fillCities(pid); loadProviderLocations(pid); } else { resetLocationAndNode(); setState("provider_location_id", ""); }
  });

  $form.on("change", '[name="city_id"]', function () {
    var pid = field("province_id").val();
    cityTitle = "";
    $.each(bootstrap.provinces || [], function (i, province) {
      if (String(province.id) === String(pid)) {
        $.each(province.children || [], function (j, city) {
          if (String(city.id) === String($(field("city_id")).val())) { cityTitle = city.title; }
        });
      }
    });
  });

  $form.on("change", '[name="birth_jy"], [name="birth_jm"]', fillBirthDays);

  $form.on("change", '[name="provider_location_id"]', function () {
    var lid = $(this).val();
    var $opt = $(this).find("option:selected");
    locationTitle = lid ? ($opt.text() || "") : "";
    if (lid) { loadProviderNodes(lid); } else {
      nodesVersion++;
      if (nodesRequest && nodesRequest.abort) nodesRequest.abort();
      nodeTitle = ""; nodeAddress = "";
      field("provider_node_id").prop("disabled", true).empty().append($("<option>").val("").text("ابتدا منطقه پستی را انتخاب کنید"));
      $wizard.find("[data-selected-center], [data-retry-nodes]").prop("hidden", true);
      setState("provider_node_id", "");
    }
  });

  $form.on("change", '[name="provider_node_id"]', function () {
    var $opt = $(this).find("option:selected");
    nodeTitle = $(this).val() ? ($opt.text() || "") : "";
    nodeAddress = $opt.data("address") || "";
    $wizard.find("[data-selected-center]").prop("hidden", !nodeTitle);
    $wizard.find("[data-selected-center-title]").text(nodeTitle);
    $wizard.find("[data-selected-center-address]").text(nodeAddress || "نشانی مرکز در پاسخ سرویس موجود نیست؛ پیش از تحویل، نشانی را بررسی کنید.");
  });

  $form.on("change", "[name='pay_method']", function () {
    paymentMethodManual = true;
    $form.find("[data-accept-rules]").prop("checked", false);
    updatePayCards(); if (currentStep === TOTAL_STEPS) renderReview(); syncButtons();
  });
  $form.on("change", "[data-accept-rules]", syncButtons);
  $wizard.find("[data-wizard-balance-refresh]").on("click", refreshActivationBalances);
  $form.on("input", "[name='national_code_serial']", function () {
    var value = $.trim(toEnDigits(this.value)).toUpperCase();
    if (this.value !== value) this.value = value;
    setFieldError("national_code_serial", value && !/^[A-Z0-9]{10}$/.test(value) ? "سریال کارت ملی باید دقیقاً ۱۰ حرف یا عدد لاتین باشد." : "");
  });
  var $serialDialog = $wizard.find("[data-wizard-serial-dialog]");
  function closeSerialHelp() {
    if (!$serialDialog.length) return;
    if (typeof $serialDialog[0].close === "function") $serialDialog[0].close();
    else { $serialDialog.removeAttr("open").trigger("close"); }
  }
  $wizard.find("[data-wizard-serial-help]").on("click", function () {
    if (!$serialDialog.length) return;
    serialHelpTrigger = this;
    if (typeof $serialDialog[0].showModal === "function") $serialDialog[0].showModal();
    else $serialDialog.attr("open", "");
    $serialDialog.find("[data-wizard-serial-close]").trigger("focus");
  });
  $serialDialog.on("close", function () { if (serialHelpTrigger && document.contains(serialHelpTrigger)) serialHelpTrigger.focus(); })
    .on("keydown", function (event) { if (event.key === "Escape") { event.preventDefault(); closeSerialHelp(); } });
  $wizard.find("[data-wizard-serial-close]").on("click", closeSerialHelp);
  $wizard.find("[data-retry-locations]").on("click", function () { var id = field("province_id").val(); if (id) loadProviderLocations(id); });
  $wizard.find("[data-retry-nodes]").on("click", function () { var id = field("provider_location_id").val(); if (id) loadProviderNodes(id); });
  $wizard.find("[data-wizard-retry]").on("click", loadActivationBootstrap);

  $btnNext.on("click", function () {
    if (!bootstrapReady || submitting || registrationBlocked) return;
    $notice.hide();
    if (!validateStep(currentStep)) { return; }
    goToStep(Math.min(currentStep + 1, TOTAL_STEPS));
  });

  $btnPrev.on("click", function () {
    if (submitting || registrationBlocked) return;
    $notice.hide();
    goToStep(Math.max(currentStep - 1, 1));
  });

  $form.on("submit", function (e) { e.preventDefault(); });

  $btnSubmit.on("click", function () {
    if (submitting || registrationBlocked || !bootstrapReady) return;

    for (var s = 1; s <= TOTAL_STEPS; s++) {
      if (!validateStep(s)) {
        goToStep(s);
        return;
      }
    }

    submitting = true;
    $btnSubmit.prop("disabled", true).text("در حال ثبت…");
    $notice.hide();

    var payload = {};
    $.each($form.serializeArray(), function (i, pair) {
      if (pair.name !== "_wpnonce" && pair.name !== "_wp_http_referer" && pair.name !== "_sg_activation_nonce") payload[pair.name] = pair.value;
    });
    payload.accept_rules = $form.find("[data-accept-rules]").is(":checked") ? "1" : "";
    payload.expected_activation_cost_rial = bootstrap.provider.activation_cost;
    syncButtons();
    $steps.attr("inert", "");

    sgWPost("salam_gate_register_shop", payload)
      .done(function (res) {
        if (res && res.success && res.data) {
          if (!res.data.shop || !Number.isSafeInteger(res.data.shop.id) || res.data.shop.id <= 0
            || [14, 15, 16, 17].indexOf(res.data.shop.status_id) === -1) {
            blockRegistration("در حال بررسی پرداخت؛ تأیید پرداخت هنوز دریافت نشده؛ وضعیت فعال‌سازی را بررسی کنید. دوباره پرداخت نکنید.");
            return;
          }
          var queued = !!res.data.queued;
          registrationBlocked = true;
          $checkStatus.prop("hidden", false).attr("href", statusUrl);
          notice(queued
            ? "درخواست فعال‌سازی ثبت شد و در حال بررسی است؛ دوباره ثبت یا پرداخت نکنید."
            : "ثبت انجام شد؛ وضعیت نهایی در صفحه سلام‌گیت نمایش داده می‌شود.", true);
          // پاکسازی state فرم پس از موفقیت (هیچ draft هویتی جایی ذخیره نمی‌شود)
          $form[0].reset();
          showActivationStatus($wizard.closest("[data-sg-root]").find("#sg-state-container"));
          return;
        }

        var data = (res && res.data) || {};
        var code = data.code || "";

        if (data.errors && typeof data.errors === "object") {
          clearAllErrors();
          var firstStep = null;
          $.each(data.errors, function (i, err) {
            var name = (typeof err === "string" ? i : (err.location || "").split(".").pop());
            var message = typeof err === "string" ? err : err.message;
            if (name === "phone") name = "mobile";
            if (!name || !message) return;
            setFieldError(name, message);
            if (firstStep === null) { firstStep = stepOfField(name); }
          });
          if (firstStep) { goToStep(firstStep); }
        }

        if (code === "register_unknown") {
          // نتیجه مبهم — تکرار کور ممنوع؛ فقط بررسی وضعیت
          blockRegistration("در حال بررسی پرداخت؛ تأیید پرداخت هنوز دریافت نشده؛ وضعیت فعال‌سازی را بررسی کنید. دوباره پرداخت نکنید.");
          return;
        }

        if (code === "registration_pending" || code === "duplicate_registration") {
          blockRegistration(data.message || "درخواست فعال‌سازی قبلاً ثبت شده است؛ وضعیت آن را بررسی کنید.");
          return;
        }
        if (code === "insufficient_balance" || code === "balance_unavailable") {
          var method = activationMethod();
          activationBalances[method] = {state: "unavailable"};
          $form.find("[data-accept-rules]").prop("checked", false);
          notice(data.message || "موجودی کافی برای فعال‌سازی تأیید نشد؛ موجودی را تازه‌سازی کنید.", false);
          return;
        }
        if (code === "activation_cost_changed") {
          if (Number.isSafeInteger(data.activation_cost_rial) && data.activation_cost_rial >= 0) {
            bootstrap.provider.activation_cost = data.activation_cost_rial;
            renderReview();
            $form.find("[data-accept-rules]").prop("checked", false);
            notice("هزینه فعال‌سازی تغییر کرده است. مبلغ جدید " + faThousands(data.activation_cost_rial / 10) + " تومان است؛ قبل از پرداخت دوباره آن را تأیید کنید. هنوز پرداختی انجام نشده است.", false);
          } else {
            bootstrapReady = false;
            $wizard.find("[data-wizard-retry]").prop("hidden", false);
            notice("هزینه فعال‌سازی تأیید نشد؛ اطلاعات را دوباره دریافت کنید. پرداختی انجام نشده است.", false);
          }
          return;
        }

        notice(data.message || "ثبت ناموفق بود؛ موارد مشخص‌شده را اصلاح کنید.", false);
        $btnSubmit.text("فعال‌سازی و پرداخت هزینه");
      })
      .fail(function () {
        blockRegistration("در حال بررسی پرداخت؛ تأیید پرداخت هنوز دریافت نشده؛ وضعیت فعال‌سازی را بررسی کنید. دوباره پرداخت نکنید.");
      })
      .always(function () {
        submitting = false;
        $steps.removeAttr("inert");
        if (!registrationBlocked) $btnSubmit.text("فعال‌سازی و پرداخت هزینه");
        syncButtons();
      });
  });
  }
  window.SalamGateActivation = { init: initActivation };
  var openingActivation = false;
  function showActivationStatus($body) {
    if (!$body.length) return;
    $.post(basalam_ajax.ajaxurl, {action: "salam_gate_account_state", _wpnonce: basalam_ajax.nonce, fresh: "1"}).done(function (res) {
      if (res && res.success === true && res.data && typeof res.data.html === "string") {
        $body.html(res.data.html);
        $body.closest("[data-sg-root]").attr("data-sg-account-state", res.data.state).trigger("salamgate:account-state");
      }
    });
  }
  $(document).on("click.sgActivation", "[data-sg-open-activation]", function (event) {
    event.preventDefault();
    if (openingActivation) return;
    var $button = $(this), $body = $button.closest("[data-sg-root]").find("#sg-state-container"), title = $button.text();
    if (!$body.length) return;
    openingActivation = true; $button.attr("aria-disabled", "true").text("در حال دریافت اطلاعات…");
    $.ajax({url: basalam_ajax.ajaxurl, type: "POST", dataType: "json", timeout: 45000,
      data: {action: "salam_gate_activation_form", _wpnonce: basalam_ajax.nonce}}).done(function (res) {
      if (!res || res.success !== true || !res.data || typeof res.data.html !== "string" || !res.data.html.trim()) {
        if (window.woosalamNotify) window.woosalamNotify("فرم فعال‌سازی دریافت نشد؛ دوباره تلاش کنید.", "error");
        return;
      }
      $body.removeAttr("data-sg-load data-sg-load-state aria-busy").removeClass("sg-load-region").html(res.data.html);
      initActivation($body);
      if (window.SalamGatePickers) window.SalamGatePickers.refresh();
      $body[0].scrollIntoView({block: "start", behavior: "smooth"});
    }).fail(function () {
      if (window.woosalamNotify) window.woosalamNotify("ارتباط برای دریافت فرم فعال‌سازی برقرار نشد؛ دوباره تلاش کنید.", "error");
    }).always(function () { openingActivation = false; $button.removeAttr("aria-disabled").text(title); });
  });
  initActivation(document);
});

// =====================================================================
// Salam Gate — dashboard stats (part 5)
// =====================================================================
jQuery(function ($) {
  var $statsBlocks = $("[data-sg-stats]");
  if (!$statsBlocks.length) return;

  $statsBlocks.each(function () {
    var $block = $(this);
    var $state = $block.siblings("[data-sg-stats-state]") || $();
    function loadStats() {
    $state.text("").hide();
    var statsLoading = typeof window !== "undefined" && window.WoosalamGateLoading && window.WoosalamGateLoading.begin($block[0], "stats", loadStats);
    var request = typeof window !== "undefined" && window.WoosalamGateRequests ? window.WoosalamGateRequests.post : $.post;
    request(basalam_ajax.ajaxurl, { _wpnonce: basalam_ajax.nonce, action: "salam_gate_stats" })
      .done(function (res) {
        if (res && res.success && res.data) {
          var d = res.data;
          $block.find("[data-stat='order_count']").text(String(d.order_count != null ? d.order_count : "—"));
          $block.find("[data-stat='avg_shipping_cost_toman']").text(
            d.avg_shipping_cost_toman != null
              ? Math.floor(Number(d.avg_shipping_cost_toman)).toLocaleString("fa-IR").replace(/٬/g, ",")
              : "—"
          );
          $block.find("[data-stat='total_profit_toman']").text(
            d.total_profit_toman != null
              ? Math.floor(Number(d.total_profit_toman)).toLocaleString("fa-IR").replace(/٬/g, ",")
              : "—"
          );
          if (statsLoading) statsLoading.ready();
          return;
        }
        var detail = res && res.data && res.data.message;
        $state.text(typeof detail === "string" && detail.trim()
          ? "دریافت گزارش عملکرد انجام نشد: " + detail
          : "گزارش عملکرد فعلاً در دسترس نیست؛ دوباره تلاش کنید.").show();
        if (statsLoading) statsLoading.fail($state.text());
      })
      .fail(function (xhr, status) {
        var message = xhr && (xhr.status === 401 || xhr.status === 403)
          ? "اعتبار صفحه پایان یافته یا دسترسی مجاز نیست؛ صفحه را تازه‌سازی کنید."
          : (status === "timeout"
            ? "دریافت گزارش عملکرد بیش از حد طول کشید؛ دوباره تلاش کنید."
            : "دریافت گزارش عملکرد انجام نشد؛ اتصال سایت و پاسخ سرور را بررسی کنید.");
        $state.text(message).show();
        if (statsLoading) statsLoading.fail($state.text());
      });
    }
    loadStats();
  });
});

// =====================================================================
// Salam Gate — settings page (part 5)
// =====================================================================
jQuery(function ($) {
  var $settings = $("[data-sg-settings]");
  if (!$settings.length) return;

  var shopsData = [], addressesData = null, senderIdentity = null;
  var selectedAddress = null, selectedShop = null;
  var $addrModal = $("[data-sg-address-edit-modal]");
  var $detailsModal = $("[data-sg-address-details-modal]");
  var $location = $addrModal.find("[data-sender-location]");
  var $node = $addrModal.find("[data-sender-node]");
  var $saveNode = $addrModal.find("[data-save-address-edit]");
  var deliveryEpoch = 0, nodeEpoch = 0;
  var senderLocationEpoch = 0;
  var senderLocationRequest = null, senderNodeRequest = null;
  var senderLocationLoading = null, senderNodeLoading = null;

  function sgPost(action, data) {
    var request = window.WoosalamGateRequests ? window.WoosalamGateRequests.post : $.post;
    return request(basalam_ajax.ajaxurl, $.extend({ _wpnonce: basalam_ajax.nonce, action: action }, data || {}));
  }
  function toEn(value) {
    return String(value == null ? "" : value)
      .replace(/[۰-۹]/g, function (d) { return String.fromCharCode(d.charCodeAt(0) - 0x06F0 + 48); })
      .replace(/[٠-٩]/g, function (d) { return String.fromCharCode(d.charCodeAt(0) - 0x0660 + 48); });
  }
  function operationNotice($notice, message, kind) {
    if (typeof window.woosalamNotify === "function") {
      $notice.hide().empty();
      window.woosalamNotify(message, kind);
    } else {
      $notice.toggleClass("sg-wizard__notice--ok", kind === "success").text(message).show();
    }
  }
  function options($select, label, disabled) {
    $select.empty().append($("<option>").val("").text(label)).prop("disabled", !!disabled);
  }
  function addressShop(addr) {
    var matches = (shopsData || []).filter(function (shop) {
      return shop.address && String(shop.address.id) === String(addr.id);
    });
    return matches.length === 1 ? matches[0] : null;
  }
  function statusBadge(shop) {
    var kind = shop.status_id === 14 ? "active" : (shop.status_id === 15 ? "pending" : "inactive");
    return $("<span class='salam-gate-badge sg-settings__status'>").attr("data-status", kind).text(shop.status_title || "وضعیت نامشخص");
  }
  function renderShopsAndAddresses() {
    var $section = $settings.find("[data-sg-addresses]").empty();
    var $addressesColumn = $("<div class='sg-settings__address-grid'>");
    $section.append($addressesColumn);

    if (addressesData === null) {
      $addressesColumn.append($("<p class='sg-card__detail sg-card__detail--error'>").text("دریافت آدرس‌ها ناموفق بود؛ دوباره تلاش کنید."));
    } else if (!addressesData.items || addressesData.items.length === 0) {
      $addressesColumn.append($("<p class='sg-card__detail'>").text("آدرس فرستنده‌ای ثبت نشده است."));
    } else {
      $.each(addressesData.items, function (_, addr) {
        var shop = addressShop(addr);
        var $card = $("<div class='sg-settings__addr'>").attr("data-address-card", addr.id);
        var $radio = $("<input type='radio' name='sg-default-addr'>")
          .attr("value", addr.id).prop("checked", !!addr.is_default).prop("disabled", !!addr.is_default)
          .attr("aria-label", "پیش‌فرض کردن این آدرس");
        $card.append(
          $("<div class='sg-settings__addr-head'>").append(
            $("<label class='sg-check sg-check--radio'>").append($radio, $("<span>").text(addr.is_default ? "فرستنده پیش‌فرض" : "انتخاب پیش‌فرض")),
            shop ? statusBadge(shop) : $()
          ),
          $("<div class='sg-settings__addr-body'>").append(
            $("<strong>").text(addr.full_name || "—"),
            $("<p class='sg-card__detail'>").text(addr.full_address || addr.address || "—"),
            $("<p class='sg-card__detail'>").text("کد پستی: " + (addr.postal_code || "—") + " · موبایل: " + (addr.phone || "—")),
            shop && shop.provider_node ? $("<p class='sg-card__detail'>").text("مرکز تحویل: " + (shop.provider_node.title || "—")) : $()
          ),
          $("<div class='sg-card__actions'>").append(
            $("<button type='button' class='sg-button sg-button--secondary sg-settings__edit-addr'>")
              .attr("data-address-id", addr.id).attr("data-address-version", addr.active_version_id || "")
              .append($("<span class='dashicons dashicons-edit' aria-hidden='true'>"), "ویرایش فرستنده")
          )
        );
        $addressesColumn.append($card);
      });
    }
  }
  function loadSettings() {
    var settingsLoading = window.WoosalamGateLoading && window.WoosalamGateLoading.begin($settings[0], "settings", loadSettings);
    $settings.find("[data-sg-addresses]").html("<div class='sg-settings__loading'><span class='sg-loading-spinner' aria-hidden='true'></span> در حال دریافت اطلاعات…</div>");
    sgPost("salam_gate_settings_data").done(function (res) {
      if (res && res.success && res.data) {
        shopsData = res.data.shops || [];
        addressesData = res.data.addresses;
        senderIdentity = res.data.sender_identity || null;
      } else { shopsData = null; addressesData = null; senderIdentity = null; }
      renderShopsAndAddresses();
      if (settingsLoading) {
        if (shopsData !== null && addressesData !== null) settingsLoading.ready();
        else settingsLoading.fail("اطلاعات غرفه و آدرس فرستنده دریافت نشد؛ دوباره تلاش کنید.");
      }
    }).fail(function () {
      shopsData = null; addressesData = null; senderIdentity = null;
      renderShopsAndAddresses();
      if (settingsLoading) settingsLoading.fail("ارتباط برای دریافت تنظیمات برقرار نشد؛ دوباره تلاش کنید.");
    });
  }
  function renderAddressSummary() {
    if (!selectedAddress) return;
    $addrModal.find("[data-sender-summary-name]").text(selectedAddress.full_name || "—");
    $addrModal.find("[data-sender-summary-address]").text(selectedAddress.full_address || selectedAddress.address || "—");
    $addrModal.find("[data-sender-summary-contact]").text("کد پستی: " + (selectedAddress.postal_code || "—") + " · موبایل: " + (selectedAddress.phone || "—"));
  }
  function updateNodeSave() {
    $saveNode.prop("disabled", !selectedShop || $node.prop("disabled") || !$node.val()
      || String($node.val()) === String((selectedShop.provider_node || {}).id || "") || !!$addrModal.data("saving"));
  }
  function abortSenderRead(request) { if (request && typeof request.abort === "function") request.abort(); }
  function stopSenderReads() {
    ++senderLocationEpoch; ++nodeEpoch;
    if (senderLocationLoading) senderLocationLoading.ready();
    if (senderNodeLoading) senderNodeLoading.ready();
    senderLocationLoading = null; senderNodeLoading = null;
    abortSenderRead(senderLocationRequest); abortSenderRead(senderNodeRequest);
    senderLocationRequest = null; senderNodeRequest = null;
  }
  function validSenderRows(rows) {
    return Array.isArray(rows) && rows.every(function (row) {
      return row && /^[1-9]\d*$/.test(String(row.id)) && Number.isSafeInteger(Number(row.id))
        && typeof row.title === "string" && row.title.trim() !== "";
    });
  }
  function senderReadFailure(loading, message) {
    if (loading) loading.fail(message);
    else operationNotice($addrModal.find("[data-addr-edit-notice]"), message, "error");
  }
  function loadSenderNodes(locationId, currentNodeId) {
    var epoch = deliveryEpoch, requestEpoch = ++nodeEpoch;
    if (senderNodeLoading) senderNodeLoading.ready();
    senderNodeLoading = null;
    abortSenderRead(senderNodeRequest);
    options($node, locationId ? "در حال دریافت مراکز تحویل…" : "ابتدا منطقه پستی را انتخاب کنید", true);
    updateNodeSave();
    if (!locationId) return;
    senderNodeLoading = window.WoosalamGateLoading && window.WoosalamGateLoading.begin($addrModal.find("[data-sg-load]")[0], "sender-nodes", function () { loadSenderNodes($location.val(), currentNodeId); });
    var loading = senderNodeLoading;
    senderNodeRequest = sgPost("salam_gate_provider_nodes", { provider_location_id: locationId }).done(function (res) {
      if (epoch !== deliveryEpoch || requestEpoch !== nodeEpoch || String($location.val()) !== String(locationId)) return;
      if (!res || !res.success || !res.data || !validSenderRows(res.data.nodes)) {
        options($node, "دریافت مراکز ناموفق بود", true);
        senderReadFailure(loading, (res && res.data && res.data.message) || "دریافت مراکز تحویل ناموفق بود.");
        return;
      }
      options($node, res.data.nodes.length ? "انتخاب مرکز تحویل" : "مرکزی در این منطقه ثبت نشده است", !res.data.nodes.length);
      $.each(res.data.nodes, function (_, node) { $node.append($("<option>").val(String(node.id)).text(node.title)); });
      if (currentNodeId) $node.val(String(currentNodeId));
      updateNodeSave();
      if (loading) loading.ready();
    }).fail(function () {
      if (epoch !== deliveryEpoch || requestEpoch !== nodeEpoch) return;
      options($node, "دریافت مراکز ناموفق بود", true);
      senderReadFailure(loading, "ارتباط برای دریافت مراکز تحویل برقرار نشد؛ دوباره تلاش کنید.");
    });
  }
  function loadSenderLocations(provinceId) {
    var epoch = deliveryEpoch, requestEpoch = ++senderLocationEpoch;
    ++nodeEpoch;
    if (senderLocationLoading) senderLocationLoading.ready();
    if (senderNodeLoading) senderNodeLoading.ready();
    senderLocationLoading = null; senderNodeLoading = null;
    abortSenderRead(senderLocationRequest); abortSenderRead(senderNodeRequest);
    options($location, "در حال دریافت مناطق پستی…", true);
    options($node, "ابتدا منطقه پستی را انتخاب کنید", true);
    updateNodeSave();
    senderLocationLoading = window.WoosalamGateLoading && window.WoosalamGateLoading.begin($addrModal.find("[data-sg-load]")[0], "sender-locations", function () { loadSenderLocations(provinceId); });
    var loading = senderLocationLoading;
    senderLocationRequest = sgPost("salam_gate_provider_locations", { province_id: provinceId }).done(function (res) {
      if (epoch !== deliveryEpoch || requestEpoch !== senderLocationEpoch) return;
      if (!res || !res.success || !res.data || !validSenderRows(res.data.locations)) {
        options($location, "دریافت مناطق ناموفق بود", true);
        senderReadFailure(loading, (res && res.data && res.data.message) || "دریافت مناطق پستی ناموفق بود؛ دوباره تلاش کنید.");
        return;
      }
      options($location, res.data.locations.length ? "انتخاب منطقه پستی" : "منطقه‌ای در دسترس نیست", !res.data.locations.length);
      $.each(res.data.locations, function (_, loc) { $location.append($("<option>").val(String(loc.id)).text(loc.title)); });
      var currentNode = selectedShop.provider_node || {};
      var currentLocation = currentNode.provider_location || {};
      if (currentLocation.id && $location.find("option").filter(function () { return this.value === String(currentLocation.id); }).length) {
        $location.val(String(currentLocation.id));
        loadSenderNodes(String(currentLocation.id), currentNode.id);
      }
      if (loading) loading.ready();
    }).fail(function () {
      if (epoch !== deliveryEpoch || requestEpoch !== senderLocationEpoch) return;
      options($location, "دریافت مناطق ناموفق بود", true);
      senderReadFailure(loading, "ارتباط برای دریافت مناطق پستی برقرار نشد؛ دوباره تلاش کنید.");
    });
  }
  function openAddressModal($button) {
    selectedAddress = null;
    $.each((addressesData && addressesData.items) || [], function (_, addr) {
      if (String(addr.id) === String($button.attr("data-address-id"))) selectedAddress = addr;
    });
    if (!selectedAddress || $addrModal.data("saving") || $detailsModal.data("saving")) return;
    selectedShop = addressShop(selectedAddress);
    ++deliveryEpoch;
    stopSenderReads();
    renderAddressSummary();
    $addrModal.find("[data-sender-national-code]").text((senderIdentity && senderIdentity.national_code) || "در دسترس نیست");
    $addrModal.find("[data-sender-national-serial]").text((senderIdentity && senderIdentity.national_card_serial) || "در دسترس نیست");
    $addrModal.find("[data-addr-edit-notice]").hide().empty();
    $addrModal.find("[data-sender-delivery-fields]").prop("hidden", !selectedShop);
    $saveNode.prop("hidden", !selectedShop).prop("disabled", true);
    options($location, "در حال دریافت مناطق پستی…", true);
    options($node, "ابتدا منطقه پستی را انتخاب کنید", true);
    $addrModal.show();
    if (!selectedShop) return;
    var provinceId = selectedAddress.city && selectedAddress.city.parent && selectedAddress.city.parent.id;
    if (!provinceId) {
      options($location, "استان این آدرس در دسترس نیست", true);
      return;
    }
    loadSenderLocations(provinceId);
  }
  $settings.on("click", ".sg-settings__edit-addr", function () { openAddressModal($(this)); });
  $location.on("change", function () { loadSenderNodes($(this).val(), null); });
  $node.on("change", updateNodeSave);
  $saveNode.on("click", function () {
    if ($(this).prop("disabled") || !selectedShop || $addrModal.data("saving")) return;
    var $notice = $addrModal.find("[data-addr-edit-notice]").hide().empty();
    $addrModal.data("saving", true);
    $saveNode.prop("disabled", true).text("در حال ذخیره…");
    sgPost("salam_gate_update_shop", {
      shop_id: selectedShop.id, provider_node_id: $node.val(), provider_location_id: $location.val(), sender_address_id: selectedAddress.id
    }).done(function (res) {
      if (res && res.success) {
        operationNotice($notice, res.data && res.data.status_id === 14 ? "مرکز تحویل ذخیره شد." : "مرکز تحویل ذخیره شد؛ حساب ارسال در انتظار تأیید است.", "success");
        $addrModal.hide(); loadSettings();
      } else { operationNotice($notice, (res && res.data && res.data.message) || "ذخیره مرکز تحویل ناموفق بود.", "error"); }
    }).fail(function () { operationNotice($notice, "ارتباط با سرور برقرار نشد.", "error"); })
      .always(function () { $addrModal.data("saving", false); $saveNode.text("ذخیره مرکز تحویل"); updateNodeSave(); });
  });
  function updateAddressLength() {
    var length = Array.from(String($detailsModal.find("#sgae-address").val() || "")).length;
    $detailsModal.find("[data-sg-address-length]").text(length.toLocaleString("fa-IR") + " کاراکتر");
  }
  $detailsModal.on("input", "#sgae-address", updateAddressLength);
  $addrModal.on("click", "[data-sender-edit-address]", function () {
    if (!selectedAddress || $detailsModal.data("saving")) return;
    $detailsModal.find("[data-addr-edit-id]").val(String(selectedAddress.id));
    $detailsModal.find("[data-addr-edit-city]").text((selectedAddress.city && selectedAddress.city.title) || "—");
    $detailsModal.find("[data-addr-edit-postal]").text(selectedAddress.postal_code || "—");
    $detailsModal.find("#sgae-name").val(selectedAddress.full_name || "");
    $detailsModal.find("#sgae-address").val(selectedAddress.address || "");
    updateAddressLength();
    $detailsModal.find("#sgae-phone").val(selectedAddress.phone || "");
    $detailsModal.find("[data-addr-details-notice]").hide().empty();
    if (window.SalamGateValidation) window.SalamGateValidation.reset($detailsModal);
    $detailsModal.show();
  });
  $detailsModal.on("click", "[data-save-address-details]", function () {
    if (!selectedAddress || $detailsModal.data("saving")) return;
    var $notice = $detailsModal.find("[data-addr-details-notice]").hide().empty();
    if (window.SalamGateValidation && !window.SalamGateValidation.validate($detailsModal)) return;
    var name = String($detailsModal.find("#sgae-name").val() || "").trim();
    var address = String($detailsModal.find("#sgae-address").val() || "").trim();
    var phone = toEn($detailsModal.find("#sgae-phone").val()).trim();
    if (!name || !address || !/^09\d{9}$/.test(phone)) {
      operationNotice($notice, "نام، نشانی کامل و شماره موبایل معتبر ۱۱ رقمی را وارد کنید.", "error"); return;
    }
    var payload = { address_id: selectedAddress.id };
    if (name !== selectedAddress.full_name) payload.full_name = name;
    if (address !== selectedAddress.address) payload.address = address;
    if (phone !== toEn(selectedAddress.phone)) payload.phone = phone;
    if (Object.keys(payload).length === 1) { $detailsModal.hide(); return; }
    var oldAddress = selectedAddress;
    var $button = $(this).prop("disabled", true).text("در حال ذخیره…");
    $detailsModal.data("saving", true);
    sgPost("salam_gate_update_address", payload).done(function (res) {
      if (res && res.success && res.data && res.data.address && String(res.data.address.id) === String(oldAddress.id)) {
        selectedAddress = res.data.address;
        $.each((addressesData && addressesData.items) || [], function (i, addr) { if (String(addr.id) === String(oldAddress.id)) addressesData.items[i] = selectedAddress; });
        renderAddressSummary(); renderShopsAndAddresses(); $detailsModal.hide();
        operationNotice($notice, "اطلاعات فرستنده ذخیره شد.", "success");
      } else { operationNotice($notice, (res && res.data && res.data.message) || "ذخیره اطلاعات فرستنده تأیید نشد؛ دوباره بررسی کنید.", "error"); }
    }).fail(function () { operationNotice($notice, "ارتباط با سرور برقرار نشد.", "error"); })
      .always(function () { $detailsModal.data("saving", false); $button.prop("disabled", false).text("ذخیره اطلاعات فرستنده"); });
  });
  // Default selection remains an explicit address-only operation.
  $settings.on("change", "input[name='sg-default-addr']", function () {
    var $radio = $(this), addressId = $radio.val();
    if (!addressId || $radio.prop("disabled")) return;
    sgPost("salam_gate_update_address", { address_id: addressId, is_default: "1" }).done(function (res) {
      operationNotice($settings.find("[data-sg-settings-notice]"), res && res.success ? "آدرس پیش‌فرض فرستنده ذخیره شد." : (res && res.data && res.data.message) || "تغییر پیش‌فرض ناموفق بود.", res && res.success ? "success" : "error");
      loadSettings();
    }).fail(function () { operationNotice($settings.find("[data-sg-settings-notice]"), "ارتباط با سرور برقرار نشد.", "error"); loadSettings(); });
  });
  $addrModal.add($detailsModal).each(function () {
    var $modal = $(this);
    function closeSenderDialog() {
      if ($modal.is($addrModal)) {
        // Ignore late read-only lookup responses after leaving the sender dialog.
        // Submitted saves keep their independent callbacks and are never cancelled.
        ++deliveryEpoch;
        stopSenderReads();
      }
      $modal.hide();
    }
    $modal.on("click", "[data-close-modal]", closeSenderDialog);
    $modal.on("click", function (event) { if (event.target === this) closeSenderDialog(); });
  });
  loadSettings();
});

// =====================================================================
// Salam Gate — post offices view (part 5)
// =====================================================================
jQuery(function ($) {
  var $view = $("[data-view='post-offices']");
  if (!$view.length) return;

  var provinces = [];
  var $province = $view.find("[data-po-province]");
  var $location = $view.find("[data-po-location]");
  var $locationState = $view.find("[data-po-location-state]");
  var $results = $view.find("[data-po-results]");
  var $resultSection = $view.find("[data-po-result-section]");
  var $referenceState = $view.find("[data-po-reference-state]");
  var $retry = $view.find("[data-po-retry]");
  var referenceEpoch = 0, locationEpoch = 0, nodeEpoch = 0;
  var referenceRequest = null, locationRequest = null, nodeRequest = null;
  var referencesLoading = null, locationLoading = null, nodeLoading = null;
  var retryStage = "references";

  function sgPost(action, data) {
    var request = window.WoosalamGateRequests ? window.WoosalamGateRequests.post : $.post;
    return request(basalam_ajax.ajaxurl, $.extend({ _wpnonce: basalam_ajax.nonce, action: action }, data || {}));
  }

  function abortRequest(request) { if (request && typeof request.abort === "function") request.abort(); }
  function options($select, text, disabled) {
    $select.empty().append($("<option>").val("").text(text)).prop("disabled", !!disabled);
  }
  function showResults() {
    var ready = !!$province.val() && !!$location.val();
    $resultSection.prop("hidden", !ready);
    return ready;
  }
  function resultMessage(text) {
    $view.find("[data-po-result-count]").empty();
    $results.empty();
    if (showResults()) $results.append($("<p class='sg-card__detail'>").text(text));
  }
  function failure(stage, message) {
    retryStage = stage;
    $retry.prop("hidden", false).show().prop("disabled", false);
    var floating = typeof window.woosalamNotify === "function";
    if (stage === "references") $referenceState.text(message).addClass("sg-field__state--error").toggleClass("sg-feedback-toast-only", floating);
    else if (stage === "locations") $locationState.text(message).addClass("sg-field__state--error").toggleClass("sg-feedback-toast-only", floating);
    else { resultMessage(message); $results.children().toggleClass("sg-feedback-toast-only", floating); }
    var loading = stage === "references" ? referencesLoading : (stage === "locations" ? locationLoading : nodeLoading);
    if (floating && !loading) window.woosalamNotify(message, "error");
    if (stage === "references" && referencesLoading) referencesLoading.fail(message);
    if (stage === "locations" && locationLoading) locationLoading.fail(message);
    if (stage === "nodes" && nodeLoading) nodeLoading.fail(message);
  }
  function validOptions(rows) {
    return Array.isArray(rows) && rows.length > 0 && rows.every(function (row) {
      return row && /^[1-9]\d*$/.test(String(row.id)) && typeof row.title === "string" && row.title.trim() !== "";
    });
  }
  function validNodes(rows) {
    return Array.isArray(rows) && rows.every(function (node) {
      return node && Number.isSafeInteger(Number(node.id)) && /^[1-9]\d*$/.test(String(node.id));
    });
  }
  function nodeText(value) { return typeof value === "string" ? value.trim() : ""; }
  function renderNodes(nodes) {
    var $grid = $("<div class='sg-po-grid'>");
    nodes.forEach(function (node) {
      var $details = $("<dl class='sg-po-card__details'>");
      var region = node.provider_location && nodeText(node.provider_location.title);
      var regionId = node.provider_location && node.provider_location.id;
      var validRegionId = Number.isSafeInteger(Number(regionId)) && /^[1-9]\d*$/.test(String(regionId));
      [["شناسه مرکز", String(node.id)], ["منطقه پستی", region],
        ["شناسه منطقه", validRegionId ? String(regionId) : ""], ["نشانی", node.address],
        ["مسئول مرکز", node.owner], ["شماره تماس", node.phone], ["کد پستی", node.postal_code]].forEach(function (row) {
        var value = nodeText(row[1]);
        var $value = $("<dd>").text(value || "ثبت نشده").toggleClass("is-missing", !value);
        if (row[0] === "شماره تماس" && /^[+\d\s()-]+$/.test(value)) {
          $value.empty().append($("<a>").attr("href", "tel:" + value.replace(/[\s()-]/g, "")).text(value));
        }
        if (row[0] === "شماره تماس" || row[0] === "کد پستی" || row[0] === "شناسه مرکز" || row[0] === "شناسه منطقه") $value.attr("dir", value ? "ltr" : "rtl");
        $details.append($("<div>").toggleClass("sg-po-card__address", row[0] === "نشانی").append($("<dt>").text(row[0]), $value));
      });
      $grid.append($("<article class='sg-po-card'>").attr("data-po-node-id", node.id).append(
        $("<div class='sg-po-card__header'>").append(
          $("<span class='sg-po-card__icon dashicons dashicons-location-alt' aria-hidden='true'>"),
          $("<h5 class='sg-po-card__title'>").text(nodeText(node.title) || "مرکز تحویل " + node.id),
          region ? $("<span class='sg-po-card__region'>").text(region) : $()), $details));
    });
    $view.find("[data-po-result-count]").text(nodes.length.toLocaleString("fa-IR") + " مرکز تحویل");
    $results.empty().append($grid);
  }

  // Public province data must not depend on onboarding/profile permission.
  function loadReferences(fresh) {
    referencesLoading = window.WoosalamGateLoading && window.WoosalamGateLoading.begin($view[0], "references", function () { loadReferences(true); });
    var epoch = ++referenceEpoch;
    ++locationEpoch; ++nodeEpoch;
    if (locationLoading) locationLoading.ready();
    if (nodeLoading) nodeLoading.ready();
    locationLoading = null; nodeLoading = null;
    abortRequest(referenceRequest); abortRequest(locationRequest); abortRequest(nodeRequest);
    options($province, "در حال دریافت استان‌ها…", true);
    options($location, "ابتدا استان را انتخاب کنید", true);
    $locationState.text("").removeClass("sg-field__state--error sg-feedback-toast-only");
    $referenceState.text("در حال دریافت فهرست استان‌ها…").removeClass("sg-field__state--error sg-feedback-toast-only");
    $retry.prop("hidden", true).hide();
    resultMessage("برای مشاهده مراکز، استان و منطقه پستی را انتخاب کنید.");
    referenceRequest = sgPost("salam_gate_post_offices_references", { fresh: fresh ? "1" : "0" })
      .done(function (res) {
        if (epoch !== referenceEpoch) return;
        if (!res || !res.success || !res.data || !validOptions(res.data.provinces)) {
          referenceFailure((res && res.data && res.data.message) || "فهرست استان‌ها دریافت نشد؛ دوباره تلاش کنید.");
          return;
        }
        provinces = res.data.provinces;
        options($province, "انتخاب استان", false);
        $.each(provinces, function (_, province) { $province.append($("<option>").val(String(province.id)).text(province.title)); });
        $referenceState.text(res.data.stale ? "فهرست ذخیره‌شده نمایش داده می‌شود؛ برای دریافت اطلاعات تازه دوباره تلاش کنید." : "");
        if (res.data.stale) { retryStage = "references"; $retry.prop("hidden", false).show().prop("disabled", false); }
        if (referencesLoading) referencesLoading.ready();
      })
      .fail(function () {
        if (epoch === referenceEpoch) {
          referenceFailure("ارتباط برای دریافت استان‌ها برقرار نشد؛ دوباره تلاش کنید.");
        }
      });
  }
  function referenceFailure(message) {
    options($province, "فهرست استان‌ها در دسترس نیست", true);
    options($location, "ابتدا استان را انتخاب کنید", true);
    $referenceState.text("دریافت فهرست استان‌ها ناموفق بود.").addClass("sg-field__state--error");
    failure("references", message);
  }
  function loadLocations(pid) {
    var epoch = ++locationEpoch;
    ++nodeEpoch;
    if (locationLoading) locationLoading.ready();
    if (nodeLoading) nodeLoading.ready();
    locationLoading = null; nodeLoading = null;
    abortRequest(locationRequest); abortRequest(nodeRequest);
    options($location, pid ? "در حال دریافت مناطق پستی…" : "ابتدا استان را انتخاب کنید", true);
    $retry.prop("hidden", true).hide();
    $locationState.text("").removeClass("sg-field__state--error sg-feedback-toast-only");
    resultMessage("برای مشاهده مراکز، منطقه پستی را انتخاب کنید.");
    if (!pid) return;
    locationLoading = window.WoosalamGateLoading && window.WoosalamGateLoading.begin($view[0], "locations", function () { loadLocations($province.val()); });
    var loading = locationLoading;
    $locationState.text("در حال دریافت مناطق پستی…");
    locationRequest = sgPost("salam_gate_provider_locations", { province_id: pid })
      .done(function (res) {
        if (epoch !== locationEpoch || String($province.val()) !== String(pid)) return;
        if (res && res.success && res.data && res.data.has_coverage === false && Array.isArray(res.data.locations) && res.data.locations.length === 0) {
          options($location, "منطقه‌ای در دسترس نیست", true);
          $locationState.text("این استان در پستِ سلام‌گیت پوشش ندارد.");
          resultMessage("برای این استان منطقه پستی ثبت نشده است.");
          if (loading) loading.ready();
          return;
        }
        if (!res || !res.success || !res.data || res.data.has_coverage !== true || !validOptions(res.data.locations)) {
          locationFailure((res && res.data && res.data.message) || "دریافت مناطق پستی ناموفق بود.");
          return;
        }
        $locationState.text("").removeClass("sg-field__state--error sg-feedback-toast-only");
        options($location, "انتخاب منطقه پستی", false);
        $.each(res.data.locations, function (_, loc) { $location.append($("<option>").val(String(loc.id)).text(loc.title)); });
        if (loading) loading.ready();
      })
      .fail(function () {
        if (epoch === locationEpoch && String($province.val()) === String(pid)) locationFailure("دریافت مناطق ناموفق بود؛ دوباره تلاش کنید.");
      });
  }
  function locationFailure(message) {
    options($location, "دریافت مناطق ناموفق بود", true);
    $locationState.text("برای دریافت مناطق پستی دوباره تلاش کنید.").addClass("sg-field__state--error");
    failure("locations", message);
  }
  function loadNodes(lid) {
    var epoch = ++nodeEpoch;
    if (nodeLoading) nodeLoading.ready();
    nodeLoading = null;
    abortRequest(nodeRequest);
    $retry.prop("hidden", true).hide();
    $view.find("[data-po-result-count]").empty();
    if (!lid || !showResults()) { resultMessage(""); return; }
    nodeLoading = window.WoosalamGateLoading && window.WoosalamGateLoading.begin($view[0], "nodes", function () { loadNodes($location.val()); });
    var loading = nodeLoading;
    $results.html("<div class='sg-settings__loading'><span class='sg-loading-spinner' aria-hidden='true'></span> در حال دریافت اطلاعات…</div>");
    nodeRequest = sgPost("salam_gate_provider_nodes", { provider_location_id: lid })
      .done(function (res) {
        if (epoch !== nodeEpoch || String($location.val()) !== String(lid)) return;
        if (res && res.success && res.data && validNodes(res.data.nodes)) {
          var nodes = res.data.nodes;
          if (!nodes.length) {
            $results.html("<p class='sg-card__detail'>مرکز تحویلی برای این منطقه ثبت نشده است.</p>");
            if (loading) loading.ready();
            return;
          }
          renderNodes(nodes);
          if (loading) loading.ready();
          return;
        }
        resultMessage("برای مشاهده مراکز، دوباره تلاش کنید.");
        failure("nodes", (res && res.data && res.data.message) || "دریافت مراکز ناموفق بود.");
      })
      .fail(function () {
        if (epoch !== nodeEpoch || String($location.val()) !== String(lid)) return;
        resultMessage("برای مشاهده مراکز، دوباره تلاش کنید.");
        failure("nodes", "ارتباط با سرور برقرار نشد.");
      });
  }
  $province.on("change", function () { loadLocations($(this).val()); });
  $location.on("change", function () { loadNodes($(this).val()); });
  $retry.on("click", function () {
    if (retryStage === "locations") loadLocations($province.val());
    else if (retryStage === "nodes") loadNodes($location.val());
    else loadReferences(true);
  });
  loadReferences(false);
});

// =====================================================================
// Salam Gate — calculator view (part 5)
// =====================================================================
jQuery(function ($) {
  var $view = $("[data-view='calculator']");
  if (!$view.length) return;

  var provinces = [];
  var $senderProvince = $view.find("[data-calc-sender-province]");
  var $receiverProvince = $view.find("[data-calc-receiver-province]");
  var $senderCity = $view.find("[data-calc-sender-city]");
  var $receiverCity = $view.find("[data-calc-receiver-city]");
  var $box = $view.find("[data-calc-box]");
  var $boxState = $view.find("[data-calc-box-state]");
  var $retry = $view.find("[data-calc-retry]");
  var loadingTicket = null;
  var referencesReady = false;
  var $submitBtn = $view.find("[data-calc-submit]");
  var $submitLabel = $view.find("[data-calc-submit-label]");
  var $spinner = $view.find("[data-calc-spinner]");
  var $result = $view.find("[data-calc-result]");
  var calculationRevision = 0;

  function sgPost(action, data) {
    var request = window.WoosalamGateRequests ? window.WoosalamGateRequests.post : $.post;
    return request(basalam_ajax.ajaxurl, $.extend({ _wpnonce: basalam_ajax.nonce, action: action }, data || {}));
  }

  function toEn(v) {
    return String(v == null ? "" : v)
      .replace(/[۰-۹]/g, function (d) { return String.fromCharCode(d.charCodeAt(0) - 0x06F0 + 48); })
      .replace(/[٠-٩]/g, function (d) { return String.fromCharCode(d.charCodeAt(0) - 0x0660 + 48); })
      .replace(/[,٬]/g, "");
  }

  function faThousands(n) {
    return Math.floor(Number(n)).toLocaleString("fa-IR", { maximumFractionDigits: 0 }).replace(/٬/g, ",");
  }

  function isRial(value) {
    // Do not coerce missing, string, boolean or unsafe API amounts into a price.
    return typeof value === "number" && Number.isSafeInteger(value);
  }

  function amountText(rial, signed) {
    var prefix = signed && rial !== 0 ? (rial < 0 ? "− " : "+ ") : "";
    return prefix + faThousands(Math.abs(rial) / 10) + " تومان";
  }

  function costRow(title, rial, kind) {
    return $("<div class='sg-calc-cost-row'>")
      .addClass(kind ? "sg-calc-cost-row--" + kind : "")
      .append($("<span class='sg-calc-cost-row__title'>").text(title),
        $("<bdi class='sg-calc-cost-row__amount'>").text(amountText(rial, kind !== "total")));
  }

  function costItems(section) {
    if (!$.isPlainObject(section) || !Array.isArray(section.items)) return [];
    return section.items.filter(function (item) {
      return $.isPlainObject(item) && isRial(item.amount)
        && typeof item.title === "string" && item.title.trim() !== "";
    });
  }

  function discountTitle(costs) {
    var percent = costs.discount_percent;
    var label = "تخفیف کرایه پستی";
    if (typeof percent === "number" && Number.isFinite(percent) && percent > 0 && percent <= 100) {
      label += " (" + percent.toLocaleString("fa-IR", { maximumFractionDigits: 3 }) + " درصد)";
    }
    return label;
  }

  function renderQuote(quote) {
    var $card = $("<section class='sg-calc-quote__item'>");
    var title = $.isPlainObject(quote) && typeof quote.service_name === "string" ? quote.service_name : "سرویس پستی";
    $card.append($("<h4 class='sg-calc-quote__service'>").text(title));
    if (!$.isPlainObject(quote) || (typeof quote.error === "string" && quote.error)) {
      return $card.addClass("sg-calc-quote__item--error")
        .append($("<p>").text(quote && typeof quote.error === "string" ? quote.error : "پاسخ این سرویس معتبر نیست؛ دوباره محاسبه کنید."));
    }
    var costs = $.isPlainObject(quote.costs) ? quote.costs : {};
    if (!isRial(costs.total) || costs.total < 0) {
      return $card.addClass("sg-calc-quote__item--error")
        .append($("<p>").text("مبلغ معتبر این سرویس دریافت نشد؛ دوباره محاسبه کنید."));
    }
    $card.append(costRow("قابل پرداخت", costs.total, "total"));
    var $breakdown = $("<div class='sg-calc-costs'>");
    if (isRial(costs.raw_cost) && costs.raw_cost >= 0) {
      $breakdown.append(costRow("کرایه پستی", costs.raw_cost, "fare"));
    }
    var discounts = costItems(costs.discount);
    if (discounts.length) {
      $.each(discounts, function (_, item) {
        var title = item.title;
        if (item.key === "provider_discount" && !/درصد|[%٪]/.test(title)) title = discountTitle(costs);
        $breakdown.append(costRow(title, item.amount, "discount"));
      });
    } else if ($.isPlainObject(costs.discount) && isRial(costs.discount.total) && costs.discount.total !== 0) {
      $breakdown.append(costRow(discountTitle(costs), costs.discount.total, "discount"));
    }
    var additional = $.isPlainObject(costs.additional) ? costs.additional : {};
    var items = costItems(additional);
    if (items.length) {
      var $details = $("<details class='sg-calc-cost-details'>");
      var $summary = $("<summary class='sg-calc-cost-row'>").append(
        $("<span class='sg-calc-cost-row__title'>").text("سایر هزینه‌های پستی")
      );
      if (isRial(additional.total)) {
        $summary.append($("<bdi class='sg-calc-cost-row__amount'>").text(amountText(additional.total, true)));
      }
      $details.append($summary);
      var $items = $("<div class='sg-calc-cost-details__items'>");
      $.each(items, function (_, item) { $items.append(costRow(item.title, item.amount, "additional")); });
      $breakdown.append($details.append($items));
    } else if (isRial(additional.total)) {
      $breakdown.append(costRow("سایر هزینه‌های پستی", additional.total, "additional"));
    }
    if ($breakdown.children().length) $card.append($breakdown);
    else $card.append($("<p class='sg-calc-quote__unavailable'>").text("ریز هزینه‌ها در پاسخ این سرویس موجود نیست."));
    return $card;
  }

  function resetCalculation() {
    calculationRevision += 1;
    $result.hide().empty().removeClass("sg-calc-result--error sg-feedback-toast-only");
    $submitBtn.prop("disabled", !referencesReady);
    $submitLabel.text("محاسبه هزینه ارسال");
    $spinner.hide();
  }

  function calculationError(message) {
    var floating = typeof window.woosalamNotify === "function";
    $result.text(message).addClass("sg-calc-result--error").toggleClass("sg-feedback-toast-only", floating).show();
    if (floating) window.woosalamNotify(message, "error");
  }

  // A response belongs only to the form values it was calculated for. Editing
  // any field hides its old price immediately, including during a slow request.
  $view.on("input change", "input, select", resetCalculation);

  function referenceError(message) {
    referencesReady = false;
    $submitBtn.prop("disabled", true);
    $retry.prop("hidden", false).prop("disabled", false);
    $boxState.addClass("sg-field__state--error").text(message)
      .toggleClass("sg-feedback-toast-only", typeof window.woosalamNotify === "function");
    if (loadingTicket) loadingTicket.fail(message);
    else if (typeof window.woosalamNotify === "function") window.woosalamNotify(message, "error");
  }

  function validReference(option) {
    return $.isPlainObject(option) && /^\d+$/.test(String(option.id))
      && Number.isSafeInteger(Number(option.id)) && Number(option.id) > 0
      && typeof option.title === "string" && option.title.trim() !== "";
  }

  function provinceById(id) {
    for (var i = 0; i < provinces.length; i += 1) {
      if (String(provinces[i].id) === String(id)) return provinces[i];
    }
    return null;
  }

  function fillCities($province, $city, side) {
    var province = provinceById($province.val());
    $city.empty().append($("<option>").val("").text(province ? "انتخاب شهر" : "ابتدا استان " + side + " را انتخاب کنید"));
    $.each(province ? province.children : [], function (_, city) {
      $city.append($("<option>").val(String(city.id)).text(city.title));
    });
    var available = !!province && province.children.length > 0;
    if (province && !available) $city.find("option").text("شهری برای این استان دریافت نشد");
    $city.prop("disabled", !available);
  }

  function selectedCityIsValid($province, $city) {
    var province = provinceById($province.val());
    if (!province || $city.prop("disabled")) return false;
    return province.children.some(function (city) { return String(city.id) === String($city.val()); });
  }

  $senderProvince.on("change", function () { fillCities($senderProvince, $senderCity, "فرستنده"); });
  $receiverProvince.on("change", function () { fillCities($receiverProvince, $receiverCity, "گیرنده"); });

  // One read-only bootstrap supplies actual city/packaging IDs. Opening this
  // estimator must never flush caches or issue an invalid provider query.
  function loadReferences() {
    loadingTicket = window.WoosalamGateLoading && window.WoosalamGateLoading.begin($view[0], "references", loadReferences);
    referencesReady = false;
    $submitBtn.prop("disabled", true);
    $retry.prop("hidden", true);
    provinces = [];
    $senderProvince.add($receiverProvince).empty().append($("<option>").val("").text("در حال دریافت استان‌ها…")).prop("disabled", true);
    fillCities($senderProvince, $senderCity, "فرستنده");
    fillCities($receiverProvince, $receiverCity, "گیرنده");
    $box.empty().append($("<option>").val("").text("در حال دریافت بسته‌بندی‌ها…")).prop("disabled", true);
    $boxState.removeClass("sg-field__state--error sg-feedback-toast-only").text("در حال دریافت اطلاعات…");
    sgPost("salam_gate_onboarding_bootstrap")
    .done(function (res) {
      if (res && res.success && res.data) {
        provinces = (Array.isArray(res.data.provinces) ? res.data.provinces : []).filter(validReference).map(function (province) {
          return { id: province.id, title: province.title, children: (Array.isArray(province.children) ? province.children : []).filter(validReference) };
        });

        $senderProvince.add($receiverProvince).empty().append($("<option>").val("").text("انتخاب استان"));
        $.each(provinces, function (_, province) {
          $senderProvince.add($receiverProvince).append($("<option>").val(String(province.id)).text(province.title));
        });
        var hasCities = provinces.some(function (province) { return province.children.length > 0; });
        $senderProvince.add($receiverProvince).prop("disabled", !hasCities);
        fillCities($senderProvince, $senderCity, "فرستنده");
        fillCities($receiverProvince, $receiverCity, "گیرنده");

        // بسته‌بندی از API زنده
        $box.empty().append($("<option>").val("").text("انتخاب بسته‌بندی"));
        $.each(res.data.box_types || [], function (i, box) {
          if (box && box.id > 0 && box.title) {
            $box.append($("<option>").val(String(box.id)).text(box.title));
          }
        });
        var hasBoxes = $box.find("option[value!='']").length > 0;
        $box.prop("disabled", !hasBoxes);
        if (!hasCities || !hasBoxes) {
          referenceError(!hasBoxes ? "نوع بسته‌بندی دریافت نشد؛ دریافت دوباره اطلاعات را بزنید." : "شهرها دریافت نشدند؛ دریافت دوباره اطلاعات را بزنید.");
          return;
        }
        referencesReady = true;
        $boxState.text("");
        $submitBtn.prop("disabled", false);
        if (loadingTicket) loadingTicket.ready();
        return;
      }
      referenceError((res && res.data && res.data.message) || "دریافت اطلاعات ناموفق بود؛ دوباره تلاش کنید.");
    })
    .fail(function () { referenceError("ارتباط با سرور برقرار نشد؛ دوباره تلاش کنید."); });
  }
  $retry.on("click", loadReferences);
  loadReferences();

  $submitBtn.on("click", function () {
    if (!referencesReady || $submitBtn.prop("disabled")) return;
    var sender = $senderCity.val();
    var receiver = $receiverCity.val();
    var weightText = toEn($view.find("#calc-weight").val() || "");
    var weight = /^\d+$/.test(weightText) ? Number(weightText) : NaN;
    var valueText = toEn($view.find("#calc-value").val() || "");
    var valueToman = /^\d+$/.test(valueText) ? Number(valueText) : NaN;
    var boxType = $box.val();
    var serviceType = $view.find("[data-calc-service]").val();

    $result.hide().empty().removeClass("sg-feedback-toast-only");

    if (window.SalamGateValidation && !window.SalamGateValidation.validate($view)) return;
    if (!$senderProvince.val() || !$receiverProvince.val()) { calculationError("استان فرستنده و گیرنده را انتخاب کنید."); return; }
    if (!selectedCityIsValid($senderProvince, $senderCity) || !selectedCityIsValid($receiverProvince, $receiverCity)) { calculationError("شهر فرستنده و گیرنده را از استان انتخاب‌شده مشخص کنید."); return; }
    if (!Number.isSafeInteger(weight) || weight < 50 || weight > 30000) { calculationError("وزن مرسوله را به گرم و عدد صحیح، بین ۵۰ تا ۳۰٬۰۰۰ گرم وارد کنید."); return; }
    if (!Number.isSafeInteger(valueToman) || valueToman < 2000 || valueToman > 214748364) { calculationError("ارزش مرسوله را به تومان و عدد صحیح، بین ۲٬۰۰۰ تا ۲۱۴٬۷۴۸٬۳۶۴ تومان وارد کنید."); return; }
    if (!boxType) { calculationError("نوع بسته‌بندی را انتخاب کنید."); return; }

    $submitBtn.prop("disabled", true);
    $submitLabel.text("در حال محاسبه…");
    $spinner.show();
    var revision = ++calculationRevision;

    sgPost("salam_gate_calculate", {
      sender_city_id: sender,
      receiver_city_id: receiver,
      weight: String(weight),
      value_toman: String(valueToman),
      box_type_id: boxType,
      service_type: serviceType || ""
    })
      .done(function (res) {
        if (revision !== calculationRevision) return;
        if (res && res.success === true && $.isPlainObject(res.data) && Array.isArray(res.data.quotes)) {
          var quotes = res.data.quotes;
          if (!quotes.length) {
            $result.removeClass("sg-calc-result--error").addClass("sg-calc-result")
              .text("برای این مسیر/وزن نرخی در دسترس نیست (نبود پوشش).").show();
            return;
          }
          var $grid = $("<div class='sg-calc-quote'>");
          $.each(quotes, function (i, q) {
            $grid.append(renderQuote(q));
          });
          var $unavailable = $grid.find(".sg-calc-quote__item--error");
          if (typeof window.woosalamNotify === "function") {
            if ($unavailable.length) {
              var messages = $unavailable.find("p").map(function () { return $(this).text(); }).get().filter(function (value, index, all) { return all.indexOf(value) === index; });
              window.woosalamNotify(messages.join(" "), "error");
              $unavailable.find("p").addClass("sg-feedback-toast-only");
              $unavailable.append($("<span class='sg-calc-quote__unavailable'>").text("نرخ این سرویس در دسترس نیست."));
            } else window.woosalamNotify("هزینه ارسال محاسبه شد.", "success");
          }
          $result.removeClass("sg-calc-result--error").empty()
            .append($("<p class='sg-calc-disclaimer'>").text("نتیجه برآوردی است؛ هزینه نهایی هنگام ثبت مرسوله محاسبه می‌شود."))
            .append($grid)
            .show();
          return;
        }
        var message = (res && res.data && res.data.message) || "محاسبه ناموفق بود.";
        calculationError(message);
      })
      .fail(function () {
        if (revision !== calculationRevision) return;
        calculationError("ارتباط با سرور برقرار نشد.");
      })
      .always(function () {
        if (revision !== calculationRevision) return;
        $submitBtn.prop("disabled", !referencesReady);
        $submitLabel.text("محاسبه هزینه ارسال");
        $spinner.hide();
      });
  });
});

// =====================================================================
// Salam Gate — standalone send page (part 6)
// =====================================================================
jQuery(function ($) {
  var $view = $("[data-view='send']");
  if (!$view.length) return;

  var data = { shops: [], categories: [], boxTypes: [], provinces: [], markets: [] };
  var selectedMarket = "";
  var announcedBasalamSource = null;
  var announcedOrderSource = null;
  var receiverData = null;
  var submitting = false;
  var bootstrapReady = false;
  var bootstrapRequest = null;
  var bootstrapEdits = { form: {}, receiver: {}, items: null, market: null };
  var bootstrapDraftRestored = false;
  var creationUnresolved = false;
  var draftFrozen = false;
  var draftStore = window.WoosalamSendDraft;
  var initialDraft = draftStore ? draftStore.load() : null;
  var creationAttempt = draftStore && draftStore.loadCreation ? draftStore.loadCreation() : null;
  var websiteEntryMode = (initialDraft && initialDraft.website_entry_mode === "manual")
    || (initialDraft && initialDraft.market === "website" && initialDraft.parcel)
    || (creationAttempt && creationAttempt.market === "website") ? "manual" : "orders";
  var creationStatusBusy = false;
  var sendStage = 1;
  var parcelMarket = "";
  var informationDirty = false;
  var informationFingerprint = "";
  var requireQuoteSelection = false;
  var adminUrlBase = basalam_ajax.ajaxurl.replace("admin-ajax.php", "");

  var $form = $view.find("[data-sg-send-form]");
  var $notice = $view.find("[data-send-notice]");
  var $quotesDiv = $view.find("[data-send-quotes]");
  var $submitBtn = $view.find("[data-send-submit]");

  function sgPost(action, postData) {
    var request = window.WoosalamGateRequests ? window.WoosalamGateRequests.post : $.post;
    return request(basalam_ajax.ajaxurl, $.extend({ _wpnonce: basalam_ajax.nonce, action: action }, postData || {}));
  }

  // Resolve the durable submission identity silently. Page loading is not a
  // user action to announce, and an unknown result must never start a new create.
  function checkCreationStatus() {
    if (creationStatusBusy || !creationAttempt || creationAttempt.invalid) return;
    creationStatusBusy = true;
    var recoveryLoading = window.WoosalamGateLoading && window.WoosalamGateLoading.begin($view[0], "recovery", checkCreationStatus);
    sgPost("salam_gate_send_creation_status", { creation_id: creationAttempt.id })
      .done(function (res) {
        var result = (res && res.data) || {};
        if (res && res.success && result.creation_id === creationAttempt.id
          && result.creation_state === "created" && result.market === creationAttempt.market
          && Number.isSafeInteger(result.parcel_id) && result.parcel_id > 0) {
          if (!draftStore.markCreation(creationAttempt.id, "created", result.parcel_id)) return;
          creationAttempt = draftStore.loadCreation();
          if (!creationAttempt || creationAttempt.invalid || creationAttempt.state !== "created") return;
          if (initialDraft && initialDraft.creation_unresolved && !initialDraft.parcel) {
            // Section navigation still has input. Recover only this exact prior submission.
            initialDraft.parcel = { id: result.parcel_id, market: result.market };
            initialDraft.creation_unresolved = false;
            if (bootstrapReady) restoreInitialDraft();
          } else {
            // Reload has cleared the previous recipient/input. Only a server-
            // confirmed identity may be retired; creating from this fresh form
            // still requires complete information and an explicit submission.
            if (!draftStore.clearCreation(creationAttempt.id)) return;
            creationAttempt = null;
            creationUnresolved = false;
            if (!initialDraft) {
              selectedMarket = "";
              renderMarkets();
            }
            unlockParcelInputs();
            goToSendStage(1, false);
            saveDraft();
          }
        } else {
          if (draftStore && creationAttempt) draftStore.markCreation(creationAttempt.id, "unknown");
        }
      })
      .fail(function () {
        if (draftStore && creationAttempt) draftStore.markCreation(creationAttempt.id, "unknown");
      })
      .always(function () {
        // Keep an unresolved result locked without an endless loading overlay.
        if (recoveryLoading) recoveryLoading.ready();
        creationStatusBusy = false;
        updateSendStage();
      });
  }

  function toEn(v) {
    return String(v == null ? "" : v)
      .replace(/[۰-۹]/g, function (d) { return String.fromCharCode(d.charCodeAt(0) - 0x06F0 + 48); })
      .replace(/[٠-٩]/g, function (d) { return String.fromCharCode(d.charCodeAt(0) - 0x0660 + 48); })
      .replace(/[,٬]/g, "");
  }

  function faNum(n) { return String(n).replace(/\d/g, function (d) { return "۰۱۲۳۴۵۶۷۸۹"[+d]; }); }
  function faThousands(n) { return Math.floor(Number(n)).toLocaleString("fa-IR", { maximumFractionDigits: 0 }).replace(/٬/g, ","); }

  // Completion feedback is announced once at the operation boundary. Loading
  // and instructions remain inline; recovery controls stay available below.
  function operationFeedback($target, message, kind) {
    var toast = typeof window.woosalamNotify === "function";
    var actions = $target.find("a, button").length > 0;
    $target.removeClass("sg-feedback-actions sg-feedback-toast-only");
    if (kind && toast) {
      $target.addClass(actions ? "sg-feedback-actions" : "sg-feedback-toast-only").attr("role", "group");
      window.woosalamNotify(message, kind);
    } else $target.attr("role", kind === "error" ? "alert" : "status");
  }

  function confirmationFeedback(message, kind, announce) {
    var $target = $view.find("[data-send-confirm-notice]").empty().show();
    $target.append($("<p>").text(message));
    if (kind) $target.append($("<a class='sg-button sg-button--secondary'>")
      .attr("href", adminUrlBase + "admin.php?page=salam_gate_parcels").text("بررسی در فهرست مرسوله‌ها"));
    if (announce === false && typeof window.woosalamNotify === "function") {
      $target.removeClass("sg-feedback-actions sg-feedback-toast-only")
        .addClass(kind ? "sg-feedback-actions" : "sg-feedback-toast-only").attr("role", "group");
    } else operationFeedback($target, message, kind);
  }

  function notice(text, ok) {
    var kind = ok === "warning" ? "warning" : (ok ? "success" : "error");
    if (typeof window.woosalamNotify === "function") {
      $notice.hide().empty();
      window.woosalamNotify(text, kind);
      return;
    }
    $notice.text(text || "").toggleClass("sg-wizard__notice--ok", kind === "success")
      .toggleClass("sg-wizard__notice--warning", kind === "warning").attr("role", kind === "error" ? "alert" : "status").show();
  }

  function sendMarketLabel(market) {
    return { basalam: "باسلام", website: "ووکامرس", instagram: "اینستاگرام", telegram: "تلگرام", eitaa: "ایتا",
      bale: "بله", digikala: "دیجی‌کالا", mixin: "میکسین", other: "سایر" }[market] || "سایر بسترها";
  }

  function senderPresentation(shop) {
    var address = shop.address || {};
    var identity = String(shop.identifier || shop.id || "");
    var name = typeof address.full_name === "string" && address.full_name.trim()
      ? address.full_name.trim() : "فرستنده ثبت‌شده";
    var city = address.city && typeof address.city.title === "string" ? address.city.title : "";
    var center = shop.provider_node && typeof shop.provider_node.title === "string" ? shop.provider_node.title : "";
    var parts = [name];
    if (city) parts.push(city);
    if (center) parts.push("مرکز تحویل: " + center);
    return { name: name, identity: identity, city: city, center: center,
      address: typeof address.address === "string" ? address.address : "", label: parts.join(" — ") };
  }

  function navigationLocked() {
    return submitting || creationUnresolved || quoteBusy || paying || paymentDone || paymentUnresolved;
  }

  function canReview() {
    return !orderPickerMarket() && lastParcelId && !informationDirty && !requireQuoteSelection && validQuote(selectedQuote)
      && (selectedPayMethod === 20 || selectedPayMethod === 21)
      && (!window.SalamGatePaymentPresentation || window.SalamGatePaymentPresentation.isMethodAvailable(selectedPayMethod))
      && !quoteBusy && !submitting
      && !creationUnresolved && !paymentDone && !paymentUnresolved;
  }

  function hasWebsiteSubmission() {
    return !!((lastParcelId && parcelMarket === "website")
      || (creationAttempt && creationAttempt.market === "website")
      || (initialDraft && initialDraft.market === "website" && initialDraft.parcel));
  }

  function orderPickerMarket() {
    if (selectedMarket === "basalam") return "basalam";
    // Manual website parcels use the existing durable direct-send identity;
    // choosing a Woo order continues through the separate, locked-items flow.
    return selectedMarket === "website" && websiteEntryMode !== "manual" && !hasWebsiteSubmission() ? "website" : "";
  }

  function updateSendStage() {
    var pickerMarket = orderPickerMarket();
    var orderSource = !!pickerMarket;
    var basalamSource = pickerMarket === "basalam";
    var $websiteModes = $view.find("[data-send-website-modes]");
    $websiteModes.prop("hidden", selectedMarket !== "website").toggle(selectedMarket === "website");
    var manualWebsite = websiteEntryMode === "manual" || hasWebsiteSubmission();
    $websiteModes.find("[data-send-website-mode]").each(function () {
      var selected = $(this).attr("data-send-website-mode") === (manualWebsite ? "manual" : "orders");
      $(this).attr("aria-pressed", String(selected)).toggleClass("is-selected", selected)
        .prop("disabled", navigationLocked() || sendStage !== 1 || hasWebsiteSubmission());
    });
    if (orderSource) sendStage = 1;
    $view.attr("data-send-order-source", pickerMarket).attr("data-send-current-stage", sendStage);
    $view.find("[data-send-direct-fields]").prop("hidden", orderSource).toggle(!orderSource);
    $view.find(".sg-send__steps").prop("hidden", orderSource).toggle(!orderSource);
    $view.find("[data-send-main-action]").toggle(!orderSource && sendStage === 1);
    if (!orderSource) $view.find("[data-sg-menu-order-host]").prop("hidden", true).hide();
    if (announcedBasalamSource !== basalamSource) {
      announcedBasalamSource = basalamSource;
      $(document).trigger("salamgate:basalam-source", [basalamSource]);
    }
    if (announcedOrderSource !== pickerMarket) {
      announcedOrderSource = pickerMarket;
      $view.find("[data-sg-menu-order-host]").prop("hidden", true).hide();
      $(document).trigger("salamgate:order-source", [pickerMarket]);
    }
    $submitBtn.prop("disabled", orderSource || !bootstrapReady || navigationLocked());
    $view.find("[data-send-stage-panel]").each(function () {
      var shown = Number($(this).attr("data-send-stage-panel")) === sendStage;
      $(this).prop("hidden", !shown).toggle(shown);
    });
    $view.find("[data-send-stage]").each(function () {
      var number = Number($(this).attr("data-send-stage"));
      var active = number === sendStage;
      var accessible = number === 1 || (number === 2 && lastParcelId && !informationDirty) || (number === 3 && canReview());
      $(this).toggleClass("is-active", active).toggleClass("is-done", number < sendStage || paymentDone);
      $(this).find("[data-send-go-stage]").prop("disabled", navigationLocked() || !accessible)
        .attr("aria-disabled", navigationLocked() || !accessible ? "true" : "false");
      if (active) $(this).add($(this).find("[data-send-go-stage]")).attr("aria-current", "step");
      else $(this).add($(this).find("[data-send-go-stage]")).removeAttr("aria-current");
    });
    $view.find("[data-send-review-next]").prop("disabled", !canReview() || navigationLocked());
    $view.find("[data-send-back-to-information]").prop("disabled", navigationLocked());
    $view.find(".sg-send__payment-actions, .sg-send__payment-help, [data-send-review]").toggle(!paymentDone && !paymentUnresolved);
    $view.find("[data-send-finance-recovery]").toggle(sendStage === 3 && (paymentDone || paymentUnresolved) && !$view.find("[data-send-result]").children().length);
    $view.find("[data-send-recheck-payment]").toggle(paymentUnresolved && !paymentDone)
      .prop("disabled", submitting || paying || quoteBusy || creationUnresolved);
    $view.find("[data-send-edit-notice]").toggle(sendStage === 1 && informationDirty);
    syncMarketChoices(false);
  }

  function goToSendStage(stage, focus) {
    sendStage = stage;
    if (stage === 1) {
      if (!navigationLocked()) unlockParcelInputs();
      $view.find("[data-send-main-action]").show();
      $submitBtn.prop("disabled", !bootstrapReady || navigationLocked()).text("مرحله بعد");
    }
    renderConfirm();
    updateSendStage();
    if (focus) $view.find("[data-send-stage-panel='" + stage + "']").find("h2, h3, h4").first().attr("tabindex", "-1").trigger("focus");
  }

  $view.on("click", "[data-send-go-stage]", function () {
    if (navigationLocked()) return;
    var stage = Number($(this).attr("data-send-go-stage"));
    if (stage === 1 || (stage === 2 && lastParcelId && !informationDirty) || (stage === 3 && canReview())) goToSendStage(stage, true);
  });
  $view.on("click", "[data-send-review-next]", function () {
    if (!navigationLocked() && canReview()) goToSendStage(3, true);
  });
  $view.on("click", "[data-send-back-to-information]", function () {
    if (!navigationLocked() && sendStage === 2) goToSendStage(1, true);
  });

  function fingerprintInformation() {
    var fields = [];
    $form.find("input, select, textarea").each(function () {
      if (!this.name) return;
      fields.push([this.name, this.type === "checkbox" || this.type === "radio" ? this.checked : $(this).val()]);
    });
    var items = $view.find("[data-send-items] tr").map(function () {
      var $row = $(this);
      return [[
        $row.find(".sg-send-item-title").val() || "",
        toEn($row.find(".sg-send-item-qty").val()),
        toEn($row.find(".sg-send-item-weight").val()),
        toEn($row.find(".sg-send-item-price").val())
      ]];
    }).get();
    return JSON.stringify([selectedMarket, fields, receiverData, items]);
  }

  function informationChanged() {
    if (orderPickerMarket() || !bootstrapReady || navigationLocked() || sendStage !== 1) return;
    updateItemPlaceholderRequirements();
    var fingerprint = fingerprintInformation();
    if (fingerprint === informationFingerprint) return;
    informationFingerprint = fingerprint;
    if (!lastParcelId) return;
    var firstChange = !informationDirty;
    informationDirty = true;
    clearPaymentFeedback();
    requireQuoteSelection = true;
    quoteSequence += 1;
    selectedQuote = null;
    selectedPayMethod = 0;
    $quotesDiv.hide().empty();
    $view.find("[data-send-confirm], [data-send-pay-method], [data-send-quote-notice], [data-send-pay-status]").hide();
    $view.find("[data-send-pay-methods], [data-send-review]").empty();
    if (firstChange) notice("اطلاعات تغییر کرد؛ با ذخیره تغییرات، روش ارسال و پرداخت را دوباره انتخاب کنید.", "warning");
    goToSendStage(1, false);
    saveDraft();
  }

  // ---------- مبدأ سفارش ----------
  function syncMarketChoices() {
    var $wrap = $view.find("[data-send-markets]");
    var locked = navigationLocked() || sendStage !== 1;
    $wrap.find("input[name='market']").each(function () {
      var selected = this.value === selectedMarket;
      $(this).prop("checked", selected).prop("disabled", locked)
        .closest("label").toggleClass("is-selected", selected).toggleClass("is-disabled", locked);
    });
  }

  function renderMarkets() {
    var $wrap = $view.find("[data-send-markets]").empty();
    // A saved draft or unresolved submission retains its actual API identity.
    if (!selectedMarket) {
      var previousMarket = initialDraft && initialDraft.market;
      if (!previousMarket && creationAttempt && !creationAttempt.invalid) previousMarket = creationAttempt.market;
      if (data.markets.indexOf(previousMarket) !== -1) selectedMarket = previousMarket;
      else if (data.markets.indexOf("website") !== -1) selectedMarket = "website";
    }
    var markets = ["website", "basalam"].filter(function (market) { return data.markets.indexOf(market) !== -1; })
      .concat(data.markets.filter(function (market) { return market !== "website" && market !== "basalam"; }));
    $.each(markets, function (i, market) {
      $("<label class='sg-radio sg-radio--market'>").append(
        $("<input>").attr({ type: "radio", name: "market", value: market, "aria-describedby": "sg-send-market-error" }),
        $("<span class='sg-send__market-name'>").text(sendMarketLabel(market))
      ).appendTo($wrap);
    });
    syncMarketChoices();
  }

  $form.on("change", "input[name='market']", function () {
    var requestedMarket = $(this).val();
    if (navigationLocked()) {
      $form.find("input[name='market']").prop("checked", false).filter(function () { return $(this).val() === selectedMarket; }).prop("checked", true);
      return;
    }
    // Preserve the independent direct-order draft, never overwrite it with a
    // Basalam customer or replay a direct create using the Basalam identity.
    if ((requestedMarket === "basalam" || requestedMarket === "website") && !orderPickerMarket()) saveDraft();
    selectedMarket = $(this).val();
    $view.find("[data-error-for='market']").text("").hide();
    $form.find("input[name='market']").removeAttr("aria-invalid").removeClass("sg-field__input--invalid");
    updateSendStage();
  });

  $view.on("click", "[data-send-website-mode]", function () {
    var mode = $(this).attr("data-send-website-mode");
    if (selectedMarket !== "website" || ["manual", "orders"].indexOf(mode) === -1
      || navigationLocked() || sendStage !== 1 || hasWebsiteSubmission()) return;
    websiteEntryMode = mode;
    updateSendStage();
    saveDraft();
  });

  // Recipient fields stay inline; the legacy draft buffer key is preserved.
  var $receiverFields = $view.find("[data-send-receiver-fields]");

  function fillReceiverProvinces() {
    var $p = $receiverFields.find("#src-province").empty().append($("<option>").val("").text("انتخاب استان"));
    $.each(data.provinces, function (i, prov) { $p.append($("<option>").val(prov.id).text(prov.title)); });
  }

  function fillReceiverCities(provinceId, cityId) {
    var $c = $receiverFields.find("#src-city").empty().append($("<option>").val("").text("انتخاب شهر"));
    $.each(data.provinces, function (i, prov) {
      if (String(prov.id) === String(provinceId)) {
        $.each(prov.children || [], function (j, city) { $c.append($("<option>").val(city.id).text(city.title)); });
      }
    });
    if (cityId) $c.val(cityId);
    $c.prop("disabled", !provinceId || navigationLocked());
  }

  function readReceiverFields() {
    var r = captureReceiverModal();
    r.name = $.trim(r.name);
    r.mobile = toEn(r.mobile);
    r.address = $.trim(r.address);
    r.postal_code = toEn(r.postal_code).replace(/\D/g, "");
    r.province_title = "";
    r.city_title = "";
    $.each(data.provinces, function (i, prov) {
      if (String(prov.id) === String(r.province_id)) {
        r.province_title = prov.title;
        $.each(prov.children || [], function (j, city) {
          if (String(city.id) === String(r.city_id)) r.city_title = city.title;
        });
      }
    });
    return r;
  }

  function validateReceiver() {
    receiverData = readReceiverFields();
    clearReceiverErrors();
    var r = receiverData;
    var problems = [];
    function receiverError(field, message) {
      problems.push(message);
      $receiverFields.find("[data-receiver-error-for='" + field + "']").text(message).show();
      $receiverFields.find("#src-" + field).attr("aria-invalid", "true").addClass("sg-field__input--invalid");
    }
    if (r.name.length < 3) receiverError("name", "نام و نام خانوادگی را با حداقل ۳ حرف وارد کنید.");
    if (!r.province_id) receiverError("province", "استان گیرنده را انتخاب کنید.");
    if (!r.city_id || !r.city_title) receiverError("city", "شهر گیرنده را انتخاب کنید.");
    if (r.address.length < 10) receiverError("address", "نشانی کامل را با حداقل ۱۰ حرف وارد کنید.");
    if (!/^\d{10}$/.test(r.postal_code)) receiverError("postal", "کد پستی باید ۱۰ رقم باشد.");
    if (!/^09\d{9}$/.test(r.mobile)) receiverError("mobile", "شماره موبایل ۱۱ رقمی با ۰۹ شروع می‌شود.");
    if (problems.length) {
      $view.find("[data-error-for='receiver']").text("اطلاعات گیرنده را کامل کنید.").show();
      $receiverFields.find("[aria-invalid='true']").first().trigger("focus");
      notice(problems[0], false);
      return false;
    }
    $view.find("[data-error-for='receiver']").text("").hide();
    return true;
  }

  $receiverFields.on("change", "#src-province", function () {
    if (navigationLocked() || sendStage !== 1) return;
    // Changing province invalidates the old city rather than silently retaining it.
    fillReceiverCities($(this).val(), "");
  });
  $receiverFields.on("input change", "input, select, textarea", function () {
    if (navigationLocked() || sendStage !== 1) return;
    receiverData = readReceiverFields();
    $receiverFields.find("[data-addr-count]").text(faNum($receiverFields.find("#src-address").val().length));
    var field = this.id.replace("src-", "");
    $(this).removeAttr("aria-invalid").removeClass("sg-field__input--invalid");
    $receiverFields.find("[data-receiver-error-for='" + field + "']").text("").hide();
    informationChanged();
  });

  // ---------- فرستنده ----------
  function renderSenders() {
    var shop = data.shops.filter(function (item) { return item.address && item.address.is_default === true; })[0] || data.shops[0];
    $view.find("[data-send-sender]").val(shop ? String(shop.id) : "");
    renderSenderSummary();
  }

  function renderSenderSummary() {
    var selectedId = String($view.find("[data-send-sender]").val() || "");
    var shop = data.shops.filter(function (item) { return String(item.id) === selectedId; })[0];
    var $summary = $view.find("[data-send-sender-summary]").empty();
    if (!shop) { $summary.append($("<p class='sg-field__hint'>").text("مشخصات فرستنده فعال در دسترس نیست؛ اتصال حساب را در تنظیمات بررسی کنید.")); return; }
    var info = senderPresentation(shop);
    $summary.append($("<strong>").text(info.name));
    if (info.city) $summary.append($("<p class='sg-card__detail'>").text("شهر: " + info.city));
    if (info.center) $summary.append($("<p class='sg-card__detail'>").text("مرکز تحویل: " + info.center));
    if (info.address) $summary.append($("<p class='sg-card__detail'>").text(info.address));
  }
  $form.on("change", "[data-send-sender]", renderSenderSummary);

  // ---------- بسته‌بندی ----------
  function renderBoxTypes() {
    var $sel = $view.find("[data-send-box]").empty().append($("<option>").val("").text("انتخاب بسته‌بندی"));
    $.each(data.boxTypes, function (i, box) {
      $sel.append($("<option>").val(box.id).text(box.title));
    });
  }

  $form.on("change", "[data-send-box]", function () {
    var is37 = $(this).val() === "37";
    $form.find("[data-send-out-of-size]").prop("checked", is37);
    if (!is37 && $(this).val()) $(this).data("sg-normal-box", $(this).val());
    $view.find("[data-send-dims]").toggle(is37);
    if (!is37) {
      $form.find("[name='dimension_length'], [name='dimension_width'], [name='dimension_height']").val("");
    }
  });
  $form.on("change", "[data-send-out-of-size]", function () {
    var $box = $view.find("[data-send-box]");
    if ($(this).is(":checked")) {
      if ($box.val() !== "37") $box.data("sg-normal-box", $box.val());
      $box.val("37");
    } else $box.val($box.data("sg-normal-box") || "");
    $box.trigger("change");
  });

  // ---------- دسته‌بندی ----------
  function renderCategories() {
    var $sel = $view.find("[data-send-category]").empty().append($("<option>").val("").text("انتخاب نوع مرسوله"));
    $.each(data.categories, function (i, cat) {
      $sel.append($("<option>").val(cat.id).text(cat.title));
    });
  }

  // ---------- اقلام ----------
  var itemRowSequence = 0;
  function itemCell(label, key, type, minimum, value, placeholder) {
    var errorId = "sg-send-item-" + itemRowSequence + "-" + key + "-error";
    var $input = $("<input>").attr({ type: type, class: "sg-send-item-" + key,
      "aria-label": label, "aria-describedby": errorId, "data-sg-validate": "", "data-sg-label": label });
    if (minimum !== null) $input.attr("min", minimum);
    if (type === "number") $input.attr({ step: "1", "data-sg-integer": "1" });
    if (key === "qty" || key === "weight") $input.attr("max", "2147483647");
    if (key !== "price") $input.prop("required", true);
    if (key === "weight") $input.attr({ "data-sg-label": "وزن خالص", "data-sg-unit": "گرم" });
    if (key === "price") $input.attr({ "data-sg-label": "قیمت قلم", "data-sg-unit": "تومان" });
    if (placeholder) $input.attr("placeholder", placeholder);
    return $("<td>").attr("data-label", label).append($input.val(value == null ? "" : value),
      $("<p class='sg-field__error'>").attr({ id: errorId, "data-send-item-error": key }));
  }
  function addItemRow(title, qty, netWeight, priceToman) {
    var $tbody = $view.find("[data-send-items]");
    itemRowSequence += 1;
    var $row = $("<tr>").append(
      itemCell("عنوان", "title", "text", null, title || "", "عنوان قلم"),
      itemCell("تعداد", "qty", "number", "1", qty == null ? 1 : qty),
      itemCell("وزن خالص (گرم)", "weight", "number", "1", netWeight),
      itemCell("قیمت (تومان)", "price", "number", "1000", priceToman),
      $("<td data-label='عملیات'>").append($("<button>").attr({ type: "button", class: "button button-small", "aria-label": "حذف قلم" }).text("✕").on("click", function () { if (navigationLocked() || sendStage !== 1) return;
        var title = $.trim($row.find(".sg-send-item-title").val() || "");
        var opener = this;
        var $dialog = $view.find("[data-send-remove-modal]");
        $dialog.find("[data-send-remove-message]").text(title ? "قلم «" + title + "» از مرسوله حذف شود؟" : "این قلم از مرسوله حذف شود؟");
        $dialog.find("[data-send-remove-hint]").text($row[0] === $tbody.children("tr").first()[0]
          ? "اطلاعات این قلم پاک می‌شود و سطر برای ورود قلم جدید باقی می‌ماند."
          : "این قلم از اطلاعات مرسوله حذف می‌شود.");
        pendingItemRemoval = { row: $row, opener: opener };
        $dialog.show(); }))
    );
    $tbody.append($row);
    updateItemPlaceholderRequirements();
  }


  var pendingItemRemoval = null;
  function closeItemRemoval() {
    $view.find("[data-send-remove-modal]").hide();
    pendingItemRemoval = null;
  }
  $view.on("click", "[data-send-remove-modal] [data-close-modal]", closeItemRemoval);
  $view.on("click", "[data-send-remove-modal]", function (event) {
    if (event.target === this) closeItemRemoval();
  });
  $view.on("click", "[data-send-remove-confirm]", function () {
    var pending = pendingItemRemoval;
    closeItemRemoval();
    if (!pending || navigationLocked() || sendStage !== 1 || !pending.row[0].isConnected) return;
    var firstRow = $view.find("[data-send-items] tr").first()[0] === pending.row[0];
    var $next;
    if (firstRow) {
      // The first row is the permanent entry slot. Reset its values in place
      // so product-search/accessibility hooks survive and a new item can be
      // entered immediately without adding a replacement row.
      pending.row.find("input").val("").removeAttr("aria-invalid").removeClass("sg-field__input--invalid");
      pending.row.find(".sg-send-item-qty").val(1);
      pending.row.find("[data-send-item-error]").empty().hide();
      $next = pending.row.find(".sg-send-item-title");
      updateItemPlaceholderRequirements();
      $next.trigger("input");
      if (window.SalamGateValidation) window.SalamGateValidation.reset(pending.row);
    } else {
      $next = pending.row.next().find(".sg-send-item-title");
      if (!$next.length) $next = pending.row.prev().find(".sg-send-item-title");
      pending.row.remove();
    }
    informationChanged();
    rememberBootstrapItems();
    saveDraft();
    ($next.length ? $next : $view.find("[data-send-add-item]")).trigger("focus");
  });

  function emptyFirstItemRow($row) {
    return $row[0] === $view.find("[data-send-items] tr").first()[0]
      && !$.trim($row.find(".sg-send-item-title").val() || "")
      && toEn($row.find(".sg-send-item-qty").val()) === "1"
      && !toEn($row.find(".sg-send-item-weight").val())
      && !toEn($row.find(".sg-send-item-price").val());
  }

  function updateItemPlaceholderRequirements() {
    var $first = $view.find("[data-send-items] tr").first();
    // An untouched/cleared entry slot does not prevent sending the populated
    // items below it. Partial input must still be completed or cleared.
    var placeholder = $first.length && emptyFirstItemRow($first) && collectItems().length > 0;
    $first.find(".sg-send-item-title, .sg-send-item-qty, .sg-send-item-weight").prop("required", !placeholder);
  }

  $view.find("[data-send-add-item]").on("click", function () {
    if (navigationLocked() || sendStage !== 1) return;
    addItemRow("", 1, "", "");
    informationChanged();
    rememberBootstrapItems();
    saveDraft();
  });

  function collectItems() {
    var items = [];
    $view.find("[data-send-items] tr").each(function () {
      var title = $.trim($(this).find(".sg-send-item-title").val() || "");
      var qty = parseInt(toEn($(this).find(".sg-send-item-qty").val() || "1"), 10);
      var netW = parseInt(toEn($(this).find(".sg-send-item-weight").val() || "0"), 10);
      var priceText = toEn($(this).find(".sg-send-item-price").val() || "");
      var price = priceText === "" ? "" : Number(priceText);
      if (title && qty >= 1 && netW >= 1) {
        items.push({ title: title, quantity: qty, net_weight: netW, price_toman: price });
      }
    });
    return items;
  }

  // Only this tab's short-lived draft is saved; no OAuth/payment credential is stored.
  function captureReceiverModal() {
    return {
      name: $receiverFields.find("#src-name").val() || "",
      mobile: $receiverFields.find("#src-mobile").val() || "",
      address: $receiverFields.find("#src-address").val() || "",
      postal_code: $receiverFields.find("#src-postal").val() || "",
      city_id: $receiverFields.find("#src-city").val() || "",
      province_id: $receiverFields.find("#src-province").val() || ""
    };
  }

  function captureDraftItems() {
    return $view.find("[data-send-items] tr").map(function () {
      return { title: $(this).find(".sg-send-item-title").val() || "",
        quantity: $(this).find(".sg-send-item-qty").val() || "",
        net_weight: $(this).find(".sg-send-item-weight").val() || "",
        price_toman: $(this).find(".sg-send-item-price").val() || "" };
    }).get();
  }

  function rememberBootstrapItems() {
    if (!bootstrapReady) bootstrapEdits.items = captureDraftItems();
  }

  function restoreInitialDraft() {
    if (!initialDraft) return;
    // A slow reference lookup must not drop the identity of a previously saved
    // parcel merely because the user typed a field while waiting. Merge only
    // touched input; untouched receiver/item data still comes from that draft.
    var restored = $.extend(true, {}, initialDraft);
    var changed = Object.keys(bootstrapEdits.form).length > 0 || Object.keys(bootstrapEdits.receiver).length > 0
      || bootstrapEdits.items !== null || bootstrapEdits.market !== null;
    restored.form = $.extend({}, restored.form || {}, bootstrapEdits.form);
    restored.receiverModal = $.extend({}, restored.receiverModal || restored.receiver || {}, bootstrapEdits.receiver);
    if (bootstrapEdits.items !== null) restored.items = bootstrapEdits.items;
    if (bootstrapEdits.market !== null) restored.market = bootstrapEdits.market;
    if (restored.parcel && changed) restored.information_dirty = true;
    initialDraft = restored;
    bootstrapEdits = { form: {}, receiver: {}, items: null, market: null };
    restoreDraft(restored);
  }

  function saveDraft() {
    if ((orderPickerMarket() && selectedMarket !== "website") || !draftStore || !bootstrapReady || draftFrozen) return;
    var fields = {};
    $.each(["provider_shop_id", "weight", "value_toman", "box_type_id", "category_id", "dimension_length", "dimension_width", "dimension_height"], function (_, name) {
      fields[name] = $form.find('[name="' + name + '"]').val() || "";
    });
    fields.is_liquid = $form.find("[name='is_liquid']").is(":checked");
    fields.is_fragile = $form.find("[name='is_fragile']").is(":checked");
    draftStore.save({ market: selectedMarket, website_entry_mode: websiteEntryMode, form: fields, receiver: receiverData,
      receiverModal: captureReceiverModal(), items: captureDraftItems(),
      parcel: lastParcelId ? { id: lastParcelId, market: parcelMarket } : null,
      information_dirty: informationDirty,
      creation_unresolved: creationUnresolved });
  }

  function restoreDraft(snapshot) {
    if (!snapshot) return;
    if (data.markets.indexOf(snapshot.market) !== -1) {
      selectedMarket = snapshot.market;
      $form.find("input[name='market']").filter(function () { return $(this).val() === selectedMarket; }).prop("checked", true);
    }
    syncMarketChoices(true);
    $.each(snapshot.form || {}, function (name, value) {
      var $field = $form.find('[name="' + name + '"]');
      if ($field.is(":checkbox")) $field.prop("checked", value === true);
      else $field.val(value);
    });
    $view.find("[data-send-box]").trigger("change");
    var savedSender = (snapshot.form || {}).provider_shop_id;
    var senderExists = data.shops.some(function (shop) { return String(shop.id) === String(savedSender); });
    var missingParcelSender = !!snapshot.parcel && !senderExists;
    // Rates for an existing parcel must never be presented as rates for a
    // replacement sender. Preserve its identity and block until it is available.
    if (missingParcelSender) $view.find("[data-send-sender]").val(savedSender || "");
    else if (!senderExists) renderSenders();
    renderSenderSummary();
    receiverData = snapshot.receiver || null;
    var modal = snapshot.receiverModal || receiverData || {};
    $receiverFields.find("#src-province").val(modal.province_id || "");
    fillReceiverCities(modal.province_id, modal.city_id);
    $.each({ name: "#src-name", mobile: "#src-mobile", address: "#src-address", postal_code: "#src-postal" }, function (name, selector) {
      $receiverFields.find(selector).val(modal[name] || "");
    });
    receiverData = readReceiverFields();
    $receiverFields.find("[data-addr-count]").text(faNum((modal.address || "").length));
    $view.find("[data-send-items]").empty();
    $.each(snapshot.items || [], function (_, item) { addItemRow(item.title, item.quantity, item.net_weight, item.price_toman); });
    if (!$view.find("[data-send-items] tr").length) addItemRow("", 1, "", "");
    updateItemPlaceholderRequirements();
    creationUnresolved = snapshot.creation_unresolved === true;
    informationDirty = snapshot.information_dirty === true || missingParcelSender;
    requireQuoteSelection = informationDirty;
    if (snapshot.parcel) {
      lastParcelId = Number(snapshot.parcel.id) || 0;
      parcelMarket = snapshot.parcel.market;
    }
    if (creationUnresolved) lockParcelInputs();
    else if (lastParcelId) unlockParcelInputs();
    if (lastParcelId && creationUnresolved) refreshQuotes(true);
    else if (lastParcelId && !informationDirty) refreshQuotes();
    else if (lastParcelId && informationDirty && !creationUnresolved) {
      notice(missingParcelSender ? "فرستنده این مرسوله در دسترس نیست؛ وضعیت فرستنده را در تنظیمات بررسی کنید."
        : "تغییرات ذخیره‌نشده بازیابی شد؛ برای انتخاب روش ارسال تازه، تغییرات را ذخیره کنید.", missingParcelSender ? false : "warning");
      goToSendStage(1, false);
    }
  }

  function lockParcelInputs() {
    $form.find("input, select, textarea, button").prop("disabled", true);
    $receiverFields.find("input, select, textarea").prop("disabled", true);
  }

  function unlockParcelInputs() {
    $form.find("input, select, textarea, button").prop("disabled", false);
    $receiverFields.find("input, select, textarea").prop("disabled", false);
    // Item changes are saved as a guarded replacement of the unpaid draft.
    $view.find("[data-send-items] input, [data-send-items] button, [data-send-add-item]").prop("disabled", false);
    $view.find("[data-send-edit-note]").toggle(!!lastParcelId);
    $receiverFields.find("#src-city").prop("disabled", !$receiverFields.find("#src-province").val());
  }

  $form.on("input change", "input, select, textarea", informationChanged);
  $form.on("input change", "input, select, textarea", function () {
    if (bootstrapReady) return;
    var $field = $(this);
    if ($field.closest("[data-send-items]").length) { rememberBootstrapItems(); return; }
    var receiverField = { "src-name": "name", "src-mobile": "mobile", "src-address": "address",
      "src-postal": "postal_code", "src-province": "province_id", "src-city": "city_id" }[this.id];
    if (receiverField) {
      bootstrapEdits.receiver[receiverField] = $field.val() || "";
      if (receiverField === "province_id") bootstrapEdits.receiver.city_id = $receiverFields.find("#src-city").val() || "";
    } else if (this.name === "market") bootstrapEdits.market = selectedMarket;
    else if (["provider_shop_id", "weight", "value_toman", "box_type_id", "category_id", "dimension_length",
      "dimension_width", "dimension_height", "is_liquid", "is_fragile"].indexOf(this.name) !== -1) {
      bootstrapEdits.form[this.name] = this.type === "checkbox" ? this.checked : ($field.val() || "");
    }
  });
  $form.on("input change", "input, select, textarea", function () { saveDraft(); });
  window.addEventListener("pagehide", saveDraft);

  // ---------- Bootstrap ----------
  var $bootstrapStatus = $("<div class='sg-state__message' data-send-bootstrap-status role='status'>");
  var sendBootstrapLoading = null;
  var $bootstrapMessage = $("<p>").appendTo($bootstrapStatus);
  var $bootstrapRetry = $("<button class='sg-btn sg-btn--secondary' type='button' data-send-bootstrap-retry>")
    .text("تلاش دوباره").appendTo($bootstrapStatus).hide();
  $form.prepend($bootstrapStatus);

  function bootstrapErrorMessage(response) {
    var messages = [];
    $.each(["shops", "categories", "box_types", "provinces"], function (_, resource) {
      var message = response && response.errors && response.errors[resource];
      if (typeof message === "string" && /[\u0600-\u06ff]/.test(message) && messages.indexOf(message) === -1) messages.push(message);
    });
    if (!messages.length && response && typeof response.message === "string" && /[\u0600-\u06ff]/.test(response.message)) messages.push(response.message);
    return (messages.length ? messages.join(" ") : "اطلاعات لازم برای ثبت مرسوله دریافت نشد.")
      + " اطلاعات واردشده حفظ می‌شود؛ پس از برطرف‌شدن مشکل، تلاش دوباره را بزنید.";
  }

  function bootstrapFailed(message) {
    bootstrapReady = false;
    $bootstrapStatus.attr("role", "alert").show();
    $bootstrapMessage.text(message || "اطلاعات لازم برای ثبت مرسوله دریافت نشد؛ دوباره تلاش کنید. اطلاعات واردشده حفظ می‌شود.");
    $bootstrapRetry.show();
    operationFeedback($bootstrapStatus, $bootstrapMessage.text(), "error");
    if (sendBootstrapLoading) sendBootstrapLoading.ready();
    updateSendStage();
  }

  function loadSendBootstrap() {
    // This read-only retry is also safe while a previous creation is unresolved.
    if (bootstrapRequest || submitting || quoteBusy || paying || paymentDone || paymentUnresolved) return;
    sendBootstrapLoading = window.WoosalamGateLoading && window.WoosalamGateLoading.begin($view[0], "bootstrap", loadSendBootstrap);
    bootstrapReady = false;
    $bootstrapStatus.removeClass("sg-feedback-actions sg-feedback-toast-only").attr("role", "status").show();
    $bootstrapMessage.text("در حال دریافت اطلاعات…");
    $bootstrapRetry.prop("disabled", true).hide();
    updateSendStage();
    bootstrapRequest = sgPost("salam_gate_send_bootstrap");
    bootstrapRequest.done(function (res) {
      if (!res || !res.success || !res.data) {
        bootstrapFailed(bootstrapErrorMessage(res && res.data));
        return;
      }
      // Refresh only references. Recipient text, parcel items and entered values
      // remain in the form even when the first attempt was only partly successful.
      var senderId = $view.find("[data-send-sender]").val();
      var boxId = $view.find("[data-send-box]").val();
      var categoryId = $view.find("[data-send-category]").val();
      var provinceId = $receiverFields.find("#src-province").val();
      var cityId = $receiverFields.find("#src-city").val();
      data.shops = Array.isArray(res.data.shops) ? res.data.shops : [];
      data.categories = Array.isArray(res.data.categories) ? res.data.categories : [];
      data.boxTypes = Array.isArray(res.data.box_types) ? res.data.box_types : [];
      data.provinces = Array.isArray(res.data.provinces) ? res.data.provinces : [];
      data.markets = Array.isArray(res.data.markets) ? res.data.markets : [];
      renderMarkets();
      if (data.markets.indexOf(selectedMarket) !== -1) {
        $form.find("input[name='market']").filter(function () { return $(this).val() === selectedMarket; }).prop("checked", true);
      }
      renderSenders();
      if (data.shops.some(function (shop) { return String(shop.id) === String(senderId); })) {
        $view.find("[data-send-sender]").val(senderId);
        renderSenderSummary();
      }
      renderBoxTypes();
      if (boxId) $view.find("[data-send-box]").val(boxId);
      renderCategories();
      if (categoryId) $view.find("[data-send-category]").val(categoryId);
      fillReceiverProvinces();
      if (provinceId) $receiverFields.find("#src-province").val(provinceId);
      fillReceiverCities($receiverFields.find("#src-province").val(), cityId);
      if (!$view.find("[data-send-items] tr").length) addItemRow("", 1, "", "");

      var hasReceiverCity = data.provinces.some(function (province) {
        return province && Array.isArray(province.children) && province.children.some(function (city) {
          return city && (typeof city.id === "number" || (typeof city.id === "string" && /^[1-9][0-9]*$/.test(city.id)))
            && Number.isSafeInteger(Number(city.id)) && Number(city.id) > 0
            && typeof city.title === "string" && city.title.trim() !== "";
        });
      });
      bootstrapReady = res.data.ready !== false && data.shops.length > 0 && data.categories.length > 0
        && data.boxTypes.length > 0 && hasReceiverCity && data.markets.length > 0;
      if (!bootstrapReady) {
        bootstrapFailed(bootstrapErrorMessage(res.data));
        return;
      }
      if (!bootstrapDraftRestored) {
        bootstrapDraftRestored = true;
        restoreInitialDraft();
      }
      $bootstrapStatus.hide();
      informationFingerprint = fingerprintInformation();
      updateSendStage();
      saveDraft();
      if (sendBootstrapLoading) sendBootstrapLoading.ready();
    }).fail(function () {
      bootstrapFailed("دریافت اطلاعات لازم انجام نشد؛ دوباره تلاش کنید. اطلاعات واردشده حفظ می‌شود.");
    }).always(function () {
      bootstrapRequest = null;
      $bootstrapRetry.prop("disabled", false);
    });
  }
  $bootstrapRetry.on("click", loadSendBootstrap);
  loadSendBootstrap();

  // ---------- Submit ----------
  $submitBtn.on("click", function () {
    if (orderPickerMarket() || !bootstrapReady || navigationLocked() || sendStage !== 1) return;
    if (lastParcelId && !informationDirty) {
      goToSendStage(2, true);
      if (!$quotesDiv.children().length) refreshQuotes();
      return;
    }

    // اعتبارسنجی
    clearErrors();
    updateItemPlaceholderRequirements();
    if (window.SalamGateValidation && !window.SalamGateValidation.validate($form)) return;

    if (!selectedMarket) { setErr("market", "مبدأ سفارش را انتخاب کنید."); return; }
    if (!validateReceiver()) return;
    if (!data.shops.some(function (shop) { return String(shop.id) === String($view.find("[data-send-sender]").val()); })) { setErr("sender", "مشخصات فرستنده فعال در دسترس نیست؛ تنظیمات اتصال را بررسی کنید."); return; }
    var weight = Number(toEn($form.find("[name='weight']").val() || ""));
    if (!Number.isSafeInteger(weight) || weight < 50) { setErr("weight", "وزن باید عدد صحیح و حداقل ۵۰ گرم باشد."); return; }
    if (weight > 30000) { setErr("weight", "وزن حداکثر ۳۰٬۰۰۰ گرم است."); return; }
    var valueText = toEn($form.find("[name='value_toman']").val() || "");
    if (!/^\d+$/.test(valueText) || !Number.isSafeInteger(Number(valueText)) || Number(valueText) < 2000 || Number(valueText) > 1000000000) {
      setErr("value", "ارزش مرسوله باید بین ۲٬۰۰۰ و ۱٬۰۰۰٬۰۰۰٬۰۰۰ تومان باشد."); return;
    }
    if (!$view.find("[data-send-box]").val()) { setErr("box", "بسته‌بندی را انتخاب کنید."); return; }
    if (!$view.find("[data-send-category]").val()) { setErr("category", "نوع مرسوله را انتخاب کنید."); return; }
    if ($view.find("[data-send-box]").val() === "37") {
      var invalidDimension = ["length", "width", "height"].filter(function (dimension) {
        var value = Number(toEn($form.find("[name='dimension_" + dimension + "']").val() || ""));
        return !Number.isSafeInteger(value) || value < 1 || value > 2147483647;
      })[0];
      if (invalidDimension) { setErr("dimension_" + invalidDimension, "این بُعد بسته را به سانتی‌متر و بیشتر از صفر وارد کنید."); return; }
    }

    if (!validateItemRows()) return;
    var items = collectItems();
    if (!items.length) { setErr("items", "حداقل یک قلم وارد کنید."); return; }

    if (!lastParcelId) {
      creationAttempt = draftStore && draftStore.beginCreation ? draftStore.beginCreation(selectedMarket) : null;
      if (!creationAttempt || creationAttempt.invalid || creationAttempt.state !== "pending") {
        notice("شناسه امن ثبت مرسوله ذخیره نشد؛ اجازه ذخیره‌سازی مرورگر را بررسی کنید و صفحه را دوباره باز کنید.", false);
        return;
      }
    }

    submitting = true;
    $submitBtn.prop("disabled", true).text("در حال ثبت و دریافت نرخ…");
    $notice.hide();
    $quotesDiv.hide().empty();

    var payload = {
      market: selectedMarket,
      provider_shop_id: $view.find("[data-send-sender]").val(),
      weight: String(weight),
      value_toman: toEn($form.find("[name='value_toman']").val()),
      box_type_id: $view.find("[data-send-box]").val(),
      category_id: $view.find("[data-send-category]").val(),
      is_liquid: $form.find("[name='is_liquid']").is(":checked") ? "1" : "",
      is_fragile: $form.find("[name='is_fragile']").is(":checked") ? "1" : "",
      name: receiverData.name,
      mobile: receiverData.mobile,
      address: receiverData.address,
      postal_code: receiverData.postal_code,
      city_id: receiverData.city_id,
      items: items
    };

    if ($view.find("[data-send-box]").val() === "37") {
      payload.dimension_length = $form.find("[name='dimension_length']").val();
      payload.dimension_width = $form.find("[name='dimension_width']").val();
      payload.dimension_height = $form.find("[name='dimension_height']").val();
    }

    var updating = !!lastParcelId;
    var dispatchParcelId = lastParcelId;
    if (updating) {
      payload.parcel_id = lastParcelId;
      payload.original_market = parcelMarket;
    } else {
      payload.creation_id = creationAttempt.id;
    }
    // Persist only an unresolved-create marker before dispatch so navigation
    // while the request is in flight cannot silently submit a second parcel.
    creationUnresolved = true;
    saveDraft();
    var dispatchMarket = selectedMarket;
    lockParcelInputs();
    updateSendStage();
    sgPost("salam_gate_send_upsert", payload)
      .done(function (res) {
        if (res && res.success && res.data) {
          var confirmedId = Number(res.data.parcel_id) || 0;
          var replacementConfirmed = updating && Number(res.data.replaces_parcel_id) === dispatchParcelId
            && confirmedId !== dispatchParcelId && res.data.requires_quote_selection === true;
          if (!Number.isSafeInteger(confirmedId) || confirmedId <= 0 || (updating && confirmedId !== dispatchParcelId && !replacementConfirmed) || res.data.market !== dispatchMarket) {
            lastParcelId = dispatchParcelId;
            creationUnresolved = true;
            lockParcelInputs();
            saveDraft();
            notice("شناسه ثبت تأیید نشد؛ از فهرست مرسوله‌ها بررسی کنید و دوباره ثبت نکنید.", false);
            return;
          }
          lastParcelId = confirmedId;
          if (!updating && creationAttempt && draftStore) draftStore.markCreation(creationAttempt.id, "created", confirmedId);
          creationUnresolved = false;
          informationDirty = false;
          parcelMarket = dispatchMarket;
          selectedMarket = dispatchMarket;
          informationFingerprint = fingerprintInformation();
          requireQuoteSelection = updating;
          selectedPayMethod = 0;
          lockParcelInputs();
          saveDraft();
          notice(updating ? "تغییرات اطلاعات و اقلام ذخیره شد؛ روش ارسال و پرداخت را دوباره انتخاب کنید."
            : "مرسوله ثبت شد (شناسه " + faNum(res.data.parcel_id) + ")؛ نرخ‌های ارسال در ادامه.", true);
          renderQuotes(res.data.quotes);
          goToSendStage(2, true);
          return;
        }

        var d = (res && res.data) || {};
        if (updating && d.code === "parcel_replaced") {
          creationUnresolved = true;
          lockParcelInputs();
          saveDraft();
          submitting = false;
          refreshQuotes(true);
          return;
        }
        if (updating && (d.code === "pay_unknown" || d.code === "already_paid")) {
          creationUnresolved = false;
          paymentDone = d.code === "already_paid";
          paymentUnresolved = !paymentDone;
          draftFrozen = true;
          if (draftStore) draftStore.clearAll();
          lockParcelInputs();
          confirmationFeedback(paymentDone ? d.message || "پرداخت این مرسوله ثبت شده است؛ رسید را در فهرست مرسوله‌ها بررسی کنید."
            : "در حال بررسی پرداخت؛ تأیید پرداخت هنوز دریافت نشده؛ وضعیت را بررسی کنید. دوباره پرداخت نکنید.", paymentDone ? "success" : "warning");
          goToSendStage(3, true);
          return;
        }
        if (d.errors) {
          $.each(d.errors, function (key, err) {
            setErr(err && typeof err === "object" ? (err.location || key) : key,
              err && typeof err === "object" ? err.message : err, false, false);
          });
          window.setTimeout(function () {
            $view.find("[aria-invalid='true']:visible").not(":disabled").first().trigger("focus");
          }, 0);
        }
        if (d.code === "create_unknown" || d.code === "update_unknown") {
          if (!updating && creationAttempt && draftStore) draftStore.markCreation(creationAttempt.id, "unknown");
          creationUnresolved = true;
          lockParcelInputs();
          saveDraft();
          notice("نتیجه ثبت یا ویرایش نامعلوم است؛ درخواست را تکرار نکنید و وضعیت را در فهرست مرسوله‌ها بررسی کنید.", false);
          $submitBtn.prop("disabled", true).text("نامعلوم — بررسی وضعیت");
          submitting = false;
          return;
        }
        notice(d.message || "ثبت ناموفق بود.", false);
        if (!updating && creationAttempt && draftStore) {
          var savedAttempt = draftStore.loadCreation();
          if (savedAttempt && savedAttempt.state !== "created") draftStore.clearCreation(creationAttempt.id);
          creationAttempt = null;
        }
        creationUnresolved = false;
        unlockParcelInputs();
        saveDraft();
        $submitBtn.prop("disabled", false).text("مرحله بعد");
      })
      .fail(function () {
        if (!updating && creationAttempt && draftStore) draftStore.markCreation(creationAttempt.id, "unknown");
        creationUnresolved = true;
        lockParcelInputs();
        saveDraft();
        notice("ارتباط با سرور برقرار نشد؛ نتیجه ممکن است نامعلوم باشد.", false);
        $submitBtn.prop("disabled", true).text("نامعلوم");
      })
      .always(function () { submitting = false; updateSendStage(); });
  });

  // ---------- نرخ‌ها ----------
  function renderQuotes(quotesData) {
    var quotes = (quotesData && quotesData.quotes) || [];
    selectedQuote = null;
    selectedPayMethod = 0;
    $view.find("[data-send-confirm], [data-send-pay-method]").hide();
    $view.find("[data-send-main-action]").hide();
    if (!quotes.length) {
      $quotesDiv.empty().append($("<p class='sg-card__detail'>").text("نرخی برای این مرسوله یافت نشد.")).show();
      quoteProblem("دریافت نرخ کامل نشد؛ همان مرسوله را بررسی کنید، نیازی به ثبت دوباره نیست.");
      return;
    }

    var selected = null;
    var $list = $("<div class='sg-quotes-cards'>");
    $.each(quotes, function (i, q) {
      var disabled = !validQuote(q);
      if (q.selected && !disabled && !requireQuoteSelection) selected = selected === null ? q : false;
      var $card = $("<button type='button'>")
        .addClass("sg-quote-card")
        .toggleClass("is-disabled", disabled)
        .toggleClass("is-selected", !!q.selected && !disabled && !requireQuoteSelection)
        .prop("disabled", disabled)
        .attr("aria-pressed", q.selected && !disabled && !requireQuoteSelection ? "true" : "false")
        .data("hash", q.hash_id);

      if (!disabled) {
        $card.on("click", function () {
          if (!quoteBusy && !paying && !paymentDone && !paymentUnresolved) selectQuote(q.hash_id);
        });
      }

      $card.append(
        $('<div class="sg-quote-card__header">').append(
          $('<span class="sg-quote-card__name">').text(q.service_name || q.service_type_label || "سرویس"),
          q.discount_percent ? $('<span class="sg-quote-card__discount">').text(q.discount_percent + "٪ تخفیف") : $()
        )
      );

      // Display the service's discounted fare here, not its payable invoice total.
      // Missing fare data stays unavailable; never label the total as a fare.
      var fare = Number.isFinite(q.base_cost_toman) && q.base_cost_toman >= 0 ? q.base_cost_toman : null;
      $card.append($('<small class="sg-quote-card__fare-label">').text("کرایه پستی بعد از تخفیف"),
        $('<div class="sg-quote-card__price">').text(fare !== null ? faThousands(fare) + " تومان" : "—"));
      if (q.error) { $card.append($('<div class="sg-quote-card__error">').text(q.error)); }

      $list.append($card);
    });

    $quotesDiv.empty().append($("<h4 class='sg-send__shipping-title'>").append(
      $("<span class='dashicons dashicons-car' aria-hidden='true'>"), "شیوه ارسال"
    )).append($list, $("<p class='sg-field__hint'>").text("مبلغ نهایی با بیمه، مالیات و سایر هزینه‌ها در مرحله تأیید نمایش داده می‌شود.")).show();
    if (selected && !paymentDone && !paymentUnresolved) showPayStep(selected);
    else $view.find("[data-send-quote-notice]").hide().empty();
  }

  // ---------- پارت ۷: روش پرداخت + تأیید + پرداخت ----------

  var selectedQuote = null;
  var selectedPayMethod = 0;
  var lastParcelId = 0;
  var paying = false;
  var quoteBusy = false;
  var quoteSequence = 0;
  var paymentDone = false;
  var paymentUnresolved = false;

  function validQuote(q) {
    return q && typeof q.hash_id === "string" && q.hash_id !== "" && !q.error
      && typeof q.total_rial === "number" && Number.isSafeInteger(q.total_rial) && q.total_rial >= 0
      && q.total_toman != null && Number.isFinite(Number(q.total_toman));
  }

  function quoteProblem(message, allowRetry, kind) {
    if (kind === undefined) kind = "error";
    var $target = $view.find("[data-send-quote-notice]").empty().show();
    $target.append($("<p>").text(message));
    if (allowRetry !== false && lastParcelId && !paymentDone && !paymentUnresolved) {
      $target.append($("<button type='button' class='sg-button sg-button--secondary' data-send-refresh-quotes>").text("دریافت دوباره نرخ‌ها"));
    }
    $target.append($("<a class='sg-button sg-button--secondary'>")
      .attr("href", adminUrlBase + "admin.php?page=salam_gate_parcels").text("بررسی در فهرست مرسوله‌ها"));
    operationFeedback($target, message, kind);
  }

  function setPaymentStatus(message, kind) {
    var $target = $view.find("[data-send-pay-status]").removeClass("is-warning is-error is-success")
      .addClass(kind ? "is-" + kind : "").text(message || "").toggle(!!message);
    operationFeedback($target, message, message ? kind : "");
  }

  function clearPaymentFeedback() {
    setPaymentStatus("");
    $view.find("[data-send-wallet-topup]").remove();
    $view.find("[data-send-confirm-notice]").hide().empty().removeClass("sg-feedback-actions sg-feedback-toast-only").attr("role", "alert");
  }

  function showPaymentError(response, method) {
    // A definitive failure has one alert; do not repeat it in the status region.
    setPaymentStatus("");
    $view.find("[data-send-wallet-topup]").remove();
    var $error = $view.find("[data-send-confirm-notice]").empty();
    var message = String(response.message || "پرداخت ناموفق بود.");
    var paymentReview = ["pay_unknown", "unknown"].indexOf(response.code) !== -1
      || ["pending", "unknown"].indexOf(response.payment_state) !== -1
      || (/پرداخت/.test(message) && /نامعلوم|نامشخص|مشخص نیست|قطعی نیست/.test(message));
    if (paymentReview) message = "در حال بررسی پرداخت؛ تأیید پرداخت هنوز دریافت نشده؛ وضعیت را بررسی کنید. دوباره پرداخت نکنید.";
    $error.append($("<p>").text(message));
    // The payment API reserves this validation location for wallet insufficiency.
    // Do not infer a top-up action from translated/generic balance or auth errors.
    var walletInsufficient = method === 21 && response.kind === "validation"
      && Array.isArray(response.errors) && response.errors.some(function (error) {
        return error && error.location === "user_wallet";
      });
    if (walletInsufficient) {
      $view.find(".sg-send__payment-actions").prepend($("<a class='sg-button sg-button--secondary sg-payment-topup' data-send-wallet-topup>")
        .attr({ href: "https://wallet.basalam.com/?from=vendor", target: "_blank", rel: "noopener noreferrer" })
        .append($("<span class='dashicons dashicons-money-alt' aria-hidden='true'>"), $("<span>").text("شارژ کیف پول")));
    }
    if (paymentReview) $error.append($("<a class='sg-button sg-button--secondary' data-send-payment-review>")
      .attr("href", adminUrlBase + "admin.php?page=salam_gate_parcels").text("بررسی وضعیت مرسوله"));
    $error.show();
    if (typeof window.woosalamNotify === "function") {
      $error.attr("role", "group").toggleClass("sg-feedback-actions", paymentReview).toggleClass("sg-feedback-toast-only", !paymentReview);
      window.woosalamNotify(message, "error");
    }
  }

  function setControlsBusy(busy) {
    $quotesDiv.find("button.sg-quote-card:not(.is-disabled)").prop("disabled", busy);
    $view.attr("data-sg-financial-busy", String(!!busy));
    $view.find("input[name='send_pay_method'], [data-send-back-to-quotes]").prop("disabled", busy);
    $view.find("[data-send-pay]").prop("disabled", busy || !canReview());
    updateSendStage();
    if (window.SalamGatePaymentPresentation) window.SalamGatePaymentPresentation.refresh($view[0]);
  }

  function adoptCanonicalParcel(response) {
    var id = Number(response.parcel_id);
    var replacement = id !== lastParcelId;
    if (!Number.isSafeInteger(id) || id <= 0
        || (replacement && (Number(response.replaces_parcel_id) !== lastParcelId || response.requires_quote_selection !== true))
        || data.markets.indexOf(response.market) === -1) return false;
    var selectionRequired = requireQuoteSelection || response.requires_quote_selection === true;
    if (!response.payment_state && !(response.result && response.result.confirmed)) {
      var payload = response.parcel_payload || response.replacement_payload;
      if (!$.isPlainObject(payload) || !$.isPlainObject(payload.receiver)
          || !Array.isArray(payload.items) || !payload.items.length) return false;
      if (!["provider_shop_id", "category_id", "weight", "box_type_id"].every(function (key) {
        return Number.isSafeInteger(Number(payload[key])) && Number(payload[key]) > 0;
      }) || !Number.isSafeInteger(Number(payload.value_toman)) || Number(payload.value_toman) < 0) return false;
      var receiver = payload.receiver, province = "";
      $.each(data.provinces, function (_, item) {
        if ((item.children || []).some(function (city) { return String(city.id) === String(receiver.city_id); })) province = String(item.id);
      });
      if (!province || !["name", "mobile", "address", "postal_code"].every(function (key) { return typeof receiver[key] === "string"; })
          || !payload.items.every(function (item) { return $.isPlainObject(item) && typeof item.title === "string"
            && Number.isSafeInteger(Number(item.quantity)) && Number(item.quantity) > 0
            && Number.isSafeInteger(Number(item.net_weight)) && Number(item.net_weight) > 0
            && Number.isSafeInteger(Number(item.price_toman)) && Number(item.price_toman) >= 0; })) return false;
      var form = {};
      $.each(["provider_shop_id", "weight", "value_toman", "box_type_id", "category_id", "dimension_length", "dimension_width", "dimension_height", "is_liquid", "is_fragile"], function (_, key) { form[key] = payload[key]; });
      restoreDraft({ market: response.market, form: form, receiver: receiver,
        receiverModal: $.extend({}, receiver, { province_id: province }), items: payload.items,
        parcel: { id: id, market: response.market }, information_dirty: false, creation_unresolved: false });
    } else {
      lastParcelId = id;
      parcelMarket = response.market;
      selectedMarket = response.market;
    }
    requireQuoteSelection = selectionRequired || informationDirty;
    informationFingerprint = fingerprintInformation();
    saveDraft();
    lockParcelInputs();
    return true;
  }

  function refreshQuotes(recoverUpdate) {
    recoverUpdate = recoverUpdate === true;
    if (orderPickerMarket() || !lastParcelId || (informationDirty && !recoverUpdate) || quoteBusy || paying || submitting || paymentDone || paymentUnresolved) return;
    clearPaymentFeedback();
    var sequence = ++quoteSequence;
    quoteBusy = true;
    var quotesLoading = window.WoosalamGateLoading && window.WoosalamGateLoading.begin($view[0], "quotes", function () { refreshQuotes(recoverUpdate === true); });
    selectedQuote = null;
    selectedPayMethod = 0;
    goToSendStage(2, false);
    $view.find("[data-send-confirm], [data-send-pay-method]").hide();
    $view.find("[data-send-quote-notice]").hide().empty();
    setControlsBusy(true);
    sgPost("salam_gate_send_quotes", { parcel_id: lastParcelId, market: parcelMarket })
      .done(function (res) {
        if (sequence !== quoteSequence) return;
        if (res && res.success && res.data && Number(res.data.parcel_id) !== lastParcelId && res.data.replaces_parcel_id) {
          if (!adoptCanonicalParcel(res.data)) {
            creationUnresolved = true;
            lockParcelInputs();
            saveDraft();
            quoteProblem("اطلاعات پیش‌نویس جایگزین تأیید نشد؛ ثبت یا پرداخت دوباره انجام ندهید.", false);
            return;
          }
        }
        if (res && res.success && res.data && Number(res.data.parcel_id) === lastParcelId && res.data.market === parcelMarket) {
          if (recoverUpdate && !res.data.payment_state && !res.data.result && res.data.requires_quote_selection !== true) {
            // No confirmed edit marker: preserve the user's unsaved information
            // and never use rates for the older unchanged fields.
            creationUnresolved = false;
            informationDirty = true;
            requireQuoteSelection = true;
            quoteBusy = false;
            unlockParcelInputs();
            goToSendStage(1, false);
            saveDraft();
            notice("ویرایش قبلی تأیید نشده؛ تغییرات را ذخیره و روش ارسال را دوباره انتخاب کنید.", "warning");
            return;
          }
          var canonical = res.data.parcel_payload || res.data.replacement_payload;
          if (!res.data.payment_state && !res.data.result && (canonical || recoverUpdate)) {
            if (!adoptCanonicalParcel(res.data)) {
              creationUnresolved = true;
              informationDirty = true;
              requireQuoteSelection = true;
              lockParcelInputs();
              saveDraft();
              quoteProblem("اطلاعات ذخیره‌شده مرسوله تأیید نشد؛ ثبت یا پرداخت دوباره انجام ندهید.", false);
              return;
            }
          }
          if (recoverUpdate) {
            creationUnresolved = false;
            informationFingerprint = fingerprintInformation();
            saveDraft();
          }
          requireQuoteSelection = requireQuoteSelection || res.data.requires_quote_selection === true;
          if (res.data.result && res.data.result.confirmed) {
            showPayResult(res.data.result);
            return;
          }
          if (res.data.payment_state) {
            paymentDone = res.data.payment_state === "paid";
            paymentUnresolved = !paymentDone;
            $quotesDiv.hide();
            sendStage = 3;
            $view.find("[data-send-confirm]").show();
            confirmationFeedback(paymentDone ? "این مرسوله قبلاً پرداخت شده است؛ رسید را در فهرست مرسوله‌ها ببینید."
              : "در حال بررسی پرداخت؛ تأیید پرداخت هنوز دریافت نشده؛ وضعیت را بررسی کنید. دوباره پرداخت نکنید.", paymentDone ? "success" : "warning", false);
            quoteProblem(paymentDone ? "پرداخت این مرسوله قبلاً ثبت شده است؛ وضعیت و رسید را در فهرست مرسوله‌ها ببینید."
              : "در حال بررسی پرداخت؛ تأیید پرداخت هنوز دریافت نشده؛ وضعیت را بررسی کنید. دوباره پرداخت نکنید.", false, paymentDone ? "success" : "warning");
            return;
          }
          $view.find("[data-send-quote-notice]").hide().empty();
          if (informationDirty) quoteProblem("فرستنده این پیش‌نویس در دسترس نیست؛ تنظیمات فرستنده را بررسی کنید.", false);
          else renderQuotes(res.data.quotes);
        } else quoteProblem((res && res.data && res.data.message) || "نرخ‌های این مرسوله تأیید نشدند.");
      })
      .fail(function () { if (sequence === quoteSequence) quoteProblem("دریافت نرخ ناموفق بود؛ پرداختی ارسال نشد."); })
      .always(function () {
        if (sequence !== quoteSequence) return;
        quoteBusy = false;
        setControlsBusy(false);
        renderConfirm();
        // Failure/unknown states are rendered explicitly by quoteProblem above;
        // reveal them only after this read has fully settled, never while pending.
        if (quotesLoading) quotesLoading.ready();
      });
  }

  $view.on("click", "[data-send-refresh-quotes]", refreshQuotes);

  $view.on("click", "[data-send-recheck-payment]", function () {
    if (!lastParcelId || !paymentUnresolved || paymentDone || quoteBusy || paying || submitting || creationUnresolved) return;
    var sequence = ++quoteSequence;
    quoteBusy = true;
    selectedQuote = null;
    selectedPayMethod = 0;
    confirmationFeedback("در حال بررسی نتیجه پرداخت قبلی؛ درخواست پرداخت تازه‌ای ارسال نمی‌شود…", "");
    setControlsBusy(true);
    sgPost("salam_gate_send_quotes", { parcel_id: lastParcelId, market: parcelMarket })
      .done(function (res) {
        if (sequence !== quoteSequence) return;
        var response = res && res.success === true && res.data;
        if (!response || Number(response.parcel_id) !== lastParcelId || response.market !== parcelMarket) {
          confirmationFeedback("وضعیت همین مرسوله تأیید نشد؛ دوباره پرداخت نکنید و کمی بعد بررسی کنید.", "error");
          return;
        }
        if (response.result && response.result.confirmed === true) { showPayResult(response.result); return; }
        if (response.payment_state === "paid") {
          paymentDone = true;
          paymentUnresolved = false;
          confirmationFeedback("پرداخت قبلی ثبت شده است؛ کد رهگیری و رسید را در فهرست مرسوله‌ها ببینید. دوباره پرداخت نکنید.", "success");
          return;
        }
        var hashes = Object.create(null), selectedCount = 0;
        var envelope = response.quotes;
        var ratesValid = envelope && Number(envelope.parcel_id) === lastParcelId && Array.isArray(envelope.quotes)
          && envelope.quotes.every(function (q) {
            if (q && q.error && q.selected === false) return true;
            if (!validQuote(q) || typeof q.selected !== "boolean" || hashes[q.hash_id]) return false;
            hashes[q.hash_id] = true;
            if (q.selected) selectedCount++;
            return selectedCount <= 1;
          });
        // The standalone payable contract uses the empty payment_state. Only
        // the server's owner/attempt-correlated failure proof may release it.
        if (response.payment_failure_confirmed !== true || response.payment_state !== "" || !ratesValid
            || !adoptCanonicalParcel(response) || informationDirty) {
          confirmationFeedback("در حال بررسی پرداخت؛ تأیید پرداخت هنوز دریافت نشده؛ وضعیت را بررسی کنید. دوباره پرداخت نکنید.", "warning");
          return;
        }
        paymentUnresolved = false;
        requireQuoteSelection = true;
        clearPaymentFeedback();
        goToSendStage(2, false);
        renderQuotes(envelope);
        setPaymentStatus("ناموفق‌بودن پرداخت قبلی تأیید شد. روش ارسال و شیوه پرداخت را دوباره انتخاب کنید.", "warning");
        saveDraft();
      })
      .fail(function () {
        if (sequence === quoteSequence) confirmationFeedback("وضعیت پرداخت دریافت نشد؛ دوباره پرداخت نکنید و کمی بعد بررسی کنید.", "error");
      })
      .always(function () {
        if (sequence !== quoteSequence) return;
        quoteBusy = false;
        setControlsBusy(paymentDone || paymentUnresolved);
        renderConfirm();
      });
  });

  function selectQuote(hash) {
    if (orderPickerMarket() || !lastParcelId || informationDirty || creationUnresolved || submitting || quoteBusy || paying || paymentDone || paymentUnresolved || sendStage !== 2) return;
    clearPaymentFeedback();
    var sequence = ++quoteSequence;
    quoteBusy = true;
    selectedQuote = null;
    selectedPayMethod = 0;
    $view.find("[data-send-confirm], [data-send-pay-method]").hide();
    $view.find("[data-send-quote-notice]").hide().empty();
    var selectionLoading = window.WoosalamGateLoading && window.WoosalamGateLoading.begin($view[0], "quote-selection", refreshQuotes);
    setControlsBusy(true);
    sgPost("salam_gate_send_select_quote", { parcel_id: lastParcelId, market: selectedMarket, hash: hash })
      .done(function (res) {
        if (sequence !== quoteSequence) return;
        var envelope = res && res.success && res.data && Number(res.data.parcel_id) === lastParcelId && res.data.market === parcelMarket ? res.data.quotes : null;
        var matches = envelope && Array.isArray(envelope.quotes) ? envelope.quotes.filter(function (q) { return q.selected && q.hash_id === hash && validQuote(q); }) : [];
        if (matches.length !== 1) {
          quoteProblem((res && res.data && res.data.message) || "انتخاب نرخ تأیید نشد؛ قبل از پرداخت دوباره نرخ بگیرید.");
          return;
        }
        if (res.data.parcel_payload && (!adoptCanonicalParcel(res.data) || informationDirty)) {
          informationDirty = true;
          requireQuoteSelection = true;
          saveDraft();
          quoteProblem("اطلاعات یا فرستنده این مرسوله تأیید نشد؛ تنظیمات را بررسی کنید و دوباره نرخ بگیرید.", false);
          return;
        }
        requireQuoteSelection = false;
        $view.find("[data-send-quote-notice]").hide().empty();
        renderQuotes(envelope);
        setPaymentStatus("");
      })
      .fail(function () { if (sequence === quoteSequence) quoteProblem("پاسخ انتخاب نرخ مشخص نیست؛ ابتدا نرخ‌ها را دوباره بررسی کنید. پرداختی ارسال نشد."); })
      .always(function () {
        if (sequence !== quoteSequence) return;
        quoteBusy = false;
        setControlsBusy(false);
        renderConfirm();
        if (selectionLoading) selectionLoading.ready();
      });
  }

  function showPayStep(quote) {
    if (orderPickerMarket()) return;
    selectedQuote = quote;
    if (validQuote(quote) && selectedPayMethod !== 20 && selectedPayMethod !== 21) selectedPayMethod = 20;

    // روش پرداخت: فقط بازارهای غیر باسلام → 20/21
    var $wrap = $view.find("[data-send-pay-methods]").empty();
    var methods = [[20, "تراز غرفه‌دار", "برداشت از موجودی تراز غرفهٔ متصل در باسلام"],
      [21, "کیف پول", "برداشت از موجودی کیف پول حساب متصل در باسلام"]];
    $.each(methods, function (i, m) {
      var id = "sgpm-" + m[0];
      $wrap.append(
        $("<label class='sg-send-payment-option'>").toggleClass("is-selected", selectedPayMethod === m[0]).append(
          $("<input>").attr({ type: "radio", name: "send_pay_method", value: m[0], id: id }).prop("checked", selectedPayMethod === m[0]).data("title", m[1]),
          $("<span class='sg-send-payment-option__content'>").append(
            $("<span class='sg-send-payment-option__label'>").text(m[1]),
            $("<span class='sg-send-payment-option__description'>").text(m[2])
          )
        )
      );
    });
    $view.find("[data-send-pay-method]").show();
    if (window.SalamGatePaymentPresentation) {
      var presentation = window.SalamGatePaymentPresentation;
      presentation.refresh($view[0]);
      if (typeof presentation.whenReady === "function") {
        var balanceLoading = window.WoosalamGateLoading && window.WoosalamGateLoading.begin($view[0], "balances", function () {
          presentation.refreshBalance($view[0]); showPayStep(selectedQuote);
        });
        presentation.whenReady($view[0]).always(function () { if (balanceLoading) balanceLoading.ready(); });
      }
    }


    renderConfirm();
  }

  $view.on("change", "input[name='send_pay_method']", function () {
    if (navigationLocked() || informationDirty || requireQuoteSelection || sendStage !== 2 || !validQuote(selectedQuote)) return;
    clearPaymentFeedback();
    var method = Number($(this).val() || 0);
    selectedPayMethod = method === 20 || method === 21 ? method : 0;
    $view.find(".sg-send-payment-option").each(function () { $(this).toggleClass("is-selected", $(this).find("input").is(":checked")); });
    $view.find("[data-error-for='pay_method']").text("").hide();
    renderConfirm();
  });

  function renderConfirm() {
    $view.attr("data-sg-payable-rial", validQuote(selectedQuote) ? selectedQuote.total_rial : "");
    updateSendStage();
    if (!canReview()) {
      $view.find("[data-send-confirm]").toggle(sendStage === 3 && (paymentDone || paymentUnresolved) && !$view.find("[data-send-result]").children().length);
      if (paymentUnresolved) $view.find("[data-send-pay]").text("در حال بررسی");
      return;
    }

    var toman = selectedQuote.total_rial / 10;
    var discount = selectedQuote.discount_percent;

    var payTitle = "";
    $view.find("input[name='send_pay_method']").each(function () {
      if (Number($(this).val()) === selectedPayMethod) payTitle = $(this).data("title") || "";
    });

    var $review = $view.find("[data-send-review]").empty();
    var rows = [
      ["سرویس ارسال", selectedQuote.service_name || "—"],
      ["مبلغ نهایی قابل پرداخت", toman != null ? faThousands(toman) + " تومان" : "—"],
      ["تخفیف", discount ? discount + "٪" : "—"],
      ["روش پرداخت", payTitle || "—"],
      ["گیرنده", receiverData ? receiverData.name : "—"],
      ["مقصد", receiverData ? (receiverData.city_title + "، " + receiverData.province_title) : "—"]
    ];
    $.each(rows, function (i, row) {
      $review.append($("<div class='sg-wizard__review-row'>").append($("<dt>").text(row[0]), $("<dd>").text(row[1])));
    });

    $view.find("[data-send-confirm]").toggle(sendStage === 3);
    if (!paying) $view.find("[data-send-pay]").prop("disabled", false).text("تأیید و پرداخت " + faThousands(toman) + " تومان");
  }

  $view.find("[data-send-back-to-quotes]").on("click", function () {
    if (navigationLocked()) return;
    clearPaymentFeedback();
    goToSendStage(2, true);
  });

  $view.find("[data-send-pay]").on("click", function () {
    if (navigationLocked() || sendStage !== 3 || !canReview()) return;
    if (!lastParcelId) return;

    paying = true;
    var dispatchedPayMethod = selectedPayMethod;
    clearPaymentFeedback();
    var $btn = $(this).prop("disabled", true).text("در حال پرداخت…");
    setControlsBusy(true);
    setPaymentStatus("درخواست پرداخت ارسال شد؛ تا مشخص‌شدن نتیجه دوباره پرداخت نکنید.");

    sgPost("salam_gate_send_pay", {
      parcel_id: lastParcelId,
      market: selectedMarket,
      method: String(dispatchedPayMethod),
      expected_hash: selectedQuote.hash_id,
      expected_total_rial: String(selectedQuote.total_rial)
    })
      .done(function (res) {
        if (res && res.success && res.data) {
          showPayResult(res.data);
          return;
        }

        var dd = (res && res.data) || {};
        var code = dd.code || "";
        setPaymentStatus("");

        if (code === "quote_changed") {
          selectedQuote = null;
          quoteProblem(dd.message || "نرخ تغییر کرده؛ دوباره نرخ بگیرید و مبلغ تازه را تأیید کنید.");
          selectedPayMethod = 0;
          goToSendStage(2, false);
        } else if (code === "already_paid") {
          paymentDone = true;
          quoteProblem("این مرسوله قبلاً پرداخت شده است؛ رسید و وضعیت را در فهرست مرسوله‌ها ببینید.", false, "success");
          confirmationFeedback("این مرسوله قبلاً پرداخت شده است؛ رسید و وضعیت را در فهرست مرسوله‌ها ببینید.", "success", false);
          $view.find("[data-send-pay-method]").hide();
          draftFrozen = true;
          if (draftStore) draftStore.clearAll();
        } else if (code === "pay_unknown") {
          paymentUnresolved = true;
          confirmationFeedback("در حال بررسی پرداخت؛ تأیید پرداخت هنوز دریافت نشده؛ وضعیت را بررسی کنید. دوباره پرداخت نکنید.", "warning", false);
          $btn.prop("disabled", true).text("در حال بررسی");
          quoteProblem("در حال بررسی پرداخت؛ تأیید پرداخت هنوز دریافت نشده؛ وضعیت را بررسی کنید. دوباره پرداخت نکنید.", false, "warning");
          paying = false;
          return;
        } else {
          showPaymentError(dd, dispatchedPayMethod);
        }
      })
      .fail(function () {
        setPaymentStatus("");
        paymentUnresolved = true;
        confirmationFeedback("در حال بررسی پرداخت؛ تأیید پرداخت هنوز دریافت نشده؛ وضعیت را بررسی کنید. دوباره پرداخت نکنید.", "error", false);
        $btn.prop("disabled", true).text("در حال بررسی");
        quoteProblem("در حال بررسی پرداخت؛ تأیید پرداخت هنوز دریافت نشده؛ وضعیت را بررسی کنید. دوباره پرداخت نکنید.", false);
      })
      .always(function () {
        paying = false;
        setControlsBusy(paymentDone || paymentUnresolved);
        renderConfirm();
      });
  });

  function showPayResult(d) {
    clearPaymentFeedback();
    paymentDone = true;
    sendStage = 3;
    draftFrozen = true;
    if (creationAttempt && draftStore && draftStore.clearCreation) draftStore.clearCreation(creationAttempt.id);
    if (draftStore) draftStore.clearAll();
    var confirmed = d && d.confirmed && d.tracking_code;
    notice(confirmed ? "پرداخت مرسوله با موفقیت انجام شد." : "پرداخت ثبت شد؛ صدور رسید در حال بررسی است. دوباره پرداخت نکنید.", confirmed ? true : "warning");
    var $card = $("<div class='sg-card'>").toggleClass("sg-card--success", !!confirmed).append(
      $("<h4>").text(confirmed ? "پرداخت موفق ✓" : "پرداخت ثبت شد؛ صدور رسید در حال بررسی است"),
      $("<p class='sg-card__text'>").text(confirmed ? "کد رهگیری: " + d.tracking_code : "دوباره پرداخت نکنید؛ وضعیت و کد رهگیری را در فهرست مرسوله‌ها بررسی کنید.")
    );
    if (Number.isSafeInteger(d.final_total_rial) && d.final_total_rial >= 0) $card.append($("<p class='sg-card__detail'>").text("هزینه نهایی ارسال: " + faThousands(d.final_total_rial / 10) + " تومان"));
    if (confirmed) $card.append($("<p class='sg-card__detail'>").text("مرکز تحویل: " + (d.delivery_node || "—")));
    $card.append($("<a class='sg-button sg-button--primary'>").attr("href", adminUrlBase + "admin.php?page=salam_gate_parcels").text("فهرست مرسوله‌ها"));
    $view.find("[data-send-result]").empty().append($card).show();
    $view.find("[data-send-confirm], [data-send-pay-method], [data-send-quote-notice]").hide();
    $quotesDiv.hide();
    setControlsBusy(true);
    $view.find("[data-send-result] h4").attr("tabindex", "-1").trigger("focus");
  }

  window.addEventListener("pageshow", function (event) {
    if (event.persisted && draftStore) {
      // Do not let this cached form overwrite the newer snapshot on pagehide.
      draftFrozen = true;
      if (!draftStore.isCurrent()) {
        // A reload/account change invalidated this cached document's recipient.
        window.location.reload();
      } else {
        // A newer send document may have created/paid this parcel. Normal
        // navigation rehydrates the latest draft without treating Back as reload.
        window.location.replace(window.location.href);
      }
    }
  });

  if (creationAttempt && (!initialDraft || !initialDraft.parcel)) {
    creationUnresolved = true;
    lockParcelInputs();
    updateSendStage();
    if (!creationAttempt.invalid) checkCreationStatus();
  }

  // ---------- errors ----------
  function sendFieldName(location) {
    var path = Array.isArray(location) ? location.join(".") : String(location || "");
    if (/(^|\.)items(?:\.|\[|$)/.test(path)) return "items";
    var name = path.split(".").pop();
    var aliases = { provider_shop_id: "sender", box_type_id: "box", category_id: "category", value_toman: "value",
      name: "receiver", mobile: "receiver", address: "receiver", postal_code: "receiver", city_id: "receiver" };
    name = aliases[name] || name;
    return ["market", "receiver", "sender", "weight", "value", "box", "category", "items", "dimension_length", "dimension_width", "dimension_height", "pay_method"].indexOf(name) !== -1 ? name : "";
  }

  function setErr(location, msg, focus, announce) {
    var name = sendFieldName(location);
    var messages = { market: "مبدأ سفارش را انتخاب کنید.", receiver: "اطلاعات گیرنده را بررسی کنید.", sender: "مشخصات فرستنده فعال را در تنظیمات بررسی کنید.",
      weight: "وزن مرسوله را به گرم وارد کنید.", value: "ارزش مرسوله را به تومان وارد کنید.", box: "نوع بسته‌بندی را انتخاب کنید.",
      category: "نوع مرسوله را انتخاب کنید.", items: "اطلاعات اقلام مرسوله را بررسی کنید.",
      dimension_length: "طول بسته را به سانتی‌متر وارد کنید.", dimension_width: "عرض بسته را به سانتی‌متر وارد کنید.",
      dimension_height: "ارتفاع بسته را به سانتی‌متر وارد کنید.", pay_method: "روش پرداخت را انتخاب کنید." };
    var message = typeof msg === "string" && /[\u0600-\u06ff]/.test(msg) ? msg : (messages[name] || "اطلاعات مرسوله را بررسی کنید.");
    var path = Array.isArray(location) ? location.join(".") : String(location || "");
    var receiverField = name === "receiver" ? ({ name: "name", mobile: "mobile", address: "address", postal_code: "postal", city_id: "city" })[path.split(".").pop()] : "";
    var $err = receiverField ? $receiverFields.find("[data-receiver-error-for='" + receiverField + "']")
      : (name ? $view.find("[data-error-for='" + name + "']") : $());
    if ($err.length) $err.text(message).show();
    var controls = { market: "input[name='market']", receiver: "#src-name", sender: "[data-send-sender]",
      weight: "[name='weight']", value: "[name='value_toman']", box: "[data-send-box]", category: "[data-send-category]",
      dimension_length: "[name='dimension_length']", dimension_width: "[name='dimension_width']", dimension_height: "[name='dimension_height']",
      pay_method: "input[name='send_pay_method']" };
    var $control = receiverField ? $receiverFields.find("#src-" + receiverField)
      : (name === "items" ? $view.find("[data-send-items] [aria-invalid='true']").first() : $view.find(controls[name] || ".sg-send-no-control").first());
    if (name === "items" && !$control.length) $control = $view.find(".sg-send-item-title, [data-send-add-item]").first();
    $control.attr("aria-invalid", "true").addClass("sg-field__input--invalid");
    if (name === "receiver" && !receiverField) $control.attr("aria-describedby", "src-name-error sg-send-receiver-error");
    if (focus !== false) $control.trigger("focus");
    if (announce !== false) notice(message, false);
  }
  function clearErrors() {
    $view.find("[data-error-for], [data-send-item-error], [data-receiver-error-for]").text("").hide();
    $view.find("[aria-invalid]").removeAttr("aria-invalid").removeClass("sg-field__input--invalid");
  }

  function clearReceiverErrors() {
    $receiverFields.find("[data-receiver-error-for]").text("").hide();
    $receiverFields.find("[aria-invalid]").removeAttr("aria-invalid").removeClass("sg-field__input--invalid");
  }

  function validateItemRows() {
    var invalid = false;
    var hasItems = collectItems().length > 0;
    $view.find("[data-send-items] tr").each(function () {
      var $row = $(this);
      if (hasItems && emptyFirstItemRow($row)) return;
      var errors = {};
      if (!$.trim($row.find(".sg-send-item-title").val() || "")) errors.title = "عنوان قلم را وارد کنید.";
      $.each(["qty", "weight", "price"], function (_, key) {
        var raw = toEn($row.find(".sg-send-item-" + key).val() || "");
        var number = Number(raw);
        if (key === "price" && !raw) return;
        if (!raw || !/^\d+$/.test(raw) || !Number.isSafeInteger(number) || number < (key === "price" ? 1000 : 1) || (key !== "price" && number > 2147483647)) {
          errors[key] = key === "qty" ? "تعداد باید عدد صحیح و حداقل ۱ باشد."
            : (key === "weight" ? "وزن خالص باید عدد صحیح و حداقل ۱ گرم باشد." : "قیمت هر قلم باید عدد صحیح و حداقل ۱٬۰۰۰ تومان باشد.");
        }
      });
      $.each(errors, function (key, message) {
        invalid = true;
        $row.find("[data-send-item-error='" + key + "']").text(message).show();
        $row.find(".sg-send-item-" + key).attr("aria-invalid", "true").addClass("sg-field__input--invalid");
      });
    });
    if (invalid) setErr("items", "اطلاعات مشخص‌شده در اقلام مرسوله را اصلاح کنید.");
    return !invalid;
  }

  $form.on("input change", "input, select, textarea", function () {
    var $control = $(this).removeAttr("aria-invalid").removeClass("sg-field__input--invalid");
    String($control.attr("aria-describedby") || "").split(/\s+/).forEach(function (id) {
      var error = document.getElementById(id);
      if (error && error.classList.contains("sg-field__error")) $(error).text("").hide();
    });
  });

  updateSendStage();

});

// =====================================================================
// Salam Gate — order parcel modal (order screens and the real Basalam menu picker)
// =====================================================================
jQuery(function ($) {
  var $box = $(".salam-gate-order-box");
  if (!$box.length) return;

  var orderId = String($box.data("order-id") || "");
  var $modal = $("#salam-gate-parcel-modal");
  var orderModalTrigger = null;
  var menuOrderHost = $box.is("[data-sg-menu-order-host]");
  var listOrderHost = $box.is("[data-sg-order-list-host]");
  var listOrderIdentity = null;
  var menuOrderIdentity = null, orderTerminalLocks = Object.create(null);
  if ((!orderId && !menuOrderHost && !listOrderHost) || !$modal.length) return;
  var orderParcelId = null, orderMarket = "website", orderEpoch = 0;
  var selectedBasalamParcelId = null, orderReadOnly = false;
  var orderBusy = "", orderLocked = false, orderFormReady = false;
  var orderSelectedQuote = null, orderQuotes = null, orderTerminalMessage = "";
  var orderStage = "form", orderInformationDirty = false, orderFingerprint = "", orderFilling = false;
  var orderItemsEditable = false, orderItemWeightsEditable = false, orderPendingRemoval = null, orderDisplay = {}, orderReadonlyItems = [];
  var orderConfirmedWeight = "";
  var orderLoadError = "", orderOpenMode = "edit";
  var orderSuspended = null;
  var orderSuspendedReviews = Object.create(null);

  var tree = [];
  try {
    tree = JSON.parse($("#salam-gate-locations-tree").text() || "[]");
  } catch (e) {
    tree = [];
  }

  function sgOrderPost(action, data) {
    // Pin the actual native parcel at dispatch; caller form data cannot replace it.
    var identity = sgOrderContext();
    var payload = $.extend({}, data || {}, { _wpnonce: basalam_ajax.nonce, action: action, order_id: identity.order });
    if (identity.native !== null) payload.basalam_parcel_id = identity.native;
    else delete payload.basalam_parcel_id;
    var request = window.WoosalamGateRequests ? window.WoosalamGateRequests.post : $.post;
    return request(basalam_ajax.ajaxurl, payload);
  }

  function sgOrderContext() {
    return { order: orderId, native: selectedBasalamParcelId, epoch: orderEpoch };
  }
  function sgCurrentOrderContext(context) {
    return context.order === orderId && context.native === selectedBasalamParcelId && context.epoch === orderEpoch;
  }
  function sgOrderKey(order, native) { return String(order) + ":" + (native === null ? "website" : String(native)); }
  function sgFinancialBusy() { return ["save", "select", "pay"].indexOf(orderBusy) !== -1; }
  function sgRememberTerminal() {
    if (orderLocked) orderTerminalLocks[sgOrderKey(orderId, selectedBasalamParcelId)] = orderTerminalMessage
      || "وضعیت این مرسوله را بررسی کنید؛ ثبت یا پرداخت مجدد انجام ندهید.";
  }
  function sgSelectNativeParcel(order, native) {
    if (sgFinancialBusy()) return false;
    var changed = String(order) !== orderId || native !== selectedBasalamParcelId;
    if (changed) {
      sgRememberTerminal();
      sgPauseLabelJob(sgOrderContext());
      orderId = String(order); selectedBasalamParcelId = native;
      ++orderEpoch; clearTimeout(sgLabelPollTimer);
      orderBusy = ""; orderParcelId = null; orderReadOnly = false;
      orderLocked = false; orderTerminalMessage = "";
      orderMarket = native !== null ? "basalam" : "website";
      orderFormReady = false; orderSelectedQuote = null; orderQuotes = null;
      orderConfirmedWeight = ""; orderDisplay = {}; orderReadonlyItems = [];
      $modal.find(".sg-f-name, .sg-f-mobile, .sg-f-address, .sg-f-postal, .sg-f-value, .sg-f-weight").val("");
      $modal.find(".sg-f-items, .sg-quotes-cards, [data-sg-order-review-summary]").empty();
      $modal.find("[data-sg-order-receipt-note]").remove();
    }
    var remembered = orderTerminalLocks[sgOrderKey(orderId, selectedBasalamParcelId)];
    if (remembered) { orderLocked = true; orderTerminalMessage = remembered; }
    return true;
  }

  $(document).on("salamgate:menu-order-open", function (event, selection) {
    var source = $("[data-send-markets] input[name='market']:checked").val();
    if (!menuOrderHost || orderBusy || $modal.css("display") !== "none"
      || !$.isPlainObject(selection) || !Number.isSafeInteger(selection.order_id) || selection.order_id <= 0) return;
    var native = null;
    if (source === "basalam") {
      if (selection.market && selection.market !== "basalam"
        || !Number.isSafeInteger(selection.basalam_order_id) || selection.basalam_order_id <= 0
        || !Number.isSafeInteger(selection.basalam_parcel_id) || selection.basalam_parcel_id <= 0) return;
      native = selection.basalam_parcel_id;
    } else if (source !== "website" || selection.market !== "website"
      || selection.basalam_order_id != null || selection.basalam_parcel_id != null) return;
    var nextId = String(selection.order_id);
    if (!sgSelectNativeParcel(nextId, native)) return;
    menuOrderIdentity = { market: source, order: nextId, invoice: selection.basalam_order_id || null, parcel: native };
    orderModalTrigger = selection.trigger && selection.trigger.nodeType === 1 ? selection.trigger : null;
    $box.attr("data-order-id", orderId).data("order-id", orderId).empty().prop("hidden", false).show();
    if (orderTerminalLocks[sgOrderKey(orderId, selectedBasalamParcelId)]) {
      orderLocked = true;
      orderTerminalMessage = orderTerminalLocks[sgOrderKey(orderId, selectedBasalamParcelId)];
      sgShowSidebarTerminal();
      sgRefreshMetabox();
      return;
    }
    sgOpenModal("edit");
    sgRefreshMetabox();
  });

  // The list adapter gets its mode/market from the guarded, freshly read order.
  // A Basalam parent first discovers exact native children; a confirmed single
  // child opens directly, while multiple/incomplete results retain the chooser.
  $(document).on("salamgate:list-order-open", function (event, selection) {
    if (!listOrderHost || orderBusy || $modal.css("display") !== "none"
      || !$.isPlainObject(selection) || !Number.isSafeInteger(selection.order_id) || selection.order_id <= 0
      || ["website", "basalam"].indexOf(selection.market) === -1
      || ["edit", "payment", "native", "receipt"].indexOf(selection.mode) === -1
      || (selection.market === "basalam" && selection.mode !== "native")
      || (selection.market === "website" && selection.mode === "native")) return;
    if (!sgSelectNativeParcel(String(selection.order_id), null)) return;
    listOrderIdentity = { order: orderId, market: selection.market };
    orderMarket = selection.market;
    orderModalTrigger = selection.trigger && selection.trigger.nodeType === 1 ? selection.trigger : null;
    if (selection.mode === "native") {
      if (window.SalamGateOrderParcels) window.SalamGateOrderParcels.mount($box[0]);
      if (window.SalamGateOrderSourceStatus) window.SalamGateOrderSourceStatus.mount($box[0]);
      sgUpdateControls();
      $(document).trigger("salamgate:list-native-show");
    } else if (orderLocked && selection.mode !== "receipt") {
      sgOrderNotify(orderTerminalMessage || "وضعیت پرداخت قبلی را از صفحه سفارش بررسی کنید.");
    } else sgOpenModal(selection.mode);
  });

  function sgOrderNotify(message, kind) {
    if (typeof window.woosalamNotify === "function") {
      window.woosalamNotify(message, kind || "error");
    } else {
      sgBanner(message);
    }
  }

  function sgReceiverEditor(open) {
    var expanded = !orderReadOnly && !!open;
    $modal.find(".sg-order-receiver-fields").toggle(expanded);
    $modal.find(".sg-order-receiver-summary").prop("hidden", expanded).toggle(!expanded);
    $modal.find(".sg-order-receiver-edit").prop("hidden", orderReadOnly).toggle(!orderReadOnly)
      .attr("aria-expanded", String(expanded)).text(sgReceiverButtonLabel(expanded));
  }

  function sgReceiverButtonLabel(expanded) {
    if (orderReadOnly) return "";
    return expanded ? "بستن ویرایش گیرنده" : (sgReceiverComplete() ? "ویرایش گیرنده" : "تکمیل اطلاعات گیرنده");
  }

  function sgReceiverComplete() {
    return $.trim($modal.find(".sg-f-name").val() || "").length >= 3
      && /^09\d{9}$/.test(sgRawMoney($modal.find(".sg-f-mobile").val()).trim())
      && /^\d{10}$/.test(sgRawMoney($modal.find(".sg-f-postal").val()).trim())
      && $.trim($modal.find(".sg-f-address").val() || "").length >= 10
      && !!$modal.find(".sg-f-city").val();
  }

  function sgReceiverSummary() {
    $.each(["name", "mobile", "address", "postal"], function (_, field) {
      $modal.find('[data-sg-order-summary="' + field + '"]').text($modal.find(".sg-f-" + field).val() || "نیاز به تکمیل");
    });
    var province = $modal.find(".sg-f-province").val();
    var city = $modal.find(".sg-f-city").val() ? $modal.find(".sg-f-city option:selected").text() : "";
    $modal.find('[data-sg-order-summary="destination"]').text([province, city].filter(Boolean).join(" — ") || "نیاز به تکمیل");
  }

  function sgClearPaymentError() {
    $modal.find("[data-sg-order-wallet-topup]").remove();
    $modal.find("[data-sg-order-modal-payment-feedback]").hide().empty().removeClass("sg-feedback-actions sg-feedback-toast-only");
  }

  function sgShowSidebarTerminal() {
    if (!orderTerminalMessage) return;
    var $feedback = $box.find("[data-sg-order-payment-feedback]");
    if (!$feedback.length) $feedback = $("<div class='sg-order-payment-feedback' data-sg-order-payment-feedback role='alert'>").appendTo($box);
    $feedback.empty().append($("<p>").text(orderTerminalMessage)).show()
      .toggleClass("sg-feedback-toast-only", typeof window.woosalamNotify === "function")
      .attr("role", typeof window.woosalamNotify === "function" ? "group" : "alert");
    if (orderSuspendedReviews[sgOrderKey(orderId, selectedBasalamParcelId)]) {
      $feedback.append($("<button type='button' class='button' data-sg-order-box-read-retry>").text("بررسی وضعیت مرسوله"))
        .removeClass("sg-feedback-toast-only").toggleClass("sg-feedback-actions", typeof window.woosalamNotify === "function");
    }
  }

  function sgPaymentError(data, dispatchedMethod, durable, kind) {
    $modal.find("[data-sg-order-wallet-topup]").remove();
    var feedbackStage = orderStage === "form" ? "form" : orderStage === "confirm" ? "confirm" : "quotes";
    var $feedback = $modal.find("[data-sg-order-modal-payment-feedback]").prependTo($modal.find('[data-stage="' + feedbackStage + '"]'));
    var message = String(data.message || "خطا در پرداخت مرسوله.").replace(/بسالم/g, "باسلام");
    var paymentReview = ["pay_unknown", "unknown"].indexOf(data.code) !== -1 || ["pending", "unknown"].indexOf(data.payment_state) !== -1
      || message.indexOf("تأیید پرداخت هنوز دریافت نشده") !== -1
      || (/پرداخت/.test(message) && /نامعلوم|نامشخص|مشخص نیست|قطعی نیست/.test(message));
    if (paymentReview) message = "در حال بررسی پرداخت؛ تأیید پرداخت هنوز دریافت نشده؛ وضعیت را بررسی کنید. دوباره پرداخت نکنید.";
    $feedback.empty().append($("<p>").text(message));
    var walletInsufficient = data.code !== "pay_unknown" && data.code !== "already_paid"
      && dispatchedMethod === "21" && data.kind === "validation"
      && Array.isArray(data.errors) && data.errors.some(function (error) { return error && error.location === "user_wallet"; });
    if (walletInsufficient) {
      $modal.find('[data-stage="confirm"] .sg-order-form-actions').prepend($("<a class='basalam-secondary-button sg-payment-topup' data-sg-order-wallet-topup>").attr({
        href: "https://wallet.basalam.com/?from=vendor", target: "_blank", rel: "noopener noreferrer"
      }).append($("<span class='dashicons dashicons-money-alt' aria-hidden='true'>"), $("<span>").text("شارژ کیف پول")));
    }
    if (paymentReview) $feedback.append($("<a class='sg-order-payment-review'>").attr({
      href: "admin.php?page=salam_gate_parcels", target: "_blank", rel: "noopener noreferrer"
    }).text("بررسی وضعیت مرسوله"));
    $feedback.show();
    if (typeof window.woosalamNotify === "function") {
      $feedback.attr("role", "group").removeAttr("tabindex")
        .toggleClass("sg-feedback-actions", paymentReview).toggleClass("sg-feedback-toast-only", !paymentReview);
      sgOrderNotify(message, kind || "error");
    } else $feedback.attr("tabindex", "-1").attr("role", "alert").trigger("focus");
    if (durable) {
      orderTerminalMessage = message;
      if (paymentReview) $modal.find(".salam-gate-select-quote").text("در حال بررسی");
    }
  }

  function sgRefreshBalances(force) {
    if (!orderFormReady || orderMarket !== "website") return;
    var presentation = window.SalamGatePaymentPresentation;
    if (!presentation) return;
    if (force && typeof presentation.refreshBalance === "function") presentation.refreshBalance($modal[0]);
    else if (typeof presentation.refresh === "function") presentation.refresh($modal[0]);
    if (typeof presentation.whenReady === "function") return presentation.whenReady($modal[0]);
  }

  function sgValidQuote(quote) {
    return $.isPlainObject(quote) && typeof quote.hash_id === "string" && quote.hash_id.trim() !== ""
      && !quote.error && Number.isSafeInteger(quote.total_rial) && quote.total_rial >= 0;
  }

  function sgCurrentWeight() {
    return sgRawMoney($modal.find(".sg-f-weight").val()).trim();
  }

  function sgWeightConfirmed() {
    var weight = sgCurrentWeight(), number = Number(weight);
    return weight !== "" && Number.isSafeInteger(number) && number >= 50 && number <= 30000 && orderConfirmedWeight === weight;
  }

  function sgWeightReview() {
    var weight = Number(sgCurrentWeight()), review = orderDisplay.weight_confirmation || {};
    var label = Number.isSafeInteger(weight) && weight > 0
      ? "وزن بسته با بسته‌بندی (" + weight.toLocaleString("fa-IR").replace(/٬/g, ",") + " گرم) را بررسی کرده‌ام و تأیید می‌کنم."
      : "وزن بسته با بسته‌بندی را وارد و تأیید کنید.";
    $modal.find("[data-sg-order-weight-confirm-label]").text(label);
    $modal.find("[data-sg-order-confirm-weight]").prop("checked", sgWeightConfirmed());
    var message = typeof review.message === "string" && review.message.trim() ? review.message
      : review.has_missing_item_weights ? "وزن بعضی کالاها ثبت نشده است؛ وزن پیشنهادی را با وزن واقعی بسته بررسی و در صورت نیاز ویرایش کنید."
        : "وزن نهایی باید شامل کالاها و بسته‌بندی باشد؛ پیش از ارسال آن را بررسی کنید.";
    if (message === "وزن ذخیره‌شده مرسوله نمایش داده شده است؛ آن را با وزن واقعی بسته بررسی و در صورت نیاز ویرایش کنید.") message = "";
    $modal.find("[data-sg-order-weight-message]").text(orderReadOnly ? "" : message).prop("hidden", orderReadOnly || !message);
    $modal.find("[data-sg-order-weight-confirmation]").toggleClass("is-confirmed", sgWeightConfirmed());
  }

  function sgRevokeWeightConfirmation() {
    orderConfirmedWeight = "";
    $modal.find("[data-sg-order-weight-error]").text("").prop("hidden", true);
    sgWeightReview();
  }

  function sgRequireWeightConfirmation() {
    if (sgWeightConfirmed()) return true;
    var $stage = $modal.find('[data-stage="' + orderStage + '"]');
    var message = "وزن بسته را بررسی کنید و برای ادامه تأیید وزن را بزنید.";
    $stage.find("[data-sg-order-weight-error]").text(message).prop("hidden", false)
      .toggleClass("sg-feedback-toast-only", typeof window.woosalamNotify === "function");
    sgOrderNotify(message, "error");
    $stage.find("[data-sg-order-confirm-weight]").trigger("focus");
    return false;
  }

  function sgUpdateControls() {
    $modal.attr("data-sg-payable-rial", sgValidQuote(orderSelectedQuote) ? orderSelectedQuote.total_rial : "");
    var locked = !!orderBusy || orderLocked;
    sgRememberTerminal();
    sgWeightReview();
    $modal.toggleClass("is-receipt", orderReadOnly);
    if (orderReadOnly) sgReceiverEditor(false);
    else $modal.find(".sg-order-receiver-edit").prop("hidden", false).show();
    $modal.find(".sg-order-modal-heading .basalam-modal-title").text(orderReadOnly ? "جزئیات مرسوله" : "ارسال با سلام‌گیت");
    $modal.find("[data-sg-order-weight-confirmation]").prop("hidden", orderReadOnly);
    $modal.find('[data-stage="form"] > .sg-order-form-actions').toggle(!orderReadOnly);
    $modal.find(".salam-gate-get-rates").prop("disabled", locked || !orderFormReady || !sgWeightConfirmed())
      .text(orderBusy === "save" ? "در حال دریافت نرخ‌های ارسال…" : "مرحله بعد");
    $modal.find(".salam-gate-select-quote").prop("disabled", locked || !sgValidQuote(orderSelectedQuote) || !sgWeightConfirmed())
      .text(orderBusy === "pay" ? "در حال پرداخت…" : (orderBusy === "select" ? "در حال تأیید روش ارسال…"
        : orderLocked && orderTerminalMessage.indexOf("تأیید پرداخت هنوز دریافت نشده") !== -1 ? "در حال بررسی"
          : orderMarket === "basalam" ? "پرداخت از مبلغ سفارش" : "تأیید و پرداخت هزینه ارسال"));
    $modal.find("[data-sg-order-review]").prop("disabled", locked || orderInformationDirty || !sgValidQuote(orderSelectedQuote));
    $modal.find(".sg-pay-method, .sg-payment-card input, .salam-gate-back-form, [data-sg-order-back-quotes]").prop("disabled", locked);
    $modal.find("[data-sg-order-confirm-weight]").prop("disabled", locked);
    $modal.find("[data-stage='form'] input, [data-stage='form'] textarea, [data-stage='form'] select").prop("disabled", locked);
    // Receipt controls are read-only provider actions, not editable shipping
    // fields. Keep the allowed label-template selector usable in a receipt.
    $modal.find("[data-sg-order-receipt-note] .salam-gate-label-template").prop("disabled", !!orderBusy);
    $modal.find(".sg-f-value").prop("readOnly", false)
      .attr({ min: "2000", max: orderMarket === "basalam" ? "500000000" : "1000000000",
        step: orderMarket === "basalam" ? "0.1" : "1", "data-sg-integer": orderMarket === "basalam" ? "0" : "1" });
    if (orderMarket === "basalam") $modal.find(".sg-f-value").attr("data-sg-decimal-places", "1");
    else $modal.find(".sg-f-value").removeAttr("data-sg-decimal-places");
    $modal.find(".sg-f-value-label").text(orderMarket === "basalam" ? "ارزش اعلامی مرسوله (تومان)" : "ارزش مرسوله (تومان)");
    $modal.find(".sg-dims-inputs input").attr({ min: orderMarket === "basalam" ? "10" : "1", max: orderMarket === "basalam" ? "100" : "2147483647" });
    $modal.find("[data-sg-order-value-hint]").prop("hidden", orderMarket !== "basalam");
    $modal.find(".sg-order-receiver-edit").prop("hidden", orderReadOnly).prop("disabled", !!orderBusy || (locked && !orderReadOnly))
      .text(sgReceiverButtonLabel($modal.find(".sg-order-receiver-edit").attr("aria-expanded") === "true"));
    $modal.find(".sg-f-non-standard").prop("disabled", locked || orderMarket === "basalam")
      .closest(".basalam-form-row").toggle(orderMarket === "website");
    if (orderMarket === "basalam") $modal.find(".sg-proposed-price-row").hide();
    $modal.find(".sg-f-items input, .sg-f-items button, [data-sg-order-add-item]").prop("disabled", locked || !orderItemsEditable);
    if (!orderItemsEditable && orderItemWeightsEditable) $modal.find(".sg-item-weight").prop("disabled", locked);
    $modal.find("[data-sg-order-add-item]").toggle(orderItemsEditable);
    $modal.find("[data-sg-order-step]").each(function () {
      var number = Number($(this).attr("data-sg-order-step"));
      var active = number === ({ form: 1, quotes: 2, confirm: 3 }[orderStage]);
      var available = number === 1 || (number === 2 && orderQuotes && !orderInformationDirty) || (number === 3 && sgValidQuote(orderSelectedQuote) && !orderInformationDirty);
      $(this).toggleClass("is-active", active).toggleClass("is-done", number < ({ form: 1, quotes: 2, confirm: 3 }[orderStage]));
      $(this).find("button").prop("disabled", locked || !available).attr("aria-current", active ? "step" : "false");
    });
    $modal.find(".sg-quote-card").attr("aria-disabled", String(locked));
    $modal.find(".salam-gate-modal__close").prop("disabled", ["save", "select", "pay"].indexOf(orderBusy) !== -1);
    $modal.find(".salam-gate-get-rates, [data-sg-order-step]").toggle(!orderReadOnly);
    $box.attr("data-sg-order-financial-busy", sgFinancialBusy() ? "1" : "0");
    $modal.attr("data-sg-financial-busy", sgFinancialBusy() ? "true" : "false");
    if (window.SalamGateOrderParcels) window.SalamGateOrderParcels.syncBusy($box[0]);
    $box.find(".salam-gate-pay, .salam-gate-open-modal").each(function () {
      var $button = $(this), rawNative = $button.attr("data-basalam-parcel-id");
      var native = rawNative && /^[1-9]\d*$/.test(rawNative) && Number.isSafeInteger(Number(rawNative)) ? Number(rawNative) : null;
      var receipt = $button.attr("data-sg-native-receipt") === "1";
      var sourceBlocked = $box.attr("data-basalam-order") === "1" && $box.attr("data-sg-source-blocked") === "1" && !receipt;
      if (native === null) { $button.prop("disabled", locked || sourceBlocked); return; }
      var blocked = $button.attr("data-sg-native-blocked") === "1";
      $button.prop("disabled", sgFinancialBusy() || sourceBlocked || blocked || (!receipt && !!orderTerminalLocks[sgOrderKey(orderId, native)]));
    });
    $modal.find("[data-sg-order-selected-service]").text(orderSelectedQuote
      ? (orderSelectedQuote.service_name || orderSelectedQuote.service_type_label || "سرویس پستی") : "شیوه ارسال را انتخاب کنید");
    var selectedFare = sgDiscountedFare(orderSelectedQuote);
    $modal.find("[data-sg-order-selected-cost]").text(selectedFare !== null ? sgCostMoney(selectedFare) : "—");
    $modal.find(".sg-payment-method-fieldset").toggle(orderMarket === "website" && sgValidQuote(orderSelectedQuote));
    $modal.find("[data-sg-order-basalam-funding]").prop("hidden", orderMarket !== "basalam" || !sgValidQuote(orderSelectedQuote));
    $modal.find("[data-sg-order-confirm-payment-hint]").text(orderMarket === "basalam"
      ? "مشخصات، وزن و هزینه ارسال را بررسی کنید. با تأیید نهایی، هزینه از مبلغی که مشتری برای همین سفارش باسلام پرداخت کرده کسر می‌شود؛ نیازی به پرداخت در درگاه بانکی نیست."
      : "مشخصات و مبلغ را بررسی کنید. با تأیید نهایی، هزینه ارسال از روش انتخاب‌شده کسر می‌شود.");
    $modal.find("[data-sg-order-cost-breakdown]").each(function () { sgRenderCostBreakdown($(this), orderSelectedQuote); });
    sgRenderOrderLoading();
    sgRefreshBalances(false);
  }

  $(document).on("salamgate:order-parcels-rendered", function (event, box) {
    if (box === $box[0]) sgUpdateControls();
  });

  function sgRenderOrderLoading() {
    var loading = !!orderBusy || !orderFormReady;
    var messages = { prepare: "در حال دریافت اطلاعات…", quotes: "در حال دریافت اطلاعات…", balances: "در حال دریافت اطلاعات…",
      save: "در حال ثبت مرسوله…", select: "در حال تأیید روش ارسال…",
      pay: "در حال پرداخت؛ دوباره پرداخت نکنید." };
    $modal.find("[data-sg-order-content]").prop("hidden", loading);
    $modal.find("[data-sg-order-loading]").prop("hidden", !loading).toggleClass("has-error", !!orderLoadError);
    $modal.find("[data-sg-order-loading-message]").text(orderLoadError || messages[orderBusy] || messages.prepare)
      .toggleClass("sg-feedback-toast-only", !!orderLoadError && typeof window.woosalamNotify === "function");
    $modal.find("[data-sg-order-loading-retry]").prop("hidden", !orderLoadError || !!orderBusy || (orderLocked && !orderReadOnly));
    $modal.find(".salam-gate-modal").attr("aria-busy", String(!!orderBusy));
  }

  function sgOrderPrepareFailed(message) {
    orderLoadError = message;
    sgSetBusy("");
    $modal.find("[data-sg-order-loading-message]").toggleClass("sg-feedback-toast-only", typeof window.woosalamNotify === "function");
    sgOrderNotify(message, "error");
  }

  function sgSetBusy(value) {
    orderBusy = value;
    sgUpdateControls();
  }

  function sgBanner(message) {
    if (typeof window.woosalamNotify === "function") {
      $modal.find(".salam-gate-modal__banner").hide().empty();
      window.woosalamNotify(message, "error");
      return;
    }
    $modal.find(".salam-gate-modal__banner").text(message).show();
  }

  function sgClearNotices() {
    $modal.find(".salam-gate-modal__banner, .salam-gate-modal__warn").hide().empty();
  }

  function sgFillProvinces(selected) {
    var $select = $modal.find(".sg-f-province").empty();
    $select.append($("<option>").val("").text("انتخاب استان"));
    $.each(tree, function (i, province) {
      $select.append($("<option>").val(province.title).text(province.title));
    });
    if (selected) $select.val(selected);
  }

  function sgFillCities(provinceTitle, selectedCityId) {
    var $select = $modal.find(".sg-f-city").empty();
    $select.append($("<option>").val("").text("انتخاب شهر"));
    var cities = [];
    $.each(tree, function (i, province) {
      if (province.title === provinceTitle) cities = province.children || [];
    });
    $.each(cities, function (i, city) {
      $select.append($("<option>").val(String(city.id)).text(city.title));
    });
    if (selectedCityId) $select.val(String(selectedCityId));
  }

  function sgStage(name) {
    orderStage = name;
    $modal.toggleClass("is-confirm-stage", name === "confirm");
    $modal.find(".salam-gate-modal__stage").hide();
    $modal.find('[data-stage="' + name + '"]').show();
    sgUpdateControls();
  }

  function sgFillForm(payload, canonicalSnapshot) {
    var previousConfirmedWeight = canonicalSnapshot ? orderConfirmedWeight : "";
    orderFilling = true;
    if (window.SalamGateValidation) window.SalamGateValidation.reset($modal);
    var receiver = payload.receiver || {};
    var parcel = payload.parcel || {};
    var display = payload._display || {};
    $modal.find("[data-sg-order-source-note]").remove();
    if (typeof display.shipping_blocked_reason === "string" && display.shipping_blocked_reason.trim()) {
      $("<p class='sg-order-modal-source-note' data-sg-order-source-note role='status'>")
        .text(display.shipping_blocked_reason + " این پنجره فقط برای مشاهده اطلاعات قبلی است.")
        .prependTo($modal.find('[data-stage="form"]'));
    }
    orderDisplay = $.extend(true, {}, display);
    var customerShippingMethod = typeof display.customer_shipping_method === "string" ? display.customer_shipping_method.trim() : "";
    $modal.find("[data-sg-order-customer-shipping-method]").text(customerShippingMethod);
    $modal.find("[data-sg-order-shipping-preference]").prop("hidden", !customerShippingMethod);
    orderMarket = display.market === "website" ? "website" : "basalam";
    // The order is the source of its items. Only recipient and parcel details
    // belong to this shipping form, including when an unpaid draft is reopened.
    orderItemsEditable = false;
    orderItemWeightsEditable = false;
    orderReadonlyItems = (payload.items || []).map(function (item) { return $.extend({}, item); });
    var $sender = $modal.find("[data-sg-order-sender]").empty(), sender = display.sender || {};
    $.each([["نام فرستنده", sender.name], ["شهر", sender.city], ["موبایل", sender.mobile],
      ["کد پستی", sender.postal_code], ["نشانی", sender.address], ["مرکز تحویل", sender.center]], function (_, row) {
      if (row[0] === "مرکز تحویل" && !row[1]) return;
      $sender.append($("<div>").toggleClass("sg-order-sender-details__wide", row[0] === "نشانی" || row[0] === "مرکز تحویل")
        .append($("<dt>").text(row[0]), $("<dd>").text(row[1] || "—")));
    });
    $modal.find("[data-sg-order-value-hint]").text(display.goods_value_needs_review === true
      ? "ارزش پیشنهادی فقط مربوط به اقلام این بسته است؛ پیش از ادامه بررسی کنید."
      : "ارزش اعلامی مرسوله برای بیمه و هزینه ارسال است؛ مقدار پیش‌فرض با تخفیف کالای غرفه محاسبه می‌شود و قابل ویرایش است. قیمت کالاهای سفارش تغییر نمی‌کند.");
    $modal.find("[data-sg-order-receiver-hint]").text(orderMarket === "basalam"
      ? "مشخصات گیرندهٔ بستهٔ انتخاب‌شده در باسلام؛ در صورت نیاز آدرس گیرنده را ویرایش کنید."
      : "مشخصات گیرنده سفارش را بررسی کنید.");
    var $category = $modal.find(".sg-f-category").empty();
    $.each(display.categories || [], function (_, category) { $category.append($("<option>").val(category.id).text(category.title)); });
    var categoryId = payload.category_id || parcel.category_id;
    if (categoryId && !$category.find("option").filter(function () { return String(this.value) === String(categoryId); }).length) $category.append($("<option>").val(categoryId).text(display.category_title || "نوع مرسوله ثبت‌شده"));
    if (categoryId) $category.val(String(categoryId));

    $modal.find(".sg-f-name").val(receiver.name || "");
    $modal.find(".sg-f-mobile").val(receiver.mobile || "");
    $modal.find(".sg-f-address").val(receiver.address || "");
    $modal.find(".sg-f-postal").val(receiver.postal_code || "");
    $modal.find(".sg-f-weight").val(parcel.weight || (display.weight_confirmation && display.weight_confirmation.weight_grams) || "");
    orderConfirmedWeight = previousConfirmedWeight === sgCurrentWeight() ? previousConfirmedWeight : "";
    $modal.find(".sg-f-value").val(display.value_toman != null ? display.value_toman : "");
    if (canonicalSnapshot && $modal.find(".sg-f-non-standard").is(":checked")) {
      $modal.find(".sg-f-proposed-price").val(display.value_toman != null ? display.value_toman : "");
    } else if (!canonicalSnapshot) {
      $modal.find(".sg-f-non-standard").prop("checked", false).trigger("change");
    }
    $modal.find(".sg-f-liquid").prop("checked", !!parcel.is_liquid);
    $modal.find(".sg-f-fragile").prop("checked", !!parcel.is_fragile);

    // محتویات مرسوله از عنوان اقلام
    var contents = "";
    var titles = [];
    $.each(payload.items || [], function (i, item) { if (item.title) titles.push(item.title); });
    if (titles.length) contents = titles.join("، ");
    $modal.find(".sg-f-contents").val(contents);

    $modal
      .find(".sg-f-box")
      .removeData("sg-normal-box")
      .val(String((payload.packaging && payload.packaging.box_type_id) || ""))
      .trigger("change");

    var dimensions = (payload.packaging && payload.packaging.dimensions) || {};
    $modal.find(".sg-f-dim-length").val(dimensions.length || "");
    $modal.find(".sg-f-dim-width").val(dimensions.width || "");
    $modal.find(".sg-f-dim-height").val(dimensions.height || "");

    var $items = $modal.find(".sg-f-items").empty();
    $modal.find(".sg-items-locked-note").remove();
    $.each(payload.items || [], function (_, item) { sgAddOrderItem(item, display.product_links || {}); });
    if (!$items.children().length && orderItemsEditable) sgAddOrderItem({ quantity: 1 });
    if (!orderItemsEditable) $("<p class='sg-items-locked-note'>").text(orderMarket === "basalam"
      ? "فقط اقلام بستهٔ انتخاب‌شده در باسلام نمایش داده می‌شوند و در فرم ارسال قابل تغییر نیستند."
      : "اقلام از سفارش فروشگاه خوانده می‌شوند و در فرم ارسال قابل تغییر نیستند.").insertAfter($items.closest("table"));

    var cityPath = display.city_path || null;
    sgFillProvinces(cityPath ? cityPath.province : null);
    sgFillCities(cityPath ? cityPath.province : null, receiver.city_id || null);
    sgReceiverSummary();
    sgReceiverEditor(false);

    // روش پرداخت فقط برای بازار وب‌سایت
    var isWebsite = display.market === "website";
    orderMarket = isWebsite ? "website" : "basalam";
    $modal.find(".sg-payment-method-fieldset").toggle(!!isWebsite);
    orderInformationDirty = false;
    orderFilling = false;
    orderFingerprint = sgInformationFingerprint();
    $modal.find("[data-sg-order-edit-warning]").prop("hidden", true);
    sgUpdateControls();
  }

  var orderItemSequence = 0;
  function sgAddOrderItem(item, links) {
    var itemId = Object.prototype.hasOwnProperty.call(item, "_order_item_id") ? item._order_item_id : (item.external_id || 0);
    var $row = $("<tr>").data("item-id", itemId || 0);
    function itemInput(label, key, value, type, minimum) {
      var id = "sg-order-item-" + (++orderItemSequence) + "-" + key;
      var $input = $("<input>").attr({ id: id, type: type || "text", form: "", "aria-label": label,
        "data-sg-validate": "", "data-sg-label": label }).addClass("sg-order-item-" + key);
      if (type === "number") $input.attr({ min: minimum, step: "1", max: key === "price" ? "1000000000" : "2147483647", "data-sg-integer": "1" });
      if (key !== "price") $input.prop("required", true);
      if (key === "title") $input.addClass("sg-send-item-title").attr("placeholder", "عنوان قلم");
      if (key === "quantity") $input.addClass("sg-send-item-qty");
      if (key === "weight") $input.addClass("sg-item-weight sg-send-item-weight").attr("data-sg-unit", "گرم").data("item-id", $row.data("item-id"));
      if (key === "price") $input.addClass("sg-send-item-price").attr("data-sg-unit", "تومان");
      return $("<td>").attr("data-label", label).append($input.val(value == null ? "" : value).prop("disabled", !orderItemsEditable && !(key === "weight" && orderItemWeightsEditable)));
    }
    var price = item.price_toman != null ? item.price_toman : (Number.isFinite(item.price) ? item.price / 10 : "");
    var $title = itemInput("عنوان", "title", item.title), productUrl = (links || {})[String(item._order_item_id || "")];
    if (!orderItemsEditable) $title = $("<td data-label='عنوان'>").text(item.title || "—");
    if (typeof productUrl === "string") {
      try {
        var url = new URL(productUrl);
        if (["http:", "https:"].indexOf(url.protocol) !== -1 && !url.username && !url.password && url.origin === window.location.origin) {
          var $link = $("<a class='sg-order-product-link'>").attr({ href: url.href, target: "_blank", rel: "noopener noreferrer" }).text(orderItemsEditable ? "مشاهده محصول ↗" : item.title);
          if (!orderItemsEditable) $title.empty();
          $title.append($link);
        }
      } catch (error) { /* Product title remains usable when no safe editor URL exists. */ }
    }
    $row.append($title, itemInput("تعداد", "quantity", item.quantity == null ? 1 : item.quantity, "number", 1),
      itemInput("وزن خالص (گرم)", "weight", item.net_weight, "number", 1), itemInput("قیمت (تومان)", "price", price, "number", 1000),
      $("<td data-label='عملیات'>").append($("<button type='button' data-sg-order-remove-item aria-label='حذف قلم'>").text("×").prop("disabled", !orderItemsEditable).toggle(orderItemsEditable)));
    $modal.find(".sg-f-items").append($row);
    sgItemPlaceholderRequirements();
    return $row;
  }

  function sgEmptyFirstItem($row) {
    return $row[0] === $modal.find(".sg-f-items tr").first()[0] && !$.trim($row.find(".sg-order-item-title").val() || "")
      && sgRawMoney($row.find(".sg-order-item-quantity").val()) === "1" && !sgRawMoney($row.find(".sg-order-item-weight").val()) && !sgRawMoney($row.find(".sg-order-item-price").val());
  }
  function sgOrderItems() {
    return $modal.find(".sg-f-items tr").map(function () {
      var $row = $(this), title = $.trim($row.find(".sg-order-item-title").val() || "");
      if (!title && sgEmptyFirstItem($row)) return null;
      return { title: title, quantity: sgRawMoney($row.find(".sg-order-item-quantity").val()), net_weight: sgRawMoney($row.find(".sg-order-item-weight").val()),
        price_toman: sgRawMoney($row.find(".sg-order-item-price").val()), _order_item_id: Number($row.data("item-id")) || 0 };
    }).get();
  }
  function sgItemPlaceholderRequirements() {
    if (!orderItemsEditable) return;
    var $first = $modal.find(".sg-f-items tr").first();
    var otherComplete = $modal.find(".sg-f-items tr").slice(1).toArray().some(function (row) {
      return $.trim($(row).find(".sg-order-item-title").val() || "") && Number($(row).find(".sg-order-item-quantity").val()) >= 1 && Number($(row).find(".sg-order-item-weight").val()) >= 1;
    });
    $first.find(".sg-order-item-title, .sg-order-item-quantity, .sg-order-item-weight").prop("required", !(sgEmptyFirstItem($first) && otherComplete));
  }
  function sgInformationFingerprint() {
    var information = sgCollectForm();
    delete information.pay_method;
    delete information.weight_confirmed;
    delete information.confirmed_weight_grams;
    return JSON.stringify(information);
  }
  function sgInformationChanged() {
    if (orderFilling || orderBusy || orderLocked || orderStage !== "form") return;
    sgItemPlaceholderRequirements();
    var current = sgInformationFingerprint();
    if (current === orderFingerprint) return;
    orderFingerprint = current;
    if (orderParcelId) {
      orderInformationDirty = true;
      orderSelectedQuote = null;
      orderQuotes = null;
      sgClearPaymentError();
      $modal.find(".sg-quotes-cards, [data-sg-order-review-summary]").empty();
      sgUpdateControls();
    }
  }

  function sgRawMoney(value) {
    return String(value == null ? "" : value).replace(/[۰-۹]/g, function (d) { return "۰۱۲۳۴۵۶۷۸۹".indexOf(d); })
      .replace(/[٠-٩]/g, function (d) { return "٠١٢٣٤٥٦٧٨٩".indexOf(d); }).replace(/[,٬]/g, "");
  }

  function sgCanonicalOrderPayload(flat) {
    if (!$.isPlainObject(flat) || !$.isPlainObject(flat.receiver) || !Array.isArray(flat.items) || !flat.items.length) return null;
    if (!flat.items.every(function (item) { return $.isPlainObject(item) && typeof item.title === "string" && item.title.trim(); })) return null;
    return { receiver: flat.receiver, category_id: flat.category_id,
      parcel: { weight: flat.weight, is_liquid: !!flat.is_liquid, is_fragile: !!flat.is_fragile },
      packaging: { box_type_id: flat.box_type_id, dimensions: { length: flat.dimension_length, width: flat.dimension_width, height: flat.dimension_height } },
      items: flat.items, _items_editable: orderItemsEditable,
      _display: $.extend(true, {}, orderDisplay, { value_toman: flat.value_toman,
        city_path: { province: (tree.filter(function (province) { return (province.children || []).some(function (city) { return String(city.id) === String(flat.receiver.city_id); }); })[0] || {}).title || "" } }) };
  }

  function sgCanonicalMatchesReview(flat) {
    var form = sgCollectForm();
    function same(a, b) { return String(a == null ? "" : a).trim() === String(b == null ? "" : b).trim(); }
    if (!["weight", "box_type_id", "category_id"].every(function (key) { return same(form[key], flat[key]); })
      || !same(form.value_toman, flat.value_toman)
      || !["name", "mobile", "address", "postal_code", "city_id"].every(function (key) { return same(form[key], flat.receiver && flat.receiver[key]); })
      || !!form.is_liquid !== !!flat.is_liquid || !!form.is_fragile !== !!flat.is_fragile) return false;
    if (String(form.box_type_id) === "37" && !["length", "width", "height"].every(function (dimension) { return same(form["dimension_" + dimension], flat["dimension_" + dimension]); })) return false;
    var items = orderItemsEditable ? sgOrderItems() : orderReadonlyItems;
    return items.length === flat.items.length && items.every(function (item, index) {
      var remote = flat.items[index];
      return ["title", "quantity", "net_weight"].every(function (key) { return same(item[key], remote[key]); })
        && (item.price_toman == null && item.price == null
          || same(item.price_toman != null ? item.price_toman : Number(item.price) / 10, remote.price_toman));
    });
  }

  function sgCollectForm() {
    var itemWeights = {};
    if (orderItemWeightsEditable && !orderItemsEditable) $modal.find(".sg-item-weight").each(function () {
      itemWeights[String($(this).data("item-id"))] = sgRawMoney($(this).val());
    });

    var nonStandard = $modal.find(".sg-f-non-standard").is(":checked");
    var valueToman = nonStandard
      ? $modal.find(".sg-f-proposed-price").val()
      : $modal.find(".sg-f-value").val();

    var result = {
      weight: sgRawMoney($modal.find(".sg-f-weight").val()),
      weight_confirmed: sgWeightConfirmed() ? "1" : "",
      confirmed_weight_grams: sgWeightConfirmed() ? orderConfirmedWeight : "",
      value_toman: sgRawMoney(valueToman),
      contents: $modal.find(".sg-f-contents").val(),
      is_non_standard: nonStandard ? "1" : "",
      proposed_price: nonStandard ? sgRawMoney($modal.find(".sg-f-proposed-price").val()) : "",
      pay_method: $modal.find(".sg-pay-method").val() || "",
      box_type_id: $modal.find(".sg-f-box").val(),
      dimension_length: sgRawMoney($modal.find(".sg-f-dim-length").val()),
      dimension_width: sgRawMoney($modal.find(".sg-f-dim-width").val()),
      dimension_height: sgRawMoney($modal.find(".sg-f-dim-height").val()),
      is_liquid: $modal.find(".sg-f-liquid").is(":checked") ? "1" : "",
      is_fragile: $modal.find(".sg-f-fragile").is(":checked") ? "1" : "",
      name: $modal.find(".sg-f-name").val(),
      mobile: $modal.find(".sg-f-mobile").val(),
      address: $modal.find(".sg-f-address").val(),
      postal_code: $modal.find(".sg-f-postal").val(),
      city_id: $modal.find(".sg-f-city").val() || "",
      item_net_weights: orderItemsEditable ? {} : itemWeights,
      category_id: $modal.find(".sg-f-category").val() || ""
    };
    if (orderMarket === "basalam") {
      delete result.is_non_standard;
      delete result.proposed_price;
    }
    if (orderItemsEditable) result.items = sgOrderItems();
    return result;
  }

  function sgRenderQuotes(quotesData, requireNewChoice) {
    sgClearNotices();
    sgClearPaymentError();
    orderSelectedQuote = null;
    orderQuotes = null;
    var $list = $modal.find(".sg-quotes-cards").empty();
    sgStage("quotes");
    if (!$.isPlainObject(quotesData) || quotesData.parcel_id !== orderParcelId || !Array.isArray(quotesData.quotes)) {
      sgUpdateControls();
      sgPaymentError({ message: "نرخ معتبر برای این مرسوله دریافت نشد؛ به مرحله قبل برگردید و نرخ‌ها را دوباره بگیرید." });
      return false;
    }
    orderQuotes = quotesData;
    orderInformationDirty = false;
    $modal.find("[data-sg-order-edit-warning]").prop("hidden", true);
    orderFingerprint = sgInformationFingerprint();
    var quotes = quotesData.quotes;
    var selected = quotes.filter(function (quote) { return sgValidQuote(quote) && quote.selected === true; });
    if (!requireNewChoice) {
      // Original Basalam orders use the service's exact selection, not a guessed postal default.
      // Website's existing local Pishtaz default is still reviewed before any select/pay request.
      orderSelectedQuote = orderMarket === "basalam" ? (selected.length === 1 ? selected[0] : null)
        : quotes.find(function (quote) {
          return sgValidQuote(quote) && (Number(quote.service_type_id) === 1
            || (!quote.service_type_id && /پیشتاز/.test(quote.service_name || quote.service_type_label || "")));
        }) || (selected.length === 1 ? selected[0] : null);
    }

    var quoteErrors = (Array.isArray(quotesData.errors) ? quotesData.errors : []).filter(function (message, index, all) {
      return typeof message === "string" && message.trim() !== "" && all.indexOf(message) === index;
    });
    // A replaced selection can accompany fresh valid rates. It is not a failed
    // quote/card: the current rates are reviewed and verified again before pay.
    // Genuine provider failures still need feedback when no usable rate exists.
    if (quoteErrors.length && !quotes.some(sgValidQuote)) {
      sgPaymentError({ message: quoteErrors.join(" ") });
    }

    if (!quotes.length) {
      $list.append($("<p>").addClass("basalam-p").text("نرخی برای این مرسوله یافت نشد."));
    }

    $.each(quotes, function (i, quote) {
      if (!$.isPlainObject(quote)) return;
      var disabled = !sgValidQuote(quote);
      var $card = $("<div>")
        .addClass("sg-quote-card")
        .toggleClass("is-disabled", disabled)
        .toggleClass("is-selected", quote === orderSelectedQuote)
        .attr({ role: "button", tabindex: disabled ? "-1" : "0", "aria-pressed": String(quote === orderSelectedQuote) })
        .data("hash", quote.hash_id);

      if (!disabled) {
        $card.on("click", function () {
          if (orderBusy || orderLocked) return;
          sgClearPaymentError();
          orderSelectedQuote = quote;
          $list.find(".sg-quote-card").removeClass("is-selected").attr("aria-pressed", "false");
          $(this).addClass("is-selected").attr("aria-pressed", "true");
          sgUpdateControls();
        }).on("keydown", function (event) {
          if (event.key === "Enter" || event.key === " ") { event.preventDefault(); $(this).trigger("click"); }
        });
      }

      $card.append(
        $('<div class="sg-quote-card__header">').append(
          $('<span class="sg-quote-card__name">').text(quote.service_name || quote.service_type_label || "سرویس"),
          quote.discount_percent
            ? $('<span class="sg-quote-card__discount">').text(quote.discount_percent + "٪ تخفیف")
            : $()
        )
      );

      var fare = sgDiscountedFare(quote);
      $card.append($('<small class="sg-quote-card__fare-label">').text("کرایه پستی بعد از تخفیف"),
        $('<div class="sg-quote-card__price">').text(fare !== null ? sgCostMoney(fare) : "—"));

      if (quote.error) {
        $card.append($('<div class="sg-quote-card__error">').text(quote.error));
      }

      $list.append($card);
    });

    sgUpdateControls();
    var balanceRead = sgRefreshBalances(true), balanceContext = sgOrderContext();
    if (balanceRead && typeof balanceRead.always === "function" && balanceRead.state() === "pending") {
      sgSetBusy("balances");
      balanceRead.always(function () {
        if (sgCurrentOrderContext(balanceContext) && orderBusy === "balances") sgSetBusy("");
      });
    }
    return true;
  }

  function sgCostMoney(amount) {
    return Math.floor(amount).toLocaleString("fa-IR", { maximumFractionDigits: 0 }).replace(/٬/g, ",") + " تومان";
  }

  function sgDiscountedFare(quote) {
    return quote && Number.isFinite(quote.base_cost_toman) && quote.base_cost_toman >= 0 ? quote.base_cost_toman : null;
  }

  function sgCostRows(value) {
    return (Array.isArray(value) ? value : []).filter(function (row) {
      return $.isPlainObject(row) && typeof row.title === "string" && row.title.trim()
        && Number.isFinite(row.amount_toman);
    });
  }

  function sgRenderCostBreakdown($section, quote) {
    if (!sgValidQuote(quote)) {
      $section.empty().removeData("quote").prop("hidden", true);
      return;
    }
    // Avoid rebuilding open cost details or live regions on unrelated input/consent changes.
    if ($section.data("quote") === quote) return;
    $section.empty().data("quote", quote).prop("hidden", false);
    var fareOnly = $section.closest('[data-stage="quotes"]').length > 0;
    $section.append($("<h4 class='sg-order-section-title'>").text(fareOnly ? "جزئیات کرایه پستی" : "جزئیات هزینه ارسال"),
      $("<p class='sg-order-cost-service'>").text(quote.service_name || quote.service_type_label || "سرویس پستی"));
    var $rows = $("<dl class='sg-order-cost-rows'>").appendTo($section);
    function row($list, title, amount, name) {
      $list.append($("<div class='sg-order-cost-row'>").attr("data-sg-order-cost-row", name || "item")
        .toggleClass("is-discount", amount < 0).append($("<dt>").text(title), $("<dd>").text(sgCostMoney(amount))));
    }
    var fare = quote.fare_cost_toman != null ? quote.fare_cost_toman
      : quote.raw_cost_toman != null ? quote.raw_cost_toman : quote.base_cost_toman;
    if (Number.isFinite(fare) && fare >= 0) row($rows, "کرایه پستی", fare, "base");
    var hasSections = Array.isArray(quote.discount_rows) || Array.isArray(quote.additional_rows);
    $.each(sgCostRows(quote.discount_rows), function (_, item) { row($rows, item.title, item.amount_toman, "discount"); });
    if (fareOnly) {
      var discountedFare = sgDiscountedFare(quote);
      if (discountedFare !== null) row($rows, "کرایه پستی بعد از تخفیف", discountedFare, "discounted-fare");
      $section.append($("<p class='sg-order-helper'>").text("مبلغ نهایی با بیمه، مالیات و سایر هزینه‌ها در مرحله تأیید نمایش داده می‌شود."));
      return;
    }
    var additional = sgCostRows(quote.additional_rows);
    if (additional.length || (Number.isFinite(quote.additional_total_toman) && quote.additional_total_toman !== 0)) {
      var $details = $("<details class='sg-order-cost-details'>").appendTo($section);
      var $summary = $("<summary>").append($("<span>").text("سایر هزینه‌ها"));
      // Group totals are supplied by the service; never reconstruct a payable amount from line items.
      if (Number.isFinite(quote.additional_total_toman)) $summary.append($("<strong>").text(sgCostMoney(quote.additional_total_toman)));
      $details.append($summary);
      var $additionalRows = $("<dl class='sg-order-cost-rows'>").appendTo($details);
      $.each(additional, function (_, item) { row($additionalRows, item.title, item.amount_toman, "additional"); });
    }
    if (!hasSections) $.each(sgCostRows(quote.cost_rows), function (_, item) { row($rows, item.title, item.amount_toman, "legacy"); });
    $section.append($("<div class='sg-order-cost-payable' data-sg-order-cost-payable>").append(
      $("<span>").text("مبلغ نهایی قابل پرداخت"), $("<strong>").text(sgCostMoney(quote.total_rial / 10))));
  }

  function sgLoadOrderQuotes(epoch, requireNewChoice) {
    var context = sgOrderContext();
    if (epoch !== context.epoch) return;
    orderSelectedQuote = null;
    sgStage("quotes");
    $modal.find(".sg-quotes-cards").empty().append($("<p>").text("در حال دریافت نرخ‌های ارسال…"));
    sgSetBusy("quotes");
    function quoteFailure(response) {
      if (!sgCurrentOrderContext(context)) return;
      var data = response && response.success === false && $.isPlainObject(response.data) ? response.data : {};
      var terminal = ["pay_unknown", "already_paid", "update_unknown"].indexOf(data.code) !== -1;
      if (terminal) orderLocked = true;
      sgSetBusy("");
      var error = { code: data.code, message: data.message || "دریافت نرخ‌های ارسال انجام نشد؛ دوباره تلاش کنید." };
      if (terminal) sgPaymentError(error, "", true);
      else sgInvalidateQuote(error);
    }
    sgOrderPost("salam_gate_get_quotes").done(function (res) {
      if (!sgCurrentOrderContext(context)) return;
      sgSetBusy("");
      if (!res || res.success !== true || !res.data || res.data.parcel_id !== orderParcelId) {
        quoteFailure(res);
        return;
      }
      sgRenderQuotes(res.data.quotes, requireNewChoice);
    }).fail(function (xhr) {
      quoteFailure(xhr && xhr.responseJSON);
    });
  }

  function sgOpenModal(mode) {
    if (orderBusy || (orderLocked && mode !== "receipt")) return;
    var epoch = ++orderEpoch;
    var context = sgOrderContext();
    if (window.SalamGatePaymentPresentation && window.SalamGatePaymentPresentation.resetChoice) window.SalamGatePaymentPresentation.resetChoice($modal[0]);
    orderReadOnly = mode === "receipt";
    if (orderReadOnly) orderLocked = true;
    orderOpenMode = mode;
    orderLoadError = "";
    orderFormReady = false;
    orderSelectedQuote = null;
    orderQuotes = null;
    orderInformationDirty = false;
    sgRevokeWeightConfirmation();
    sgClearNotices();
    sgClearPaymentError();
    $modal.find("[data-sg-order-customer-shipping-method]").empty();
    $modal.find("[data-sg-order-shipping-preference]").prop("hidden", true);
    $modal.find("[data-sg-order-receipt-note]").remove();
    sgStage("form");
    sgSetBusy("prepare");
    $modal.show();
    $modal.find(".salam-gate-modal__close").trigger("focus");

    sgOrderPost("salam_gate_prepare_parcel")
      .done(function (res) {
        if (!sgCurrentOrderContext(context)) return;
        sgSetBusy("");
        if (!res || res.success !== true || !$.isPlainObject(res.data) || !$.isPlainObject(res.data.payload)) {
          sgOrderPrepareFailed((res && res.data && res.data.message) || "اطلاعات ارسال دریافت نشد؛ دوباره تلاش کنید.");
          return;
        }
        var display = res.data.payload._display;
        var invalidNative = selectedBasalamParcelId !== null && (!$.isPlainObject(display)
          || display.market !== "basalam" || !Number.isSafeInteger(display.external_id)
          || display.external_id !== selectedBasalamParcelId);
        var invalidMenu = menuOrderHost && (!menuOrderIdentity || menuOrderIdentity.order !== orderId
          || menuOrderIdentity.parcel !== selectedBasalamParcelId || !$.isPlainObject(display)
          || display.market !== menuOrderIdentity.market);
        var invalidList = listOrderHost && (!listOrderIdentity || listOrderIdentity.order !== orderId
          || !$.isPlainObject(display) || display.market !== listOrderIdentity.market);
        if (invalidNative || invalidMenu || invalidList) {
          orderFormReady = false;
          orderLocked = true;
          orderLoadError = "ارتباط این مرسوله با سفارش انتخاب‌شده تأیید نشد؛ از جزئیات سفارش بررسی کنید. ارسال و پرداخت انجام نشد.";
          sgStage("form");
          sgPaymentError({ message: "ارتباط این مرسوله با سفارش انتخاب‌شده تأیید نشد؛ از جزئیات سفارش بررسی کنید. ارسال و پرداخت انجام نشد." }, "", true);
          sgUpdateControls();
          return;
        }
        orderParcelId = Number.isSafeInteger(res.data.parcel_id) && res.data.parcel_id > 0 ? res.data.parcel_id : null;
        if (display && typeof display.shipping_blocked_reason === "string" && display.shipping_blocked_reason.trim()) {
          orderReadOnly = true;
          orderLocked = true;
          orderOpenMode = "receipt";
        }
        sgFillForm(res.data.payload || {});
        orderFormReady = true;
        if (orderReadOnly) {
          sgStage("form");
          sgRenderNativeReceipt(res.data.existing);
        } else if (res.data.existing && res.data.existing.paid) {
          orderLocked = true;
          orderReadOnly = true;
          orderOpenMode = "receipt";
          sgStage("form");
          sgRenderNativeReceipt(res.data.existing);
        } else if (mode === "payment" && orderParcelId && res.data.existing
          && res.data.existing.parcel_id === orderParcelId && res.data.existing.status_id === 7) {
          // Every entry point reviews information/weight before loading rates.
          // Preparing an existing draft does not register or pay it again.
          sgStage("form");
        } else if (mode === "payment") {
          orderLocked = true;
          sgStage("form");
          sgPaymentError({ message: "وضعیت مرسوله قبلی برای ادامه پرداخت تأیید نشد؛ آن را از صفحه سفارش بررسی کنید. ثبت یا پرداخت دوباره انجام نشد." }, "", true);
        }
        sgUpdateControls();
      })
      .fail(function () {
        if (!sgCurrentOrderContext(context)) return;
        sgOrderPrepareFailed("اطلاعات ارسال دریافت نشد؛ اتصال اینترنت را بررسی کنید و دوباره تلاش کنید.");
      });
  }

  $modal.on("click", "[data-sg-order-loading-retry]", function () {
    if (!orderBusy && (!orderLocked || orderReadOnly)) sgOpenModal(orderOpenMode);
  });

  function sgCompactSourceStatus(state) {
    if (state && typeof state.cancelled === "boolean") $box.attr("data-sg-source-cancelled", state.cancelled ? "1" : "0");
    else if ($box.find("[data-sg-order-source-status].is-cancelled").length) $box.attr("data-sg-source-cancelled", "1");
    $box.find("[data-sg-order-source-status].is-cancelled [data-sg-order-source-message]")
      .text("این سفارش در باسلام لغو شده است؛ ثبت مرسوله و پرداخت تازه برای آن امکان‌پذیر نیست.");
  }
  sgCompactSourceStatus();
  $(document).on("salamgate:source-state-updated", function (event, box, state) {
    if (box === $box[0]) { sgCompactSourceStatus(state); sgUpdateControls(); }
  });

  function sgRenderNativeReceipt(existing) {
    var receipt = $.isPlainObject(existing) ? existing : {};
    var $receipt = $("<section class='sg-order-native-receipt' data-sg-order-receipt-note>").prependTo($modal.find('[data-stage="form"]'));
    var $header = $("<div class='sg-order-receipt-header'>").appendTo($receipt);
    if (typeof receipt.status_title === "string" && receipt.status_title.trim()) {
      $("<div class='sg-order-receipt-state'>").append(
        $("<span class='sg-order-receipt-icon dashicons dashicons-archive' aria-hidden='true'>"),
        $("<div>").append($("<span class='sg-order-receipt-caption'>").text("وضعیت ارسال"), $("<strong>").text(receipt.status_title))
      ).appendTo($header);
    }
    if (receipt.paid !== true) return;
    $("<span class='sg-order-receipt-paid'>").append($("<span class='dashicons dashicons-yes-alt' aria-hidden='true'>"), "پرداخت شده").appendTo($header);
    var $toolbar = $("<div class='sg-order-receipt-toolbar'>").appendTo($receipt);
    var tracking = typeof receipt.tracking_code === "string" ? receipt.tracking_code.trim() : "";
    if (tracking) {
      var $trackingCode = $("<bdi class='sg-order-receipt-code' dir='ltr'>").text(tracking);
      $("<div class='salam-gate-tracking sg-order-receipt-tracking'>").append(
        $("<span class='sg-order-receipt-caption'>").text("کد رهگیری"),
        $("<div class='sg-order-receipt-tracking-row'>").append(
          $trackingCode,
          $("<button type='button' class='button salam-gate-copy'>").attr({ "data-code": tracking, "aria-label": "کپی کد رهگیری" }).append(
            $("<span class='dashicons dashicons-admin-page' aria-hidden='true'>"), $("<span class='sg-order-copy-label'>").text("کپی")
          )
        )
      ).appendTo($toolbar);
    }
    var $actions = $("<div class='sg-order-receipt-actions'>").appendTo($toolbar);
    if (tracking && receipt.can_print_label === true) {
      var $labels = $("<div class='salam-gate-label-row'>").appendTo($actions);
      var $template = $("<select class='salam-gate-label-template' aria-label='قالب برچسب مرسوله'>").appendTo($labels);
      $.each({ invoice: "فاکتور کامل", invoice_new: "فاکتور کامل (طراحی جدید)", summary: "لیبل خلاصه", label_10x15: "لیبل ۱۰×۱۵" }, function (value, title) {
        $("<option>").val(value).text(title).appendTo($template);
      });
      $("<button type='button' class='button salam-gate-get-label'>").text("چاپ برچسب پستی").appendTo($labels);
    }
    $("<p class='salam-gate-label-status sg-order-helper' role='status'>").hide().appendTo($receipt);
  }

  $box.on("click", ".salam-gate-open-modal", function () {
    var $button = $(this);
    if ($button.prop("disabled") || sgFinancialBusy()) return;
    var rawNative = $button.attr("data-basalam-parcel-id");
    if (rawNative != null) {
      if (!/^[1-9]\d*$/.test(rawNative) || !Number.isSafeInteger(Number(rawNative)) || !sgSelectNativeParcel(orderId, Number(rawNative))) return;
    }
    if (!listOrderHost) orderModalTrigger = this;
    // Basalam always starts with package details/weight, even for a saved draft.
    // The existing prepare/save guards still reuse its exact unpaid parcel.
    var mode = $button.attr("data-sg-native-receipt") === "1" ? "receipt"
      : orderMarket === "website" && listOrderHost && $button.attr("data-sg-native-has-local") === "1" ? "payment" : "edit";
    if (listOrderHost) $(document).trigger("salamgate:list-native-hide");
    sgOpenModal(mode);
  });

  $box.on("click", ".salam-gate-pay", function () {
    if ($(this).prop("disabled")) return;
    orderModalTrigger = this;
    sgOpenModal("payment");
  });

  $modal.on("click", ".sg-order-receiver-edit", function () {
    var open = $(this).attr("aria-expanded") !== "true";
    sgReceiverSummary();
    sgReceiverEditor(open);
    if (open) $modal.find(".sg-f-name").trigger("focus");
  });

  $modal.on("input change", ".sg-order-receiver-fields input, .sg-order-receiver-fields textarea, .sg-f-city", sgReceiverSummary);
  $modal.on("input change", ".sg-f-weight", function () {
    if (orderFilling || orderBusy || orderLocked) return;
    sgRevokeWeightConfirmation();
    sgUpdateControls();
  });
  $modal.on("change", "[data-sg-order-confirm-weight]", function () {
    if (orderFilling || orderBusy || orderLocked) return;
    orderConfirmedWeight = $(this).is(":checked") ? sgCurrentWeight() : "";
    $modal.find("[data-sg-order-weight-error]").text("").prop("hidden", true);
    sgUpdateControls();
  });
  $modal.on("input change", "[data-stage='form'] input, [data-stage='form'] select, [data-stage='form'] textarea", sgInformationChanged);

  function sgReviewOrder() {
    if (orderBusy || orderLocked || orderInformationDirty || !sgValidQuote(orderSelectedQuote)) return;
    var method = orderMarket === "website" ? String($modal.find(".sg-pay-method").val() || "") : "19";
    if (orderMarket === "website" && ["20", "21"].indexOf(method) === -1) return;
    var $review = $modal.find("[data-sg-order-review-summary]").empty();
    function group(title, icon, rows, kind) {
      var $group = $("<section class='sg-order-review-group'>").attr("data-review-group", kind);
      $group.append($("<h5>").append($("<span class='dashicons' aria-hidden='true'>").addClass("dashicons-" + icon), document.createTextNode(title)));
      var $fields = $("<dl class='sg-order-review-fields'>");
      rows.forEach(function (row) {
        $fields.append($("<div>").toggleClass("is-wide", row[2] === true).append($("<dt>").text(row[0]), $("<dd>").text(row[1] || "—")));
      });
      $review.append($group.append($fields));
    }
    var packaging = $modal.find(".sg-f-box option:selected").text();
    if ($modal.find(".sg-f-box").val() === "37") {
      packaging += " · " + [["طول", "length"], ["عرض", "width"], ["ارتفاع", "height"]].map(function (dimension) {
        return dimension[0] + " " + $modal.find(".sg-f-dim-" + dimension[1]).val();
      }).join("، ") + " سانتی‌متر";
    }
    var count = orderItemsEditable ? sgOrderItems().length : $modal.find(".sg-f-items tr").length;
    var contents = [$modal.find(".sg-f-category option:selected").text(), Number(count).toLocaleString("fa-IR") + " قلم"].filter(Boolean).join(" · ");
    var packageRows = [["سرویس ارسال", orderSelectedQuote.service_name || "سرویس پستی"],
      ["روش پرداخت", { 19: "کسر از مبلغ همین سفارش باسلام", 20: "تراز غرفه‌دار", 21: "کیف پول" }[method]],
      ["وزن با بسته‌بندی", Number(sgCurrentWeight()).toLocaleString("fa-IR") + " گرم"],
      ["ارزش اعلامی", Number.isFinite(Number(sgRawMoney($modal.find(".sg-f-value").val())))
        ? sgCostMoney(Number(sgRawMoney($modal.find(".sg-f-value").val()))) : "—"],
      ["بسته‌بندی", packaging], ["محتویات", contents]];
    var flags = [];
    if ($modal.find(".sg-f-liquid").is(":checked")) flags.push("مایعات");
    if ($modal.find(".sg-f-fragile").is(":checked")) flags.push("شکستنی");
    if (flags.length) packageRows.push(["ویژگی‌های بسته", flags.join(" · "), true]);
    group("مرسوله و پرداخت", "archive", packageRows, "parcel");
    group("گیرنده", "download", [["نام", $modal.find(".sg-f-name").val()],
      ["مقصد", [$modal.find(".sg-f-province").val(), $modal.find(".sg-f-city option:selected").text()].filter(Boolean).join(" — ")],
      ["موبایل", $modal.find(".sg-f-mobile").val()], ["کد پستی", $modal.find(".sg-f-postal").val()],
      ["نشانی", $modal.find(".sg-f-address").val(), true]], "receiver");
    var sender = orderDisplay.sender || {};
    group("فرستنده", "upload", [["نام", sender.name], ["شهر", sender.city],
      ["موبایل", sender.mobile], ["کد پستی", sender.postal_code], ["نشانی", sender.address, true]], "sender");
    sgClearPaymentError();
    sgStage("confirm");
    $modal.find("#sg-order-confirm-title").trigger("focus");
  }
  $modal.on("click", "[data-sg-order-review]", sgReviewOrder);
  $modal.on("click", "[data-sg-order-back-quotes]", function () {
    if (orderBusy || orderLocked) return;
    sgClearPaymentError(); sgStage("quotes");
  });
  $modal.on("click", "[data-sg-order-go-step]", function () {
    if (orderBusy || orderLocked || $(this).prop("disabled")) return;
    var number = Number($(this).attr("data-sg-order-go-step"));
    if (number === 1) { sgClearPaymentError(); sgStage("form"); }
    else if (number === 2 && orderQuotes && !orderInformationDirty) { sgClearPaymentError(); sgStage("quotes"); }
    else if (number === 3) sgReviewOrder();
  });
  $modal.on("click", "[data-sg-order-add-item]", function () {
    if (orderBusy || orderLocked || orderStage !== "form" || !orderItemsEditable) return;
    var $row = sgAddOrderItem({ quantity: 1 });
    sgRevokeWeightConfirmation();
    if (window.SalamGateMoney) window.SalamGateMoney.refresh();
    sgInformationChanged(); $row.find(".sg-order-item-title").trigger("focus");
  });
  function sgCloseOrderRemoval() {
    var pending = orderPendingRemoval;
    orderPendingRemoval = null;
    $modal.find("[data-sg-order-remove-modal]").hide();
    if (pending && document.contains(pending.opener)) $(pending.opener).trigger("focus");
  }
  $modal.on("click", "[data-sg-order-remove-item]", function () {
    if (orderBusy || orderLocked || orderStage !== "form" || !orderItemsEditable) return;
    var $row = $(this).closest("tr"), title = $.trim($row.find(".sg-order-item-title").val() || "");
    orderPendingRemoval = { row: $row, opener: this };
    $modal.find("[data-sg-order-remove-message]").text(title ? "قلم «" + title + "» از مرسوله حذف شود؟" : "این قلم از مرسوله حذف شود؟");
    $modal.find("[data-sg-order-remove-hint]").text($row[0] === $modal.find(".sg-f-items tr").first()[0]
      ? "اطلاعات قلم پاک می‌شود و سطر برای ورود قلم جدید باقی می‌ماند." : "این سطر از اقلام مرسوله حذف می‌شود.");
    $modal.find("[data-sg-order-remove-modal]").show().find("[data-sg-order-remove-cancel]").trigger("focus");
  });
  $modal.on("click", "[data-sg-order-remove-cancel]", sgCloseOrderRemoval);
  $modal.on("click", "[data-sg-order-remove-modal]", function (event) { if (event.target === this) sgCloseOrderRemoval(); });
  $modal.on("keydown", "[data-sg-order-remove-modal]", function (event) {
    if (event.key === "Escape") { event.preventDefault(); event.stopImmediatePropagation(); sgCloseOrderRemoval(); return; }
    if (event.key !== "Tab") return;
    var $buttons = $(this).find("button"), first = $buttons[0], last = $buttons[$buttons.length - 1];
    if ((!event.shiftKey && event.target === last) || (event.shiftKey && event.target === first)) {
      event.preventDefault(); event.stopPropagation(); $(event.shiftKey ? last : first).trigger("focus");
    }
  });
  $modal.on("click", "[data-sg-order-remove-confirm]", function () {
    var pending = orderPendingRemoval; sgCloseOrderRemoval();
    if (!pending || orderBusy || orderLocked || orderStage !== "form" || !orderItemsEditable || !document.contains(pending.row[0])) return;
    sgRevokeWeightConfirmation();
    var $next;
    if (pending.row[0] === $modal.find(".sg-f-items tr").first()[0]) {
      pending.row.find("input").val(""); pending.row.find(".sg-order-item-quantity").val(1);
      pending.row.data("item-id", 0).find(".sg-order-product-link").remove();
      $next = pending.row.find(".sg-order-item-title");
      sgItemPlaceholderRequirements(); $next.trigger("input");
      if (window.SalamGateValidation) window.SalamGateValidation.reset(pending.row);
    } else {
      $next = pending.row.prev().find(".sg-order-item-title"); pending.row.remove();
    }
    sgInformationChanged(); if ($next.length) $next.trigger("focus");
  });

  function sgCloseModal() {
    if (["save", "select", "pay"].indexOf(orderBusy) !== -1) return;
    ++orderEpoch;
    sgSetBusy("");
    $modal.hide();
    sgShowSidebarTerminal();
    if (listOrderHost && orderLocked) $(document).trigger("salamgate:order-list-changed", [Number(orderId)]);
    if (orderModalTrigger && document.contains(orderModalTrigger)) $(orderModalTrigger).trigger("focus");
  }

  $modal.on("click", ".salam-gate-modal__close", sgCloseModal);

  $modal.on("click", function (event) {
    if (event.target === this) sgCloseModal();
  });

  $(document).on("keydown.salamGateOrder", function (event) {
    if (event.key === "Escape" && $modal.css("display") !== "none") {
      if (orderPendingRemoval) { event.preventDefault(); event.stopImmediatePropagation(); sgCloseOrderRemoval(); }
      else sgCloseModal();
    }
  });

  $modal.on("change", ".sg-f-province", function () {
    sgFillCities($(this).val(), null);
    sgReceiverSummary();
  });

  $modal.on("change", ".sg-f-box", function () {
    var needsDimensions = $(this).val() === "37";
    $modal.find("[data-sg-order-out-of-size]").prop("checked", needsDimensions);
    if (!needsDimensions && $(this).val()) $(this).data("sg-normal-box", $(this).val());
    $modal.find(".sg-dims-row").toggle(needsDimensions);
  });
  $modal.on("change", "[data-sg-order-out-of-size]", function () {
    var $box = $modal.find(".sg-f-box");
    if ($(this).is(":checked")) {
      if ($box.val() !== "37") $box.data("sg-normal-box", $box.val());
      $box.val("37");
    } else {
      var normal = $box.data("sg-normal-box");
      if (!normal || !$box.find("option[value='" + normal + "']").length) normal = $box.find("option").filter(function () { return this.value && this.value !== "37"; }).first().val() || "";
      $box.val(normal);
    }
    $box.trigger("change");
  });

  // سوییچ سفارش غیراستاندارد → نمایش/مخفی قیمت پیشنهادی
  $modal.on("change", ".sg-f-non-standard", function () {
    var checked = $(this).is(":checked");
    $modal.find(".sg-proposed-price-row").toggle(checked);
    if (checked) {
      $modal.find(".sg-f-value").closest(".basalam-form-group").hide();
      $modal.find(".sg-f-value-label").text("ارزش مرسوله (تومان)");
    } else {
      $modal.find(".sg-f-value").closest(".basalam-form-group").show();
      $modal.find(".sg-f-proposed-price").val("");
    }
  });

  $modal.on("click", ".salam-gate-get-rates", function () {
    if (orderBusy || orderLocked || !orderFormReady) return;
    sgClearNotices();
    sgClearPaymentError();

    var mobile = sgRawMoney($modal.find(".sg-f-mobile").val()).trim();
    var postal = sgRawMoney($modal.find(".sg-f-postal").val()).trim();
    var cityId = String($modal.find(".sg-f-city").val() || "");

    var receiverError = !cityId ? ["استان و شهر گیرنده را انتخاب کنید.", ".sg-f-province"]
      : !/^09\d{9}$/.test(mobile) ? ["شماره موبایل گیرنده معتبر نیست.", ".sg-f-mobile"]
        : !/^\d{10}$/.test(postal) ? ["کد پستی باید دقیقاً ۱۰ رقم باشد.", ".sg-f-postal"]
          : $.trim($modal.find(".sg-f-name").val() || "").length < 3 ? ["نام گیرنده باید حداقل ۳ کاراکتر باشد.", ".sg-f-name"]
            : $.trim($modal.find(".sg-f-address").val() || "").length < 10 ? ["آدرس پستی باید حداقل ۱۰ کاراکتر باشد.", ".sg-f-address"] : null;
    if (receiverError) {
      sgReceiverEditor(true);
      $modal.find(receiverError[1]).trigger("focus");
      return sgBanner(receiverError[0]);
    }
    sgItemPlaceholderRequirements();
    if (window.SalamGateValidation && !window.SalamGateValidation.validate($modal)) return;
    if (orderItemsEditable && !sgOrderItems().length) return sgBanner("حداقل یک قلم مرسوله را وارد کنید.");
    if (!sgRequireWeightConfirmation()) return;

    var epoch = orderEpoch;
    var context = sgOrderContext();
    var dispatchParcelId = orderParcelId;
    orderSelectedQuote = null;
    sgSetBusy("save");

    function saveFailure(response) {
      if (!sgCurrentOrderContext(context)) return;
      var data = response && response.success === false && $.isPlainObject(response.data) ? response.data : null;
      var terminal = data && ["pay_unknown", "already_paid", "update_unknown", "create_recovered"].indexOf(data.code) !== -1;
      var definite = data && !terminal && data.code !== "create_unknown" && typeof data.message === "string"
        && (!data.kind || data.kind === "validation" || data.kind === "auth" || data.kind === "forbidden");
      orderLocked = !definite;
      sgSetBusy("");
      if (listOrderHost && orderLocked) $(document).trigger("salamgate:order-list-changed", [Number(orderId)]);
      if (definite) sgOrderNotify(data.message);
      else {
        sgStage("quotes");
        $modal.find(".sg-quotes-cards").empty();
        sgPaymentError({ code: data && data.code, message: terminal && data.message ? data.message : "نتیجه ثبت مرسوله مشخص نیست؛ از فهرست مرسولات بررسی کنید و دوباره ثبت نکنید." }, "", true);
      }
    }

    sgOrderPost("salam_gate_upsert_parcel", sgCollectForm())
      .done(function (res) {
        if (!sgCurrentOrderContext(context)) return;
        if (!res || res.success !== true || !$.isPlainObject(res.data)
          || !Number.isSafeInteger(res.data.parcel_id) || res.data.parcel_id <= 0
          || (dispatchParcelId && res.data.parcel_id !== dispatchParcelId
            && !(res.data.replaces_parcel_id === dispatchParcelId && res.data.requires_quote_selection === true))) {
          return saveFailure(res);
        }
        var canonical = sgCanonicalOrderPayload(res.data.parcel_payload);
        if (Object.prototype.hasOwnProperty.call(res.data, "parcel_payload") && !canonical) return saveFailure(res);
        orderParcelId = res.data.parcel_id;
        if (canonical) sgFillForm(canonical, true);
        // The remote update DTO cannot edit item weights once a parcel exists.
        if (!orderItemsEditable) orderItemWeightsEditable = false;
        sgSetBusy("");
        sgRenderQuotes(res.data.quotes);
        if (listOrderHost) $(document).trigger("salamgate:order-list-changed", [Number(orderId)]);
        if (Array.isArray(res.data.warnings) && res.data.warnings.length) {
          sgOrderNotify(res.data.warnings.join(" — "), "warning");
        }
      })
      .fail(function (xhr) {
        saveFailure(xhr && xhr.responseJSON);
      });
  });

  $modal.on("click", ".salam-gate-back-form", function () {
    if (orderBusy || orderLocked) return;
    sgClearPaymentError();
    sgStage("form");
    sgUpdateControls();
  });

  $modal.on("change", ".sg-pay-method", function () {
    if (orderBusy || orderLocked) return;
    sgClearPaymentError();
    sgUpdateControls();
  });

  $modal.on("click", ".salam-gate-reload-quotes", function () {
    if (orderBusy || orderLocked || !orderParcelId) return;
    sgClearPaymentError();
    sgLoadOrderQuotes(orderEpoch, true);
  });

  function sgInvalidateQuote(data) {
    sgStage("quotes");
    orderSelectedQuote = null;
    if (data.quotes) sgRenderQuotes(data.quotes, true);
    else {
      orderQuotes = null;
      $modal.find(".sg-quotes-cards").empty().append($("<p>").text("برای ادامه، نرخ‌های ارسال را دوباره دریافت کنید."));
    }
    sgUpdateControls();
    sgPaymentError(data);
    $modal.find("[data-sg-order-modal-payment-feedback]").append(
      $("<button type='button' class='button salam-gate-reload-quotes'>").text("دریافت دوباره نرخ‌ها")
    ).removeClass("sg-feedback-toast-only").toggleClass("sg-feedback-actions", typeof window.woosalamNotify === "function");
  }

  $modal.on("click", ".salam-gate-select-quote", function () {
    if (orderBusy || orderLocked || orderStage !== "confirm" || orderInformationDirty || !orderParcelId || !sgValidQuote(orderSelectedQuote)) return;
    if (!sgRequireWeightConfirmation()) return;
    var method = orderMarket === "website" ? String($modal.find(".sg-pay-method").val() || "") : "19";
    if (orderMarket === "website" && ["20", "21"].indexOf(method) === -1) {
      return sgPaymentError({ message: "روش پرداخت را انتخاب کنید." });
    }
    // Consent is pinned before any async call. Never silently accept a changed rate.
    var consent = { parcelId: orderParcelId, hash: orderSelectedQuote.hash_id, total: orderSelectedQuote.total_rial, method: method, weight: orderConfirmedWeight };
    var epoch = orderEpoch;
    var context = sgOrderContext();
    sgClearPaymentError();
    sgSetBusy("select");

    function selectionFailure(response) {
      if (!sgCurrentOrderContext(context)) return;
      var data = response && response.success === false && $.isPlainObject(response.data) ? response.data : {};
      var terminal = ["pay_unknown", "already_paid", "update_unknown"].indexOf(data.code) !== -1;
      if (terminal) orderLocked = true;
      sgSetBusy("");
      sgPaymentError({ code: data.code, message: data.message || "تأیید روش ارسال انجام نشد؛ پرداختی درخواست نشد. دوباره تلاش کنید." }, "", terminal);
    }

    function paymentFailure(response) {
      if (!sgCurrentOrderContext(context)) return;
      var trusted = $.isPlainObject(response) && response.success === false && $.isPlainObject(response.data);
      var data = trusted ? response.data : {};
      var terminal = ["pay_unknown", "already_paid", "update_unknown"].indexOf(data.code) !== -1;
      var definitive = trusted && !terminal && data.kind === "validation"
        && typeof data.message === "string" && Array.isArray(data.errors)
        && data.errors.every(function (error) {
          return $.isPlainObject(error) && typeof error.location === "string" && typeof error.message === "string";
        });
      orderLocked = !definitive;
      sgSetBusy("");
      if (definitive && data.code === "weight_confirmation_required") {
        var currentWeight = data.current_weight_grams;
        var hasCurrentWeight = Number.isSafeInteger(currentWeight) && currentWeight >= 50 && currentWeight <= 30000;
        if (hasCurrentWeight) {
          $modal.find(".sg-f-weight").val(currentWeight);
          orderDisplay.weight_confirmation = $.extend({}, orderDisplay.weight_confirmation || {}, {
            weight_grams: currentWeight, source: "saved",
            message: "وزن فعلی مرسوله از سرور دریافت شد؛ آن را با وزن واقعی بسته بررسی و دوباره تأیید کنید."
          });
          if (window.SalamGateValidation) window.SalamGateValidation.reset($modal.find(".sg-f-weight"));
        }
        sgRevokeWeightConfirmation();
        orderSelectedQuote = null;
        orderQuotes = null;
        orderInformationDirty = true;
        $modal.find(".sg-quotes-cards, [data-sg-order-review-summary]").empty();
        sgClearPaymentError();
        sgStage("form");
        var weightMessage = hasCurrentWeight
          ? "وزن مرسوله تغییر کرده است و پرداخت انجام نشد. وزن تازه را بررسی و تأیید کنید، سپس نرخ ارسال را دوباره بگیرید."
          : "وزن فعلی مرسوله تأیید نشد و پرداخت انجام نشد. وزن واقعی بسته را بررسی و وارد کنید؛ برای دریافت اطلاعات تازه می‌توانید پنجره را دوباره باز کنید.";
        $modal.find("[data-sg-order-edit-warning]").prop("hidden", false).text(weightMessage)
          .toggleClass("sg-feedback-toast-only", typeof window.woosalamNotify === "function");
        sgOrderNotify(weightMessage, "warning");
        $modal.find(".sg-f-weight").trigger("focus");
        return;
      }
      if (definitive && (data.code === "quote_changed" || data.code === "quote_check_failed" || data.code === "quote_selection_required")) {
        sgInvalidateQuote(data);
      } else {
        sgPaymentError(definitive || (terminal && typeof data.message === "string" && data.message.trim()) ? data : {
          message: "در حال بررسی پرداخت؛ تأیید پرداخت هنوز دریافت نشده؛ وضعیت را بررسی کنید. دوباره پرداخت نکنید."
        }, definitive ? consent.method : "", !definitive);
      }
      if (definitive) sgRefreshBalances(true);
    }

    sgOrderPost("salam_gate_select_quote", { hash: consent.hash })
      .done(function (res) {
        if (!sgCurrentOrderContext(context)) return;
        if (!res || res.success !== true || !$.isPlainObject(res.data)) return selectionFailure(res);
        var rates = res.data.quotes;
        var validParcel = res.data.parcel_id === consent.parcelId && $.isPlainObject(rates)
          && rates.parcel_id === consent.parcelId && Array.isArray(rates.quotes);
        var selected = validParcel ? rates.quotes.filter(function (q) { return $.isPlainObject(q) && q.selected === true; }) : [];
        var matching = validParcel ? rates.quotes.filter(function (q) { return q && q.hash_id === consent.hash; }) : [];
        if (!validParcel || selected.length !== 1 || matching.length !== 1 || !sgValidQuote(selected[0])
          || selected[0].hash_id !== consent.hash || selected[0].total_rial !== consent.total) {
          sgSetBusy("");
          sgInvalidateQuote({ message: "نرخ یا روش ارسال تغییر کرده است؛ مبلغ را دوباره بررسی و تأیید کنید. پرداختی درخواست نشد.", quotes: validParcel ? rates : null });
          return;
        }
        var canonical = sgCanonicalOrderPayload(res.data.parcel_payload);
        if (Object.prototype.hasOwnProperty.call(res.data, "parcel_payload") && !canonical) {
          sgSetBusy("");
          sgInvalidateQuote({ message: "اطلاعات تأییدشده مرسوله کامل نیست؛ اطلاعات و روش ارسال را دوباره بررسی کنید. پرداختی درخواست نشد." });
          return;
        }
        if (canonical && !sgCanonicalMatchesReview(res.data.parcel_payload)) {
          sgFillForm(canonical, true);
          orderSelectedQuote = null;
          orderQuotes = null;
          orderInformationDirty = true;
          sgSetBusy(""); sgStage("form");
          var changedMessage = "اطلاعات مرسوله روی سرور تغییر کرده است؛ اطلاعات تازه را بررسی و روش ارسال و پرداخت را دوباره انتخاب کنید.";
          $modal.find("[data-sg-order-edit-warning]").prop("hidden", false).text(changedMessage)
            .toggleClass("sg-feedback-toast-only", typeof window.woosalamNotify === "function");
          sgOrderNotify(changedMessage, "warning");
          return;
        }
        if (!sgWeightConfirmed() || sgCurrentWeight() !== consent.weight) {
          sgSetBusy(""); sgStage("confirm"); sgRequireWeightConfirmation();
          return;
        }
        sgSetBusy("pay");
        sgOrderPost("salam_gate_pay_parcel", {
          method: consent.method, expected_hash: consent.hash, expected_total_rial: consent.total,
          weight_confirmed: "1", confirmed_weight_grams: consent.weight
        }).done(function (payment) {
          if (!sgCurrentOrderContext(context)) return;
          var data = $.isPlainObject(payment) && payment.success === true && $.isPlainObject(payment.data) ? payment.data : null;
          var verified = data && data.parcel_id === consent.parcelId && typeof data.confirmed === "boolean"
            && (data.confirmed ? [8, 9, 10, 11, 12].indexOf(data.status_id) !== -1 : data.status_id === 0);
          if (!verified) return paymentFailure(payment);
          orderLocked = true;
          sgSetBusy("");
          if (data.confirmed) {
            sgCloseModal();
            sgOrderNotify("پرداخت هزینه ارسال با موفقیت ثبت شد.", "success");
          } else {
            sgPaymentError({ message: "پرداخت ثبت شده است و تأیید نهایی ارسال در حال بررسی است؛ دوباره پرداخت نکنید." }, "", true, "warning");
          }
          sgRefreshMetabox();
        }).fail(function (xhr) { paymentFailure(xhr && xhr.responseJSON); });
      })
      .fail(function (xhr) { selectionFailure(xhr && xhr.responseJSON); });
  });

  // ---------- پارت ۳: پرداخت، لیبل، پیگیری ----------

  var metaboxReadSequence = 0;
  function sgReleaseMetaboxLoading(sequence) {
    // This spinner belongs to the root read, not to the modal's selection epoch.
    // A closed/switched modal must not strand it or clear a newer root read.
    if (sequence != null && sequence !== metaboxReadSequence) return;
    $box.removeClass("sg-order-box-loading").attr("aria-busy", "false");
    $box.find("[data-sg-order-box-loader]").remove();
  }
  function sgRefreshMetabox() {
    var context = sgOrderContext();
    var readSequence = ++metaboxReadSequence;
    $box.addClass("sg-order-box-loading").attr("aria-busy", "true");
    $box.find("[data-sg-order-box-loader], [data-sg-order-box-read-error], [data-sg-order-box-read-retry]").remove();
    $("<p class='sg-order-box-loader' data-sg-order-box-loader role='status'>").text("در حال دریافت اطلاعات…").appendTo($box);
    function readFailed() {
      if (readSequence !== metaboxReadSequence || !sgCurrentOrderContext(context)) return;
      $("<div class='sg-order-helper' data-sg-order-box-read-error role='status'>").append(
        $("<p>").text("وضعیت تازه دریافت نشد؛ اطلاعات قبلی نمایش داده می‌شود."),
        $("<button type='button' class='button' data-sg-order-box-read-retry>").text("دریافت دوباره وضعیت")
      ).toggleClass("sg-feedback-actions", typeof window.woosalamNotify === "function").appendTo($box);
      sgOrderNotify("وضعیت تازه دریافت نشد؛ اطلاعات قبلی نمایش داده می‌شود.", "error");
    }
    return sgOrderPost("salam_gate_refresh_metabox").done(function (res) {
      if (readSequence !== metaboxReadSequence || !sgCurrentOrderContext(context)) return;
      if (res && res.success && res.data && typeof res.data.html === "string" && res.data.html) {
        var $wrapper = $("<div>").html(res.data.html), $root = $wrapper.find(".salam-gate-order-box").first();
        if ($root.length) {
          if (String($root.attr("data-order-id") || "") !== context.order) { readFailed(); return; }
          $box.attr("data-basalam-order", $root.attr("data-basalam-order") || "0").html($root.html());
        } else $box.html(res.data.html);
        if (menuOrderHost) $box.find('.salam-gate-summary').remove();
        $(document).trigger("salamgate:order-box-refreshed", [$box[0]]);
        if (listOrderHost) $(document).trigger("salamgate:order-list-row-updated", [res.data]);
        sgUpdateControls();
        sgShowSidebarTerminal();
      } else readFailed();
    }).fail(readFailed).always(function () {
      sgReleaseMetaboxLoading(readSequence);
    });
  }
  $box.on("click", "[data-sg-order-box-read-retry]", function () { if (!orderBusy) sgRefreshMetabox(); });

  // Never contact SalamGate merely by opening a Woo order, even for legacy
  // parcels without a saved status. Let the merchant request reconciliation.
  if (!menuOrderHost && $box.attr("data-basalam-order") !== "1" && $box.find("[data-sg-order-status-missing]").length) {
    $("<button type='button' class='button' data-sg-order-box-read-retry>")
      .text("دریافت وضعیت مرسوله").appendTo($box);
  }

  $box.add($modal).on("click", ".salam-gate-copy", function () {
    var $button = $(this), code = String($button.attr("data-code") || "");
    if ($button.prop("disabled")) return;
    if (!code) return;
    var active = document.activeElement;
    $button.prop("disabled", true);
    function finish(copied) {
      $button.prop("disabled", false).toggleClass("is-copied", copied);
      if ($button.find(".sg-order-copy-label").length) $button.find(".sg-order-copy-label").text(copied ? "کپی شد" : "کپی");
      else if (copied) $button.text("کپی شد ✓");
      sgOrderNotify(copied ? "کد رهگیری کپی شد." : "کد رهگیری کپی نشد؛ کد را انتخاب و دستی کپی کنید.", copied ? "success" : "error");
    }
    function fallback() {
      var copied = false;
      var $input = $("<textarea readonly>").val(code).css({ position: "fixed", top: "0", left: "-9999px" }).appendTo(document.body);
      try { $input[0].focus({ preventScroll: true }); $input[0].select(); copied = document.execCommand("copy") === true; } catch (_) { /* Report the actual result. */ }
      $input.remove();
      finish(copied);
      if (active && document.contains(active)) active.focus({ preventScroll: true });
    }
    try {
      if (navigator.clipboard && typeof navigator.clipboard.writeText === "function") navigator.clipboard.writeText(code).then(function () { finish(true); }, fallback);
      else fallback();
    } catch (error) { fallback(); }
  });


  var sgLabelPollTimer = null;
  var sgLabelJobs = Object.create(null);

  function sgLabelContext(template) {
    return $.extend(sgOrderContext(), { labelTemplate: template, labelNonce: String(basalam_ajax.nonce || "") });
  }
  function sgCurrentLabelContext(context, $scope) {
    return sgCurrentOrderContext(context) && context.labelNonce === String(basalam_ajax.nonce || "")
      && !!$scope && String($scope.find(".salam-gate-label-template").val() || "") === context.labelTemplate;
  }
  function sgLabelJob(context) {
    var orderKey = sgOrderKey(context.order, context.native);
    var key = JSON.stringify([orderKey, context.labelTemplate, context.labelNonce]);
    return sgLabelJobs[key] || (sgLabelJobs[key] = { orderKey: orderKey, labelTemplate: context.labelTemplate,
      labelNonce: context.labelNonce, pending: false, unresolved: false,
      requestId: 0, scope: null, version: 0, fileUrl: "", fileExpires: 0 });
  }
  function sgLabelButtons(job) {
    if (!job.scope || job.labelNonce !== String(basalam_ajax.nonce || "")
      || String(job.scope.find(".salam-gate-label-template").val() || "") !== job.labelTemplate) return;
    job.scope.find(".salam-gate-get-label").prop("disabled", job.pending)
      .text(job.pending ? "در حال آماده‌سازی…" : job.unresolved ? "بررسی وضعیت برچسب" : "چاپ برچسب پستی");
  }
  function sgPauseLabelJob(context) {
    if (!sgLabelJobs) return;
    Object.keys(sgLabelJobs).forEach(function (key) {
      var job = sgLabelJobs[key];
      if (job.orderKey !== sgOrderKey(context.order, context.native)) return;
      job.pending = false;
      if (!job.requestId) job.unresolved = false;
      sgLabelButtons(job);
    });
  }

  function sgShowLabelFile(url, $scope) {
    $scope = $scope || $box;
    var fileUrl;
    try {
      fileUrl = new URL(url);
      if (["https:", "http:"].indexOf(fileUrl.protocol) === -1 || fileUrl.username || fileUrl.password) throw new Error("Invalid label URL");
    } catch (error) {
      $scope.find(".salam-gate-label-status").hide().empty();
      sgOrderNotify("آدرس فایل برچسب معتبر نیست؛ دوباره وضعیت را بررسی کنید.");
      return "";
    }
    $scope.find(".salam-gate-label-status").hide().empty();
    try {
      // A same-tab navigation is not blocked as an asynchronous popup. Open
      // only the URL returned for this exact parcel and completed label job.
      if (!window.open(fileUrl.href, "_self")) throw new Error("Label navigation was blocked");
    } catch (error) {
      // Preserve access if a browser extension prevents the navigation.
      $scope.find(".salam-gate-label-status").prop("hidden", false).empty().append(
        $("<a class='sg-order-label-file'>").attr({ href: fileUrl.href, target: "_self" }).text("مشاهده برچسب پستی")
      ).show();
      sgOrderNotify("بازکردن خودکار برچسب انجام نشد؛ از «مشاهده برچسب پستی» استفاده کنید.", "warning");
    }
    return fileUrl.href;
  }
  $(window).on("pagehide", function () {
    sgPauseLabelJob(sgOrderContext());
    // A cached document can return after the account or receipt changed.
    // Retain queued job recovery, but reverify completed files after returning.
    Object.keys(sgLabelJobs).forEach(function (key) { sgLabelJobs[key].fileUrl = ""; sgLabelJobs[key].fileExpires = 0; });
    orderSuspended = { busy: orderBusy, financial: sgFinancialBusy() };
    if (orderSuspended.financial) {
      // Do not abort or replay a write: the server can finish it while this
      // document is cached. Remember the uncertainty for this native parcel.
      orderLocked = true;
      orderSuspendedReviews[sgOrderKey(orderId, selectedBasalamParcelId)] = true;
      orderTerminalMessage = orderTerminalMessage || (orderBusy === "pay"
        ? "در حال بررسی پرداخت؛ تأیید پرداخت هنوز دریافت نشده؛ وضعیت را بررسی کنید. دوباره پرداخت نکنید."
        : "نتیجه ثبت یا انتخاب روش ارسال قبلی هنوز مشخص نیست؛ آن را تکرار نکنید. وضعیت مرسوله را بررسی کنید.");
      sgRememberTerminal();
    }
    ++orderEpoch; clearTimeout(sgLabelPollTimer);
    ++metaboxReadSequence; sgReleaseMetaboxLoading();
  });
  $(window).on("pageshow", function (event) {
    if (!orderSuspended || !((event.originalEvent && event.originalEvent.persisted) || event.persisted === true)) return;
    var suspended = orderSuspended;
    orderSuspended = null;
    orderBusy = "";
    $modal.find(".salam-gate-get-label").prop("disabled", false).text("دریافت برچسب پستی");
    $modal.find(".salam-gate-track-list").empty().hide();
    $modal.find(".salam-gate-label-status").each(function () {
      if (!$(this).find(".sg-order-label-file").length) $(this).empty().hide();
    });
    if (suspended.financial) {
      orderSelectedQuote = null; orderQuotes = null;
      sgRevokeWeightConfirmation();
      orderLoadError = "";
      sgStage("quotes");
      sgPaymentError({ message: orderTerminalMessage }, "", true, "warning");
      sgShowSidebarTerminal();
    } else if (suspended.busy === "prepare") {
      orderFormReady = false;
      orderLoadError = "برای دریافت اطلاعات تازهٔ مرسوله، «تلاش دوباره» را بزنید. هیچ ثبت یا پرداختی تکرار نشده است.";
      sgRevokeWeightConfirmation();
    } else if (suspended.busy) {
      sgRevokeWeightConfirmation();
      sgInvalidateQuote({ message: "اطلاعات قبلی ممکن است تازه نباشد؛ برای ادامه، نرخ‌های ارسال را دوباره دریافت و بررسی کنید. پرداختی ارسال نشده است." });
    }
    sgUpdateControls();
  });

  function sgLabelPoll(requestId, retryAfter, context, $scope) {
    context = context || sgLabelContext("");
    $scope = $scope || $box;
    clearTimeout(sgLabelPollTimer);

    sgLabelPollTimer = setTimeout(function () {
      if (!sgCurrentLabelContext(context, $scope)) {
        sgRememberDetachedLabel(null, sgLabelJob(context));
        return;
      }
      sgOrderPost("salam_gate_label_status", { request_id: requestId }).done(function (res) {
        if (!sgCurrentLabelContext(context, $scope)) {
          sgRememberDetachedLabel(res && res.success === true ? res.data : null, sgLabelJob(context));
          return;
        }
        if (!res || !res.success) {
          sgLabelReadFailed(context, $scope, (res && res.data && res.data.message) || "وضعیت برچسب دریافت نشد؛ وضعیت همین درخواست را دوباره بررسی کنید.");
          return;
        }
        sgAcceptLabelResult(res.data || {}, context, $scope);
      }).fail(function () {
        if (!sgCurrentLabelContext(context, $scope)) { sgRememberDetachedLabel(null, sgLabelJob(context)); return; }
        sgLabelReadFailed(context, $scope, "ارتباط قطع شد؛ برای دریافت فایل، «بررسی وضعیت برچسب» را بزنید.");
      });
    }, Math.min(60, Math.max(2, Number(retryAfter) || 5)) * 1000);
  }

  function sgLabelReadFailed(context, $scope, message) {
    var job = sgLabelJob(context);
    job.pending = false;
    job.scope = $scope;
    sgLabelButtons(job);
    $scope.find(".salam-gate-label-status").hide().empty();
    sgOrderNotify(message);
  }
  function sgAcceptLabelResult(result, context, $scope) {
    var job = sgLabelJob(context);
    job.scope = $scope;
    if (!Number.isSafeInteger(result.request_id) || result.request_id <= 0
      || (job.requestId > 0 && job.requestId !== result.request_id)) {
      job.unresolved = job.requestId > 0;
      sgLabelReadFailed(context, $scope, "پاسخ برچسب متعلق به این درخواست نیست؛ وضعیت همین درخواست را بررسی کنید.");
      return;
    }
    job.requestId = result.request_id;
    if (result.status === "completed" && typeof result.url === "string" && result.url) {
      job.pending = false; job.unresolved = false;
      sgLabelButtons(job);
      var fileExpires = Date.parse(result.expire_at || "");
      if (Number.isFinite(fileExpires) && fileExpires <= Date.now()) {
        sgOrderNotify("مهلت دریافت فایل قبلی تمام شده است؛ دوباره برچسب را درخواست کنید.", "warning");
        return;
      }
      job.fileUrl = sgShowLabelFile(result.url, $scope);
      // Limit reuse when the provider does not publish its expiration time.
      job.fileExpires = Number.isFinite(fileExpires) ? fileExpires : Date.now() + 5 * 60 * 1000;
    } else if (result.status === "failed" || result.status === "expired") {
      job.pending = false; job.unresolved = false;
      sgLabelButtons(job);
      $scope.find(".salam-gate-label-status").hide().empty();
      sgOrderNotify(result.message || "ساخت برچسب انجام نشد؛ می‌توانید دوباره درخواست بدهید.", result.status === "expired" ? "warning" : "error");
    } else if (result.status === "requested" && job.requestId > 0) {
      job.pending = true; job.unresolved = true;
      sgLabelButtons(job);
      $scope.find(".salam-gate-label-status").prop("hidden", false).text("در حال آماده‌سازی برچسب پستی…").show();
      sgLabelPoll(job.requestId, result.retry_after || 5, context, $scope);
    } else {
      job.unresolved = job.requestId > 0;
      sgLabelReadFailed(context, $scope, "تأیید آماده‌شدن برچسب دریافت نشد؛ وضعیت همین درخواست را دوباره بررسی کنید.");
    }
  }
  function sgRememberDetachedLabel(result, job) {
    // A known job still belongs to the original child when the user switches
    // cards. Retain its ID, but never paint its file into the new selection.
    job.pending = false;
    var resultId = result && Number.isSafeInteger(result.request_id) && result.request_id > 0 ? result.request_id : 0;
    if (!job.requestId) job.requestId = resultId;
    job.unresolved = job.requestId > 0 && (!result || resultId !== job.requestId
      || (result.status !== "failed" && result.status !== "expired"));
    sgLabelButtons(job);
  }

  $box.add($modal).on("change", ".salam-gate-label-template", function () {
    var $scope = $(this).closest("[data-sg-order-label-scope]");
    if (!$scope.length) $scope = $(this).closest("#salam-gate-parcel-modal").length ? $modal : $box;
    var context = sgLabelContext(String($(this).val() || ""));
    Object.keys(sgLabelJobs).forEach(function (key) {
      var previous = sgLabelJobs[key];
      // Preserve a queued job for its original template without leaving the
      // new template's print button disabled. Its late callback cannot open it.
      if (previous.scope && previous.scope[0] === $scope[0] && previous.labelTemplate !== context.labelTemplate
        && previous.requestId > 0) previous.pending = false;
    });
    var job = sgLabelJob(context);
    job.scope = $scope;
    sgLabelButtons(job);
    $scope.find(".salam-gate-label-status").hide().empty();
  });

  $box.add($modal).on("click", ".salam-gate-get-label", function () {
    if ($(this).prop("disabled") || sgFinancialBusy()) return;
    var nativeId = $(this).attr("data-basalam-parcel-id");
    if (nativeId != null && (!/^[1-9]\d*$/.test(nativeId) || !Number.isSafeInteger(Number(nativeId))
      || !sgSelectNativeParcel(orderId, Number(nativeId)))) return;
    var $scope = $(this).closest("[data-sg-order-label-scope]");
    if (!$scope.length) $scope = $(this).closest("#salam-gate-parcel-modal").length ? $modal : $box;
    var template = String($scope.find(".salam-gate-label-template").val() || "");
    var context = sgLabelContext(template);
    var job = sgLabelJob(context);
    if (job.pending) return;
    if (job.fileUrl && job.fileExpires > Date.now()) {
      sgShowLabelFile(job.fileUrl, $scope);
      return;
    }
    job.fileUrl = ""; job.fileExpires = 0;
    job.scope = $scope; job.pending = true;
    sgLabelButtons(job);
    if (job.unresolved) {
      sgLabelPoll(job.requestId, 2, context, $scope);
      return;
    }
    job.requestId = 0;
    job.unresolved = true;
    var labelVersion = ++job.version;

    sgOrderPost("salam_gate_get_label", { template: template })
      .done(function (res) {
        if (job.version !== labelVersion) return;
        if (!sgCurrentLabelContext(context, $scope)) {
          sgRememberDetachedLabel(res && res.success === true ? res.data : null, job);
          return;
        }
        if (!res || !res.success) {
          // Label generation only creates a PDF; unlike shipping/payment it can
          // safely be requested again. Never attach an unrelated GET-latest
          // file to this single parcel when its job ID was not received.
          job.unresolved = false;
          sgLabelReadFailed(context, $scope, (res && res.data && res.data.message) || "تأیید ساخت برچسب دریافت نشد؛ وضعیت درخواست را بررسی کنید.");
          return;
        }
        sgAcceptLabelResult(res.data || {}, context, $scope);
      })
      .fail(function () {
        if (job.version !== labelVersion) return;
        job.unresolved = false;
        if (!sgCurrentLabelContext(context, $scope)) { sgRememberDetachedLabel(null, job); return; }
        sgLabelReadFailed(context, $scope, "فایل برچسب دریافت نشد؛ دوباره «چاپ برچسب پستی» را بزنید. ثبت مرسوله یا پرداخت دوباره انجام نمی‌شود.");
      });
  });
});

// =====================================================================
// Salam Gate — parcels list page (part 8 — escaped rendering, tabs, modals)
// =====================================================================
jQuery(function ($) {
  var $page = $(".salam-gate-parcels-page");
  if (!$page.length || !$("#sgp-config").length) return;

  var config = {};
  try {
    config = JSON.parse($("#sgp-config").text() || "{}");
  } catch (e) {
    config = {};
  }
  var filters = $.extend({ tab: "all", market_name: [], status: "", sort: "desc", cursor: "", search: "" }, config.filters || {});
  var cursorState = { next: "", prev: "" };
  var currentCursor = filters.cursor || "";
  var listRequest = null;
  var listSequence = 0;
  var listedParcels = Object.create(null);
  var selectedParcels = Object.create(null);
  var labelCandidates = Object.create(null), labelListRequest = null, labelListVersion = 0;
  var labelListCursor = "", labelListHasMore = true, labelListReady = false, labelListConnection = null;
  var labelListSeen = Object.create(null), labelListError = "";
  var $printModal = $("[data-sgp-print-modal-el]");
  var $printMenu = $page.find("[data-sgp-print-menu]");
  var defaultLabelTemplate = String($printModal.find("[name='sgp-template']:checked").val() || "invoice");
  var bulkPrintKind = "label";
  var searchResults = null, searchKey = "", searchTimer = null;
  var $filterModal = $(".sg-modal-overlay[data-sgp-filter-modal]");

  function sgPost(action, data) {
    var request = window.WoosalamGateRequests ? window.WoosalamGateRequests.post : $.post;
    return request(basalam_ajax.ajaxurl, $.extend({ _wpnonce: basalam_ajax.nonce, action: action }, data || {}));
  }

  function esc(v) {
    return $("<span>").text(v == null ? "" : String(v)).html();
  }

  function fa(n) {
    return String(n).replace(/\d/g, function (d) { return "۰۱۲۳۴۵۶۷۸۹"[+d]; });
  }

  function faThousands(n) {
    return fa(String(Math.floor(Number(n))).replace(/\B(?=(\d{3})+(?!\d))/g, ","));
  }

  function statusText(text) {
    $page.find(".sgp-label-status").text(text).removeClass("sg-feedback-actions sg-feedback-toast-only").attr("role", "status").toggle(!!text);
  }

  function readableMessage(text, fallback) {
    return typeof text === "string" && /[\u0600-\u06ff]/.test(text)
      ? text.replace(/بسالم/g, "باسلام") : fallback;
  }

  function listError($body, message, announce) {
    $page.find("[data-sgp-search-status]").prop("hidden", true);
    message = readableMessage(message, "فهرست مرسوله‌ها دریافت نشد؛ دوباره تلاش کنید.");
    var floating = typeof window.woosalamNotify === "function";
    $body.empty().append($("<tr>").append(
      $("<td colspan='8' class='sgp-empty sgp-state--error'>").attr("role", floating ? "group" : "status").append(
        $("<p>").text(message).toggleClass("sg-feedback-toast-only", floating),
        $("<button type='button' class='button-link' data-sgp-retry>").text("تلاش مجدد")
      )
    ));
    if (floating && announce !== false) window.woosalamNotify(message, "error");
    return message;
  }

  // ---------- رندر ردیف‌ها — همه مقادیر با text node / esc (بدون XSS) ----------
  function parcelCell(label) {
    return $("<td>").attr("data-label", label);
  }

  function rowNode(item) {
    var id = String(item.id);
    listedParcels[id] = item;
    var $tr = $("<tr>").attr("data-parcel-id", id);
    $tr.toggleClass("sgp-row--paid", item.payment_state === "paid");
    if (Number.isFinite(item.shipping_cost_toman)) $tr.attr("data-shipping-cost-toman", item.shipping_cost_toman);

    var $receiver = parcelCell("گیرنده").append($("<strong>").text(item.receiver_name || "—"));
    if (item.receiver_city) $receiver.append($("<small class='sgp-cell-note'>").text(item.receiver_city));

    var $tracking = parcelCell("کد رهگیری").addClass("sgp-tracking-cell");
    if (item.tracking_code) {
      var $trackingCode = $("<bdi dir='ltr'>").text(item.tracking_code);
      if (/^\d{24}$/.test(item.tracking_code)) $trackingCode.empty().append(
        document.createTextNode(item.tracking_code.slice(0, 12)), $("<wbr>"), document.createTextNode(item.tracking_code.slice(12))
      );
      $tracking.append(
        $("<button type='button' class='sgp-track' data-sgp-copy-tracking>")
          .attr({ "data-code": item.tracking_code, title: "کپی کد رهگیری", "aria-label": "کپی کد رهگیری " + item.tracking_code })
          .append($trackingCode, $("<span class='dashicons dashicons-admin-page' aria-hidden='true'>"))
      );
    } else {
      $tracking.append($("<span class='sgp-tracking-empty'>").text("—"));
    }

    var $status = $("<div class='sgp-status'>").append(
      $("<span class='salam-gate-badge'>").css("background-color", item.status_color || "#6b7280").text(paymentStatusLabel(item.status_label) || "")
    );
    var paymentLabels = { unpaid: "در انتظار پرداخت", paid: "پرداخت شده", pending: "در حال بررسی", unknown: "در حال بررسی", refunded: "برگشت وجه", voided: "باطل شده", blocked: "تأیید نشده" };
    var paymentNote = typeof item.payment_label === "string" && item.payment_label.trim()
      ? item.payment_label.trim() : (paymentLabels[item.payment_state] || (item.can_resume_payment !== true ? "تأیید نشده" : ""));
    var paymentTone = { unpaid: "waiting", paid: "paid", pending: "review", unknown: "review", refunded: "blocked", voided: "blocked" }[item.payment_state] || "blocked";
    var paymentIcon = { waiting: "clock", paid: "yes-alt", review: "update", blocked: "info-outline" }[paymentTone];
    if (paymentNote) $status.append($("<small class='sgp-payment-reason'>")
      .attr({ "data-tone": paymentTone, "aria-label": "وضعیت پرداخت: " + paymentNote,
        title: typeof item.payment_message === "string" ? paymentStatusLabel(item.payment_message) : "" })
      .append($("<span class='dashicons' aria-hidden='true'>").addClass("dashicons-" + paymentIcon), $("<span>").text(paymentNote)));
    var $labelCell = parcelCell("وضعیت").append($status);
    $tr.append(
      parcelCell("مرسوله / بازار").append($("<strong class='sgp-parcel-id'>").text("#" + fa(id)), $("<small class='sgp-cell-note'>").text(item.market_label || "—")),
      $receiver,
      parcelCell("محتویات").text(item.contents || "—"),
      parcelCell("تاریخ").text(item.invoice_paid_at || item.updated_at ? dateText(item.invoice_paid_at || item.updated_at) : (item.created_at_display || "—")),
      $labelCell,
      parcelCell("روش ارسال").text(item.method_title || "—").append(Number.isFinite(item.shipping_cost_toman)
        ? $("<small class='sgp-method-price'>").text(faThousands(item.shipping_cost_toman) + " تومان") : $()),
      $tracking,
      parcelCell("عملیات").addClass("sgp-actions").append(
        $("<div class='sgp-actions__buttons'>").append(
          $("<button type='button' class='button button-small sgp-detail'>")
            .addClass("is-expanded")
            .attr({ "aria-label": "جزئیات مرسوله " + fa(id), title: "جزئیات مرسوله" })
            .append(
              $("<span class='dashicons dashicons-visibility' aria-hidden='true'>"),
              $("<span class='sgp-detail__text' aria-hidden='true'>").text("جزئیات")
            )
        )
      )
    );
    if (item.can_resume_payment === true || item.can_check_payment === true) {
      $tr.find(".sgp-actions__buttons").prepend($("<button type='button' class='sg-button sg-button--primary sgp-checkout'>")
        .attr("data-sgp-resume-payment", item.can_resume_payment === true ? "true" : "false")
        .text(item.can_resume_payment === true ? "ادامه پرداخت" : "بررسی وضعیت پرداخت"));
    }
    if (item.can_print_label === true) {
      $tr.find(".sgp-actions__buttons").prepend(
        $("<button type='button' class='button button-small sgp-row-label'>")
          .attr("aria-label", "چاپ برچسب پستی مرسوله " + fa(id))
          .append($("<span class='dashicons dashicons-printer' aria-hidden='true'>"), $("<span>").text("چاپ برچسب پستی"))
      );
    }

    return $tr;
  }

  // Clipboard API requires a secure context; LAN HTTP uses the click-bound fallback.
  $page.on("click", "[data-sgp-copy-tracking]", function () {
    var $button = $(this), code = $button.attr("data-code");
    if (!code || $button.data("copy-pending")) return;
    $button.data("copy-pending", true);
    var resetTimer = $button.data("copy-reset-timer");
    if (resetTimer) window.clearTimeout(resetTimer);
    function finish(copied) {
      $button.removeData("copy-pending").toggleClass("is-copied", copied)
        .attr("title", copied ? "کپی شد" : "کپی کد رهگیری");
      $button.find(".dashicons").toggleClass("dashicons-admin-page", !copied).toggleClass("dashicons-yes", copied);
      var message = copied ? "کد رهگیری کپی شد." : "کپی انجام نشد؛ کد رهگیری را انتخاب و دستی کپی کنید.";
      $page.find("[data-sgp-copy-status]").text(message);
      if (typeof window.woosalamNotify === "function") window.woosalamNotify(message, copied ? "success" : "error");
      if (copied) $button.data("copy-reset-timer", window.setTimeout(function () {
        $button.removeClass("is-copied").attr("title", "کپی کد رهگیری").removeData("copy-reset-timer");
        $button.find(".dashicons").removeClass("dashicons-yes").addClass("dashicons-admin-page");
      }, 2000));
    }
    function fallback() {
      var active = document.activeElement;
      var $input = $("<textarea readonly>").val(code).css({ position: "fixed", top: "0", left: "-9999px" }).appendTo(document.body);
      var copied = false;
      try { $input[0].focus({ preventScroll: true }); $input[0].select(); copied = document.execCommand("copy") === true; } catch (error) { /* Report the actual result below. */ }
      $input.remove();
      if (active && document.contains(active)) active.focus({ preventScroll: true });
      finish(copied);
    }
    if (navigator.clipboard && typeof navigator.clipboard.writeText === "function") {
      try { navigator.clipboard.writeText(code).then(function () { finish(true); }, fallback); }
      catch (error) { fallback(); }
    } else fallback();
  });

  function emptyRow() {
    var constrained = !!((filters.market_name || []).length || filters.status || currentCursor || filters.search);
    var $cell = $("<td colspan='8' class='sgp-empty'>");
    $cell.append(
      $("<div class='sgp-empty__icon' aria-hidden='true'>").append($("<span class='dashicons dashicons-archive'>")),
      $("<h3 class='sgp-empty__title'>").text(filters.search ? "نتیجه‌ای برای این جستجو پیدا نشد" : constrained ? "مرسوله‌ای با این شرایط پیدا نشد" : "هنوز مرسوله‌ای ثبت نکرده‌اید"),
      $("<p class='sgp-empty__text'>").text(filters.search ? "نام گیرنده، شماره سفارش یا نام یکی از اقلام را جستجو کنید." : constrained ? "فیلترهای فعال را از بالای فهرست تغییر دهید." : "برای شروع، اطلاعات اولین مرسوله فروشگاه را ثبت کنید.")
    );
    var $actions = $("<div class='sgp-empty__actions'>");
    if (constrained) {
      if (filters.search) $actions.append($("<button type='button' class='sg-button sg-button--secondary' data-sgp-search-clear>").text("پاک کردن جستجو"));
      $actions.append($("<button type='button' class='sg-button sg-button--secondary' data-sgp-filter-modal>").text("تغییر فیلتر بازار"));
      if (currentCursor) $actions.append($("<button type='button' class='sg-button sg-button--secondary' data-sgp-first-page>").text("بازگشت به صفحه اول"));
    } else {
      $actions.append($("<a class='sg-button sg-button--primary'>").attr("href", config.sendUrl || "admin.php?page=salam_gate&view=send").text("ثبت اولین مرسوله"));
    }
    return $("<tr class='sgp-empty-row'>").append($cell.append($actions));
  }

  function stateFromUrl() {
    var url = new URL(window.location.href);
    var tab = url.searchParams.get("sg_tab") || "all";
    if (["all", "pending", "new", "barcode"].indexOf(tab) === -1) tab = "all";
    var markets = [];
    var allowedMarkets = config.markets || [];
    url.searchParams.forEach(function (value, key) {
      if (!/^(sg_market|sg_markets|market_names)(\[\d*\])?$/.test(key)) return;
      value.split(",").forEach(function (market) {
        if (allowedMarkets.indexOf(market) !== -1 && markets.indexOf(market) === -1) markets.push(market);
      });
    });
    var rawStatus = tabStatus(tab) || url.searchParams.get("sg_status") || "";
    rawStatus = rawStatus.replace(/[۰-۹]/g, function (digit) { return "۰۱۲۳۴۵۶۷۸۹".indexOf(digit); })
      .replace(/[٠-٩]/g, function (digit) { return "٠١٢٣٤٥٦٧٨٩".indexOf(digit); });
    var status = /^[0-9]+$/.test(rawStatus) ? String(Number(rawStatus)) : "";
    if (!Object.prototype.hasOwnProperty.call(config.statusLabels || {}, status)) status = "";
    return {
      tab: tab,
      market_name: markets,
      status: status,
      sort: url.searchParams.get("sg_sort") === "asc" ? "asc" : "desc",
      search: (url.searchParams.get("sg_search") || "").slice(0, 120),
      cursor: url.searchParams.get("sg_cursor") || ""
    };
  }

  function tabStatus(tab) {
    return { pending: "7", new: "8", barcode: "9" }[tab] || "";
  }

  function stateUrl(state, cursor) {
    var url = new URL(window.location.href);
    var oldMarketKeys = [];
    url.searchParams.forEach(function (value, key) {
      if (/^(sg_market|sg_markets|market_names)(\[.*\])?$/.test(key)) oldMarketKeys.push(key);
    });
    oldMarketKeys.forEach(function (key) { url.searchParams.delete(key); });
    (state.market_name || []).forEach(function (market) { url.searchParams.append("sg_market[]", market); });
    url.searchParams.set("sg_tab", state.tab || "all");
    url.searchParams.set("sg_sort", state.sort || "desc");
    if (state.search) url.searchParams.set("sg_search", state.search); else url.searchParams.delete("sg_search");
    if (state.tab === "all" && state.status) url.searchParams.set("sg_status", state.status);
    else url.searchParams.delete("sg_status");
    if (cursor) url.searchParams.set("sg_cursor", cursor);
    else url.searchParams.delete("sg_cursor");
    return url.toString();
  }

  function updateFilterControls() {
    if ($page.find("[data-sgp-search]").val() !== filters.search) $page.find("[data-sgp-search]").val(filters.search);
    $page.find(".sgp-search__clear").prop("hidden", !filters.search);
    var selectedMarket = (filters.market_name || [])[0] || "";
    $filterModal.find("input[name='sgp-market']").each(function () {
      $(this).prop("checked", this.value === selectedMarket);
    });
    var $filterButton = $page.find("button[data-sgp-filter-modal]");
    var marketLabel = filters.market_name.length ? filters.market_name.map(function (market) {
      return (config.marketLabels || {})[market] || market;
    }).join("، ") : "همه بازارها";
    $filterButton.find("[data-sgp-filter-label]").text(marketLabel);
    $filterButton.filter(".sgp-tool").toggleClass("is-active", filters.market_name.length > 0).attr({
      "aria-label": "فیلتر بازار: " + marketLabel,
      title: "فیلتر بازار: " + marketLabel,
      "data-sgp-tooltip": "فیلتر بازار: " + marketLabel
    });
    $page.find("[data-sgp-sort]").val(filters.sort);
    var sortLabel = "ترتیب نمایش: " + (filters.sort === "asc" ? "قدیمی‌ترین" : "جدیدترین");
    $page.find("[data-sgp-sort-control]").toggleClass("is-active", filters.sort === "asc")
      .attr({ "data-sort": filters.sort, title: sortLabel, "data-sgp-tooltip": sortLabel })
      .find(".sg-picker-trigger").attr("title", sortLabel);
    $page.find("[data-sgp-status]").val(filters.status);
    var $chips = $page.find("[data-sgp-filter-chips]").empty();
    (filters.market_name || []).forEach(function (market) {
      var label = (config.marketLabels || {})[market] || market;
      $chips.append($("<button type='button' class='sgp-filter-chip'>")
        .attr({ "data-sgp-remove-market": market, "aria-label": "حذف فیلتر بازار " + label })
        .append($("<span>").text("بازار: " + label), $("<span aria-hidden='true'>").text("×")));
    });
    if (filters.status) {
      var statusLabel = (config.statusLabels || {})[filters.status] || "وضعیت انتخاب‌شده";
      $chips.append($("<button type='button' class='sgp-filter-chip' data-sgp-remove-status aria-label='حذف فیلتر وضعیت'>")
        .append($("<span>").text("وضعیت: " + statusLabel), $("<span aria-hidden='true'>").text("×")));
    }
    var hasFilters = !!((filters.market_name || []).length || filters.status);
    $chips.prop("hidden", !hasFilters);
    $page.find("[data-sgp-tab]").each(function () {
      var tab = $(this).attr("data-sgp-tab");
      var current = tab === filters.tab;
      var tabState = $.extend({}, filters, { tab: tab, status: tabStatus(tab) });
      $(this).toggleClass("is-active", current).attr("href", stateUrl(tabState, ""));
      if (current) $(this).attr("aria-current", "page");
      else $(this).removeAttr("aria-current");
    });
  }

  function renderSearchPage(cursor, historyMode) {
    var offset = /^search:\d+$/.test(cursor || "") ? Number(cursor.slice(7)) : 0;
    if (offset >= searchResults.length) offset = Math.max(0, Math.floor((searchResults.length - 1) / 30) * 30);
    currentCursor = offset > 0 ? "search:" + offset : "";
    if (historyMode) window.history[historyMode === "push" ? "pushState" : "replaceState"]({}, "", stateUrl(filters, currentCursor));
    listedParcels = Object.create(null);
    var $body = $("#sgp-body").empty();
    if (!searchResults.length) $body.append(emptyRow());
    searchResults.slice(offset, offset + 30).forEach(function (item) { $body.append(rowNode(item)); });
    $page.find(".sgp-prev").attr("data-cursor", "search:" + Math.max(0, offset - 30)).prop("disabled", offset === 0);
    $page.find(".sgp-next").attr("data-cursor", "search:" + (offset + 30)).prop("disabled", offset + 30 >= searchResults.length);
    $page.find("[data-sgp-search-status]").prop("hidden", false).text(fa(searchResults.length) + " مرسوله پیدا شد");
    updateLabelActions();
  }

  function loadPage(cursor, historyMode) {
    currentCursor = cursor || "";
    filters.cursor = currentCursor;
    updateFilterControls();
    if (historyMode) {
      var url = stateUrl(filters, currentCursor);
      if (url !== window.location.href) window.history[historyMode === "replace" ? "replaceState" : "pushState"]({}, "", url);
    }
    var sequence = ++listSequence;
    var searching = !!filters.search;
    var listLoading = (!searching || $page.attr("data-sg-load-state") === "loading") && window.WoosalamGateLoading
      && window.WoosalamGateLoading.begin($page[0], "list", function () { loadPage(currentCursor, "replace"); });
    if (listRequest && typeof listRequest.abort === "function") listRequest.abort();
    listedParcels = Object.create(null);
    var $body = $("#sgp-body").html("<tr><td colspan='8' class='sgp-empty' role='status'>در حال دریافت مرسوله‌ها…</td></tr>");
    updateLabelActions();

    $page.find(".sgp-table-wrap").attr("aria-busy", "true");
    $page.find("[data-sgp-initial-error]").remove();
    $page.find(".sgp-next, .sgp-prev").prop("disabled", true);
    searchResults = null;
    var requestFilters = $.extend({}, filters), found = [], seen = Object.create(null), seenCursors = Object.create(null), searchConnection = null;
    searchKey = JSON.stringify([filters.search, filters.market_name, filters.status, filters.sort]);
    $page.find("[data-sgp-search-status]").prop("hidden", !searching).text(searching ? "در حال جستجو در مرسوله‌ها…" : "");

    function requestPage(providerCursor) {
      var request = sgPost("salam_gate_parcels_list", $.extend({}, requestFilters, { cursor: providerCursor }));
      listRequest = request;
      request
        .done(function (res) {
          if (sequence !== listSequence) return;
          if (!res || !res.success || !res.data || !Array.isArray(res.data.items)) {
            var error = listError($body, (res && res.data && res.data.message) || "فهرست مرسوله‌ها دریافت نشد؛ دوباره تلاش کنید.", !listLoading);
            if (listLoading) listLoading.fail(error);
            return;
          }
          var data = res.data || {};
          if (searching) {
            if (typeof data.connection_id === "string") {
              if (searchConnection !== null && searchConnection !== data.connection_id) {
                listError($body, "حساب متصل تغییر کرده است؛ فهرست را دوباره بررسی کنید.");
                if (listLoading) listLoading.fail("حساب متصل تغییر کرده است؛ فهرست را دوباره بررسی کنید.");
                return;
              }
              searchConnection = data.connection_id;
            }
            if (listLoading) { listLoading.ready(); listLoading = null; }
            data.items.forEach(function (item) {
              if (!seen[String(item.id)]) { seen[String(item.id)] = true; found.push(item); }
            });
            var next = typeof data.next_cursor === "string" ? data.next_cursor : "";
            if (next) {
              if (seenCursors[next]) {
                listError($body, "جستجو کامل نشد؛ فهرست را دوباره بررسی کنید.");
                return;
              }
              seenCursors[next] = true;
              $page.find("[data-sgp-search-status]").text("در حال جستجو… " + fa(found.length) + " نتیجه پیدا شد");
              requestPage(next);
              return;
            }
            searchResults = found;
            renderSearchPage(currentCursor, "replace");
            return;
          }
          cursorState = { next: data.next_cursor || "", prev: data.previous_cursor || "" };
          $body.empty();
          if (!(data.items || []).length) {
            $body.append(emptyRow());
          } else {
            $.each(data.items, function (i, item) { $body.append(rowNode(item)); });
          }

          $page.find(".sgp-next").attr("data-cursor", cursorState.next).prop("disabled", !cursorState.next);
          $page.find(".sgp-prev").attr("data-cursor", cursorState.prev).prop("disabled", !cursorState.prev);
          if (listLoading) listLoading.ready();
        })
        .fail(function (xhr, status) {
          if (sequence !== listSequence || status === "abort") return;
          var error = listError($body, "ارتباط برای دریافت مرسوله‌ها برقرار نشد؛ دوباره تلاش کنید.", !listLoading);
          if (listLoading) listLoading.fail(error);
        })
        .always(function () {
          if (sequence !== listSequence || listRequest !== request) return;
          listRequest = null;
          $page.find(".sgp-table-wrap").attr("aria-busy", "false");
          updateLabelActions();
        });
    }
    requestPage(searching ? "" : currentCursor);
  }

  $page.on("submit", "[data-sgp-search-form]", function (event) {
    event.preventDefault();
    window.clearTimeout(searchTimer);
    filters.search = String($page.find("[data-sgp-search]").val() || "").trim().slice(0, 120);
    loadPage("", "push");
  });
  $page.on("input", "[data-sgp-search]", function () {
    window.clearTimeout(searchTimer);
    var value = String(this.value || "").trim().slice(0, 120);
    $page.find(".sgp-search__clear").prop("hidden", !value);
    searchTimer = window.setTimeout(function () { filters.search = value; loadPage("", "replace"); }, 400);
  });
  $page.on("click", "[data-sgp-search-clear]", function () {
    window.clearTimeout(searchTimer); filters.search = "";
    $page.find("[data-sgp-search]").val("").trigger("focus"); loadPage("", "push");
  });

  $page.on("click", "[data-sgp-retry]", function () { loadPage(currentCursor, "replace"); });
  $(document).on("sgp:refresh", function () { loadPage(currentCursor, "replace"); });

  // A paid or newly prepared row changes eligibility even if the bulk dialog
  // stays open after a partial failure. Refresh behind it without paying again.
  // Switching browser tabs or focusing the address bar must not erase rows,
  // selections or payment actions. The checkout revalidates current server state.
  $page.on("click", ".sgp-checkout", function () {
    var item = listedParcels[String($(this).closest("tr").attr("data-parcel-id"))];
    if (item && (item.can_resume_payment === true || item.can_check_payment === true)) $(document).trigger("sgp:checkout", [item]);
  });

  function focusFilters() {
    var $next = $page.find("[data-sgp-filter-chips] .sgp-filter-chip").first();
    ($next.length ? $next : $page.find("button[data-sgp-filter-modal]")).trigger("focus");
  }

  $page.on("click", "[data-sgp-remove-market]", function () {
    var market = $(this).attr("data-sgp-remove-market");
    filters.market_name = (filters.market_name || []).filter(function (selected) { return selected !== market; });
    loadPage("", "push");
    focusFilters();
  });
  $page.on("click", "[data-sgp-remove-status]", function () {
    filters.tab = "all";
    filters.status = "";
    loadPage("", "push");
    focusFilters();
  });
  $page.on("click", "[data-sgp-first-page]", function () { loadPage("", "push"); });

  // تغییر فیلتر/مرتب‌سازی/تب بدون بارگذاری کامل؛ URL برای رفرش و عقب/جلو حفظ می‌شود.
  $page.on("click", ".sgp-next, .sgp-prev", function () {
    var cursor = $(this).attr("data-cursor");
    if (filters.search && searchResults && searchKey === JSON.stringify([filters.search, filters.market_name, filters.status, filters.sort])) {
      renderSearchPage(cursor, "push"); return;
    }
    if (cursor && !$(this).prop("disabled")) loadPage(cursor, "push");
  });

  $page.find("[data-sgp-sort]").on("change", function () {
    filters.sort = $(this).val() === "asc" ? "asc" : "desc";
    loadPage("", "push");
  });
  $page.on("change", "[data-sgp-status]", function () {
    var status = String($(this).val() || "");
    filters.status = Object.prototype.hasOwnProperty.call(config.statusLabels || {}, status) ? status : "";
    filters.tab = "all";
    loadPage("", "push");
  });

  $page.on("click", "[data-sgp-tab]", function (event) {
    if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.which > 1) return;
    event.preventDefault();
    filters.tab = $(this).attr("data-sgp-tab");
    filters.status = tabStatus(filters.tab);
    loadPage("", "push");
  });

  $(window).on("popstate.salamGateParcels", function () {
    filters = stateFromUrl();
    loadPage(filters.cursor);
  });

  // ---------- مودال فیلتر بازار ----------
  $page.on("click", "button[data-sgp-filter-modal]", function () {
    updateFilterControls();
    $filterModal.show();
  });
  $(document).on("click", "[data-sgp-filter-modal].sg-modal-overlay [data-close-modal]", function () {
    $(this).closest(".sg-modal-overlay").hide();
  });
  $(document).on("click", "[data-sgp-filter-modal].sg-modal-overlay", function (e) {
    if (e.target === this) $(this).hide();
  });

  $(document).on("click", "[data-sgp-filter-apply]", function () {
    var market = $filterModal.find("input[name='sgp-market']:checked").val() || "";
    filters.market_name = market ? [market] : [];
    $filterModal.hide();
    loadPage("", "push");
  });

  $(document).on("click", "[data-sgp-filter-clear]", function () {
    filters.market_name = [];
    $filterModal.hide();
    loadPage("", "push");
  });

  // ---------- برچسب پستی: وضعیت job + polling با request_id مستقل ----------
  var pollTimer = null;
  var activeJobId = 0;
  var jobSequence = 0;
  var labelRequestPending = false;
  var latestJobPending = false;
  var jobStorageKey = "salamGateLabelJob:" + window.location.origin + ":" + (config.userId || 0);

  function validJobId(value) {
    if (typeof value !== "number" && !(typeof value === "string" && /^[1-9]\d*$/.test(value))) return 0;
    var number = Number(value);
    return Number.isSafeInteger(number) && number > 0 ? number : 0;
  }

  function updateLabelActions() {
    $page.find(".sgp-row-label, [data-sgp-print-modal], [data-sgp-print-toggle]")
      .add($("[data-sgp-print-modal-el] [data-sgp-print-submit]"))
      .prop("disabled", labelRequestPending || latestJobPending);
    updateSelection();
  }

  function selectedIds() {
    return Object.keys(selectedParcels).filter(function (id) {
      return labelCandidates[id] && labelCandidates[id].can_print_label === true;
    });
  }

  function selectionPrintMode() {
    return $printModal.find("[name='sgp-print-mode']:checked").val() === "selection";
  }

  function updateSelection() {
    var ids = selectedIds();
    var pending = labelRequestPending || latestJobPending;
    $printModal.find("[data-sgp-selected-count]").text(fa(ids.length) + " مرسوله انتخاب شده");
    $printModal.find("[data-sgp-clear-selection]").prop("disabled", !ids.length || pending);
    $printModal.find("[data-sgp-print-submit]").prop("disabled", pending || (selectionPrintMode() && (!ids.length || !!labelListRequest)));
    $printModal.find("[name='sgp-print-mode'], [name='sgp-template'], [data-sgp-select]").prop("disabled", pending);
    $printModal.find("[data-sgp-label-list-more]").prop("hidden", !labelListHasMore)
      .prop("disabled", pending || !!labelListRequest).text(labelListError ? "تلاش دوباره" : "نمایش مرسوله‌های بیشتر");
    $printModal.find("[data-sgp-label-list-state]").text(labelListRequest ? "در حال دریافت مرسوله‌های قابل چاپ…"
      : labelListError || (labelListReady ? fa(Object.keys(labelCandidates).length) + " مرسوله قابل چاپ" : ""));
  }

  function renderLabelCandidates() {
    var $items = $printModal.find("[data-sgp-label-candidates]").empty();
    Object.keys(labelCandidates).forEach(function (id) {
      var item = labelCandidates[id];
      $items.append($("<label class='sgp-print-choice'>").append(
        $("<input type='checkbox' data-sgp-select>").attr({ value: id, "aria-label": "انتخاب مرسوله " + fa(id) + " برای چاپ" }).prop("checked", !!selectedParcels[id]),
        $("<span class='sgp-print-choice__content'>").append(
          $("<strong>").text(item.receiver_name || "گیرنده مرسوله"),
          $("<small>").text("سفارش " + fa(item.order_number || item.external_id || item.id) + (item.market_label ? " · " + item.market_label : "")),
          $("<span class='sgp-print-choice__contents'>").text(item.contents || "").attr("title", item.contents || "")
        ),
        $("<span class='sgp-print-choice__receipt'>").append(
          $("<small>").text("پرداخت شده"), $("<bdi dir='ltr'>").text(item.tracking_code || "")
        )
      ));
    });
    if (!$items.children().length && labelListReady && !labelListError) {
      $items.append($("<p class='sgp-print-picker__empty'>").text(labelListHasMore
        ? "در این بخش مرسوله قابل چاپی نیست؛ مرسوله‌های بیشتری را نمایش دهید." : "مرسوله‌ای با پرداخت تکمیل‌شده و کد رهگیری پیدا نشد."));
    }
    updateSelection();
  }

  function resetLabelSelection() {
    ++labelListVersion;
    if (labelListRequest && typeof labelListRequest.abort === "function") labelListRequest.abort();
    labelListRequest = null;
    selectedParcels = Object.create(null); labelCandidates = Object.create(null); labelListSeen = Object.create(null);
    labelListCursor = ""; labelListHasMore = true; labelListReady = false; labelListConnection = null; labelListError = "";
    $printModal.find("[data-sgp-label-candidates]").empty();
  }

  function loadLabelCandidates() {
    if (labelListRequest || !labelListHasMore || labelRequestPending || latestJobPending) return;
    var version = ++labelListVersion, cursor = labelListCursor;
    labelListError = "";
    var request = sgPost("salam_gate_parcels_list", { cursor: cursor, status: "", sort: "desc", market_name: filters.market_name || [], label_selection: "1" });
    labelListRequest = request;
    updateSelection();
    function failed(message) {
      labelListError = message;
      if (typeof window.woosalamNotify === "function") window.woosalamNotify(message, "error");
      renderLabelCandidates();
    }
    request.done(function (res) {
      if (version !== labelListVersion) return;
      var data = res && res.success === true && res.data;
      if (!data || !Array.isArray(data.items) || !data.items.every(function (item) { return $.isPlainObject(item) && validJobId(item.id); })) {
        failed("فهرست مرسوله‌های قابل چاپ دریافت نشد؛ دوباره تلاش کنید."); return;
      }
      if (typeof data.connection_id === "string") {
        if (labelListConnection !== null && labelListConnection !== data.connection_id) {
          selectedParcels = Object.create(null); labelCandidates = Object.create(null);
          labelListCursor = ""; labelListConnection = null; labelListSeen = Object.create(null);
          failed("حساب متصل تغییر کرده است؛ فهرست را دوباره دریافت کنید."); return;
        }
        labelListConnection = data.connection_id;
      }
      labelListSeen[cursor] = true;
      data.items.forEach(function (item) {
        var id = String(item.id);
        if (item.can_print_label === true) labelCandidates[id] = item;
        else { delete labelCandidates[id]; delete selectedParcels[id]; }
      });
      labelListReady = true;
      var next = typeof data.next_cursor === "string" ? data.next_cursor : "";
      if (next && labelListSeen[next]) {
        labelListHasMore = false;
        failed("ادامه فهرست مرسوله‌ها تأیید نشد؛ پنجره چاپ را دوباره باز کنید."); return;
      }
      labelListCursor = next; labelListHasMore = !!next;
      renderLabelCandidates();
    }).fail(function (_, status) {
      if (version !== labelListVersion || status === "abort") return;
      failed("ارتباط برای دریافت مرسوله‌های قابل چاپ برقرار نشد؛ دوباره تلاش کنید.");
    }).always(function () {
      if (version !== labelListVersion || labelListRequest !== request) return;
      labelListRequest = null; updateSelection();
    });
  }

  $(document).on("change", "[data-sgp-print-modal-el] [data-sgp-select]", function () {
    var id = String(this.value);
    if (this.checked && labelCandidates[id] && labelCandidates[id].can_print_label === true) {
      if (!selectedParcels[id] && selectedIds().length >= 100) {
        this.checked = false;
        if (typeof window.woosalamNotify === "function") window.woosalamNotify("در هر درخواست چاپ حداکثر ۱۰۰ مرسوله را انتخاب کنید.", "error");
      } else selectedParcels[id] = true;
    }
    else delete selectedParcels[id];
    updateSelection();
  });
  $(document).on("click", "[data-sgp-print-modal-el] [data-sgp-clear-selection]", function () {
    var $firstSelected = $printModal.find("[data-sgp-select]:checked").first();
    selectedParcels = Object.create(null);
    $printModal.find("[data-sgp-select]").prop("checked", false);
    updateSelection();
    $firstSelected.trigger("focus");
  });
  $(document).on("click", "[data-sgp-label-list-more]", loadLabelCandidates);
  $(document).on("change", "[data-sgp-print-modal-el] [name='sgp-print-mode']", function () {
    var selection = selectionPrintMode();
    $printModal.find("[data-sgp-print-date-view]").prop("hidden", selection);
    $printModal.find("[data-sgp-print-selection-view]").prop("hidden", !selection);
    $printModal.find(".sgp-print-dialog").toggleClass("is-selection", selection);
    $("[data-sgp-print-notice]").hide().empty();
    if (selection && !labelListReady) loadLabelCandidates();
    updateSelection();
  });

  $page.on("click", ".sgp-row-label", function () {
    requestLabelForIds([String($(this).closest("tr").attr("data-parcel-id"))], defaultLabelTemplate);
  });

  function restoreLabelJob(previous) {
    // A failed/unknown PDF request can be regenerated safely; this does not
    // retry parcel registration or payment. Only known job IDs are resumable.
    activeJobId = previous.id;
    latestJobPending = previous.latest;
    try {
      if (latestJobPending) window.sessionStorage.setItem(jobStorageKey, "latest");
      else if (activeJobId) window.sessionStorage.setItem(jobStorageKey, String(activeJobId));
      else window.sessionStorage.removeItem(jobStorageKey);
    } catch (error) { /* Private mode. */ }
    updateLabelActions();
  }

  function rememberJob(value) {
    activeJobId = validJobId(value);
    if (activeJobId) {
      latestJobPending = false;
      try { window.sessionStorage.setItem(jobStorageKey, String(activeJobId)); } catch (error) { /* Private mode. */ }
    }
    updateLabelActions();
  }

  function markLatestJob() {
    activeJobId = 0;
    latestJobPending = true;
    // A transport interruption before request_id arrives must not restore an
    // older request after refresh. Only an explicit status GET can reconcile it.
    try { window.sessionStorage.setItem(jobStorageKey, "latest"); } catch (error) { /* Private mode. */ }
    updateLabelActions();
  }

  function recoverLabel(text, noAuto, kind) {
    var floating = kind !== "info" && typeof window.woosalamNotify === "function";
    var $status = $page.find(".sgp-label-status").empty().show().toggleClass("sg-feedback-actions", floating).attr("role", floating ? "group" : (kind === "info" ? "status" : "alert"));
    $status.append($("<span>").text(text).toggleClass("sg-feedback-toast-only", floating));
    if (floating) window.woosalamNotify(text, "error");
    if (activeJobId) {
      $status.append(document.createTextNode(" — درخواست #" + fa(activeJobId) + " "),
        $("<button type='button' class='button button-small' data-sgp-resume-label>").text("بررسی وضعیت فایل"));
    } else if (latestJobPending) {
      $status.append(document.createTextNode(noAuto
        ? " برای بررسی دوباره وضعیت، کمی بعد صفحه را تازه کنید."
        : " وضعیت آخرین فایل به‌صورت خودکار بررسی می‌شود."));
    }
  }

  function safeLabelUrl(value) {
    if (typeof value !== "string" || !/^https?:\/\//i.test(value)) return null;
    try {
      var url = new URL(value);
      return ["https:", "http:"].indexOf(url.protocol) !== -1 && !url.username && !url.password ? url.href : null;
    } catch (error) { return null; }
  }

  var reservedLabelWindow = null;
  function reserveLabelWindow() {
    // Reserve from the actual click before asynchronous PDF generation.
    closeReservedLabelWindow();
    try {
      reservedLabelWindow = window.open("about:blank", "_blank");
      if (reservedLabelWindow) {
        reservedLabelWindow.opener = null;
        reservedLabelWindow.document.title = "در حال دریافت فایل چاپ…";
      }
    } catch (_) { reservedLabelWindow = null; }
  }
  function closeReservedLabelWindow() {
    if (reservedLabelWindow) {
      try { reservedLabelWindow.close(); } catch (_) { /* Already closed. */ }
      reservedLabelWindow = null;
    }
  }
  function nativeLabelUrl(requestId) {
    if (!validJobId(requestId) || !window.basalam_ajax || !window.basalam_ajax.nonce) return null;
    try {
      var url = new URL(window.basalam_ajax.ajaxurl, window.location.href);
      if (url.origin !== window.location.origin || url.username || url.password) return null;
      url.search = ""; url.hash = "";
      url.searchParams.set("action", "salam_gate_label_preview");
      url.searchParams.set("_wpnonce", window.basalam_ajax.nonce);
      url.searchParams.set("request_id", String(requestId));
      return url.href;
    } catch (_) { return null; }
  }

  function readyLabel(result) {
    var url = safeLabelUrl(result.url) && nativeLabelUrl(result.request_id);
    // An API timestamp without an offset is not browser-local time. The
    // authenticated PDF read revalidates the job; don't guess its timezone.
    var explicitExpiry = typeof result.expire_at === "string" && /(?:Z|[+-]\d{2}:?\d{2})$/i.test(result.expire_at)
      ? Date.parse(result.expire_at) : NaN;
    if (!url || Number.isFinite(explicitExpiry) && explicitExpiry <= Date.now()) {
      closeReservedLabelWindow();
      recoverLabel("نشانی فایل چاپ معتبر نیست؛ وضعیت فایل را دوباره بررسی کنید.");
      return;
    }
    var floating = typeof window.woosalamNotify === "function";
    // Stream the original PDF to the native browser viewer, preserving embedded
    // fonts and its actual print/download controls instead of rendering canvas.
    $page.find(".sgp-label-status").empty().removeClass("sg-feedback-actions sg-feedback-toast-only")
      .attr("role", "status").text(floating ? "" : "فایل چاپ آماده است.").toggle(!floating);
    var target = reservedLabelWindow;
    reservedLabelWindow = null;
    try {
      if (!target || target.closed) target = window.open("about:blank", "_blank");
      if (!target) throw new Error("Popup blocked");
      target.opener = null;
      target.location.replace(url);
    } catch (_) {
      $page.find(".sgp-label-status").empty().append($("<a target='_blank' rel='noopener noreferrer'>")
        .attr("href", url).text("مشاهده فایل PDF چاپ")).show();
      if (floating) window.woosalamNotify("مرورگر بازشدن تب PDF را مسدود کرد؛ از لینک مشاهده PDF استفاده کنید.", "warning");
      return;
    }
    if (floating) window.woosalamNotify("فایل چاپ آماده است.", "success");
  }

  function labelResult(result, sequence) {
    if (sequence !== jobSequence) return;
    if (result.status === "not_found") {
      // JSON null is a valid latest-job read, not an invalid API response. An
      // absent job still cannot prove an interrupted create will never appear.
      stopPolling();
      closeReservedLabelWindow();
      recoverLabel("درخواست چاپ پیدا نشد؛ نتیجه درخواست قبلی هنوز تأیید نشده است. درخواست جدید ثبت نکنید.", true);
      return;
    }
    if (activeJobId && validJobId(result.request_id) && validJobId(result.request_id) !== activeJobId) {
      stopPolling(); closeReservedLabelWindow();
      recoverLabel("پاسخ فایل مربوط به این درخواست نیست؛ وضعیت همین فایل را دوباره بررسی کنید.");
      return;
    }
    if (validJobId(result.request_id)) rememberJob(result.request_id);
    if (result.status === "completed" && result.url) {
      readyLabel(result);
    } else if (result.status === "failed" || result.status === "expired") {
      closeReservedLabelWindow();
      stopPolling();
      recoverLabel(readableMessage(result.message, "ساخت فایل چاپ انجام نشد؛ می‌توانید درخواست جدید ثبت کنید."));
    } else if (!activeJobId) {
      closeReservedLabelWindow();
      // An unscoped latest request can belong to another selected parcel/tab.
      // Regenerating this PDF is safe; never replay shipping or payment here.
      restoreLabelJob({ id: 0, latest: false });
      recoverLabel("فایل چاپ تأیید نشد؛ دوباره درخواست چاپ را بزنید. ثبت مرسوله یا پرداخت دوباره انجام نمی‌شود.");
    } else {
      statusText("در حال آماده‌سازی فایل چاپ… (درخواست #" + fa(activeJobId) + ")");
      pollLabel(activeJobId, result.retry_after || 5, sequence);
    }
  }

  function stopPolling() {
    if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
  }

  // خروج از صفحه polling را متوقف می‌کند (نتیجه با request_id قابل بازیابی می‌ماند)
  $(window).on("beforeunload pagehide", function () { stopPolling(); closeReservedLabelWindow(); });

  function pollLabel(requestId, retryAfter, sequence) {
    stopPolling();
    if (requestId) rememberJob(requestId);
    if (sequence == null) sequence = ++jobSequence;
    pollTimer = setTimeout(function () {
      if (sequence !== jobSequence) return;
      sgPost("salam_gate_label_poll", { request_id: requestId || 0 }).done(function (res) {
        if (sequence !== jobSequence) return;
        if (!res || !res.success) {
          closeReservedLabelWindow();
          recoverLabel(readableMessage(res && res.data && res.data.message, "پیگیری فایل متوقف شد؛ دوباره وضعیت همین فایل را بررسی کنید."));
          return;
        }
        labelResult(res.data || {}, sequence);
      }).fail(function () {
        if (sequence !== jobSequence) return;
        closeReservedLabelWindow();
        recoverLabel("ارتباط برقرار نشد؛ درخواست تولید جدید ثبت نکنید و وضعیت همین فایل را بررسی کنید.");
      });
    }, Math.min(60, Math.max(2, Number(retryAfter) || 5)) * 1000);
  }

  function bulkPrintTemplate() {
    return bulkPrintKind === "barcode" ? "barcode" : String($printModal.find("[name='sgp-template']:checked").val() || defaultLabelTemplate);
  }

  function updatePrintSubmitLabel() {
    $printModal.find("[data-sgp-print-submit-label]").text(bulkPrintKind === "barcode" ? "چاپ بارکدها" : "چاپ برچسب‌های پستی");
  }

  function requestLabelForIds(ids, template) {
    if (labelRequestPending || latestJobPending) return;
    ids = ids.filter(function (id) { return (listedParcels[id] && listedParcels[id].can_print_label === true)
      || (labelCandidates[id] && labelCandidates[id].can_print_label === true); });
    if (!ids.length) return;
    reserveLabelWindow();
    labelRequestPending = true;
    stopPolling();
    var previousJob = { id: activeJobId, latest: latestJobPending };
    markLatestJob();
    var sequence = ++jobSequence;
    statusText("در حال ثبت درخواست چاپ…");
    sgPost("salam_gate_bulk_label", { parcel_ids: ids, template: template })
      .done(function (res) {
        if (!res || !res.success) {
          closeReservedLabelWindow();
          restoreLabelJob(previousJob);
          recoverLabel(readableMessage(res && res.data && res.data.message,
            res && res.data && res.data.request_dispatched === false ? "این مرسوله هنوز آماده چاپ نیست؛ وضعیت پرداخت و کد رهگیری را بررسی کنید." : "فایل چاپ دریافت نشد؛ دوباره درخواست چاپ را بزنید. ثبت مرسوله یا پرداخت دوباره انجام نمی‌شود."));
          return;
        }
        $printModal.hide();
        labelResult(res.data || {}, sequence);
      })
      .fail(function () {
        closeReservedLabelWindow();
        restoreLabelJob(previousJob);
        recoverLabel("فایل چاپ دریافت نشد؛ دوباره درخواست چاپ را بزنید. ثبت مرسوله یا پرداخت دوباره انجام نمی‌شود.");
      }).always(function () {
        labelRequestPending = false;
        updateLabelActions();
      });
  }

  $page.on("click", "[data-sgp-resume-label]", function () {
    reserveLabelWindow();
    statusText("در حال بررسی وضعیت فایل…");
    pollLabel(activeJobId, 2);
  });

  // Disclosure menu: opening it never dispatches a PDF job or payment.
  function closePrintMenu(restoreFocus) {
    $printMenu.find("[data-sgp-print-options]").prop("hidden", true);
    $printMenu.find("[data-sgp-print-toggle]").attr("aria-expanded", "false");
    if (restoreFocus) $printMenu.find("[data-sgp-print-toggle]").trigger("focus");
  }
  $page.on("click", "[data-sgp-print-toggle]", function () {
    var open = $(this).attr("aria-expanded") !== "true";
    $(this).attr("aria-expanded", String(open));
    $printMenu.find("[data-sgp-print-options]").prop("hidden", !open);
  });
  $(document).on("click", function (event) {
    if (!$(event.target).closest("[data-sgp-print-menu]").length) closePrintMenu(false);
  });
  $printMenu.on("keydown", function (event) {
    if (event.key === "Escape") { event.preventDefault(); closePrintMenu(true); }
    if (event.key === "ArrowDown" && $(event.target).is("[data-sgp-print-toggle]")) {
      event.preventDefault();
      $printMenu.find("[data-sgp-print-toggle]").attr("aria-expanded", "true");
      $printMenu.find("[data-sgp-print-options]").prop("hidden", false).find("button").first().trigger("focus");
    }
  }).on("focusout", function (event) {
    if (!event.relatedTarget || !this.contains(event.relatedTarget)) closePrintMenu(false);
  });

  // ---------- Batch labels / barcodes by date or selection ----------
  $page.on("click", "[data-sgp-print-modal]", function () {
    if (labelRequestPending || latestJobPending) return;
    bulkPrintKind = $(this).attr("data-sgp-print-kind") === "barcode" ? "barcode" : "label";
    closePrintMenu(false);
    $printModal.find("#sgp-print-title").text(bulkPrintKind === "barcode" ? "چاپ گروهی بارکدها" : "چاپ گروهی برچسب‌های پستی");
    $printModal.find("[data-sgp-label-options]").prop("hidden", bulkPrintKind === "barcode");
    updatePrintSubmitLabel();
    // باز کردن مودال درخواست تولید نمی‌سازد — فقط فرم
    resetLabelSelection();
    $printModal.find("[name='sgp-print-mode'][value='date']").prop("checked", true);
    $printModal.find("[data-sgp-print-date-view]").prop("hidden", false);
    $printModal.find("[data-sgp-print-selection-view]").prop("hidden", true);
    $printModal.find(".sgp-print-dialog").removeClass("is-selection");
    $printModal.show(); updateSelection();
    $("[data-sgp-print-notice]").hide().empty();
  });

  $(document).on("click", "[data-sgp-print-modal-el] [data-close-modal]", function () {
    resetLabelSelection();
    $(this).closest(".sg-modal-overlay").hide();
  });
  $(document).on("click", "[data-sgp-print-modal-el]", function (e) {
    if (e.target === this) { resetLabelSelection(); $(this).hide(); }
  });

  $(document).on("click", "[data-sgp-print-submit]", function () {
    if (labelRequestPending || latestJobPending) return;
    if (selectionPrintMode()) {
      if (labelListRequest || !selectedIds().length) return;
      requestLabelForIds(selectedIds(), bulkPrintTemplate());
      return;
    }
    var $n = $("[data-sgp-print-notice]").hide().empty();
    function printError(message) {
      var floating = typeof window.woosalamNotify === "function";
      $n.text(message).toggleClass("sg-feedback-toast-only", floating).attr("role", floating ? "group" : "alert").show();
      if (floating) window.woosalamNotify(message, "error");
    }
    var from = String($("[data-sgp-from]").val() || "").trim();
    var to = String($("[data-sgp-to]").val() || "").trim();
    if (!from || !to) {
      printError("تاریخ شروع و پایان بازه چاپ را انتخاب کنید.");
      (!from ? $("[data-sgp-from]") : $("[data-sgp-to]")).trigger("focus");
      return;
    }
    labelRequestPending = true;
    stopPolling();
    var previousJob = { id: activeJobId, latest: latestJobPending };
    markLatestJob();
    var sequence = ++jobSequence;
    $(this).prop("disabled", true).find("[data-sgp-print-submit-label]").text("در حال ثبت…");
    var markets = filters.market_name && filters.market_name.length ? filters.market_name : [];

    reserveLabelWindow();
    sgPost("salam_gate_bulk_label_by_date", { from_jalali: from, to_jalali: to, template: bulkPrintTemplate(), market_names: markets })
      .done(function (res) {
        if (res && res.success && res.data) {
          $("[data-sgp-print-modal-el]").hide();
          labelResult(res.data || {}, sequence);
          return;
        }
        restoreLabelJob(previousJob);
        printError(readableMessage(res && res.data && res.data.message, "درخواست چاپ ثبت نشد؛ تاریخ‌ها و مرسوله‌های قابل چاپ را بررسی کنید."));
        closeReservedLabelWindow();
      })
      .fail(function () {
        $("[data-sgp-print-modal-el]").hide();
        closeReservedLabelWindow();
        restoreLabelJob(previousJob);
        recoverLabel("فایل چاپ دریافت نشد؛ دوباره درخواست چاپ را بزنید. ثبت مرسوله یا پرداخت دوباره انجام نمی‌شود.");
      }).always(function () {
        labelRequestPending = false;
        updatePrintSubmitLabel();
        updateLabelActions();
      });
  });

  // ---------- جزئیات + رهگیری ----------
  var $detail = $("#sgp-detail-modal");
  var detailSequence = 0;
  var detailRequest = null;
  var detailItem = null;
  var detailCancelBusy = false;

  function dateText(value) {
    if (typeof value !== "string" || !value) return "—";
    var date = new Date(value);
    return Number.isFinite(date.getTime()) ? date.toLocaleString("fa-IR", { timeZone: "Asia/Tehran", dateStyle: "short", timeStyle: "short" }) : "—";
  }

  function paymentStatusLabel(value) {
    return typeof value === "string" && /پرداخت/.test(value) && /نامعلوم|نامشخص|مشخص نیست|قطعی نیست/.test(value)
      ? "در حال بررسی پرداخت" : value;
  }

  function renderDetail(item) {
    $detail.find(".sgd-id").text(fa(item.id));
    $detail.find("[data-sgd-cancel]").prop("hidden", item.can_cancel !== true);
    $detail.find("[data-sgd-cancel-check]").prop("hidden", item.can_check_cancel !== true);
    var $info = $detail.find(".sgd-info").empty();
    var $summary = $("<div class='sgd-summary'>").append(
      $("<span class='sgd-market'>").text(item.market_label || "—"),
      $("<span class='sgd-status'>").attr("data-status", item.status_id).text(item.can_resume_payment ? "در انتظار پرداخت" : paymentStatusLabel(item.status_label) || "—"));
    if (item.created_at_display) $summary.append($("<span class='sgd-date'>").text(fa(item.created_at_display)));
    $info.append($summary);
    function section(title, icon, rows) {
      var $section = $("<section class='sgd-section'>").append($("<h5>").append(
        $("<span aria-hidden='true' class='dashicons'>").addClass("dashicons-" + icon), document.createTextNode(title)));
      var $rows = $("<dl class='sgd-grid'>");
      rows.forEach(function (row) {
        if (row[1] == null || row[1] === "") return;
        $rows.append($("<div>").toggleClass("sgd-wide", row[2] === true).append($("<dt>").text(row[0]), $("<dd>").text(row[1])));
      });
      return $section.append($rows);
    }
    $info.append(section("گیرنده", "location", [
      ["نام", item.receiver_name], ["شهر", item.receiver_city], ["تلفن", item.receiver_mobile],
      ["کد پستی", item.receiver_postal_code], ["نشانی", item.receiver_address, true]
    ]));
    $info.append(section("اطلاعات ارسال", "archive", [
      ["شیوه ارسال", item.method_title], ["کد رهگیری", item.tracking_code],
      ["وزن", Number.isFinite(item.weight) ? faThousands(item.weight) + " گرم" : null],
      ["بسته‌بندی", item.box_type], ["نوع مرسوله", item.category_title],
      ["هزینه نهایی", Number.isFinite(item.shipping_cost_toman) ? faThousands(item.shipping_cost_toman) + " تومان" : null],
      ["مرکز تحویل", item.delivery_node, true]
    ]));
    var items = Array.isArray(item.items) ? item.items : [];
    $detail.find("[data-sgd-items-panel]").prop("hidden", items.length === 0);
    $detail.find("[data-sgd-items-count]").text(fa(items.length) + " قلم");
    var $items = $detail.find(".sgd-items").empty();
    items.forEach(function (entry) {
      $items.append($("<div class='sg-wizard__review-row'>").append(
        $("<dt>").text(entry.title || "—"),
        $("<dd>").text("× " + (entry.quantity != null ? fa(entry.quantity) : "—"))
      ));
    });
  }

  function cancelDetailRequest() {
    ++detailSequence;
    if (detailRequest && typeof detailRequest.abort === "function") detailRequest.abort();
    detailRequest = null;
    if (window.WoosalamGateLoading) window.WoosalamGateLoading.begin($detail.find("[data-sg-load]")[0], "detail", function () { $detail.find("[data-sgd-refresh]").trigger("click"); }).ready();
    $detail.find("[data-sgd-refresh]").prop("disabled", false).text("به‌روزرسانی جزئیات");
  }

  function detailError(message, announce) {
    message = readableMessage(message, "جزئیات تازه دریافت نشد؛ اطلاعات قبلی حفظ شده است. دوباره تلاش کنید.");
    var floating = typeof window.woosalamNotify === "function";
    $detail.find("[data-sgd-error]").text(message).toggleClass("sg-feedback-toast-only", floating).attr("role", floating ? "group" : "alert").show();
    if (floating && announce !== false) window.woosalamNotify(message, "error");
    return message;
  }

  $page.on("click", ".sgp-detail", function () {
    if (detailCancelBusy) return;
    var item = listedParcels[String($(this).closest("tr").attr("data-parcel-id"))];
    if (!item) return;
    cancelDetailRequest();
    detailItem = $.extend({}, item);
    $detail.find("[data-sgd-error]").hide().empty();
    $detail.find("[data-sgd-cancel-review]").prop("hidden", true);
    renderDetail(detailItem);
    $detail.find("details").prop("open", false);
    $detail.show();
  });

  $(document).on("click", "#sgp-detail-modal [data-sgd-refresh]", function () {
    if (!detailItem || detailRequest || detailCancelBusy) return;
    var parcelId = detailItem.id;
    var sequence = ++detailSequence;
    var detailLoading = window.WoosalamGateLoading && window.WoosalamGateLoading.begin($detail.find("[data-sg-load]")[0], "detail", function () { $detail.find("[data-sgd-refresh]").trigger("click"); });
    $detail.find("[data-sgd-error]").hide().empty();
    $detail.find("[data-sgd-refresh]").prop("disabled", true).text("در حال به‌روزرسانی…");
    detailRequest = sgPost("salam_gate_parcel_detail", { parcel_id: parcelId })
      .done(function (res) {
        if (sequence !== detailSequence) return;
        if (!res || !res.success) {
          var error = detailError(res && res.data && res.data.message, !detailLoading);
          if (detailLoading) detailLoading.fail(error);
          return;
        }
        var data = res.data || {};
        var p = data.parcel || {};
        if (String(p.id) !== String(parcelId)) {
          detailError("جزئیات دریافتی متعلق به این مرسوله نیست؛ دوباره بررسی کنید.", !detailLoading);
          if (detailLoading) detailLoading.fail("جزئیات دریافتی متعلق به این مرسوله نیست؛ دوباره بررسی کنید.");
          return;
        }
        // Detail API intentionally omits list-only invoice/market/tracking fields.
        // Keep those from the last verified list snapshot rather than replacing them with null.
        ["receiver_name", "delivery_node", "weight", "value_toman", "box_type", "created_at_display", "status_label"].forEach(function (key) {
          if (p[key] != null) detailItem[key] = p[key];
        });
        var detail = data.detail || {};
        var receiver = (detail.shipping_info || {}).receiver || {};
        if (receiver.city && receiver.city.title) detailItem.receiver_city = receiver.city.title;
        if (receiver.phone != null) detailItem.receiver_mobile = receiver.phone;
        if (receiver.postal_code != null) detailItem.receiver_postal_code = receiver.postal_code;
        if (receiver.address != null) detailItem.receiver_address = receiver.address;
        if (Array.isArray(detail.items)) detailItem.items = detail.items;
        renderDetail(detailItem);

        if (detailLoading) detailLoading.ready();
      })
      .fail(function (xhr, status) {
        if (sequence !== detailSequence || status === "abort") return;
        detailError("ارتباط قطع شد؛ جزئیات قبلی حفظ شده است. دوباره تلاش کنید.", !detailLoading);
        if (detailLoading) detailLoading.fail("ارتباط قطع شد؛ جزئیات قبلی حفظ شده است. دوباره تلاش کنید.");
      }).always(function () {
        if (sequence !== detailSequence) return;
        detailRequest = null;
        $detail.find("[data-sgd-refresh]").prop("disabled", false).text("به‌روزرسانی جزئیات");
      });
  });

  function updateCancellationRow(item) {
    var id = String(item.id);
    delete selectedParcels[id];
    delete labelCandidates[id];
    if (Array.isArray(searchResults)) searchResults = searchResults.map(function (row) { return String(row.id) === id ? item : row; });
    var $row = $page.find("tr[data-parcel-id]").filter(function () { return $(this).attr("data-parcel-id") === id; });
    if ($row.length) $row.replaceWith(rowNode(item));
    updateLabelActions();
  }

  function cancelParcel(confirm) {
    if (!detailItem || detailCancelBusy || detailRequest) return;
    var id = detailItem.id;
    detailCancelBusy = true;
    $detail.attr("data-sg-financial-busy", "true").attr("aria-busy", "true");
    $detail.find("button").prop("disabled", true);
    $detail.find("[data-sgd-error]").hide().empty();
    $detail.find("[data-sgd-cancel-confirm]").text(confirm ? "در حال لغو…" : "تأیید لغو مرسوله");
    $detail.find("[data-sgd-cancel-check]").text(confirm ? "بررسی وضعیت لغو" : "در حال بررسی…");
    function uncertain(message) {
      if (!detailItem || detailItem.id !== id) return;
      detailItem.can_cancel = false; detailItem.can_check_cancel = true; detailItem.can_print_label = false;
      updateCancellationRow(detailItem); renderDetail(detailItem);
      detailError(message || "پاسخ کامل دریافت نشد؛ «بررسی وضعیت لغو» را بزنید. درخواست لغو خودکار تکرار نمی‌شود.");
    }
    sgPost("salam_gate_parcel_cancel", { parcel_id: id, confirm: confirm ? "1" : "0" })
      .done(function (res) {
        var data = res && res.success === true && res.data;
        if (!data || data.parcel_id !== id || !$.isPlainObject(data.parcel) || data.parcel.id !== id
            || ["cancelled", "review", "eligible", "retryable", "unavailable"].indexOf(data.state) === -1) {
          return uncertain(res && res.data && res.data.message);
        }
        if (data.state === "cancelled" && (data.parcel.status_id !== 44 || data.parcel.invoice_status !== 63 || data.parcel.can_print_label !== false)) return uncertain();
        detailItem = data.parcel;
        updateCancellationRow(detailItem); renderDetail(detailItem);
        $detail.find("[data-sgd-cancel-review]").prop("hidden", true);
        if (data.state === "cancelled" && data.order_synced !== false) {
          if (typeof window.woosalamNotify === "function") window.woosalamNotify(data.message, "success");
          else $detail.find("[data-sgd-error]").text(data.message).attr("role", "status").show();
          $(document).trigger("salamgate:parcel-cancelled", [id]);
        } else detailError(data.message);
      })
      .fail(function (xhr) { uncertain(xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message); })
      .always(function () {
        detailCancelBusy = false;
        $detail.removeAttr("data-sg-financial-busy").attr("aria-busy", "false");
        $detail.find("button").prop("disabled", false);
        $detail.find("[data-sgd-cancel-confirm]").text("تأیید لغو مرسوله");
        $detail.find("[data-sgd-cancel-check]").text("بررسی وضعیت لغو");
        if (detailItem) renderDetail(detailItem);
        $detail.find("[data-sgd-cancel-review]").prop("hidden", true);
        if (detailItem) $detail.find(detailItem.can_check_cancel ? "[data-sgd-cancel-check]"
          : (detailItem.can_cancel ? "[data-sgd-cancel]" : "[data-sgd-refresh]")).trigger("focus");
      });
  }

  $(document).on("click", "#sgp-detail-modal [data-sgd-cancel]", function () {
    if (!detailItem || detailItem.can_cancel !== true || detailCancelBusy || detailRequest) return;
    $detail.find("[data-sgd-cancel-review]").prop("hidden", false);
    $detail.find("[data-sgd-cancel-back]").trigger("focus");
  });
  $(document).on("click", "#sgp-detail-modal [data-sgd-cancel-back]", function () {
    if (detailCancelBusy) return;
    $detail.find("[data-sgd-cancel-review]").prop("hidden", true);
    $detail.find("[data-sgd-cancel]").trigger("focus");
  });
  $(document).on("click", "#sgp-detail-modal [data-sgd-cancel-confirm]", function () {
    if (!detailItem || detailItem.can_cancel !== true || $detail.find("[data-sgd-cancel-review]").prop("hidden")) return;
    cancelParcel(true);
  });
  $(document).on("click", "#sgp-detail-modal [data-sgd-cancel-check]", function () { cancelParcel(false); });

  function closeDetail() { if (detailCancelBusy) return; cancelDetailRequest(); detailItem = null; $detail.hide(); }
  $(document).on("click", "#sgp-detail-modal [data-close-modal]", closeDetail);
  $(document).on("click", "#sgp-detail-modal", function (e) { if (e.target === this) closeDetail(); });
  $(document).on("keydown.sgpDetail", function (event) { if (event.key === "Escape" && detailItem) closeDetail(); });

  updateFilterControls();
  $page.find("[data-sgp-initial-error]").each(function () {
    var $state = $(this), message = $state.attr("data-sgp-initial-error");
    $state.attr("role", "status").prepend($("<p>").text(readableMessage(message, "فهرست مرسوله‌ها دریافت نشد؛ دوباره تلاش کنید.")));
  });
  // A previous page's PDF job must not create an unsolicited banner or open
  // an unrelated parcel's label. New explicit print actions own their exact job.
  try { window.sessionStorage.removeItem(jobStorageKey); } catch (error) { /* Private mode. */ }
  updateLabelActions();
  // فقط وقتی هیچ خروجی/خطای سروری نداریم، لیست اولیه را دریافت کن.
  if (config.deferredList === true || (!$("#sgp-body tr[data-parcel-id]").length && !$("#sgp-body .sgp-empty").length && !$page.find(".sgp-state--error").length)) {
    loadPage(currentCursor, "replace");
  }
});
