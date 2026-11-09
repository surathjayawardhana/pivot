/* Profile pages. Everything here is a convenience on top of normal forms:
   without JavaScript the follow button and the edit form still work, and the
   server validates everything again. */
(function () {
    'use strict';

    var MAX_BYTES = 5 * 1024 * 1024;
    var TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /* ---------- Follow / unfollow without a page reload ---------- */
    var followForm = document.querySelector('[data-follow-form]');
    if (followForm && window.fetch && window.FormData) {
        var button = followForm.querySelector('[data-follow-button]');
        var actionInput = followForm.querySelector('[data-follow-action]');
        var countEl = document.querySelector('[data-followers-count]');
        var statusEl = document.querySelector('[data-follow-status]');

        followForm.addEventListener('submit', function (event) {
            event.preventDefault();
            if (button.disabled) { return; }
            button.disabled = true;

            // getAttribute: the form has an input named "action", which shadows form.action.
            fetch(followForm.getAttribute('action'), {
                method: 'POST',
                body: new FormData(followForm),
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        return { status: response.status, data: data };
                    });
                })
                .then(function (result) {
                    var data = result.data;
                    if (result.status === 401 && data.login) {
                        window.location.href = data.login;
                        return;
                    }
                    if (!data.ok) { throw new Error(data.message || 'Request failed'); }

                    actionInput.value = data.following ? 'unfollow' : 'follow';
                    button.textContent = data.following ? 'Unfollow' : 'Follow';
                    button.classList.toggle('profile-btn--secondary', !!data.following);
                    if (countEl) { countEl.textContent = String(data.followers); }
                    if (statusEl) { statusEl.textContent = data.message; }
                    button.disabled = false;
                })
                .catch(function () {
                    // Anything unexpected: fall back to the normal form post.
                    followForm.submit();
                });
        });
    }

    /* ---------- Edit profile: picture preview ---------- */
    var fileInput = document.querySelector('[data-avatar-input]');
    var preview = document.querySelector('[data-avatar-preview]');
    var errorEl = document.querySelector('[data-avatar-error]');

    if (fileInput && preview) {
        var savedSrc = preview.getAttribute('src');
        var objectUrl = null;

        fileInput.addEventListener('change', function () {
            var file = fileInput.files && fileInput.files[0];
            var message = '';

            if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }

            if (!file) {
                preview.src = savedSrc;
            } else if (TYPES.indexOf(file.type) === -1) {
                message = 'Only JPG, PNG or WEBP images are allowed.';
            } else if (file.size > MAX_BYTES) {
                message = 'The image is too large. The maximum size is 5 MB.';
            } else {
                objectUrl = URL.createObjectURL(file);
                preview.src = objectUrl;
            }

            if (message) {
                fileInput.value = '';
                preview.src = savedSrc;
            }
            if (errorEl) {
                errorEl.textContent = message;
                var field = errorEl.closest('.profile-field');
                if (field) { field.classList.toggle('has-error', !!message); }
            }
        });
    }

    /* ---------- Edit profile: biography counter ---------- */
    var bio = document.querySelector('[data-bio]');
    var counter = document.querySelector('[data-bio-counter]');
    if (bio && counter) {
        var max = parseInt(bio.getAttribute('data-max'), 10) || 250;
        var update = function () {
            counter.textContent = Array.from(bio.value).length + ' / ' + max;
        };
        bio.addEventListener('input', update);
        update();
    }

    /* ---------- Confirm before removing the picture ---------- */
    var confirmForm = document.querySelector('form[data-confirm]');
    if (confirmForm) {
        confirmForm.addEventListener('submit', function (event) {
            if (!window.confirm(confirmForm.getAttribute('data-confirm'))) {
                event.preventDefault();
            }
        });
    }
})();
