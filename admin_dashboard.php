<?php
// admin_dashboard.php
// Admin Dashboard for Hackathon 2025
// Displays notifications, high-level analytics with Chart.js, manages active form, views evaluations, and handles direct replies to clients

require_once 'Config/db_connection.php';
require_once 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// Fetch recent submissions for notifications (last 10)
function fetchRecentSubmissions($pdo)
{
    try {
        $stmt = $pdo->query("
            SELECT fs.submission_id, fs.submission_token, fs.comments, 
                   c.date_of_visit, c.service_availed, o.office_name, orat.rating
            FROM form_submissions fs
            JOIN clients c ON fs.client_id = c.client_id
            JOIN offices o ON c.office_id = o.office_id
            LEFT JOIN overall_ratings orat ON fs.submission_id = orat.submission_id
            ORDER BY fs.created_at DESC
            LIMIT 10
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("fetchRecentSubmissions failed: " . $e->getMessage());
        return ['error' => "Could not fetch submissions: " . htmlspecialchars($e->getMessage())];
    }
}

// Fetch analytics data for chart (ratings and SQD by office)
function fetchChartData($pdo)
{
    try {
        $stmt = $pdo->query("
            SELECT o.office_name, 
                   AVG(orat.rating) as avg_overall_rating,
                   AVG(sqd.weighted_mean) as avg_sqd_rating
            FROM offices o
            LEFT JOIN clients c ON o.office_id = c.office_id
            LEFT JOIN form_submissions fs ON c.client_id = fs.client_id
            LEFT JOIN overall_ratings orat ON fs.submission_id = orat.submission_id
            LEFT JOIN sqd_ratings sqd ON fs.submission_id = sqd.submission_id
            GROUP BY o.office_id, o.office_name
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("fetchChartData failed: " . $e->getMessage());
        return ['error' => "Could not fetch chart data: " . htmlspecialchars($e->getMessage())];
    }
}

// Fetch evaluation details by submission ID
function fetchEvaluationDetails($pdo, $submission_id)
{
    try {
        $stmt = $pdo->prepare("
            SELECT fs.submission_id, fs.submission_token, fs.comments, 
                   c.date_of_visit, c.service_availed, o.office_name, orat.rating,
                   q.question_code, q.question_text, oq.option_text, oq.option_value,
                   se.email
            FROM form_submissions fs
            JOIN clients c ON fs.client_id = c.client_id
            JOIN offices o ON c.office_id = o.office_id
            LEFT JOIN overall_ratings orat ON fs.submission_id = orat.submission_id
            LEFT JOIN responses r ON c.client_id = r.client_id
            LEFT JOIN questions q ON r.question_id = q.question_id
            LEFT JOIN options oq ON r.option_id = oq.option_id
            LEFT JOIN submission_emails se ON fs.submission_id = se.submission_id
            WHERE fs.submission_id = :submission_id
        ");
        $stmt->execute(['submission_id' => $submission_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("fetchEvaluationDetails failed: " . $e->getMessage());
        return ['error' => "Could not fetch evaluation: " . htmlspecialchars($e->getMessage())];
    }
}

// Fetch all forms for the active form popup
function fetchForms($pdo)
{
    try {
        $stmt = $pdo->query("SELECT form_id, form_code, revision_no FROM forms ORDER BY form_id DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("fetchForms failed: " . $e->getMessage());
        return ['error' => "Could not fetch forms: " . htmlspecialchars($e->getMessage())];
    }
}

// Fetch current active form
function fetchCurrentActiveForm($pdo)
{
    try {
        $stmt = $pdo->query("
            SELECT f.form_id, f.form_code, f.revision_no 
            FROM forms f 
            JOIN active_form af ON f.form_id = af.form_id 
            ORDER BY af.set_at DESC LIMIT 1
        ");
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("fetchCurrentActiveForm failed: " . $e->getMessage());
        return ['error' => "Could not fetch active form: " . htmlspecialchars($e->getMessage())];
    }
}

// Set active form
function setActiveForm($pdo, $form_id)
{
    try {
        $pdo->beginTransaction();

        // Check if an active form already exists
        $stmt = $pdo->query("SELECT active_form_id FROM active_form ORDER BY set_at DESC LIMIT 1");
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Update existing active form
            $stmt = $pdo->prepare("UPDATE active_form SET form_id = :form_id, set_at = NOW() WHERE active_form_id = :active_form_id");
            $stmt->execute([
                'form_id' => $form_id,
                'active_form_id' => $existing['active_form_id']
            ]);
        } else {
            // Insert new active form
            $stmt = $pdo->prepare("INSERT INTO active_form (form_id, set_at) VALUES (:form_id, NOW())");
            $stmt->execute(['form_id' => $form_id]);
        }

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("setActiveForm failed: " . $e->getMessage());
        return ['error' => "Could not set active form: " . htmlspecialchars($e->getMessage())];
    }
}

// Send reply email directly to client
function sendReplyEmail($pdo, $submission_id, $message)
{
    try {
        // Log function entry
        error_log("sendReplyEmail: Starting for submission_id=$submission_id");

        // Sanitize and validate message
        $message = trim(strip_tags($message));
        if (empty($message)) {
            error_log("sendReplyEmail: Empty message for submission_id=$submission_id");
            return ['error' => 'Reply message cannot be empty.'];
        }

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
            return ['error' => 'Submission not found.'];
        }

        if (empty($result['email'])) {
            error_log("sendReplyEmail: No email found for submission_id=$submission_id");
            return ['error' => 'No email provided by the client. Cannot send reply.'];
        }

        // Validate email format
        if (!filter_var($result['email'], FILTER_VALIDATE_EMAIL)) {
            error_log("sendReplyEmail: Invalid email format for submission_id=$submission_id: " . $result['email']);
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

        // Enable detailed debugging
        $mail->SMTPDebug = SMTP::DEBUG_SERVER; // More verbose for debugging
        $mail->Debugoutput = function ($str, $level) use ($submission_id) {
            error_log("PHPMailer Debug [submission_id=$submission_id, level=$level]: $str");
        };

        // SMTP Configuration
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = GMAIL_USERNAME;
        $mail->Password = GMAIL_APP_PASSWORD; // No str_replace needed; config.ini should be clean
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        // Set timeout and keep-alive
        $mail->Timeout = 30;
        $mail->SMTPKeepAlive = false;

        // Sender and Recipient
        $mail->setFrom(GMAIL_USERNAME, GMAIL_FROM_NAME);
        $mail->addAddress($result['email']);
        $mail->addReplyTo(GMAIL_REPLY_TO_EMAIL, GMAIL_REPLY_TO_NAME);

        // Email Content
        $mail->isHTML(true);
        $mail->Subject = "Reply to Your Feedback - Submission {$result['submission_token']}";
        $mail->Body = "
            <h2>Response from {$result['office_name']}</h2>
            <p><strong>Submission ID:</strong> {$result['submission_token']}</p>
            <p><strong>Our Reply:</strong> " . htmlspecialchars($message) . "</p>
            <p>Thank you for your feedback!</p>
            <p>Best regards,<br>" . GMAIL_FROM_NAME . "</p>
        ";
        $mail->AltBody = "
            Response from {$result['office_name']}

            Submission ID: {$result['submission_token']}
            Our Reply: " . htmlspecialchars($message) . "

            Thank you for your feedback!
            Best regards,
            " . GMAIL_FROM_NAME;

        // Send email
        error_log("sendReplyEmail: Attempting to send email to {$result['email']} for submission_id=$submission_id");
        $mail->send();
        error_log("sendReplyEmail: Email sent successfully to {$result['email']} for submission_id=$submission_id");

        // Log success
        $stmt = $pdo->prepare("
            INSERT INTO email_logs (submission_id, email_type, status, logged_at) 
            VALUES (:submission_id, 'admin_reply', 'sent', NOW())
        ");
        $stmt->execute(['submission_id' => $submission_id]);

        return ['success' => 'Reply sent successfully to ' . htmlspecialchars($result['email']) . '.'];
    } catch (Exception $e) {
        // Log failure
        error_log("sendReplyEmail: Failed for submission_id=$submission_id: " . $e->getMessage());
        $stmt = $pdo->prepare("
            INSERT INTO email_logs (submission_id, email_type, status, error_message, logged_at) 
            VALUES (:submission_id, 'admin_reply', 'failed', :error, NOW())
        ");
        $stmt->execute([
            'submission_id' => $submission_id,
            'error' => $e->getMessage()
        ]);
        return ['error' => 'Failed to send reply: ' . htmlspecialchars($e->getMessage())];
    }
}

// Function to display star ratings
function displayStarRating($rating)
{
    $fullStars = floor($rating);
    $halfStar = $rating - $fullStars >= 0.5 ? 1 : 0;
    $emptyStars = 5 - $fullStars - $halfStar;

    $stars = str_repeat('<i class="fas fa-star star-filled"></i>', $fullStars);
    $stars .= $halfStar ? '<i class="fas fa-star-half-alt star-filled"></i>' : '';
    $stars .= str_repeat('<i class="far fa-star star-empty"></i>', $emptyStars);
    return $stars;
}

// Initialize variables
$submissions = fetchRecentSubmissions($pdo);
$chart_data = fetchChartData($pdo);
$forms = fetchForms($pdo);
$current_active_form = fetchCurrentActiveForm($pdo);
$evaluation_details = null;
$reply_message = null;
$success_message = null;

// Handle active form selection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_active_form'])) {
    $form_id = filter_input(INPUT_POST, 'form_id', FILTER_VALIDATE_INT);
    if ($form_id) {
        $result = setActiveForm($pdo, $form_id);
        if (isset($result['error'])) {
            $error_message = $result['error'];
        } else {
            error_log("Active form set to form_id: $form_id");
            $success_message = "Active form set successfully.";
            $current_active_form = fetchCurrentActiveForm($pdo); // Refresh active form
        }
    } else {
        $error_message = "Invalid form ID.";
    }
}

// Handle view evaluation
if (isset($_GET['view_submission'])) {
    $submission_id = filter_input(INPUT_GET, 'view_submission', FILTER_VALIDATE_INT);
    if ($submission_id) {
        $evaluation_details = fetchEvaluationDetails($pdo, $submission_id);
    }
}

// Handle reply submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_reply'])) {
    $submission_id = filter_input(INPUT_POST, 'submission_id', FILTER_VALIDATE_INT);
    $reply_text = trim($_POST['reply_message'] ?? '');
    error_log("POST received: send_reply for submission_id=$submission_id, message=" . substr($reply_text, 0, 50));
    if ($submission_id && $reply_text) {
        $reply_message = sendReplyEmail($pdo, $submission_id, $reply_text);
    } else {
        $reply_message = ['error' => 'Invalid submission ID or empty reply message.'];
        error_log("Invalid POST: submission_id=$submission_id, message_empty=" . (empty($reply_text) ? 'yes' : 'no'));
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="Style/admin_dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
</head>

<body>
    <!-- Sidebar Navigation -->
    <div class="sidebar">
        <div class="sidebar-header">
            <button class="toggle-btn" onclick="toggleSidebar()" data-tooltip="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <h2>Admin Panel</h2>
        </div>
        <a href="admin_dashboard.php" class="active" data-tooltip="Dashboard Overview">
            <i class="fas fa-tachometer-alt"></i>
            <span class="link-text">Dashboard</span>
        </a>
        <a href="view_offices.php" data-tooltip="View Office Details">
            <i class="fas fa-building"></i>
            <span class="link-text">View Offices</span>
        </a>
        <a href="form_create.php" data-tooltip="Create or Edit Forms">
            <i class="fas fa-file-alt"></i>
            <span class="link-text">Form Maker</span>
        </a>
        <button class="logout-btn" data-tooltip="Logout from Admin Panel">
            <i class="fas fa-sign-out-alt"></i>
            <span class="link-text">Logout</span>
        </button>
        <div class="theme-toggle">
            <button id="theme-toggle-btn" onclick="toggleTheme()" data-tooltip="Toggle Dark Mode">
                <i class="fas fa-moon"></i>
            </button>
        </div>
    </div>

    <!-- Main Content Area -->
    <div class="main-content">
        <div class="dashboard-header">
            <h1>Admin Dashboard</h1>
            <button class="set-form-btn" onclick="openFormModal()" data-tooltip="Set the Active Form">Set Active Form</button>
        </div>

        <?php if (isset($error_message)): ?>
            <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <?php if (isset($reply_message)): ?>
            <div class="<?php echo isset($reply_message['error']) ? 'error-message' : 'success-message'; ?>">
                <?php echo htmlspecialchars($reply_message['error'] ?? $reply_message['success']); ?>
            </div>
        <?php endif; ?>

        <!-- Chart Section -->
        <div class="analytics-section">
            <h3>PQA Analytics Overview</h3>
            <?php if (isset($chart_data['error'])): ?>
                <div class="error-message"><?php echo htmlspecialchars($chart_data['error']); ?></div>
            <?php elseif (empty($chart_data)): ?>
                <p>No data available for analytics.</p>
            <?php else: ?>
                <div class="chart-container">
                    <canvas id="analyticsChart"></canvas>
                </div>
            <?php endif; ?>
        </div>

        <!-- Evaluation Details -->
        <?php if ($evaluation_details && !isset($evaluation_details['error'])): ?>
            <div class="evaluation-details">
                <h3>Evaluation Details</h3>
                <div class="notification">
                    <h4>Feedback for <?php echo htmlspecialchars($evaluation_details[0]['office_name']); ?></h4>
                    <p><strong>Submission ID:</strong> <?php echo htmlspecialchars($evaluation_details[0]['submission_token']); ?></p>
                    <p><strong>Service Availed:</strong> <?php echo htmlspecialchars($evaluation_details[0]['service_availed']); ?></p>
                    <p>
                        <strong>Overall Rating:</strong>
                        <?php echo htmlspecialchars($evaluation_details[0]['rating'] ?? 'N/A'); ?>/5
                        <span class="star-rating">
                            <?php echo $evaluation_details[0]['rating'] ? displayStarRating($evaluation_details[0]['rating']) : ''; ?>
                        </span>
                    </p>
                    <p><strong>Comments:</strong> <?php echo htmlspecialchars($evaluation_details[0]['comments'] ?? 'None'); ?></p>
                    <p><strong>Date:</strong> <?php echo htmlspecialchars($evaluation_details[0]['date_of_visit'] ?? 'Not specified'); ?></p>
                    <h5>Responses:</h5>
                    <ul>
                        <?php foreach ($evaluation_details as $detail): ?>
                            <?php if ($detail['question_code']): ?>
                                <li>
                                    <strong><?php echo htmlspecialchars($detail['question_code']); ?>:</strong>
                                    <?php echo htmlspecialchars($detail['question_text']); ?> -
                                    <?php echo htmlspecialchars($detail['option_text'] . ' (' . $detail['option_value'] . ')'); ?>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                    <?php if (!empty($evaluation_details[0]['email'])): ?>
                        <form method="POST" class="reply-form" id="reply-form-<?php echo $evaluation_details[0]['submission_id']; ?>">
                            <input type="hidden" name="submission_id" value="<?php echo $evaluation_details[0]['submission_id']; ?>">
                            <label>
                                Reply to Evaluator:
                                <textarea name="reply_message" rows="4" required placeholder="Type your response..." class="reply-textarea"></textarea>
                            </label>
                            <button type="submit" name="send_reply" class="reply-btn" data-tooltip="Send Reply to Client">
                                <span class="btn-text">Send Reply</span>
                                <span class="btn-loader" style="display: none;"><i class="fas fa-spinner fa-spin"></i></span>
                            </button>
                        </form>
                    <?php else: ?>
                        <p class="error-message">No email provided by the client. Cannot send a reply.</p>
                    <?php endif; ?>
                    <a href="admin_dashboard.php" class="back-btn" data-tooltip="Return to Dashboard">Back to Dashboard</a>
                </div>
            </div>
        <?php else: ?>
            <!-- Notifications Section -->
            <div class="notifications-section">
                <h3>New Evaluations</h3>
                <?php if (isset($submissions['error'])): ?>
                    <div class="error-message"><?php echo htmlspecialchars($submissions['error']); ?></div>
                <?php elseif (empty($submissions)): ?>
                    <p>No recent submissions.</p>
                <?php else: ?>
                    <div class="notifications-grid">
                        <?php foreach ($submissions as $submission): ?>
                            <div class="notification">
                                <h4>New Feedback for <?php echo htmlspecialchars($submission['office_name']); ?></h4>
                                <p><strong>Submission ID:</strong> <?php echo htmlspecialchars($submission['submission_token']); ?></p>
                                <p><strong>Service Availed:</strong> <?php echo htmlspecialchars($submission['service_availed']); ?></p>
                                <p>
                                    <strong>Rating:</strong>
                                    <?php echo htmlspecialchars($submission['rating'] ?? 'N/A'); ?>/5
                                    <span class="star-rating">
                                        <?php echo $submission['rating'] ? displayStarRating($submission['rating']) : ''; ?>
                                    </span>
                                </p>
                                <p><strong>Comments:</strong> <?php echo htmlspecialchars($submission['comments'] ?? 'None'); ?></p>
                                <p><strong>Date:</strong> <?php echo htmlspecialchars($submission['date_of_visit'] ?? 'Not specified'); ?></p>
                                <a href="admin_dashboard.php?view_submission=<?php echo $submission['submission_id']; ?>" class="view-btn" data-tooltip="View Full Evaluation Details">View Full Evaluation</a>
                                <!-- Reply Form for Each Notification -->
                                <?php
                                $email_check = $pdo->prepare("SELECT email FROM submission_emails WHERE submission_id = :submission_id");
                                $email_check->execute(['submission_id' => $submission['submission_id']]);
                                $has_email = $email_check->fetchColumn();
                                ?>
                                <?php if ($has_email): ?>
                                    <form method="POST" class="reply-form" id="reply-form-<?php echo $submission['submission_id']; ?>">
                                        <input type="hidden" name="submission_id" value="<?php echo $submission['submission_id']; ?>">
                                        <label>
                                            Quick Reply:
                                            <textarea name="reply_message" rows="3" required placeholder="Type your response..." class="reply-textarea"></textarea>
                                        </label>
                                        <button type="submit" name="send_reply" class="reply-btn" data-tooltip="Send Reply to Client">
                                            <span class="btn-text">Send Reply</span>
                                            <span class="btn-loader" style="display: none;"><i class="fas fa-spinner fa-spin"></i></span>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <p class="error-message">No email provided. Cannot send a reply.</p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Modal for Setting Active Form -->
        <div class="modal" id="form-modal">
            <div class="modal-content">
                <h2>Select Active Form</h2>
                <button class="close-modal" onclick="closeFormModal()" data-tooltip="Close Modal">×</button>
                <p>Current Active Form:
                    <?php if (isset($current_active_form['error'])): ?>
                        <span class="error"><?php echo htmlspecialchars($current_active_form['error']); ?></span>
                    <?php elseif ($current_active_form): ?>
                        <?php echo htmlspecialchars($current_active_form['form_code'] . ' (Rev. ' . $current_active_form['revision_no'] . ')'); ?>
                    <?php else: ?>
                        None
                    <?php endif; ?>
                </p>
                <?php if (isset($success_message)): ?>
                    <div class="success-message"><?php echo htmlspecialchars($success_message); ?></div>
                <?php endif; ?>
                <?php if (isset($forms['error'])): ?>
                    <div class="error-message"><?php echo htmlspecialchars($forms['error']); ?></div>
                <?php else: ?>
                    <form method="POST">
                        <label>
                            Select Form
                            <select name="form_id" required class="modal-select">
                                <option value="">-- Select a form --</option>
                                <?php foreach ($forms as $form): ?>
                                    <option value="<?php echo htmlspecialchars($form['form_id']); ?>" <?php echo ($current_active_form && $current_active_form['form_id'] == $form['form_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($form['form_code'] . ' (Rev. ' . $form['revision_no'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button type="submit" name="set_active_form" class="modal-btn" data-tooltip="Set as Active Form">Set Active Form</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Chart.js Initialization
        <?php if (!isset($chart_data['error']) && !empty($chart_data)): ?>
            document.addEventListener('DOMContentLoaded', () => {
                const ctx = document.getElementById('analyticsChart').getContext('2d');
                const officeNames = <?php echo json_encode(array_column($chart_data, 'office_name')); ?>;
                const overallRatings = <?php echo json_encode(array_map(function ($data) {
                                            return $data['avg_overall_rating'] ? round($data['avg_overall_rating'], 2) : 0;
                                        }, $chart_data)); ?>;
                const sqdRatings = <?php echo json_encode(array_map(function ($data) {
                                        return $data['avg_sqd_rating'] ? round($data['avg_sqd_rating'], 2) : 0;
                                    }, $chart_data)); ?>;

                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: officeNames,
                        datasets: [{
                                label: 'Average Overall Rating',
                                data: overallRatings,
                                backgroundColor: 'rgba(80, 200, 120, 0.6)',
                                borderColor: 'rgba(80, 200, 120, 1)',
                                borderWidth: 1
                            },
                            {
                                label: 'Average SQD Rating',
                                data: sqdRatings,
                                backgroundColor: 'rgba(33, 150, 243, 0.6)',
                                borderColor: 'rgba(33, 150, 243, 1)',
                                borderWidth: 1
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: 5,
                                title: {
                                    display: true,
                                    text: 'Rating (out of 5)'
                                },
                                grid: {
                                    color: document.body.classList.contains('dark-theme') ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)'
                                }
                            },
                            x: {
                                title: {
                                    display: true,
                                    text: 'Offices'
                                },
                                grid: {
                                    display: false
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                position: 'top',
                                labels: {
                                    boxWidth: 20,
                                    padding: 15
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return `${context.dataset.label}: ${context.parsed.y.toFixed(2)}`;
                                    }
                                }
                            }
                        }
                    }
                });
            });
        <?php endif; ?>

        // Sidebar and Theme Functions
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

            // Handle Reply Form Submissions
            document.querySelectorAll('.reply-form').forEach(form => {
                form.addEventListener('submit', async (e) => {
                    e.preventDefault();
                    const submitBtn = form.querySelector('button[type="submit"]');
                    const btnText = submitBtn.querySelector('.btn-text');
                    const btnLoader = submitBtn.querySelector('.btn-loader');

                    // Show loading state
                    btnText.style.display = 'none';
                    btnLoader.style.display = 'inline-block';
                    submitBtn.disabled = true;

                    const formData = new FormData(form);
                    try {
                        const response = await fetch('admin_dashboard.php', {
                            method: 'POST',
                            body: formData
                        });
                        const result = await response.json(); // Expect JSON response
                        if (response.ok && result.success) {
                            const successDiv = document.createElement('div');
                            successDiv.className = 'success-message';
                            successDiv.textContent = result.success;
                            form.prepend(successDiv);
                            setTimeout(() => {
                                successDiv.remove();
                                window.location.reload();
                            }, 3000);
                        } else {
                            throw new Error(result.error || `Submission failed: ${response.statusText}`);
                        }
                    } catch (error) {
                        console.error('Submission error:', error);
                        const errorDiv = document.createElement('div');
                        errorDiv.className = 'error-message';
                        errorDiv.textContent = error.message || 'Failed to send reply. Please try again.';
                        form.prepend(errorDiv);
                        setTimeout(() => errorDiv.remove(), 5000);
                    } finally {
                        btnText.style.display = 'inline-block';
                        btnLoader.style.display = 'none';
                        submitBtn.disabled = false;
                    }
                });
            });
        });

        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            sidebar.classList.toggle('minimized');
            localStorage.setItem('sidebarMinimized', sidebar.classList.contains('minimized'));
        }

        function openFormModal() {
            document.getElementById('form-modal').style.display = 'flex';
        }

        function closeFormModal() {
            document.getElementById('form-modal').style.display = 'none';
        }

        function toggleTheme() {
            const currentTheme = document.body.classList.contains('dark-theme') ? 'dark' : 'light';
            const newTheme = currentTheme === 'light' ? 'dark' : 'light';
            document.body.classList.remove(currentTheme + '-theme');
            document.body.classList.add(newTheme + '-theme');
            localStorage.setItem('theme', newTheme);

            const themeToggleBtn = document.getElementById('theme-toggle-btn');
            themeToggleBtn.innerHTML = newTheme === 'light' ? '<i class="fas fa-moon"></i>' : '<i class="fas fa-sun"></i>';

            // Update chart grid color if chart exists
            if (document.getElementById('analyticsChart')) {
                const chartCanvas = document.getElementById('analyticsChart');
                const chartInstance = Chart.getChart(chartCanvas);
                if (chartInstance) {
                    chartInstance.options.scales.y.grid.color = newTheme === 'dark' ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)';
                    chartInstance.update();
                }
            }
        }
    </script>

    <style>
        .chart-container {
            background: #ffffff;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
            height: 400px;
            position: relative;
        }

        body.dark-theme .chart-container {
            background: #2c3e50;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
        }

        .chart-container canvas {
            max-height: 100%;
            width: 100%;
        }

        .notifications-section .reply-form {
            margin-top: 15px;
        }

        .notifications-section .reply-textarea {
            min-height: 80px;
        }

        .error-message,
        .success-message {
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
        }

        .error-message {
            background-color: #f8d7da;
            color: #721c24;
        }

        .success-message {
            background-color: #d4edda;
            color: #155724;
        }
    </style>
</body>

</html>