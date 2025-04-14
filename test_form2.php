<?php
require 'Config/db_connection.php';

// Check for vendor/autoload.php
if (!file_exists('vendor/autoload.php')) {
    die('Error: Composer dependencies not installed. Run "composer install" in C:\xampp\htdocs\Hackathon_2025');
}
require 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\OAuth;
use League\OAuth2\Client\Provider\Google;

// Fetch form codes
$all_form_codes = [];
try {
    $stmt = $pdo->query("SELECT form_code FROM forms ORDER BY form_code");
    $all_form_codes = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    die("Failed to fetch form codes: " . $e->getMessage());
}

// Determine selected form code
$selected_form_code = $_GET['form_code'] ?? 'TAU-UP-QF-04';
if (!in_array($selected_form_code, $all_form_codes)) {
    $selected_form_code = 'TAU-UP-QF-04';
}

// Get form details
$form_query = "SELECT * FROM forms WHERE form_code = :form_code";
$stmt = $pdo->prepare($form_query);
$stmt->execute(['form_code' => $selected_form_code]);
$form = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$form) {
    die("Form not found for code: " . htmlspecialchars($selected_form_code));
}

// Get questions
$questions_query = "SELECT q.*, qt.type_name 
                   FROM questions q 
                   JOIN question_types qt ON q.type_id = qt.type_id 
                   WHERE q.form_id = :form_id 
                   ORDER BY q.display_order";
$stmt = $pdo->prepare($questions_query);
$stmt->execute(['form_id' => $form['form_id']]);
$questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate required fields
        if (empty($_POST['office_visited']) || empty($_POST['service_availed'])) {
            throw new Exception("Office Evaluated and Service Availed are required.");
        }
        if (!empty($_POST['email']) && !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email format.");
        }
        if (strlen($_POST['office_visited']) > 100 || strlen($_POST['service_availed']) > 100) {
            throw new Exception("Input too long.");
        }

        $pdo->beginTransaction();

        // Insert into clients
        $stmt = $pdo->prepare("INSERT INTO clients (date_of_visit, office_visited, service_availed) 
                              VALUES (:date_of_visit, :office_visited, :service_availed)");
        $stmt->execute([
            'date_of_visit' => $_POST['date_of_visit'] ?: null,
            'office_visited' => filter_var($_POST['office_visited'], FILTER_SANITIZE_STRING),
            'service_availed' => filter_var($_POST['service_availed'], FILTER_SANITIZE_STRING)
        ]);
        $client_id = $pdo->lastInsertId();

        // Generate submission token
        $submission_token = bin2hex(random_bytes(16));

        // Insert into form_submissions
        $stmt = $pdo->prepare("INSERT INTO form_submissions (client_id, comments, submission_token) 
                              VALUES (:client_id, :comments, :submission_token)");
        $stmt->execute([
            'client_id' => $client_id,
            'comments' => htmlspecialchars($_POST['comments'] ?? ''),
            'submission_token' => $submission_token
        ]);
        $submission_id = $pdo->lastInsertId();

        // Insert optional email
        if (!empty($_POST['email'])) {
            $expires_at = (new DateTime())->modify('+30 days')->format('Y-m-d H:i:s');
            $stmt = $pdo->prepare("INSERT INTO submission_emails (submission_id, email, expires_at) 
                                  VALUES (:submission_id, :email, :expires_at)");
            $stmt->execute([
                'submission_id' => $submission_id,
                'email' => $_POST['email'],
                'expires_at' => $expires_at
            ]);
        }

        // Insert responses
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'question_') === 0) {
                $question_id = str_replace('question_', '', $key);
                $option_id = $value;
                $stmt = $pdo->prepare("INSERT INTO responses (client_id, question_id, option_id) 
                                      VALUES (:client_id, :question_id, :option_id)");
                $stmt->execute([
                    'client_id' => $client_id,
                    'question_id' => $question_id,
                    'option_id' => $option_id
                ]);
            }
        }

        $pdo->commit();

        // Send confirmation email if email provided
        if (!empty($_POST['email'])) {
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->AuthType = 'XOAUTH2';
                $provider = new Google([
                    'clientId' => 'YOUR_ACTUAL_CLIENT_ID', // Replace with actual
                    'clientSecret' => 'YOUR_ACTUAL_CLIENT_SECRET', // Replace with actual
                ]);
                $mail->setOAuth(
                    new OAuth([
                        'provider' => $provider,
                        'clientId' => 'YOUR_ACTUAL_CLIENT_ID', // Replace with actual
                        'clientSecret' => 'YOUR_ACTUAL_CLIENT_SECRET', // Replace with actual
                        'refreshToken' => 'YOUR_ACTUAL_REFRESH_TOKEN', // Replace with actual
                        'userName' => 'your-gmail-address@gmail.com', // Replace with actual
                    ])
                );
                $mail->setFrom('your-gmail-address@gmail.com', 'Anonymous Feedback');
                $mail->addAddress($_POST['email']);
                $mail->Subject = 'Feedback Submission Confirmation';
                $mail->Body = "Thank you for your feedback on {$_POST['office_visited']}!\n\nSubmission ID: $submission_token\nOffice Evaluated: {$_POST['office_visited']}\nService Availed: {$_POST['service_availed']}\n\nYou may receive a reply from our team if needed.";
                $mail->send();

                $stmt = $pdo->prepare("INSERT INTO email_logs (submission_id, email_type, status) 
                                      VALUES (:submission_id, 'submission_confirmation', 'sent')");
                $stmt->execute(['submission_id' => $submission_id]);
            } catch (Exception $e) {
                $stmt = $pdo->prepare("INSERT INTO email_logs (submission_id, email_type, status, error_message) 
                                      VALUES (:submission_id, 'submission_confirmation', 'failed', :error)");
                $stmt->execute(['submission_id' => $submission_id, 'error' => $mail->ErrorInfo]);
            }
        }

        // Display confirmation
        echo "<div class='confirmation-message'><h3>Thank You!</h3><p>Your feedback for <strong>" . htmlspecialchars($_POST['office_visited']) . "</strong> has been submitted. Your submission ID is: <strong>$submission_token</strong>. Please keep it for reference.</p></div>";
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<div class='error-message'>Submission failed: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anonymous Feedback Form</title>
    <link rel="stylesheet" href="Style/form_design.css">
</head>

<body>
    <div class="form-switcher">
        <label for="form-code-switcher">Form Code: </label>
        <select id="form-code-switcher" onchange="switchFormCode()">
            <?php foreach ($all_form_codes as $code): ?>
                <option value="<?php echo htmlspecialchars($code); ?>" <?php echo $code === $selected_form_code ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($code); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <form action="" method="POST">
        <div class="form-header">
            <h1>Anonymous Feedback Form</h1>
            <p class="form-metadata">
                <strong>Form Code:</strong> <?php echo htmlspecialchars($form['form_code']); ?> |
                <strong>Revision No:</strong> <?php echo htmlspecialchars($form['revision_no']); ?> |
                <strong>Effectivity:</strong> <?php echo htmlspecialchars($form['effectivity_date']); ?>
            </p>
        </div>

        <div class="form-section">
            <h3>Evaluation Details</h3>
            <p class="instructions">Please provide details about the office you are evaluating.</p>
            <div class="client-info">
                <label>
                    Office Evaluated
                    <input type="text" name="office_visited" placeholder="e.g., Registrar" required>
                </label>
                <label>
                    Service Availed
                    <input type="text" name="service_availed" placeholder="e.g., Transcript Request" required>
                </label>
                <label>
                    Date of Visit (Optional)
                    <input type="date" name="date_of_visit">
                </label>
                <label>
                    Email (Optional, for replies)
                    <input type="email" name="email" placeholder="e.g., your.email@example.com">
                </label>
            </div>
        </div>

        <?php
        $current_section = '';
        foreach ($questions as $question) {
            if (strpos($question['question_code'], 'CC') === 0 && $current_section != 'CC') {
                $current_section = 'CC';
                echo '<div class="form-section"><h3>Citizen’s Charter Questions</h3></div>';
            } elseif (strpos($question['question_code'], 'SQD') === 0 && $current_section != 'SQD') {
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

                    if ($question['type_name'] == 'Multiple Choice') {
                        echo '<table class="multiple-choice-table">';
                        foreach ($options as $option) {
                            echo '<tr>';
                            echo '<td class="radio-cell">';
                            echo '<input type="radio" name="question_' . $question['question_id'] . '" value="' . $option['option_id'] . '" required>';
                            echo '</td>';
                            echo '<td>' . htmlspecialchars($option['option_text']) . '</td>';
                            echo '</tr>';
                        }
                        echo '</table>';
                    } else {
                        echo '<table class="likert-table">';
                        echo '<tr>';
                        foreach ($options as $option) {
                            echo '<th>' . htmlspecialchars($option['option_text']) . '</th>';
                        }
                        echo '</tr>';
                        echo '<tr>';
                        foreach ($options as $option) {
                            echo '<td>';
                            echo '<input type="radio" name="question_' . $question['question_id'] . '" value="' . $option['option_id'] . '" required>';
                            echo htmlspecialchars($option['option_value']);
                            echo '</td>';
                        }
                        echo '</tr>';
                        echo '</table>';
                    }
                    ?>
                </div>
            </div>
        <?php } ?>

        <div class="form-section">
            <h3>Additional Comments</h3>
            <textarea name="comments" rows="5" placeholder="Share any further feedback about the office or service..."></textarea>
        </div>

        <button type="submit">Submit Feedback</button>
    </form>

    <script>
        function switchFormCode() {
            const select = document.getElementById('form-code-switcher');
            const formCode = select.value;
            window.location.href = `test_form2.php?form_code=${encodeURIComponent(formCode)}`;
        }
    </script>
</body>

</html>