// =====================================================================
// Salam Gate — post offices view (part 5)
// =====================================================================
jQuery(function ($) {
  if (window.WoosalamGateBoot && !window.WoosalamGateBoot.claim("post-offices")) return;
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
