/* Temporary input plus a separate durable submission identity. No auth, quotes or customer data in that identity. */
(function (window) {
  "use strict";

  var PREFIX = "woosalam:send-draft:v1:";
  var EPOCH_KEY = PREFIX + "epoch";
  var SCOPE_KEY = PREFIX + "scope";
  var CREATION_PREFIX = "woosalam:send-create:v1:";
  var MAX_BYTES = 256 * 1024;
  var MAX_ITEMS = 100;
  var scope = "";
  var epoch = "";
  var blocked = false;

  function storage() {
    try { return window.sessionStorage || null; } catch (error) { return null; }
  }

  function navigationType() {
    try {
      var entries = window.performance && window.performance.getEntriesByType
        ? window.performance.getEntriesByType("navigation") : [];
      if (entries.length && entries[0].type) return entries[0].type;
    } catch (error) { /* Older browsers can still expose the legacy API. */ }
    try {
      var legacy = window.performance && window.performance.navigation;
      if (legacy) return legacy.type === 1 ? "reload" : (legacy.type === 2 ? "back_forward" : "navigate");
    } catch (error) { /* No reliable navigation signal; do not guess a reload. */ }
    return "navigate";
  }

  function newEpoch() {
    return String(Date.now()) + "-" + String(Math.random()).slice(2);
  }

  function clearNamespace(store, except) {
    var keys = [];
    for (var index = 0; index < store.length; index++) {
      var key = store.key(index);
      if (typeof key === "string" && key.indexOf(PREFIX) === 0 && key !== except) keys.push(key);
    }
    keys.forEach(function (key) { store.removeItem(key); });
  }

  function clearAll() {
    var store = storage();
    if (!store) { blocked = true; return false; }
    try {
      clearNamespace(store);
      epoch = newEpoch();
      store.setItem(EPOCH_KEY, epoch);
      if (scope) store.setItem(SCOPE_KEY, scope);
      blocked = false;
      return true;
    } catch (error) { blocked = true; return false; }
  }

  function isCurrent() {
    if (blocked || !scope || !epoch) return false;
    var store = storage();
    if (!store) return false;
    try { return store.getItem(EPOCH_KEY) === epoch && store.getItem(SCOPE_KEY) === scope; } catch (error) { return false; }
  }

  function draftKey() { return PREFIX + "draft:" + scope; }

  function creationKey() { return CREATION_PREFIX + scope; }

  function loadCreation() {
    if (!isCurrent()) return null;
    try {
      var raw = storage().getItem(creationKey());
      if (!raw) return null;
      if (raw.length > 1024) return { invalid: true };
      var value = JSON.parse(raw);
      var markets = ["website", "instagram", "eitaa", "bale", "telegram", "digikala", "mixin", "other"];
      if (!object(value) || typeof value.id !== "string" || !/^[a-f0-9]{32}$/.test(value.id)
        || value.scope !== scope || markets.indexOf(value.market) === -1
        || ["pending", "unknown", "created"].indexOf(value.state) === -1
        || (value.parcel_id !== null && (typeof value.parcel_id !== "number" || !isFinite(value.parcel_id)
          || Math.floor(value.parcel_id) !== value.parcel_id || value.parcel_id <= 0 || value.parcel_id > 9007199254740991))) return { invalid: true };
      return { id: value.id, market: value.market, state: value.state, parcel_id: value.parcel_id };
    } catch (error) { return { invalid: true }; }
  }

  function beginCreation(market) {
    if (!isCurrent()) return null;
    var existing = loadCreation();
    if (existing) return existing.invalid || existing.market !== market ? null : existing;
    if (["website", "instagram", "eitaa", "bale", "telegram", "digikala", "mixin", "other"].indexOf(market) === -1) return null;
    try {
      if (!window.crypto || typeof window.crypto.getRandomValues !== "function") return null;
      var bytes = new Uint8Array(16);
      window.crypto.getRandomValues(bytes);
      var id = Array.prototype.map.call(bytes, function (byte) { return (byte < 16 ? "0" : "") + byte.toString(16); }).join("");
      var attempt = { id: id, scope: scope, market: market, state: "pending", parcel_id: null };
      storage().setItem(creationKey(), JSON.stringify(attempt));
      return loadCreation();
    } catch (error) { return null; }
  }

  function markCreation(id, state, parcelId) {
    if (!isCurrent() || ["pending", "unknown", "created"].indexOf(state) === -1) return false;
    var current = loadCreation();
    if (!current || current.invalid || current.id !== id) return false;
    if (state === "created" && (typeof parcelId !== "number" || !isFinite(parcelId)
      || Math.floor(parcelId) !== parcelId || parcelId <= 0 || parcelId > 9007199254740991)) return false;
    // A late timeout from an older document cannot replace a confirmed identity.
    if (current.state === "created" && state !== "created") return false;
    try {
      storage().setItem(creationKey(), JSON.stringify({ id: id, scope: scope, market: current.market,
        state: state, parcel_id: state === "created" ? parcelId : null }));
      return true;
    } catch (error) { return false; }
  }

  function clearCreation(id) {
    if (!isCurrent()) return false;
    var current = loadCreation();
    if (!current || current.invalid || current.id !== id) return false;
    try { storage().removeItem(creationKey()); return true; } catch (error) { return false; }
  }

  function init(connectionScope) {
    // An opaque server-generated fingerprint, never an access/refresh token.
    if (typeof connectionScope !== "string" || !/^[a-zA-Z0-9_-]{8,128}$/.test(connectionScope)) {
      scope = "";
      clearAll();
      return false;
    }
    var store = storage();
    if (!store || blocked) return false;
    try {
      var storedEpoch = store.getItem(EPOCH_KEY);
      if (!epoch) {
        epoch = typeof storedEpoch === "string" && storedEpoch ? storedEpoch : newEpoch();
        if (!storedEpoch) store.setItem(EPOCH_KEY, epoch);
      }
      // An old BFCache document must not revive input cleared by a later reload.
      if (store.getItem(EPOCH_KEY) !== epoch) return false;
      var activeScope = store.getItem(SCOPE_KEY);
      if (activeScope && activeScope !== connectionScope) epoch = newEpoch();
      scope = connectionScope;
      clearNamespace(store, draftKey());
      store.setItem(EPOCH_KEY, epoch);
      store.setItem(SCOPE_KEY, scope);
      return true;
    } catch (error) { scope = ""; blocked = true; return false; }
  }

  function object(value) { return value !== null && typeof value === "object" && !Array.isArray(value); }

  function text(value, limit) {
    if (value === undefined || value === null) return "";
    if (typeof value !== "string" && !(typeof value === "number" && isFinite(value))) throw new Error("Invalid input type");
    value = String(value);
    if (value.length > limit) throw new Error("Oversized input");
    return value;
  }

  function fields(source, limits) {
    if (!object(source)) throw new Error("Invalid input object");
    var result = {};
    Object.keys(limits).forEach(function (name) { result[name] = text(source[name], limits[name]); });
    return result;
  }

  function receiver(source, titles) {
    if (source === undefined || source === null) return null;
    var limits = { name: 256, mobile: 64, address: 512, postal_code: 32, city_id: 32, province_id: 32 };
    if (titles) { limits.province_title = 128; limits.city_title = 128; }
    return fields(source, limits);
  }

  function sanitize(snapshot) {
    if (!object(snapshot)) throw new Error("Invalid draft");
    var form = fields(snapshot.form || {}, {
      provider_shop_id: 32, weight: 32, value_toman: 32, box_type_id: 32, category_id: 32,
      dimension_length: 32, dimension_width: 32, dimension_height: 32
    });
    form.is_liquid = snapshot.form ? snapshot.form.is_liquid === true : false;
    form.is_fragile = snapshot.form ? snapshot.form.is_fragile === true : false;
    var items = snapshot.items === undefined ? [] : snapshot.items;
    if (!Array.isArray(items) || items.length > MAX_ITEMS) throw new Error("Invalid draft items");
    var clean = {
      market: text(snapshot.market, 64),
      form: form,
      receiver: receiver(snapshot.receiver, true),
      receiverModal: receiver(snapshot.receiverModal, false),
      items: items.map(function (item) { return fields(item, { title: 256, quantity: 32, net_weight: 32, price_toman: 32 }); })
    };
    if (snapshot.website_entry_mode !== undefined) {
      if (["orders", "manual"].indexOf(snapshot.website_entry_mode) === -1) throw new Error("Invalid website entry mode");
      clean.website_entry_mode = snapshot.website_entry_mode;
    }
    // Navigation resume may retain a known identity solely for a fresh GET of
    // existing rates/status. Never retain quote hashes, payment state or replay
    // a create/pay action. Reload removes this identity with the form draft.
    if (snapshot.parcel !== undefined && snapshot.parcel !== null) {
      var parcel = snapshot.parcel;
      var markets = ["instagram", "eitaa", "bale", "telegram", "website", "digikala", "mixin", "other"];
      if (!object(parcel) || typeof parcel.id !== "number" || !isFinite(parcel.id)
        || Math.floor(parcel.id) !== parcel.id || parcel.id <= 0 || parcel.id > 9007199254740991
        || typeof parcel.market !== "string" || markets.indexOf(parcel.market) === -1
        || (clean.market && clean.market !== parcel.market && snapshot.information_dirty !== true)) throw new Error("Invalid parcel identity");
      clean.parcel = { id: parcel.id, market: parcel.market };
    }
    if (snapshot.information_dirty !== undefined) {
      if (typeof snapshot.information_dirty !== "boolean") throw new Error("Invalid edit marker");
      clean.information_dirty = snapshot.information_dirty;
    }
    if (snapshot.creation_unresolved !== undefined) {
      if (typeof snapshot.creation_unresolved !== "boolean") throw new Error("Invalid creation marker");
      clean.creation_unresolved = snapshot.creation_unresolved;
    }
    return clean;
  }

  function clear() {
    if (!isCurrent()) return false;
    try { storage().removeItem(draftKey()); return true; } catch (error) { return false; }
  }

  function load() {
    if (!isCurrent()) return null;
    try {
      var raw = storage().getItem(draftKey());
      if (!raw) return null;
      if (raw.length * 2 > MAX_BYTES) throw new Error("Oversized draft");
      var envelope = JSON.parse(raw);
      var now = Date.now();
      if (!object(envelope) || envelope.version !== 1 || envelope.scope !== scope
        || typeof envelope.updatedAt !== "number" || !isFinite(envelope.updatedAt)
        || envelope.updatedAt < 0 || envelope.updatedAt > now + 60000) throw new Error("Invalid draft");
      return sanitize(envelope.data);
    } catch (error) { clear(); return null; }
  }

  function save(snapshot) {
    if (!isCurrent()) return false;
    try {
      var envelope = JSON.stringify({ version: 1, scope: scope, updatedAt: Date.now(), data: sanitize(snapshot) });
      if (envelope.length * 2 > MAX_BYTES) return false;
      storage().setItem(draftKey(), envelope);
      return true;
    } catch (error) { return false; }
  }

  window.WoosalamSendDraft = {
    init: init, load: load, save: save, clear: clear, clearAll: clearAll, isCurrent: isCurrent,
    loadCreation: loadCreation, beginCreation: beginCreation, markCreation: markCreation, clearCreation: clearCreation,
    navigationType: navigationType
  };

  // This runs on every wp-admin page, not only the send form. The new epoch also
  // prevents pagehide in an older BFCache document from resurrecting the draft.
  if (navigationType() === "reload") clearAll();
  var config = window.woosalamSendDraftConfig;
  if (config && Object.prototype.hasOwnProperty.call(config, "scope")) init(config.scope);
})(window);
