(function () {
  var config = window.syncBasalamBalanceSettlement;

  if (!config || typeof window.fetch !== "function") {
    return;
  }

  var overlay = document.getElementById("basalam-settlement-modal");
  var amountInput = document.getElementById("basalam-settlement-amount");
  var submitBtn = document.getElementById("basalam-settlement-submit");
  var nextBtn = document.getElementById("basalam-settlement-next");
  var cancelBtn = document.getElementById("basalam-settlement-cancel");
  var closeBtn = document.getElementById("basalam-settlement-close");
  var errorMessage = document.getElementById("basalam-settlement-error");
  var methodInput = document.getElementById("basalam-settlement-method");
  var investmentInput = document.getElementById("basalam-settlement-investment-option-id");
  var bankAccountInput = document.getElementById("basalam-settlement-bank-account-id");

  var step1 = document.getElementById("basalam-settlement-step-1");
  var step2 = document.getElementById("basalam-settlement-step-2");
  var backBtn = document.getElementById("basalam-settlement-back");
  var submitBankBtn = document.getElementById("basalam-settlement-submit-bank");
  var bankAccountsList = document.getElementById("basalam-bank-accounts-list");
  var bankAccountsError = document.getElementById("basalam-bank-accounts-error");

  var selectedBankAccountId = null;

  function formatWithCommas(value) {
    var num = value.replace(/\D/g, "");
    return num.replace(/\B(?=(\d{3})+(?!\d))/g, ",");
  }

  function getRawNumber(value) {
    return parseInt(value.replace(/,/g, ""), 10) || 0;
  }

  function openModal(method) {
    methodInput.value = method;
    amountInput.value = "";
    amountInput.classList.remove("basalam-plus-modal__input--error");
    errorMessage.style.display = "none";
    errorMessage.textContent = "";
    selectedBankAccountId = null;

    var balanceError = document.getElementById("basalam-settlement-balance-error");
    if (balanceError) {
      balanceError.style.display = "none";
    }

    // Show step 1, hide step 2
    step1.style.display = "";
    step2.style.display = "none";

    // For bank transfer (method=1): show "بعدی", hide "ثبت درخواست"
    // For wallet transfer (method=2): hide "بعدی", show "ثبت درخواست"
    var isBank = parseInt(method, 10) === 1;
    nextBtn.style.display = isBank ? "" : "none";
    submitBtn.style.display = isBank ? "none" : "";

    submitBtn.disabled = false;
    submitBtn.textContent = config.submitText;

    overlay.classList.add("is-open");
    amountInput.focus();
  }

  function closeModal() {
    overlay.classList.remove("is-open");
  }

  function showError(msg) {
    errorMessage.textContent = msg;
    errorMessage.style.display = "block";
  }

  function setLoading(loading) {
    submitBtn.disabled = loading;
    submitBtn.textContent = loading ? config.loadingText : config.submitText;
  }

  function setBankLoading(loading) {
    submitBankBtn.disabled = loading;
    submitBankBtn.textContent = loading ? config.loadingText : config.submitText;
  }

  function goToStep2() {
    step1.style.display = "none";
    step2.style.display = "";
    fetchBankAccounts();
  }

  function goToStep1() {
    step2.style.display = "none";
    step1.style.display = "";
  }

  function fetchBankAccounts() {
    bankAccountsList.innerHTML = '<div class="basalam-bank-accounts-loading">در حال بارگذاری...</div>';
    bankAccountsError.style.display = "none";
    selectedBankAccountId = null;
    submitBankBtn.disabled = true;

    var body = new URLSearchParams();
    body.set("action", config.bankAccountsAction);
    body.set("nonce", config.nonce);

    window
      .fetch(config.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
        },
        body: body.toString(),
      })
      .then(function (response) {
        return response.json();
      })
      .then(function (payload) {
        if (!payload || !payload.success) {
          var msg = payload && payload.data && payload.data.message ? payload.data.message : config.bankAccountsError;
          throw new Error(msg);
        }

        var accounts = payload.data && payload.data.accounts ? payload.data.accounts : [];
        renderBankAccounts(accounts);
      })
      .catch(function (error) {
        bankAccountsList.innerHTML = "";
        bankAccountsError.textContent = error.message || config.bankAccountsError;
        bankAccountsError.style.display = "block";
      });
  }

  function renderBankAccounts(accounts) {
    if (!accounts || accounts.length === 0) {
      bankAccountsList.innerHTML = '<div class="basalam-bank-accounts-empty">حساب بانکی یافت نشد.</div>';
      return;
    }

    var html = "";
    for (var i = 0; i < accounts.length; i++) {
      var acc = accounts[i];
      var id = acc.id || "";
      var owner = acc.account_owner || "-";
      var bankName = acc.bank_name || "-";
      var sheba = acc.sheba_number || "-";

      html +=
        '<label class="basalam-bank-account-item" data-id="' + id + '">' +
          '<input type="radio" name="bank_account" value="' + id + '" class="basalam-bank-account-item__radio" />' +
          '<div class="basalam-bank-account-item__content">' +
            '<div class="basalam-bank-account-item__row">' +
              '<span class="basalam-bank-account-item__label">صاحب حساب:</span>' +
              '<span class="basalam-bank-account-item__value">' + owner + '</span>' +
            '</div>' +
            '<div class="basalam-bank-account-item__row">' +
              '<span class="basalam-bank-account-item__label">بانک:</span>' +
              '<span class="basalam-bank-account-item__value">' + bankName + '</span>' +
            '</div>' +
            '<div class="basalam-bank-account-item__row">' +
              '<span class="basalam-bank-account-item__label">شماره شبا:</span>' +
              '<span class="basalam-bank-account-item__value basalam-bank-account-item__sheba">' + sheba + '</span>' +
            '</div>' +
          '</div>' +
        '</label>';
    }

    bankAccountsList.innerHTML = html;
  }

  // Bank account selection
  bankAccountsList.addEventListener("change", function (e) {
    if (e.target.name !== "bank_account") {
      return;
    }

    var id = e.target.value;
    selectedBankAccountId = id;
    bankAccountInput.value = id;
    submitBankBtn.disabled = false;

    // Highlight selected
    var items = bankAccountsList.querySelectorAll(".basalam-bank-account-item");
    for (var i = 0; i < items.length; i++) {
      items[i].classList.toggle("is-selected", items[i].getAttribute("data-id") === id);
    }
  });

  // Open modal on button click
  document.addEventListener("click", function (e) {
    var btn = e.target.closest(".basalam-balance-action");
    if (!btn || btn.disabled) {
      return;
    }

    var method = btn.getAttribute("data-method");
    if (!method) {
      return;
    }

    e.preventDefault();
    openModal(parseInt(method, 10));

    var title = btn.classList.contains("basalam-balance-action--wallet") ? config.walletTitle : config.bankTitle;
    var modalTitle = overlay.querySelector(".basalam-plus-modal__title");
    if (modalTitle) {
      modalTitle.textContent = title;
    }
  });

  // Format input with commas and validate against balance
  amountInput.addEventListener("input", function () {
    var cursorPos = this.selectionStart;
    var before = this.value;
    var formatted = formatWithCommas(this.value);
    this.value = formatted;

    var diff = formatted.length - before.length;
    this.setSelectionRange(cursorPos + diff, cursorPos + diff);

    // Reset error state on new input
    this.classList.remove("basalam-plus-modal__input--error");
    var balanceError = document.getElementById("basalam-settlement-balance-error");
    if (balanceError) {
      balanceError.style.display = "none";
    }

    // Validate against max balance
    var maxBalance = parseInt(this.getAttribute("data-max-balance"), 10) || 0;
    var rawAmount = getRawNumber(formatted);
    if (maxBalance > 0 && rawAmount > maxBalance) {
      this.classList.add("basalam-plus-modal__input--error");
      if (balanceError) {
        balanceError.style.display = "block";
      }
    }
  });

  // Close modal
  cancelBtn.addEventListener("click", closeModal);
  closeBtn.addEventListener("click", closeModal);

  overlay.addEventListener("click", function (e) {
    if (e.target === overlay) {
      closeModal();
    }
  });

  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape" && overlay.classList.contains("is-open")) {
      closeModal();
    }
  });

  // Validate amount helper
  function validateAmount() {
    var rawAmount = getRawNumber(amountInput.value);

    if (rawAmount <= 0) {
      showError(config.amountError);
      return false;
    }

    if (amountInput.classList.contains("basalam-plus-modal__input--error")) {
      return false;
    }

    return true;
  }

  // Next button (step 1 → step 2) for bank transfer
  nextBtn.addEventListener("click", function () {
    errorMessage.style.display = "none";
    if (!validateAmount()) {
      return;
    }
    goToStep2();
  });

  // Back button (step 2 → step 1)
  backBtn.addEventListener("click", function () {
    goToStep1();
  });

  // Submit settlement request (used by both wallet and bank)
  function submitSettlement() {
    var rawAmount = getRawNumber(amountInput.value);

    if (!validateAmount()) {
      return;
    }

    var rialAmount = rawAmount * 10;

    errorMessage.style.display = "none";

    var body = new URLSearchParams();
    body.set("action", config.action);
    body.set("nonce", config.nonce);
    body.set("amount", String(rialAmount));
    body.set("method", methodInput.value);

    var investmentId = investmentInput ? investmentInput.value : "";
    var bankAccountIdVal = bankAccountInput ? bankAccountInput.value : "";

    if (investmentId !== "") {
      body.set("investment_option_id", investmentId);
    }
    if (bankAccountIdVal !== "") {
      body.set("bank_account_id", bankAccountIdVal);
    }

    return window.fetch(config.ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
      },
      body: body.toString(),
    })
      .then(function (response) {
        return response.json();
      })
      .then(function (payload) {
        if (!payload || !payload.success) {
          var msg = payload && payload.data && payload.data.message ? payload.data.message : config.errorMessage;
          throw new Error(msg);
        }

        closeModal();
        if (payload.data && payload.data.message) {
          window.alert(payload.data.message);
        } else {
          window.alert(config.successMessage);
        }
        window.location.reload();
      });
  }

  // Wallet submit (step 1 direct)
  submitBtn.addEventListener("click", function () {
    setLoading(true);
    submitSettlement().catch(function (error) {
      setLoading(false);
      showError(error.message || config.errorMessage);
    });
  });

  // Bank submit (step 2)
  submitBankBtn.addEventListener("click", function () {
    if (!selectedBankAccountId) {
      return;
    }
    setBankLoading(true);
    submitSettlement().catch(function (error) {
      setBankLoading(false);
      bankAccountsError.textContent = error.message || config.errorMessage;
      bankAccountsError.style.display = "block";
    });
  });
})();
