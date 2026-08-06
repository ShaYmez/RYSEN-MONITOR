/**
 * RYSEN-MONITOR dashboard WebSocket client.
 * Copyright (C) 2020-2026 Shane Daley, M0VUB <shane@freestar.network>
 */
var sock = null;
var ellog = null;
const conf_groups = [];
var bulletin_tbl = null;

let translationsCache = null;
let translationsPromise = null;
let languageListenerBound = false;
let delegatedTooltipsReady = false;
const TRANSLATIONS_STORAGE_KEY = 'rysen_translations';
const TOOLTIP_SELECTOR = '[data-bs-toggle="tooltip"], [data-toggle="tooltip"]';
const TOOLTIP_FADE_MS = 150;
let pinnedTooltipEl = null;
let pinnedTooltipDismissBound = false;
let tooltipFadeOutBound = false;

function readStoredTranslations() {
    try {
        const raw = sessionStorage.getItem(TRANSLATIONS_STORAGE_KEY);
        if (!raw) {
            return null;
        }
        return JSON.parse(raw);
    } catch (err) {
        return null;
    }
}

function persistTranslations(data) {
    try {
        sessionStorage.setItem(TRANSLATIONS_STORAGE_KEY, JSON.stringify(data));
    } catch (err) {
        // sessionStorage full or unavailable
    }
}

function formatDashboardLabel(text) {
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
    const label = formatDashboardLabel(translation);
    const isTooltip = element.getAttribute('data-bs-toggle') === 'tooltip'
        || element.getAttribute('data-toggle') === 'tooltip';
    if (isTooltip) {
        element.setAttribute('title', label);
    } else if (element.tagName === 'INPUT') {
        element.setAttribute('placeholder', label);
    } else {
        element.textContent = label;
    }
}

function translateElements(translations, selectedLanguage) {
    Object.keys(translations).forEach(key => {
        const element = document.getElementById(key);
        if (element && translations[key][selectedLanguage] != null) {
            applyTranslation(element, translations[key][selectedLanguage]);
        }
    });
}

function removeOrphanTooltipNodes() {
    document.querySelectorAll('.tooltip, .bs-tooltip-auto').forEach(function (el) {
        if (pinnedTooltipEl && el === pinnedTooltipEl) {
            return;
        }
        el.remove();
    });
}

function getVisibleTooltipElement() {
    return document.querySelector('.tooltip.show, .tooltip.in');
}

function pinVisibleTooltipBeforeDomSwap() {
    if (pinnedTooltipEl) {
        return;
    }

    const visible = getVisibleTooltipElement();
    if (!visible) {
        return;
    }

    pinnedTooltipEl = visible;
    pinnedTooltipEl.classList.add('dashboard-tooltip-pinned');
}

function fadeOutTooltipElement(tip, callback) {
    if (!tip || tip.classList.contains('dashboard-tooltip-fading')) {
        if (callback) {
            callback();
        }
        return;
    }

    tip.classList.add('dashboard-tooltip-fading');
    window.setTimeout(function () {
        tip.remove();
        if (callback) {
            callback();
        }
    }, TOOLTIP_FADE_MS);
}

function releasePinnedTooltip(immediate) {
    if (!pinnedTooltipEl) {
        return;
    }

    const el = pinnedTooltipEl;
    pinnedTooltipEl = null;

    if (immediate) {
        el.remove();
        return;
    }

    fadeOutTooltipElement(el);
}

function bindPinnedTooltipDismiss() {
    if (pinnedTooltipDismissBound) {
        return;
    }
    pinnedTooltipDismissBound = true;

    document.addEventListener('mouseenter', function (e) {
        const trigger = e.target && e.target.closest && e.target.closest(TOOLTIP_SELECTOR);
        if (trigger && pinnedTooltipEl) {
            releasePinnedTooltip(true);
        }
    }, true);

    document.addEventListener('mouseout', function (e) {
        if (!pinnedTooltipEl) {
            return;
        }

        const related = e.relatedTarget;

        if (pinnedTooltipEl.contains(e.target)) {
            if (!related || (!pinnedTooltipEl.contains(related) && !related.closest(TOOLTIP_SELECTOR))) {
                releasePinnedTooltip(false);
            }
            return;
        }

        const trigger = e.target && e.target.closest && e.target.closest(TOOLTIP_SELECTOR);
        if (!trigger) {
            return;
        }

        if (related && (trigger.contains(related) || related.closest('.tooltip') || related.closest(TOOLTIP_SELECTOR))) {
            return;
        }

        releasePinnedTooltip(false);
    });
}

function bindTooltipFadeOut() {
    if (tooltipFadeOutBound || typeof $ === 'undefined' || !$.fn || !$.fn.tooltip) {
        return;
    }
    tooltipFadeOutBound = true;

    $('body').on('hide.bs.tooltip', TOOLTIP_SELECTOR, function (e) {
        if (pinnedTooltipEl) {
            return;
        }

        const tip = getVisibleTooltipElement();
        if (!tip) {
            return;
        }

        e.preventDefault();

        const trigger = this;
        fadeOutTooltipElement(tip, function () {
            try {
                $(trigger).tooltip('dispose');
            } catch (err) {
                // Ignore stale tooltip instances after DOM replacement.
            }
        });
    });
}

function cleanupTooltips() {
    releasePinnedTooltip(true);

    if (typeof $ !== 'undefined' && $.fn && $.fn.tooltip) {
        try {
            $(TOOLTIP_SELECTOR).tooltip('hide');
        } catch (err) {
            // Ignore stale tooltip instances after DOM replacement.
        }
    }

    removeOrphanTooltipNodes();
}

function prepareDomSwapCleanup() {
    pinVisibleTooltipBeforeDomSwap();
    removeOrphanTooltipNodes();
}

function applySectionUpdate(el, html) {
    prepareDomSwapCleanup();
    el.innerHTML = html;
    loadTranslations().then(translations => {
        const languageSelect = document.getElementById('languageSelect');
        const lang = languageSelect ? languageSelect.value : 'en';
        translateElements(translations, lang);
    });
}

function applyDashboardPaneUpdate(container, message) {
    const prevHeight = container.offsetHeight;

    prepareDomSwapCleanup();
    container.classList.add('dashboard-updating');
    if (prevHeight > 0) {
        container.style.minHeight = prevHeight + 'px';
    }

    container.innerHTML = message;

    loadTranslations().then(translations => {
        const languageSelect = document.getElementById('languageSelect');
        const lang = languageSelect ? languageSelect.value : 'en';
        translateElements(translations, lang);
        bindLanguageListener();
    });

    requestAnimationFrame(function () {
        container.classList.remove('dashboard-updating');
        container.style.minHeight = '';
    });
}

function initDelegatedTooltips() {
    if (delegatedTooltipsReady || typeof $ === 'undefined' || !$.fn || !$.fn.tooltip) {
        return;
    }
    delegatedTooltipsReady = true;
    bindPinnedTooltipDismiss();
    $('body').tooltip({
        selector: TOOLTIP_SELECTOR,
        trigger: 'hover',
        html: true,
        container: 'body',
        boundary: 'window',
        sanitize: false
    });
    bindTooltipFadeOut();
}

function getPageLanguage() {
    const languageSelect = document.getElementById('languageSelect');
    if (languageSelect && languageSelect.value) {
        return languageSelect.value;
    }
    const bodyLang = document.body && document.body.getAttribute('data-current-lang');
    return bodyLang || 'en';
}

function applyPageTranslations(lang) {
    if (!translationsCache) {
        return;
    }
    translateElements(translationsCache, lang || getPageLanguage());
}
window.applyPageTranslations = applyPageTranslations;

function loadTranslations() {
    if (translationsCache) {
        return Promise.resolve(translationsCache);
    }

    const cached = readStoredTranslations();
    if (cached) {
        translationsCache = cached;
    }

    if (!translationsPromise) {
        translationsPromise = fetch('translations.json', { cache: 'no-store' })
            .then(response => {
                if (!response.ok) {
                    throw new Error('translations.json HTTP ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                translationsCache = data;
                persistTranslations(data);
                return data;
            })
            .catch(err => {
                if (translationsCache) {
                    return translationsCache;
                }
                translationsPromise = null;
                throw err;
            });
    }

    if (translationsCache) {
        return Promise.resolve(translationsCache);
    }

    return translationsPromise;
}

function bindLanguageListener() {
    const languageSelect = document.getElementById('languageSelect');
    if (!languageSelect || languageListenerBound) {
        return;
    }
    languageListenerBound = true;
    languageSelect.addEventListener('change', function () {
        if (!translationsCache) {
            return;
        }
        applyPageTranslations(languageSelect.value);
        cleanupTooltips();
    });
}

function initDashboardStatLinks() {
    const anchorOffset = 80;

    document.addEventListener('click', function (e) {
        const link = e.target.closest('#main-stats a.dashboard-stat-link');
        if (!link) {
            return;
        }
        const hash = link.getAttribute('href');
        if (!hash || hash.charAt(0) !== '#') {
            return;
        }
        const target = document.getElementById(hash.slice(1));
        if (!target) {
            return;
        }
        e.preventDefault();
        const top = target.getBoundingClientRect().top + window.scrollY - anchorOffset;
        window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
        document.querySelectorAll('.dashboard-scroll-flash').forEach(function (el) {
            el.classList.remove('dashboard-scroll-flash');
        });
        target.classList.add('dashboard-scroll-flash');
        window.setTimeout(function () {
            target.classList.remove('dashboard-scroll-flash');
        }, 1400);
    });
}

function markDashboardPanes() {
    const paneIds = ['main', 'lnksys', 'statictg', 'bridge', 'opb', 'tgcount', 'lsthrd_log', 'bulletin'];
    paneIds.forEach(function (id) {
        const pane = document.getElementById(id);
        if (pane) {
            pane.classList.add('dashboard-pane');
        }
    });
}

function updateSection(sectionId, html) {
    const el = document.getElementById(sectionId);
    if (!el || el.innerHTML === html) {
        return;
    }

    applySectionUpdate(el, html);
}

function updateTbody(tbodyId, html) {
    const el = document.getElementById(tbodyId);
    if (!el || el.innerHTML === html) {
        return;
    }
    el.innerHTML = html;
}

function handleMainSection(opcode, message) {
    if (opcode === '2') {
        updateSection('main-stats', message);
    } else if (opcode === '3') {
        updateSection('main-activity', message);
    } else if (opcode === '4') {
        updateTbody('main-lastheard-rows', message);
    } else if (opcode === '5') {
        updateSection('main-connected', message);
    }
}

function updateDashboardPane(container, message) {
    if (!container) {
        return;
    }
    if (container.innerHTML === message) {
        return;
    }

    applyDashboardPaneUpdate(container, message);
}

function initPageTranslations() {
    loadTranslations().then(function () {
        applyPageTranslations(getPageLanguage());
        bindLanguageListener();
        initDelegatedTooltips();
    }).catch(function (err) {
        console.error('Failed to load translations:', err);
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPageTranslations);
} else {
    initPageTranslations();
}

window.addEventListener('load', function () {
    var wsuri;
    conf_id();
    markDashboardPanes();
    initDashboardStatLinks();

    ellog = document.getElementById('log');

    bridge_tbl = document.getElementById('bridge');
    main_tbl = document.getElementById('main');
    lnksys_tbl = document.getElementById('lnksys');
    opb_tbl = document.getElementById('opb');
    statictg_tbl = document.getElementById('statictg');
    tgcount_tbl = document.getElementById('tgcount');
    lsthrd_log_tbl = document.getElementById('lsthrd_log');
    bulletin_tbl = document.getElementById('bulletin');

    // HBMonv2 pattern: Direct WebSocket connection to port 9000
    // Production installer will modify this to use /wss/ if Mode 2 selected
    wsuri = (((window.location.protocol === "https:") ? "wss://" : "ws://") + window.location.hostname + ":9000");

    if ("WebSocket" in window) {
        sock = new WebSocket(wsuri);
    } else if ("MozWebSocket" in window) {
        sock = new MozWebSocket(wsuri);
    } else {
        if (ellog != null) {
            log("Browser does not support WebSocket!");
        }
    }

    if (sock) {
        sock.onopen = function () {
            if (conf_groups.length > 0) {
                sock.send("conf," + conf_groups);
            }
            if (ellog != null) {
                log("Connected to " + wsuri);
            }
        }

        sock.onclose = function (e) {
            if (ellog != null) {
                log("Connection closed (wasClean = " + e.wasClean + ", code = " + e.code + ", reason = '" + e.reason + "')");
            }
            sock = null;
            cleanupTooltips();
            for (i = 0; i < conf_groups.length; i++) {
                var group = conf_groups[i];
                if (group == 'bridge') {
                    bridge_tbl.innerHTML = "";
                } else if (group == 'main') {
                    main_tbl.innerHTML = "";
                } else if (group == 'lnksys') {
                    lnksys_tbl.innerHTML = "";
                } else if (group == 'opb') {
                    opb_tbl.innerHTML = "";
                } else if (group == 'statictg') {
                    statictg_tbl.innerHTML = "";
                } else if (group == 'tgcount') {
                    tgcount_tbl.innerHTML = "";
                } else if (group == 'lsthrd_log') {
                    lsthrd_log_tbl.innerHTML = "";
                } else if (group == 'bulletin') {
                    bulletin_tbl.innerHTML = "";
                }
            }
        }

        sock.onmessage = function (e) {
            var raw = e.data;
            if (raw instanceof ArrayBuffer) {
                raw = new TextDecoder('utf-8').decode(raw);
            } else if (typeof raw !== 'string') {
                raw = String(raw);
            }
            var opcode = raw.slice(0, 1);
            var message = raw.slice(1);
            if (opcode == "b") {
                Bmsg(message);
            } else if (opcode == "t") {
                Tmsg(message)
            } else if (opcode == "c") {
                Cmsg(message);
            } else if (opcode == "i") {
                Imsg(message);
            } else if (opcode == "2" || opcode == "3" || opcode == "4" || opcode == "5") {
                handleMainSection(opcode, message);
            } else if (opcode == "o") {
                Omsg(message);
            } else if (opcode == "s") {
                Smsg(message);
            } else if (opcode == 'h') {
                Hmsg(message);
            } else if (opcode == 'u') {
                Umsg(message);
            } else if (opcode == "v") {
                document.querySelectorAll('.rysen-version').forEach(function (el) {
                    el.textContent = message;
                });
            } else if (opcode == "l") {
                if (ellog != null) {
                    log(message);
                }
            } else if (opcode == "q") {
                if (ellog != null) {
                    log(message);
                }
                if (message.indexOf('Lost') === -1) {
                    return;
                }
                cleanupTooltips();
                for (i = 0; i < conf_groups.length; i++) {
                    var group = conf_groups[i];
                    if (group == "bridge") {
                        bridge_tbl.innerHTML = "";
                    } else if (group == "main") {
                        main_tbl.innerHTML = "";
                    } else if (group == "lnksys") {
                        lnksys_tbl.innerHTML = "";
                    } else if (group == "opb") {
                        opb_tbl.innerHTML = "";
                    } else if (group == 'statictg') {
                        statictg_tbl.innerHTML = "";
                    } else if (group == "tgcount") {
                        tgcount_tbl.innerHTML = "";
                    } else if (group == 'lsthrd_log') {
                        lsthrd_log_tbl.innerHTML = "";
                    } else if (group == 'bulletin') {
                        bulletin_tbl.innerHTML = "";
                    }
                }
            } else {
                if (ellog != null) {
                    log("Unknown Message Received: " + message);
                }
            }
        }
    }
});


function Bmsg(_msg) {
    updateDashboardPane(bridge_tbl, _msg);
}

function Cmsg(_msg) {
    updateDashboardPane(lnksys_tbl, _msg);
}

function Imsg(_msg) {
    if (!main_tbl) {
        return;
    }
    // Full shell paint only when home pane is empty (connect or after backend loss).
    if (main_tbl.querySelector('#main-stats')) {
        return;
    }
    updateDashboardPane(main_tbl, _msg);
}

function Omsg(_msg) {
    updateDashboardPane(opb_tbl, _msg);
}

function Smsg(_msg) {
    updateDashboardPane(statictg_tbl, _msg);
}

function Hmsg(_msg) {
    updateDashboardPane(lsthrd_log_tbl, _msg);
}

function Umsg(_msg) {
    updateDashboardPane(bulletin_tbl, _msg);
}

function Tmsg(_msg) {
    updateDashboardPane(tgcount_tbl, _msg);
}


function log(_msg) {
    ellog.innerHTML += _msg + '\n';
    ellog.scrollTop = ellog.scrollHeight;
};

// Find tables that are present
function conf_id() {
    const groups = ["main", "bridge", "lnksys", "opb", "statictg", "log", "lsthrd_log", "tgcount", "bulletin"];
    groups.forEach(function (id) {
        if (document.getElementById(id)) {
            conf_groups.push(id);
        }
    });
};
