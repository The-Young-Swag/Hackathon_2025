<?php
// form_maker.php
// Admin tool for creating feedback forms with dynamic questions and options

require 'Config/db_connection.php';

// Initialize variables
$question_types = [];
$success_message = '';
$error_message = '';

// Load question types for dropdown
try {
    $stmt = $pdo->query("SELECT * FROM question_types");
    $question_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Couldn’t load question types: " . $e->getMessage();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        // Insert form metadata
        $form_data = [
            ':form_code' => $_POST['form_code'],
            ':revision_no' => $_POST['revision_no'],
            ':effectivity_date' => $_POST['effectivity_date']
        ];
        $stmt = $pdo->prepare(
            "INSERT INTO forms (form_code, revision_no, effectivity_date) 
             VALUES (:form_code, :revision_no, :effectivity_date)"
        );
        $stmt->execute($form_data);
        $form_id = $pdo->lastInsertId();

        // Insert questions and options
        $display_order = 1;
        foreach ($_POST['questions'] ?? [] as $question) {
            $question_data = [
                ':form_id' => $form_id,
                ':type_id' => $question['type_id'],
                ':question_code' => $question['question_code'],
                ':question_text' => $question['question_text'],
                ':display_order' => $display_order
            ];
            $stmt = $pdo->prepare(
                "INSERT INTO questions (form_id, type_id, question_code, question_text, display_order) 
                 VALUES (:form_id, :type_id, :question_code, :question_text, :display_order)"
            );
            $stmt->execute($question_data);
            $question_id = $pdo->lastInsertId();
            $display_order++;

            // Insert options, if any
            foreach ($question['options'] ?? [] as $option) {
                $option_data = [
                    ':question_id' => $question_id,
                    ':option_value' => $option['value'],
                    ':option_text' => $option['text']
                ];
                $stmt = $pdo->prepare(
                    "INSERT INTO options (question_id, option_value, option_text) 
                     VALUES (:question_id, :option_value, :option_text)"
                );
                $stmt->execute($option_data);
            }
        }

        $pdo->commit();
        $success_message = "Form saved successfully!";
    } catch (Exception $e) {
        $pdo->rollBack();
        $error_message = "Failed to save form: " . $e->getMessage();
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
    <link rel="stylesheet" href="Style/form_maker_design.css">
    <script src="https://kit.fontawesome.com/a076d05399.js" crossorigin="anonymous"></script>
</head>

<body>
    <!-- Sidebar -->
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
        <a href="form_maker.php" class="active">
            <i class="fas fa-file-alt"></i>
            <span class="link-text">Form Maker</span>
        </a>
        <button class="logout-btn">
            <i class="fas fa-sign-out-alt"></i>
            <span class="link-text">Logout</span>
        </button>
    </div>

    <!-- Main Content -->
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

            <!-- Form -->
            <form id="form-maker" method="POST">
                <!-- Metadata -->
                <div class="form-section">
                    <h3>Form Metadata</h3>
                    <div class="metadata-section">
                        <label>
                            Form Code
                            <input type="text" name="form_code" required placeholder="e.g., TAU-UP-QF-04">
                        </label>
                        <label>
                            Revision No
                            <input type="text" name="revision_no" required placeholder="e.g., 01">
                        </label>
                        <label>
                            Effectivity Date
                            <input type="date" name="effectivity_date" required>
                        </label>
                    </div>
                </div>

                <!-- Questions -->
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

    <!-- Options Modal -->
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
        const questionTypes = <?php echo json_encode($question_types); ?>;
    </script>
    <script src="Script/form_maker.js"></script>
</body>

</html>