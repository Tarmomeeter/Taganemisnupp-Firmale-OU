(function () {
    'use strict';

    var table = document.querySelector('.firmale-settings-form .form-table');
    var tabs = document.querySelector('[data-firmale-tabs]');
    var search = document.querySelector('[data-firmale-settings-search]');

    if (!table || !tabs) {
        return;
    }

    var rows = Array.prototype.slice.call(table.querySelectorAll('tbody > tr'));
    var currentSection = '';

    rows.forEach(function (row) {
        if (row.classList.contains('firmale-settings-section')) {
            currentSection = row.getAttribute('data-section') || '';
        }
        row.setAttribute('data-firmale-section', currentSection);
    });

    function activate(section) {
        tabs.querySelectorAll('button[data-section]').forEach(function (button) {
            var active = button.getAttribute('data-section') === section;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });

        rows.forEach(function (row) {
            row.hidden = row.getAttribute('data-firmale-section') !== section;
        });

        if (window.history && window.history.replaceState) {
            window.history.replaceState(null, '', '#' + section);
        }
    }

    tabs.addEventListener('click', function (event) {
        var button = event.target.closest('button[data-section]');
        if (!button) {
            return;
        }
        if (search) {
            search.value = '';
        }
        activate(button.getAttribute('data-section'));
    });

    if (search) {
        search.addEventListener('input', function () {
            var query = search.value.trim().toLocaleLowerCase();
            if (!query) {
                var active = tabs.querySelector('button.is-active');
                activate(active ? active.getAttribute('data-section') : 'general');
                return;
            }

            rows.forEach(function (row) {
                var isHeading = row.classList.contains('firmale-settings-section');
                row.hidden = isHeading || row.textContent.toLocaleLowerCase().indexOf(query) === -1;
            });
        });
    }

    var requestedSection = window.location.hash.replace('#', '');
    var requestedTab = tabs.querySelector('button[data-section="' + requestedSection + '"]');
    activate(requestedTab ? requestedSection : 'general');
}());
