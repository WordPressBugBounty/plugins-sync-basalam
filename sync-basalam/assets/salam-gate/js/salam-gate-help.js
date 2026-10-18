/** Help-page enhancement only; no network, account lookup or shipping action. */
(function (window, document, $) {
  "use strict";
  if (!$) return;
  function mountCompatibilityFragments() {
    var staging = document.querySelector('[data-sg-help-compatibility]');
    var root = document.querySelector('.basalam-help-content');
    if (!staging || !root) return;
    var destinations = [
      ['.sg-help-tab', '.basalam-help-categories'],
      [".sg-help[data-category='سلام‌گیت']", '.basalam-faq-sections']
    ];
    destinations.forEach(function (pair) {
      var fragment = staging.querySelector(pair[0]);
      var destination = root.querySelector(pair[1]);
      if (fragment && destination && !root.querySelector(pair[0])) destination.appendChild(fragment);
    });
    if (root.querySelector('.sg-help-tab') && root.querySelector(".sg-help[data-category='سلام‌گیت']")) staging.remove();
  }
  // Footer scripts run before DOM ready: legacy base handlers must see the
  // inserted category when they initialize. No copied FAQs, requests or HTML
  // strings are required, and modern hook-rendered fragments stay untouched.
  mountCompatibilityFragments();
  $(function () {
    mountCompatibilityFragments();
    var panel = document.querySelector(".sg-help[data-category='سلام‌گیت']");
    var root = panel && panel.closest(".basalam-help-content");
    if (!root || root.getAttribute("data-sg-help-ready") === "true") return;
    root.setAttribute("data-sg-help-ready", "true");
    root.classList.add("sg-help-host");
    var tablist = root.querySelector(".basalam-help-categories");
    // Base renderer calls panes 'category', while its help controller expects
    // 'section'. Add the expected class only here; leave other pages untouched.
    root.querySelectorAll(".basalam-faq-category").forEach(function (element) {
      element.classList.add("basalam-faq-section");
    });
    var panes = Array.prototype.slice.call(root.querySelectorAll(".basalam-faq-section"));
    // Keep every existing FAQ category reachable. The base template provides
    // five buttons but its renderer includes more categories (support, common
    // problems, etc.). Hiding non-selected panes must not orphan that content.
    if (tablist) panes.forEach(function (pane) {
      var category = pane.getAttribute("data-category");
      var represented = Array.prototype.some.call(tablist.querySelectorAll(".basalam-category-tab"), function (tab) {
        return tab.getAttribute("data-category") === category;
      });
      if (!category || represented) return;
      var tab = document.createElement("button");
      tab.type = "button"; tab.className = "basalam-category-tab";
      tab.setAttribute("data-category", category); tab.textContent = category;
      tablist.insertBefore(tab, tablist.querySelector(".sg-help-tab"));
    });
    var tabs = Array.prototype.slice.call(root.querySelectorAll(".basalam-category-tab"));
    if (tablist) { tablist.setAttribute("role", "tablist"); tablist.setAttribute("aria-label", "موضوع‌های راهنما"); }
    tabs.forEach(function (tab, index) {
      tab.type = "button";
      // The base labels its settings button 'installation', but also has a
      // separate installation category. Give these two topics distinct names.
      if (tab.getAttribute("data-category") === "تنظیمات") tab.textContent = "تنظیمات افزونه";
      tab.id = "woosalam-help-tab-" + index;
      tab.setAttribute("role", "tab");
      var matching = panes.filter(function (pane) { return pane.getAttribute("data-category") === tab.getAttribute("data-category"); })[0];
      if (matching) {
        matching.id = "woosalam-help-panel-" + index;
        matching.setAttribute("role", "tabpanel");
        matching.setAttribute("aria-labelledby", tab.id);
        tab.setAttribute("aria-controls", matching.id);
      }
    });
    function activate(tab, persist) {
      if (!tab) return;
      var category = tab.getAttribute("data-category");
      tabs.forEach(function (item) {
        var selected = item === tab;
        item.classList.toggle("active", selected);
        item.setAttribute("aria-selected", String(selected));
        item.tabIndex = selected ? 0 : -1;
      });
      panes.forEach(function (pane) {
        var selected = pane.getAttribute("data-category") === category;
        pane.hidden = !selected;
        pane.style.display = selected ? "block" : "none";
      });
      if (persist && window.history && window.history.replaceState) {
        var url = new window.URL(window.location.href);
        if (category === "سلام‌گیت") url.searchParams.set("category", "salam-gate");
        else url.searchParams.delete("category");
        try { window.history.replaceState(null, "", url.toString()); } catch (ignored) {}
      }
    }
    $(root).on("click.sgHelp", ".basalam-category-tab", function () { activate(this, true); });
    if (tablist) tablist.addEventListener("keydown", function (event) {
      var current = tabs.indexOf(event.target);
      if (current < 0) return;
      var next = current;
      if (event.key === "Home") next = 0;
      else if (event.key === "End") next = tabs.length - 1;
      else if (event.key === "ArrowLeft") next = (current + 1) % tabs.length; // RTL order
      else if (event.key === "ArrowRight") next = (current + tabs.length - 1) % tabs.length;
      else return;
      event.preventDefault();
      // Use existing category click behavior too (clear search, etc.).
      tabs[next].click(); tabs[next].focus();
    });
    var selectedCategory = new window.URL(window.location.href).searchParams.get("category");
    var initial = selectedCategory === "salam-gate" ? tabs.filter(function (tab) {
      return tab.getAttribute("data-category") === "سلام‌گیت";
    })[0] : tabs.filter(function (tab) { return tab.classList.contains("active"); })[0];
    activate(initial || tabs[0], false);
    window.addEventListener("popstate", function () {
      var category = new window.URL(window.location.href).searchParams.get("category") === "salam-gate" ? "سلام‌گیت" : "عمومی";
      activate(tabs.filter(function (tab) { return tab.getAttribute("data-category") === category; })[0] || tabs[0], false);
    });
  });
}(window, document, window.jQuery));
