document.addEventListener("DOMContentLoaded", function () {
  const modalConfig = [
    {
      key: "add",
      modalId: "basalamAddProductsModal",
      openBtnId: "basalamOpenAddProductsModal",
      formId: "basalamAddProductsForm",
      action: "create_products_to_basalam",
    },
    {
      key: "update",
      modalId: "BasalamUpdateProductsModal",
      openBtnId: "BasalamOpenUpdateProductsModal",
      formId: "BasalamUpdateProductsForm",
      action: "update_products_in_basalam",
    },
    {
      key: "connect",
      modalId: "BasalamConnectProductsModal",
      openBtnId: "BasalamOpenConnectProductsModal",
      formId: "BasalamConnectProductsForm",
      action: "connect_products_with_basalam",
    },
  ];

  const closeModal = () => {
    document.querySelectorAll(".basalam-modal").forEach((modal) => {
      modal.style.display = "none";
    });
    document.body.style.overflow = "auto";
  };

  modalConfig.forEach(({ modalId, openBtnId, formId, action }) => {
    const modal = document.getElementById(modalId);
    const openBtn = document.getElementById(openBtnId);
    const form = document.getElementById(formId);

    if (openBtn && modal) {
      openBtn.addEventListener("click", () => {
        modal.style.display = "block";
        document.body.style.overflow = "hidden";

        if (modalId === "BasalamUpdateProductsModal") {
          const selectionDiv = document.getElementById("update-type-selection");
          const quickConfirm = document.getElementById("quick-update-confirm");
          const fullConfirm = document.getElementById("full-update-confirm");

          if (selectionDiv) selectionDiv.style.display = "block";
          if (quickConfirm) quickConfirm.style.display = "none";
          if (fullConfirm) fullConfirm.style.display = "none";
        }
      });
    }

    if (form) {
      form.addEventListener("submit", function (e) {
        e.preventDefault();

        if (action === "create_products_to_basalam") {
          const confirmModal = document.getElementById("BasalamConfirmModal");
          confirmModal.style.display = "block";
          document.body.style.overflow = "hidden";

          const confirmButton = document.getElementById("confirmSubmitBtn");

          const newConfirmBtn = confirmButton.cloneNode(true);
          confirmButton.parentNode.replaceChild(newConfirmBtn, confirmButton);

          newConfirmBtn.addEventListener("click", function () {
            confirmModal.style.display = "none";

            submitToServer(form, action);
          });
        } else {
          submitToServer(form, action);
        }
      });
    }
  });

  setTimeout(() => {
    const quickUpdateBtn = document.getElementById("quick-update-btn");
    const fullUpdateBtn = document.getElementById("full-update-btn");

    if (quickUpdateBtn) {
      quickUpdateBtn.addEventListener("click", (e) => {
        e.preventDefault();

        const formData = new FormData();
        formData.append("update_type", "quick");
        formData.append("action", "update_products_in_basalam");
        formData.append(
          "_wpnonce",
          document.querySelector(
            '#BasalamUpdateProductsModal input[name="_wpnonce"]'
          )?.value || ""
        );

        submitUpdateRequest(formData, "quick");
      });
    }

    if (fullUpdateBtn) {
      fullUpdateBtn.addEventListener("click", (e) => {
        e.preventDefault();

        const formData = new FormData();
        formData.append("update_type", "full");
        formData.append("action", "update_products_in_basalam");
        formData.append(
          "_wpnonce",
          document.querySelector(
            '#BasalamUpdateProductsModal input[name="_wpnonce"]'
          )?.value || ""
        );

        submitUpdateRequest(formData, "full");
      });
    }
  }, 100);

  function submitUpdateRequest(formData, updateType) {
    const quickBtn = document.getElementById("quick-update-btn");
    const fullBtn = document.getElementById("full-update-btn");

    if (quickBtn) quickBtn.disabled = true;
    if (fullBtn) fullBtn.disabled = true;

    const clickedBtn = updateType === "quick" ? quickBtn : fullBtn;
    if (clickedBtn) {
      const originalText = clickedBtn.innerHTML;
      clickedBtn.innerHTML =
        '<span class="basalam-loading-text">در حال ارسال...</span>';

      fetch(ajaxurl, {
        method: "POST",
        body: formData,
      })
        .then((response) => response.json())
        .then((data) => {
          closeModal();
          if (data.success) {
            alert(data.data?.message || "عملیات با موفقیت آغاز شد.");
          } else {
            alert(data.data?.message || "خطایی رخ داده است.");
          }
          location.reload();
        })
        .catch((error) => {
          console.error("Error:", error);
          alert("خطایی در ارسال رخ داده است.");

          if (quickBtn) quickBtn.disabled = false;
          if (fullBtn) fullBtn.disabled = false;
          if (clickedBtn) clickedBtn.innerHTML = originalText;
        });
    }
  }

  function submitToServer(form, action, updateType = null) {
    const formData = new FormData(form);
    formData.append("action", action);

    const submitButton = form.querySelector('button[type="submit"]');
    submitButton.disabled = true;
    submitButton.innerHTML =
      '<span class="dashicons dashicons-update"></span> در حال ارسال...';

    fetch(ajaxurl, {
      method: "POST",
      body: formData,
    })
      .then((response) => response.json())
      .then((data) => {
        closeModal();
        if (data.success) {
          alert(data.data?.message || "عملیات با موفقیت آغاز شد.");
        } else {
          alert(data.data?.message || "خطایی رخ داده است.");
        }
        location.reload();
      })
      .catch((error) => {
        console.error("Error:", error);
        alert("خطایی در ارسال رخ داده است.");
      })
      .finally(() => {
        submitButton.disabled = false;
        submitButton.innerHTML =
          '<span class="dashicons dashicons-plus-alt"></span> انجام شد';
      });
  }

  document.querySelectorAll(".basalam-modal-close").forEach((btn) => {
    btn.addEventListener("click", closeModal);
  });

  window.addEventListener("click", (event) => {
    if (event.target.classList.contains("basalam-modal")) {
      closeModal();
    }
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      closeModal();
    }
  });

  const select = document.getElementById("basalam-sync-type");
  if (select) {
    const toggleFields = (val) => {
      document.getElementById("Basalam-custom-fields").style.display =
        val === "custom" ? "block" : "none";
    };
    toggleFields(select.value);
    select.addEventListener("change", (e) => toggleFields(e.target.value));
  }

  // Tab functionality for settings modal
  const initTabs = () => {
    const tabBtns = document.querySelectorAll(".basalam-tab-btn");
    const tabContents = document.querySelectorAll(".basalam-tab-content");

    tabBtns.forEach((btn) => {
      btn.addEventListener("click", () => {
        const tabId = btn.getAttribute("data-tab");

        // Remove active class from all buttons and contents
        tabBtns.forEach((b) => b.classList.remove("active"));
        tabContents.forEach((c) => c.classList.remove("active"));

        // Add active class to clicked button and corresponding content
        btn.classList.add("active");
        const content = document.getElementById(tabId);
        if (content) {
          content.classList.add("active");
        }
      });
    });
  };

  initTabs();

  const initPointerOnboarding = () => {
    const pointerTour = window.basalamPointerTour;

    if (
      !pointerTour ||
      !Array.isArray(pointerTour.steps) ||
      pointerTour.steps.length === 0
    ) {
      return;
    }

    if (
      typeof window.jQuery === "undefined" ||
      typeof window.jQuery.fn.pointer !== "function"
    ) {
      return;
    }

    const steps = pointerTour.steps.filter(
      (step) =>
        step &&
        typeof step.selector === "string" &&
        document.querySelector(step.selector)
    );

    if (steps.length === 0) {
      return;
    }

    let completionRequested = false;

    const markTourCompleted = () => {
      if (completionRequested) {
        return;
      }

      completionRequested = true;

      if (!pointerTour.completeAction || !pointerTour.nonce) {
        return;
      }

      const formData = new FormData();
      formData.append("action", pointerTour.completeAction);
      formData.append("nonce", pointerTour.nonce);

      fetch(ajaxurl, {
        method: "POST",
        body: formData,
      }).catch(() => {});
    };

    const openStep = (index) => {
      if (index >= steps.length) {
        markTourCompleted();
        return;
      }

      const step = steps[index];
      const $target = window.jQuery(step.selector).first();

      if (!$target.length) {
        openStep(index + 1);
        return;
      }

      const targetElement = $target.get(0);
      if (targetElement && typeof targetElement.scrollIntoView === "function") {
        targetElement.scrollIntoView({ behavior: "smooth", block: "center" });
      }

      let shouldAdvance = false;
      let shouldStop = false;

      $target
        .pointer({
          pointerClass: "sync-basalam-pointer",
          content: step.content || "",
          position: step.position || { edge: "right", align: "middle" },
          buttons: function (event, t) {
            const isLastStep = index === steps.length - 1;
            const skipLabel = step.skipLabel || "بستن";
            const nextLabel = isLastStep
              ? step.doneLabel || "اتمام"
              : step.nextLabel || "بعدی";

            const $skipButton = window.jQuery(
              '<button type="button" class="button button-secondary"></button>'
            ).text(skipLabel);

            const $nextButton = window.jQuery(
              '<button type="button" class="button button-primary"></button>'
            ).text(nextLabel);

            $skipButton.on("click", function () {
              shouldStop = true;
              t.element.pointer("close");
            });

            $nextButton.on("click", function () {
              shouldAdvance = true;
              t.element.pointer("close");
            });

            return window.jQuery('<div class="wp-pointer-buttons" />')
              .append($skipButton)
              .append($nextButton);
          },
          close: function () {
            if (shouldStop) {
              markTourCompleted();
              return;
            }

            if (shouldAdvance) {
              openStep(index + 1);
              return;
            }

            markTourCompleted();
          },
        })
        .pointer("open");
    };

    openStep(0);
  };

  initPointerOnboarding();

  const initAnnouncementsPanel = () => {
    const announcementsConfig = window.basalamAnnouncements;

    if (
      !announcementsConfig ||
      !Array.isArray(announcementsConfig.items) ||
      announcementsConfig.items.length === 0
    ) {
      return;
    }

    const root = document.getElementById("sync-basalam-announcement-root");
    const trigger = document.getElementById("sync-basalam-announcement-trigger");
    const panel = document.getElementById("sync-basalam-announcement-panel");
    const overlay = document.getElementById("sync-basalam-announcement-overlay");
    const closeBtn = document.getElementById("sync-basalam-announcement-close");
    const counter = document.getElementById("sync-basalam-announcement-counter");
    const list = document.getElementById("sync-basalam-announcement-list");
    const pageIndicator = document.getElementById("sync-basalam-announcement-page");
    const prevBtn = document.getElementById("sync-basalam-announcement-prev");
    const nextBtn = document.getElementById("sync-basalam-announcement-next");

    if (
      !root ||
      !trigger ||
      !panel ||
      !overlay ||
      !closeBtn ||
      !counter ||
      !list ||
      !pageIndicator ||
      !prevBtn ||
      !nextBtn
    ) {
      return;
    }

    const normalizeItems = (rawItems) =>
      rawItems
        .map((item) => {
          const files = Array.isArray(item?.files) ? item.files : [];
          const imageFile = files.find((f) => f?.url && /\.(png|jpe?g|gif|webp|svg)/i.test(f.url));

          return {
            id: String(item?.id || ""),
            description: String(item?.description || ""),
            link: String(item?.link || "#"),
            linkText: String(item?.linkText || "ادامه"),
            image: imageFile ? String(imageFile.url) : (item?.image ? String(item.image) : ""),
          };
        })
        .filter((item) => item.id && item.description);

    let items = normalizeItems(announcementsConfig.items);

    if (items.length === 0) {
      root.remove();
      return;
    }

    let currentPage = 1;
    let totalPages = Math.max(parseInt(announcementsConfig.totalPage, 10) || 1, 1);
    let isFetching = false;

    let seenIds = new Set(
      Array.isArray(announcementsConfig.seenIds)
        ? announcementsConfig.seenIds.map((id) => String(id))
        : []
    );
    let seenRequested = false;

    const getUnreadCount = () =>
      items.reduce((total, item) => total + (seenIds.has(item.id) ? 0 : 1), 0);

    const updateCounter = () => {
      const unreadCount = getUnreadCount();
      counter.textContent = String(unreadCount);
      counter.classList.toggle(
        "sync-basalam-announcement-counter-hidden",
        unreadCount === 0
      );
    };

    const renderItems = (pageItems) => {
      list.innerHTML = "";

      pageItems.forEach((item) => {
        const card = document.createElement("article");
        card.className =
          "sync-basalam-announcement-card" +
          (item.image ? "" : " sync-basalam-announcement-card-no-image") +
          (seenIds.has(item.id) ? " is-seen" : " is-unread");

        if (item.image) {
          const imageWrapper = document.createElement("div");
          imageWrapper.className = "sync-basalam-announcement-image-wrap";

          const image = document.createElement("img");
          image.className = "sync-basalam-announcement-image";
          image.src = item.image;
          image.alt = "خبر ووسلام";
          image.loading = "lazy";

          imageWrapper.appendChild(image);
          card.appendChild(imageWrapper);
        }

        const content = document.createElement("div");
        content.className = "sync-basalam-announcement-content";

        const description = document.createElement("p");
        description.className = "sync-basalam-announcement-text";
        description.textContent = item.description;

        const link = document.createElement("a");
        link.className = "sync-basalam-announcement-link";
        link.href = item.link;
        link.textContent = item.linkText || "ادامه";
        link.target = "_blank";
        link.rel = "noopener noreferrer";

        content.appendChild(description);
        if (item.link && item.link !== "#") {
          content.appendChild(link);
        }
        card.appendChild(content);
        list.appendChild(card);
      });
    };

    const updatePagination = () => {
      pageIndicator.textContent = `${currentPage} / ${totalPages}`;
      prevBtn.disabled = currentPage <= 1 || isFetching;
      nextBtn.disabled = currentPage >= totalPages || isFetching;
    };

    const renderPage = () => {
      renderItems(items);
      updatePagination();
    };

    const fetchPage = (page) => {
      if (isFetching) {
        return;
      }

      isFetching = true;
      prevBtn.disabled = true;
      nextBtn.disabled = true;
      list.innerHTML = '<div class="sync-basalam-announcement-loading">در حال بارگذاری...</div>';

      const formData = new FormData();
      formData.append("action", announcementsConfig.fetchPageAction || "");
      formData.append("nonce", announcementsConfig.fetchPageNonce || "");
      formData.append("page", String(page));

      fetch(ajaxurl, {
        method: "POST",
        body: formData,
      })
        .then((response) => response.json())
        .then((data) => {
          if (!data?.success) {
            return;
          }

          const newItems = normalizeItems(data.data.items || []);
          items = newItems;
          currentPage = parseInt(data.data.page, 10) || page;
          totalPages = parseInt(data.data.totalPage, 10) || totalPages;

          renderPage();
        })
        .catch(() => {
          updatePagination();
        })
        .finally(() => {
          isFetching = false;
          updatePagination();
        });
    };

    const markAllSeen = () => {
      if (seenRequested || getUnreadCount() === 0) {
        return;
      }

      seenRequested = true;

      const formData = new FormData();
      formData.append("action", announcementsConfig.markSeenAction || "");
      formData.append("nonce", announcementsConfig.nonce || "");

      fetch(ajaxurl, {
        method: "POST",
        body: formData,
      })
        .then((response) => response.json())
        .then((data) => {
          if (!data?.success) {
            return;
          }

          seenIds = new Set(items.map((item) => item.id));
          updateCounter();
          renderPage();
        })
        .catch(() => {})
        .finally(() => {
          seenRequested = false;
        });
    };

    const openPanel = () => {
      root.classList.add("is-open");
      panel.setAttribute("aria-hidden", "false");
      document.body.classList.add("sync-basalam-announcement-open");
      markAllSeen();
    };

    const closePanel = () => {
      root.classList.remove("is-open");
      panel.setAttribute("aria-hidden", "true");
      document.body.classList.remove("sync-basalam-announcement-open");
    };

    trigger.addEventListener("click", openPanel);
    closeBtn.addEventListener("click", closePanel);
    overlay.addEventListener("click", closePanel);

    document.addEventListener("mousedown", (event) => {
      if (!root.classList.contains("is-open")) {
        return;
      }

      const target = event.target;
      if (!(target instanceof Node)) {
        return;
      }

      if (panel.contains(target) || trigger.contains(target)) {
        return;
      }

      closePanel();
    });

    prevBtn.addEventListener("click", () => {
      if (currentPage <= 1 || isFetching) {
        return;
      }
      fetchPage(currentPage - 1);
    });

    nextBtn.addEventListener("click", () => {
      if (currentPage >= totalPages || isFetching) {
        return;
      }
      fetchPage(currentPage + 1);
    });

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && root.classList.contains("is-open")) {
        closePanel();
      }
    });

    updateCounter();
    renderPage();
  };

  initAnnouncementsPanel();

  const advancedSettingsForm = document.getElementById(
    "basalam-advanced-settings-form"
  );
  const advancedSubmitSection = document.getElementById(
    "basalam-advanced-submit-section"
  );

  const isTrackedAdvancedSettingField = (fieldName) =>
    typeof fieldName === "string" &&
    fieldName.startsWith("sync_basalam_settings[");

  if (advancedSettingsForm && advancedSubmitSection) {
    const serializeAdvancedSettings = () => {
      const formData = new FormData(advancedSettingsForm);
      const entries = [];

      formData.forEach((value, key) => {
        if (isTrackedAdvancedSettingField(key)) {
          entries.push(`${key}=${String(value)}`);
        }
      });

      return entries.join("&");
    };

    let initialSettingsSnapshot = "";

    const toggleAdvancedSubmitVisibility = () => {
      const currentSnapshot = serializeAdvancedSettings();
      const hasChanges = currentSnapshot !== initialSettingsSnapshot;

      advancedSubmitSection.classList.toggle(
        "basalam-submit-section-hidden",
        !hasChanges
      );
    };

    const captureInitialSnapshot = () => {
      initialSettingsSnapshot = serializeAdvancedSettings();
      toggleAdvancedSubmitVisibility();
    };

    const handleSettingsFieldMutation = (event) => {
      const fieldName = event.target?.name || "";

      if (!isTrackedAdvancedSettingField(fieldName)) {
        return;
      }

      window.requestAnimationFrame(toggleAdvancedSubmitVisibility);
    };

    advancedSettingsForm.addEventListener("input", handleSettingsFieldMutation);
    advancedSettingsForm.addEventListener(
      "change",
      handleSettingsFieldMutation
    );

    captureInitialSnapshot();
    window.requestAnimationFrame(captureInitialSnapshot);
  }

  const infoTriggers = document.querySelectorAll(".basalam-info-trigger");

  const moveModalsToBody = () => {
    const allModals = document.querySelectorAll(".basalam-info-modal");
    allModals.forEach((modal) => {
      if (modal.parentNode !== document.body) {
        document.body.appendChild(modal);
      }
    });
  };

  moveModalsToBody();

  const closeAllModals = () => {
    const allModals = document.querySelectorAll(".basalam-info-modal");
    allModals.forEach((modal) => {
      modal.style.display = "none";
      modal.classList.remove("show");
    });
    document.body.style.overflow = "auto";
  };

  const openModal = (modalId) => {
    const modal = document.getElementById(modalId);
    if (modal) {
      if (modal.parentNode !== document.body) {
        document.body.appendChild(modal);
      }

      modal.style.display = "block";
      modal.classList.add("show");
      document.body.style.overflow = "hidden";

      modal.focus();
    }
  };

  infoTriggers.forEach((trigger) => {
    trigger.addEventListener("click", (e) => {
      e.preventDefault();
      e.stopPropagation();

      const modalId = trigger.getAttribute("data-modal-id");
      if (modalId) {
        openModal(modalId);
      }
    });
  });

  document.addEventListener("click", (e) => {
    if (e.target.classList.contains("basalam-info-modal-close")) {
      e.preventDefault();
      closeAllModals();
    }

    if (e.target.classList.contains("basalam-info-modal-overlay")) {
      closeAllModals();
    }

    if (e.target.classList.contains("basalam-info-modal")) {
      closeAllModals();
    }
  });

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") {
      closeAllModals();
    }
  });

  document.addEventListener("click", (e) => {
    if (e.target.closest(".basalam-info-modal-content")) {
      e.stopPropagation();
    }
  });

  const tasksAutoToggle = document.querySelector(".basalam-tasks-auto-toggle");
  const tasksManualContainer = document.querySelector(
    ".basalam-tasks-manual-container"
  );
  const tasksManualInput = document.querySelector(
    ".basalam-tasks-manual-input"
  );
  const tasksAutoHidden = document.querySelector(".basalam-tasks-auto-hidden");

  const toggleTasksManualInput = () => {
    if (tasksAutoToggle && tasksManualInput) {
      const isAuto = tasksAutoToggle.checked;

      if (isAuto) {
        tasksManualInput.disabled = true;
        if (tasksAutoHidden) tasksAutoHidden.disabled = true;
      } else {
        tasksManualInput.disabled = false;
        if (tasksAutoHidden) tasksAutoHidden.disabled = false;
      }
    }
  };

  if (tasksAutoToggle) {
    tasksAutoToggle.addEventListener("change", toggleTasksManualInput);
  }

  // Attribute suffix toggle handler
  const attributeSuffixToggle = document.querySelector(
    ".basalam-attribute-suffix-toggle"
  );
  const attributeSuffixPriority = document.querySelector(
    ".basalam-attribute-suffix-priority"
  );
  const attributeSuffixContainer = document.querySelector(
    ".basalam-attribute-suffix-container"
  );
  const attributeSuffixHidden = document.querySelector(
    ".basalam-attribute-suffix-hidden"
  );

  const toggleAttributeSuffixPriority = () => {
    if (attributeSuffixToggle) {
      const isEnabled = attributeSuffixToggle.checked;

      if (attributeSuffixPriority) {
        attributeSuffixPriority.disabled = !isEnabled;
      }

      if (attributeSuffixContainer) {
        attributeSuffixContainer.style.display = isEnabled ? "block" : "none";
      }

      if (attributeSuffixHidden) {
        attributeSuffixHidden.disabled = isEnabled;
      }
    }
  };

  if (attributeSuffixToggle) {
    attributeSuffixToggle.addEventListener(
      "change",
      toggleAttributeSuffixPriority
    );
    // Initialize on page load
    toggleAttributeSuffixPriority();
  }

  var modal = document.getElementById("sync_basalam_support_modal");
  var btn = document.getElementById("sync_basalam_support_btn");
  var cancelBtn = document.getElementById("sync_basalam_cancel_btn");

  if (btn) {
    btn.addEventListener("click", function () {
      if (modal) {
        modal.style.display = "flex";
      }
    });
  }

  if (cancelBtn) {
    cancelBtn.addEventListener("click", function () {
      if (modal) {
        modal.style.display = "none";
      }
    });
  }

  if (modal) {
    modal.addEventListener("click", function (e) {
      if (e.target === modal) {
        modal.style.display = "none";
      }
    });
  }

  // Star rating functionality
  var currentRating = 5;

  function updateStars(rating) {
    var stars = document.querySelectorAll('#basalam_rating_stars .basalam-star');
    stars.forEach(function(star) {
      var starRating = parseInt(star.getAttribute('data-rating'));
      if (starRating <= rating) {
        star.style.color = '#f5a623';
      } else {
        star.style.color = '#ddd';
      }
    });
  }

  updateStars(5);

  var starsContainer = document.getElementById('basalam_rating_stars');
  if (starsContainer) {
    starsContainer.addEventListener('mouseover', function(e) {
      if (e.target.classList.contains('basalam-star')) {
        var hoverRating = parseInt(e.target.getAttribute('data-rating'));
        updateStars(hoverRating);
      }
    });

    starsContainer.addEventListener('mouseout', function() {
      updateStars(currentRating);
    });

    starsContainer.addEventListener('click', function(e) {
      if (e.target.classList.contains('basalam-star')) {
        currentRating = parseInt(e.target.getAttribute('data-rating'));
        document.getElementById('sync_basalam_rating').value = currentRating;
        updateStars(currentRating);
      }
    });
  }

  // Remind Later button
  var remindLaterBtn = document.getElementById('sync_basalam_remind_later_review_btn');
  if (remindLaterBtn) {
    remindLaterBtn.addEventListener('click', function() {
      var nonceEl = document.getElementById('sync_basalam_remind_later_review_nonce');
      jQuery.ajax({
        url: ajaxurl,
        type: 'POST',
        data: {
          action: 'sync_basalam_remind_later_review',
          _wpnonce: nonceEl ? nonceEl.value : ''
        },
        success: function() {
          document.getElementById('sync_basalam_like_alert').style.display = 'none';
        }
      });
    });
  }

  // Never Remind button
  var neverRemindBtn = document.getElementById('sync_basalam_never_remind_review_btn');
  if (neverRemindBtn) {
    neverRemindBtn.addEventListener('click', function() {
      var nonceEl = document.getElementById('sync_basalam_never_remind_review_nonce');
      jQuery.ajax({
        url: ajaxurl,
        type: 'POST',
        data: {
          action: 'sync_basalam_never_remind_review',
          _wpnonce: nonceEl ? nonceEl.value : ''
        },
        success: function() {
          document.getElementById('sync_basalam_like_alert').style.display = 'none';
        }
      });
    });
  }

  // Submit Review form
  var supportForm = document.getElementById('sync_basalam_support_form');
  if (supportForm) {
    supportForm.addEventListener('submit', function(e) {
      e.preventDefault();
      var nonceEl = document.getElementById('sync_basalam_submit_review_nonce');
      var ratingEl = document.getElementById('sync_basalam_rating');
      var commentEl = document.getElementById('sync_basalam_comment');

      jQuery.ajax({
        url: ajaxurl,
        type: 'POST',
        data: {
          action: 'sync_basalam_submit_review',
          _wpnonce: nonceEl ? nonceEl.value : '',
          sync_basalam_rating: ratingEl ? ratingEl.value : '5',
          sync_basalam_comment: commentEl ? commentEl.value : ''
        },
        success: function(response) {
          if (response.success) {
            document.getElementById('sync_basalam_like_alert').style.display = 'none';
            if (modal) modal.style.display = 'none';
          } else {
            alert(response.data && response.data.message ? response.data.message : 'خطا در ارسال نظر');
          }
        }
      });
    });
  }
});
