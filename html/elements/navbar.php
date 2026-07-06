<?php
/**
 * Navbar Element Component
 * Renders the main navigation bar with core menu items
 * Includes custom items from config/navbar-custom.php
 */

// Load language defaults (updated on upgrade) and custom navbar configuration
include_once __DIR__ . '/../include/languages.php';
include_once __DIR__ . '/../config/navbar-custom.php';
include_once __DIR__ . '/../config/branding.php';

// Merge defaults with any custom overrides (preserved config keeps old lists complete)
$custom_languages = isset($available_languages) ? $available_languages : array();
$available_languages = array_merge($default_languages, $custom_languages);
$selfcareUrl = (isset($_SESSION['user_id']) && !empty($_SESSION['int_ids'])) ? 'ssmain.php' : 'sslogin.php';

$infoNetworkUrl = '';
$infoNetworkId = 'nav_freestar';
$infoNetworkTarget = '_self';
$infoNetworkLabel = null;

if (isset($info_network_link) && is_array($info_network_link) && !empty($info_network_link['url'])) {
    $infoNetworkUrl = trim((string) $info_network_link['url']);
    $infoNetworkId = isset($info_network_link['id']) ? (string) $info_network_link['id'] : 'nav_freestar';
    $infoNetworkTarget = isset($info_network_link['target']) ? (string) $info_network_link['target'] : '_self';
    if (isset($info_network_link['label']) && trim((string) $info_network_link['label']) !== '') {
        $infoNetworkLabel = trim((string) $info_network_link['label']);
    }
}

/**
 * @param array|null $items
 * @return array
 */
function navbar_valid_menu_items($items) {
    if (!is_array($items)) {
        return array();
    }

    $valid = array();
    foreach ($items as $item) {
        if (!is_array($item) || empty($item['label']) || empty($item['url'])) {
            continue;
        }
        $valid[] = $item;
    }

    return $valid;
}

$infoDropdownItems = navbar_valid_menu_items(isset($info_dropdown_items) ? $info_dropdown_items : array());
$customNavbarItems = navbar_valid_menu_items(isset($custom_navbar_items) ? $custom_navbar_items : array());
?>
<nav class="main-header navbar navbar-expand-lg navbar-light navbar-dark text-sm">
    <div class="container text-nowrap">
        <a href="./index.php" class="navbar-brand">
            <img src="<?php echo LOGO_NAV_PATH; ?>" alt="<?php echo ORG_NAME; ?>" class="brand-image img-circle elevation-3"
                style="opacity: .8">
        </a>
        <button class="navbar-toggler order-1" type="button" data-bs-toggle="collapse" data-bs-target="#navbarCollapse"
            aria-controls="navbarCollapse" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse order-3" id="navbarCollapse">
            <ul class="navbar-nav">
                <li class="nav-item">
                    <a href="index.php?p=home" class="nav-link" id="nav_dash"></a>
                </li>
                <li class="nav-item">
                    <a href="index.php?p=systems" class="nav-link" id="nav_lnksys"></a>
                </li>
                <li class="nav-item">
                    <a href="index.php?p=systemstg" class="nav-link" id="nav_statg"></a>
                </li>
                <li class="nav-item">
                    <a href="index.php?p=openbridge" class="nav-link" id="nav_opb"></a>
                </li>
                <li class="nav-item">
                    <a href="index.php?p=toptg" class="nav-link" id="nav_tptg"></a>
                </li>
                <li class="nav-item">
                    <a href="index.php?p=monitor" class="nav-link" id="nav_mon"></a>
                </li>
                <li class="nav-item">
                    <a href="index.php?p=bulletin" class="nav-link"><i class="fas fa-bullhorn"></i> <span id="bulletin_board"></span></a>
                </li>
                <li class="nav-item">
                    <a href="<?php echo htmlspecialchars($selfcareUrl, ENT_QUOTES, 'UTF-8'); ?>" class="nav-link">Selfcare</a>
                </li>
                <li class="nav-item dropdown dropdown-hover">
                    <a id="dropdownSubMenu1" href="#" role="button" data-toggle="dropdown" aria-haspopup="true"
                        aria-expanded="false" class="nav-link dropdown-toggle"><span
                            id="nav_info"></span></a>
                    <ul aria-labelledby="dropdownSubMenu1" class="dropdown-menu border-0 shadow">
                        <li>
                            <a href="https://hosepipe.freestar.network" class="dropdown-item" target="_blank" rel="noopener noreferrer">HosePipe.</a>
                        </li>
                        <li>
                            <a href="index.php?p=wwtg" class="dropdown-item" id="nav_tglst"></a>
                        </li>
                        <li>
                            <a href="index.php?p=wwbridges" class="dropdown-item" id="nav_brdglst"></a>
                        </li>
                        <li>
                            <a href="index.php?p=wwservers" class="dropdown-item" id="nav_srvlst"></a>
                        </li>
                        <li>
                            <a href="../status/index.php" class="dropdown-item" id="nav_srvstat"></a>
                        </li>
                        <li>
                            <a href="index.php?p=calc" class="dropdown-item" id="nav_calc"></a>
                        </li>
                        <?php if ($infoNetworkUrl !== ''): ?>
                        <li>
                            <a href="<?php echo htmlspecialchars($infoNetworkUrl, ENT_QUOTES, 'UTF-8'); ?>"
                               class="dropdown-item"
                               <?php if ($infoNetworkLabel === null): ?>id="<?php echo htmlspecialchars($infoNetworkId, ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>
                               target="<?php echo htmlspecialchars($infoNetworkTarget, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php if ($infoNetworkLabel !== null): ?>
                                    <?php echo htmlspecialchars($infoNetworkLabel, ENT_QUOTES, 'UTF-8'); ?>
                                <?php endif; ?>
                            </a>
                        </li>
                        <?php endif; ?>
                        <?php
                        foreach ($infoDropdownItems as $infoItem) {
                            $infoTarget = isset($infoItem['target']) ? $infoItem['target'] : '_self';
                            $infoId = isset($infoItem['id']) ? $infoItem['id'] : '';
                            echo '<li>';
                            echo '<a href="' . htmlspecialchars($infoItem['url'], ENT_QUOTES, 'UTF-8') . '" class="dropdown-item"';
                            if ($infoId !== '') {
                                echo ' id="' . htmlspecialchars($infoId, ENT_QUOTES, 'UTF-8') . '"';
                            }
                            echo ' target="' . htmlspecialchars($infoTarget, ENT_QUOTES, 'UTF-8') . '">';
                            echo htmlspecialchars($infoItem['label'], ENT_QUOTES, 'UTF-8');
                            echo '</a></li>';
                        }
                        ?>
                    </ul>
                </li>
                <?php
                foreach ($customNavbarItems as $item) {
                    $target = isset($item['target']) ? $item['target'] : '_self';
                    $icon = isset($item['icon']) ? '<i class="' . htmlspecialchars($item['icon']) . '"></i> ' : '';
                    echo '<li class="nav-item">';
                    echo '<a href="' . htmlspecialchars($item['url']) . '" class="nav-link" target="' . $target . '">';
                    echo $icon . htmlspecialchars($item['label']);
                    echo '</a></li>';
                }
                ?>
            </ul>
        </div>
        <ul class="order-1 order-md-3 navbar-nav navbar-no-expand ml-auto">
            <!-- Social media links -->
            <?php
            if (isset($social_links) && !empty($social_links)) {
                foreach ($social_links as $social) {
                    echo '<li class="nav-item">';
                    echo '<a class="nav-link" href="' . htmlspecialchars($social['url']) . '" data-bs-toggle="tooltip" title="' . htmlspecialchars($social['title']) . '" role="button">';
                    echo '<i class="' . htmlspecialchars($social['icon']) . '"></i>';
                    echo '</a></li>';
                }
            }
            ?>
            <!-- Language selector -->
            <select class="form-select" id="languageSelect">
                <?php
                if (isset($available_languages)) {
                    foreach ($available_languages as $code => $label) {
                        $selected = isset($_SESSION['lang']) && $_SESSION['lang'] === $code ? 'selected' : '';
                        echo '<option value="' . htmlspecialchars($code) . '" ' . $selected . '>' . htmlspecialchars($label) . '</option>';
                    }
                }
                ?>
            </select>
            <!-- Theme toggle -->
            <li class="nav-item">
                <a class="nav-link" href="#" role="button" id="toggle-mode">
                    <i class="fas fa-adjust"></i>
                </a>
            </li>
        </ul>
    </div>
</nav>
<script src="scripts/i18n-boot.js"></script>
