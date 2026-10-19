"use strict";
// Offline browser-probe regression; no external packages, WordPress or network.
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");
const source = fs.readFileSync(path.join(__dirname, "../../assets/salam-gate/js/salam-gate-order-availability.js"), "utf8");
let checks = 0;
async function scenario({ available, interaction, ok = true, reject = false }) {
  const listeners = new Map();
  let reloads = 0, request, finish;
  const window = {
    woosalamOrderAvailability: { url: "/wp-admin/admin-ajax.php", nonce: "synthetic-nonce" },
    fetch: (url, args) => { request = { url, args }; return new Promise((resolve, rejectPromise) => { finish = () => reject ? rejectPromise(new Error("offline")) : resolve({ ok, json: async () => ({ success: true, data: { available } }) }); }); },
    AbortController,
    setTimeout: () => 1,
    clearTimeout: () => {},
    addEventListener: (event, callback) => listeners.set(event, callback),
    removeEventListener: (event) => listeners.delete(event),
    location: { reload: () => reloads++ },
  };
  vm.runInNewContext(source, { window, AbortController, URLSearchParams });
  assert.equal(request.args.body.get("action"), "salam_gate_order_availability");
  assert.equal(request.args.body.get("_wpnonce"), "synthetic-nonce");
  assert.equal(request.args.credentials, "same-origin");
  if (interaction) listeners.get(interaction)();
  finish();
  await new Promise(setImmediate);
  assert.equal(reloads, available === true && !interaction && ok && !reject ? 1 : 0);
  assert.equal(listeners.size, 0, "Remove probe listeners after completion");
  checks += 5;
}
(async () => {
  await scenario({ available: true });
  await scenario({ available: false });
  await scenario({ available: "true" });
  await scenario({ available: true, ok: false });
  await scenario({ available: true, reject: true });
  for (const interaction of ["input", "change", "submit", "click", "pagehide"]) await scenario({ available: true, interaction });
  process.stdout.write(`SalamGate order availability: ${checks} checks passed\n`);
})().catch((error) => { console.error(error); process.exitCode = 1; });
