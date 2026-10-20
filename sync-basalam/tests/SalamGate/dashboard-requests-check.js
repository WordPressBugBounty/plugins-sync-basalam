"use strict";
// Offline browser-controller checks: no packages, browser sessions or live API calls.
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");
const navigation = fs.readFileSync(path.join(__dirname, "../../assets/salam-gate/js/salam-gate-navigation.js"), "utf8");
const index = fs.readFileSync(path.join(__dirname, "../../assets/salam-gate/js/index.js"), "utf8");
const statsModule = index.slice(index.indexOf("jQuery(function", index.indexOf("// Salam Gate — dashboard stats")), index.indexOf("// Salam Gate — settings page"));
let scenarios = 0;
function deferred() {
  return { done(fn) { this.resolve = fn; return this; }, fail(fn) { this.reject = fn; return this; }, always(fn) { this.finish = fn; return this; } };
}
function accountScenario({ response, status, httpStatus = 0, view = "" }) {
  const title = { textContent: "" }, hint = { hidden: true, textContent: "", classList: { toggle() {} } };
  const button = {}, spinner = {}, attributes = {}, request = deferred();
  let reloads = 0, content = "", options;
  const body = { setAttribute(key, value) { attributes[key] = value; },
    querySelector(selector) { return selector === ".sg-loading__text" ? title : hint; },
    querySelectorAll(selector) { return selector.includes("spinner") ? [spinner] : [button]; } };
  const root = { querySelector(selector) { return selector.includes("#sg-state-container") ? body : { getAttribute() { return view; } }; },
    setAttribute() {}, dispatchEvent() {}, addEventListener() {} };
  const $ = () => ({ html(value) { content = value; } });
  $.ajax = (value) => { options = value; return request; };
  const window = { basalam_ajax: { ajaxurl: "/wp-admin/admin-ajax.php", nonce: "synthetic-nonce" }, jQuery: $,
    location: { pathname: "/wp-admin/admin.php", search: "?page=salam_gate", reload() { reloads++; } },
    sessionStorage: { getItem() { return null; }, setItem() {} }, CustomEvent: class {}, addEventListener() {} };
  vm.runInNewContext(navigation, { window, document: { readyState: "complete", querySelector() { return root; } } });
  assert.equal(options.timeout, 10000, "Account checks must offer retry after 10 seconds without a response");
  assert.equal(options.data.action, "salam_gate_account_state", "Only the read-only account action may run");
  if (status) request.reject({ status: httpStatus }, status);
  else request.resolve(response);
  request.finish();
  scenarios++;
  return { hint, title, button, spinner, attributes, reloads, content };
}
let result = accountScenario({ response: { success: true, data: { state: "active", html: "<div>Active</div>" } } });
assert.equal(result.reloads, 1);
result = accountScenario({ response: { success: true, data: { state: "error_auth", html: "<div>Reconnect</div>" } } });
assert.equal(result.content, "<div>Reconnect</div>");
assert.equal(result.reloads, 0);
result = accountScenario({ response: { success: true, data: { state: "error_network", html: "<div>Error</div>", message: "Synthetic provider validation failure" } } });
assert.equal(result.hint.textContent, "Synthetic provider validation failure");
assert.equal(result.button.disabled, false);
assert.equal(result.attributes["aria-busy"], "false");
result = accountScenario({ response: { success: false, data: { message: "Synthetic account read failure" } } });
assert.equal(result.hint.textContent, "Synthetic account read failure");
for (const httpStatus of [401, 403]) {
  result = accountScenario({ status: "error", httpStatus });
  assert.match(result.hint.textContent, /اعتبار صفحه/);
}
result = accountScenario({ status: "timeout" });
assert.match(result.hint.textContent, /بیش از حد طول کشید/);
result = accountScenario({ status: "parsererror" });
assert.match(result.hint.textContent, /پاسخ سایت/);
result = accountScenario({ status: "error", httpStatus: 500 });
assert.match(result.hint.textContent, /پاسخ سرور/);
result = accountScenario({ status: "abort" });
assert.equal(result.hint.hidden, true, "Navigation abort is not an account error");
result = accountScenario({ view: "calculator", status: "timeout" });
assert.equal(result.content, "", "Account failures must not replace public tools");
assert.equal(result.hint.hidden, true);

function reportScenario({ response, status, httpStatus = 0 }) {
  const request = deferred(), metrics = new Map(), tickets = [];
  const region = { name: "report-only" };
  const element = { closest() { return region; } };
  const state = { value: "Previous failure", visible: true,
    text(value) { if (value === undefined) return this.value; this.value = value; return this; },
    show() { this.visible = true; return this; }, hide() { this.visible = false; return this; } };
  const block = { 0: element, siblings() { return state; }, find(selector) { return { text(value) { metrics.set(selector, value); } }; } };
  const blocks = { length: 1, each(callback) { callback.call(element); } };
  const $ = (value) => value === "[data-sg-stats]" ? blocks : block;
  const jQuery = (callback) => callback($);
  const window = { WoosalamGateLoading: { begin(target, key, retry) {
    assert.equal(target.closest("[data-sg-load]"), region);
    const ticket = { ready() { this.state = "ready"; }, fail(message) { this.state = "error"; this.message = message; } };
    tickets.push(ticket); return ticket;
  } }, WoosalamGateRequests: { post(url, data) {
    assert.equal(data.action, "salam_gate_stats"); return request;
  } } };
  vm.runInNewContext(statsModule, { window, jQuery, basalam_ajax: { ajaxurl: "/admin-ajax.php", nonce: "synthetic" } });
  assert.equal(state.value, "", "A retry clears its old report error");
  if (status) request.reject({ status: httpStatus }, status);
  else request.resolve(response);
  assert.equal(tickets.length, 1, "Only the report owns a loading ticket");
  scenarios++;
  return { state, metrics, ticket: tickets[0] };
}
result = reportScenario({ response: { success: true, data: { order_count: 0, avg_shipping_cost_toman: null, total_profit_toman: null } } });
assert.equal(result.ticket.state, "ready");
assert.equal(result.metrics.get("[data-stat='order_count']"), "0", "Real zero is displayed");
assert.equal(result.metrics.get("[data-stat='avg_shipping_cost_toman']"), "—", "Unavailable money is not fabricated as zero");
assert.equal(result.state.visible, false);
result = reportScenario({ response: { success: false, data: { message: "Synthetic upstream failure" } } });
assert.equal(result.ticket.state, "error");
assert.match(result.ticket.message, /Synthetic upstream failure/);
assert.equal(result.metrics.size, 0, "Failed reports must not publish fabricated statistics");
for (const [status, httpStatus, expected] of [["timeout", 0, /بیش از حد طول کشید/], ["error", 403, /اعتبار صفحه/], ["error", 500, /پاسخ سرور/]]) {
  result = reportScenario({ status, httpStatus });
  assert.equal(result.ticket.state, "error");
  assert.match(result.ticket.message, expected);
}
process.stdout.write(`SalamGate dashboard requests: ${scenarios} scenarios passed\n`);
