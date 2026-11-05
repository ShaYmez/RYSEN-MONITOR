<?php
/**
 * Public options calculator.
 * Same function controls as logged-in selfcare, with a string to copy into
 * Pi-Star or WPSD. Nothing here is saved to a device.
 */
require_once __DIR__ . '/functions.php';

if (!function_exists('calcHiddenDefault')) {
    /**
     * Hidden live default for hotspot and repeater modes.
     */
    function calcHiddenDefault($id, $key, array $hotspot, array $repeater, $boolean = false)
    {
        $hotspotValue = serverDefaultAttribute($hotspot, $key, $boolean);
        $repeaterValue = serverDefaultAttribute($repeater, $key, $boolean);

        return '<input type="hidden" id="' . $id . '" value="' . escapeHtml($hotspotValue) . '"'
            . ' data-hotspot="' . escapeHtml($hotspotValue) . '"'
            . ' data-repeater="' . escapeHtml($repeaterValue) . '">';
    }

    /**
     * On/Off select. Blank when this server's config could not be read.
     */
    function calcOnOff($id, $value, $offId, $onId)
    {
        $html = '<select class="form-control form-control-sm" id="' . $id . '">';
        if ($value === '') {
            $html .= '<option value="" selected>Unavailable</option>';
        }
        $html .= '<option value="0"' . ($value === '0' ? ' selected' : '') . ' id="' . $offId . '"></option>';
        $html .= '<option value="1"' . ($value === '1' ? ' selected' : '') . ' id="' . $onId . '"></option>';
        $html .= '</select>';

        return $html;
    }
}

$hotspotDefaults = loadRysenFunctionDefaults(1);
$repeaterDefaults = loadRysenFunctionDefaults(0);
if (empty($repeaterDefaults['available'])) {
    $hyteraDefaults = loadRysenFunctionDefaults(-1);
    if (!empty($hyteraDefaults['available'])) {
        $repeaterDefaults = $hyteraDefaults;
    }
}

$hotspotAvailable = !empty($hotspotDefaults['available']);
$repeaterAvailable = !empty($repeaterDefaults['available']);
$voiceValue = displayedBooleanOption(null, $hotspotDefaults, 'voice');
$langValue = displayedScalarOption(null, $hotspotDefaults, 'language');
$singleValue = displayedBooleanOption(null, $hotspotDefaults, 'single');
$stickyValue = displayedBooleanOption(null, $hotspotDefaults, 'sticky');
$aliasValue = displayedBooleanOption(null, $hotspotDefaults, 'alias');
$timerValue = displayedScalarOption(null, $hotspotDefaults, 'timer');
$languages = [
    'en_GB' => 'English (en_GB)',
    'en_US' => 'English (en_US)',
    'es_ES' => 'Spanish (es_ES)',
    'fr_FR' => 'French (fr_FR)',
    'de_DE' => 'German (de_DE)',
    'dk_DK' => 'Danish (dk_DK)',
    'it_IT' => 'Italian (it_IT)',
    'no_NO' => 'Norwegian (no_NO)',
    'pl_PL' => 'Polish (pl_PL)',
    'se_SE' => 'Swedish (se_SE)',
    'pt_PT' => 'Portuguese (pt_PT)',
    'cy_GB' => 'Welsh (cy_GB)',
    'el_GR' => 'Greek (el_GR)',
    'CW' => 'CW',
];
?>
<div class="container options-form" id="options-calculator">
    <div class="row justify-content-center">
        <div class="col-12">
            <div class="card">
                <div class="card-header border-transparent text-center">
                    <h3 class="card-title options-calc-heading" id="clc_title"></h3>
                </div>
                <div class="card-body options-card-body">
                    <div class="row justify-content-center">
                        <div class="col-12 col-md-10 text-center mb-3">
                            <span id="calc_blurb"></span>
                        </div>
                    </div>

                    <input type="hidden" id="server-defaults-available" value="<?php echo $hotspotAvailable ? '1' : '0'; ?>" data-hotspot="<?php echo $hotspotAvailable ? '1' : '0'; ?>" data-repeater="<?php echo $repeaterAvailable ? '1' : '0'; ?>">
                    <?php
                    echo calcHiddenDefault('server-default-voice', 'voice', $hotspotDefaults, $repeaterDefaults, true);
                    echo calcHiddenDefault('server-default-language', 'language', $hotspotDefaults, $repeaterDefaults);
                    echo calcHiddenDefault('server-default-single', 'single', $hotspotDefaults, $repeaterDefaults, true);
                    echo calcHiddenDefault('server-default-sticky', 'sticky', $hotspotDefaults, $repeaterDefaults, true);
                    echo calcHiddenDefault('server-default-alias', 'alias', $hotspotDefaults, $repeaterDefaults, true);
                    echo calcHiddenDefault('server-default-timer', 'timer', $hotspotDefaults, $repeaterDefaults);
                    ?>

                    <div class="row justify-content-center">
                        <div class="form-group col-12 col-sm-8 col-md-4">
                            <div class="row justify-content-center">
                                <p class="mb-1"><b><span id="calc_type"></span></b>&nbsp;&nbsp;&nbsp;<i class="far fa-question-circle" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-html="true" title="" id="calchlptype"></i></p>
                            </div>
                            <select class="form-control form-control-sm bg-warning" id="modeSelector">
                                <option value="duplex" selected id="calc_duplex"></option>
                                <option value="simplex" id="calc_simplex"></option>
                                <option value="repeater" id="calc_repeater"></option>
                            </select>
                        </div>
                    </div>

                    <div class="row justify-content-center">
                        <div id="timeslot1col" class="col-12 col-md-5 mb-3 mb-md-0">
                            <table class="table table-sm border align-middle">
                                <thead class="bg-danger">
                                    <tr>
                                        <th colspan="3" style="text-align: center;" class="align-middle">Time Slot 1&nbsp;&nbsp;&nbsp;<i class="far fa-question-circle" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-html="true" title="" id="calchlpts1"></i></th>
                                    </tr>
                                </thead>
                                <tbody id="timeslotTable">
                                    <tr>
                                        <td class="align-middle text-md-nowrap">TG 1:</td>
                                        <td><input type="number" class="form-control form-control-sm" min="0" step="1"></td>
                                        <td><button type="button" class="btn" onclick="window.selfcare && window.selfcare.removeRow(this)"><i class="fas fa-times text-danger"></i></button></td>
                                    </tr>
                                </tbody>
                            </table>
                            <div class="row justify-content-center">
                                <button type="button" class="btn btn-primary btn-xs" onclick="window.selfcare && window.selfcare.addRow('timeslotTable')" id="calc_addts1"></button>
                            </div>
                        </div>
                        <div id="timeslot2col" class="col-12 col-md-5 mb-3 mb-md-0">
                            <table class="table table-sm border align-middle">
                                <thead class="bg-warning">
                                    <tr>
                                        <th colspan="3" style="text-align: center;" class="align-middle">Time Slot 2&nbsp;&nbsp;&nbsp;<i class="far fa-question-circle" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-html="true" title="" id="calchlpts2"></i></th>
                                    </tr>
                                </thead>
                                <tbody id="timeslotTable2">
                                    <tr>
                                        <td class="align-middle text-md-nowrap">TG 1:</td>
                                        <td><input type="number" class="form-control form-control-sm" min="0" step="1"></td>
                                        <td><button type="button" class="btn" onclick="window.selfcare && window.selfcare.removeRow(this)"><i class="fas fa-times text-danger"></i></button></td>
                                    </tr>
                                </tbody>
                            </table>
                            <div class="row justify-content-center">
                                <button type="button" class="btn btn-primary btn-xs" onclick="window.selfcare && window.selfcare.addRow('timeslotTable2')" id="calc_addts2"></button>
                            </div>
                        </div>
                    </div>

                    <div class="row justify-content-center">
                        <div class="col-12 col-lg-8">
                            <table class="table table-sm border align-middle mt-4">
                                <thead class="bg-success">
                                    <tr>
                                        <th colspan="2" style="text-align: center;" class="align-middle">Functions&nbsp;&nbsp;&nbsp;<i class="far fa-question-circle" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-html="true" title="" id="calchlpfun"></i></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr id="calc-defaults-warning"<?php echo $hotspotAvailable ? ' style="display: none;"' : ''; ?>>
                                        <td colspan="2" class="text-center text-warning py-2"><span id="calc_defaults_unavailable"></span></td>
                                    </tr>
                                    <tr class="calc-hotspot-only">
                                        <td class="align-middle text-md-nowrap"><span id="calc_dialtg"></span>&nbsp;&nbsp;&nbsp;<i class="far fa-question-circle" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-html="true" title="" id="calchlpdtg"></i></td>
                                        <td><input type="number" class="form-control form-control-sm" min="0" step="1" id="dialTGInput" value="0"></td>
                                    </tr>
                                    <tr class="calc-hotspot-only">
                                        <td class="align-middle text-md-nowrap"><span id="calc_voice"></span>&nbsp;&nbsp;&nbsp;<i class="far fa-question-circle" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-html="true" title="" id="calchlpvoice"></i></td>
                                        <td><?php echo calcOnOff('voiceSelect', $voiceValue, 'calc_voiceoff', 'calc_voiceon'); ?></td>
                                    </tr>
                                    <tr id="languagerow" class="calc-hotspot-only"<?php echo $voiceValue === '1' ? '' : ' style="display: none;"'; ?>>
                                        <td class="align-middle text-md-nowrap"><span id="calc_lang"></span>&nbsp;&nbsp;&nbsp;<i class="far fa-question-circle" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-html="true" title="" id="calchlplang"></i></td>
                                        <td>
                                            <select class="form-control form-control-sm" id="languageselect">
                                                <?php if ($langValue === ''): ?><option value="" selected>Unavailable</option><?php endif; ?>
                                                <?php foreach ($languages as $code => $label): ?>
                                                <option value="<?php echo escapeHtml($code); ?>"<?php echo $langValue === $code ? ' selected' : ''; ?>><?php echo escapeHtml($label); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr class="calc-hotspot-only">
                                        <td class="align-middle text-md-nowrap"><span id="calc_smode"></span>&nbsp;&nbsp;&nbsp;<i class="far fa-question-circle" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-html="true" title="" id="calchlpsmode"></i></td>
                                        <td><?php echo calcOnOff('singleModeSelect', $singleValue, 'calc_smodeoff', 'calc_smodeon'); ?></td>
                                    </tr>
                                    <tr class="calc-hotspot-only">
                                        <td class="align-middle text-md-nowrap"><span id="calc_sticky"></span>&nbsp;&nbsp;&nbsp;<i class="far fa-question-circle" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-html="true" title="" id="calchlpsticky"></i></td>
                                        <td><?php echo calcOnOff('stickySelect', $stickyValue, 'calc_stickyoff', 'calc_stickyon'); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="align-middle text-md-nowrap"><span id="calc_alias"></span>&nbsp;&nbsp;&nbsp;<i class="far fa-question-circle" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-html="true" title="" id="calchlpalias"></i></td>
                                        <td><?php echo calcOnOff('aliasSelect', $aliasValue, 'calc_aliasoff', 'calc_aliason'); ?></td>
                                    </tr>
                                    <tr class="calc-hotspot-only">
                                        <td class="align-middle text-md-nowrap"><span id="calc_tgto"></span>&nbsp;&nbsp;&nbsp;<i class="far fa-question-circle" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-html="true" title="" id="calchlpstgto"></i></td>
                                        <td><input type="number" class="form-control form-control-sm" min="0" step="1" id="timeoutInput" value="<?php echo escapeHtml($timerValue); ?>"></td>
                                    </tr>
                                    <tr>
                                        <td colspan="2" class="text-center py-2">
                                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.selfcare && window.selfcare.resetFunctionsToServerDefaults()" id="calc_reset_defaults"></button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="row justify-content-center mb-3">
                        <div class="col-12 col-md-5 mb-3 mb-md-0">
                            <div class="row justify-content-center">
                                <p class="mb-1"><b>DMR Gateway</b></p>
                            </div>
                            <textarea class="form-control text-sm form-control-sm" id="generatedTextWithQuotes" rows="2" readonly></textarea>
                            <div class="row justify-content-center mt-4 mb-4">
                                <button type="button" class="btn btn-primary mt-2" onclick="window.selfcare && window.selfcare.copyGeneratedText('generatedTextWithQuotes')" id="calc_copy1"></button>
                            </div>
                        </div>
                        <div class="col-12 col-md-5">
                            <div class="row justify-content-center">
                                <p class="mb-1"><b>DMR Options=</b></p>
                            </div>
                            <textarea class="form-control text-sm form-control-sm" id="generatedTextWithoutQuotes" rows="2" readonly></textarea>
                            <div class="row justify-content-center mt-4 mb-4">
                                <button type="button" class="btn btn-primary mt-2" onclick="window.selfcare && window.selfcare.copyGeneratedText('generatedTextWithoutQuotes')" id="calc_copy2"></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
