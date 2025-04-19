<?php
// view_offices.php
// Displays ratings, evaluations, and SQD weighted means for all offices with filtering and reply functionality

// Start output buffering to capture any unintended output
ob_start();

// Suppress display of errors to prevent breaking JSON; log them instead
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start session for CSRF protection
session_start();

// Generate CSRF token if not set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require 'Config/db_connection.php';
require_once 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// Send reply email directly to client
function sendReplyEmail($pdo, $submission_id, $message)
{
    try {
        error_log("sendReplyEmail: Starting for submission_id=$submission_id");

        // Sanitize and validate input
        $message = trim(filter_var($message, FILTER_SANITIZE_STRING));
        if (empty($message)) {
            error_log("sendReplyEmail: Empty message for submission_id=$submission_id");
            return ['error' => 'Reply message cannot be empty.'];
        }

        if (!filter_var($submission_id, FILTER_VALIDATE_INT) || $submission_id <= 0) {
            error_log("sendReplyEmail: Invalid submission_id=$submission_id");
            return ['error' => 'Invalid submission ID.'];
        }

        // Check for existing reply
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_replies WHERE submission_id = :submission_id");
        $stmt->execute(['submission_id' => $submission_id]);
        if ($stmt->fetchColumn() > 0) {
            error_log("sendReplyEmail: Reply already exists for submission_id=$submission_id");
            return ['error' => 'A reply has already been sent for this submission.'];
        }

        // Begin transaction
        $pdo->beginTransaction();

        // Fetch submission details
        $stmt = $pdo->prepare("
            SELECT fs.submission_token, o.office_name, se.email
            FROM form_submissions fs
            JOIN clients c ON fs.client_id = c.client_id
            JOIN offices o ON c.office_id = o.office_id
            LEFT JOIN submission_emails se ON fs.submission_id = se.submission_id
            WHERE fs.submission_id = :submission_id
        ");
        $stmt->execute(['submission_id' => $submission_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            error_log("sendReplyEmail: No submission found for submission_id=$submission_id");
            $pdo->rollBack();
            return ['error' => 'Submission not found.'];
        }

        if (empty($result['email'])) {
            error_log("sendReplyEmail: No email found for submission_id=$submission_id");
            $pdo->rollBack();
            return ['error' => 'No email provided by the client. Cannot send reply.'];
        }

        if (!filter_var($result['email'], FILTER_VALIDATE_EMAIL)) {
            error_log("sendReplyEmail: Invalid email format for submission_id=$submission_id: " . $result['email']);
            $pdo->rollBack();
            return ['error' => 'Invalid client email address.'];
        }

        // Store reply in admin_replies
        $stmt = $pdo->prepare("
            INSERT INTO admin_replies (submission_id, reply_text, replied_at) 
            VALUES (:submission_id, :reply_text, NOW())
        ");
        $stmt->execute([
            'submission_id' => $submission_id,
            'reply_text' => $message
        ]);
        error_log("sendReplyEmail: Reply stored in admin_replies for submission_id=$submission_id");

        // Initialize PHPMailer
        $mail = new PHPMailer(true);
        $mail->SMTPDebug = SMTP::DEBUG_OFF;
        $mail->Debugoutput = function ($str, $level) use ($submission_id) {
            error_log("PHPMailer Debug [submission_id=$submission_id, level=$level]: $str");
        };

        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = GMAIL_USERNAME;
        $mail->Password = GMAIL_APP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->Timeout = 30;
        $mail->SMTPKeepAlive = false;

        $mail->setFrom(GMAIL_USERNAME, GMAIL_FROM_NAME);
        $mail->addAddress($result['email']);
        $mail->addReplyTo(GMAIL_REPLY_TO_EMAIL, GMAIL_REPLY_TO_NAME);

        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = "Reply to Your Feedback - Submission {$result['submission_token']}";
        $mail->Body = "
            <h2>Response from " . htmlspecialchars($result['office_name'], ENT_QUOTES, 'UTF-8') . "</h2>
            <p><strong>Submission ID:</strong> " . htmlspecialchars($result['submission_token'], ENT_QUOTES, 'UTF-8') . "</p>
            <p><strong>Our Reply:</strong> " . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "</p>
            <p>Thank you for your feedback!</p>
            <p>Best regards,<br>" . htmlspecialchars(GMAIL_FROM_NAME, ENT_QUOTES, 'UTF-8') . "</p>
        ";
        $mail->AltBody = "
            Response from " . htmlspecialchars($result['office_name'], ENT_QUOTES, 'UTF-8') . "\n
            Submission ID: " . htmlspecialchars($result['submission_token'], ENT_QUOTES, 'UTF-8') . "\n
            Our Reply: " . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "\n
            Thank you for your feedback!\n
            Best regards,\n" . htmlspecialchars(GMAIL_FROM_NAME, ENT_QUOTES, 'UTF-8');

        error_log("sendReplyEmail: Attempting to send email to {$result['email']} for submission_id=$submission_id");
        $mail->send();
        error_log("sendReplyEmail: Email sent successfully to {$result['email']} for submission_id=$submission_id");

        // Log email success
        $stmt = $pdo->prepare("
            INSERT INTO email_logs (submission_id, email_type, status, logged_at) 
            VALUES (:submission_id, 'admin_reply', 'sent', NOW())
        ");
        $stmt->execute(['submission_id' => $submission_id]);

        // Commit transaction
        $pdo->commit();
        return ['success' => 'Reply sent successfully to ' . htmlspecialchars($result['email'], ENT_QUOTES, 'UTF-8') . '.'];
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("sendReplyEmail: Failed for submission_id=$submission_id: " . $e->getMessage());
        try {
            $stmt = $pdo->prepare("
                INSERT INTO email_logs (submission_id, email_type, status, error_message, logged_at) 
                VALUES (:submission_id, 'admin_reply', 'failed', :error, NOW())
            ");
            $stmt->execute([
                'submission_id' => $submission_id,
                'error' => substr($e->getMessage(), 0, 255)
            ]);
        } catch (PDOException $log_e) {
            error_log("sendReplyEmail: Failed to log email error for submission_id=$submission_id: " . $log_e->getMessage());
        }
        return ['error' => 'Failed to send reply: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')];
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("sendReplyEmail: Database error for submission_id=$submission_id: " . $e->getMessage());
        return ['error' => 'Database error occurred. Please try again later.'];
    }
}

// Handle reply submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_reply'])) {
    // Clear any existing output
    ob_clean();

    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        error_log("POST: CSRF token mismatch for send_reply");
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Invalid CSRF token.'], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        exit;
    }

    $submission_id = filter_input(INPUT_POST, 'submission_id', FILTER_VALIDATE_INT);
    $reply_text = trim(filter_input(INPUT_POST, 'reply_message', FILTER_SANITIZE_STRING) ?? '');
    error_log("POST received: send_reply for submission_id=$submission_id, message=" . substr($reply_text, 0, 50));

    header('Content-Type: application/json; charset=utf-8');
    if ($submission_id && $reply_text) {
        $reply_message = sendReplyEmail($pdo, $submission_id, $reply_text);
    } else {
        $reply_message = ['error' => 'Invalid submission ID or empty reply message.'];
        error_log("Invalid POST: submission_id=$submission_id, message_empty=" . (empty($reply_text) ? 'yes' : 'no'));
    }

    echo json_encode($reply_message, JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}

// Determine filter
$filter = filter_input(INPUT_GET, 'filter', FILTER_SANITIZE_STRING) ?? 'all';
$start_date = null;
if ($filter === 'week') {
    $start_date = date('Y-m-d', strtotime('-7 days'));
} elseif ($filter === 'month') {
    $start_date = date('Y-m-d', strtotime('-30 days'));
}

// Fetch offices, their average ratings, and SQD weighted means
$offices_data = [];
try {
    $query = "
        SELECT o.office_id, o.office_name, 
               AVG(orat.rating) as avg_rating, 
               COUNT(orat.rating) as rating_count,
               AVG(sqd.weighted_mean) as avg_sqd_rating,
               q.question_code, q.question_text,
               AVG(CASE WHEN q.question_code LIKE 'SQD%' THEN sqd.weighted_mean END) as question_weighted_mean
        FROM offices o
        LEFT JOIN clients c ON o.office_id = c.office_id
        LEFT JOIN form_submissions fs ON c.client_id = fs.client_id
        LEFT JOIN overall_ratings orat ON fs.submission_id = orat.submission_id
        LEFT JOIN sqd_ratings sqd ON fs.submission_id = sqd.submission_id
        LEFT JOIN questions q ON sqd.question_id = q.question_id
    ";
    if ($start_date) {
        $query .= " WHERE c.date_of_visit >= :start_date";
    }
    $query .= " GROUP BY o.office_id, o.office_name, q.question_id, q.question_code, q.question_text ORDER BY o.office_name";

    $stmt = $pdo->prepare($query);
    if ($start_date) {
        $stmt->execute(['start_date' => $start_date]);
    } else {
        $stmt->execute();
    }
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($data as $row) {
        $office_id = $row['office_id'];
        if (!isset($offices_data[$office_id])) {
            $offices_data[$office_id] = [
                'office_name' => $row['office_name'],
                'avg_rating' => $row['avg_rating'],
                'rating_count' => $row['rating_count'],
                'avg_sqd_rating' => $row['avg_sqd_rating'],
                'questions' => []
            ];
        }
        if ($row['question_code']) {
            $offices_data[$office_id]['questions'][] = [
                'question_code' => $row['question_code'],
                'question_text' => $row['question_text'],
                'weighted_mean' => $row['question_weighted_mean']
            ];
        }
    }
} catch (PDOException $e) {
    error_log("Failed to fetch offices: " . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    echo '<div class="error-message">Error: Could not fetch offices: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
    ob_end_flush();
    exit;
}

// Fetch individual evaluations for each office
$evaluations = [];
foreach ($offices_data as $office_id => $office) {
    $query = "
        SELECT fs.submission_id, fs.submission_token, fs.comments, c.date_of_visit, c.service_availed, orat.rating,
               se.email,
               (SELECT COUNT(*) FROM admin_replies ar WHERE ar.submission_id = fs.submission_id) as reply_count
        FROM form_submissions fs
        JOIN clients c ON fs.client_id = c.client_id
        JOIN offices o ON c.office_id = o.office_id
        JOIN overall_ratings orat ON fs.submission_id = orat.submission_id
        LEFT JOIN submission_emails se ON fs.submission_id = se.submission_id
        WHERE o.office_id = :office_id
    ";
    if ($start_date) {
        $query .= " AND c.date_of_visit >= :start_date";
    }
    $query .= " ORDER BY fs.submission_id DESC";

    try {
        $stmt = $pdo->prepare($query);
        if ($start_date) {
            $stmt->execute(['office_id' => $office_id, 'start_date' => $start_date]);
        } else {
            $stmt->execute(['office_id' => $office_id]);
        }
        $evaluations[$office_id] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Failed to fetch evaluations for office_id=$office_id: " . $e->getMessage());
        $evaluations[$office_id] = [];
    }
}

// Function to display star ratings
function displayStarRating($rating)
{
    $rating = (float) $rating;
    $fullStars = floor($rating);
    $halfStar = $rating - $fullStars >= 0.5 ? 1 : 0;
    $emptyStars = 5 - $fullStars - $halfStar;

    $stars = str_repeat('<i class="fas fa-star star-filled"></i>', $fullStars);
    $stars .= $halfStar ? '<i class="fas fa-star-half-alt star-filled"></i>' : '';
    $stars .= str_repeat('<i class="far fa-star star-empty"></i>', $emptyStars);
    return $stars;
}

// Clean buffer before HTML output
ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Offices</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="Style/admin_dashboard.css">
    <style>
        .success-message,
        .error-message {
            padding: 10px;
            margin-bottom: 10px;
            border-radius: 4px;
            font-size: 14px;
        }

        .success-message {
            background-color: #d4edda;
            color: #155724;
        }

        .error-message {
            background-color: #f8d7da;
            color: #721c24;
        }

        .reply-form {
            margin-top: 10px;
        }

        .reply-textarea {
            width: 100%;
            resize: vertical;
        }

        .reply-btn {
            display: inline-flex;
            align-items: center;
            padding: 8px 16px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        .reply-btn:disabled {
            background-color: #6c757d;
            cursor: not-allowed;
        }

        .btn-loader {
            margin-left: 8px;
        }

        .reply-sent-badge {
            display: inline-block;
            padding: 4px 8px;
            background-color: #28a745;
            color: white;
            border-radius: 4px;
            font-size: 12px;
            margin-top: 5px;
        }
    </style>
</head>

<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <button class="toggle-btn" onclick="toggleSidebar()" data-tooltip="Toggle Sidebar"><i class="fas fa-bars"></i></button>
            <h2>Admin Panel</h2>
        </div>
        <a href="admin_dashboard.php" data-tooltip="Dashboard Overview"><i class="fas fa-tachometer-alt"></i><span class="link-text">Dashboard</span></a>
        <a href="view_offices.php" class="active" data-tooltip="View Office Details"><i class="fas fa-building"></i><span class="link-text">View Offices</span></a>
        <a href="form_create.php" data-tooltip="Create or Edit Forms"><i class="fas fa-file-alt"></i><span class="link-text">Form Maker</span></a>
        <button class="logout-btn" data-tooltip="Logout from Admin Panel"><i class="fas fa-sign-out-alt"></i><span class="link-text"> Logout</span></button>
        <div class="theme-toggle">
            <button id="theme-toggle-btn" onclick="toggleTheme()" data-tooltip="Toggle Dark Mode">
                <i class="fas fa-moon"></i>
            </button>
        </div>
    </div>

    <div class="main-content">
        <div class="dashboard-header">
            <h1>Office Ratings & Evaluations</h1>
            <div class="filter-section">
                <label>Filter by:</label>
                <select onchange="location = this.value;" class="filter-select">
                    <option value="view_offices.php?filter=all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All Time</option>
                    <option value="view_offices.php?filter=week" <?php echo $filter === 'week' ? 'selected' : ''; ?>>Last Week</option>
                    <option value="view_offices.php?filter=month" <?php echo $filter === 'month' ? 'selected' : ''; ?>>Last Month</option>
                </select>
            </div>
        </div>

        <div class="offices-section">
            <?php foreach ($offices_data as $office_id => $office): ?>
                <div class="office-card">
                    <div class="office-header" onclick="toggleEvaluations(<?php echo (int) $office_id; ?>)">
                        <h3><?php echo htmlspecialchars($office['office_name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                        <div class="office-stats">
                            <p>
                                Average Rating: <?php echo $office['avg_rating'] ? number_format($office['avg_rating'], 2) : 'N/A'; ?>/5
                                (<?php echo (int) $office['rating_count']; ?> reviews)
                                <span class="star-rating">
                                    <?php echo $office['avg_rating'] ? displayStarRating($office['avg_rating']) : ''; ?>
                                </span>
                            </p>
                            <p>
                                Average SQD Rating: <?php echo $office['avg_sqd_rating'] ? number_format($office['avg_sqd_rating'], 2) : 'N/A'; ?>/5
                                <span class="star-rating">
                                    <?php echo $office['avg_sqd_rating'] ? displayStarRating($office['avg_sqd_rating']) : ''; ?>
                                </span>
                            </p>
                        </div>
                        <span class="toggle-icon">▼</span>
                    </div>
                    <div class="evaluations" id="evaluations-<?php echo (int) $office_id; ?>" style="display: none;">
                        <!-- SQD Weighted Means -->
                        <?php if (!empty($office['questions'])): ?>
                            <div class="sqd-section">
                                <h5>SQD Weighted Means</h5>
                                <div class="sqd-grid">
                                    <?php foreach ($office['questions'] as $question): ?>
                                        <?php if ($question['weighted_mean'] !== null): ?>
                                            <div class="sqd-item">
                                                <p><strong><?php echo htmlspecialchars($question['question_code'], ENT_QUOTES, 'UTF-8'); ?>:</strong></p>
                                                <p><?php echo htmlspecialchars($question['question_text'], ENT_QUOTES, 'UTF-8'); ?></p>
                                                <p>
                                                    <strong>Weighted Mean:</strong> <?php echo number_format($question['weighted_mean'], 2); ?>/5
                                                    <span class="star-rating">
                                                        <?php echo displayStarRating($question['weighted_mean']); ?>
                                                    </span>
                                                </p>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        <!-- Evaluations -->
                        <div class="evaluations-section">
                            <h5>Evaluations</h5>
                            <?php if (empty($evaluations[$office_id])): ?>
                                <p>No evaluations available.</p>
                            <?php else: ?>
                                <div class="evaluations-grid">
                                    <?php foreach ($evaluations[$office_id] as $eval): ?>
                                        <div class="evaluation">
                                            <p><strong>Submission ID:</strong> <?php echo htmlspecialchars($eval['submission_token'], ENT_QUOTES, 'UTF-8'); ?></p>
                                            <p><strong>Service Availed:</strong> <?php echo htmlspecialchars($eval['service_availed'], ENT_QUOTES, 'UTF-8'); ?></p>
                                            <p>
                                                <strong>Rating:</strong> <?php echo (int) $eval['rating']; ?>/5
                                                <span class="star-rating">
                                                    <?php echo displayStarRating($eval['rating']); ?>
                                                </span>
                                            </p>
                                            <p><strong>Comments:</strong> <?php echo htmlspecialchars($eval['comments'] ?? 'None', ENT_QUOTES, 'UTF-8'); ?></p>
                                            <p><strong>Date:</strong> <?php echo htmlspecialchars($eval['date_of_visit'] ?? 'Not specified', ENT_QUOTES, 'UTF-8'); ?></p>
                                            <!-- Reply Form -->
                                            <?php if ($eval['reply_count'] > 0): ?>
                                                <p class="reply-sent-badge">Reply Sent</p>
                                            <?php elseif (!empty($eval['email'])): ?>
                                                <form method="POST" class="reply-form" id="reply-form-<?php echo (int) $eval['submission_id']; ?>">
                                                    <input type="hidden" name="submission_id" value="<?php echo (int) $eval['submission_id']; ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                                    <label>
                                                        Reply to Evaluator:
                                                        <textarea name="reply_message" rows="3" required placeholder="Type your response..." class="reply-textarea"></textarea>
                                                    </label>
                                                    <button type="submit" name="send_reply" class="reply-btn" data-tooltip="Send Reply to Client">
                                                        <span class="btn-text">Send Reply</span>
                                                        <span class="btn-loader" style="display: none;"><i class="fas fa-spinner fa-spin"></i></span>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <p class="error-message">No email provided by the client. Cannot send a reply.</p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            sidebar.classList.toggle('minimized');
            localStorage.setItem('sidebarMinimized', sidebar.classList.contains('minimized'));
        }

        function toggleEvaluations(officeId) {
            const evaluations = document.getElementById(`evaluations-${officeId}`);
            const toggleIcon = evaluations.previousElementSibling.querySelector('.toggle-icon');
            if (evaluations.style.display === 'none') {
                evaluations.style.display = 'block';
                toggleIcon.textContent = '▲';
            } else {
                evaluations.style.display = 'none';
                toggleIcon.textContent = '▼';
            }
        }

        function toggleTheme() {
            const currentTheme = document.body.classList.contains('dark-theme') ? 'dark' : 'light';
            const newTheme = currentTheme === 'light' ? 'dark' : 'light';
            document.body.classList.remove(currentTheme + '-theme');
            document.body.classList.add(newTheme + '-theme');
            localStorage.setItem('theme', newTheme);
            const themeToggleBtn = document.getElementById('theme-toggle-btn');
            themeToggleBtn.innerHTML = newTheme === 'light' ? '<i class="fas fa-moon"></i>' : '<i class="fas fa-sun"></i>';
        }

        document.addEventListener('DOMContentLoaded', () => {
            const sidebar = document.querySelector('.sidebar');
            const isMinimized = localStorage.getItem('sidebarMinimized') === 'true';
            if (isMinimized) {
                sidebar.classList.add('minimized');
            }

            const theme = localStorage.getItem('theme') || 'light';
            document.body.classList.add(theme + '-theme');
            const themeToggleBtn = document.getElementById('theme-toggle-btn');
            if (theme === 'dark') {
                themeToggleBtn.innerHTML = '<i class="fas fa-sun"></i>';
            }

            document.querySelectorAll('.reply-form').forEach(form => {
                form.addEventListener('submit', async (e) => {
                    e.preventDefault();

                    // Confirmation prompt
                    if (!confirm('Are you sure you want to send this reply?')) {
                        return;
                    }

                    const submitBtn = form.querySelector('button[type="submit"]');
                    const btnText = submitBtn.querySelector('.btn-text');
                    const btnLoader = submitBtn.querySelector('.btn-loader');

                    // Remove any existing messages
                    const existingMessages = form.querySelectorAll('.success-message, .error-message');
                    existingMessages.forEach(msg => msg.remove());

                    btnText.style.display = 'none';
                    btnLoader.style.display = 'inline-block';
                    submitBtn.disabled = true;

                    const formData = new FormData(form);
                    try {
                        const response = await fetch('view_offices.php', {
                            method: 'POST',
                            body: formData,
                            headers: {
                                'Accept': 'application/json'
                            }
                        });

                        let result;
                        try {
                            result = await response.json();
                        } catch (e) {
                            throw new Error('Invalid response from server. Please try again.');
                        }

                        if (!response.ok) {
                            throw new Error(result.error || `HTTP error! Status: ${response.status}`);
                        }

                        if (result.success) {
                            const successDiv = document.createElement('div');
                            successDiv.className = 'success-message';
                            successDiv.textContent = result.success;
                            form.prepend(successDiv);
                            form.reset();
                            setTimeout(() => {
                                successDiv.remove();
                                window.location.reload();
                            }, 3000);
                        } else {
                            throw new Error(result.error || 'Unknown error occurred');
                        }
                    } catch (error) {
                        console.error('Submission error:', error);
                        const errorDiv = document.createElement('div');
                        errorDiv.className = 'error-message';
                        errorDiv.textContent = error.message || 'Failed to send reply. Please try again.';
                        form.prepend(errorDiv);
                        setTimeout(() => errorDiv.remove(), 7000);
                    } finally {
                        btnText.style.display = 'inline-block';
                        btnLoader.style.display = 'none';
                        submitBtn.disabled = false;
                    }
                });
            });
        });
    </script>
</body>

</html>