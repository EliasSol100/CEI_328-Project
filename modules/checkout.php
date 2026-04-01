<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// Log all errors to a file for debugging
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/checkout_errors.log');
error_log("=== Checkout page accessed at " . date('Y-m-d H:i:s') . " ===");
unset($_SESSION['checkout_error'], $_SESSION['paypal_order_id'], $_SESSION['paypal_total']);

define('INCLUDE_CHECK', true);

require_once __DIR__ . '/../authentication/database.php';
require_once __DIR__ . '/../include/loyalty_program.php';
require_once __DIR__ . '/place_order.php';

$configPath = __DIR__ . '/../authentication/get_config.php';
if (file_exists($configPath)) {
    require_once $configPath;
    $system_title = function_exists('getSystemConfig') ? getSystemConfig('site_title') : 'Creations by Athina';
} else {
    $system_title = 'Creations by Athina';
}

if (!$conn || $conn->connect_error) {
    die("Database connection failed: " . ($conn->connect_error ?? 'Unknown error'));
}

// ========== CREATE COURIER PICKUP POINTS TABLE IF NOT EXISTS ==========
function ensureCourierPickupPointsTable($conn) {
    $tableCheck = $conn->query("SHOW TABLES LIKE 'courier_pickup_points'");
    if ($tableCheck->num_rows == 0) {
        $sql = "CREATE TABLE IF NOT EXISTS `courier_pickup_points` (
            `pickup_id` INT AUTO_INCREMENT PRIMARY KEY,
            `courier_code` VARCHAR(50) NOT NULL,
            `name` VARCHAR(255) NOT NULL,
            `address` VARCHAR(255) NOT NULL,
            `city` VARCHAR(100) NOT NULL,
            `town` VARCHAR(100),
            `postal_code` VARCHAR(20),
            `country` VARCHAR(50) NOT NULL,
            `lat` DECIMAL(10, 8) NOT NULL,
            `lng` DECIMAL(11, 8) NOT NULL,
            `is_active` TINYINT DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_courier_country (courier_code, country),
            INDEX idx_location (lat, lng),
            INDEX idx_city (city)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        if ($conn->query($sql)) {
            // Insert sample data for Cyprus and Greece
            $sampleData = [
                // Akis Express Cyprus
                ['akis_express', 'Akis Express Nicosia Center', '15 Makariou Avenue', 'Nicosia', null, '1065', 'Cyprus', 35.1667, 33.3667],
                ['akis_express', 'Akis Express Strovolos', '25 Athalassas Avenue', 'Nicosia', 'Strovolos', '2025', 'Cyprus', 35.1500, 33.3500],
                ['akis_express', 'Akis Express Limassol Center', '25 Agias Zonis', 'Limassol', null, '3025', 'Cyprus', 34.6833, 33.0500],
                ['akis_express', 'Akis Express Larnaca Center', '10 Grigori Afxentiou', 'Larnaca', null, '6022', 'Cyprus', 34.9167, 33.6333],
                ['akis_express', 'Akis Express Paphos Center', '5 Apostolou Pavlou', 'Paphos', null, '8040', 'Cyprus', 34.7754, 32.4257],
                
                // ACS Cyprus
                ['acs', 'ACS Nicosia Main', '1 Dimostheni Severi', 'Nicosia', null, '1080', 'Cyprus', 35.1667, 33.3667],
                ['acs', 'ACS Nicosia Mall', 'Mall of Cyprus', 'Nicosia', 'Strovolos', '2064', 'Cyprus', 35.1333, 33.3500],
                ['acs', 'ACS Limassol Center', '30 Anexartisias', 'Limassol', null, '3040', 'Cyprus', 34.6833, 33.0500],
                ['acs', 'ACS Larnaca Center', '15 Leonida Kioupi', 'Larnaca', null, '6015', 'Cyprus', 34.9167, 33.6333],
                ['acs', 'ACS Paphos Center', '8 Nikolaou Nikolaidi', 'Paphos', null, '8010', 'Cyprus', 34.7754, 32.4257],
                
                // BoxNow Cyprus
                ['boxnow', 'BoxNow Nicosia - Mall of Cyprus', 'Mall of Cyprus', 'Nicosia', 'Strovolos', '2064', 'Cyprus', 35.1333, 33.3500],
                ['boxnow', 'BoxNow Nicosia - Engomi', '20 Kyriakou Matsi', 'Nicosia', 'Engomi', '2409', 'Cyprus', 35.1500, 33.3200],
                ['boxnow', 'BoxNow Limassol - MyMall', 'MyMall Limassol', 'Limassol', null, '4060', 'Cyprus', 34.6833, 33.0500],
                ['boxnow', 'BoxNow Larnaca - Metropolis Mall', 'Metropolis Mall', 'Larnaca', null, '6045', 'Cyprus', 34.9167, 33.6333],
                ['boxnow', 'BoxNow Paphos - Kings Avenue Mall', 'Kings Avenue Mall', 'Paphos', null, '8041', 'Cyprus', 34.7754, 32.4257],
                
                // Greece - Akis Express
                ['akis_express', 'Akis Express Athens Center', '10 Panepistimiou', 'Athens', null, '10564', 'Greece', 37.9838, 23.7275],
                ['akis_express', 'Akis Express Piraeus', '25 Akti Miaouli', 'Piraeus', null, '18535', 'Greece', 37.9420, 23.6460],
                ['akis_express', 'Akis Express Thessaloniki', '30 Egnatia', 'Thessaloniki', null, '54630', 'Greece', 40.6401, 22.9444],
                
                // Greece - ACS
                ['acs', 'ACS Athens Center', '15 Agiou Konstantinou', 'Athens', null, '10431', 'Greece', 37.9838, 23.7275],
                ['acs', 'ACS Thessaloniki', '30 Egnatia', 'Thessaloniki', null, '54630', 'Greece', 40.6401, 22.9444],
                ['acs', 'ACS Patras', '20 Agiou Nikolaou', 'Patras', null, '26221', 'Greece', 38.2466, 21.7346],
                
                // Greece - BoxNow
                ['boxnow', 'BoxNow Athens - Syntagma', 'Syntagma Square', 'Athens', null, '10563', 'Greece', 37.9754, 23.7350],
                ['boxnow', 'BoxNow Athens - Kifisia', '25 Kifisias Avenue', 'Athens', 'Kifisia', '14562', 'Greece', 38.0742, 23.8143],
                ['boxnow', 'BoxNow Thessaloniki - Aristotelous', 'Aristotelous Square', 'Thessaloniki', null, '54623', 'Greece', 40.6320, 22.9400],
            ];
            
            $insertStmt = $conn->prepare("INSERT INTO courier_pickup_points (courier_code, name, address, city, town, postal_code, country, lat, lng) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($sampleData as $data) {
                $insertStmt->bind_param("sssssssdd", $data[0], $data[1], $data[2], $data[3], $data[4], $data[5], $data[6], $data[7], $data[8]);
                $insertStmt->execute();
            }
            $insertStmt->close();
        }
    }
}
ensureCourierPickupPointsTable($conn);

// ========== AJAX HANDLER FOR PICKUP POINTS ==========
if (isset($_GET['ajax_get_pickup_points'])) {
    // Clear any previous output
    while (ob_get_level()) ob_end_clean();
    ob_start();
    
    error_reporting(0);
    header('Content-Type: application/json');
    
    $response = ['success' => false, 'points' => [], 'user_coords' => null, 'error' => null];
    
    try {
        $courier = trim($_GET['courier'] ?? '');
        $country = trim($_GET['country'] ?? '');
        $city = trim($_GET['city'] ?? '');
        $postal = trim($_GET['postal'] ?? '');
        $address = trim($_GET['address'] ?? '');
        
        if (!$courier || !$country) throw new Exception('Missing courier or country');
        
        // Expanded city coordinates (add all cities your users might enter)
        $cityCoords = [
        // ========== CYPRUS ==========
        // Major cities
        'nicosia'      => [35.1856, 33.3823],
        'lefkosia'     => [35.1856, 33.3823],
        'strovolos'    => [35.1500, 33.3500],
        'engomi'       => [35.1500, 33.3200],
        'latsia'       => [35.1090, 33.3800],
        'limassol'     => [34.6841, 33.0379],
        'lemesos'      => [34.6841, 33.0379],
        'larnaca'      => [34.9167, 33.6290],
        'paphos'       => [34.7754, 32.4257],
        'pafos'        => [34.7754, 32.4257],
        'paralimni'    => [35.0396, 33.9819],
        'ayia napa'    => [34.9875, 34.0017],
        'protaras'     => [35.0096, 34.0541],
        // Villages & towns
        'agros'        => [34.9197, 33.0194],
        'alaminos'     => [34.8172, 33.4503],
        'anogyra'      => [34.7456, 32.7217],
        'argaka'       => [35.0656, 32.4847],
        'avgorou'      => [35.0408, 33.8517],
        'ayia marina'  => [35.1172, 32.5311],
        'ayios epiktitos' => [35.3206, 33.4333],
        'ayios tychonas'=> [34.7083, 33.1500],
        'chloraka'     => [34.8011, 32.4147],
        'dali'         => [35.0292, 33.4233],
        'deryneia'     => [35.0639, 33.9567],
        'dhrousha'     => [34.9639, 32.4050],
        'eastern cyprus'=> [35.0396, 33.9819],
        'emba'         => [34.8000, 32.4300],
        'geroskipou'   => [34.7597, 32.4533],
        'gialousa'     => [35.5372, 34.1778],
        'goudi'        => [35.1778, 33.3672],
        'inou'         => [34.9694, 32.4303],
        'kalavasos'    => [34.7647, 33.2994],
        'kato paphos'  => [34.7600, 32.4067],
        'kelokedara'   => [34.8192, 32.7797],
        'kissonerga'   => [34.8214, 32.3981],
        'klavdia'      => [34.9083, 33.5528],
        'konia'        => [34.7689, 32.4625],
        'kornos'       => [34.9219, 33.3961],
        'kouklia'      => [34.7036, 32.5764],
        'kourion'      => [34.7111, 32.8747],
        'kyperounta'   => [34.9392, 32.8722],
        'lania'        => [34.8272, 32.9142],
        'lapithos'     => [35.3403, 33.1792],
        'latakia'      => [35.1090, 33.3800],
        'leonarisso'   => [35.1742, 33.7425],
        'livadia'      => [35.0642, 33.6222],
        'mesa geitonia'=> [34.6994, 33.0456],
        'miliou'       => [34.9331, 32.3792],
        'mosphiloti'   => [34.9467, 33.4297],
        'mouttagiaka'  => [34.7247, 33.0839],
        'northern cyprus' => [35.1667, 33.3667],
        'orphanides'   => [34.9386, 33.3997],
        'pano arodes'  => [34.9206, 32.5378],
        'pano panagia' => [34.9356, 32.5228],
        'pano platres' => [34.8992, 32.8608],
        'pano polemi'  => [34.8694, 32.5286],
        'paphos region'=> [34.7754, 32.4257],
        'pegeia'       => [34.8839, 32.3822],
        'pendeia'      => [34.8692, 32.5139],
        'platres'      => [34.8833, 32.8667],
        'polemi'       => [34.8681, 32.5264],
        'poli crysochous' => [35.0381, 32.4267],
        'pyla'         => [34.9986, 33.6919],
        'rizokarpaso'  => [35.6631, 34.3750],
        'salami'       => [35.1792, 33.9122],
        'sotira'       => [35.0247, 33.9519],
        'tala'         => [34.8397, 32.4333],
        'teratsoudia'  => [34.7683, 32.4853],
        'ternatos'     => [34.9014, 32.4325],
        'timi'         => [34.7481, 32.5236],
        'trikomo'      => [35.2797, 33.8842],
        'tsada'        => [34.8389, 32.4819],
        'vavla'        => [34.8625, 33.3525],
        'xylofagou'    => [34.9714, 33.8525],
        'yermasoyia'   => [34.7114, 33.0867],
        // ========== GREECE ==========
        // Major cities
        'athens'        => [37.9838, 23.7275],
        'athina'        => [37.9838, 23.7275],
        'piraeus'       => [37.9420, 23.6460],
        'thessaloniki'  => [40.6401, 22.9444],
        'patras'        => [38.2466, 21.7346],
        'iraklio'       => [35.3387, 25.1442],
        'heraklion'     => [35.3387, 25.1442],
        'larissa'       => [39.6390, 22.4191],
        'volos'         => [39.3610, 22.9420],
        'ioannina'      => [39.6650, 20.8537],
        'kavala'        => [40.9392, 24.4015],
        'rhodes'        => [36.4342, 28.2174],
        'chania'        => [35.5138, 24.0180],
        // Villages & towns (partial)
        'agios nikolaos' => [35.1917, 25.7172],
        'argos'         => [37.6333, 22.7167],
        'argostoli'     => [38.1797, 20.4872],
        'artemida'      => [37.9500, 23.9667],
        'aspropyrgos'   => [38.0600, 23.5900],
        'chalkida'      => [38.4642, 23.6000],
        'corfu'         => [39.6242, 19.9228],
        'drama'         => [41.1525, 24.1425],
        'edessa'        => [40.8000, 22.0500],
        'elefsina'      => [38.0417, 23.5433],
        'europos'       => [40.9333, 22.5000],
        'florina'       => [40.7833, 21.4000],
        'grevena'       => [40.0833, 21.4167],
        'kalamata'      => [37.0333, 22.1167],
        'karditsa'      => [39.3667, 21.9167],
        'kastoria'      => [40.5167, 21.2667],
        'katerini'      => [40.2667, 22.5000],
        'kozani'        => [40.3000, 21.7833],
        'lamia'         => [38.9000, 22.4333],
        'lefkada'       => [38.8333, 20.7000],
        'livadeia'      => [38.4333, 22.8667],
        'megalopoli'    => [37.4000, 22.1333],
        'messini'       => [37.0500, 22.0000],
        'mytilini'      => [39.1119, 26.5536],
        'nafplio'       => [37.5667, 22.8000],
        'naxos'         => [37.1167, 25.3667],
        'oia'           => [36.4667, 25.3667],
        'pylos'         => [36.9167, 21.7000],
        'rethymno'      => [35.3667, 24.4667],
        'sparti'        => [37.0667, 22.4333],
        'thebes'        => [38.3167, 23.3167],
        'tripoli'       => [37.5167, 22.3833],
        'veria'         => [40.5333, 22.2000],
        'xanthi'        => [41.1417, 24.8867],
        'zante'         => [37.7833, 20.9000],
    ];
        
        $cityLower = strtolower(trim($city));
        $userLat = null;
        $userLng = null;
        
        if (isset($cityCoords[$cityLower])) {
            $userLat = $cityCoords[$cityLower][0];
            $userLng = $cityCoords[$cityLower][1];
        } else {
            // Partial match
            foreach ($cityCoords as $key => $coords) {
                if (strpos($cityLower, $key) !== false || strpos($key, $cityLower) !== false) {
                    $userLat = $coords[0];
                    $userLng = $coords[1];
                    break;
                }
            }
        }
        
        // Fallback to country center
        if (!$userLat) {
            if ($country === 'Cyprus') {
                $userLat = 35.14; $userLng = 33.36;
            } elseif ($country === 'Greece') {
                $userLat = 38.12; $userLng = 23.72;
            } else {
                throw new Exception('Unsupported country');
            }
        }
        
        $response['user_coords'] = ['lat' => $userLat, 'lng' => $userLng];
        
        // Query: get the 3 closest points for this courier in this country
        $query = "SELECT pickup_id, name, address, city, town, postal_code, lat, lng, courier_code,
          ROUND(6371 * acos(
              GREATEST(-1, LEAST(1, 
                  COS(RADIANS(?)) * COS(RADIANS(lat)) * 
                  COS(RADIANS(lng) - RADIANS(?)) + 
                  SIN(RADIANS(?)) * SIN(RADIANS(lat))
              ))
          ), 2) AS distance
          FROM courier_pickup_points 
          WHERE courier_code = ? AND country = ? AND is_active = 1
          HAVING distance <= 20
          ORDER BY distance ASC
          LIMIT 3";
        
        $stmt = $conn->prepare($query);
        if (!$stmt) throw new Exception('SQL prepare failed: ' . $conn->error);
        $stmt->bind_param("ddsss", $userLat, $userLng, $userLat, $courier, $country);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $points = [];
        while ($row = $result->fetch_assoc()) {
            $points[] = [
                'id' => (int)$row['pickup_id'],
                'name' => $row['name'],
                'address' => $row['address'],
                'city' => $row['city'],
                'town' => $row['town'] ?? '',
                'postal_code' => $row['postal_code'] ?? '',
                'lat' => (float)$row['lat'],
                'lng' => (float)$row['lng'],
                'distance' => (float)$row['distance'],
                'courier_code' => $row['courier_code']
            ];
        }
        $stmt->close();
        
        $response['success'] = true;
        $response['points'] = $points;
        
    } catch (Exception $e) {
        $response['error'] = $e->getMessage();
    }
    
    ob_clean();
    echo json_encode($response);
    exit;
}
// ========== CHECKOUT FUNCTIONS ==========
function ensurePromotionCouponColumn(mysqli $conn): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $check = $conn->query("SHOW COLUMNS FROM promotions LIKE 'couponCode'");
    $exists = ($check && $check->num_rows > 0);
    if (!$exists) {
        $conn->query("ALTER TABLE promotions ADD COLUMN couponCode VARCHAR(64) NULL AFTER promotionName");
    }
}

function normalizeCouponCode(string $code): string {
    $code = strtoupper(trim($code));
    $code = preg_replace('/[^A-Z0-9_-]/', '', $code);
    return (string)$code;
}

function findActiveCouponPromotion(mysqli $conn, string $couponCode): ?array {
    if ($couponCode === '') return null;

    $sql = "
        SELECT p.promotionID, p.promotionName, p.discountType, p.discountValue, p.scope, p.categoryID, c.categoryName
        FROM promotions p
        LEFT JOIN categories c ON c.categoryID = p.categoryID
        WHERE p.isActive = 1
          AND UPPER(TRIM(COALESCE(p.couponCode, ''))) = ?
          AND (p.startDate IS NULL OR p.startDate <= CURDATE())
          AND (p.endDate IS NULL OR p.endDate >= CURDATE())
        ORDER BY p.createdAt DESC, p.promotionID DESC
        LIMIT 1
    ";
    $st = $conn->prepare($sql);
    if (!$st) return null;
    $st->bind_param("s", $couponCode);
    $st->execute();
    $res = $st->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $st->close();
    return $row ?: null;
}

function checkoutSanitizePositiveInt($value): int {
    $digits = preg_replace('/\D/', '', (string)$value);
    return max(0, (int)$digits);
}

function checkoutResetLoyaltySelection(): void {
    unset($_SESSION['cart_loyalty_points_redeem'], $_SESSION['cart_loyalty_user_id']);
}

function checkoutStoreLoyaltySelection(int $userId, int $points): void {
    if ($userId <= 0 || $points <= 0) {
        checkoutResetLoyaltySelection();
        return;
    }

    $_SESSION['cart_loyalty_points_redeem'] = $points;
    $_SESSION['cart_loyalty_user_id'] = $userId;
}

function cartLineTotalsWithCategory(mysqli $conn, array $cartItems): array {
    $lineTotals = [];
    $productIds = [];
    foreach ($cartItems as $item) {
        $productId = (int)($item['product']['id'] ?? $item['productID'] ?? $item['product_id'] ?? 0);
        $qty = max(1, (int)($item['quantity'] ?? 1));
        $basePrice = (float)($item['product']['basePrice'] ?? 0);
        $addonsCost = (float)($item['addons']['addonsCost'] ?? 0);
        if ($addonsCost <= 0) {
            if (!empty($item['addons']['giftWrapping'])) $addonsCost += 2.0;
            if (!empty($item['addons']['giftBagFlag'])) $addonsCost += 1.5;
        }
        $unit = (float)($item['price'] ?? $item['pricing']['unitTotal'] ?? ($basePrice + $addonsCost));
        $lineTotals[] = [
            'product_id' => $productId,
            'line_total' => $unit * $qty,
            'category' => '',
        ];
        if ($productId > 0) $productIds[$productId] = true;
    }

    $categoryByProduct = [];
    if (!empty($productIds)) {
        $ids = implode(',', array_map('intval', array_keys($productIds)));
        $res = $conn->query("SELECT productID, COALESCE(category, '') AS category FROM products WHERE productID IN ({$ids})");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $categoryByProduct[(int)$row['productID']] = (string)$row['category'];
            }
        }
    }

    foreach ($lineTotals as &$line) {
        $pid = (int)$line['product_id'];
        $line['category'] = $categoryByProduct[$pid] ?? '';
    }
    unset($line);

    return $lineTotals;
}

function computePromotionDiscount(mysqli $conn, array $cartItems, array $promotion): float {
    $discountType = strtolower(trim((string)($promotion['discountType'] ?? 'percentage')));
    $discountValue = max(0, (float)($promotion['discountValue'] ?? 0));
    $scope = strtolower(trim((string)($promotion['scope'] ?? 'store')));

    $lines = cartLineTotalsWithCategory($conn, $cartItems);
    $eligibleSubtotal = 0.0;

    if ($scope === 'category') {
        $targetCategory = trim((string)($promotion['categoryName'] ?? ''));
        foreach ($lines as $line) {
            if ($targetCategory !== '' && strcasecmp((string)$line['category'], $targetCategory) === 0) {
                $eligibleSubtotal += (float)$line['line_total'];
            }
        }
    } else {
        foreach ($lines as $line) {
            $eligibleSubtotal += (float)$line['line_total'];
        }
    }

    if ($eligibleSubtotal <= 0) return 0.0;

    if ($discountType === 'fixed') {
        return min($eligibleSubtotal, $discountValue);
    }

    return min($eligibleSubtotal, ($eligibleSubtotal * $discountValue) / 100);
}

function checkoutNormalizeCountry(string $country, array $availableCountries): string {
    $country = trim($country);
    foreach ($availableCountries as $allowed) {
        if (strcasecmp($country, (string)$allowed) === 0) {
            return (string)$allowed;
        }
    }
    return (string)($availableCountries[0] ?? 'Cyprus');
}

function checkoutCountryCouriers(string $country, array $countryCouriers): array {
    return $countryCouriers[$country] ?? [];
}

function checkoutIsCourierAllowed(string $country, string $courierCode, array $countryCouriers): bool {
    if ($courierCode === '') return false;
    $allowed = checkoutCountryCouriers($country, $countryCouriers);
    return array_key_exists($courierCode, $allowed);
}

function checkoutShippingCost(
    string $country,
    string $speed,
    float $cartTotal,
    float $freeShippingThreshold,
    array $shippingRatesByCountry
): float {
    if ($cartTotal >= $freeShippingThreshold) return 0.0;
    if (!isset($shippingRatesByCountry[$country])) return 0.0;
    if (!isset($shippingRatesByCountry[$country][$speed])) return 0.0;
    return (float)$shippingRatesByCountry[$country][$speed];
}

function checkoutTableExists(mysqli $conn, string $tableName): bool {
    $safe = $conn->real_escape_string($tableName);
    $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $res && $res->num_rows > 0;
}

function checkoutLoadDefaultAddress(mysqli $conn, int $userId): array {
    $default = ['address' => '', 'city' => '', 'postal_code' => '', 'country' => '', 'label' => '', 'source' => ''];
    if ($userId <= 0) return $default;

    try {
        $tableCheck = $conn->query("SHOW TABLES LIKE 'user_addresses'");
        if ($tableCheck && $tableCheck->num_rows > 0) {
            $addressStmt = $conn->prepare("
                SELECT label, country, city, address, postcode
                FROM user_addresses
                WHERE user_id = ? AND is_default = 1
                ORDER BY created_at ASC, id ASC
                LIMIT 1
            ");
            if ($addressStmt) {
                $addressStmt->bind_param('i', $userId);
                $addressStmt->execute();
                $res = $addressStmt->get_result();
                $row = $res ? $res->fetch_assoc() : null;
                $addressStmt->close();

                if ($row) {
                    $default['address'] = trim((string)($row['address'] ?? ''));
                    $default['city'] = trim((string)($row['city'] ?? ''));
                    $default['postal_code'] = trim((string)($row['postcode'] ?? ''));
                    $default['country'] = trim((string)($row['country'] ?? ''));
                    $default['label'] = trim((string)($row['label'] ?? ''));
                    $default['source'] = 'address_book';
                    return $default;
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error accessing user_addresses: " . $e->getMessage());
    }

    try {
        $profileStmt = $conn->prepare("
            SELECT country, city, address, postcode
            FROM users
            WHERE userID = ?
            LIMIT 1
        ");
        if ($profileStmt) {
            $profileStmt->bind_param('i', $userId);
            $profileStmt->execute();
            $res = $profileStmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $profileStmt->close();

            if ($row) {
                $default['address'] = trim((string)($row['address'] ?? ''));
                $default['city'] = trim((string)($row['city'] ?? ''));
                $default['postal_code'] = trim((string)($row['postcode'] ?? ''));
                $default['country'] = trim((string)($row['country'] ?? ''));
                $default['source'] = 'registration';
            }
        }
    } catch (Exception $e) {
        error_log("Error accessing users table: " . $e->getMessage());
    }

    return $default;
}

ensurePromotionCouponColumn($conn);
if (function_exists('ensureLoyaltyProgramSchema')) ensureLoyaltyProgramSchema($conn);

$project = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
if ($project === '' || $project === '.') {
    $project = '';
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$isLoggedIn = isset($_SESSION["user"]);
$userId = $isLoggedIn ? (int)($_SESSION["user"]["id"] ?? $_SESSION["user"]["userID"] ?? 0) : 0;
$userEmail = $isLoggedIn ? ($_SESSION["user"]["email"] ?? null) : null;
$userFullName = $isLoggedIn ? ($_SESSION["user"]["full_name"] ?? 'User') : null;
if (!$isLoggedIn || $userId <= 0) {
    checkoutResetLoyaltySelection();
} elseif (isset($_SESSION['cart_loyalty_user_id']) && (int)$_SESSION['cart_loyalty_user_id'] !== $userId) {
    checkoutResetLoyaltySelection();
}

$sessionCart = $_SESSION['cart'] ?? [];
$cartItems = (is_array($sessionCart) && isset($sessionCart['items']) && is_array($sessionCart['items']))
    ? $sessionCart['items']
    : (is_array($sessionCart) ? $sessionCart : []);
$cartTotal = 0;
$cartCount = 0;
foreach ($cartItems as $item) {
    $basePrice = (float)($item['product']['basePrice'] ?? 0);
    $addonsCost = (float)($item['addons']['addonsCost'] ?? 0);
    if ($addonsCost <= 0) {
        if (!empty($item['addons']['giftWrapping'])) $addonsCost += 2.0;
        if (!empty($item['addons']['giftBagFlag'])) $addonsCost += 1.5;
    }
    $price = (float)($item['price'] ?? $item['pricing']['unitTotal'] ?? ($basePrice + $addonsCost));
    $qty = (int)($item['quantity'] ?? 1);
    $cartTotal += $price * $qty;
    $cartCount += $qty;
}
if (empty($cartItems)) {
    header('Location: ' . $project . '/cart.php');
    exit;
}

$couponAction = '';
$loyaltyAction = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $couponAction = strtolower(trim((string)($_POST['coupon_action'] ?? '')));
    if (!in_array($couponAction, ['apply', 'remove'], true)) $couponAction = '';
    if ($couponAction === 'remove') $_POST['coupon_code'] = '';

    $loyaltyAction = strtolower(trim((string)($_POST['loyalty_action'] ?? '')));
    if (!in_array($loyaltyAction, ['apply', 'remove'], true)) $loyaltyAction = '';
    if ($loyaltyAction === 'remove') $_POST['loyalty_points'] = '';
}

$selectedCouponCode = normalizeCouponCode((string)($_POST['coupon_code'] ?? ($_SESSION['cart_coupon_code'] ?? '')));
$activeCoupon = null;
$couponDiscount = 0.0;
$couponMessage = '';
if ($selectedCouponCode !== '') {
    $activeCoupon = findActiveCouponPromotion($conn, $selectedCouponCode);
    if ($activeCoupon) {
        $couponDiscount = round(computePromotionDiscount($conn, $cartItems, $activeCoupon), 2);
        if ($couponDiscount > 0) {
            $_SESSION['cart_coupon_code'] = $selectedCouponCode;
            $couponMessage = 'Coupon applied: ' . (string)($activeCoupon['promotionName'] ?? $selectedCouponCode);
        } else {
            unset($_SESSION['cart_coupon_code']);
        }
    } else {
        unset($_SESSION['cart_coupon_code']);
    }
} else {
    unset($_SESSION['cart_coupon_code']);
}
$availableLoyaltyBalance = 0;
if ($isLoggedIn && $userId > 0) {
    try {
        $availableLoyaltyBalance = loyaltyGetCurrentBalance($conn, $userId);
    } catch (Exception $e) {
        $availableLoyaltyBalance = 0;
    }
}

$loyaltyEligibleSubtotal = max(0, round($cartTotal - $couponDiscount, 2));
$selectedLoyaltyPoints = ($isLoggedIn && $userId > 0)
    ? checkoutSanitizePositiveInt($_POST['loyalty_points'] ?? ($_SESSION['cart_loyalty_points_redeem'] ?? 0))
    : 0;
$loyaltyMessage = '';
$loyaltyRedemption = loyaltyBuildRedemptionPreview(
    $selectedLoyaltyPoints,
    $availableLoyaltyBalance,
    $loyaltyEligibleSubtotal
);

if ($selectedLoyaltyPoints > 0 && $loyaltyRedemption['error'] !== '' && $loyaltyAction !== 'apply') {
    if ((int)$loyaltyRedemption['max_points_allowed'] > 0 && $isLoggedIn && $userId > 0) {
        $selectedLoyaltyPoints = (int)$loyaltyRedemption['max_points_allowed'];
        checkoutStoreLoyaltySelection($userId, $selectedLoyaltyPoints);
        $loyaltyRedemption = loyaltyBuildRedemptionPreview($selectedLoyaltyPoints, $availableLoyaltyBalance, $loyaltyEligibleSubtotal);
        $loyaltyMessage = 'Loyalty redemption was adjusted to match your current cart.';
    } else {
        checkoutResetLoyaltySelection();
        $selectedLoyaltyPoints = 0;
        $loyaltyRedemption = loyaltyBuildRedemptionPreview(0, $availableLoyaltyBalance, $loyaltyEligibleSubtotal);
        $loyaltyMessage = 'Loyalty redemption was removed because this order is no longer eligible.';
    }
}

$loyaltyDiscount = (float)($loyaltyRedemption['discount_amount'] ?? 0);
$estimatedEarnedPoints = (($isLoggedIn && $userId > 0) || (!empty($_POST['create_account']) && $_POST['create_account'] === 'yes'))
    ? loyaltyCalculateEarnedPoints(max(0, round($loyaltyEligibleSubtotal - $loyaltyDiscount, 2)))
    : 0;

$countryCouriers = [
    'Cyprus' => ['akis_express' => 'Akis Express', 'boxnow' => 'BoxNow', 'acs' => 'ACS'],
    'Greece' => ['akis_express' => 'Akis Express', 'acs' => 'ACS', 'boxnow' => 'BoxNow'],
];
$shippingRatesByCountry = [
    'Cyprus' => ['standard' => 2.00, 'express' => 5.00],
    'Greece' => ['standard' => 4.00, 'express' => 10.00],
];
$fulfillmentModes = ['delivery', 'pickup'];
$shippingSpeeds = ['standard', 'express'];
$shippingModeLabels = ['delivery' => 'Deliver to my address', 'pickup' => 'Pickup from courier point'];
$freeShippingThreshold = 100.0;
$freeShippingEligible = $cartTotal >= $freeShippingThreshold;
$shippingDifference = max(0.0, $freeShippingThreshold - $cartTotal);
$availableCountries = array_keys($countryCouriers);
$defaultAddress = $isLoggedIn && $userId > 0 ? checkoutLoadDefaultAddress($conn, $userId) : ['address' => '', 'city' => '', 'postal_code' => '', 'country' => '', 'label' => '', 'source' => ''];
$hasDefaultAddressData = (
    trim((string)$defaultAddress['address']) !== '' ||
    trim((string)$defaultAddress['city']) !== '' ||
   trim((string)$defaultAddress['postal_code']) !== '' ||
    trim((string)$defaultAddress['country']) !== ''
);

$errors = [];
$error = '';
$formData = $_POST;
if (!isset($formData['coupon_code']) && $selectedCouponCode !== '') $formData['coupon_code'] = $selectedCouponCode;
if (!isset($formData['loyalty_points']) && $selectedLoyaltyPoints > 0) $formData['loyalty_points'] = (string)$selectedLoyaltyPoints;
if (!isset($formData['shipping_speed']) || !in_array((string)$formData['shipping_speed'], $shippingSpeeds, true)) $formData['shipping_speed'] = 'standard';
if (!isset($formData['fulfillment_mode']) || !in_array((string)$formData['fulfillment_mode'], $fulfillmentModes, true)) $formData['fulfillment_mode'] = 'delivery';
if (!isset($formData['shipping_label'])) $formData['shipping_label'] = '';
if (!isset($formData['shipping_country']) || trim((string)$formData['shipping_country']) === '') {
    $fallbackCountry = trim((string)($defaultAddress['country'] ?? '')) !== ''
        ? (string)$defaultAddress['country']
        : (string)($availableCountries[0] ?? 'Cyprus');
    $formData['shipping_country'] = checkoutNormalizeCountry($fallbackCountry, $availableCountries);
}

$selectedCountry = checkoutNormalizeCountry((string)($formData['shipping_country'] ?? ''), $availableCountries);
$formData['shipping_country'] = $selectedCountry;
$selectedSpeed = (string)$formData['shipping_speed'];
$countryCourierOptions = checkoutCountryCouriers($selectedCountry, $countryCouriers);
$selectedCourier = (string)($formData['courier'] ?? '');
if (!checkoutIsCourierAllowed($selectedCountry, $selectedCourier, $countryCouriers)) {
    $selectedCourier = (string)(array_key_first($countryCourierOptions) ?? '');
}
$formData['courier'] = $selectedCourier;
$displayShippingCost = checkoutShippingCost($selectedCountry, $selectedSpeed, (float)$cartTotal, (float)$freeShippingThreshold, $shippingRatesByCountry);
$displayTotal = max(0, ($cartTotal - $couponDiscount - $loyaltyDiscount) + $displayShippingCost);

// ========== FORM PROCESSING ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('Invalid CSRF token');
    }

    $isCouponOnlyPost = ($couponAction !== '');
    $isLoyaltyOnlyPost = ($loyaltyAction !== '');
    
    if ($isCouponOnlyPost) {
        // coupon handling
        if ($couponAction === 'remove') {
            unset($_SESSION['cart_coupon_code']);
            $selectedCouponCode = '';
            $activeCoupon = null;
            $couponDiscount = 0.0;
            $couponMessage = 'Coupon removed.';
            $_POST['coupon_code'] = '';
            $formData['coupon_code'] = '';
        } else {
            $formData['coupon_code'] = $selectedCouponCode;
            if ($selectedCouponCode === '') {
                unset($_SESSION['cart_coupon_code']);
                $errors['coupon_code'] = 'Enter a coupon code to apply.';
            } elseif (!$activeCoupon || $couponDiscount <= 0) {
                unset($_SESSION['cart_coupon_code']);
                $errors['coupon_code'] = 'Coupon code is invalid, expired, or not applicable to your cart.';
                $couponMessage = '';
            } else {
                $couponMessage = 'Coupon applied: ' . (string)($activeCoupon['promotionName'] ?? $selectedCouponCode);
            }
        }
        $loyaltyEligibleSubtotal = max(0, round($cartTotal - $couponDiscount, 2));
        if ($selectedLoyaltyPoints > 0) {
            $loyaltyRedemption = loyaltyBuildRedemptionPreview($selectedLoyaltyPoints, $availableLoyaltyBalance, $loyaltyEligibleSubtotal);
            if ($loyaltyRedemption['error'] !== '') {
                if ((int)$loyaltyRedemption['max_points_allowed'] > 0 && $isLoggedIn && $userId > 0) {
                    $selectedLoyaltyPoints = (int)$loyaltyRedemption['max_points_allowed'];
                    checkoutStoreLoyaltySelection($userId, $selectedLoyaltyPoints);
                    $loyaltyRedemption = loyaltyBuildRedemptionPreview($selectedLoyaltyPoints, $availableLoyaltyBalance, $loyaltyEligibleSubtotal);
                    $loyaltyMessage = 'Loyalty redemption was adjusted to match your current cart.';
                } else {
                    checkoutResetLoyaltySelection();
                    $selectedLoyaltyPoints = 0;
                    $loyaltyRedemption = loyaltyBuildRedemptionPreview(0, $availableLoyaltyBalance, $loyaltyEligibleSubtotal);
                    $loyaltyMessage = 'Loyalty redemption was removed because this order is no longer eligible.';
                }
            }
        }
        $loyaltyDiscount = (float)($loyaltyRedemption['discount_amount'] ?? 0);
        $estimatedEarnedPoints = (($isLoggedIn && $userId > 0) || (!empty($_POST['create_account']) && $_POST['create_account'] === 'yes'))
            ? loyaltyCalculateEarnedPoints(max(0, round($loyaltyEligibleSubtotal - $loyaltyDiscount, 2)))
            : 0;
        if ($selectedLoyaltyPoints > 0 && !isset($formData['loyalty_points'])) $formData['loyalty_points'] = (string)$selectedLoyaltyPoints;
        $displayTotal = max(0, ($cartTotal - $couponDiscount - $loyaltyDiscount) + $displayShippingCost);
    } elseif ($isLoyaltyOnlyPost) {
        // loyalty handling
        $formData['loyalty_points'] = trim((string)($_POST['loyalty_points'] ?? ''));
        if (!$isLoggedIn || $userId <= 0) {
            checkoutResetLoyaltySelection();
            $selectedLoyaltyPoints = 0;
            $loyaltyRedemption = loyaltyBuildRedemptionPreview(0, 0, $loyaltyEligibleSubtotal);
            $loyaltyDiscount = 0.0;
            $errors['loyalty_points'] = 'Please log in to redeem loyalty points.';
        } elseif ($loyaltyAction === 'remove') {
            checkoutResetLoyaltySelection();
            $selectedLoyaltyPoints = 0;
            $loyaltyRedemption = loyaltyBuildRedemptionPreview(0, $availableLoyaltyBalance, $loyaltyEligibleSubtotal);
            $loyaltyDiscount = 0.0;
            $loyaltyMessage = 'Loyalty points removed.';
            $_POST['loyalty_points'] = '';
            $formData['loyalty_points'] = '';
        } else {
            $selectedLoyaltyPoints = checkoutSanitizePositiveInt($_POST['loyalty_points'] ?? 0);
            $formData['loyalty_points'] = $selectedLoyaltyPoints > 0 ? (string)$selectedLoyaltyPoints : '';
            $loyaltyRedemption = loyaltyBuildRedemptionPreview($selectedLoyaltyPoints, $availableLoyaltyBalance, $loyaltyEligibleSubtotal);
            if ($selectedLoyaltyPoints <= 0) {
                checkoutResetLoyaltySelection();
                $loyaltyDiscount = 0.0;
                $errors['loyalty_points'] = 'Enter how many loyalty points you want to redeem.';
            } elseif ($loyaltyRedemption['error'] !== '') {
                checkoutResetLoyaltySelection();
                $loyaltyDiscount = 0.0;
                $errors['loyalty_points'] = (string)$loyaltyRedemption['error'];
            } else {
                checkoutStoreLoyaltySelection($userId, (int)$loyaltyRedemption['points_to_redeem']);
                $selectedLoyaltyPoints = (int)$loyaltyRedemption['points_to_redeem'];
                $loyaltyDiscount = (float)$loyaltyRedemption['discount_amount'];
                $loyaltyMessage = 'Using ' . $selectedLoyaltyPoints . ' points for €' . number_format($loyaltyDiscount, 2) . ' off.';
            }
        }
        $estimatedEarnedPoints = (($isLoggedIn && $userId > 0) || (!empty($_POST['create_account']) && $_POST['create_account'] === 'yes'))
            ? loyaltyCalculateEarnedPoints(max(0, round($loyaltyEligibleSubtotal - $loyaltyDiscount, 2)))
            : 0;
        $displayTotal = max(0, ($cartTotal - $couponDiscount - $loyaltyDiscount) + $displayShippingCost);
    } else {
        // Full checkout submission
        $required = [
            'shipping_address' => 'Shipping address',
            'shipping_city' => 'City',
            'shipping_postal_code' => 'Postal code',
            'shipping_country' => 'Country',
            'courier' => 'Courier',
            'fulfillment_mode' => 'Delivery method',
            'payment_method' => 'Payment method',
        ];
        foreach ($required as $field => $label) {
            if (empty($_POST[$field])) $errors[$field] = "$label is required";
        }

        $allowedPaymentMethods = ['stripe', 'paypal'];
        $paymentMethod = trim((string)($_POST['payment_method'] ?? ''));
        if ($paymentMethod !== '' && !in_array($paymentMethod, $allowedPaymentMethods, true)) {
            $errors['payment_method'] = 'Selected payment method is not available.';
        }

        $shippingCountry = checkoutNormalizeCountry((string)($_POST['shipping_country'] ?? ''), $availableCountries);
        $_POST['shipping_country'] = $shippingCountry;
        $formData['shipping_country'] = $shippingCountry;

        $shippingSpeed = trim((string)($_POST['shipping_speed'] ?? 'standard'));
        if (!in_array($shippingSpeed, $shippingSpeeds, true)) {
            $errors['shipping_speed'] = 'Please select a valid shipping speed.';
        }

        $fulfillmentMode = trim((string)($_POST['fulfillment_mode'] ?? 'delivery'));
        if (!in_array($fulfillmentMode, $fulfillmentModes, true)) {
            $errors['fulfillment_mode'] = 'Please select delivery or pickup.';
        }

        $courier = trim((string)($_POST['courier'] ?? ''));
        if (!checkoutIsCourierAllowed($shippingCountry, $courier, $countryCouriers)) {
            $errors['courier'] = 'Selected courier is not available for the chosen country.';
        }

        // Validate pickup point selection for pickup mode
        if ($fulfillmentMode === 'pickup') {
            $pickupPointField = '';
            if ($courier === 'akis_express') {
                $pickupPointField = 'akis_pickup_point';
            } elseif ($courier === 'acs') {
                $pickupPointField = 'acs_pickup_point';
            } elseif ($courier === 'boxnow') {
                $pickupPointField = 'boxnow_pickup_point';
            }
            
            if ($pickupPointField && empty($_POST[$pickupPointField])) {
                $errors[$pickupPointField] = 'Please select a pickup point for ' . htmlspecialchars($courier);
            } elseif ($pickupPointField && !empty($_POST[$pickupPointField])) {
                $pickupData = json_decode($_POST[$pickupPointField], true);
                if ($pickupData && isset($pickupData['name'])) {
                    $_SESSION['selected_pickup_point'] = $pickupData;
                    $_POST['shipping_address'] = $pickupData['address'];
                    $_POST['shipping_city'] = $pickupData['town'] ?? $pickupData['city'];
                    $_POST['shipping_postal_code'] = $pickupData['postal_code'] ?? '';
                }
            }
        }

        if (!$isLoggedIn) {
            if (empty($_POST['full_name'])) {
                $errors['full_name'] = 'Full name is required';
            } elseif (str_word_count(trim($_POST['full_name'])) < 2) {
                $errors['full_name'] = 'Enter first and last name';
            }
            if (empty($_POST['email'])) {
                $errors['email'] = 'Email is required';
            } elseif (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Invalid email format';
            }
            if (empty($_POST['phone'])) {
                $errors['phone'] = 'Phone is required';
            }
        }

        if (!empty($_POST['shipping_postal_code'])) {
            $postal = preg_replace('/[^0-9]/', '', (string)$_POST['shipping_postal_code']);
            $country = $shippingCountry;
            $isPostalValid = false;
            $postalError = 'Postal code is invalid.';
            if ($country === 'Cyprus') {
                $isPostalValid = (bool)preg_match('/^[0-9]{4}$/', $postal);
                $postalError = 'Cyprus postal code must be exactly 4 digits.';
            } elseif ($country === 'Greece') {
                $isPostalValid = (bool)preg_match('/^[0-9]{5}$/', $postal);
                $postalError = 'Greece postal code must be exactly 5 digits.';
            }
            if (!$isPostalValid) $errors['shipping_postal_code'] = $postalError;
            $_POST['shipping_postal_code'] = $postal;
            $formData['shipping_postal_code'] = $postal;
        }

        if (empty($_POST['accept_terms'])) {
            $errors['accept_terms'] = 'You must accept Terms & Conditions';
        }
        if ($selectedCouponCode !== '' && (!$activeCoupon || $couponDiscount <= 0)) {
            $errors['coupon_code'] = 'Coupon code is invalid, expired, or not applicable to your cart.';
        }
        if (!empty($_POST['loyalty_points'])) {
            $selectedLoyaltyPoints = checkoutSanitizePositiveInt($_POST['loyalty_points']);
            $formData['loyalty_points'] = (string)$selectedLoyaltyPoints;
            $loyaltyRedemption = loyaltyBuildRedemptionPreview($selectedLoyaltyPoints, $availableLoyaltyBalance, $loyaltyEligibleSubtotal);
            $loyaltyDiscount = (float)($loyaltyRedemption['discount_amount'] ?? 0);
        }
        if ($selectedLoyaltyPoints > 0) {
            if (!$isLoggedIn || $userId <= 0) {
                $errors['loyalty_points'] = 'Please log in to redeem loyalty points.';
            } elseif ($loyaltyRedemption['error'] !== '') {
                $errors['loyalty_points'] = (string)$loyaltyRedemption['error'];
            }
        }

        if (empty($errors)) {
            try {
                $conn->begin_transaction();

                $shippingCost = checkoutShippingCost($shippingCountry, $shippingSpeed, (float)$cartTotal, (float)$freeShippingThreshold, $shippingRatesByCountry);
                $freeShippingFlag = $shippingCost <= 0 ? 1 : 0;
                $shippingMessage = $freeShippingFlag ? "Free Shipping Applied!" : "Add €" . number_format($shippingDifference, 2) . " more for free delivery!";

                $lockedLoyaltyBalance = ($isLoggedIn && $userId > 0) ? loyaltyGetCurrentBalance($conn, $userId, true) : 0;
                $finalLoyaltyRedemption = loyaltyBuildRedemptionPreview($selectedLoyaltyPoints, $lockedLoyaltyBalance, $loyaltyEligibleSubtotal);
                if ($selectedLoyaltyPoints > 0 && $finalLoyaltyRedemption['error'] !== '') {
                    throw new RuntimeException((string)$finalLoyaltyRedemption['error']);
                }

                $loyaltyDiscount = (float)($finalLoyaltyRedemption['discount_amount'] ?? 0);
                $combinedDiscountTotal = round($couponDiscount + $loyaltyDiscount, 2);
                $loyaltyEarnEligibleAmount = max(0, round($loyaltyEligibleSubtotal - $loyaltyDiscount, 2));
                $earnedPoints = loyaltyCalculateEarnedPoints($loyaltyEarnEligibleAmount);
                $totalAmount = max(0, ($cartTotal - $combinedDiscountTotal) + $shippingCost);

                // Stock validation
                function productHasVariationRows(mysqli $conn, int $productId): bool {
                    static $cache = [];
                    if (array_key_exists($productId, $cache)) {
                        return $cache[$productId];
                    }
                    $stmt = $conn->prepare("SELECT 1 FROM product_variations WHERE productID = ? LIMIT 1");
                    if (!$stmt) return false;
                    $stmt->bind_param("i", $productId);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    $cache[$productId] = (bool)$row;
                    return $cache[$productId];
                }
                
                $stockErrors = [];
                $productCache = [];

                foreach ($cartItems as $item) {
                    $productId = (int)($item['productID'] ?? $item['product_id'] ?? $item['product']['id'] ?? 0);
                    $quantity = (int)($item['quantity'] ?? 1);
                    if ($productId <= 0) continue;

                    if (!isset($productCache[$productId])) {
                        $stmt = $conn->prepare("SELECT inventory, nameEN, hasVariants FROM products WHERE productID = ? FOR UPDATE");
                        $stmt->bind_param("i", $productId);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $productCache[$productId] = $result->fetch_assoc();
                        $stmt->close();
                    }
                    $productData = $productCache[$productId];
                    if (!$productData) {
                        $stockErrors[] = "Product #{$productId} not found.";
                        continue;
                    }

                    $hasVariants = (int)$productData['hasVariants'];
                    $hasVariationRows = productHasVariationRows($conn, $productId);
                    $productName = $productData['nameEN'] ?? "Product #{$productId}";
                    $available = (int)$productData['inventory'];

                    $variationId = null;
                    if (isset($item['variation']['variationID'])) {
                        $variationId = (int)$item['variation']['variationID'];
                    } elseif (isset($item['variation_id'])) {
                        $variationId = (int)$item['variation_id'];
                    }

                    if ($hasVariants === 1 && $hasVariationRows) {
                        if ($variationId && $variationId > 0) {
                            $stmt = $conn->prepare("SELECT stock FROM product_variations WHERE variationID = ? FOR UPDATE");
                            $stmt->bind_param("i", $variationId);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            $row = $result->fetch_assoc();
                            if ($row) {
                                $available = (int)($row['stock'] ?? 0);
                            } else {
                                $available = (int)$productData['inventory'];
                            }
                            $stmt->close();
                        } else {
                            $available = 0;
                        }
                    }

                    if ($available < $quantity) {
                        $stockErrors[] = "Insufficient stock for {$productName}: ordered {$quantity}, available {$available}.";
                    }
                }

                if (!empty($stockErrors)) {
                    throw new RuntimeException(implode(', ', $stockErrors));
                }

                $placed = placeOrder($conn, [
                    'payment_confirmed' => false,
                    'items' => $cartItems,
                    'user_id' => ($isLoggedIn && $userId > 0) ? $userId : NULL,
                    'is_guest' => $isLoggedIn ? 0 : 1,
                    'email' => $isLoggedIn ? $userEmail : trim((string)($_POST['email'] ?? '')),
                    'customer_name' => $isLoggedIn ? (string)$userFullName : trim((string)($_POST['full_name'] ?? 'Customer')),
                    'order_status' => 'pending',
                    'payment_status' => 'pending',
                    'payment_method' => trim((string)($_POST['payment_method'] ?? 'stripe')),
                    'subtotal' => $cartTotal,
                    'discount_total' => $combinedDiscountTotal,
                    'shipping_cost' => $shippingCost,
                    'total_amount' => $totalAmount,
                    'shipping_address' => trim((string)($_POST['shipping_address'] ?? '')),
                    'shipping_city' => trim((string)($_POST['shipping_city'] ?? '')),
                    'shipping_postal_code' => trim((string)($_POST['shipping_postal_code'] ?? '')),
                    'shipping_country' => $shippingCountry,
                    'shipping_label' => trim((string)($_POST['shipping_label'] ?? '')),
                    'courier' => $courier,
                    'shipping_priority' => $shippingSpeed,
                    'fulfillment_mode' => $fulfillmentMode,
                ]);
                $orderId = (int)$placed['order_id'];
                $orderNumber = (string)$placed['order_number'];

                // Create account for guest if checkbox checked
                if (!$isLoggedIn && isset($_POST['create_account']) && $_POST['create_account'] === 'yes') {
                    $guestEmail = trim((string)($_POST['email'] ?? ''));
                    $guestFullName = trim((string)($_POST['full_name'] ?? ''));
                    $guestPhone = trim((string)($_POST['phone'] ?? ''));

                    $checkUser = $conn->prepare("SELECT userID FROM users WHERE email = ?");
                    $checkUser->bind_param("s", $guestEmail);
                    $checkUser->execute();
                    $checkUser->store_result();
                    if ($checkUser->num_rows === 0) {
                        $tempPassword = bin2hex(random_bytes(8));
                        $hashedPassword = password_hash($tempPassword, PASSWORD_DEFAULT);
                        
                        $shippingAddress = trim((string)($_POST['shipping_address'] ?? ''));
                        $shippingCity = trim((string)($_POST['shipping_city'] ?? ''));
                        $shippingPostalCode = trim((string)($_POST['shipping_postal_code'] ?? ''));

                        $insertUser = $conn->prepare("INSERT INTO users (full_name, email, phone, password, address, city, postcode, country, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                        $insertUser->bind_param("ssssssss", $guestFullName, $guestEmail, $guestPhone, $hashedPassword, $shippingAddress, $shippingCity, $shippingPostalCode, $shippingCountry);
                        if ($insertUser->execute()) {
                            $createdAccountUserId = $conn->insert_id;

                            $linkOrder = $conn->prepare("UPDATE orders SET userID = ?, is_guest = 0 WHERE orderID = ?");
                            $linkOrder->bind_param("ii", $createdAccountUserId, $orderId);
                            $linkOrder->execute();

                            if (checkoutTableExists($conn, 'user_addresses')) {
                                $insertAddr = $conn->prepare("INSERT INTO user_addresses (user_id, label, country, city, address, postcode, is_default, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW())");
                                $defaultLabel = 'Home';
                                $insertAddr->bind_param("isssss", $createdAccountUserId, $defaultLabel, $shippingCountry, $shippingCity, $shippingAddress, $shippingPostalCode);
                                $insertAddr->execute();
                            }

                            $_SESSION['user'] = [
                                'id' => $createdAccountUserId,
                                'email' => $guestEmail,
                                'full_name' => $guestFullName
                            ];
                            $_SESSION['temp_password'] = $tempPassword;
                        }
                    }
                    $checkUser->close();
                }

                $_SESSION['checkout_data'] = [
                    'order_id'            => $orderId,
                    'payment_method'      => $paymentMethod,
                    'courier'             => $courier,
                    'shipping_speed'      => $shippingSpeed,
                    'shipping_address'    => trim((string)($_POST['shipping_address'] ?? '')),
                    'shipping_city'       => trim((string)($_POST['shipping_city'] ?? '')),
                    'shipping_postal_code'=> trim((string)($_POST['shipping_postal_code'] ?? '')),
                    'shipping_country'    => $shippingCountry,
                    'total_amount'        => $totalAmount,
                    'order_number'        => $orderNumber,
                    'email'               => $isLoggedIn ? $userEmail : trim((string)($_POST['email'] ?? '')),
                    'full_name'           => $isLoggedIn ? (string)$userFullName : trim((string)($_POST['full_name'] ?? 'Customer')),
                ];

                if ($selectedLoyaltyPoints > 0 && $userId > 0) {
                    $loyaltyOutcome = loyaltyApplyOrderTransactions(
                        $conn,
                        $userId,
                        $orderId,
                        (int)($finalLoyaltyRedemption['points_to_redeem'] ?? 0),
                        $loyaltyDiscount,
                        $earnedPoints
                    );
                }

                $conn->commit();

                if ($paymentMethod === 'stripe') {
                    $paymentPage = 'payment_stripe.php';
                } else {
                    $paymentPage = 'payment_paypal.php';
                }
                header('Location: ' . $project . '/' . $paymentPage . '?order_id=' . $orderId . '&total=' . urlencode($totalAmount));
                exit;

            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Order failed: ' . $e->getMessage();
                error_log("Checkout error: " . $e->getMessage());
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Checkout - <?= htmlspecialchars($system_title) ?></title>
    <link rel="stylesheet" href="../assets/styling/styles.css">
    <link rel="stylesheet" href="../assets/styling/header.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        /* All your existing CSS styles (keep as is) */
        .btn-secondary {
            background: #f0eaff;
            border: 1px solid #8a4dd6;
            color: #4a2a7a;
            padding: 8px 16px;
            border-radius: 40px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-secondary:hover {
            background: #e1d5f5;
            border-color: #6b3a9e;
        }
        .courier-map-frame {
            height: 300px;
            width: 100%;
            margin-top: 15px;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #e6dff2;
        }
        .courier-map-points {
            margin-top: 15px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .courier-map-points span {
            display: inline-block;
            background: #f5f0ff;
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 12px;
            margin: 0;
            border: 1px solid #e0d4f0;
            cursor: pointer;
            transition: all 0.2s;
        }
        .courier-map-points span:hover {
            background: #e1d5f5;
            transform: scale(1.02);
        }
        .courier-map-points span.is-closest {
            background: #8a4dd6;
            color: white;
            border-color: #8a4dd6;
        }
        .pickup-point-select {
            width: 100%;
            padding: 10px;
            border: 1px solid #e0d4f0;
            border-radius: 8px;
            background: white;
            font-size: 14px;
        }
        .loading-spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #8a4dd6;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-right: 8px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .checkout-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        .checkout-grid {
        display: grid;
        grid-template-columns: 1fr 380px;
        gap: 30px;
        align-items: start; /* ensures both columns start at the top */
        }
        @media (max-width: 768px) {
            .checkout-grid {
                grid-template-columns: 1fr;
            }
        }
        .checkout-form fieldset {
            border: 1px solid #e0d4f0;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            background: white;
        }
        .checkout-form legend {
            font-weight: bold;
            padding: 0 10px;
            color: #4a2a7a;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #e0d4f0;
            border-radius: 8px;
            font-size: 14px;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        .form-options {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        .form-options-column {
            flex-direction: column;
            gap: 10px;
        }
        .option-label {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }
        .order-summary {
            background: #f9f7fc;
            border-radius: 12px;
            padding: 20px;
            position: sticky;
            top: 20px;
        }
        .order-item {
            padding: 10px 0;
            border-bottom: 1px solid #e0d4f0;
        }
        .order-item-main {
            display: flex;
            justify-content: space-between;
        }
        .order-item-addons {
            font-size: 12px;
            color: #666;
            margin-top: 4px;
        }
        .summary-divider {
            margin: 15px 0;
            border-color: #e0d4f0;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
        }
        .summary-row-total {
            font-weight: bold;
            font-size: 18px;
            border-top: 2px solid #e0d4f0;
            margin-top: 10px;
            padding-top: 15px;
        }
        .btn-primary {
            background: #8a4dd6;
            color: white;
            border: none;
            padding: 15px;
            border-radius: 40px;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            width: 100%;
            margin-top: 20px;
            transition: background 0.2s;
        }
        .btn-primary:hover {
            background: #6b3a9e;
        }
        .guest-notice {
            background: #e8f0fe;
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }
        .free-shipping-notice {
            background: #e6f7e6;
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
            color: #2e7d32;
        }
        .checkout-error {
            background: #ffebee;
            color: #c62828;
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .terms-row {
            margin: 20px 0;
        }
        .courier-map-card {
            margin-top: 20px;
            background: #f9f7fc;
            border-radius: 12px;
            padding: 15px;
        }
        .courier-map-card h3 {
            margin-top: 0;
            margin-bottom: 10px;
            color: #4a2a7a;
        }
        .error {
            color: #dc3545;
            font-size: 12px;
            margin-top: 4px;
            display: block;
        }
        .form-helper {
            font-size: 12px;
            color: #666;
            margin-top: 4px;
            display: block;
        }
        .coupon-row {
            display: flex;
            gap: 8px;
            margin-bottom: 8px;
        }
        .coupon-row input {
            flex: 1;
        }
        .coupon-actions {
            display: flex;
            gap: 8px;
        }
        .btn-inline {
            background: none;
            border: none;
            color: #8a4dd6;
            cursor: pointer;
            font-size: 12px;
            padding: 4px 8px;
            text-decoration: underline;
        }
        .btn-inline:hover {
            color: #6b3a9e;
        }
        .btn-apply {
            background: #8a4dd6;
            color: white;
            text-decoration: none;
            border-radius: 20px;
            padding: 4px 12px;
        }
        .btn-apply:hover {
            background: #6b3a9e;
            color: white;
        }
    </style>
</head>
<body class="site-page">
<?php
$headerPath = __DIR__ . '/../include/header.php';
if (file_exists($headerPath)) {
    $activePage = 'checkout';
    include $headerPath;
}
?>
<div class="checkout-container">
    <h1 class="checkout-title">Checkout</h1>
    <?php if ($shippingDifference > 0 && $cartTotal < $freeShippingThreshold): ?>
        <div class="free-shipping-notice">Add &euro;<?= number_format($shippingDifference,2) ?> more for FREE Delivery!</div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="checkout-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <div class="checkout-grid">
        <div class="checkout-form">
            <?php if (!$isLoggedIn): ?>
                <div class="guest-notice"><strong>Guest checkout</strong> - <a href="<?= $project ?>/authentication/login.php">Login</a> for faster checkout.</div>
            <?php endif; ?>
            <form method="post" id="checkoutForm">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                <?php if (!$isLoggedIn): ?>
                <fieldset>
                    <legend>Contact</legend>
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="full_name" value="<?= htmlspecialchars($formData['full_name']??'') ?>" required>
                        <?php if (isset($errors['full_name'])): ?><span class="error"><?= $errors['full_name'] ?></span><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label>Email *</label>
                        <input type="email" name="email" value="<?= htmlspecialchars($formData['email']??'') ?>" required>
                        <?php if (isset($errors['email'])): ?><span class="error"><?= $errors['email'] ?></span><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label>Phone *</label>
                        <input type="tel" name="phone" value="<?= htmlspecialchars($formData['phone']??'') ?>" required>
                        <?php if (isset($errors['phone'])): ?><span class="error"><?= $errors['phone'] ?></span><?php endif; ?>
                    </div>
                </fieldset>
                <?php endif; ?>

                <fieldset>
                    <legend>Shipping</legend>
                    <?php if ($isLoggedIn): ?>
                    <div style="position: relative; margin-bottom: 15px;">
                        <div style="position: absolute; top: -10px; right: 0;">
                            <label class="option-label" style="background: #f9f7fc; padding: 5px 12px; border-radius: 20px; font-size: 13px;">
                                <input type="checkbox" id="autofill-checkbox" name="autofill_checkbox" value="1" <?= ($hasDefaultAddressData && ($_POST['autofill_checkbox'] ?? '1') === '1') ? 'checked' : '' ?>>
                                Use my default address
                            </label>
                        </div>
                        <?php if ($hasDefaultAddressData): ?>
                            <span class="form-helper" style="display: block; margin-top: 5px;">Automatically fill shipping details from your saved address.</span>
                        <?php else: ?>
                            <span class="form-helper" style="display: block; margin-top: 5px;">No saved address found. Please fill the fields below.</span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label>Address *</label>
                        <input type="text" id="shipping_address" name="shipping_address" value="<?= htmlspecialchars($formData['shipping_address']??'') ?>" required>
                        <?php if (isset($errors['shipping_address'])): ?><span class="error"><?= $errors['shipping_address'] ?></span><?php endif; ?>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>City *</label>
                            <input type="text" id="shipping_city" name="shipping_city" value="<?= htmlspecialchars($formData['shipping_city']??'') ?>" required>
                            <?php if (isset($errors['shipping_city'])): ?><span class="error"><?= $errors['shipping_city'] ?></span><?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label>Postal Code *</label>
                            <input type="text" id="shipping_postal_code" name="shipping_postal_code" value="<?= htmlspecialchars($formData['shipping_postal_code']??'') ?>" autocomplete="postal-code" inputmode="numeric" pattern="[0-9]{4,5}" maxlength="5" required>
                            <?php if (isset($errors['shipping_postal_code'])): ?><span class="error"><?= $errors['shipping_postal_code'] ?></span><?php endif; ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Country *</label>
                        <select id="shipping_country" name="shipping_country" required>
                            <option value="">Select</option>
                            <?php foreach ($availableCountries as $countryOption): ?>
                                <option value="<?= htmlspecialchars($countryOption) ?>" <?= ($formData['shipping_country']??'')===$countryOption ? 'selected' : '' ?>><?= htmlspecialchars($countryOption) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['shipping_country'])): ?><span class="error"><?= $errors['shipping_country'] ?></span><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label>Label (optional)</label>
                        <input type="text" id="shipping_label" name="shipping_label" value="<?= htmlspecialchars($formData['shipping_label']??'') ?>" placeholder="Home, Office, Gift address...">
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Shipping Method</legend>
                    <div class="form-group">
                        <label>Delivery Option *</label>
                        <div class="form-options">
                            <?php foreach ($shippingModeLabels as $modeKey => $modeLabel): ?>
                                <label class="option-label"><input type="radio" name="fulfillment_mode" value="<?= htmlspecialchars($modeKey) ?>" <?= (($formData['fulfillment_mode'] ?? 'delivery') === $modeKey) ? 'checked' : '' ?>> <?= htmlspecialchars($modeLabel) ?></label>
                            <?php endforeach; ?>
                        </div>
                        <?php if (isset($errors['fulfillment_mode'])): ?><span class="error"><?= $errors['fulfillment_mode'] ?></span><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label>Courier *</label>
                        <select id="courier_select" name="courier" required>
                            <option value="">Select</option>
                            <?php foreach ($countryCourierOptions as $courierCode => $courierName): ?>
                                <option value="<?= htmlspecialchars($courierCode) ?>" <?= ($formData['courier']??'')===$courierCode ? 'selected' : '' ?>><?= htmlspecialchars($courierName) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="form-helper">Couriers update automatically based on the selected country.</span>
                        <?php if (isset($errors['courier'])): ?><span class="error"><?= $errors['courier'] ?></span><?php endif; ?>
                    </div>
                    
                    <!-- Pickup point wrappers -->
                    <div class="form-group" id="akis-point-wrapper" style="display:none;">
                        <label>Select Akis Express Pickup Point *</label>
                        <select id="akis_pickup_point" name="akis_pickup_point" class="pickup-point-select">
                            <option value="">-- Select Pickup Point --</option>
                        </select>
                        <span class="form-helper">The closest pickup point to your address will be selected automatically</span>
                        <?php if (isset($errors['akis_pickup_point'])): ?><span class="error"><?= $errors['akis_pickup_point'] ?></span><?php endif; ?>
                    </div>
                    <div class="form-group" id="acs-point-wrapper" style="display:none;">
                        <label>Select ACS Pickup Point *</label>
                        <select id="acs_pickup_point" name="acs_pickup_point" class="pickup-point-select">
                            <option value="">-- Select Pickup Point --</option>
                        </select>
                        <span class="form-helper">The closest pickup point to your address will be selected automatically</span>
                        <?php if (isset($errors['acs_pickup_point'])): ?><span class="error"><?= $errors['acs_pickup_point'] ?></span><?php endif; ?>
                    </div>
                    <div class="form-group" id="boxnow-point-wrapper" style="display:none;">
                        <label>Select BoxNow Locker *</label>
                        <select id="boxnow_pickup_point" name="boxnow_pickup_point" class="pickup-point-select">
                            <option value="">-- Select Locker --</option>
                        </select>
                        <span class="form-helper">The closest locker to your address will be selected automatically</span>
                        <?php if (isset($errors['boxnow_pickup_point'])): ?><span class="error"><?= $errors['boxnow_pickup_point'] ?></span><?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label>Speed</label>
                        <div class="form-options">
                            <label class="option-label"><input type="radio" name="shipping_speed" value="standard" <?= ($formData['shipping_speed']??'standard')=='standard'?'checked':'' ?>> Standard <span id="standard-cost-label"></span></label>
                            <label class="option-label"><input type="radio" name="shipping_speed" value="express" <?= ($formData['shipping_speed']??'')=='express'?'checked':'' ?>> Express <span id="express-cost-label"></span></label>
                        </div>
                        <?php if (isset($errors['shipping_speed'])): ?><span class="error"><?= $errors['shipping_speed'] ?></span><?php endif; ?>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Payment</legend>
                    <div class="form-options form-options-column">
                        <label class="option-label"><input type="radio" name="payment_method" value="stripe" <?= ($formData['payment_method']??'stripe')=='stripe'?'checked':'' ?> required> Credit Card (Stripe)</label>
                        <label class="option-label"><input type="radio" name="payment_method" value="paypal" <?= ($formData['payment_method']??'')=='paypal'?'checked':'' ?>> PayPal</label>
                    </div>
                    <?php if (isset($errors['payment_method'])): ?><span class="error"><?= $errors['payment_method'] ?></span><?php endif; ?>
                </fieldset>

                <fieldset>
                    <legend>Coupon</legend>
                    <div class="form-group">
                        <label>Coupon Code</label>
                        <div class="coupon-row">
                            <input type="text" name="coupon_code" value="<?= htmlspecialchars($formData['coupon_code'] ?? '') ?>" placeholder="Enter coupon code (if available)">
                        </div>
                        <div class="coupon-actions">
                            <button type="submit" name="coupon_action" value="apply" class="btn-inline btn-apply" formnovalidate>Apply</button>
                            <button type="submit" name="coupon_action" value="remove" class="btn-inline" formnovalidate>Remove</button>
                        </div>
                        <?php if ($couponMessage !== ''): ?><span class="form-helper"><?= htmlspecialchars($couponMessage) ?></span><?php endif; ?>
                        <?php if (isset($errors['coupon_code'])): ?><span class="error"><?= $errors['coupon_code'] ?></span><?php endif; ?>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Loyalty Program</legend>
                    <?php if ($isLoggedIn && $userId > 0): ?>
                    <div class="form-group">
                        <label>Your loyalty balance</label>
                        <span class="form-helper">
                            <strong><?= number_format($availableLoyaltyBalance) ?> points</strong>
                            worth about €<?= number_format($availableLoyaltyBalance * 0.01, 2) ?>.
                            Earn 1 point per €1 spent and redeem 100 points for every €1.00 off.
                        </span>
                    </div>
                    <div class="form-group">
                        <label>Redeem points</label>
                        <div class="coupon-row">
                            <input type="number" min="0" step="1" name="loyalty_points" value="<?= htmlspecialchars($formData['loyalty_points'] ?? '') ?>" placeholder="Enter points to redeem">
                        </div>
                        <div class="coupon-actions">
                            <button type="submit" name="loyalty_action" value="apply" class="btn-inline btn-apply" formnovalidate>Apply</button>
                            <button type="submit" name="loyalty_action" value="remove" class="btn-inline" formnovalidate>Remove</button>
                        </div>
                        <span class="form-helper">
                            Max usable on this order: <strong><?= number_format((int)($loyaltyRedemption['max_points_allowed'] ?? 0)) ?> points</strong>
                            for up to €<?= number_format(((int)($loyaltyRedemption['max_points_allowed'] ?? 0)) * 0.01, 2) ?> off.
                        </span>
                        <span class="form-helper">
                            Estimated points after this purchase: <strong><?= number_format($estimatedEarnedPoints) ?> points</strong>.
                        </span>
                        <?php if ($loyaltyMessage !== ''): ?><span class="form-helper"><?= htmlspecialchars($loyaltyMessage) ?></span><?php endif; ?>
                        <?php if (isset($errors['loyalty_points'])): ?><span class="error"><?= $errors['loyalty_points'] ?></span><?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="form-group">
                        <label>Loyalty rewards</label>
                        <span class="form-helper">Sign in to redeem points now, or create an account during checkout to start earning points from this order.</span>
                    </div>
                    <?php endif; ?>
                </fieldset>

                <?php if (!$isLoggedIn): ?>
<div class="form-group" style="margin: 15px 0;">
    <label class="option-label">
        <input type="checkbox" name="create_account" value="yes" checked>
        <strong>✓ Create an account for me</strong> (no password needed – you can set it later)
    </label>
    <span class="form-helper">We'll create an account using your email and shipping details.</span>
</div>
<?php endif; ?>

                <div class="terms-row">
                    <label class="terms-label"><input type="checkbox" name="accept_terms" value="yes" <?= isset($formData['accept_terms'])?'checked':'' ?> required> I accept Terms & Privacy</label>
                    <?php if (isset($errors['accept_terms'])): ?><span class="error"><?= $errors['accept_terms'] ?></span><?php endif; ?>
                </div>

                <button type="submit" class="btn-primary" id="placeOrderBtn">Place Order &bull; &euro;<span id="placeOrderTotal"><?= number_format($displayTotal,2) ?></span></button>
            </form>
        </div>

        <div>
            <div class="order-summary">
                <h2>Your Order (<?= $cartCount ?>)</h2>
                <?php foreach ($cartItems as $item): 
                    $name = $item['name'] ?? $item['product']['nameEN'] ?? $item['product']['nameGR'] ?? 'Product';
                    $basePrice = (float)($item['product']['basePrice'] ?? 0);
                    $addonsCost = (float)($item['addons']['addonsCost'] ?? 0);
                    if ($addonsCost <= 0) {
                        if (!empty($item['addons']['giftWrapping'])) $addonsCost += 2.0;
                        if (!empty($item['addons']['giftBagFlag'])) $addonsCost += 1.5;
                    }
                    $price = (float)($item['price'] ?? $item['pricing']['unitTotal'] ?? ($basePrice + $addonsCost));
                    $qty = (int)($item['quantity'] ?? 1);
                    $giftBits = [];
                    if (!empty($item['addons']['giftWrapping'])) $giftBits[] = 'Gift Wrapping (+€2.00)';
                    if (!empty($item['addons']['giftBagFlag'])) $giftBits[] = 'Gift Bag (+€1.50)';
                    if (!empty($item['addons']['giftMessage'])) $giftBits[] = 'Note: ' . (string)$item['addons']['giftMessage'];
                ?>
                <div class="order-item">
                    <div class="order-item-main">
                        <span><?= htmlspecialchars($name) ?> x<?= $qty ?></span>
                        <span>&euro;<?= number_format($price*$qty,2) ?></span>
                    </div>
                    <?php if (!empty($giftBits)): ?>
                    <div class="order-item-addons">
                        <?= htmlspecialchars(implode(' | ', $giftBits)) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <hr class="summary-divider">
                <div class="summary-row"><span>Subtotal</span><span>&euro;<span id="orderSubtotal"><?= number_format($cartTotal,2) ?></span></span></div>
                <div class="summary-row"><span>Coupon Discount</span><span>-&euro;<span id="orderCouponDiscount"><?= number_format($couponDiscount,2) ?></span></span></div>
                <div class="summary-row"><span>Loyalty Discount</span><span>-&euro;<span id="orderLoyaltyDiscount"><?= number_format($loyaltyDiscount,2) ?></span></span></div>
                <div class="summary-row"><span>Shipping</span><span id="orderShipping"><?= $freeShippingEligible ? 'FREE' : ('€' . number_format($displayShippingCost,2)) ?></span></div>
                <div class="summary-row summary-row-total"><span>Total</span><span>&euro;<span id="orderTotal"><?= number_format($displayTotal,2) ?></span></span></div>
            </div>

            <div class="courier-map-card" id="courier-map-card">
                <h3 id="courier-map-title">Courier Locations</h3>
                <p id="courier-map-hint">Select a courier to preview the 3 closest pickup spots</p>
                <div id="courier-map-frame" class="courier-map-frame"></div>
                <div class="courier-map-points" id="courier-map-points"></div>
            </div>
        </div>
    </div>
</div>

<script>
// ========== ADDRESS GEOCODER CLASS ==========
class AddressGeocoder {
    constructor() {
        this.cache = new Map();
        this.lastRequestTime = 0;
        this.requestQueue = [];
        this.processing = false;
        
        // Local coordinate database (for fast lookup)
        this.localCoords = {
            // Cyprus
            'nicosia': [35.1856, 33.3823],
            'limassol': [34.6841, 33.0379],
            'larnaca': [34.9167, 33.6290],
            'paphos': [34.7754, 32.4257],
            'paralimni': [35.0396, 33.9819],
            'ayia napa': [34.9875, 34.0017],
            'protaras': [35.0096, 34.0541],
            'strovolos': [35.1500, 33.3500],
            'engomi': [35.1500, 33.3200],
            'latsia': [35.1090, 33.3800],
            'emba': [34.8000, 32.4300],
            'geroskipou': [34.7597, 32.4533],
            'chloraka': [34.8011, 32.4147],
            'pegeia': [34.8839, 32.3822],
            'tala': [34.8397, 32.4333],
            'kissonerga': [34.8214, 32.3981],
            // Greece
            'athens': [37.9838, 23.7275],
            'thessaloniki': [40.6401, 22.9444],
            'patras': [38.2466, 21.7346],
            'iraklio': [35.3387, 25.1442],
            'heraklion': [35.3387, 25.1442],
            'larissa': [39.6390, 22.4191],
            'volos': [39.3610, 22.9420],
            'ioannina': [39.6650, 20.8537],
            'kavala': [40.9392, 24.4015],
            'rhodes': [36.4342, 28.2174],
            'chania': [35.5138, 24.0180],
            'agios nikolaos': [35.1917, 25.7172],
        };
    }
    
    async geocodeAddress(address, city, postalCode, country) {
        const cacheKey = `${address}|${city}|${postalCode}|${country}`;
        if (this.cache.has(cacheKey)) {
            return this.cache.get(cacheKey);
        }
        
        return new Promise((resolve) => {
            this.requestQueue.push({ address, city, postalCode, country, resolve });
            this.processQueue();
        });
    }
    
    async processQueue() {
        if (this.processing) return;
        if (this.requestQueue.length === 0) return;
        
        this.processing = true;
        
        // Respect Nominatim's rate limit: at least 1 second between requests
        const now = Date.now();
        const timeSinceLast = now - this.lastRequestTime;
        if (timeSinceLast < 1000) {
            await new Promise(r => setTimeout(r, 1000 - timeSinceLast));
        }
        
        const { address, city, postalCode, country, resolve } = this.requestQueue.shift();
        
        try {
            // Try local lookup first
            let result = this.localLookup(city, country);
            if (result) {
                result.query_used = 'local database';
                result.display_name = `${city}, ${country}`;
            } else {
                result = await this._doGeocode(address, city, postalCode, country);
            }
            this.cache.set(`${address}|${city}|${postalCode}|${country}`, result);
            resolve(result);
        } catch (error) {
            console.warn('Geocoding failed:', error);
            resolve(null);
        } finally {
            this.lastRequestTime = Date.now();
            this.processing = false;
            this.processQueue();
        }
    }
    
    localLookup(city, country) {
        const cityLower = city.toLowerCase().trim();
        // Check for exact match
        if (this.localCoords[cityLower]) {
            const coords = this.localCoords[cityLower];
            return { lat: coords[0], lng: coords[1] };
        }
        // Partial match
        for (const [key, coords] of Object.entries(this.localCoords)) {
            if (cityLower.includes(key) || key.includes(cityLower)) {
                return { lat: coords[0], lng: coords[1] };
            }
        }
        return null;
    }
    
    async _doGeocode(address, city, postalCode, country) {
        // Try multiple query formats
        const queries = [];
        if (address && city && postalCode) {
            queries.push(`${address}, ${city}, ${postalCode}, ${country}`);
        }
        if (address && city) {
            queries.push(`${address}, ${city}, ${country}`);
        }
        if (address && postalCode) {
            queries.push(`${address}, ${postalCode}, ${country}`);
        }
        if (postalCode && city) {
            queries.push(`${postalCode}, ${city}, ${country}`);
        }
        if (postalCode) {
            queries.push(`${postalCode}, ${country}`);
        }
        if (city) {
            queries.push(`${city}, ${country}`);
        }
        
        for (const query of queries) {
            const url = `https://nominatim.openstreetmap.org/search?q=${encodeURIComponent(query)}&format=json&limit=1&addressdetails=1&countrycodes=${country === 'Greece' ? 'gr' : 'cy'}`;
            const response = await fetch(url, {
                headers: { 'User-Agent': 'CreationsByAthina/1.0' }
            });
            const data = await response.json();
            if (data && data.length > 0) {
                return {
                    lat: parseFloat(data[0].lat),
                    lng: parseFloat(data[0].lon),
                    display_name: data[0].display_name,
                    query_used: query
                };
            }
        }
        return null;
    }
}
// ========== PICKUP POINTS MANAGER CLASS ==========
class PickupPointsManager {
    constructor() {
        this.cache = new Map();
        this.loading = false;
        this.currentPoints = [];
    }
    
    async updateForCourier(courier, country, address, city, postalCode, selectElement, mode) {
        if (mode !== 'pickup') return { points: [], user_coords: null };
        if (!courier || !country) return { points: [], user_coords: null };
        if (!address || !city) return { points: [], user_coords: null };
        
        const cacheKey = `${courier}|${country}|${address}|${city}|${postalCode}`;
        if (this.cache.has(cacheKey)) {
            const cached = this.cache.get(cacheKey);
            this.populateSelect(selectElement, cached.points);
            return cached;
        }
        if (this.loading) return { points: [], user_coords: null };
        
        this.loading = true;
        try {
            const url = `?ajax_get_pickup_points=1&courier=${encodeURIComponent(courier)}&country=${encodeURIComponent(country)}&city=${encodeURIComponent(city)}&postal=${encodeURIComponent(postalCode)}&address=${encodeURIComponent(address)}`;
            const response = await fetch(url);
            const data = await response.json();
            if (data && data.success) {
                this.currentPoints = data.points || [];
                this.cache.set(cacheKey, data);
                this.populateSelect(selectElement, this.currentPoints);
                return data;
            } else {
                return { points: [], user_coords: null };
            }
        } catch (error) {
            console.error('Error loading pickup points:', error);
            return { points: [], user_coords: null };
        } finally {
            this.loading = false;
        }
    }
    
    populateSelect(selectElement, points) {
        if (!selectElement) return;
        selectElement.innerHTML = '<option value="">-- Select Pickup Point --</option>';
        if (!points || points.length === 0) {
            const option = document.createElement('option');
            option.value = '';
            option.textContent = 'No pickup points available nearby';
            selectElement.appendChild(option);
            return;
        }
        const sortedPoints = [...points].sort((a, b) => (a.distance || 999) - (b.distance || 999));
        sortedPoints.forEach(point => {
            const option = document.createElement('option');
            const distanceText = point.distance ? ` (${point.distance.toFixed(1)} km)` : '';
            const locationText = point.town || point.city;
            option.textContent = `${point.name} - ${locationText}${distanceText}`;
            option.value = JSON.stringify({
                id: point.id,
                name: point.name,
                address: point.address,
                city: point.city,
                town: point.town,
                postal_code: point.postal_code,
                lat: point.lat,
                lng: point.lng,
                courier_code: point.courier_code
            });
            selectElement.appendChild(option);
        });
    }
    
    getClosestPoints(limit = 3) {
        if (!this.currentPoints || this.currentPoints.length === 0) return [];
        return [...this.currentPoints]
            .sort((a, b) => (a.distance || 999) - (b.distance || 999))
            .slice(0, limit);
    }
}

// ========== MAP RENDERING ==========
let currentMapMode = null; // 'delivery' or 'pickup'

function renderDeliveryMap(map, mapLayer, location) {
    if (!map || !mapLayer) return;
    mapLayer.clearLayers();
    if (location && location.lat && location.lng) {
        const userMarker = L.circleMarker([location.lat, location.lng], {
            radius: 12,
            color: '#ff4444',
            fillColor: '#ff4444',
            fillOpacity: 0.8,
            weight: 3
        });
        userMarker.bindPopup('<strong>Your Delivery Address</strong><br>This is where your order will be delivered.');
        userMarker.addTo(mapLayer);
        map.setView([location.lat, location.lng], 15);
    } else {
        map.setView([35.14, 33.36], 7);
    }
}

function renderPickupMap(map, mapLayer, points, userLocation) {
    if (!map || !mapLayer) return;
    mapLayer.clearLayers();
    const bounds = [];
    
    // Add user location marker if available
    if (userLocation && userLocation.lat && userLocation.lng) {
        const userMarker = L.circleMarker([userLocation.lat, userLocation.lng], {
            radius: 8,
            color: '#ff4444',
            fillColor: '#ff4444',
            fillOpacity: 0.7,
            weight: 2
        });
        userMarker.bindPopup('<strong>Your Location</strong><br>Based on your address');
        userMarker.addTo(mapLayer);
        bounds.push([userLocation.lat, userLocation.lng]);
    }
    
    // Add pickup point markers
    if (points && points.length > 0) {
        points.forEach((point, index) => {
            const marker = L.circleMarker([point.lat, point.lng], {
                radius: 8,
                color: '#8a4dd6',
                fillColor: index === 0 ? '#8a4dd6' : '#ffffff',
                fillOpacity: index === 0 ? 1 : 0.8,
                weight: 3
            });
            const distanceText = point.distance ? `<br><strong>Distance:</strong> ${point.distance.toFixed(1)} km` : '';
            const closestText = index === 0 ? '<br><strong>Closest</strong>' : '';
            marker.bindPopup(`
                <strong>${escapeHtml(point.name)}</strong><br>
                ${escapeHtml(point.address)}<br>
                ${escapeHtml(point.town || point.city)} ${escapeHtml(point.postal_code || '')}${distanceText}${closestText}
            `);
            marker.addTo(mapLayer);
            bounds.push([point.lat, point.lng]);
        });
    }
    
    // Adjust map view
    if (bounds.length > 1) {
        map.fitBounds(bounds, { padding: [50, 50] });
    } else if (bounds.length === 1) {
        map.setView(bounds[0], 13);
    } else {
        map.setView([35.14, 33.36], 7);
    }
}

function escapeHtml(text) {
    if (!text) return '';
    return text.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

// ========== MAIN CHECKOUT SCRIPT ==========
(function () {
    const geocoder = new AddressGeocoder();
    const pickupManager = new PickupPointsManager();
    const courierSelect = document.getElementById('courier_select');
    const fulfillmentModeRadios = document.querySelectorAll('input[name="fulfillment_mode"]');
    const shippingAddress = document.getElementById('shipping_address');
    const shippingCity = document.getElementById('shipping_city');
    const postalCode = document.getElementById('shipping_postal_code');
    const countrySelect = document.getElementById('shipping_country');
    const akisSelect = document.getElementById('akis_pickup_point');
    const acsSelect = document.getElementById('acs_pickup_point');
    const boxnowSelect = document.getElementById('boxnow_pickup_point');
    const mapContainer = document.getElementById('courier-map-frame');
    const mapPointsContainer = document.getElementById('courier-map-points');
    const mapTitle = document.getElementById('courier-map-title');
    const mapHint = document.getElementById('courier-map-hint');
    
    let map = null, mapLayer = null;
    if (mapContainer && typeof L !== 'undefined') {
        map = L.map(mapContainer, { zoomControl: true, scrollWheelZoom: false });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);
        mapLayer = L.layerGroup().addTo(map);
        map.setView([35.14, 33.36], 7);
    } else {
        console.error('Map container or Leaflet not found');
    }
    
    function getCurrentValues() {
        return {
            courier: courierSelect ? courierSelect.value : '',
            country: countrySelect ? countrySelect.value : '',
            address: shippingAddress ? shippingAddress.value : '',
            city: shippingCity ? shippingCity.value : '',
            postalCode: postalCode ? postalCode.value : '',
            mode: getSelectedFulfillmentMode()
        };
    }
    
    function getSelectedFulfillmentMode() {
        const checked = document.querySelector('input[name="fulfillment_mode"]:checked');
        return checked ? checked.value : 'delivery';
    }
    
    function getSelectForCourier(courier) {
        if (courier === 'akis_express') return akisSelect;
        if (courier === 'acs') return acsSelect;
        if (courier === 'boxnow') return boxnowSelect;
        return null;
    }
    
    function hideAllPickupWrappers() {
        ['akis-point-wrapper', 'acs-point-wrapper', 'boxnow-point-wrapper'].forEach(id => {
            const wrapper = document.getElementById(id);
            if (wrapper) wrapper.style.display = 'none';
        });
    }
    
    function showPickupWrapperForCourier(courier) {
        hideAllPickupWrappers();
        if (!courier) return;
        let wrapperId = '';
        if (courier === 'akis_express') wrapperId = 'akis-point-wrapper';
        else if (courier === 'acs') wrapperId = 'acs-point-wrapper';
        else if (courier === 'boxnow') wrapperId = 'boxnow-point-wrapper';
        if (wrapperId) {
            const wrapper = document.getElementById(wrapperId);
            if (wrapper) wrapper.style.display = 'block';
        }
    }
    
    function attachPickupSelectListeners() {
        const selects = [akisSelect, acsSelect, boxnowSelect];
        selects.forEach(select => {
            if (select) {
                select.removeEventListener('change', handlePickupSelectChange);
                select.addEventListener('change', handlePickupSelectChange);
            }
        });
    }
    
    function handlePickupSelectChange() {
        const select = this;
        if (select.value && select.value !== '') {
            try {
                const pickupData = JSON.parse(select.value);
                if (shippingAddress) shippingAddress.value = pickupData.address;
                if (shippingCity) shippingCity.value = pickupData.town || pickupData.city;
                if (postalCode) postalCode.value = pickupData.postal_code || '';
                if (map && pickupData.lat && pickupData.lng) {
                    map.setView([pickupData.lat, pickupData.lng], 15);
                }
                // Highlight selected chip
                if (mapPointsContainer) {
                    const chips = mapPointsContainer.querySelectorAll('span');
                    chips.forEach(chip => {
                        if (chip.textContent.includes(pickupData.name)) {
                            chip.classList.add('is-closest');
                            chip.style.background = '#8a4dd6';
                            chip.style.color = 'white';
                        } else {
                            chip.classList.remove('is-closest');
                            chip.style.background = '#f5f0ff';
                            chip.style.color = '';
                        }
                    });
                }
            } catch(e) {
                console.error('Error parsing pickup data:', e);
            }
        }
    }
    
    function updateMapWithPoints(points, userLocation) {
        if (!map || !mapLayer) return;
        if (mapPointsContainer) {
            mapPointsContainer.innerHTML = '';
            if (points && points.length > 0) {
                const closestPoints = pickupManager.getClosestPoints(3);
                closestPoints.forEach((point, idx) => {
                    const chip = document.createElement('span');
                    const distanceText = point.distance ? ` (${point.distance.toFixed(1)} km)` : '';
                    const locationText = point.town || point.city;
                    chip.textContent = `${idx + 1}. ${point.name} - ${locationText}${distanceText}`;
                    if (idx === 0) {
                        chip.classList.add('is-closest');
                    }
                    chip.addEventListener('click', () => {
                        if (map && point.lat && point.lng) map.setView([point.lat, point.lng], 15);
                        const select = getSelectForCourier(point.courier_code);
                        if (select) {
                            for (let i = 0; i < select.options.length; i++) {
                                try {
                                    const optData = JSON.parse(select.options[i].value);
                                    if (optData.id === point.id) {
                                        select.selectedIndex = i;
                                        const event = new Event('change');
                                        select.dispatchEvent(event);
                                        break;
                                    }
                                } catch(e) { continue; }
                            }
                        }
                    });
                    mapPointsContainer.appendChild(chip);
                });
            } else {
                mapPointsContainer.innerHTML = '<span>No pickup points available nearby. Try a different courier or address.</span>';
            }
        }
        renderPickupMap(map, mapLayer, points, userLocation);
    }
    
    async function updateDeliveryMap() {
        const values = getCurrentValues();
        if (values.mode !== 'delivery') return;
        
        if (!values.address || !values.city || !values.country) {
            if (mapPointsContainer) mapPointsContainer.innerHTML = '<span>📍 Please enter your full address to see delivery location on map</span>';
            if (mapLayer) mapLayer.clearLayers();
            if (mapTitle) mapTitle.textContent = 'Delivery Location';
            if (mapHint) mapHint.textContent = 'Enter your address details above';
            map.setView([35.14, 33.36], 7);
            return;
        }
        
        if (mapTitle) mapTitle.innerHTML = '<span class="loading-spinner"></span> Locating your address...';
        if (mapHint) mapHint.textContent = 'Geocoding your address...';
        
        try {
            const location = await geocoder.geocodeAddress(values.address, values.city, values.postalCode, values.country);
            console.log('Geocoding result:', location);
            
            if (location && location.lat && location.lng) {
                const displayName = location.display_name.substring(0, 150);
                mapPointsContainer.innerHTML = `
                    <span style="display:block; margin-bottom:8px;">✅ <strong>Address found:</strong> ${displayName}</span>
                    <span style="font-size:11px; color:#666;">If this is not correct, please verify your address details.</span>
                `;
                mapTitle.textContent = 'Your Delivery Location';
                mapHint.textContent = 'This is where your order will be delivered.';
                renderDeliveryMap(map, mapLayer, location);
            } else {
                mapPointsContainer.innerHTML = `
                    <span>⚠️ Could not locate your address. Please check the following:</span>
                    <span style="display:block; margin-top:8px;">• Ensure street name and number are correct</span>
                    <span>• Verify city and postal code match</span>
                    <span>• Try using a more specific address (e.g., include district)</span>
                `;
                mapTitle.textContent = 'Location Not Found';
                mapHint.textContent = 'Unable to geocode your address. Please verify the address fields.';
                mapLayer.clearLayers();
                map.setView([35.14, 33.36], 7);
            }
        } catch (error) {
            console.error('Geocoding error:', error);
            mapPointsContainer.innerHTML = '<span>❌ Error while locating address. Please try again later.</span>';
            mapTitle.textContent = 'Location Error';
            mapHint.textContent = 'There was a problem finding your address.';
            mapLayer.clearLayers();
            map.setView([35.14, 33.36], 7);
        }
    }
    
    async function updatePickupPoints() {
        const values = getCurrentValues();
        const select = getSelectForCourier(values.courier);
        
        if (values.mode === 'pickup' && values.courier) {
            showPickupWrapperForCourier(values.courier);
        } else {
            hideAllPickupWrappers();
        }
        if (values.mode !== 'pickup' || !values.courier || !values.country) {
            if (mapPointsContainer) mapPointsContainer.innerHTML = '<span>Select "Pickup from courier point" and a courier to see pickup locations</span>';
            if (mapLayer) mapLayer.clearLayers();
            if (mapTitle) mapTitle.textContent = 'Pickup Locations';
            if (mapHint) mapHint.textContent = 'Choose pickup mode and select a courier above';
            return;
        }
        if (!values.address || !values.city) {
            if (mapPointsContainer) mapPointsContainer.innerHTML = '<span>Please enter your address and city to find nearby pickup points</span>';
            if (mapLayer) mapLayer.clearLayers();
            if (mapTitle) mapTitle.textContent = 'Enter address to see pickup locations';
            if (mapHint) mapHint.textContent = 'Fill in your shipping address details first';
            return;
        }
        if (mapTitle) mapTitle.innerHTML = '<span class="loading-spinner"></span> Loading pickup points...';
        if (mapHint) mapHint.textContent = 'Finding the 3 closest locations to your address';
        
        const result = await pickupManager.updateForCourier(
            values.courier, values.country, values.address, values.city,
            values.postalCode, select, values.mode
        );
        
        console.log('Received points:', result.points);
        
        const courierNames = <?= json_encode($countryCouriers) ?>;
        const courierName = (courierNames[values.country] && courierNames[values.country][values.courier])
            ? courierNames[values.country][values.courier] : 'Pickup';
        if (mapTitle) mapTitle.textContent = `${courierName} - Closest Locations`;
        
        if (result && result.points) {
            const closestPoints = pickupManager.getClosestPoints(3);
            if (closestPoints.length > 0) {
                const minDist = Math.min(...closestPoints.map(p => p.distance)).toFixed(1);
                const maxDist = Math.max(...closestPoints.map(p => p.distance)).toFixed(1);
                mapHint.textContent = `✅ Showing ${closestPoints.length} closest pickup points (${minDist} - ${maxDist} km from your address)`;
                updateMapWithPoints(closestPoints, result.user_coords);
            } else {
                mapHint.textContent = `❌ No pickup points found for this courier in your area. Please try a different courier or choose delivery.`;
                updateMapWithPoints([], result.user_coords);
                if (select) select.disabled = true;
            }
        } else {
            mapHint.textContent = '❌ Unable to load pickup points. Please check your connection or try a different courier.';
            updateMapWithPoints([], null);
        }
        
        attachPickupSelectListeners();
    }
    
    let debounceTimer;
    async function updateMapBasedOnMode() {
        const mode = getSelectedFulfillmentMode();
        console.log('Updating map for mode:', mode);
        if (mode === 'delivery') {
            hideAllPickupWrappers();
            if (mapPointsContainer) mapPointsContainer.innerHTML = '<span>📍 Locating your delivery address...</span>';
            await updateDeliveryMap();
        } else if (mode === 'pickup') {
            await updatePickupPoints();
        }
    }
    
    function debouncedUpdate() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => updateMapBasedOnMode(), 500);
    }
    
    // Event listeners
    if (courierSelect) courierSelect.addEventListener('change', debouncedUpdate);
    if (fulfillmentModeRadios) {
        fulfillmentModeRadios.forEach(radio => {
            radio.addEventListener('change', () => {
                debouncedUpdate();
                if (getSelectedFulfillmentMode() !== 'pickup') {
                    if (akisSelect) akisSelect.value = '';
                    if (acsSelect) acsSelect.value = '';
                    if (boxnowSelect) boxnowSelect.value = '';
                }
            });
        });
    }
    if (shippingAddress) {
        shippingAddress.addEventListener('input', debouncedUpdate);
        shippingAddress.addEventListener('change', debouncedUpdate);
    }
    if (shippingCity) {
        shippingCity.addEventListener('input', debouncedUpdate);
        shippingCity.addEventListener('change', debouncedUpdate);
    }
    if (postalCode) {
        postalCode.addEventListener('input', debouncedUpdate);
        postalCode.addEventListener('change', debouncedUpdate);
    }
    if (countrySelect) {
        countrySelect.addEventListener('change', () => {
            updateShippingCostDisplay();
            debouncedUpdate();
        });
    }
    
    attachPickupSelectListeners();
    updateShippingCostDisplay();
    
    // Initial load
    setTimeout(() => {
        updateMapBasedOnMode();
    }, 500);
})();

function updateShippingCostDisplay() {
    const country = document.getElementById('shipping_country')?.value;
    const standardLabel = document.getElementById('standard-cost-label');
    const expressLabel = document.getElementById('express-cost-label');
    if (!country) return;
    const rates = {
        'Cyprus': { standard: '2.00', express: '5.00' },
        'Greece': { standard: '4.00', express: '10.00' }
    };
    if (rates[country]) {
        if (standardLabel) standardLabel.innerHTML = ` (€${rates[country].standard})`;
        if (expressLabel) expressLabel.innerHTML = ` (€${rates[country].express})`;
    } else {
        if (standardLabel) standardLabel.innerHTML = '';
        if (expressLabel) expressLabel.innerHTML = '';
    }
}

window.testPickupPoints = async function() {
    const courier = document.getElementById('courier_select')?.value;
    const country = document.getElementById('shipping_country')?.value;
    const address = document.getElementById('shipping_address')?.value;
    const city = document.getElementById('shipping_city')?.value;
    const postal = document.getElementById('shipping_postal_code')?.value;
    if (!courier || !country || !address || !city) {
        console.error('Missing data:', {courier, country, address, city});
        alert('Missing shipping information. Please fill in all fields.');
        return;
    }
    const url = `?ajax_get_pickup_points=1&courier=${encodeURIComponent(courier)}&country=${encodeURIComponent(country)}&city=${encodeURIComponent(city)}&postal=${encodeURIComponent(postal)}&address=${encodeURIComponent(address)}`;
    console.log('Testing AJAX URL:', url);
    try {
        const response = await fetch(url);
        const data = await response.json();
        console.log('AJAX response:', data);
        if (data.error) alert('Error: ' + data.error);
        else alert('Points found: ' + (data.points ? data.points.length : 0) + '\nCheck console for details.');
    } catch (err) {
        console.error('Fetch error:', err);
        alert('Fetch error: ' + err.message);
    }
};
</script><?php
$footerPath = __DIR__ . '/../include/footer.php';
if (file_exists($footerPath)) {
    include $footerPath;
}
?>
</body>
</html>