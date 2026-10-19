/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 *
 * Content > Staging: a confirmation before Start now / End now / Delete /
 * Remove, a field's tick set when its value is edited, the new campaign's
 * fields only when "A new campaign…" is chosen, the store view switch.
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    ready(function () {
        document.querySelectorAll('form[data-zst-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!window.confirm(form.getAttribute('data-zst-confirm'))) {
                    event.preventDefault();
                }
            });
        });

        document.querySelectorAll('[data-zst-field]').forEach(function (row) {
            var tick = row.querySelector('[data-zst-tick]');
            row.querySelectorAll('input:not([data-zst-tick]), textarea, select').forEach(function (input) {
                input.addEventListener('input', function () {
                    tick.checked = true;
                });
            });
        });

        var campaign = document.querySelector('[data-zst-campaign]');
        var fresh = document.querySelector('[data-zst-new]');
        if (campaign && fresh) {
            var toggle = function () {
                var isNew = campaign.value === '0';
                fresh.hidden = !isNew;
                fresh.querySelectorAll('input').forEach(function (input) {
                    if (input.name === 'new[name]' || input.name === 'new[start]') {
                        input.required = isNew;
                    }
                });
            };
            campaign.addEventListener('change', toggle);
            toggle();
        }

        document.querySelectorAll('form[data-zst-autosubmit] select').forEach(function (select) {
            select.addEventListener('change', function () {
                select.form.submit();
            });
        });
    });
}());
