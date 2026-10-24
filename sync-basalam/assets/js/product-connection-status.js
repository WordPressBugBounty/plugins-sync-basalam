(function () {
  "use strict";

  function start() {
    var config = window.basalamProductConnectionStatus;
    var list = document.getElementById("the-list");
    if (!config || !list) return;

    var seen = new WeakSet();
    var queue = [];
    var running = false;

    function failed(element) {
      element.textContent = "بررسی اتصال انجام نشد";
      element.title = "برای تلاش مجدد، صفحه را دوباره بارگذاری کنید.";
      element.removeAttribute("aria-busy");
    }

    async function drain() {
      if (running) return;
      running = true;
      while (queue.length) {
        var element = queue.shift();
        if (!element.isConnected) continue;
        var productId = element.getAttribute("data-basalam-check-product");
        element.textContent = "در حال بررسی اتصال…";
        element.setAttribute("aria-busy", "true");
        var controller = new AbortController();
        var timeout = setTimeout(function () { controller.abort(); }, 30000);
        try {
          var body = new URLSearchParams({
            action: "get_product_connection_status",
            _wpnonce: config.nonce,
            product_id: productId,
            vendor_id: config.vendorId,
          });
          var response = await fetch(config.url, {
            method: "POST",
            credentials: "same-origin",
            body: body,
            signal: controller.signal,
          });
          var result = await response.json();
          if (!response.ok || !result.success || !result.data
            || String(result.data.product_id) !== productId
            || typeof result.data.html !== "string"
            || result.data.html.indexOf("data-basalam-check-product") !== -1) {
            throw new Error("Connection check unavailable");
          }
          if (element.isConnected) element.outerHTML = result.data.html;
        } catch (error) {
          if (element.isConnected) failed(element);
        } finally {
          clearTimeout(timeout);
        }
      }
      running = false;
    }

    function scan() {
      list.querySelectorAll("[data-basalam-check-product]").forEach(function (element) {
        if (seen.has(element)) return;
        seen.add(element);
        queue.push(element);
      });
      drain();
    }

    // Quick edit replaces table rows after the initial page load.
    new MutationObserver(scan).observe(list, { childList: true, subtree: true });
    scan();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start);
  } else {
    start();
  }
})();
