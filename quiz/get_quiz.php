<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../common/auth_middleware.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed. GET method required."
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

$authUser = getAuthenticatedUser($conn, false);
$userId = $authUser ? (int)$authUser["id"] : filter_var($_GET["user_id"] ?? $_GET["userId"] ?? null, FILTER_VALIDATE_INT);

$quizId = filter_var($_GET["id"] ?? $_GET["quiz_id"] ?? null, FILTER_VALIDATE_INT);

if (!$quizId || $quizId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid 'id' (quiz_id) is required."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 1. Fetch quiz header
    $qStmt = $conn->prepare("
        SELECT q.*, n.title AS note_title, n.course_code, n.professor
        FROM quizzes q
        LEFT JOIN notes n ON q.note_id = n.id
        WHERE q.id = ?
    ");
    $qStmt->execute([$quizId]);
    $quiz = $qStmt->fetch();

    if (!$quiz) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Quiz not found for ID: " . $quizId
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. Ownership check
    if ($userId && (int)$quiz["user_id"] !== $userId) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "Forbidden: You do not have permission to view this quiz."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 3. Fetch questions
    $questStmt = $conn->prepare("
        SELECT id, quiz_id, question_number, question_text, option_a, option_b, option_c, option_d,
               correct_option, explanation, topic, created_at
        FROM quiz_questions
        WHERE quiz_id = ?
        ORDER BY question_number ASC, id ASC
    ");
    $questStmt->execute([$quizId]);
    $questionRows = $questStmt->fetchAll();

    $questions = [];
    $totalQuestions = count($questionRows);

    foreach ($questionRows as $q) {
        $qNum = (int)$q["question_number"];
        $topic = $q["topic"] ?: $quiz["subject"];

        $questions[] = [
            "id" => (int)$q["id"],
            "questionNumber" => $qNum,
            "question_number" => $qNum,
            "questionText" => $q["question_text"],
            "question_text" => $q["question_text"],
            "topic" => $topic,
            "topicText" => "$topic · {$quiz['subject']}",
            "instructionText" => "Choose one answer.",
            "modeText" => "{$quiz['difficulty']} · {$quiz['question_type']}",
            "options" => [
                ["optionKey" => "A", "text" => $q["option_a"]],
                ["optionKey" => "B", "text" => $q["option_b"]],
                ["optionKey" => "C", "text" => $q["option_c"]],
                ["optionKey" => "D", "text" => $q["option_d"]]
            ],
            "correctOption" => strtoupper($q["correct_option"]),
            "correct_option" => strtoupper($q["correct_option"]),
            "explanation" => $q["explanation"]
        ];
    }

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "quiz" => [
            "id" => (int)$quiz["id"],
            "user_id" => (int)$quiz["user_id"],
            "note_id" => !empty($quiz["note_id"]) ? (int)$quiz["note_id"] : null,
            "title" => $quiz["title"],
            "lectureTitle" => $quiz["note_title"] ?: $quiz["title"],
            "subject" => $quiz["subject"],
            "courseCode" => $quiz["course_code"] ?: "CS301",
            "difficulty" => $quiz["difficulty"] ?: "Moderate",
            "questionType" => $quiz["question_type"] ?: "MCQ",
            "totalQuestions" => $totalQuestions,
            "estimatedTimeText" => "$totalQuestions multiple-choice questions · About $totalQuestions min",
            "latestScore" => (int)$quiz["score_percentage"],
            "correctCount" => (int)$quiz["correct_count"],
            "aiServiceUsed" => $quiz["ai_service_used"],
            "createdAt" => $quiz["created_at"]
        ],
        "questions" => $questions
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while fetching quiz: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

