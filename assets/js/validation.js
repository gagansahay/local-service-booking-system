/* =====================================================================
   LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
   BCSP-064 -- Bachelor of Computer Applications, IGNOU
   ---------------------------------------------------------------------
   FILE    : assets/js/validation.js
   PURPOSE : Client-side form validation.

   IMPORTANT -- read this before relying on anything below.

   These checks exist to give the user immediate feedback, so they are
   not made to wait for a page reload to learn that a phone number was
   two digits short. They are NOT a security control. Anyone can disable
   JavaScript, edit the DOM, or post directly with curl.

   EVERY rule implemented here is implemented again on the server in
   includes/functions.php (valid_email, valid_phone, valid_pincode,
   password_problem, valid_date) and is additionally enforced by CHECK
   constraints in the database. The server is the authority; this file
   is only a convenience.
   ===================================================================== */
'use strict';

document.addEventListener('DOMContentLoaded', function () {

    /* -----------------------------------------------------------------
       Rules. Each returns an error string, or null when the value is
       acceptable. These mirror the PHP functions one for one.
       ----------------------------------------------------------------*/
    const rules = {
        required: function (value) {
            return value.trim() === '' ? 'This field is required.' : null;
        },

        email: function (value) {
            if (value.trim() === '') return null;
            return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value)
                ? null
                : 'Enter a valid email address, for example name@example.com';
        },

        /* Indian mobile: ten digits starting 6-9. */
        phone: function (value) {
            if (value.trim() === '') return null;
            return /^[6-9]\d{9}$/.test(value)
                ? null
                : 'Enter a 10-digit mobile number starting with 6, 7, 8 or 9.';
        },

        pincode: function (value) {
            if (value.trim() === '') return null;
            return /^[1-9]\d{5}$/.test(value) ? null : 'Enter a valid 6-digit PIN code.';
        },

        password: function (value) {
            if (value === '') return null;
            if (value.length < 8)        return 'Use at least 8 characters.';
            if (!/[A-Za-z]/.test(value)) return 'Include at least one letter.';
            if (!/[0-9]/.test(value))    return 'Include at least one number.';
            return null;
        },

        /* Confirm field must match the password field it names. */
        match: function (value, field) {
            const other = document.getElementById(field.getAttribute('data-match'));
            if (!other || value === '') return null;
            return value === other.value ? null : 'The two passwords do not match.';
        },

        /* A booking cannot be placed in the past. */
        futuredate: function (value) {
            if (value === '') return null;
            const today    = new Date(); today.setHours(0, 0, 0, 0);
            const selected = new Date(value + 'T00:00:00');
            return selected < today ? 'Choose today or a later date.' : null;
        },

        positive: function (value) {
            if (value.trim() === '') return null;
            return parseFloat(value) >= 0 ? null : 'Enter a value of zero or more.';
        }
    };

    /* -----------------------------------------------------------------
       Show / clear an inline message beneath a field.
       ----------------------------------------------------------------*/
    function showError(field, message) {
        field.classList.add('is-invalid');
        field.setAttribute('aria-invalid', 'true');

        let box = field.parentNode.querySelector('.error.js-error');
        if (!box) {
            box = document.createElement('div');
            box.className = 'error js-error';
            field.parentNode.appendChild(box);
        }
        box.textContent = message;   // textContent, never innerHTML
    }

    function clearError(field) {
        field.classList.remove('is-invalid');
        field.removeAttribute('aria-invalid');
        const box = field.parentNode.querySelector('.error.js-error');
        if (box) box.remove();
    }

    /**
     * Run every rule named in data-validate on one field.
     * @returns {boolean} true when the field passes
     */
    function validateField(field) {
        const spec = field.getAttribute('data-validate');
        if (!spec) return true;

        const names = spec.split('|');

        for (let i = 0; i < names.length; i++) {
            const rule = rules[names[i].trim()];
            if (!rule) continue;

            const message = rule(field.value, field);
            if (message) { showError(field, message); return false; }
        }

        clearError(field);
        return true;
    }

    /* -----------------------------------------------------------------
       Wire up every form that opts in with data-validate-form.
       ----------------------------------------------------------------*/
    document.querySelectorAll('form[data-validate-form]').forEach(function (form) {
        const fields = form.querySelectorAll('[data-validate]');

        fields.forEach(function (field) {
            // Validate on blur, but once a field is already marked bad,
            // re-check on every keystroke so the error clears as soon as
            // the user fixes it rather than lingering until they leave.
            field.addEventListener('blur', function () { validateField(field); });
            field.addEventListener('input', function () {
                if (field.classList.contains('is-invalid')) validateField(field);
            });
        });

        form.addEventListener('submit', function (event) {
            let firstBad = null;

            fields.forEach(function (field) {
                if (!validateField(field) && !firstBad) firstBad = field;
            });

            if (firstBad) {
                event.preventDefault();
                firstBad.focus();
                firstBad.scrollIntoView({ block: 'center', behavior: 'smooth' });
                return;
            }

            // Stop double submission -- a second click would create a
            // duplicate booking. The button is re-enabled if the browser
            // restores the page from cache (back button).
            const submit = form.querySelector('button[type="submit"]');
            if (submit) {
                submit.disabled = true;
                submit.dataset.originalText = submit.textContent;
                submit.textContent = 'Please wait...';
            }
        });
    });

    window.addEventListener('pageshow', function () {
        document.querySelectorAll('button[type="submit"][disabled]').forEach(function (button) {
            if (button.dataset.originalText) {
                button.disabled = false;
                button.textContent = button.dataset.originalText;
            }
        });
    });

    /* -----------------------------------------------------------------
       Password strength meter.
       ----------------------------------------------------------------*/
    const passwordBox = document.getElementById('password');
    const meterFill   = document.getElementById('meterFill');

    if (passwordBox && meterFill) {
        passwordBox.addEventListener('input', function () {
            const value = passwordBox.value;
            let score = 0;

            if (value.length >= 8)          score++;
            if (value.length >= 12)         score++;
            if (/[A-Z]/.test(value))        score++;
            if (/[0-9]/.test(value))        score++;
            if (/[^A-Za-z0-9]/.test(value)) score++;

            meterFill.className = 'meter__fill';
            if (value.length === 0)  return;
            if (score <= 2)          meterFill.classList.add('is-weak');
            else if (score <= 3)     meterFill.classList.add('is-medium');
            else                     meterFill.classList.add('is-strong');
        });
    }

    /* -----------------------------------------------------------------
       Keep numeric-only inputs numeric as the user types.
       ----------------------------------------------------------------*/
    document.querySelectorAll('[data-numeric]').forEach(function (field) {
        field.addEventListener('input', function () {
            const max = parseInt(field.getAttribute('data-numeric'), 10) || 10;
            field.value = field.value.replace(/\D/g, '').slice(0, max);
        });
    });
});
