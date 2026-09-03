/* =====================================================================
   LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
   BCSP-064 -- Bachelor of Computer Applications, IGNOU
   ---------------------------------------------------------------------
   FILE    : assets/js/main.js
   PURPOSE : Interface behaviour -- navigation, notification panel,
             dismissible alerts, confirmation dialogs, the live booking
             slot loader and table search.

   Written in plain ES6 with no library, in keeping with the approved
   tools list. Every behaviour here is an ENHANCEMENT: with JavaScript
   disabled, every form still submits and every page still works,
   because the server re-checks everything anyway.
   ===================================================================== */
'use strict';

document.addEventListener('DOMContentLoaded', function () {

    /* -----------------------------------------------------------------
       1. MOBILE SIDEBAR
       Adds a backdrop so a tap outside closes the menu, and returns
       focus sensibly for keyboard users.
       ----------------------------------------------------------------*/
    const sidebar = document.getElementById('sidebar');
    const toggle  = document.getElementById('sidebarToggle');
    let backdrop  = null;

    function closeSidebar() {
        if (!sidebar) return;
        sidebar.classList.remove('is-open');
        if (toggle) toggle.setAttribute('aria-expanded', 'false');
        if (backdrop) { backdrop.remove(); backdrop = null; }
    }

    if (toggle && sidebar) {
        toggle.addEventListener('click', function () {
            const open = sidebar.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', String(open));

            if (open) {
                backdrop = document.createElement('div');
                backdrop.className = 'sidebar-backdrop';
                backdrop.addEventListener('click', closeSidebar);
                document.body.appendChild(backdrop);
            } else {
                closeSidebar();
            }
        });
    }

    /* -----------------------------------------------------------------
       2. NOTIFICATION PANEL
       ----------------------------------------------------------------*/
    const bellButton = document.getElementById('bellButton');
    const bellPanel  = document.getElementById('bellPanel');

    if (bellButton && bellPanel) {
        bellButton.addEventListener('click', function (event) {
            event.stopPropagation();
            const willOpen = bellPanel.hidden;
            bellPanel.hidden = !willOpen;
            bellButton.setAttribute('aria-expanded', String(willOpen));

            // Mark everything read the moment the panel is opened.
            if (willOpen) {
                fetch(window.LSBMS_BASE + 'ajax/notifications.php?action=mark_read', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'csrf_token=' + encodeURIComponent(window.LSBMS_CSRF || ''),
                })
                    .then(function () {
                        const dot = bellButton.querySelector('.bell__dot');
                        if (dot) dot.remove();
                    })
                    .catch(function () { /* non-critical: ignore */ });
            }
        });

        document.addEventListener('click', function (event) {
            if (!bellPanel.hidden && !bellPanel.contains(event.target)) {
                bellPanel.hidden = true;
                bellButton.setAttribute('aria-expanded', 'false');
            }
        });
    }

    /* Escape closes whatever is open. */
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        if (bellPanel && !bellPanel.hidden) {
            bellPanel.hidden = true;
            if (bellButton) bellButton.setAttribute('aria-expanded', 'false');
        }
        closeSidebar();
        document.querySelectorAll('.modal').forEach(function (m) { m.remove(); });
    });

    /* -----------------------------------------------------------------
       3. DISMISSIBLE ALERTS
       ----------------------------------------------------------------*/
    document.addEventListener('click', function (event) {
        const close = event.target.closest('.alert__close');
        if (close) close.closest('.alert').remove();
    });

    /* -----------------------------------------------------------------
       4. CONFIRMATION BEFORE DESTRUCTIVE ACTIONS
       Any control carrying data-confirm asks first. The server still
       re-validates, so this is a courtesy rather than a safeguard.
       ----------------------------------------------------------------*/
    document.addEventListener('click', function (event) {
        const el = event.target.closest('[data-confirm]');
        if (el && !window.confirm(el.getAttribute('data-confirm'))) {
            event.preventDefault();
            event.stopPropagation();
        }
    });

    /* -----------------------------------------------------------------
       5. LIVE SLOT LOADER  (booking screen)
       When the customer changes the date, ask the server which start
       times are still free for that professional. The same check runs
       again on submit -- this only saves the user a wasted round trip.
       ----------------------------------------------------------------*/
    const dateInput   = document.getElementById('bookingDate');
    const slotSelect  = document.getElementById('bookingTime');
    const slotNote    = document.getElementById('slotNote');
    const providerBox = document.getElementById('providerId');
    const durationBox = document.getElementById('durationMinutes');

    /**
     * Replace a <select>'s contents with a single message option.
     * Uses textContent so the select can never become an XSS sink.
     */
    function setSelectMessage(select, text, value) {
        while (select.firstChild) { select.removeChild(select.firstChild); }
        const option = document.createElement('option');
        option.value = value || '';
        option.textContent = text;
        select.appendChild(option);
    }

    function loadSlots() {
        if (!dateInput || !slotSelect || !providerBox) return;
        if (!dateInput.value) return;

        slotSelect.disabled = true;
        setSelectMessage(slotSelect, 'Checking availability...');
        if (slotNote) slotNote.textContent = '';

        const params = new URLSearchParams({
            provider_id: providerBox.value,
            date:        dateInput.value,
            duration:    durationBox ? durationBox.value : 60
        });

        fetch(window.LSBMS_BASE + 'ajax/check-slot.php?' + params.toString())
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok || !data.slots || data.slots.length === 0) {
                    setSelectMessage(slotSelect, 'No times available');
                    if (slotNote) {
                        slotNote.textContent = data.message || 'This professional is not available on that day.';
                        slotNote.className = 'error';
                    }
                    return;
                }

                const free = data.slots.filter(function (s) { return s.free; });

                if (free.length === 0) {
                    setSelectMessage(slotSelect, 'Fully booked');
                    if (slotNote) {
                        slotNote.textContent = 'Every slot that day is taken. Try another date.';
                        slotNote.className = 'error';
                    }
                    return;
                }

                setSelectMessage(slotSelect, 'Select a time');

                data.slots.forEach(function (slot) {
                    const option = document.createElement('option');
                    option.value = slot.value;
                    option.textContent = slot.free ? slot.label : slot.label + ' -- booked';
                    option.disabled = !slot.free;
                    slotSelect.appendChild(option);
                });

                slotSelect.disabled = false;
                if (slotNote) {
                    slotNote.textContent = free.length + ' slot' + (free.length === 1 ? '' : 's') + ' free on this date.';
                    slotNote.className = 'hint';
                }
            })
            .catch(function () {
                setSelectMessage(slotSelect, 'Could not load times');
                if (slotNote) {
                    slotNote.textContent = 'Could not reach the server. Check that MySQL and Apache are running, then reload.';
                    slotNote.className = 'error';
                }
            });
    }

    if (dateInput) {
        dateInput.addEventListener('change', loadSlots);
        if (dateInput.value) loadSlots();
    }
    if (durationBox) durationBox.addEventListener('change', loadSlots);

    /* -----------------------------------------------------------------
       6. INSTANT TABLE FILTER
       Any input carrying data-filter-table="<table id>" filters that
       table's rows as the user types.
       ----------------------------------------------------------------*/
    document.querySelectorAll('[data-filter-table]').forEach(function (input) {
        input.addEventListener('input', function () {
            const table = document.getElementById(input.getAttribute('data-filter-table'));
            if (!table) return;

            const needle = input.value.trim().toLowerCase();
            let shown = 0;

            table.querySelectorAll('tbody tr').forEach(function (row) {
                if (row.classList.contains('js-no-results')) return;
                const match = row.textContent.toLowerCase().indexOf(needle) !== -1;
                row.hidden = !match;
                if (match) shown++;
            });

            // Show a friendly row rather than an empty table body.
            let emptyRow = table.querySelector('.js-no-results');
            if (shown === 0) {
                if (!emptyRow) {
                    // Built with DOM methods rather than innerHTML. Nothing
                    // here is user-supplied, but keeping the codebase free
                    // of innerHTML entirely means no future edit can turn
                    // this into an XSS sink by accident.
                    const columns = table.querySelectorAll('thead th').length || 1;
                    emptyRow = document.createElement('tr');
                    emptyRow.className = 'js-no-results';

                    const cell = document.createElement('td');
                    cell.colSpan = columns;
                    cell.className = 'text-center text-muted';
                    cell.style.padding = '28px';
                    cell.textContent = 'Nothing matches that search.';

                    emptyRow.appendChild(cell);
                    table.querySelector('tbody').appendChild(emptyRow);
                }
                emptyRow.hidden = false;
            } else if (emptyRow) {
                emptyRow.hidden = true;
            }
        });
    });

    /* -----------------------------------------------------------------
       7. STAR RATING PICKER  (feedback form)
       ----------------------------------------------------------------*/
    const starPicker = document.getElementById('starPicker');
    if (starPicker) {
        const hidden  = document.getElementById('ratingValue');
        const buttons = starPicker.querySelectorAll('button');

        function paint(upto) {
            buttons.forEach(function (b, i) {
                b.textContent = i < upto ? '★' : '☆';
                b.classList.toggle('is-lit', i < upto);
            });
        }

        buttons.forEach(function (button, index) {
            button.addEventListener('click', function () {
                if (hidden) hidden.value = index + 1;
                paint(index + 1);
                starPicker.setAttribute('data-value', String(index + 1));
            });
            button.addEventListener('mouseenter', function () { paint(index + 1); });
        });

        starPicker.addEventListener('mouseleave', function () {
            paint(parseInt(starPicker.getAttribute('data-value') || '0', 10));
        });
    }

    /* -----------------------------------------------------------------
       8. DEMO CREDENTIAL FILLER  (login screen convenience)
       ----------------------------------------------------------------*/
    document.querySelectorAll('.demo-fill').forEach(function (button) {
        button.addEventListener('click', function () {
            const email = document.getElementById('email');
            const pass  = document.getElementById('password');
            if (email) email.value = button.getAttribute('data-email');
            if (pass)  pass.value  = button.getAttribute('data-password');
            if (email) email.focus();
        });
    });

    /* -----------------------------------------------------------------
       9. AUTO-DISMISS SUCCESS MESSAGES
       Errors stay put -- the user needs to read and act on those.
       ----------------------------------------------------------------*/
    setTimeout(function () {
        document.querySelectorAll('.alert--success').forEach(function (alert) {
            alert.style.transition = 'opacity .4s ease';
            alert.style.opacity = '0';
            setTimeout(function () { alert.remove(); }, 400);
        });
    }, 6000);
});
