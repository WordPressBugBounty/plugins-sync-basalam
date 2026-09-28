(function () {
  window.syncBasalamFinanceLock = function () {
    var page = document.querySelector('.basalam-finance-page');
    if (!page) {
      return;
    }

    var modal = document.getElementById('basalam-settlement-modal');
    if (modal) {
      modal.classList.remove('is-open');
    }

    var dashboard = page.querySelector('.basalam-dashboard');
    if (dashboard) {
      dashboard.setAttribute('inert', '');
      dashboard.setAttribute('aria-hidden', 'true');
    }

    page.classList.add('is-locked');

    var loginButton = page.querySelector('.basalam-finance-lock__button');
    if (loginButton) {
      loginButton.focus();
    }
  };
}());
