<?php
declare(strict_types=1);

require_once __DIR__ . '/../authentication/database.php';
require_once __DIR__ . '/../include/homepage_customization.php';

app_homepage_ensure_schema($conn);

$projectRoot = app_homepage_project_root();
$uploadDir = app_homepage_upload_dir();
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
    throw new RuntimeException('Could not create homepage upload directory.');
}

$sourceLogo = 'C:/Users/elias/OneDrive - Crystal IT Services/Desktop/website/WEBSITE PHOTOS/LOGO FOR HEADER.png';
$targetLogoRelative = 'uploads/assets/images/homepage/header-logo.jpg';
$targetLogoAbsolute = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $targetLogoRelative);

if (is_file($sourceLogo)) {
    foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $candidateExt) {
        $existingLogo = $uploadDir . DIRECTORY_SEPARATOR . 'header-logo.' . $candidateExt;
        if (is_file($existingLogo)) {
            @unlink($existingLogo);
        }
    }

    if (!app_image_convert_file_to_jpeg($sourceLogo, $targetLogoAbsolute, 50, 50, 88)) {
        throw new RuntimeException('Could not restore the homepage header logo file.');
    }
}

$homepageConfig = [
    'homepage_hero_image' => 'uploads/assets/images/homepage/hero-section.jpg',
    'homepage_collection_1_image' => 'uploads/assets/images/homepage/shop-collection-1.jpg',
    'homepage_collection_1_label' => 'Antipilling Plushies',
    'homepage_collection_1_link' => 'shop.php?category=Animals',
    'homepage_collection_2_image' => 'uploads/assets/images/homepage/shop-collection-2.jpg',
    'homepage_collection_2_label' => 'Cozy Blankets',
    'homepage_collection_2_link' => 'shop.php?category=Blankets',
    'homepage_collection_3_image' => 'uploads/assets/images/homepage/shop-collection-3.jpg',
    'homepage_collection_3_label' => 'Character Figures',
    'homepage_collection_3_link' => 'shop.php?category=Dolls',
    'homepage_collection_4_image' => 'uploads/assets/images/homepage/shop-collection-4.jpg',
    'homepage_collection_4_label' => 'Velvet Plushies',
    'homepage_collection_4_link' => 'shop.php?q=velvet',
    'homepage_journey_1_image' => 'uploads/assets/images/homepage/follow-journey-1.jpg',
    'homepage_journey_2_image' => 'uploads/assets/images/homepage/follow-journey-2.jpg',
    'homepage_journey_3_image' => 'uploads/assets/images/homepage/follow-journey-3.jpg',
];

if (is_file($targetLogoAbsolute)) {
    $homepageConfig['homepage_header_logo_path'] = $targetLogoRelative;
    $homepageConfig['logo_path'] = $targetLogoRelative;
}

foreach ($homepageConfig as $key => $value) {
    app_homepage_set_config_value($conn, $key, (string)$value);
}

echo "Homepage assets restored:\n";
foreach ($homepageConfig as $key => $value) {
    echo "- {$key} => {$value}\n";
}
