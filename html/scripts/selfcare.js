/**
 * Selfcare Device Configuration Manager
 * Manages device settings, timeslots, and configuration options
 */
class SelfcareManager {
    constructor(config) {
        this.deviceMode = config.deviceMode;
        this.isIpsc = config.isIpsc;
        this.deviceId = config.deviceId;
        this.isModified = config.isModified;
        this.checkInterval = null;
        this.init();
    }

    /**
     * Initialize the manager
     */
    init() {
        this.attachEventListeners();
        this.toggleTimeslotTable();
        this.toggleLanguageDropdown();
        this.updateGeneratedText();
        
        if (this.isModified) {
            this.startStatusPolling();
        }
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

        if (this.isIpsc) {
            let genText = '';
            genText += this.formatTimeslotOption(1, timeslots1, true);
            genText += this.formatTimeslotOption(2, timeslots2, true);
            const genTextElement = document.getElementById('genText');
            if (genTextElement) {
                genTextElement.value = genText;
            }
            return;
        }

        if (!dialTGInput || !voiceSelect) return;

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

        const genTextElement = document.getElementById('genText');
        if (genTextElement) {
            genTextElement.value = genText;
        }
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
                    this.toggleSpinner(false);
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
        const genText = document.getElementById('genText');
        const genTextHidden = document.getElementById('genTextHidden');
        
        if (genText && genTextHidden) {
            genTextHidden.value = genText.value;
            document.getElementById('saveChangesForm').submit();
        }
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
