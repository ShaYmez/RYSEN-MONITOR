<?php
/**
 * Header Element Component
 * Renders the HTML head section with metadata, styles, and includes
 * Includes branding configuration from config/branding.php
 */

// Load branding configuration
include_once __DIR__ . '/../config/branding.php';
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<!-- Page Title -->
<title><?php echo PAGE_TITLE; ?></title>
<!-- Favicon -->
<link rel="apple-touch-icon" sizes="180x180" href="<?php echo FAVICON_ICON; ?>">
<link rel="icon" type="image/png" sizes="32x32" href="<?php echo FAVICON_32; ?>">
<link rel="icon" type="image/png" sizes="16x16" href="<?php echo FAVICON_16; ?>">
<link rel="manifest" href="<?php echo MANIFEST; ?>">
<link rel="mask-icon" href="<?php echo MASK_ICON; ?>" color="<?php echo MASK_ICON_COLOR; ?>">
<meta name="msapplication-TileColor" content="<?php echo TILE_COLOR; ?>">
<meta name="theme-color" content="<?php echo THEME_COLOR; ?>">
<!-- Site Description -->
<meta name="description" content="<?php echo ORG_DESCRIPTION; ?>">
<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
<link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
<link rel="stylesheet" href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css">
<link rel="stylesheet" href="plugins/adminlte/css/adminlte.min.css">
<link rel="stylesheet" href="css/marquee.css">
<link rel="stylesheet" href="css/custom.css">
