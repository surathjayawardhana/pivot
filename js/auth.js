/**
 * NSBM ArtCraft - client-side validation for login.php and register.php.
 *
 * This only makes the form friendlier. The server (PHP) repeats every check,
 * because JavaScript can always be bypassed.
 */
(function () {
    'use strict';

    var NSBM_SUFFIX = '.nsbm.ac.lk';
    var PASSWORD_MIN = 8;
    var PASSWORD_MAX = 72;
    var NAME_MAX = 50;
    var EMAIL_MAX = 254;

    var form = document.getElementById('auth-form');
    if (!form) {
        return;
    }

    var formType = form.getAttribute('data-auth-form'); // "register" or "login"
    var submitButton = form.querySelector('button[type="submit"]');
    var submitLabel = submitButton.querySelector('.btn-label');
    var originalLabel = submitLabel.textContent;
    var isSubmitting = false;

    // ---- Helpers ------------------------------------------------------

    function field(name) {
        return form.elements[name] || null;
    }

    function collapseSpaces(value) {
        return value.replace(/\s+/g, ' ').trim();
    }

    function byteLength(value) {
        return new TextEncoder().encode(value).length;
    }

    // ---- Validators: return an error message, or '' when the value is fine ----

    function validateName(label) {
        return function (value) {
            var cleaned = collapseSpaces(value);
            if (cleaned === '') {
                return 'Enter your ' + label + '.';
            }
            if (cleaned.length > NAME_MAX) {
                return label.charAt(0).toUpperCase() + label.slice(1) +
                    ' must be ' + NAME_MAX + ' characters or fewer.';
            }
            return '';
        };
    }

    function validateEmail(value) {
        var email = value.trim().toLowerCase();
        var parts;
        var domain;

        if (email === '') {
            return 'Enter your NSBM email address.';
        }

        // Basic shape: one "@", no spaces, something on both sides.
        parts = email.split('@');
        if (email.length > EMAIL_MAX || parts.length !== 2 || parts[0] === '' || parts[1] === '' ||
            /\s/.test(email) || !/^[a-z0-9.!#$%&'*+\/=?^_`{|}~-]+$/.test(parts[0])) {
            return 'Enter a valid email address.';
        }

        domain = parts[1];
        if (!/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/.test(domain)) {
            return 'Enter a valid email address.';
        }

        // Must end with ".nsbm.ac.lk" and have a name in front (e.g. student.nsbm.ac.lk).
        if (domain.length <= NSBM_SUFFIX.length ||
            domain.slice(-NSBM_SUFFIX.length) !== NSBM_SUFFIX) {
            return 'Use your NSBM email address (for example, name@student.nsbm.ac.lk).';
        }

        return '';
    }

    function validateNewPassword(value) {
        if (value === '') {
            return 'Enter a password.';
        }
        if (value.length < PASSWORD_MIN) {
            return 'Password must be at least ' + PASSWORD_MIN + ' characters.';
        }
        if (byteLength(value) > PASSWORD_MAX) {
            return 'Password must be ' + PASSWORD_MAX + ' characters or fewer.';
        }
        return '';
    }

    function validateLoginPassword(value) {
        return value === '' ? 'Enter your password.' : '';
    }

    function validateConfirm(value) {
        var password = field('password').value;
        if (value === '') {
            return 'Confirm your password.';
        }
        if (value !== password) {
            return 'Passwords do not match.';
        }
        return '';
    }

    var validators = formType === 'register'
        ? {
            first_name: validateName('first name'),
            surname: validateName('surname'),
            email: validateEmail,
            password: validateNewPassword,
            confirm_password: validateConfirm
        }
        : {
            email: validateEmail,
            password: validateLoginPassword
        };

    // ---- Showing and clearing errors ------------------------------------

    function showError(input, message) {
        var errorBox = document.getElementById(input.id + '-error');
        input.classList.remove('is-valid');
        input.classList.add('is-invalid');
        input.setAttribute('aria-invalid', 'true');
        if (errorBox) {
            errorBox.textContent = message;
        }
    }

    function showValid(input) {
        var errorBox = document.getElementById(input.id + '-error');
        input.classList.remove('is-invalid');
        input.classList.add('is-valid');
        input.removeAttribute('aria-invalid');
        if (errorBox) {
            errorBox.textContent = '';
        }
    }

    function clearState(input) {
        var errorBox = document.getElementById(input.id + '-error');
        input.classList.remove('is-invalid', 'is-valid');
        input.removeAttribute('aria-invalid');
        if (errorBox) {
            errorBox.textContent = '';
        }
    }

    /** Validates one field. Returns true when it is valid. */
    function checkField(name) {
        var input = field(name);
        var message;

        if (!input || !validators[name]) {
            return true;
        }

        message = validators[name](input.value);
        if (message !== '') {
            showError(input, message);
            return false;
        }

        showValid(input);
        return true;
    }

    // ---- Events -----------------------------------------------------------

    Object.keys(validators).forEach(function (name) {
        var input = field(name);
        if (!input) {
            return;
        }

        // Validate when the person leaves the field.
        input.addEventListener('blur', function () {
            if (name === 'first_name' || name === 'surname') {
                input.value = collapseSpaces(input.value);
            } else if (name === 'email') {
                input.value = input.value.trim();
            }
            if (input.value !== '' || input.classList.contains('is-invalid')) {
                checkField(name);
            }
        });

        // Once a field shows an error, re-check as the person types the fix.
        input.addEventListener('input', function () {
            if (input.classList.contains('is-invalid')) {
                checkField(name);
            } else if (input.classList.contains('is-valid')) {
                clearState(input);
            }

            // Keep the "passwords match" message in step with the password field.
            if (name === 'password' && formType === 'register') {
                var confirm = field('confirm_password');
                if (confirm && confirm.value !== '') {
                    checkField('confirm_password');
                }
            }
        });
    });

    // Show / Hide password buttons
    Array.prototype.forEach.call(form.querySelectorAll('.toggle-password'), function (button) {
        button.addEventListener('click', function () {
            var target = document.getElementById(button.getAttribute('aria-controls'));
            var showing = target.type === 'text';
            target.type = showing ? 'password' : 'text';
            button.textContent = showing ? 'Show' : 'Hide';
            button.setAttribute('aria-pressed', showing ? 'false' : 'true');
        });
    });

    function setLoading(loading) {
        isSubmitting = loading;
        submitButton.classList.toggle('is-loading', loading);
        submitButton.disabled = loading;
        submitButton.setAttribute('aria-busy', loading ? 'true' : 'false');
        submitLabel.textContent = loading
            ? (submitButton.getAttribute('data-loading-text') || 'Please wait…')
            : originalLabel;
    }

    form.addEventListener('submit', function (event) {
        var firstInvalid = null;

        // Ignore repeat clicks while the first request is on its way.
        if (isSubmitting) {
            event.preventDefault();
            return;
        }

        // Tidy values before checking and sending.
        ['first_name', 'surname'].forEach(function (name) {
            var input = field(name);
            if (input) {
                input.value = collapseSpaces(input.value);
            }
        });
        field('email').value = field('email').value.trim();

        Object.keys(validators).forEach(function (name) {
            if (!checkField(name) && firstInvalid === null) {
                firstInvalid = field(name);
            }
        });

        if (firstInvalid) {
            event.preventDefault();
            firstInvalid.focus();
            return;
        }

        setLoading(true);
    });

    // If the person comes back with the browser's Back button, unlock the form.
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            setLoading(false);
        }
    });
})();
