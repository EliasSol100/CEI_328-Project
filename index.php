<?php
session_start();
require_once "database.php";
require_once "get_config.php";

$system_title = getSystemConfig("site_title");
$logo_path = getSystemConfig("logo_path");
$moodle_url = getSystemConfig("moodle_url");

$role = "Null";
$fullName = "Guest";

if (isset($_SESSION["user"])) {
    $userId = $_SESSION["user"]["id"];
    $fullName = $_SESSION["user"]["full_name"] ?? 'User';
    $role = $_SESSION["user"]["role"] ?? 'user';

    $stmt = $conn->prepare("SELECT country, city, address, postcode, dob, phone FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    $fieldsComplete = $user && $user["country"] && $user["city"] && $user["address"] && $user["postcode"] && $user["dob"] && $user["phone"];
    $_SESSION["user"]["profile_complete"] = $fieldsComplete;

    if (!$fieldsComplete) {
        header("Location: complete_profile.php");
        exit();
    }

    $_SESSION['user_id'] = $userId;
    $_SESSION['role'] = $role;
}

$hasApplications = false;

if (isset($_SESSION["user"])) {
    $userId = $_SESSION["user"]["id"];
    $fullName = $_SESSION["user"]["full_name"] ?? 'User';
    $role = $_SESSION["user"]["role"] ?? 'user';

    $stmt = $conn->prepare("SELECT country, city, address, postcode, dob, phone FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    $fieldsComplete = $user && $user["country"] && $user["city"] && $user["address"] && $user["postcode"] && $user["dob"] && $user["phone"];
    $_SESSION["user"]["profile_complete"] = $fieldsComplete;

    if (!$fieldsComplete) {
        header("Location: complete_profile.php");
        exit();
    }

    $_SESSION['user_id'] = $userId;
    $_SESSION['role'] = $role;

    //Moved inside to get access to $userId
    if ($role === 'user') {
        $appQuery = $conn->prepare("SELECT COUNT(*) FROM applications WHERE user_id = ?");
        $appQuery->bind_param("i", $userId);
        $appQuery->execute();
        $appQuery->bind_result($appCount);
        $appQuery->fetch();
        $hasApplications = $appCount > 0;
        $appQuery->close();
    }
}

$totalUsers = $totalApps = $activePeriods = 0;

try {
    $res1 = $conn->query("SELECT COUNT(*) AS total FROM users");
    $totalUsers = $res1 ? $res1->fetch_assoc()['total'] : 0;

    $res2 = $conn->query("SELECT COUNT(*) AS total FROM applications");
    $totalApps = $res2 ? $res2->fetch_assoc()['total'] : 0;

    $res3 = $conn->query("SELECT COUNT(*) AS total FROM application_periods WHERE start_date <= CURDATE() AND end_date >= CURDATE()");
    $activePeriods = $res3 ? $res3->fetch_assoc()['total'] : 0;
} catch (Exception $e) {
    $totalUsers = $totalApps = $activePeriods = 0;
}

$totalApps = $pendingApps = $reviewedApps = $thisMonthApps = 0;

if (in_array($role, ['hr', 'admin', 'owner'])) {
    try {
        $res1 = $conn->query("SELECT COUNT(*) AS total FROM applications");
        $totalApps = $res1 ? $res1->fetch_assoc()['total'] : 0;

        $res2 = $conn->query("SELECT COUNT(*) AS total FROM applications WHERE status = 'pending'");
        $pendingApps = $res2 ? $res2->fetch_assoc()['total'] : 0;

        $res3 = $conn->query("SELECT COUNT(*) AS total FROM applications WHERE status != 'pending'");
        $reviewedApps = $res3 ? $res3->fetch_assoc()['total'] : 0;

        $res4 = $conn->query("SELECT COUNT(*) AS total FROM applications WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
        $thisMonthApps = $res4 ? $res4->fetch_assoc()['total'] : 0;
    } catch (Exception $e) {
        $totalApps = $pendingApps = $reviewedApps = $thisMonthApps = 0;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($system_title) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css">
  <link href="https://fonts.googleapis.com/css2?family=Fira+Code&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="indexstyle.css">
  <link rel="stylesheet" href="darkmode.css">
  <!-- AOS Animate on Scroll CSS -->
  <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">

</head>

<body>
<div id="scrollProgress" style="height: 4px; background: #4da3ff; width: 0%; position: fixed; top: 0; left: 0; z-index: 9999;"></div>

<!-- Navbar -->
<?php include "navbar.php"; ?>


<!-- Hero Section -->
<div class="hero-section">

  <div class="container">
    
    <h1>Welcome to the Special Scientists System</h1>
    <p class="lead mb-2">A centralized portal for:</p>
    <p id="typedText" class="fs-4 typed-cursor"></p>



    <?php if ($role === 'user'): ?>
      <div class="mt-4 text-center">
        <p class="fs-5">Start or manage your application process for Special Scientist positions:</p>
        <div class="d-flex justify-content-center gap-3 flex-wrap">
          <a href="application_form.php" class="btn btn-success btn-shiny px-4 py-2 fs-5">New Application</a>
          <a href="my_applications.php" class="btn btn-primary btn-shiny px-4 py-2 fs-5">View My Applications</a>
        </div>
      </div>
    <?php elseif (!isset($_SESSION['user'])): ?>
      <div class="d-flex justify-content-center mt-4">
      <div class="alert alert-info text-center" role="alert" style="max-width: 500px;">
        Please <a href="login.php" class="btn btn-info px-4 py-2 fw-bold">Login</a> to apply for a Special Scientist position.
      </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<div style="overflow:hidden;line-height:0;">
  <svg viewBox="0 0 500 150" preserveAspectRatio="none" style="height: 60px; width: 100%;">
    <path d="M-0.27,104.71 C149.99,150.00 349.99,54.28 500.84,104.71 L500.00,0.00 L0.00,0.00 Z" style="stroke: none; fill: #ffffff;"></path>
  </svg>
</div>


<!-- Main Content -->
<div class="container content-section py-5 border-bottom">
  <div class="row">
    
    <div class="col-md-4" data-aos="fade-up" data-aos-delay="100">
      <h3>Candidate Services</h3>
      <p>Support for Prospective Special Scientists - Explore open calls for special scientist positions, submit your applications, and monitor your application progress with full transparency and accountability.</p>
    </div>
    <div class="col-md-4" data-aos="fade-up" data-aos-delay="200">
      <h3>Active Engagement</h3>
      <p>Tools for Enrolled Special Scientists - Access your academic responsibilities, connect with course environments via Moodle, and maintain your profile as part of the university's extended academic team.</p>
    </div>
    <div class="col-md-4" data-aos="fade-up" data-aos-delay="300">
      <h3>Administrative Management</h3>
      <p>Efficient Oversight for HR and Academic Coordinators - Streamline the recruitment, enrollment, and reporting processes for special scientists — with data-driven tools and real-time access to all institutional workflows.</p>
    </div>
  </div>
</div>


<!-- FAQ Section -->
<div class="container text-center py-5 border-bottom" data-aos="fade-up">
<h2 class="section-heading">Frequently Asked Questions</h2>
  <div class="accordion" id="faqAccordion">
    <div class="accordion-item">
      <h2 class="accordion-header"><button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#q1">Who can apply?</button></h2>
      <div id="q1" class="accordion-collapse collapse show"><div class="accordion-body">Anyone with academic or research qualifications relevant to the posted positions.</div></div>
    </div>
    <div class="accordion-item">
      <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#q2">What documents are required?</button></h2>
      <div id="q2" class="accordion-collapse collapse"><div class="accordion-body">A CV, degree certificates, ID, and declaration of consent.</div></div>
    </div>
  </div>
</div>



<!-- Application Period Timeline with Filters -->
<div class="container py-5 border-bottom" data-aos="fade-up">
  <h2 class="section-heading text-center mb-4">Application Periods Timeline</h2>

  <!-- Filter Buttons -->
  <div class="d-flex justify-content-center gap-3 mb-4">
    <button class="btn btn-outline-success active" data-filter="open">
      🟢 Open
    </button>
    <button class="btn btn-outline-info" data-filter="upcoming">
      🔵 Upcoming
    </button>
    <button class="btn btn-outline-secondary" data-filter="closed">
      ⚫ Closed
    </button>
    <button class="btn btn-outline-dark" data-filter="all">
      🔄 All
    </button>
  </div>

  <!-- Timeline Cards -->
  <div id="periodTimeline" class="d-flex overflow-auto gap-3 px-2 flex-wrap justify-content-center">
    <?php
      $now = date("Y-m-d");
      $periods = $conn->query("SELECT name, start_date, end_date FROM application_periods");
      $all = [];

      while ($row = $periods->fetch_assoc()) {
          $start = $row['start_date'];
          $end = $row['end_date'];
          $status = "closed";

          if ($start <= $now && $end >= $now) $status = "open";
          elseif ($start > $now) $status = "upcoming";

          $all[] = array_merge($row, ["status" => $status]);
      }

      // Sort each group by start_date
      $open = array_filter($all, fn($p) => $p['status'] === 'open');
      $upcoming = array_filter($all, fn($p) => $p['status'] === 'upcoming');
      $closed = array_filter($all, fn($p) => $p['status'] === 'closed');

      usort($open, fn($a, $b) => strtotime($a['start_date']) - strtotime($b['start_date']));
      usort($upcoming, fn($a, $b) => strtotime($a['start_date']) - strtotime($b['start_date']));
      usort($closed, fn($a, $b) => strtotime($b['start_date']) - strtotime($a['start_date'])); // most recent closed first

      $sorted = array_merge($open, $upcoming, $closed);


      foreach ($sorted as $row):
        $start = date("M j, Y", strtotime($row['start_date']));
        $end = date("M j, Y", strtotime($row['end_date']));
        $status = $row['status'];
        $badgeClass = $status === "open" ? "success" : ($status === "upcoming" ? "info" : "secondary");
    ?>
    <div class="card shadow-sm flex-shrink-0 timeline-card" data-status="<?= $status ?>" style="min-width: 220px;">
      <div class="card-body">
        <h5 class="card-title"><?= htmlspecialchars($row['name']) ?></h5>
        <p class="card-text small"><?= $start ?> – <?= $end ?></p>
        <span class="badge bg-<?= $badgeClass ?> text-capitalize"><?= $status ?></span>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <p class="text-muted mt-3 text-center small">Use the buttons to filter the periods you're interested in.</p>
</div>

<script>
  document.addEventListener("DOMContentLoaded", () => {
    const buttons = document.querySelectorAll("[data-filter]");
    const cards = document.querySelectorAll(".timeline-card");

    buttons.forEach(btn => {
      btn.addEventListener("click", () => {
        const filter = btn.getAttribute("data-filter");

        // Update button styling
        buttons.forEach(b => b.classList.remove("active"));
        btn.classList.add("active");

        // Filter cards
        cards.forEach(card => {
          const status = card.getAttribute("data-status");
          if (filter === "all" || status === filter) {
            card.style.display = "block";
          } else {
            card.style.display = "none";
          }
        });
      });
    });
  });
</script>




<!-- Stats For Staff Section -->
<?php if (in_array($role, ['hr', 'admin', 'owner'])): ?>
<div class="container text-center py-5" data-aos="fade-up">
  <h2 class="section-heading">Recruitment Overview</h2>
  <div class="row justify-content-center g-4">
    <div class="col-md-3">
      <div class="dashboard-box">
        <h2 class="fw-bold text-primary"><?= $totalApps ?></h2>
        <p>Total Applications</p>
      </div>
    </div>
    <div class="col-md-3">
      <div class="dashboard-box">
        <h2 class="fw-bold text-warning"><?= $pendingApps ?></h2>
        <p>Pending Review</p>
      </div>
    </div>
    <div class="col-md-3">
      <div class="dashboard-box">
        <h2 class="fw-bold text-success"><?= $reviewedApps ?></h2>
        <p>Reviewed Applications</p>
      </div>
    </div>
    <div class="col-md-3">
      <div class="dashboard-box">
        <h2 class="fw-bold text-info"><?= $thisMonthApps ?></h2>
        <p>This Month</p>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Mail Help -->
<a href="mailto:administration@cut.ac.cy" class="position-fixed bottom-0 end-0 m-4 btn btn-info shadow-lg rounded-pill">
  <i class="bi bi-envelope-fill me-1"></i> Ask a Question
</a>

<!-- Feedback Floating Bubble -->
<div id="feedbackBubble" class="feedback-bubble" title="Send Feedback">
  <i class="bi bi-chat-dots-fill fs-4"></i>
</div>

<?php if (in_array($_SESSION['user']['role'] ?? '', ['admin', 'owner'])): ?>
<div id="adminFeedbackPanel" class="feedback-panel shadow" style="max-height: 400px; overflow-y: auto; display: none;">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <span class="fw-bold"><i class="bi bi-list-check me-1"></i>All Feedback</span>
    <button class="btn-close btn-sm" onclick="toggleFeedbackPanel()"></button>
  </div>
  <div id="feedbackList">Loading...</div>
</div>
<?php endif; ?>


<!-- Feedback Slide-in Box -->
<div id="feedbackPanel" class="feedback-panel shadow">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <button class="btn-close btn-sm" onclick="toggleFeedbackPanel()"></button>
  </div>
  <form id="feedbackForm" action="send_feedback.php" method="post" onsubmit="return confirmSendFeedback();">
    <div class="form-group mb-2">
      <textarea id="feedbackInput" class="form-control feedback-textarea" name="feedback" rows="2" required placeholder="Type your message..."></textarea>
    </div>
    <button id="sendFeedbackBtn" type="button" class="btn btn-primary">Send Feedback</button>

  </form>
</div>

<!-- Guest Feedback Notice -->
<div id="guestFeedbackPanel" class="feedback-panel shadow" style="display: none;">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <span class="fw-bold"><i class="bi bi-person-lock me-1"></i>Login Required</span>
    <button class="btn-close btn-sm" onclick="toggleFeedbackPanel()"></button>
  </div>
  <p>You need to log in to send feedback.</p>
  <a href="login.php" class="btn btn-primary w-100 mt-2">Login Now</a>
</div>

<!-- Next Session Open -->
<?php
$nextPeriod = $conn->query("SELECT name, start_date FROM application_periods WHERE start_date > CURDATE() ORDER BY start_date ASC LIMIT 1");
$periodInfo = $nextPeriod->fetch_assoc();
?>
<?php if ($periodInfo): ?>
<div class="alert alert-info text-center mt-4 w-75 mx-auto shadow-sm">
  🎯 Next Call: <strong><?= htmlspecialchars($periodInfo['name']) ?></strong> opens on <strong><?= date("F j, Y", strtotime($periodInfo['start_date'])) ?></strong>
</div>
<?php endif; ?>


<!-- Footer -->
<footer class="footer mt-auto py-4 bg-dark text-white">
  <div class="container text-center">
    <div class="row mb-4">
      <div class="col-md-3 mb-3">
        <img src="Tepak-logo-white.png" alt="University Logo" class="footer-logo">
      </div>
      
      <div class="col-md-3 mb-3 text-start">
        <h5>Quick Links</h5>
        <ul class="list-unstyled">
          <li><a href="https://www.cut.ac.cy" class="text-white text-decoration-none" target="_blank">University Website</a></li>
          <li>
  <a href="https://www.cut.ac.cy/university/about/" class="text-white text-decoration-none" target="_blank">
    <?= $_SESSION['lang'] === 'el' ? 'Σχετικά' : 'About' ?>
  </a>
</li>
          <li><a href="https://www.cut.ac.cy/students/practical-information/enrolment/academic-calendar/?languageId=1" class="text-white text-decoration-none" target="_blank">Academic Calendar</a></li>
        </ul>
      </div>
      <div class="col-md-3 mb-3 text-start">
        <h5>Resources</h5>
        <ul class="list-unstyled">
          <li><a href="<?= htmlspecialchars($moodle_url) ?>" class="text-white text-decoration-none" target="_blank">Moodle</a></li>
          <li>
  <a href="https://www.cut.ac.cy/university/administration/administrative-services/ist/support/" class="text-white text-decoration-none" target="_blank">
    <?= $_SESSION['lang'] === 'el' ? 'Υποστήριξη IT' : 'IT Support' ?>
  </a>
</li>
        </ul>
      </div>
      <div class="col-md-3 mb-3 text-start">
  <h5>Contact Info</h5>
  <p class="mb-2">
    <i class="bi bi-geo-alt-fill me-1"></i>
    <a href="https://www.google.com/maps/place/Cyprus+University+of+Technology/@34.6757317,33.0436313,17z" target="_blank" class="text-white text-decoration-none">
      Cyprus University of Technology, Limassol
    </a>
  </p>
  <p class="mb-2"><i class="bi bi-telephone-fill me-1"></i> +357 25 002500</p>
  <p><i class="bi bi-envelope-fill me-1"></i> info@cut.ac.cy</p>
</div>

    <div class="text-center small text-white-50">
      &copy; 2025 Cyprus University of Technology. All rights reserved.
    </div>
  </div>
</footer>

<!-- Shared Confirmation Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 rounded-3">
      <div class="modal-header">
        <h5 class="modal-title">Please Confirm</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="confirmModalBody">Are you sure?</div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="confirmModalYes">Yes, Confirm</button>
      </div>
    </div>
  </div>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
  const toggle = document.getElementById('darkModeToggle');
  const body = document.body;

  const savedMode = localStorage.getItem('dark-mode');
  if (savedMode === 'true') {
    body.classList.add('dark-mode');
    toggle.checked = true;
  }

  toggle.addEventListener('change', () => {
    body.classList.toggle('dark-mode');
    localStorage.setItem('dark-mode', body.classList.contains('dark-mode'));
  });

  window.addEventListener("pageshow", function (event) {
    if (event.persisted || (window.performance && window.performance.getEntriesByType("navigation")[0].type === "back_forward")) {
      window.location.reload();
    }
  });
</script>
<!-- AOS Animate on Scroll JS -->
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
<script>AOS.init();</script>

<script>
  window.onscroll = function () {
    const scrollTop = window.scrollY || document.documentElement.scrollTop;
    const docHeight = document.documentElement.scrollHeight - document.documentElement.clientHeight;
    const scrollPercent = (scrollTop / docHeight) * 100;
    document.getElementById("scrollProgress").style.width = scrollPercent + "%";
  };
</script>
<script>
    document.addEventListener("DOMContentLoaded", () => {
    const phrases = <?= json_encode(
    $_SESSION['lang'] === 'el'
      ? [
          "Αίτηση για θέσεις έρευνας.",
          "Διαχείριση του ακαδημαϊκού σας προφίλ.",
          "Παρακολούθηση της προόδου της αίτησής σας."
        ]
      : [
          "Applying to research positions.",
          "Managing your academic profile.",
          "Tracking your application progress."
        ]
  ); ?>;

    const el = document.getElementById("typedText");
    let i = 0;

    const typePhrase = async (text) => {
      for (let j = 0; j <= text.length; j++) {
        el.textContent = text.substring(0, j);
        await new Promise(res => setTimeout(res, 60));
      }
    };

    const erasePhrase = async (text) => {
      for (let j = text.length; j >= 0; j--) {
        el.textContent = text.substring(0, j);
        await new Promise(res => setTimeout(res, 30));
      }
    };

    const loop = async () => {
      while (true) {
        const phrase = phrases[i];
        el.style.opacity = 1;
        await typePhrase(phrase);
        await new Promise(res => setTimeout(res, 1500));
        await erasePhrase(phrase);
        el.style.opacity = 0.4;
        await new Promise(res => setTimeout(res, 300));
        i = (i + 1) % phrases.length;
      }
    };

    loop();
  });
</script>
<script>
document.addEventListener("DOMContentLoaded", () => {

  // Load feedback list
  function loadFeedbacks() {
    fetch("get_feedback_list.php")
      .then(res => res.text())
      .then(html => {
        document.getElementById("feedbackList").innerHTML = html;
      });
  }

  // Delete feedback using event delegation
  document.body.addEventListener("click", (e) => {
    if (e.target.classList.contains("delete-feedback-btn")) {
      e.preventDefault();
      const id = e.target.getAttribute("data-id");
      
      // Check if ID is present
      if (!id) {
        alert("Feedback ID missing!");
        return;
      }

      // Show confirmation modal
      showConfirmModal("Delete this feedback?", () => {
        fetch("delete_feedback.php", {
          method: "POST",
          headers: {
            "Content-Type": "application/x-www-form-urlencoded"
          },
          body: "id=" + encodeURIComponent(id)
        })
        .then(res => {
          if (res.ok){
            loadFeedbacks();
            showNotification("Feedback deleted!");
          } 
          else alert("Failed to delete feedback. Status: " + res.status);
        })
        .catch(err => {
          console.error("Fetch error:", err);
          alert("An error occurred: " + err.message);
        });
      });
    }

    // Clear all feedback button
    if (e.target.id === "clearAllBtn") {
      showConfirmModal("Delete ALL feedback?", () => {
        fetch("clear_feedback.php", { method: "POST" })
          .then(res => {
            if (res.ok){
              loadFeedbacks();
              showNotification("All feedback cleared!");
            }
            else alert("Failed to clear feedback.");
          });
      });
      
    }
  });

  // Feedback panel toggle
  document.getElementById('feedbackBubble')?.addEventListener('click', () => {
    const role = "<?= $_SESSION['user']['role'] ?? '' ?>";
    const guest = "<?= isset($_SESSION['user']) ? '0' : '1' ?>";

    const adminPanel = document.getElementById("adminFeedbackPanel");
    const userPanel = document.getElementById("feedbackPanel");
    const guestPanel = document.getElementById("guestFeedbackPanel");

    // Hide all first
    [adminPanel, userPanel, guestPanel].forEach(p => {
      if (p) p.style.display = 'none';
    });

    // Show the correct one
    if (guest === '1') {
      guestPanel.style.display = 'block';
    } else if (role === 'admin' || role === 'owner') {
      adminPanel.style.display = 'block';
      loadFeedbacks();
    } else {
      userPanel.style.display = 'block';
    }
  });

  // Submit feedback (already working, unchanged)
  document.getElementById("sendFeedbackBtn")?.addEventListener("click", (e) => {
    e.preventDefault();
    const text = document.getElementById("feedbackInput")?.value.trim();
    if (!text) return;

    showConfirmModal("Submit this feedback?", () => {
      fetch("send_feedback.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: "feedback=" + encodeURIComponent(text)
      }).then(() => {
        document.getElementById("feedbackInput").value = "";
        showNotification("Feedback sent!");
      });
    });


  });

  // Confirmation modal logic (already working)
  let confirmCallback = null;
  document.getElementById('confirmModalYes').addEventListener('click', () => {
    if (typeof confirmCallback === 'function') {
      confirmCallback();
      confirmCallback = null;
    }
    bootstrap.Modal.getInstance(document.getElementById('confirmModal')).hide();
  });

  function showConfirmModal(message, onConfirm) {
    document.getElementById('confirmModalBody').textContent = message;
    const modal = new bootstrap.Modal(document.getElementById('confirmModal'));
    modal.show();
    confirmCallback = onConfirm;
  }

});
</script>
<script>
document.addEventListener("DOMContentLoaded", () => {
  const notif = document.getElementById("feedbackNotification");
  const role = "<?= $_SESSION['user']['role'] ?? '' ?>";
  const bubble = document.getElementById("feedbackBubble");
  const adminPanel = document.getElementById("adminFeedbackPanel");
  const userPanel = document.getElementById("feedbackPanel");
  const modal = document.getElementById("confirmModal");

  const panel = (role === "admin" || role === "owner") ? adminPanel : userPanel;

  // Show toast
  window.showNotification = (msg) => {
    notif.textContent = msg;
    notif.classList.add("show");
    setTimeout(() => notif.classList.remove("show"), 3000);
  };


  // "X" close button
  document.querySelectorAll(".feedback-panel .btn-close").forEach(btn => {
    btn.addEventListener("click", (e) => {
      e.stopPropagation();
      e.target.closest(".feedback-panel").style.display = "none";
    });
  });

  // Close if clicked outside
  document.addEventListener("click", (e) => {
    if (modal.classList.contains("show") || modal.contains(e.target)) return;
    if (!bubble.contains(e.target) && !panel.contains(e.target)) {
      panel.style.display = "none";
    }
  });


});
</script>
<div id="feedbackNotification" class="feedback-notification"></div>
<style>
  button img {
    cursor: pointer;
  }
</style>
</body>
</html>
