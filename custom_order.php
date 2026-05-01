<?php
session_start();

require_once __DIR__ . "/authentication/database.php";
require_once __DIR__ . "/authentication/get_config.php";
require_once __DIR__ . "/include/security.php";
require_once __DIR__ . "/include/translation_helpers.php";
require_once __DIR__ . "/include/custom_order_settings.php";
if (!defined('CUSTOM_ORDERS_DIRECT')) {
    define('CUSTOM_ORDERS_DIRECT', true);
}
require_once __DIR__ . "/modules/custom_orders.php";
if (!defined('CREATE_CUSTOM_PRODUCT_DIRECT')) {
    define('CREATE_CUSTOM_PRODUCT_DIRECT', true);
}
require_once __DIR__ . "/modules/create_custom_product.php";

ensureCustomOrdersTable($conn);
$customOrderSteps = app_custom_order_steps($conn);

$system_title = getSystemConfig("site_title") ?: "Athina E-Shop";
$role = "guest";
$fullName = "Guest";
$currentUser = null;
$userId = 0;

if (isset($_SESSION["user"]) && is_array($_SESSION["user"])) {
    $userId = (int)($_SESSION["user"]["id"] ?? $_SESSION["user"]["userID"] ?? 0);
    $fullName = trim((string)($_SESSION["user"]["full_name"] ?? 'User'));
    $role = (string)($_SESSION["user"]["role"] ?? 'user');
} elseif (isset($_SESSION["user_id"])) {
    $userId = (int)$_SESSION["user_id"];
}

if ($userId > 0) {
    $stmt = $conn->prepare("SELECT userID, email, full_name, role, profile_complete, is_verified FROM users WHERE userID = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $currentUser = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }
    if ($currentUser) {
        $fullName = trim((string)($currentUser["full_name"] ?? $fullName));
        $role = (string)($currentUser["role"] ?? $role);
        $_SESSION["user"]["id"] = (int)$currentUser["userID"];
        $_SESSION["user"]["userID"] = (int)$currentUser["userID"];
        $_SESSION["user"]["email"] = (string)$currentUser["email"];
        $_SESSION["user"]["full_name"] = $fullName !== '' ? $fullName : 'User';
        $_SESSION["user"]["role"] = $role;
        $_SESSION["user"]["profile_complete"] = (int)($currentUser["profile_complete"] ?? 0);
        $_SESSION["user"]["is_verified"] = (int)($currentUser["is_verified"] ?? 0);
    } else {
        $userId = 0;
    }
}

$isLoggedIn = $userId > 0 && is_array($currentUser);
$isProfileComplete = $isLoggedIn && (int)($currentUser["profile_complete"] ?? 0) === 1;
$isVerified = $isLoggedIn && (int)($currentUser["is_verified"] ?? 0) === 1;
$customerEmail = $isLoggedIn ? normalizeCustomerEmail((string)($currentUser["email"] ?? '')) : '';
$customerName = $isLoggedIn ? trim((string)($currentUser["full_name"] ?? $fullName)) : '';
if ($customerName === '') {
    $customerName = 'Customer';
}

$GLOBALS['header_user_full_name'] = $fullName;
$GLOBALS['header_user_role'] = $role;
$instagramUrl = 'https://www.instagram.com/creations.by.athina/';
$loginRedirect = '/custom_order.php';
$loginHref = 'authentication/login.php?redirect=' . rawurlencode($loginRedirect);
$registerHref = 'authentication/registration.php';
$successMessage = '';
$successMessageKey = '';
$errorMessage = '';
$errorMessageKey = '';

function coStatusClass(string $status): string
{
    $status = strtolower(trim($status));
    if (in_array($status, ['accepted', 'completed'], true)) return 'is-accepted';
    if (in_array($status, ['in_production', 'ready_for_checkout', 'in_progress'], true)) return 'is-production';
    if (in_array($status, ['in_discussion'], true)) return 'is-discussion';
    if (in_array($status, ['declined', 'cancelled'], true)) return 'is-declined';
    return 'is-pending';
}

function coFormatDate(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') return '-';
    $ts = strtotime($value);
    return $ts ? date('d/m/Y', $ts) : $value;
}

function coBuildRequestDescription(array $input): string
{
    $lines = [];
    $budget = trim((string)($input['budget'] ?? ''));
    if ($budget !== '') {
        $lines[] = 'Preferred budget: ' . $budget;
    }

    $details = trim((string)($input['details'] ?? ''));
    if ($details !== '') {
        $lines[] = $details;
    }
    return trim(implode("\n", $lines));
}

function coLoadPrivateCheckoutMeta(mysqli $conn, array $order): array
{
    $productId = (int)($order['sourceProductID'] ?? 0);
    if ($productId <= 0) {
        return ['product_id' => 0, 'ready' => false, 'verified' => false, 'product_url' => ''];
    }
    $stmt = $conn->prepare("SELECT productID, cartStatus, privateAccessToken FROM products WHERE productID = ? LIMIT 1");
    if (!$stmt) {
        return ['product_id' => 0, 'ready' => false, 'verified' => false, 'product_url' => ''];
    }
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row || (string)($row['cartStatus'] ?? '') !== 'made_to_order') {
        return ['product_id' => 0, 'ready' => false, 'verified' => false, 'product_url' => ''];
    }
    $verified = isMadeToOrderProductAccessible($conn, $productId);
    return [
        'product_id' => $productId,
        'ready' => true,
        'verified' => $verified,
        'product_url' => $verified ? ('product.php?id=' . $productId) : '',
    ];
}

function coRenderStepList(array $steps): void
{
    echo '<ol class="custom-order-numbered-steps">';
    foreach ($steps as $step) {
        $titleEn = trim((string)($step['title_en'] ?? ''));
        $textEn = trim((string)($step['text_en'] ?? ''));
        $titleGr = trim((string)($step['title_gr'] ?? $titleEn));
        $textGr = trim((string)($step['text_gr'] ?? $textEn));
        echo '<li>';
        echo '<strong' . app_translate_text_attrs($titleEn, $titleGr) . '>' . htmlspecialchars($titleEn, ENT_QUOTES, 'UTF-8') . '</strong>';
        echo '<p' . app_translate_text_attrs($textEn, $textGr) . '>' . htmlspecialchars($textEn, ENT_QUOTES, 'UTF-8') . '</p>';
        echo '</li>';
    }
    echo '</ol>';
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = (string)($_POST["action"] ?? "");
    if (!$isLoggedIn) {
        if (function_exists('rememberAuthRedirectTarget')) {
            rememberAuthRedirectTarget($loginRedirect);
        }
        header("Location: " . $loginHref);
        exit;
    }

    app_require_csrf(false, "Invalid request token. Please refresh and try again.");

    try {
        if ($action === "submit_custom_request") {
            if (!$isProfileComplete) {
                throw new RuntimeException("Please complete your profile before sending a website custom order request.");
            }
            if (!$isVerified) {
                throw new RuntimeException("Please verify your email before sending a website custom order request.");
            }

            $input = [
                'project_title' => trim((string)($_POST['project_title'] ?? '')),
                'item_type' => trim((string)($_POST['item_type'] ?? '')),
                'size' => trim((string)($_POST['size'] ?? '')),
                'colours' => trim((string)($_POST['colours'] ?? '')),
                'budget' => trim((string)($_POST['budget'] ?? '')),
                'needed_by' => trim((string)($_POST['needed_by'] ?? '')),
                'details' => trim((string)($_POST['details'] ?? '')),
            ];

            if ($input['project_title'] === '') {
                throw new InvalidArgumentException("Please add a short title for your custom idea.");
            }
            if (function_exists('mb_strlen') ? mb_strlen($input['details']) < 20 : strlen($input['details']) < 20) {
                throw new InvalidArgumentException("Please describe your idea with a little more detail.");
            }
            if ($input['needed_by'] !== '') {
                $neededBy = DateTime::createFromFormat('Y-m-d', $input['needed_by']);
                if (!$neededBy || $neededBy->format('Y-m-d') !== $input['needed_by']) {
                    throw new InvalidArgumentException("Please choose a valid needed-by date.");
                }
            }

            $photoPath = '';
            if (!empty($_FILES['referencePhoto']) && (int)($_FILES['referencePhoto']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $photoPath = storeCustomOrderReferencePhoto($_FILES['referencePhoto']);
            }

            $description = coBuildRequestDescription($input);
            try {
                $created = createCustomOrderRequest($conn, $userId, $customerEmail, $description, [
                    'customer_name' => $customerName,
                    'idea_title' => $input['project_title'],
                    'product_type' => $input['item_type'],
                    'preferred_size' => $input['size'],
                    'preferred_colours' => $input['colours'],
                    'deadline' => $input['needed_by'],
                    'photo_reference_path' => $photoPath,
                    'special_instructions' => 'Submitted through the website custom order form.',
                ]);
            } catch (Throwable $e) {
                if ($photoPath !== '') {
                    deleteCustomOrderReferencePhoto($photoPath);
                }
                throw $e;
            }

            $createdId = (int)($created['custom_order_id'] ?? 0);
            header("Location: custom_order.php?created=1&view={$createdId}#discussion");
            exit;
        }

        if ($action === "verify_private_checkout_code") {
            $orderId = (int)($_POST['customOrderID'] ?? 0);
            $submittedCode = normalizeCustomOrderAccessCode((string)($_POST['accessCode'] ?? ''));
            $order = getCustomOrderById($conn, $orderId, $userId);
            $expectedCode = normalizeCustomOrderAccessCode((string)($order['accessCode'] ?? ''));
            $productId = (int)($order['sourceProductID'] ?? 0);
            if ($productId <= 0 || $expectedCode === '') {
                throw new RuntimeException('This private checkout product is not ready yet.');
            }
            if ($submittedCode === '' || !hash_equals($expectedCode, $submittedCode)) {
                throw new InvalidArgumentException('Invalid access code.');
            }
            $accessRow = loadMadeToOrderProductAccessRow($conn, $productId);
            $token = trim((string)($accessRow['privateAccessToken'] ?? ''));
            if (!$accessRow || (string)($accessRow['cartStatus'] ?? '') !== 'made_to_order' || $token === '') {
                throw new RuntimeException('This private checkout product is not ready yet.');
            }
            setMadeToOrderSessionAccess($productId, $token);
            header("Location: product.php?id={$productId}");
            exit;
        }

        if ($action === "customer_message") {
            $orderId = (int)($_POST['customOrderID'] ?? 0);
            $message = trim((string)($_POST['messageBody'] ?? ''));
            if ($orderId <= 0 || $message === '') {
                throw new InvalidArgumentException("Please write a reply before sending.");
            }
            getCustomOrderById($conn, $orderId, $userId);
            addCustomOrderMessage($conn, $orderId, 'customer', $userId, $message);
            header("Location: custom_order.php?message_sent=1&view={$orderId}#discussion");
            exit;
        }

    } catch (Throwable $e) {
        $errorMessage = $e->getMessage() !== '' ? $e->getMessage() : 'Something went wrong. Please try again.';
    }
}

if (isset($_GET['created'])) {
    $successMessage = 'Your website custom order request was sent to Athina.';
    $successMessageKey = 'customOrderSuccessCreated';
} elseif (isset($_GET['message_sent'])) {
    $successMessage = 'Your reply was sent.';
    $successMessageKey = 'customOrderSuccessReplySent';
}

$errorMessageKeys = [
    'Please complete your profile before sending a website custom order request.' => 'customOrderCompleteProfileNote',
    'Please verify your email before sending a website custom order request.' => 'customOrderVerifyEmailNote',
    'Please add a short title for your custom idea.' => 'customOrderErrorAddTitle',
    'Please describe your idea with a little more detail.' => 'customOrderErrorDescribeMore',
    'Please choose a valid needed-by date.' => 'customOrderErrorNeededBy',
    'Please write a reply before sending.' => 'customOrderErrorWriteReply',
    'Something went wrong. Please try again.' => 'customOrderErrorGeneric',
];
if ($errorMessage !== '') {
    $errorMessageKey = $errorMessageKeys[$errorMessage] ?? '';
}

$customerOrders = $isLoggedIn ? getCustomOrdersForUser($conn, $userId) : [];
$selectedOrder = null;
$selectedOrderId = (int)($_GET['view'] ?? 0);
if ($selectedOrderId <= 0 && !empty($customerOrders)) {
    $selectedOrderId = (int)$customerOrders[0]['customOrderID'];
}
foreach ($customerOrders as $orderRow) {
    if ((int)$orderRow['customOrderID'] === $selectedOrderId) {
        $selectedOrder = $orderRow;
        break;
    }
}

$selectedMessages = [];
$privateCheckoutLink = '';
$privateCheckoutMeta = ['product_id' => 0, 'ready' => false, 'verified' => false, 'product_url' => ''];
if ($selectedOrder) {
    $selectedMessages = getCustomOrderMessages($conn, (int)$selectedOrder['customOrderID']);
    $privateCheckoutMeta = coLoadPrivateCheckoutMeta($conn, $selectedOrder);
    $privateCheckoutLink = (string)($privateCheckoutMeta['product_url'] ?? '');
}

$statusLabels = getCustomOrderStatusLabels();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Creations by Athina - Custom Order</title>
    <link rel="stylesheet" href="assets/styling/styles.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/styles.css') ?>">
    <link rel="stylesheet" href="assets/styling/header.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/header.css') ?>">
    <link rel="stylesheet" href="assets/styling/custom_order.css?v=<?= (int)@filemtime(__DIR__ . '/assets/styling/custom_order.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="assets/js/translations.js?v=<?= (int)@filemtime(__DIR__ . '/assets/js/translations.js') ?>" defer></script>
    <script src="assets/js/custom_order_translations.js?v=<?= (int)@filemtime(__DIR__ . '/assets/js/custom_order_translations.js') ?>" defer></script>
    <?php include __DIR__ . '/include/pwa_head.php'; ?>
</head>
<body class="site-page"<?= app_translate_page_title_attrs('Creations by Athina - Custom Order', 'Creations by Athina - Custom Order') ?>>
<?php
$activePage = 'custom_order';
include __DIR__ . '/include/header.php';
?>

<main class="custom-order-page">
    <section class="custom-order-hero">
        <div class="container">
            <span class="custom-order-kicker" data-co-text="customOrderKicker">Custom Crochet Service</span>
            <h1 data-co-text="customOrderPageTitle">Custom Orders: Instagram Chat or Website Request</h1>
            <p data-co-text="customOrderPageSubtitle">Start with the recommended Instagram chat for quick back-and-forth, or send a structured website request from your registered account. Once the details are approved, you receive a private checkout link.</p>
        </div>
    </section>

    <section class="custom-order-content">
        <div class="container custom-order-layout custom-order-layout-single">
            <div class="custom-order-card custom-order-guide">
                <h2 data-co-text="customOrderHowItWorks">How It Works</h2>
                <p class="custom-order-guide-intro" data-co-text="customOrderGuideIntro">Choose the path that suits you best. Instagram is recommended for fast conversation, while the website request keeps the whole request connected to your account.</p>

                <div class="custom-order-guide-paths">
                    <div class="custom-order-guide-section">
                    <h3 data-co-text="customOrderInstagramGuideTitle">Instagram steps</h3>
                    <?php coRenderStepList($customOrderSteps['instagram'] ?? []); ?>

                </div>

                    <div class="custom-order-guide-section">
                    <h3 data-co-text="customOrderWebsiteGuideTitle">Website request steps</h3>
                    <?php coRenderStepList($customOrderSteps['website'] ?? []); ?>

                    </div>
                </div>
            </div>

            <div class="custom-order-card custom-order-info-card">
                <?php if ($successMessage !== ''): ?>
                    <div class="custom-order-alert success"<?= $successMessageKey !== '' ? ' data-co-text="' . htmlspecialchars($successMessageKey, ENT_QUOTES, 'UTF-8') . '"' : '' ?>><?= htmlspecialchars($successMessage) ?></div>
                <?php endif; ?>
                <?php if ($errorMessage !== ''): ?>
                    <div class="custom-order-alert error"<?= $errorMessageKey !== '' ? ' data-co-text="' . htmlspecialchars($errorMessageKey, ENT_QUOTES, 'UTF-8') . '"' : '' ?>><?= htmlspecialchars($errorMessage) ?></div>
                <?php endif; ?>

                <h2 data-co-text="customOrderChooseTitle">Choose how to start</h2>
                <div class="custom-order-info-list">
                    <div class="custom-order-info-item custom-order-recommended">
                        <strong data-co-text="customOrderInstagramCardTitle">Recommended: Instagram chat</strong>
                        <p data-co-text="customOrderInstagramCardText">Best when you want to share photos, compare colors, and agree details quickly with the shop owner.</p>
                        <a href="<?= htmlspecialchars($instagramUrl) ?>" target="_blank" rel="noopener noreferrer" class="custom-order-btn custom-order-instagram-btn">
                            <i class="fab fa-instagram"></i>
                            <span data-co-text="customOrderInstagramAction">Message on Instagram</span>
                        </a>
                    </div>
                    <div class="custom-order-info-item">
                        <strong data-co-text="customOrderWebsiteCardTitle">Second option: website request</strong>
                        <p data-co-text="customOrderWebsiteCardText">Send a structured request from your account. Athina can reply, ask for more details, make an offer, decline the idea, or send a private checkout product link.</p>
                    </div>
                </div>

                <div class="custom-order-cta-box" id="website-request">
                    <h3 data-co-text="customOrderWebsiteFormTitle">Website custom order request</h3>
                    <?php if (!$isLoggedIn): ?>
                        <p class="form-note" data-co-text="customOrderLoginRequiredNote">You need a registered account before using the website request form.</p>
                        <div class="custom-order-inline-actions">
                            <a href="<?= htmlspecialchars($loginHref) ?>" class="custom-order-secondary-btn" data-co-text="customOrderLoginAction">Sign In</a>
                            <a href="<?= htmlspecialchars($registerHref) ?>" class="custom-order-secondary-btn" data-co-text="customOrderRegisterAction">Create Account</a>
                        </div>
                    <?php elseif (!$isProfileComplete): ?>
                        <div class="custom-order-alert info" data-co-text="customOrderCompleteProfileNote">Please complete your profile before sending a website custom order request.</div>
                        <a href="authentication/complete_profile.php" class="custom-order-secondary-btn" data-co-text="customOrderCompleteProfileAction">Complete Profile</a>
                    <?php elseif (!$isVerified): ?>
                        <div class="custom-order-alert info" data-co-text="customOrderVerifyEmailNote">Please verify your email before sending a website custom order request.</div>
                        <a href="authentication/verify.php" class="custom-order-secondary-btn" data-co-text="customOrderVerifyEmailAction">Verify Email</a>
                    <?php else: ?>
                        <form method="POST" enctype="multipart/form-data" class="custom-order-form">
                            <?= app_csrf_input() ?>
                            <input type="hidden" name="action" value="submit_custom_request">
                            <div class="form-grid">
                                <div class="form-field">
                                    <label for="project_title" data-co-text="customOrderIdeaTitleLabel">Idea title</label>
                                    <input type="text" id="project_title" name="project_title" maxlength="120" required placeholder="e.g. Pink bunny plushie" data-co-placeholder="customOrderIdeaTitlePlaceholder">
                                </div>
                                <div class="form-field">
                                    <label for="item_type" data-co-text="customOrderProductTypeLabel">Product type</label>
                                    <input type="text" id="item_type" name="item_type" maxlength="120" placeholder="Plushie, blanket, gift set..." data-co-placeholder="customOrderProductTypePlaceholder">
                                </div>
                            </div>
                            <div class="form-grid">
                                <div class="form-field">
                                    <label for="size" data-co-text="customOrderPreferredSizeLabel">Preferred size</label>
                                    <input type="text" id="size" name="size" maxlength="120" placeholder="Small, medium, exact cm..." data-co-placeholder="customOrderPreferredSizePlaceholder">
                                </div>
                                <div class="form-field">
                                    <label for="colours" data-co-text="customOrderPreferredColoursLabel">Preferred colours</label>
                                    <input type="text" id="colours" name="colours" maxlength="180" placeholder="Pink, cream, lavender..." data-co-placeholder="customOrderPreferredColoursPlaceholder">
                                </div>
                            </div>
                            <div class="form-grid">
                                <div class="form-field">
                                    <label for="budget" data-co-text="customOrderPreferredBudgetLabel">Preferred budget</label>
                                    <input type="text" id="budget" name="budget" maxlength="80" placeholder="Optional" data-co-placeholder="customOrderPreferredBudgetPlaceholder">
                                </div>
                                <div class="form-field">
                                    <label for="needed_by" data-co-text="customOrderNeededByLabel">Needed by</label>
                                    <input type="date" id="needed_by" name="needed_by">
                                </div>
                            </div>
                            <div class="form-field">
                                <label for="details" data-co-text="customOrderDescribeIdeaLabel">Describe your idea</label>
                                <textarea id="details" name="details" required placeholder="Tell Athina what you want, who it is for, preferred style, materials, and anything important." data-co-placeholder="customOrderDescribeIdeaPlaceholder"></textarea>
                            </div>
                            <div class="upload-panel">
                                <div class="form-field">
                                    <label for="referencePhoto" data-co-text="customOrderReferencePhotoLabel">Reference photo</label>
                                    <input type="file" id="referencePhoto" name="referencePhoto" accept="image/*">
                                </div>
                                <p data-co-text="customOrderReferencePhotoHelp">Optional. Uploaded PNG, JPG, or GIF files are converted to webp automatically.</p>
                                <div class="upload-preview" id="referencePreview"><img src="" alt="Reference preview" data-co-alt="customOrderReferencePreviewAlt"></div>
                            </div>
                            <button type="submit" class="custom-order-btn">
                                <i class="fas fa-paper-plane"></i>
                                <span data-co-text="customOrderSendWebsiteRequest">Send Website Request</span>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($isLoggedIn && !empty($customerOrders)): ?>
        <div class="container custom-order-dashboard" id="discussion">
            <aside class="custom-order-card">
                <div class="discussion-card-header">
                    <div>
                        <h2 data-co-text="customOrderYourRequestsTitle">Your Requests</h2>
                        <p data-co-text="customOrderYourRequestsText">Track website requests and replies from Athina.</p>
                    </div>
                </div>
                <div class="custom-order-list">
                    <?php foreach ($customerOrders as $orderRow): ?>
                        <?php
                        $orderId = (int)$orderRow['customOrderID'];
                        $status = (string)($orderRow['status'] ?? 'pending');
                        $label = $statusLabels[$status] ?? ucwords(str_replace('_', ' ', $status));
                        $isSelected = $selectedOrder && (int)$selectedOrder['customOrderID'] === $orderId;
                        ?>
                        <a href="custom_order.php?view=<?= $orderId ?>#discussion" class="custom-order-list-item<?= $isSelected ? ' is-selected' : '' ?>">
                            <div class="custom-order-list-top">
                                <strong>#<?= $orderId ?></strong>
                                <span class="custom-order-status-pill <?= coStatusClass($status) ?>" data-co-status="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label) ?></span>
                            </div>
                            <div class="custom-order-list-bottom">
                                <span><?= htmlspecialchars(coFormatDate((string)($orderRow['created_at'] ?? ''))) ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </aside>

            <section class="custom-order-card">
                <?php if (!$selectedOrder): ?>
                    <div class="empty-state" data-co-text="customOrderChooseRequestEmpty">Choose a custom request to see the discussion.</div>
                <?php else: ?>
                    <?php
                    $selectedId = (int)$selectedOrder['customOrderID'];
                    $selectedStatus = (string)($selectedOrder['status'] ?? 'pending');
                    $selectedLabel = $statusLabels[$selectedStatus] ?? ucwords(str_replace('_', ' ', $selectedStatus));
                    $photoPath = trim((string)($selectedOrder['photoReferencePath'] ?? ''));
                    $photoUrl = $photoPath !== '' ? ltrim(str_replace('\\', '/', $photoPath), '/') : '';
                    ?>
                    <div class="discussion-section-header">
                        <div>
                            <h2><span data-co-text="customOrderRequestLabel">Request</span> #<?= $selectedId ?></h2>
                            <p data-co-text="customOrderDiscussionHelp">Use this thread if Athina needs more information about your idea.</p>
                        </div>
                        <span class="custom-order-status-pill <?= coStatusClass($selectedStatus) ?>" data-co-status="<?= htmlspecialchars($selectedStatus, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($selectedLabel) ?></span>
                    </div>

                    <div class="custom-order-summary">
                        <div class="custom-order-summary-item">
                            <span data-co-text="customOrderAgreedPriceLabel">Agreed price</span>
                            <strong><?= htmlspecialchars(customOrderPriceLabel($selectedOrder), ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                        <div class="custom-order-summary-item">
                            <span data-co-text="customOrderTargetDateLabel">Target date</span>
                            <strong><?= htmlspecialchars(coFormatDate((string)($selectedOrder['deadline'] ?? ''))) ?></strong>
                        </div>
                        <div class="custom-order-summary-item">
                            <span data-co-text="customOrderPrivateCheckoutLabel">Private checkout</span>
                            <strong<?= !empty($privateCheckoutMeta['ready']) ? ' data-co-text="customOrderReadyLabel"' : '' ?>><?= !empty($privateCheckoutMeta['ready']) ? 'Ready' : '-' ?></strong>
                        </div>
                    </div>

                    <?php if (!empty($privateCheckoutMeta['ready'])): ?>
                        <div class="custom-order-offer-card" id="private-checkout">
                            <span class="offer-kicker" data-co-text="customOrderPrivateCheckoutLinkTitle">Private checkout link</span>
                            <?php if ($privateCheckoutLink !== ''): ?>
                                <p class="offer-note"><span data-co-text="customOrderPrivateCheckoutReadyText">Your private custom product is ready. Open it while signed in with your account email.</span> <strong><?= htmlspecialchars($customerEmail) ?></strong></p>
                                <a href="<?= htmlspecialchars($privateCheckoutLink) ?>" class="custom-order-link" data-co-text="customOrderOpenPrivateProduct">Open Private Product</a>
                            <?php else: ?>
                                <p class="offer-note">Enter the access code from your email to unlock the private product.</p>
                                <form method="POST" class="custom-order-reply-form custom-order-access-form">
                                    <?= app_csrf_input() ?>
                                    <input type="hidden" name="action" value="verify_private_checkout_code">
                                    <input type="hidden" name="customOrderID" value="<?= $selectedId ?>">
                                    <input type="text" name="accessCode" maxlength="32" required placeholder="Access code">
                                    <button type="submit" class="custom-order-btn">Unlock Private Product</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="custom-order-request-text">
                        <h3 data-co-text="customOrderYourRequestTitle">Your request</h3>
                        <div><?= nl2br(htmlspecialchars(customOrderBuildProductDescription($selectedOrder))) ?></div>
                    </div>

                    <?php if ($photoUrl !== ''): ?>
                        <div class="custom-order-reference">
                            <h3 data-co-text="customOrderReferencePhotoLabel">Reference photo</h3>
                            <img src="<?= htmlspecialchars($photoUrl) ?>" alt="Custom order reference" data-co-alt="customOrderReferenceAlt">
                        </div>
                    <?php endif; ?>

                    <div class="custom-order-messages">
                        <h3 data-co-text="customOrderDiscussionTitle">Discussion</h3>
                        <?php if (empty($selectedMessages)): ?>
                            <div class="empty-state" data-co-text="customOrderNoRepliesYet">No replies yet.</div>
                        <?php else: ?>
                            <div class="message-thread">
                                <?php foreach ($selectedMessages as $message): ?>
                                    <?php $senderRole = strtolower((string)($message['senderRole'] ?? 'system')); ?>
                                    <div class="message-bubble is-<?= htmlspecialchars($senderRole) ?>">
                                        <div class="message-meta">
                                            <strong data-co-sender-role="<?= htmlspecialchars($senderRole, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(ucfirst($senderRole)) ?></strong>
                                            <span><?= htmlspecialchars(coFormatDate((string)($message['createdAt'] ?? ''))) ?></span>
                                        </div>
                                        <p class="message-body"><?= nl2br(htmlspecialchars((string)$message['messageBody'])) ?></p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!in_array($selectedStatus, ['completed', 'cancelled'], true)): ?>
                        <div class="custom-order-reply">
                            <h3 data-co-text="customOrderReplyTitle">Reply to Athina</h3>
                            <form method="POST" class="custom-order-reply-form">
                                <?= app_csrf_input() ?>
                                <input type="hidden" name="action" value="customer_message">
                                <input type="hidden" name="customOrderID" value="<?= $selectedId ?>">
                                <textarea name="messageBody" required placeholder="Write your reply or extra information..." data-co-placeholder="customOrderReplyPlaceholder"></textarea>
                                <button type="submit" class="custom-order-btn" data-co-text="customOrderSendReplyAction">Send Reply</button>
                            </form>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </div>
        <?php endif; ?>
    </section>
</main>

<?php include __DIR__ . '/include/footer.php'; ?>
<?= app_csrf_bootstrap_script() ?>
<script>
(function () {
    var input = document.getElementById('referencePhoto');
    var preview = document.getElementById('referencePreview');
    if (!input || !preview) return;
    var img = preview.querySelector('img');
    input.addEventListener('change', function () {
        var file = input.files && input.files[0] ? input.files[0] : null;
        if (!file || !file.type || file.type.indexOf('image/') !== 0) {
            preview.classList.remove('is-visible');
            if (img) img.src = '';
            return;
        }
        if (img) img.src = URL.createObjectURL(file);
        preview.classList.add('is-visible');
    });
})();
</script>
</body>
</html>
