// Mobile menu toggle for the site header.
(function () {
    'use strict';

    var nav = document.querySelector('.site-nav');
    if (!nav) return;

    var toggle = nav.querySelector('.site-nav__toggle');
    if (!toggle) return;

    var desktop = window.matchMedia('(min-width: 48rem)');

    function setOpen(isOpen) {
        nav.classList.toggle('is-open', isOpen);
        toggle.setAttribute('aria-expanded', String(isOpen));
    }

    // Enables the collapsed mobile menu (without JS the links stay visible).
    nav.classList.add('site-nav--js');

    toggle.addEventListener('click', function () {
        setOpen(toggle.getAttribute('aria-expanded') !== 'true');
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
            setOpen(false);
            toggle.focus();
        }
    });

    desktop.addEventListener('change', function (event) {
        if (event.matches) setOpen(false);
    });
})();
