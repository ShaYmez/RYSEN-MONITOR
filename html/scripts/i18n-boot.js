/**
 * Apply cached translations as soon as the navbar exists (before page paint completes).
 * monitor.js refreshes the cache and re-applies for the full page.
 *
 * Copyright (C) 2020-2026 Shane Daley, M0VUB <shane@freestar.network>
 */
(function () {
    var STORAGE_KEY = 'rysen_translations';

    function formatLabel(text) {
        if (typeof text !== 'string') {
            return text;
        }
        return text.trim()
            .replace(/^\.\:\s*/, '')
            .replace(/\s*:\.\s*$/, '')
            .trim();
    }

    function applyTranslation(element, translation) {
        if (!element || translation == null) {
            return;
        }
        var label = formatLabel(translation);
        var isTooltip = element.getAttribute('data-bs-toggle') === 'tooltip'
            || element.getAttribute('data-toggle') === 'tooltip';
        if (isTooltip) {
            element.setAttribute('title', label);
        } else if (element.tagName === 'INPUT') {
            element.setAttribute('placeholder', label);
        } else {
            element.textContent = label;
        }
    }

    function getLang() {
        try {
            var stored = localStorage.getItem('language');
            if (stored) {
                return stored;
            }
        } catch (e) {
            // ignore
        }
        if (document.body) {
            return document.body.getAttribute('data-current-lang') || 'en';
        }
        return 'en';
    }

    function applyCachedTranslations() {
        var raw;
        try {
            raw = sessionStorage.getItem(STORAGE_KEY);
        } catch (e) {
            return;
        }
        if (!raw) {
            return;
        }
        var translations;
        try {
            translations = JSON.parse(raw);
        } catch (e) {
            return;
        }
        var lang = getLang();
        Object.keys(translations).forEach(function (key) {
            var el = document.getElementById(key);
            if (el && translations[key][lang] != null) {
                applyTranslation(el, translations[key][lang]);
            }
        });
    }

    applyCachedTranslations();
})();
