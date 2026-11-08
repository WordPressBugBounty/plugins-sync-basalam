// =====================================================================
// Salam Gate — settings page (part 5)
// =====================================================================
jQuery(function ($) {
  if (window.WoosalamGateBoot && !window.WoosalamGateBoot.claim("settings")) return;
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
