"use strict";

// Feed the real PHP-rendered page on stdin. Requires jsdom and jquery in NODE_PATH.
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const { JSDOM } = require("jsdom");
const jquery = require("jquery");
const root = path.resolve(__dirname, "../..");
const source = fs.readFileSync(path.join(root, "assets/salam-gate/js/index.js"), "utf8");
const start = source.indexOf('jQuery(function ($) {\n  var $page = $(".salam-gate-parcels-page");');
assert.ok(start >= 0, "Find the real parcels module");
const html = fs.readFileSync(0, "utf8");
assert.ok(html.includes('id="sgp-config"'), "Provide PHP-rendered HTML on stdin");

(async function () {
  const dom = new JSDOM(html, { url: "http://example.test/wp-admin/admin.php?page=salam_gate_parcels", runScripts: "outside-only" });
  const { window } = dom, $ = jquery(window), requests = [], timers = [], popups = [], notices = [];
  window.jQuery = window.$ = $;
  window.basalam_ajax = { ajaxurl: "/wp-admin/admin-ajax.php", nonce: "synthetic" };
  window.woosalamNotify = (text, kind) => notices.push({ text, kind });
  window.WoosalamGateRequests = { post(url, data) {
    const deferred = $.Deferred();
    requests.push({ data, deferred });
    return deferred.promise({ abort() { deferred.reject({}, "abort"); } });
  } };
  window.open = () => {
    const popup = { document: {}, closed: false, close() { this.closed = true; }, location: { replace(url) { popup.url = url; } } };
    popups.push(popup); return popup;
  };
  const originalTimeout = window.setTimeout.bind(window);
  window.setTimeout = (callback, delay) => {
    if (delay >= 2000) { timers.push(callback); return timers.length; }
    return originalTimeout(callback, delay);
  };
  window.clearTimeout = () => {};
  window.eval(source.slice(start));
  await new Promise(resolve => $(resolve));
  let checks = 0;
  const check = (ok, message) => { assert.ok(ok, message); ++checks; };
  const last = () => requests[requests.length - 1];
  const paid = { id: 101, receiver_name: "گیرنده آزمایشی", contents: "محصول تستی", market: "website", market_label: "ووکامرس", status_id: 8, status_title: "بارکد صادر شد", invoice_status: 2, tracking_code: "test-tracking", can_print_label: true };
  check(requests.length === 1 && last().data.action === "salam_gate_parcels_list", "Page load only reads parcels");
  last().deferred.resolve({ success: true, data: { items: [paid], connection_id: "synthetic", next_cursor: null } });
  const $toggle = $("[data-sgp-print-toggle]"), $menu = $("[data-sgp-print-options]"), $modal = $("[data-sgp-print-modal-el]"), $submit = $("[data-sgp-print-submit]");
  function open(kind) { $toggle.trigger("click"); $(`[data-sgp-print-kind='${kind}']`).trigger("click"); }
  function close() { $modal.find("[data-close-modal]").first().trigger("click"); }
  $toggle.trigger("click");
  check($toggle.text().includes("چاپ گروهی") && $toggle.attr("aria-expanded") === "true" && !$menu.prop("hidden"), "Generic button opens two-choice disclosure");
  $("[data-sgp-print-kind='barcode']").trigger("click");
  check($menu.prop("hidden") && $toggle.attr("aria-expanded") === "false" && $modal.css("display") !== "none", "Choosing barcode closes menu and opens shared dialog");
  check($("#sgp-print-title").text() === "چاپ گروهی بارکدها" && $("[data-sgp-label-options]").prop("hidden") && $submit.text().includes("چاپ بارکدها"), "Barcode dialog hides irrelevant label sizes");
  check(requests.length === 1, "Opening disclosure and dialog never generates PDF or payment");
  $submit.trigger("click");
  check(requests.length === 1 && $("[data-sgp-print-notice]").text().includes("تاریخ شروع"), "Missing dates are explained before any write");
  $("[data-sgp-from]").val("1405/07/28"); $("[data-sgp-to]").val("1405/07/28");
  $submit.trigger("click");
  check(last().data.action === "salam_gate_bulk_label_by_date" && last().data.template === "barcode", "Date flow explicitly requests barcode template");
  check($toggle.prop("disabled") && $submit.prop("disabled") && $("[name='sgp-template']").prop("disabled"), "Pending generation locks print choices");
  const countBefore = requests.length;
  $submit.trigger("click");
  check(requests.length === countBefore, "Double click cannot create a second job");
  last().deferred.resolve({ success: true, data: { status: "requested", request_id: 91, retry_after: 2 } });
  check($modal.css("display") === "none" && !$toggle.prop("disabled") && $submit.find(".dashicons-printer").length === 1, "Queued barcode preserves print icon and restores controls");
  timers.pop()();
  check(last().data.action === "salam_gate_label_poll" && last().data.request_id === 91, "Poll uses exact barcode job ID");
  last().deferred.resolve({ success: true, data: { status: "completed", request_id: 91, url: "https://example.test/barcodes.pdf" } });
  check(popups[0].url.includes("action=salam_gate_label_preview") && popups[0].url.includes("request_id=91"), "Barcode PDF opens through authenticated native preview");
  check(notices.some(notice => notice.kind === "success" && notice.text === "فایل چاپ آماده است."), "Barcode success is not mislabelled as postal label");
  open("label");
  check(!$("[data-sgp-label-options]").prop("hidden") && $("#sgp-print-title").text().includes("برچسب"), "Label choice restores existing format options");
  $("[name='sgp-template'][value='summary']").prop("checked", true);
  $submit.trigger("click");
  check(last().data.action === "salam_gate_bulk_label_by_date" && last().data.template === "summary", "Postal-label date flow keeps selected format");
  last().deferred.resolve({ success: false, data: { message: "در این بازه مرسوله قابل چاپ نیست.", request_dispatched: false } });
  check($modal.css("display") !== "none" && !$submit.prop("disabled") && $submit.text().includes("برچسب") && $submit.find(".dashicons-printer").length === 1, "Rejected batch leaves editable dialog and icon intact");
  close(); open("barcode"); close();
  $(".sgp-row-label").trigger("click");
  check(last().data.action === "salam_gate_bulk_label" && last().data.template === "label_10x15", "Single-row print cannot inherit barcode or bulk-label selections");
  last().deferred.resolve({ success: false, data: { request_dispatched: false } });
  open("barcode");
  $("[name='sgp-print-mode'][value='selection']").prop("checked", true).trigger("change");
  check(last().data.action === "salam_gate_parcels_list" && last().data.label_selection === "1" && $submit.prop("disabled"), "Selection mode first reads eligible parcels");
  last().deferred.resolve({ success: true, data: { items: [paid, { ...paid, id: 102, can_print_label: false }], connection_id: "synthetic", next_cursor: null } });
  check($("[data-sgp-select]").length === 1 && $submit.prop("disabled"), "Unpaid/unprintable parcels cannot be selected");
  $("[data-sgp-select]").prop("checked", true).trigger("change");
  check(!$submit.prop("disabled"), "A selected paid parcel enables printing");
  $submit.trigger("click");
  check(last().data.action === "salam_gate_bulk_label" && last().data.template === "barcode" && Array.from(last().data.parcel_ids).join(",") === "101", "Selected batch uses exact IDs with barcode, not invoice");
  last().deferred.reject({}, "error");
  check(!$toggle.prop("disabled"), "Transport failure restores print actions");
  close();
  $toggle.trigger("click"); $menu.find("button").first().trigger("focus").trigger($.Event("keydown", { key: "Escape" }));
  check($menu.prop("hidden") && window.document.activeElement === $toggle[0], "Escape closes disclosure and restores focus");
  $toggle.trigger($.Event("keydown", { key: "ArrowDown" }));
  check(!$menu.prop("hidden") && window.document.activeElement === $menu.find("button")[0], "ArrowDown opens disclosure with keyboard focus");
  $(".sgp-title").trigger("click");
  check($menu.prop("hidden"), "Clicking outside closes disclosure");
  check(requests.every(request => ["salam_gate_parcels_list", "salam_gate_bulk_label", "salam_gate_bulk_label_by_date", "salam_gate_label_poll"].includes(request.data.action)), "No registration, payment or cancellation requests");
  dom.window.close();
  process.stdout.write(`SalamGate bulk-print UI: ${checks} checks passed\n`);
})().catch(error => { console.error(error); process.exitCode = 1; });
