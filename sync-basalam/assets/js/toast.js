(function (window, document) {
  "use strict";

  if (window.BasalamToast && window.BasalamToast.__initialized) {
    return;
  }

  var CONTAINER_ID = "basalam-toast-container";
  var DEFAULT_DURATION = 4500;

  var ICONS = {
    success:
      '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">' +
      '<circle cx="12" cy="12" r="10" fill="currentColor" opacity="0.15"/>' +
      '<path d="M7.5 12.5l3 3 6-6.5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>' +
      "</svg>",
    error:
      '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">' +
      '<circle cx="12" cy="12" r="10" fill="currentColor" opacity="0.15"/>' +
      '<path d="M9 9l6 6M15 9l-6 6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>' +
      "</svg>",
    warning:
      '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">' +
      '<path d="M12 3l10 18H2L12 3z" fill="currentColor" opacity="0.15"/>' +
      '<path d="M12 3l10 18H2L12 3z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>' +
      '<path d="M12 10v4.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>' +
      '<circle cx="12" cy="17.5" r="1.1" fill="currentColor"/>' +
      "</svg>",
    info:
      '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">' +
      '<circle cx="12" cy="12" r="10" fill="currentColor" opacity="0.15"/>' +
      '<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="1.8"/>' +
      '<path d="M12 11v5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>' +
      '<circle cx="12" cy="8" r="1.1" fill="currentColor"/>' +
      "</svg>",
  };

  var DEFAULT_TITLES = {
    success: "موفق",
    error: "خطا",
    warning: "هشدار",
    info: "اطلاعات",
  };

  function ensureContainer() {
    var container = document.getElementById(CONTAINER_ID);
    if (container) {
      return container;
    }

    container = document.createElement("div");
    container.id = CONTAINER_ID;
    container.className = "basalam-toast-container";
    container.setAttribute("role", "region");
    container.setAttribute("aria-live", "polite");
    container.setAttribute("aria-label", "اعلان‌ها");
    document.body.appendChild(container);
    return container;
  }

  function dismiss(toast) {
    if (!toast || toast.dataset.leaving === "1") {
      return;
    }
    toast.dataset.leaving = "1";
    toast.classList.add("is-leaving");
    toast.classList.remove("is-visible");

    var remove = function () {
      if (toast.parentNode) {
        toast.parentNode.removeChild(toast);
      }
    };

    var fallback = window.setTimeout(remove, 400);
    toast.addEventListener(
      "transitionend",
      function handler() {
        window.clearTimeout(fallback);
        toast.removeEventListener("transitionend", handler);
        remove();
      },
      { once: true }
    );
  }

  function createToast(message, type, options) {
    var opts = options || {};
    var resolvedType = ICONS[type] ? type : "info";
    var duration =
      typeof opts.duration === "number" ? opts.duration : DEFAULT_DURATION;
    var hasTitle = Object.prototype.hasOwnProperty.call(opts, "title");
    var title = hasTitle ? opts.title : DEFAULT_TITLES[resolvedType];

    var container = ensureContainer();

    var toast = document.createElement("div");
    toast.className =
      "basalam-toast basalam-toast--" + resolvedType;
    toast.setAttribute("role", resolvedType === "error" ? "alert" : "status");

    var iconWrap = document.createElement("span");
    iconWrap.className = "basalam-toast__icon";
    iconWrap.innerHTML = ICONS[resolvedType];
    toast.appendChild(iconWrap);

    var body = document.createElement("div");
    body.className = "basalam-toast__body";

    if (title) {
      var titleEl = document.createElement("p");
      titleEl.className = "basalam-toast__title";
      titleEl.textContent = String(title);
      body.appendChild(titleEl);
    }

    var messageEl = document.createElement("p");
    messageEl.className = "basalam-toast__message";
    messageEl.textContent = message == null ? "" : String(message);
    body.appendChild(messageEl);

    toast.appendChild(body);

    var closeBtn = document.createElement("button");
    closeBtn.type = "button";
    closeBtn.className = "basalam-toast__close";
    closeBtn.setAttribute("aria-label", "بستن");
    closeBtn.innerHTML = "&times;";
    closeBtn.addEventListener("click", function () {
      dismiss(toast);
    });
    toast.appendChild(closeBtn);

    var timer = null;
    if (duration > 0) {
      var progress = document.createElement("div");
      progress.className = "basalam-toast__progress";
      var progressBar = document.createElement("div");
      progressBar.className = "basalam-toast__progress-bar";
      progressBar.style.animationDuration = duration + "ms";
      progress.appendChild(progressBar);
      toast.appendChild(progress);

      timer = window.setTimeout(function () {
        dismiss(toast);
      }, duration);

      toast.addEventListener("mouseenter", function () {
        if (timer) {
          window.clearTimeout(timer);
          timer = null;
        }
        progressBar.style.animationPlayState = "paused";
      });
      toast.addEventListener("mouseleave", function () {
        progressBar.style.animationPlayState = "running";
        if (!timer) {
          timer = window.setTimeout(function () {
            dismiss(toast);
          }, duration / 2);
        }
      });
    }

    container.appendChild(toast);

    window.requestAnimationFrame(function () {
      window.requestAnimationFrame(function () {
        toast.classList.add("is-visible");
      });
    });

    return {
      element: toast,
      dismiss: function () {
        if (timer) {
          window.clearTimeout(timer);
          timer = null;
        }
        dismiss(toast);
      },
    };
  }

  var BasalamToast = {
    __initialized: true,
    show: function (message, type, options) {
      return createToast(message, type, options);
    },
    success: function (message, options) {
      return createToast(message, "success", options);
    },
    error: function (message, options) {
      return createToast(message, "error", options);
    },
    warning: function (message, options) {
      return createToast(message, "warning", options);
    },
    info: function (message, options) {
      return createToast(message, "info", options);
    },
    dismissAll: function () {
      var container = document.getElementById(CONTAINER_ID);
      if (!container) {
        return;
      }
      var toasts = container.querySelectorAll(".basalam-toast");
      for (var i = 0; i < toasts.length; i++) {
        dismiss(toasts[i]);
      }
    },
  };

  window.BasalamToast = BasalamToast;
})(window, document);
