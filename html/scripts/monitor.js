var sock = null;
var ellog = null;
const conf_groups = [];
var bulletin_tbl = null;

let translationsCache = null;
let translationsPromise = null;
let languageListenerBound = false;
let delegatedTooltipsReady = false;

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
        if (element.getAttribute('data-bs-toggle') === 'tooltip') {
            element.setAttribute('data-bs-title', label);
        }
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

function cleanupTooltips() {
    if (typeof $ === 'undefined' || !$.fn.tooltip) {
        return;
    }
    try {
        $('[data-toggle="tooltip"], [data-bs-toggle="tooltip"]').tooltip('hide');
    } catch (err) {
        // Ignore stale tooltip instances after DOM replacement.
    }
    $('.tooltip').remove();
}

function initDelegatedTooltips() {
    if (delegatedTooltipsReady || typeof $ === 'undefined' || !$.fn.tooltip) {
        return;
    }
    delegatedTooltipsReady = true;
    $('body').tooltip({
        selector: '[data-toggle="tooltip"]',
        trigger: 'hover',
        html: true,
        container: 'body',
        boundary: 'window'
    });
}

function loadTranslations() {
    if (translationsCache) {
        return Promise.resolve(translationsCache);
    }
    if (!translationsPromise) {
        translationsPromise = fetch('translations.json')
            .then(response => response.json())
            .then(data => {
                translationsCache = data;
                return data;
            });
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
        translateElements(translationsCache, languageSelect.value);
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
    el.innerHTML = html;
    loadTranslations().then(translations => {
        const languageSelect = document.getElementById('languageSelect');
        const lang = languageSelect ? languageSelect.value : 'en';
        translateElements(translations, lang);
    });
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
    loadTranslations().then(translations => {
        const languageSelect = document.getElementById('languageSelect');
        const lang = languageSelect ? languageSelect.value : 'en';
        const prevHeight = container.offsetHeight;

        cleanupTooltips();
        container.classList.add('dashboard-updating');
        if (prevHeight > 0) {
            container.style.minHeight = prevHeight + 'px';
        }

        container.innerHTML = message;
        translateElements(translations, lang);
        bindLanguageListener();

        requestAnimationFrame(function () {
            container.classList.remove('dashboard-updating');
            container.style.minHeight = '';
        });
    });
}

window.onload = function () {
    var wsuri;
    conf_id();
    markDashboardPanes();
    initDashboardStatLinks();
    initDelegatedTooltips();

    ellog = document.getElementById('log');

    bridge_tbl = document.getElementById('bridge');
    main_tbl = document.getElementById('main');
    lnksys_tbl = document.getElementById('lnksys');
    opb_tbl = document.getElementById('opb');
    statictg_tbl = document.getElementById('statictg');
    tgcount_tbl = document.getElementById('tgcount');
    lsthrd_log_tbl = document.getElementById('lsthrd_log');
    bulletin_tbl = document.getElementById('bulletin');

    loadTranslations().then(translations => {
        const languageSelect = document.getElementById('languageSelect');
        if (languageSelect) {
            translateElements(translations, languageSelect.value);
            bindLanguageListener();
        }
    });

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
            var opcode = e.data.slice(0, 1);
            var message = e.data.slice(1);
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
            } else if (opcode == "l") {
                if (ellog != null) {
                    log(message);
                }
            } else if (opcode == "q") {
                log(message);
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
                log("Unknown Message Received: " + message);
            }
        }
    }
};


function Bmsg(_msg) {
    updateDashboardPane(bridge_tbl, _msg);
}

function Cmsg(_msg) {
    updateDashboardPane(lnksys_tbl, _msg);
}

function Imsg(_msg) {
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
    const tags = [document.getElementsByTagName("p"), document.getElementsByTagName("pre")]
    for (i = 0; i < tags.length; i++) {
        for (j = 0; j < tags[i].length; j++)
            if (groups.includes(tags[i][j].id)) {
                conf_groups.push(tags[i][j].id);
            }
    }
    console.log(conf_groups)
};
