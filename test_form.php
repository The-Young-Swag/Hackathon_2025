<?php

try {
    require 'Config/db_connection.php';
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

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
    $selected_form_code = 'TAU-UP-QF-04'; // Fallback to default
}

// Get form details
$form_query = "SELECT * FROM forms WHERE form_code = :form_code";
$stmt = $pdo->prepare($form_query);
$stmt->execute(['form_code' => $selected_form_code]);
$form = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$form) {
    die("Form not found for code: " . htmlspecialchars($selected_form_code));
}

$questions_query = "SELECT q.*, qt.type_name 
                   FROM questions q 
                   JOIN question_types qt ON q.type_id = qt.type_id 
                   WHERE q.form_id = :form_id 
                   ORDER BY q.display_order";
$stmt = $pdo->prepare($questions_query);
$stmt->execute(['form_id' => $form['form_id']]);
$questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Feedback Form</title>
    <link rel="stylesheet" href="Style/form_design.css">
</head>

<body>

    <div class="form-switcher">
        <label for="form-code-switcher">Test Form Code: </label>
        <select id="form-code-switcher" onchange="switchFormCode()">
            <?php foreach ($all_form_codes as $code): ?>
                <option value="<?php echo htmlspecialchars($code); ?>"
                    <?php echo $code === $selected_form_code ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($code); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <form action="submit.php" method="POST">
        <div class="form-header">
            <h1>Client Feedback Form</h1>
            <p class="form-metadata">
                <strong>Form Code:</strong> <?php echo htmlspecialchars($form['form_code']); ?> |
                <strong>Revision No:</strong> <?php echo htmlspecialchars($form['revision_no']); ?> |
                <strong>Effectivity:</strong> <?php echo htmlspecialchars($form['effectivity_date']); ?>
            </p>
        </div>


        <div class="form-section">
            <h3>Client Information</h3>
            <p class="instructions">Please check (✔) your answer.</p>
            <div class="client-info">
                <label>
                    Date of Visit
                    <input type="date" name="date_of_visit" required>
                </label>
                <label>
                    Age
                    <input type="number" name="age">
                </label>
                <label>
                    Sex
                    <div class="radio-group">
                        <div>
                            <input type="radio" name="sex" value="Male" id="sex-male" required>
                            <label for="sex-male">Male</label>
                        </div>
                        <div>
                            <input type="radio" name="sex" value="Female" id="sex-female">
                            <label for="sex-female">Female</label>
                        </div>
                    </div>
                </label>
                <label>
                    Region
                    <input type="text" name="region">
                </label>
                <label>
                    Office Visited
                    <input type="text" name="office_visited">
                </label>
                <label>
                    Service Availed
                    <input type="text" name="service_availed">
                </label>
                <label>
                    Community
                    <div class="checkbox-group">
                        <div>
                            <input type="checkbox" name="community[]" value="Faculty/Staff" id="comm-faculty">
                            <label for="comm-faculty">Faculty/Staff</label>
                        </div>
                        <div>
                            <input type="checkbox" name="community[]" value="Student" id="comm-student">
                            <label for="comm-student">Student</label>
                        </div>
                        <div>
                            <input type="checkbox" name="community[]" value="Visitor" id="comm-visitor">
                            <label for="comm-visitor">Visitor</label>
                        </div>
                    </div>
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
                echo '<p class="instructions">For SQD 0-8, select the option that best fits your answer.</p>';
                echo '<p class="instructions">How would you rate our service in terms of:</p>';
                echo '</div>';
            }
        ?>
            <div class="question">
                <p><strong><?php echo htmlspecialchars($question['question_code']); ?>.</strong>
                    <?php echo htmlspecialchars($question['question_text']); ?></p>
                <div class="options">
                    <?php
                    // Get options for this question
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
            <h3>Suggestions/Recommendations/Comments</h3>
            <textarea name="comments" rows="5"></textarea>
        </div>


        <button type="submit">Submit</button>
    </form>

    <script>
        function switchFormCode() {
            const select = document.getElementById('form-code-switcher');
            const formCode = select.value;
            window.location.href = `test_form.php?form_code=${encodeURIComponent(formCode)}`;
        }
    </script>
</body>

</html>