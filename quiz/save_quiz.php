<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../common/auth_middleware.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed. POST method required."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Normalize Authorization headers in environments where Apache strips HTTP_AUTHORIZATION
if (empty($_SERVER["HTTP_AUTHORIZATION"])) {
    $reqHeaders = function_exists("getallheaders") ? getallheaders() : [];
    foreach ($reqHeaders as $hKey => $hVal) {
        if (strcasecmp($hKey, "Authorization") === 0) {
            $_SERVER["HTTP_AUTHORIZATION"] = $hVal;
        } elseif (strcasecmp($hKey, "X-Auth-Token") === 0) {
            $_SERVER["HTTP_X_AUTH_TOKEN"] = $hVal;
        }
    }
}

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

if (!is_array($data)) {
    $data = $_POST;
}

if (!is_array($data) || empty($data)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Invalid or empty JSON payload."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$authUser = getAuthenticatedUser($conn, false);
$userId = $authUser ? (int)$authUser["id"] : filter_var($data["user_id"] ?? $data["userId"] ?? null, FILTER_VALIDATE_INT);

if (!$userId || $userId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid user_id or Authorization Bearer token is required."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$noteId = filter_var($data["note_id"] ?? $data["noteId"] ?? null, FILTER_VALIDATE_INT);

if (!$noteId || $noteId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid 'note_id' is required to link the quiz."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$questions = $data["questions"] ?? [];
if (!is_array($questions) || empty($questions)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "A non-empty array of 'questions' is required."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 1. Verify user exists
    $userStmt = $conn->prepare("SELECT id, name FROM users WHERE id = ?");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch();

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "User account not found for ID: " . $userId
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. Verify note exists and belongs to user
    $noteStmt = $conn->prepare("SELECT * FROM notes WHERE id = ?");
    $noteStmt->execute([$noteId]);
    $note = $noteStmt->fetch();

    if (!$note) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Note not found for ID: " . $noteId
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ((int)$note["user_id"] !== $userId) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "Forbidden: You do not have permission to attach quizzes to this note."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $quizTitle = trim($data["title"] ?? ("Quiz: " . $note["title"]));
    $quizSubject = trim($data["subject"] ?? $note["subject"]);
    $difficulty = trim($data["difficulty"] ?? "Moderate");
    $questionType = trim($data["question_type"] ?? $data["questionType"] ?? "MCQ");
    $serviceUsed = trim($data["service_used"] ?? "Manual / Structured Input");
    $totalQuestions = count($questions);

    $conn->beginTransaction();

    $quizInsert = $conn->prepare("
        INSERT INTO quizzes (
            user_id, note_id, title, subject, score_percentage, correct_count, total_questions, difficulty, question_type, ai_service_used
        ) VALUES (?, ?, ?, ?, 0, 0, ?, ?, ?, ?)
    ");
    $quizInsert->execute([
        $userId, $noteId, $quizTitle, $quizSubject, $totalQuestions, $difficulty, $questionType, $serviceUsed
    ]);
    $newQuizId = (int)$conn->lastInsertId();

    $qInsert = $conn->prepare("
        INSERT INTO quiz_questions (
            quiz_id, question_number, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, topic
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $formattedQuestions = [];

    foreach ($questions as $idx => $q) {
        $qNum = (int)($q["question_number"] ?? $q["questionNumber"] ?? ($idx + 1));
        $qText = trim($q["question_text"] ?? $q["questionText"] ?? "");
        
        // Support either flat option_a..d or options array
        $optA = trim($q["option_a"] ?? "");
        $optB = trim($q["option_b"] ?? "");
        $optC = trim($q["option_c"] ?? "");
        $optD = trim($q["option_d"] ?? "");

        if (isset($q["options"]) && is_array($q["options"])) {
            foreach ($q["options"] as $opt) {
                $k = strtoupper($opt["optionKey"] ?? $opt["key"] ?? "");
                $v = trim($opt["text"] ?? "");
                if ($k === "A") $optA = $v;
                if ($k === "B") $optB = $v;
                if ($k === "C") $optC = $v;
                if ($k === "D") $optD = $v;
            }
        }

        $correctOpt = strtoupper(trim($q["correct_option"] ?? $q["correctOption"] ?? "A"));
        $explanation = trim($q["explanation"] ?? $q["explanationText"] ?? "");
        $topic = trim($q["topic"] ?? $q["topicText"] ?? $note["subject"]);

        $qInsert->execute([
            $newQuizId, $qNum, $qText, $optA, $optB, $optC, $optD, $correctOpt, $explanation, $topic
        ]);
        $insertedQId = (int)$conn->lastInsertId();

        $formattedQuestions[] = [
            "id" => $insertedQId,
            "questionNumber" => $qNum,
            "question_number" => $qNum,
            "questionText" => $qText,
            "question_text" => $qText,
            "topic" => $topic,
            "topicText" => "$topic · $quizSubject",
            "instructionText" => "Choose one answer.",
            "options" => [
                ["optionKey" => "A", "text" => $optA],
                ["optionKey" => "B", "text" => $optB],
                ["optionKey" => "C", "text" => $optC],
                ["optionKey" => "D", "text" => $optD]
            ],
            "correctOption" => $correctOpt,
            "correct_option" => $correctOpt,
            "explanation" => $explanation
        ];
    }

    $conn->commit();

    http_response_code(201);
    echo json_encode([
        "success" => true,
        "message" => "Quiz and questions saved to database successfully",
        "quiz_id" => $newQuizId,
        "note_id" => $noteId,
        "title" => $quizTitle,
        "subject" => $quizSubject,
        "difficulty" => $difficulty,
        "total_questions" => $totalQuestions,
        "estimatedTimeText" => "$totalQuestions multiple-choice questions · About $totalQuestions min",
        "questions" => $formattedQuestions
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while saving quiz: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

