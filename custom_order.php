<?php
session_start();

require_once __DIR__ . "/authentication/database.php";
require_once __DIR__ . "/authentication/get_config.php";
require_once __DIR__ . "/include/security.php";
require_once __DIR__ . "/include/translation_helpers.php";
if (!defined('CUSTOM_ORDERS_DIRECT')) {
    define('CUSTOM_ORDERS_DIRECT', true);
}
require_once __DIR__ . "/modules/custom_orders.php";
if (!defined('CREATE_CUSTOM_PRODUCT_DIRECT')) {
    define('CREATE_CUSTOM_PRODUCT_DIRECT', true);
}
require_once __DIR__ . "/modules/create_custom_product.php";

ensureCustomOrdersTable($conn);

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
$errorMessage = '';

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
    $map = [
        'project_title' => 'Project title',
        'item_type' => 'Product type',
        'size' => 'Preferred size',
        'colours' => 'Preferred colours',
        'budget' => 'Preferred budget',
        'needed_by' => 'Needed by',
    ];
    foreach ($map as $key => $label) {
        $value = trim((string)($input[$key] ?? ''));
        if ($value !== '') {
            $lines[] = $label . ': ' . $value;
        }
    }
    $details = trim((string)($input['details'] ?? ''));
    if ($details !== '') {
        $lines[] = 'Customer details: ' . $details;
    }
    return trim(implode("\n", $lines));
}

function coLoadPrivateCheckoutLink(mysqli $conn, array $order): string
{
    $productId = (int)($order['sourceProductID'] ?? 0);
    if ($productId <= 0) {
        return '';
    }
    $stmt = $conn->prepare("SELECT productID, cartStatus, privateAccessToken FROM products WHERE productID = ? LIMIT 1");
    if (!$stmt) {
        return '';
    }
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row || (string)($row['cartStatus'] ?? '') !== 'made_to_order') {
        return '';
    }
    $token = trim((string)($row['privateAccessToken'] ?? ''));
    return $token !== '' ? generateAccessLink($productId, 'token', $token, null) : '';
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

        if ($action === "offer_response") {
            $orderId = (int)($_POST['customOrderID'] ?? 0);
            $offerId = (int)($_POST['offerID'] ?? 0);
            $decision = (string)($_POST['decision'] ?? '');
            respondToCustomOrderOffer($conn, $orderId, $offerId, $userId, $decision);
            $flag = strtolower($decision) === 'accept' ? 'offer_accepted=1' : 'offer_declined=1';
            header("Location: custom_order.php?{$flag}&view={$orderId}#discussion");
            exit;
        }
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage() !== '' ? $e->getMessage() : 'Something went wrong. Please try again.';
    }
}

if (isset($_GET['created'])) {
    $successMessage = 'Your website custom order request was sent to Athina.';
} elseif (isset($_GET['message_sent'])) {
    $successMessage = 'Your reply was sent.';
} elseif (isset($_GET['offer_accepted'])) {
    $successMessage = 'You accepted the offer. Athina has been notified.';
} elseif (isset($_GET['offer_declined'])) {
    $successMessage = 'You declined the offer. You can reply with changes if needed.';
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
$activeOffer = null;
$latestOffer = null;
$privateCheckoutLink = '';
if ($selectedOrder) {
    $selectedMessages = getCustomOrderMessages($conn, (int)$selectedOrder['customOrderID']);
    $activeOffer = getActiveCustomOrderOffer($conn, (int)$selectedOrder['customOrderID']);
    $latestOffer = getLatestCustomOrderOffer($conn, (int)$selectedOrder['customOrderID']);
    $privateCheckoutLink = coLoadPrivateCheckoutLink($conn, $selectedOrder);
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
                <div class="custom-order-steps">
                    <div>
                        <strong data-co-text="customOrderStep1Title">1. Recommended: chat on Instagram</strong>
                        <p data-co-text="customOrderStep1Text">Use Instagram when you want the fastest discussion about photos, colors, size, timing, and small changes with the shop owner.</p>
                    </div>
                    <div>
                        <strong data-co-text="customOrderStep2Title">2. Or send a website request</strong>
                        <p data-co-text="customOrderStep2Text">If you prefer the form, sign in first and send your idea from the website so replies, offers, and links stay connected to your account.</p>
                    </div>
                    <div>
                        <strong data-co-text="customOrderStep3Title">3. Athina reviews the idea</strong>
                        <p data-co-text="customOrderStep3Text">Athina can reply for more details, make an offer, accept the request, or let you know if the idea cannot be made.</p>
                    </div>
                    <div>
                        <strong data-co-text="customOrderStep4Title">4. Receive a private checkout link</strong>
                        <p data-co-text="customOrderStep4Text">When the custom piece is approved and priced, Athina sends you a private shop link that only your account can open.</p>
                    </div>
                </div>
            </div>

            <div class="custom-order-card custom-order-info-card">
                <?php if ($successMessage !== ''): ?>
                    <div class="custom-order-alert success"><?= htmlspecialchars($successMessage) ?></div>
                <?php endif; ?>
                <?php if ($errorMessage !== ''): ?>
                    <div class="custom-order-alert error"><?= htmlspecialchars($errorMessage) ?></div>
                <?php endif; ?>

                <h2>Choose how to start</h2>
                <div class="custom-order-info-list">
                    <div class="custom-order-info-item custom-order-recommended">
                        <strong>Recommended: Instagram chat</strong>
                        <p>Best when you want to share photos, compare colors, and agree details quickly with the shop owner.</p>
                        <a href="<?= htmlspecialchars($instagramUrl) ?>" target="_blank" rel="noopener noreferrer" class="custom-order-btn custom-order-instagram-btn">
                            <i class="fab fa-instagram"></i>
                            <span data-co-text="customOrderInstagramAction">Message on Instagram</span>
                        </a>
                    </div>
                    <div class="custom-order-info-item">
                        <strong>Second option: website request</strong>
                        <p>Send a structured request from your account. Athina can reply, ask for more details, make an offer, decline the idea, or send a private checkout product link.</p>
                    </div>
                </div>

                <div class="custom-order-cta-box" id="website-request">
                    <h3>Website custom order request</h3>
                    <?php if (!$isLoggedIn): ?>
                        <p class="form-note">You need a registered account before using the website request form.</p>
                        <div class="custom-order-inline-actions">
                            <a href="<?= htmlspecialchars($loginHref) ?>" class="custom-order-secondary-btn" data-co-text="customOrderLoginAction">Sign In</a>
                            <a href="<?= htmlspecialchars($registerHref) ?>" class="custom-order-secondary-btn" data-co-text="customOrderRegisterAction">Create Account</a>
                        </div>
                    <?php elseif (!$isProfileComplete): ?>
                        <div class="custom-order-alert info">Please complete your profile before sending a website custom order request.</div>
                        <a href="authentication/complete_profile.php" class="custom-order-secondary-btn">Complete Profile</a>
                    <?php elseif (!$isVerified): ?>
                        <div class="custom-order-alert info">Please verify your email before sending a website custom order request.</div>
                        <a href="authentication/verify.php" class="custom-order-secondary-btn">Verify Email</a>
                    <?php else: ?>
                        <form method="POST" enctype="multipart/form-data" class="custom-order-form">
                            <?= app_csrf_input() ?>
                            <input type="hidden" name="action" value="submit_custom_request">
                            <div class="form-grid">
                                <div class="form-field">
                                    <label for="project_title">Idea title</label>
                                    <input type="text" id="project_title" name="project_title" maxlength="120" required placeholder="e.g. Pink bunny plushie">
                                </div>
                                <div class="form-field">
                                    <label for="item_type">Product type</label>
                                    <input type="text" id="item_type" name="item_type" maxlength="120" placeholder="Plushie, blanket, gift set...">
                                </div>
                            </div>
                            <div class="form-grid">
                                <div class="form-field">
                                    <label for="size">Preferred size</label>
                                    <input type="text" id="size" name="size" maxlength="120" placeholder="Small, medium, exact cm...">
                                </div>
                                <div class="form-field">
                                    <label for="colours">Preferred colours</label>
                                    <input type="text" id="colours" name="colours" maxlength="180" placeholder="Pink, cream, lavender...">
                                </div>
                            </div>
                            <div class="form-grid">
                                <div class="form-field">
                                    <label for="budget">Preferred budget</label>
                                    <input type="text" id="budget" name="budget" maxlength="80" placeholder="Optional">
                                </div>
                                <div class="form-field">
                                    <label for="needed_by">Needed by</label>
                                    <input type="date" id="needed_by" name="needed_by">
                                </div>
                            </div>
                            <div class="form-field">
                                <label for="details">Describe your idea</label>
                                <textarea id="details" name="details" required placeholder="Tell Athina what you want, who it is for, preferred style, materials, and anything important."></textarea>
                            </div>
                            <div class="upload-panel">
                                <div class="form-field">
                                    <label for="referencePhoto">Reference photo</label>
                                    <input type="file" id="referencePhoto" name="referencePhoto" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif">
                                </div>
                                <p>Optional. Uploaded PNG, WEBP, or GIF files are converted to JPG automatically.</p>
                                <div class="upload-preview" id="referencePreview"><img src="" alt="Reference preview"></div>
                            </div>
                            <button type="submit" class="custom-order-btn">
                                <i class="fas fa-paper-plane"></i>
                                Send Website Request
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
                        <h2>Your Requests</h2>
                        <p>Track website requests and replies from Athina.</p>
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
                                <span class="custom-order-status-pill <?= coStatusClass($status) ?>"><?= htmlspecialchars($label) ?></span>
                            </div>
                            <div class="custom-order-list-bottom">
                                <span><?= htmlspecialchars(coFormatDate((string)($orderRow['created_at'] ?? ''))) ?></span>
                                <?php if ((int)($orderRow['pendingOfferCount'] ?? 0) > 0): ?>
                                    <span class="custom-order-inline-badge">Offer ready</span>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </aside>

            <section class="custom-order-card">
                <?php if (!$selectedOrder): ?>
                    <div class="empty-state">Choose a custom request to see the discussion.</div>
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
                            <h2>Request #<?= $selectedId ?></h2>
                            <p>Use this thread if Athina needs more information about your idea.</p>
                        </div>
                        <span class="custom-order-status-pill <?= coStatusClass($selectedStatus) ?>"><?= htmlspecialchars($selectedLabel) ?></span>
                    </div>

                    <div class="custom-order-summary">
                        <div class="custom-order-summary-item">
                            <span>Agreed price</span>
                            <strong><?= (float)($selectedOrder['agreedPrice'] ?? 0) > 0 ? 'EUR ' . number_format((float)$selectedOrder['agreedPrice'], 2) : '-' ?></strong>
                        </div>
                        <div class="custom-order-summary-item">
                            <span>Target date</span>
                            <strong><?= htmlspecialchars(coFormatDate((string)($selectedOrder['deadline'] ?? ''))) ?></strong>
                        </div>
                        <div class="custom-order-summary-item">
                            <span>Private checkout</span>
                            <strong><?= $privateCheckoutLink !== '' ? 'Ready' : '-' ?></strong>
                        </div>
                    </div>

                    <?php if ($privateCheckoutLink !== ''): ?>
                        <div class="custom-order-offer-card">
                            <span class="offer-kicker">Private checkout link</span>
                            <p class="offer-note">Your private custom product is ready. Open it while signed in with <?= htmlspecialchars($customerEmail) ?>.</p>
                            <a href="<?= htmlspecialchars($privateCheckoutLink) ?>" class="custom-order-link">Open Private Product</a>
                        </div>
                    <?php endif; ?>

                    <?php if ($activeOffer): ?>
                        <div class="custom-order-offer-card">
                            <div class="offer-card-head">
                                <div>
                                    <span class="offer-kicker">Offer awaiting your reply</span>
                                    <h3>Review Athina's offer</h3>
                                </div>
                            </div>
                            <div class="custom-order-offer-grid">
                                <div>
                                    <span>Price</span>
                                    <strong>EUR <?= number_format((float)$activeOffer['offeredPrice'], 2) ?></strong>
                                </div>
                                <div>
                                    <span>Target date</span>
                                    <strong><?= htmlspecialchars(coFormatDate((string)($activeOffer['proposedDeadline'] ?? ''))) ?></strong>
                                </div>
                            </div>
                            <?php if (trim((string)($activeOffer['offerNote'] ?? '')) !== ''): ?>
                                <p class="offer-note"><?= nl2br(htmlspecialchars((string)$activeOffer['offerNote'])) ?></p>
                            <?php endif; ?>
                            <div class="offer-actions">
                                <form method="POST">
                                    <?= app_csrf_input() ?>
                                    <input type="hidden" name="action" value="offer_response">
                                    <input type="hidden" name="customOrderID" value="<?= $selectedId ?>">
                                    <input type="hidden" name="offerID" value="<?= (int)$activeOffer['offerID'] ?>">
                                    <input type="hidden" name="decision" value="accept">
                                    <button type="submit" class="custom-order-btn offer-accept-btn">Accept Offer</button>
                                </form>
                                <form method="POST">
                                    <?= app_csrf_input() ?>
                                    <input type="hidden" name="action" value="offer_response">
                                    <input type="hidden" name="customOrderID" value="<?= $selectedId ?>">
                                    <input type="hidden" name="offerID" value="<?= (int)$activeOffer['offerID'] ?>">
                                    <input type="hidden" name="decision" value="decline">
                                    <button type="submit" class="custom-order-secondary-btn">Decline Offer</button>
                                </form>
                            </div>
                        </div>
                    <?php elseif ($latestOffer): ?>
                        <div class="custom-order-offer-card is-history">
                            <span class="offer-kicker">Latest offer</span>
                            <div class="custom-order-offer-grid">
                                <div>
                                    <span>Price</span>
                                    <strong>EUR <?= number_format((float)$latestOffer['offeredPrice'], 2) ?></strong>
                                </div>
                                <div>
                                    <span>Status</span>
                                    <strong><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string)$latestOffer['offerStatus']))) ?></strong>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="custom-order-request-text">
                        <h3>Your request</h3>
                        <div><?= nl2br(htmlspecialchars((string)($selectedOrder['requestDescription'] ?? ''))) ?></div>
                    </div>

                    <?php if ($photoUrl !== ''): ?>
                        <div class="custom-order-reference">
                            <h3>Reference photo</h3>
                            <img src="<?= htmlspecialchars($photoUrl) ?>" alt="Custom order reference">
                        </div>
                    <?php endif; ?>

                    <div class="custom-order-messages">
                        <h3>Discussion</h3>
                        <?php if (empty($selectedMessages)): ?>
                            <div class="empty-state">No replies yet.</div>
                        <?php else: ?>
                            <div class="message-thread">
                                <?php foreach ($selectedMessages as $message): ?>
                                    <?php $senderRole = strtolower((string)($message['senderRole'] ?? 'system')); ?>
                                    <div class="message-bubble is-<?= htmlspecialchars($senderRole) ?>">
                                        <div class="message-meta">
                                            <strong><?= htmlspecialchars(ucfirst($senderRole)) ?></strong>
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
                            <h3>Reply to Athina</h3>
                            <form method="POST" class="custom-order-reply-form">
                                <?= app_csrf_input() ?>
                                <input type="hidden" name="action" value="customer_message">
                                <input type="hidden" name="customOrderID" value="<?= $selectedId ?>">
                                <textarea name="messageBody" required placeholder="Write your reply or extra information..."></textarea>
                                <button type="submit" class="custom-order-btn">Send Reply</button>
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
