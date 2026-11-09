/* Like / unlike without a page reload.
   Progressive enhancement: without JavaScript the button is a normal form post
   to like_action.php, and the server validates everything again. */
(function () {
    'use strict';

    if (!window.fetch || !window.FormData) { return; }

    function render(form, data) {
        var button = form.querySelector('[data-like-button]');
        var actionInput = form.querySelector('[data-like-action]');
        var countEl = form.querySelector('[data-like-count]');
        var title = button.getAttribute('data-title') || '';

        actionInput.value = data.liked ? 'unlike' : 'like';
        button.classList.toggle('is-liked', !!data.liked);
        button.setAttribute('aria-pressed', data.liked ? 'true' : 'false');
        button.setAttribute('aria-label', (data.liked ? 'Unlike artwork: ' : 'Like artwork: ') + title);
        if (countEl) { countEl.textContent = String(data.likes); }

        if (data.liked) {                       // small "pop" on like
            button.classList.remove('is-pop');
            void button.offsetWidth;            // restart the animation
            button.classList.add('is-pop');
        }
    }

    // One listener for every like button on the page.
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form.matches || !form.matches('[data-like-form]')) { return; }

        event.preventDefault();
        var button = form.querySelector('[data-like-button]');
        if (!button || button.disabled) { return; }
        button.disabled = true;

        // getAttribute: the form has an input named "action", which shadows form.action.
        fetch(form.getAttribute('action'), {
            method: 'POST',
            body: new FormData(form),
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
                    window.location.href = data.login;   // guests go to the login page
                    return;
                }
                if (!data.ok) { throw new Error(data.message || 'Request failed'); }
                render(form, data);
                button.disabled = false;
            })
            .catch(function () {
                // Anything unexpected: fall back to the normal form post.
                button.disabled = false;
                form.submit();
            });
    });
})();
