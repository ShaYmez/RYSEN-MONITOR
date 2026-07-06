/**
 * Selfcare login page helpers — password reveal toggle.
 */
(function () {
    var TRANSLATIONS_STORAGE_KEY = 'rysen_translations';
    var SHOW_KEY = 'sslog_pass_show';
    var HIDE_KEY = 'sslog_pass_hide';

    function getPageLanguage() {
        return localStorage.getItem('language') || 'en';
    }

    function getTranslation(key) {
        try {
            var raw = sessionStorage.getItem(TRANSLATIONS_STORAGE_KEY);
            if (!raw) {
                return null;
            }
            var translations = JSON.parse(raw);
            var lang = getPageLanguage();
            if (translations[key] && translations[key][lang] != null) {
                return translations[key][lang];
            }
        } catch (err) {
            return null;
        }
        return null;
    }

    function getToggleLabels() {
        return {
            show: getTranslation(SHOW_KEY) || 'Show password',
            hide: getTranslation(HIDE_KEY) || 'Hide password'
        };
    }

    function findPasswordInput(toggleButton) {
        var group = toggleButton.closest('.input-group');
        if (!group) {
            return null;
        }
        return group.querySelector('input[type="password"], input[type="text"].ss-password-visible');
    }

    function updateToggleTooltip(button, visible) {
        var labels = getToggleLabels();
        var title = visible ? labels.hide : labels.show;
        button.setAttribute('title', title);
        button.setAttribute('aria-label', title);
    }

    function setPasswordVisible(button, visible) {
        var input = findPasswordInput(button);
        if (!input) {
            return;
        }

        input.type = visible ? 'text' : 'password';
        input.classList.toggle('ss-password-visible', visible);
        button.setAttribute('aria-pressed', visible ? 'true' : 'false');
        button.classList.toggle('ss-password-toggle--visible', visible);
        updateToggleTooltip(button, visible);
    }

    function refreshToggleLabels() {
        document.querySelectorAll('.ss-password-toggle').forEach(function (button) {
            var visible = button.getAttribute('aria-pressed') === 'true';
            updateToggleTooltip(button, visible);
        });
    }

    function bindPasswordToggle(button) {
        var labels = getToggleLabels();
        button.setAttribute('title', labels.show);
        button.setAttribute('aria-label', labels.show);

        button.addEventListener('click', function () {
            var isVisible = button.getAttribute('aria-pressed') === 'true';
            setPasswordVisible(button, !isVisible);
        });
    }

    function initPasswordToggles() {
        document.querySelectorAll('.ss-password-toggle:not([data-ss-password-bound])').forEach(function (button) {
            button.setAttribute('data-ss-password-bound', '1');
            bindPasswordToggle(button);
        });

        refreshToggleLabels();

        if (typeof loadTranslations === 'function') {
            loadTranslations().then(refreshToggleLabels);
        }

        var languageSelect = document.getElementById('languageSelect');
        if (languageSelect && !languageSelect.hasAttribute('data-ss-password-lang-bound')) {
            languageSelect.setAttribute('data-ss-password-lang-bound', '1');
            languageSelect.addEventListener('change', refreshToggleLabels);
        }
    }

    document.addEventListener('DOMContentLoaded', initPasswordToggles);
})();
