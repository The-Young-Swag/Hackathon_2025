<?php
// test_form.php
// Anonymous Feedback Form for Hackathon 2025
// Handles form display, submission, SQD rating calculations, and email confirmations

require 'Config/db_connection.php';

if (!file_exists('vendor/autoload.php')) {
    die('<div class="error-message">Error: Please run "composer install" in C:\xampp\htdocs\Hackathon_2025 to set up dependencies.</div>');
}
require 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Prevent caching to ensure latest form is loaded
header("Cache-Control: no-cache, must-revalidate");

function sanitize_input($input)
{
    $input = strip_tags($input);
    $input = trim($input);
    $input = preg_replace('/\s+/', ' ', $input);
    return substr($input, 0, 100);
}

try {
    $stmt = $pdo->query("
        SELECT f.* 
        FROM forms f 
        JOIN active_form af ON f.form_id = af.form_id 
        ORDER BY af.set_at DESC 
        LIMIT 1
    ");
    $form = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$form) {
        die('<div class="error-message">No active form set. Please contact support.</div>');
    }
    error_log("Active form loaded: form_id={$form['form_id']}, form_code={$form['form_code']}");
} catch (PDOException $e) {
    error_log("Error fetching active form: " . $e->getMessage());
    die('<div class="error-message">Error: Could not fetch active form: ' . htmlspecialchars($e->getMessage()) . '</div>');
}

$selected_form_code = $form['form_code'];
$selected_form_id = $form['form_id'];

try {
    $stmt = $pdo->query("SELECT office_id, office_name FROM offices ORDER BY office_name");
    $offices = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('<div class="error-message">Error: Could not fetch offices: ' . htmlspecialchars($e->getMessage()) . '</div>');
}

try {
    $questions_query = "
        SELECT q.*, qt.type_name 
        FROM questions q 
        JOIN question_types qt ON q.type_id = qt.type_id 
        WHERE q.form_id = :form_id 
        ORDER BY q.display_order
    ";
    $stmt = $pdo->prepare($questions_query);
    $stmt->execute(['form_id' => $selected_form_id]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching questions: " . $e->getMessage());
    die('<div class="error-message">Error: Could not fetch questions: ' . htmlspecialchars($e->getMessage()) . '</div>');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (empty($_POST['office_id'])) {
            $errors['office_id'] = "Please select an office.";
        }
        if (empty($_POST['service_availed'])) {
            $errors['service_availed'] = "Please specify the service availed.";
        }
        if (empty($_POST['overall_rating'])) {
            $errors['overall_rating'] = "Please provide an overall rating.";
        }
        if (!empty($_POST['email']) && !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = "Invalid email address.";
        }
        if (strlen($_POST['service_availed']) > 100) {
            $errors['service_availed'] = "Service input is too long (max 100 characters).";
        }

        if (empty($errors)) {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO clients (date_of_visit, office_id, service_availed) 
                VALUES (:date_of_visit, :office_id, :service_availed)
            ");
            $stmt->execute([
                'date_of_visit' => $_POST['date_of_visit'] ?: null,
                'office_id' => $_POST['office_id'],
                'service_availed' => sanitize_input($_POST['service_availed'])
            ]);
            $client_id = $pdo->lastInsertId();

            $submission_token = bin2hex(random_bytes(16));
            $stmt = $pdo->prepare("
                INSERT INTO form_submissions (client_id, comments, submission_token) 
                VALUES (:client_id, :comments, :submission_token)
            ");
            $stmt->execute([
                'client_id' => $client_id,
                'comments' => htmlspecialchars($_POST['comments'] ?? ''),
                'submission_token' => $submission_token
            ]);
            $submission_id = $pdo->lastInsertId();

            $stmt = $pdo->prepare("
                INSERT INTO overall_ratings (submission_id, rating) 
                VALUES (:submission_id, :rating)
            ");
            $stmt->execute([
                'submission_id' => $submission_id,
                'rating' => $_POST['overall_rating']
            ]);

            if (!empty($_POST['email'])) {
                $expires_at = (new DateTime())->modify('+30 days')->format('Y-m-d H:i:s');
                $stmt = $pdo->prepare("
                    INSERT INTO submission_emails (submission_id, email, expires_at) 
                    VALUES (:submission_id, :email, :expires_at)
                ");
                $stmt->execute([
                    'submission_id' => $submission_id,
                    'email' => $_POST['email'],
                    'expires_at' => $expires_at
                ]);
            }

            $sqd_values = [
                'SD' => 1,
                'D' => 2,
                'NAD' => 3,
                'A' => 4,
                'SA' => 5
            ];

            foreach ($_POST as $key => $value) {
                if (strpos($key, 'question_') === 0) {
                    $question_id = str_replace('question_', '', $key);
                    $option_id = $value;

                    $stmt = $pdo->prepare("
                        INSERT INTO responses (client_id, question_id, option_id) 
                        VALUES (:client_id, :question_id, :option_id)
                    ");
                    $stmt->execute([
                        'client_id' => $client_id,
                        'question_id' => $question_id,
                        'option_id' => $option_id
                    ]);

                    $stmt = $pdo->prepare("
                        SELECT q.question_id, q.question_code, o.option_value 
                        FROM questions q 
                        JOIN options o ON o.question_id = q.question_id 
                        WHERE q.question_id = :question_id 
                        AND o.option_id = :option_id
                        AND q.question_code LIKE 'SQD%'
                    ");
                    $stmt->execute([
                        'question_id' => $question_id,
                        'option_id' => $option_id
                    ]);
                    $sqd_data = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($sqd_data && isset($sqd_values[$sqd_data['option_value']])) {
                        $stmt = $pdo->prepare("
                            INSERT INTO sqd_ratings (submission_id, office_id, question_id, weighted_mean) 
                            VALUES (:submission_id, :office_id, :question_id, :weighted_mean)
                        ");
                        $success = $stmt->execute([
                            'submission_id' => $submission_id,
                            'office_id' => $_POST['office_id'],
                            'question_id' => $sqd_data['question_id'],
                            'weighted_mean' => $sqd_values[$sqd_data['option_value']]
                        ]);
                        if (!$success) {
                            error_log("Failed to insert SQD rating for submission_id $submission_id, question_id $question_id");
                        }
                    } elseif (!empty($sqd_data)) {
                        error_log("No valid SQD option_value for question_id $question_id, option_id $option_id");
                    }
                }
            }

            $pdo->commit();

            $stmt = $pdo->prepare("SELECT office_name FROM offices WHERE office_id = :office_id");
            $stmt->execute(['office_id' => $_POST['office_id']]);
            $office_name = $stmt->fetchColumn();

            // Send confirmation email to client (if provided)
            if (!empty($_POST['email'])) {
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host = 'smtp.gmail.com';
                    $mail->SMTPAuth = true;
                    $mail->Username = GMAIL_USERNAME;
                    $mail->Password = GMAIL_APP_PASSWORD;
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port = 587;

                    $mail->setFrom(GMAIL_USERNAME, GMAIL_FROM_NAME);
                    $mail->addAddress($_POST['email']);
                    $mail->addReplyTo(GMAIL_USERNAME, GMAIL_FROM_NAME);

                    $mail->isHTML(true);
                    $mail->Subject = 'Your Feedback Submission';
                    $mail->Body = '
                        <h2>Thank You for Your Feedback!</h2>
                        <p>We received your feedback for <strong>' . htmlspecialchars($office_name) . '</strong>. Here are the details:</p>
                        <ul>
                            <li><strong>Submission ID:</strong> ' . htmlspecialchars($submission_token) . '</li>
                            <li><strong>Office Evaluated:</strong> ' . htmlspecialchars($office_name) . '</li>
                            <li><strong>Service Availed:</strong> ' . htmlspecialchars($_POST['service_availed']) . '</li>
                        </ul>
                        <p>Keep your Submission ID for reference. We may contact you via this email.</p>
                        <p>Best regards,<br>' . GMAIL_FROM_NAME . '</p>
                    ';
                    $mail->AltBody = '
                        Thank you for your feedback on ' . htmlspecialchars($office_name) . '!

                        Submission ID: ' . htmlspecialchars($submission_token) . '
                        Office Evaluated: ' . htmlspecialchars($office_name) . '
                        Service Availed: ' . htmlspecialchars($_POST['service_availed']) . '

                        Keep your Submission ID for reference. We may contact you via this email.

                        Best regards,
                        ' . GMAIL_FROM_NAME;

                    $mail->send();

                    $stmt = $pdo->prepare("
                        INSERT INTO email_logs (submission_id, email_type, status) 
                        VALUES (:submission_id, 'submission_confirmation', 'sent')
                    ");
                    $stmt->execute(['submission_id' => $submission_id]);
                } catch (Exception $e) {
                    $stmt = $pdo->prepare("
                        INSERT INTO email_logs (submission_id, email_type, status, error_message) 
                        VALUES (:submission_id, 'submission_confirmation', 'failed', :error)
                    ");
                    $stmt->execute([
                        'submission_id' => $submission_id,
                        'error' => $mail->ErrorInfo
                    ]);
                }
            }

            // Send notification to admin
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = GMAIL_USERNAME;
                $mail->Password = GMAIL_APP_PASSWORD;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = 587;

                $mail->setFrom(GMAIL_USERNAME, GMAIL_FROM_NAME);
                $mail->addAddress(GMAIL_USERNAME, GMAIL_FROM_NAME);

                $mail->isHTML(true);
                $mail->Subject = 'New Feedback Submission Received';
                $mail->Body = '
                    <h2>New Feedback Submission</h2>
                    <p>A new feedback has been submitted for <strong>' . htmlspecialchars($office_name) . '</strong>.</p>
                    <ul>
                        <li><strong>Submission ID:</strong> ' . htmlspecialchars($submission_token) . '</li>
                        <li><strong>Office Evaluated:</strong> ' . htmlspecialchars($office_name) . '</li>
                        <li><strong>Service Availed:</strong> ' . htmlspecialchars($_POST['service_availed']) . '</li>
                        <li><strong>Overall Rating:</strong> ' . htmlspecialchars($_POST['overall_rating']) . '/5</li>
                        <li><strong>Comments:</strong> ' . htmlspecialchars($_POST['comments'] ?? 'None') . '</li>
                    </ul>
                    <p>Review this submission in the admin dashboard.</p>
                    <p>Best regards,<br>' . GMAIL_FROM_NAME . '</p>
                ';
                $mail->AltBody = '
                    New Feedback Submission

                    Submission ID: ' . htmlspecialchars($submission_token) . '
                    Office Evaluated: ' . htmlspecialchars($office_name) . '
                    Service Availed: ' . htmlspecialchars($_POST['service_availed']) . '
                    Overall Rating: ' . htmlspecialchars($_POST['overall_rating']) . '/5
                    Comments: ' . htmlspecialchars($_POST['comments'] ?? 'None') . '

                    Review this submission in the admin dashboard.

                    Best regards,
                    ' . GMAIL_FROM_NAME;

                $mail->send();

                $stmt = $pdo->prepare("
                    INSERT INTO email_logs (submission_id, email_type, status) 
                    VALUES (:submission_id, 'admin_notification', 'sent')
                ");
                $stmt->execute(['submission_id' => $submission_id]);
            } catch (Exception $e) {
                $stmt = $pdo->prepare("
                    INSERT INTO email_logs (submission_id, email_type, status, error_message) 
                    VALUES (:submission_id, 'admin_notification', 'failed', :error)
                ");
                $stmt->execute([
                    'submission_id' => $submission_id,
                    'error' => $mail->ErrorInfo
                ]);
            }

            echo '<div class="confirmation-message">
                    <h3>Thank You For Your Feedback</h3>
                    <p>Your submission has been received. <a href="test_form.php">Submit another feedback</a></p>
                  </div>';
            exit;
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors['general'] = htmlspecialchars($e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feedback Form</title>
    <link rel="stylesheet" href="Style/form_designs.css">
</head>

<body>
    <div class="form-container">
        <form action="" method="POST" id="feedback-form">
            <div class="form-header">
                <h1>Feedback Form</h1>
                <p class="form-metadata">
                    <strong>Form Code:</strong> <?php echo htmlspecialchars($form['form_code']); ?> |
                    <strong>Revision No:</strong> <?php echo htmlspecialchars($form['revision_no']); ?> |
                    <strong>Effectivity:</strong> <?php echo htmlspecialchars($form['effectivity_date']); ?>
                </p>
            </div>

            <?php if (!empty($errors['general'])): ?>
                <div class="error-message"><?php echo $errors['general']; ?></div>
            <?php endif; ?>

            <div class="form-section">
                <h3>Evaluation Details</h3>
                <p class="instructions">Please provide details about the office you are evaluating.</p>
                <div class="client-info">
                    <label>
                        Office Evaluated <span class="required">*</span>
                        <select name="office_id" required aria-describedby="office-error">
                            <option value="">Select an office</option>
                            <?php foreach ($offices as $office): ?>
                                <option value="<?php echo $office['office_id']; ?>" <?php echo ($_POST['office_id'] ?? '') == $office['office_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($office['office_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!empty($errors['office_id'])): ?>
                            <span class="error-text" id="office-error"><?php echo $errors['office_id']; ?></span>
                        <?php endif; ?>
                    </label>
                    <label>
                        Service Availed <span class="required">*</span>
                        <input type="text" name="service_availed" value="<?php echo htmlspecialchars($_POST['service_availed'] ?? ''); ?>" placeholder="e.g., Transcript Request" required aria-describedby="service-error">
                        <?php if (!empty($errors['service_availed'])): ?>
                            <span class="error-text" id="service-error"><?php echo $errors['service_availed']; ?></span>
                        <?php endif; ?>
                    </label>
                    <label>
                        Date of Visit (Optional)
                        <input type="date" name="date_of_visit" value="<?php echo htmlspecialchars($_POST['date_of_visit'] ?? ''); ?>">
                    </label>
                    <label>
                        Email (Optional, for replies)
                        <input type="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" placeholder="e.g., your.email@example.com" aria-describedby="email-error">
                        <?php if (!empty($errors['email'])): ?>
                            <span class="error-text" id="email-error"><?php echo $errors['email']; ?></span>
                        <?php endif; ?>
                    </label>
                </div>
            </div>

            <?php
            if (empty($questions)) {
                echo '<div class="error-message">No questions available for this form. Please contact support.</div>';
            } else {
                $current_section = '';
                foreach ($questions as $question) {
                    if (strpos($question['question_code'], 'CC') === 0 && $current_section !== 'CC') {
                        $current_section = 'CC';
                        echo '<div class="form-section"><h3>Citizen’s Charter Questions</h3></div>';
                    } elseif (strpos($question['question_code'], 'SQD') === 0 && $current_section !== 'SQD') {
                        $current_section = 'SQD';
                        echo '<div class="form-section">';
                        echo '<h3>Service Quality Dimensions</h3>';
                        echo '<p class="instructions">Select the option that best fits your experience.</p>';
                        echo '</div>';
                    }
            ?>
                    <div class="question">
                        <p><strong><?php echo htmlspecialchars($question['question_code']); ?>.</strong>
                            <?php echo htmlspecialchars($question['question_text']); ?></p>
                        <div class="options">
                            <?php
                            $options_query = "SELECT * FROM options WHERE question_id = :question_id ORDER BY option_id";
                            $stmt = $pdo->prepare($options_query);
                            $stmt->execute(['question_id' => $question['question_id']]);
                            $options = $stmt->fetchAll(PDO::FETCH_ASSOC);

                            if (empty($options)) {
                                echo '<p class="error-message">No options available for this question.</p>';
                            } elseif ($question['type_name'] === 'Multiple Choice') {
                                echo '<div class="multiple-choice">';
                                foreach ($options as $option) {
                                    echo '<label class="radio-group">';
                                    echo '<input type="radio" name="question_' . $question['question_id'] . '" 
                                                value="' . $option['option_id'] . '" required>';
                                    echo '<span>' . htmlspecialchars($option['option_text']) . '</span>';
                                    echo '</label>';
                                }
                                echo '</div>';
                            } else {
                                echo '<div class="likert-scale">';
                                foreach ($options as $option) {
                                    echo '<label class="radio-group">';
                                    echo '<input type="radio" name="question_' . $question['question_id'] . '" 
                                                value="' . $option['option_id'] . '" required>';
                                    echo '<span>' . htmlspecialchars($option['option_text']) . ' (' . htmlspecialchars($option['option_value']) . ')</span>';
                                    echo '</label>';
                                }
                                echo '</div>';
                            }
                            ?>
                        </div>
                    </div>
            <?php
                }
            }
            ?>

            <div class="form-section">
                <h3>Overall Rating <span class="required">*</span></h3>
                <p class="instructions">Please rate your overall experience with the office (1 = Poor, 5 = Excellent).</p>
                <div class="likert-scale">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <label class="radio-group">
                            <input type="radio" name="overall_rating" value="<?php echo $i; ?>" required <?php echo ($_POST['overall_rating'] ?? '') == $i ? 'checked' : ''; ?>>
                            <span><?php echo $i; ?> <?php echo $i == 1 ? '(Poor)' : ($i == 5 ? '(Excellent)' : ''); ?></span>
                        </label>
                    <?php endfor; ?>
                </div>
                <?php if (!empty($errors['overall_rating'])): ?>
                    <span class="error-text"><?php echo $errors['overall_rating']; ?></span>
                <?php endif; ?>
            </div>

            <div class="form-section">
                <h3>Additional Comments</h3>
                <textarea name="comments" placeholder="Share any further feedback about the office or service..."><?php echo htmlspecialchars($_POST['comments'] ?? ''); ?></textarea>
            </div>

            <button type="submit" id="submit-btn">
                <span class="btn-text">Submit Feedback</span>
                <span class="btn-loader" style="display: none;"><i class="fas fa-spinner fa-spin"></i></span>
            </button>
        </form>
    </div>

    <script>
        document.getElementById('feedback-form').addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('submit-btn');
            const btnText = submitBtn.querySelector('.btn-text');
            const btnLoader = submitBtn.querySelector('.btn-loader');

            btnText.style.display = 'none';
            btnLoader.style.display = 'inline-block';
            submitBtn.disabled = true;

            // Allow form submission to proceed
            setTimeout(() => {
                btnText.style.display = 'inline-block';
                btnLoader.style.display = 'none';
                submitBtn.disabled = false;
            }, 5000); // Reset after 5s if submission fails
        });
    </script>
</body>

</html>