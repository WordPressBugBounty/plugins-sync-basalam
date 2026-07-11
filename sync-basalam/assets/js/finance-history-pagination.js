(function () {
    const config = window.syncBasalamHistoryPagination;

    if (!config || typeof window.fetch !== 'function') {
        return;
    }

    const createSectionFromHtml = function (html) {
        const template = document.createElement('template');
        template.innerHTML = String(html).trim();

        return template.content.firstElementChild;
    };

    const setLoadingState = function (section, isLoading) {
        section.classList.toggle('is-loading', isLoading);
        section.setAttribute('aria-busy', isLoading ? 'true' : 'false');
    };

    const updateUrl = function (section, historyPage) {
        const url = new URL(window.location.href);

        url.searchParams.set('page', config.pageSlug);
        url.searchParams.set('sbp_active_page', section.dataset.activePage || '1');
        url.searchParams.set('sbp_history_page', String(historyPage));

        window.history.replaceState({ historyPage: historyPage }, '', url);
    };

    document.addEventListener('click', function (event) {
        const link = event.target.closest('.basalam-history-section .basalam-pagination a.page-numbers');

        if (!link) {
            return;
        }

        const section = link.closest('.basalam-history-section');
        if (!section || section.classList.contains('is-loading')) {
            return;
        }

        const linkUrl = new URL(link.href, window.location.origin);
        const historyPage = parseInt(link.dataset.historyPage || linkUrl.searchParams.get('sbp_history_page') || '', 10);
        const activePage = section.dataset.activePage || linkUrl.searchParams.get('sbp_active_page') || '1';

        if (!historyPage) {
            return;
        }

        event.preventDefault();
        setLoadingState(section, true);

        const body = new URLSearchParams();
        body.set('action', config.action);
        body.set('nonce', config.nonce);
        body.set('active_page', String(activePage));
        body.set('history_page', String(historyPage));

        window.fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString()
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error(config.errorMessage);
                }

                return response.json();
            })
            .then(function (payload) {
                if (!payload || !payload.success || !payload.data || !payload.data.html) {
                    const message = payload && payload.data && payload.data.message ? payload.data.message : config.errorMessage;
                    throw new Error(message);
                }

                const nextSection = createSectionFromHtml(payload.data.html);
                if (!nextSection) {
                    throw new Error(config.errorMessage);
                }

                section.replaceWith(nextSection);
                updateUrl(nextSection, nextSection.dataset.historyPage || payload.data.historyPage || historyPage);
            })
            .catch(function (error) {
                setLoadingState(section, false);
                window.alert(error.message || config.errorMessage);
            });
    });
}());
