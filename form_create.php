<?php
// create_form.php
// Admin tool for creating feedback forms with dynamic questions and options

require 'Config/db_connection.php';

// Initialize variables
$question_types = [];
$success_message = '';
$error_message = '';

// Load question types for dropdown
try {
    $stmt = $pdo->query("SELECT type_id, type_name FROM question_types ORDER BY type_name");
    $question_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Could not load question types: " . htmlspecialchars($e->getMessage());
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        // Validate form metadata
        $form_code = trim($_POST['form_code'] ?? '');
        $revision_no = trim($_POST['revision_no'] ?? '');
        $effectivity_date = $_POST['effectivity_date'] ?? '';

        if (empty($form_code) || empty($revision_no) || empty($effectivity_date)) {
            throw new Exception("All form metadata fields are required.");
        }

        // Check for unique form_code and revision_no combination
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM forms WHERE form_code = :form_code AND revision_no = :revision_no");
        $stmt->execute(['form_code' => $form_code, 'revision_no' => $revision_no]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception("A form with this code and revision number already exists.");
        }

        // Insert form metadata
        $form_data = [
            ':form_code' => $form_code,
            ':revision_no' => $revision_no,
            ':effectivity_date' => $effectivity_date
        ];
        $stmt = $pdo->prepare(
            "INSERT INTO forms (form_code, revision_no, effectivity_date) 
             VALUES (:form_code, :revision_no, :effectivity_date)"
        );
        $stmt->execute($form_data);
        $form_id = $pdo->lastInsertId();

        // Insert questions and options
        $display_order = 1;
        if (!isset($_POST['questions']) || !is_array($_POST['questions'])) {
            throw new Exception("At least one question is required.");
        }

        foreach ($_POST['questions'] as $question) {
            // Validate question data
            $question_code = trim($question['question_code'] ?? '');
            $question_text = trim($question['question_text'] ?? '');
            $type_id = $question['type_id'] ?? '';

            if (empty($question_code) || empty($question_text) || empty($type_id)) {
                throw new Exception("All question fields are required.");
            }

            $question_data = [
                ':form_id' => $form_id,
                ':type_id' => $type_id,
                ':question_code' => $question_code,
                ':question_text' => $question_text,
                ':display_order' => $display_order
            ];
            $stmt = $pdo->prepare(
                "INSERT INTO questions (form_id, type_id, question_code, question_text, display_order) 
                 VALUES (:form_id, :type_id, :question_code, :question_text, :display_order)"
            );
            $stmt->execute($question_data);
            $question_id = $pdo->lastInsertId();
            $display_order++;

            // Insert options, if provided
            if (isset($question['options']) && is_array($question['options'])) {
                foreach ($question['options'] as $option) {
                    $option_value = trim($option['value'] ?? '');
                    $option_text = trim($option['text'] ?? '');

                    if (empty($option_value) || empty($option_text)) {
                        throw new Exception("Option value and text are required for question $question_code.");
                    }

                    $option_data = [
                        ':question_id' => $question_id,
                        ':option_value' => $option_value,
                        ':option_text' => $option_text
                    ];
                    $stmt = $pdo->prepare(
                        "INSERT INTO options (question_id, option_value, option_text) 
                         VALUES (:question_id, :option_value, :option_text)"
                    );
                    $stmt->execute($option_data);
                }
            } else {
                throw new Exception("At least one option is required for question $question_code.");
            }
        }

        // Set form as active
        $stmt = $pdo->prepare(
            "INSERT INTO active_form (form_id, set_at) 
             VALUES (:form_id, CURRENT_TIMESTAMP)"
        );
        $stmt->execute(['form_id' => $form_id]);

        $pdo->commit();
        $success_message = "Form created successfully and set as active!";
    } catch (Exception $e) {
        $pdo->rollBack();
        $error_message = "Failed to create form: " . htmlspecialchars($e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Form Maker - Admin Panel</title>
    <link rel="stylesheet" href="Style/admin-sidebar.css">
    <link rel="stylesheet" href="Style/form_create.css">
    <script src="https://kit.fontawesome.com/a076d05399.js" crossorigin="anonymous"></script>
</head>

<body>
    <!-- Sidebar Navigation -->
    <div class="sidebar">
        <button class="toggle-btn" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <h2>Admin Panel</h2>
        <a href="admin_dashboard.php">
            <i class="fas fa-tachometer-alt"></i>
            <span class="link-text">Dashboard</span>
        </a>
        <a href="view_offices.php">
            <i class="fas fa-building"></i>
            <span class="link-text">View Offices</span>
        </a>
        <a href="create_form.php" class="active">
            <i class="fas fa-file-alt"></i>
            <span class="link-text">Form Maker</span>
        </a>
        <button class="logout-btn">
            <i class="fas fa-sign-out-alt"></i>
            <span class="link-text">Logout</span>
        </button>
    </div>

    <!-- Main Content Area -->
    <div class="main-content">
        <div class="form-maker-container">
            <div class="form-maker-header">
                <h1>Create New Form</h1>
            </div>

            <!-- Feedback Messages -->
            <?php if ($success_message): ?>
                <div class="message success">
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php elseif ($error_message): ?>
                <div class="message error">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <!-- Form Creation Interface -->
            <form id="form-maker" method="POST" onsubmit="return validateForm()">
                <!-- Form Metadata Section -->
                <div class="form-section">
                    <h3>Form Metadata</h3>
                    <div class="metadata-section">
                        <label>
                            Form Code <span class="required">*</span>
                            <input type="text" name="form_code" required placeholder="e.g., TAU-UP-QF-04" maxlength="50">
                        </label>
                        <label>
                            Revision No <span class="required">*</span>
                            <input type="text" name="revision_no" required placeholder="e.g., 01" maxlength="10">
                        </label>
                        <label>
                            Effectivity Date <span class="required">*</span>
                            <input type="date" name="effectivity_date" required>
                        </label>
                    </div>
                </div>

                <!-- Questions Section -->
                <div class="form-section">
                    <h3>Questions</h3>
                    <div id="questions-container"></div>
                    <button type="button" class="add-question-btn" onclick="addQuestion()">
                        Add Question
                    </button>
                </div>

                <button type="submit">Save Form</button>
            </form>
        </div>
    </div>

    <!-- Options Modal for Question Options -->
    <div class="modal" id="options-modal">
        <div class="modal-content">
            <h2>Manage Options</h2>
            <button class="close-modal" onclick="closeOptionsModal()">×</button>
            <div id="options-container"></div>
            <button type="button" class="add-question-btn" onclick="addOption()">
                Add Option
            </button>
            <button type="button" class="add-question-btn" onclick="saveOptions()">
                Save Options
            </button>
        </div>
    </div>

    <script>
        // Pass question types to JavaScript
        const questionTypes = <?php echo json_encode($question_types); ?>;
    </script>
    <script src="Script/form_maker.js"></script>
    <script>
        // Client-side form validation
        function validateForm() {
            const questions = document.querySelectorAll('.question-card');
            if (questions.length === 0) {
                alert('Please add at least one question.');
                return false;
            }
            for (let card of questions) {
                const optionsList = card.querySelector('.options-list');
                if (!optionsList.hasChildNodes()) {
                    alert('Please add options for all questions.');
                    return false;
                }
            }
            return true;
        }
    </script>
</body>

</html>