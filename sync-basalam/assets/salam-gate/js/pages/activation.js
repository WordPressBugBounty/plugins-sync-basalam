// =====================================================================
// Salam Gate — onboarding wizard (part 4)
// =====================================================================
jQuery(function ($) {
  if (window.WoosalamGateBoot && !window.WoosalamGateBoot.claim("activation")) return;
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
    return window.SalamGateAmount.fromRial(Number(n), false);
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

  function blockRegistration(message, unknown) {
    registrationBlocked = true;
    $checkStatus.prop("hidden", false).attr("href", statusUrl);
    $btnSubmit.text(unknown !== false ? "در حال بررسی" : "ابتدا وضعیت فعال‌سازی را بررسی کنید");
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
        ? "موجودی: " + faThousands(Math.max(0, record.rial)) + " تومان"
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
    $wizard.find("[data-cost-display]").text(costRial != null ? faThousands(costRial) : "—");
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
          : "درخواست فعال‌سازی این حساب قبلاً ثبت شده است؛ وضعیت آن را بررسی کنید.", data.registration_state === "unknown");
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
          blockRegistration(data.message || "درخواست فعال‌سازی قبلاً ثبت شده است؛ وضعیت آن را بررسی کنید.", false);
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
            notice("هزینه فعال‌سازی تغییر کرده است. مبلغ جدید " + faThousands(data.activation_cost_rial) + " تومان است؛ قبل از پرداخت دوباره آن را تأیید کنید. هنوز پرداختی انجام نشده است.", false);
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
