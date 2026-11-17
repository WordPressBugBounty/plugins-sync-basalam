(function () {
  "use strict";

  function initialize() {
    document.querySelectorAll("[data-woosalam-promotions]").forEach(function (banner) {
      var slides = Array.from(banner.querySelectorAll("[data-promotion-slide]"));
      var controls = banner.querySelector("[data-promotion-controls]");
      if (slides.length < 2 || !controls) return;

      var dots = Array.from(controls.querySelectorAll("[data-promotion-dot]"));
      var track = banner.querySelector(".woosalam-promotion-banner__slides");
      var current = 0;
      var timer;

      function schedule() {
        window.clearTimeout(timer);
        if (document.hidden) return;
        timer = window.setTimeout(function () {
          show(current + 1);
        }, 7000);
      }

      function show(index) {
        var moveFocus = slides[current].contains(document.activeElement);
        current = (index + slides.length) % slides.length;
        slides.forEach(function (slide, position) {
          var active = position === current;
          slide.setAttribute("aria-hidden", String(!active));
          slide.inert = !active;
          slide.classList.toggle("is-current", active);
          dots[position].setAttribute("aria-pressed", String(active));
        });
        // Keep keyboard focus visible when its slide changes automatically.
        if (moveFocus) dots[current].focus();
        schedule();
      }

      slides.forEach(function (slide, index) {
        slide.setAttribute("role", "group");
        slide.setAttribute("aria-roledescription", "اسلاید");
        slide.setAttribute("aria-label", (index + 1) + " از " + slides.length + ": " + slide.dataset.promotionName);
      });
      banner.setAttribute("aria-roledescription", "اسلایدر");
      track.setAttribute("aria-live", "off");
      banner.classList.add("is-enhanced");
      controls.hidden = false;

      controls.querySelector("[data-promotion-previous]").addEventListener("click", function () {
        show(current - 1);
      });
      controls.querySelector("[data-promotion-next]").addEventListener("click", function () {
        show(current + 1);
      });
      dots.forEach(function (dot, index) {
        dot.addEventListener("click", function () { show(index); });
      });
      banner.addEventListener("keydown", function (event) {
        if (event.key !== "ArrowLeft" && event.key !== "ArrowRight") return;
        event.preventDefault();
        show(current + (event.key === "ArrowLeft" ? 1 : -1));
      });
      document.addEventListener("visibilitychange", schedule);
      show(0);
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initialize);
  } else {
    initialize();
  }
})();
