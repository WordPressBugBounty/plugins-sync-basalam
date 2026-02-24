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
