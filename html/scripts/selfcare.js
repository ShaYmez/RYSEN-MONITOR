/**
 * Selfcare Device Configuration Manager
 * Manages device settings, timeslots, and configuration options
 */

/** Proxy send_opts loop interval (hotspot_proxy_v2_sc.py). */
const PROXY_OPTS_INTERVAL_MS = 10000;
/**
 * Hold after proxy send — covers RYSEN options_config (~26s) and simplex busy-channel delay.
 * User-tested minimum for reliable disconnect without radio PTT.
 */
const DISCONNECT_HOLD_MS = 60000;

class SelfcareManager {
    constructor(config) {
        this.deviceMode = config.deviceMode;
        this.isIpsc = config.isIpsc;
        this.deviceId = config.deviceId;
        this.isModified = config.isModified;
        this.checkInterval = null;
        this.disconnectInProgress = false;
        this.init();
    }

    /**
     * Initialize the manager
     */
    init() {
        this.attachEventListeners();
        this.toggleTimeslotTable();
        const dialTGInput = document.getElementById('dialTGInput');
        if (dialTGInput) {
            this.toggleTimeslot2(dialTGInput.value);
        }
        this.toggleLanguageDropdown();
        this.updateGeneratedText();
        
        if (this.isModified) {
            this.startStatusPolling();
        }

        this.setActionButtonsDisabled(this.isModified);
    }

    /**
     * Attach event listeners to form elements
     */
    attachEventListeners() {
        const inputs = document.querySelectorAll('input, select');
        inputs.forEach(input => {
            if (input.type === 'number' && input.closest('#timeslotTable, #timeslotTable2')) {
                this.bindTalkgroupInput(input);
            } else {
                input.addEventListener('input', () => this.updateGeneratedText());
            }
        });

        const voiceSelect = document.getElementById('voiceSelect');
        if (voiceSelect) {
            voiceSelect.addEventListener('change', () => this.toggleLanguageDropdown());
        }
    }

    /**
     * Toggle timeslot 1 table visibility based on device mode
     */
    toggleTimeslotTable() {
        const timeSlot1Col = document.getElementById('timeslot1col');
        if (timeSlot1Col) {
            // Simplex hotspot: hide TS1. IPSC and duplex: show TS1.
            timeSlot1Col.style.display = (this.deviceMode === 4 && !this.isIpsc) ? 'none' : 'block';
        }
        this.updateGeneratedText();
    }

    /**
     * Toggle timeslot 2 visibility
     */
    toggleTimeslot2(value) {
        const timeslot2Col = document.getElementById('timeslot2col');
        if (timeslot2Col) {
            timeslot2Col.style.display = value > 0 ? 'none' : 'block';
        }
        this.updateGeneratedText();
    }

    /**
     * Toggle language dropdown based on voice setting
     */
    toggleLanguageDropdown() {
        const voiceSelect = document.getElementById('voiceSelect');
        const languageRow = document.getElementById('languagerow');
        
        if (voiceSelect && languageRow) {
            languageRow.style.display = voiceSelect.value === '1' ? 'table-row' : 'none';
        }
        this.updateGeneratedText();
    }

    /**
     * Add a new talkgroup row to a timeslot table
     */
    addRow(tableId) {
        const table = document.getElementById(tableId);
        if (!table) return;

        const rowCount = table.rows.length;
        const row = table.insertRow(rowCount);
        
        const tgCell = row.insertCell(0);
        const timeslotCell = row.insertCell(1);
        const removeCell = row.insertCell(2);
        
        tgCell.innerHTML = `TG ${rowCount + 1}:`;
        tgCell.classList.add('align-middle', 'text-nowrap');
        
        // Create input element
        const input = document.createElement('input');
        input.type = 'number';
        input.className = 'form-control form-control-sm';
        input.min = '0';
        input.step = '1';
        this.bindTalkgroupInput(input);
        timeslotCell.appendChild(input);
        
        // Create remove button without inline onclick
        const button = document.createElement('button');
        button.className = 'btn';
        button.innerHTML = '<i class="fas fa-times text-danger"></i>';
        button.addEventListener('click', () => this.removeRow(button));
        removeCell.appendChild(button);
        
        this.updateGeneratedText();
    }

    /**
     * Remove a talkgroup row
     */
    removeRow(button) {
        const row = button.closest('tr');
        if (row) {
            row.remove();
            this.updateGeneratedText();
        }
    }

    /**
     * Check for duplicate talkgroup entries (run on blur, not while typing).
     */
    checkDupes() {
        const inputs = document.querySelectorAll('#timeslotTable input, #timeslotTable2 input');
        const values = [];
        let changed = false;

        inputs.forEach(input => {
            if (input.value === '') {
                return;
            }

            const value = parseInt(input.value, 10);
            if (isNaN(value)) {
                return;
            }

            if (values.includes(value)) {
                input.value = '';
                changed = true;
            } else {
                values.push(value);
            }
        });

        if (changed) {
            this.updateGeneratedText();
        }
    }

    /**
     * Wire talkgroup number inputs for live options text and blur-time dupe check.
     */
    bindTalkgroupInput(input) {
        input.addEventListener('input', () => this.updateGeneratedText());
        input.addEventListener('blur', () => this.checkDupes());
    }

    /**
     * Build TS1=/TS2= fragment; empty slot sends TSx=; so RYSEN clears statics.
     */
    formatTimeslotOption(slot, values, applicable) {
        if (!applicable) {
            return '';
        }
        if (values.length > 0) {
            return 'TS' + slot + '=' + values.join(',') + ';';
        }
        return 'TS' + slot + '=;';
    }

    /**
     * Persist generated options string for form POST (not shown in UI).
     */
    setGeneratedOptions(genText) {
        const genTextHidden = document.getElementById('genTextHidden');
        if (genTextHidden) {
            genTextHidden.value = genText;
        }
    }

    /**
     * Update the generated options text
     */
    updateGeneratedText() {
        const timeslotTable = document.getElementById('timeslotTable');
        const timeslotTable2 = document.getElementById('timeslotTable2');
        const dialTGInput = document.getElementById('dialTGInput');
        const voiceSelect = document.getElementById('voiceSelect');
        const languageSelect = document.getElementById('languageselect');
        const singleModeSelect = document.getElementById('singleModeSelect');
        const stickySelect = document.getElementById('stickySelect');
        const timeoutInput = document.getElementById('timeoutInput');

        const timeslots1 = this.getTimeslotValues(timeslotTable);
        const timeslots2 = this.getTimeslotValues(timeslotTable2);

        if (!dialTGInput || !voiceSelect) {
            return;
        }

        const dialTGValue = dialTGInput.value;
        const voiceValue = voiceSelect.value;
        const languageValue = languageSelect ? languageSelect.value : 'en_GB';
        const singleModeValue = singleModeSelect ? singleModeSelect.value : '-1';
        const stickyValue = stickySelect ? stickySelect.value : '-1';
        const timeoutValue = timeoutInput ? timeoutInput.value : '0';

        let genText = '';

        // Always send both TS1 and TS2 when static talkgroups apply (incl. simplex)
        const staticTgsApplicable = parseInt(dialTGValue, 10) <= 0;

        if (staticTgsApplicable) {
            genText += this.formatTimeslotOption(1, timeslots1, true);
            genText += this.formatTimeslotOption(2, timeslots2, true);
        }

        if (parseInt(dialTGValue, 10) > 0) {
            genText += 'DIAL=' + dialTGValue + ';';
        }

        if (voiceValue !== '-1') {
            genText += 'VOICE=' + voiceValue + ';';
        }

        if (voiceValue === '1' && languageSelect) {
            genText += 'LANG=' + languageValue + ';';
        }

        if (singleModeValue !== '-1') {
            genText += 'SINGLE=' + singleModeValue + ';';
        }

        if (stickyValue !== '-1') {
            genText += 'STICKY=' + stickyValue + ';';
        }

        if (parseInt(timeoutValue, 10) > 0) {
            genText += 'TIMER=' + timeoutValue + ';';
        }

        this.setGeneratedOptions(genText);
    }

    /**
     * Get timeslot values from a table
     */
    getTimeslotValues(table) {
        if (!table) return [];
        
        const values = [];
        for (let i = 0; i < table.rows.length; i++) {
            const input = table.rows[i].cells[1].querySelector('input');
            if (input && input.value.trim() !== '') {
                values.push(input.value);
            }
        }
        return values;
    }

    /**
     * Toggle spinner visibility
     */
    toggleSpinner(showSpinner) {
        const spinner = document.querySelector('.spinner');
        const blurContent = document.querySelector('.blur-content');
        
        if (spinner && blurContent) {
            if (showSpinner) {
                spinner.style.display = 'block';
                blurContent.style.display = 'none';
            } else {
                spinner.style.display = 'none';
                blurContent.style.display = 'block';
            }
        }
    }

    /**
     * Start polling for modified status
     */
    startStatusPolling() {
        this.toggleSpinner(true);
        this.checkInterval = setInterval(() => this.checkModifiedStatus(), 500);
    }

    /**
     * Check if device modification is complete
     */
    checkModifiedStatus() {
        fetch('sscheck.php')
            .then(response => response.text())
            .then(data => {
                if (data === '0') {
                    this.isModified = false;
                    this.toggleSpinner(false);
                    this.setActionButtonsDisabled(false);
                    if (this.checkInterval) {
                        clearInterval(this.checkInterval);
                        this.checkInterval = null;
                    }
                }
            })
            .catch(error => {
                console.error('Status check failed:', error);
            });
    }

    /**
     * Save configuration changes
     */
    saveChanges() {
        this.updateGeneratedText();
        const form = document.getElementById('saveChangesForm');
        if (form) {
            form.submit();
        }
    }

    /**
     * Update spinner status text by translation element id.
     */
    setWaitMessage(elementId) {
        const waitEl = document.getElementById('calc_wait');
        const sourceEl = document.getElementById(elementId);
        if (waitEl && sourceEl && sourceEl.textContent.trim() !== '') {
            waitEl.textContent = sourceEl.textContent;
        }
    }

    /**
     * Enable or disable save / disconnect controls during server apply.
     */
    setActionButtonsDisabled(disabled) {
        const saveBtn = document.getElementById('calc_save');
        const disconnectBtn = document.getElementById('calchlpdisconnect');
        if (saveBtn) {
            saveBtn.disabled = disabled;
        }
        if (disconnectBtn) {
            disconnectBtn.disabled = disabled;
        }
    }

    /**
     * POST pulse or restore phase to ssdisconnect.php.
     */
    postDisconnectPhase(action) {
        const csrfInput = document.querySelector('input[name="csrf_token"]');
        if (!csrfInput) {
            return Promise.reject(new Error('Missing CSRF token'));
        }

        return fetch('ssdisconnect.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                csrf_token: csrfInput.value,
                action: action
            })
        }).then(response => response.json().then(body => {
            if (!response.ok || !body.success) {
                throw new Error(body.error || 'Disconnect request failed');
            }
            return body;
        }));
    }

    /**
     * Poll until RYSEN applied a disconnect phase (modified=0 while device online).
     *
     * @param {number} deadlineMs Absolute timestamp when polling must stop
     */
    pollUntilDisconnectApplied(deadlineMs) {
        return new Promise((resolve, reject) => {
            const interval = setInterval(() => {
                if (Date.now() > deadlineMs) {
                    clearInterval(interval);
                    reject(new Error('Timed out waiting for the server to apply changes'));
                    return;
                }

                fetch('sscheck.php?full=1')
                    .then(response => response.json())
                    .then(data => {
                        if (data.logged_in !== '1') {
                            clearInterval(interval);
                            this.postDisconnectPhase('abort')
                                .catch(() => {})
                                .finally(() => {
                                    reject(new Error(this.getDisconnectOfflineMessage()));
                                });
                            return;
                        }

                        if (data.modified === '0') {
                            clearInterval(interval);
                            resolve();
                        }
                    })
                    .catch(error => {
                        clearInterval(interval);
                        reject(error);
                    });
            }, 500);
        });
    }

    /**
     * User-facing message when the device drops off during disconnect.
     */
    getDisconnectOfflineMessage() {
        const sourceEl = document.getElementById('calc_disconnect_offline');
        if (sourceEl && sourceEl.textContent.trim() !== '') {
            return sourceEl.textContent.trim();
        }
        return 'Device went offline before disconnect could complete. Reconnect and try again.';
    }

    /**
     * Poll sscheck.php until modified matches desired state.
     *
     * @param {boolean} expectedModified Wait for modified=1 (true) or 0 (false)
     * @param {number} deadlineMs Absolute timestamp (Date.now()) when polling must stop
     */
    pollUntilModified(expectedModified, deadlineMs) {
        const wantModified = expectedModified ? '1' : '0';

        return new Promise((resolve, reject) => {
            const interval = setInterval(() => {
                if (Date.now() > deadlineMs) {
                    clearInterval(interval);
                    reject(new Error('Timed out waiting for the server to apply changes'));
                    return;
                }

                fetch('sscheck.php')
                    .then(response => response.text())
                    .then(data => {
                        if (data === wantModified) {
                            clearInterval(interval);
                            resolve();
                        }
                    })
                    .catch(error => {
                        clearInterval(interval);
                        reject(error);
                    });
            }, 500);
        });
    }

    /**
     * @param {number} ms
     * @returns {Promise<void>}
     */
    sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    /**
     * Apply TG 4000-only options (like manual clear + Save), hold, then restore backup.
     */
    disconnectDynamicLink() {
        if (this.disconnectInProgress || this.isModified) {
            return;
        }

        const applyTimeoutMs = PROXY_OPTS_INTERVAL_MS + 5000;

        this.disconnectInProgress = true;
        this.setActionButtonsDisabled(true);
        this.toggleSpinner(true);
        this.setWaitMessage('calc_disconnect_wait');

        this.postDisconnectPhase('pulse')
            .then(() => this.pollUntilDisconnectApplied(Date.now() + applyTimeoutMs))
            .then(() => {
                this.setWaitMessage('calc_disconnect_hold');
                return this.sleep(DISCONNECT_HOLD_MS);
            })
            .then(() => {
                this.setWaitMessage('calc_disconnect_restore');
                return this.postDisconnectPhase('restore');
            })
            .then(() => this.pollUntilDisconnectApplied(Date.now() + applyTimeoutMs))
            .then(() => {
                window.location.reload();
            })
            .catch(error => {
                console.error('Disconnect failed:', error);
                this.toggleSpinner(false);
                this.setActionButtonsDisabled(false);
                this.setWaitMessage('calc_wait');
                alert(error.message || 'Disconnect failed. Please try again.');
            })
            .finally(() => {
                this.disconnectInProgress = false;
            });
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    const modeStatus = document.getElementById('mode-status');
    const ipscDevice = document.getElementById('ipsc-device');
    const deviceId = document.getElementById('device-id');
    const deviceModified = document.getElementById('device-modified');
    
    if (modeStatus && deviceId) {
        window.selfcare = new SelfcareManager({
            deviceMode: parseInt(modeStatus.value, 10),
            isIpsc: ipscDevice ? ipscDevice.value === '1' : false,
            deviceId: parseInt(deviceId.value, 10),
            isModified: deviceModified ? deviceModified.value === '1' : false
        });
    }
});
