function ticketFileUpload(inputId, nonce) {
    var input = document.getElementById(inputId);
    if (!input) return;

    var wrapper = input.closest('.ticket-file-upload');
    var previews = wrapper.querySelector('.ticket-file-upload__previews');

    input.addEventListener('change', function () {
        var file = this.files[0];
        if (!file) return;
        this.value = '';

        var objectUrl = URL.createObjectURL(file);
        var item = document.createElement('div');
        item.className = 'ticket-file-upload__preview-item ticket-file-upload__preview-item--loading';
        item.innerHTML =
            '<img src="' + objectUrl + '" class="ticket-file-upload__preview-img" alt="">' +
            '<div class="ticket-file-upload__preview-info">' +
                '<span class="ticket-file-upload__preview-name">' + file.name + '</span>' +
                '<span class="ticket-file-upload__preview-status">در حال آپلود...</span>' +
            '</div>' +
            '<button type="button" class="ticket-file-upload__preview-remove" aria-label="حذف">×</button>';

        previews.appendChild(item);

        item.querySelector('.ticket-file-upload__preview-remove').addEventListener('click', function () {
            URL.revokeObjectURL(objectUrl);
            item.remove();
        });

        var fd = new FormData();
        fd.append('action', 'upload_ticket_media');
        fd.append('_wpnonce', nonce);
        fd.append('file', file);

        fetch(ajaxurl, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                item.classList.remove('ticket-file-upload__preview-item--loading');
                var statusEl = item.querySelector('.ticket-file-upload__preview-status');
                if (res.success) {
                    item.classList.add('ticket-file-upload__preview-item--done');
                    statusEl.textContent = 'آپلود شد';
                    var hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'file_ids[]';
                    hidden.value = res.data.file_id;
                    item.appendChild(hidden);
                } else {
                    item.classList.add('ticket-file-upload__preview-item--error');
                    statusEl.textContent = (res.data && res.data.message) ? res.data.message : 'خطا در آپلود';
                }
            })
            .catch(function () {
                item.classList.remove('ticket-file-upload__preview-item--loading');
                item.classList.add('ticket-file-upload__preview-item--error');
                item.querySelector('.ticket-file-upload__preview-status').textContent = 'خطا در آپلود';
            });
    });
}

(function (window, document) {
    'use strict';

    var sections = [
        {
            prefix: 'dashboard',
            label: 'پیشخوان وردپرس'
        },
        {
            prefix: 'host_panel',
            label: 'کنترل پنل هاست'
        }
    ];

    function showError(message) {
        if (window.BasalamToast) {
            window.BasalamToast.error(message);
        }
    }

    function field(form, name) {
        return form.querySelector('[name="' + name + '"]');
    }

    function validateAccess(form) {
        for (var i = 0; i < sections.length; i++) {
            var section = sections[i];
            var loginUrl = field(form, section.prefix + '_login_url');
            var username = field(form, section.prefix + '_username');
            var password = field(form, section.prefix + '_password');
            if (!loginUrl || !username || !password) continue;

            var loginValue = loginUrl.value.trim();
            var usernameValue = username.value.trim();
            var passwordValue = password.value;
            var hasAnyValue = loginValue !== '' || usernameValue !== '' || passwordValue !== '';
            if (!hasAnyValue) continue;

            if (usernameValue === '' || passwordValue === '') {
                var missingField = usernameValue === '' ? username : password;
                missingField.setAttribute('aria-invalid', 'true');
                missingField.focus();
                showError('برای ' + section.label + ' نام کاربری و رمز عبور را کامل وارد کنید.');
                return false;
            }

            if (passwordValue.length > 1024 || /[\u0000-\u001F\u007F]/.test(passwordValue)) {
                password.setAttribute('aria-invalid', 'true');
                password.focus();
                showError(passwordValue.length > 1024
                    ? 'رمز عبور طولانی‌تر از حد مجاز است.'
                    : 'رمز عبور دارای نویسه کنترلی نامعتبر است.');
                return false;
            }
        }

        return true;
    }

    function setSubmitting(form, submitting) {
        var submitButton = form.querySelector('button[type="submit"]');
        if (!submitButton) return;

        submitButton.disabled = submitting;
        if (submitting) {
            submitButton.dataset.originalText = submitButton.textContent;
            submitButton.textContent = 'در حال ارسال...';
        } else if (submitButton.dataset.originalText) {
            submitButton.textContent = submitButton.dataset.originalText;
            delete submitButton.dataset.originalText;
        }
    }

    function rememberCreatedTicket(form, ticketId) {
        if (!ticketId) return;

        var input = field(form, 'existing_ticket_id');
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'existing_ticket_id';
            form.appendChild(input);
        }
        input.value = String(ticketId);
    }

    function submitCreateTicket(form) {
        setSubmitting(form, true);

        fetch(window.ajaxurl, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin'
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (response) {
                var data = response && response.data ? response.data : {};
                if (!response || !response.success) {
                    rememberCreatedTicket(form, data.ticket_id);
                    showError(data.message || 'خطایی در ثبت تیکت رخ داد. اطلاعات فرم حفظ شده است.');
                    return;
                }

                if (data.redirect_url) {
                    window.location.assign(data.redirect_url);
                }
            })
            .catch(function () {
                showError('ارتباط با سرور برقرار نشد. اطلاعات فرم حفظ شده است.');
            })
            .finally(function () {
                setSubmitting(form, false);
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var actionInputs = document.querySelectorAll(
            'input[name="action"][value="create_ticket"], ' +
            'input[name="action"][value="create_ticket_item"]'
        );

        for (var i = 0; i < actionInputs.length; i++) {
            var form = actionInputs[i].closest('form');
            if (!form || form.dataset.ticketAccessValidation === '1') continue;
            form.dataset.ticketAccessValidation = '1';
            form.addEventListener('submit', function (event) {
                if (!validateAccess(event.currentTarget)) {
                    event.preventDefault();
                    return;
                }

                var action = field(event.currentTarget, 'action');
                if (action && action.value === 'create_ticket') {
                    event.preventDefault();
                    submitCreateTicket(event.currentTarget);
                }
            });
        }
    });
})(window, document);
