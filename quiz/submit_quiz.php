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

$quizId = filter_var($data["quiz_id"] ?? $data["quizId"] ?? null, FILTER_VALIDATE_INT);

if (!$quizId || $quizId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid 'quiz_id' is required to submit answers."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$userAnswers = $data["answers"] ?? [];
if (!is_array($userAnswers)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "'answers' must be an object or array mapping questions to selected option keys."
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

    // 2. Fetch quiz and verify ownership
    $qStmt = $conn->prepare("SELECT * FROM quizzes WHERE id = ?");
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

    if ((int)$quiz["user_id"] !== $userId) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "Forbidden: You do not have permission to submit answers for this quiz."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 3. Fetch questions
    $questStmt = $conn->prepare("
        SELECT * FROM quiz_questions WHERE quiz_id = ? ORDER BY question_number ASC, id ASC
    ");
    $questStmt->execute([$quizId]);
    $questions = $questStmt->fetchAll();

    if (empty($questions)) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "This quiz contains no questions."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $totalQuestions = count($questions);
    $correctCount = 0;
    $reviewItems = [];

    foreach ($questions as $idx => $q) {
        $qId = (int)$q["id"];
        $qNum = (int)$q["question_number"];
        $correctOpt = strtoupper(trim($q["correct_option"]));

        // Look up user's answer by question ID, string ID, question number, or index
        $userOpt = null;
        if (isset($userAnswers[$qId])) {
            $userOpt = $userAnswers[$qId];
        } elseif (isset($userAnswers[(string)$qId])) {
            $userOpt = $userAnswers[(string)$qId];
        } elseif (isset($userAnswers[$qNum])) {
            $userOpt = $userAnswers[$qNum];
        } elseif (isset($userAnswers[(string)$qNum])) {
            $userOpt = $userAnswers[(string)$qNum];
        } elseif (isset($userAnswers[$idx])) {
            $userOpt = $userAnswers[$idx];
        }

        $userOpt = $userOpt !== null ? strtoupper(trim((string)$userOpt)) : null;

        $isCorrect = ($userOpt !== null && $userOpt === $correctOpt);
        if ($isCorrect) {
            $correctCount++;
        }

        // Map option text
        $optMap = [
            "A" => $q["option_a"],
            "B" => $q["option_b"],
            "C" => $q["option_c"],
            "D" => $q["option_d"]
        ];

        $userText = $userOpt && isset($optMap[$userOpt]) ? "$userOpt — " . $optMap[$userOpt] : ($userOpt ?: "No answer");
        $correctText = isset($optMap[$correctOpt]) ? "$correctOpt — " . $optMap[$correctOpt] : $correctOpt;

        $reviewItems[] = [
            "questionNumber" => "Q" . $qNum,
            "questionTopic" => $q["topic"] ?: $quiz["title"],
            "isCorrect" => $isCorrect,
            "userAnswerKey" => $userOpt,
            "userAnswerText" => $userText,
            "correctAnswerKey" => $correctOpt,
            "correctAnswerText" => $correctText,
            "explanationText" => $q["explanation"] ?: ($correctOpt . " is the correct answer.")
        ];
    }

    // 4. Calculate score
    $scorePercentage = (int)round(($correctCount / $totalQuestions) * 100);

    if ($scorePercentage >= 80) {
        $titleMessage = "You're getting the big picture.";
        $feedbackHeader = "A strong foundation";
        $feedbackText = "You demonstrated an outstanding grasp of the core concepts in this lecture. Keep up the great work!";
    } elseif ($scorePercentage >= 60) {
        $titleMessage = "Solid progress on this topic.";
        $feedbackHeader = "Good understanding";
        $feedbackText = "You understand the primary mechanisms well. Review the highlighted questions to master the remaining details.";
    } else {
        $titleMessage = "Keep practicing.";
        $feedbackHeader = "Room to grow";
        $feedbackText = "Review the original study notes and key takeaways, then retake the quiz to build your confidence.";
    }

    $reviewJson = json_encode($reviewItems, JSON_UNESCAPED_UNICODE);
    $answersJson = json_encode($userAnswers, JSON_UNESCAPED_UNICODE);

    // 5. Store attempt in MySQL
    $attemptStmt = $conn->prepare("
        INSERT INTO quiz_attempts (
            quiz_id, user_id, score_percentage, correct_count, total_questions,
            answers_json, review_json, feedback_header, feedback_text
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $attemptStmt->execute([
        $quizId, $userId, $scorePercentage, $correctCount, $totalQuestions,
        $answersJson, $reviewJson, $feedbackHeader, $feedbackText
    ]);
    $newAttemptId = (int)$conn->lastInsertId();

    // 6. Update quizzes table with latest score
    $updateQuiz = $conn->prepare("
        UPDATE quizzes SET
            score_percentage = ?,
            correct_count = ?,
            total_questions = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $updateQuiz->execute([$scorePercentage, $correctCount, $totalQuestions, $quizId]);

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Quiz submitted and evaluated successfully",
        "attempt_id" => $newAttemptId,
        "quiz_id" => $quizId,
        "score_percentage" => $scorePercentage,
        "correct_count" => $correctCount,
        "total_questions" => $totalQuestions,
        "feedback_header" => $feedbackHeader,
        "feedback_text" => $feedbackText,
        "review_items" => $reviewItems,
        "results" => [
            "titleMessage" => $titleMessage,
            "scorePercentage" => $scorePercentage,
            "correctCount" => $correctCount,
            "totalQuestions" => $totalQuestions,
            "quizTopicMetadata" => "{$quiz['title']} · {$quiz['difficulty']} · $totalQuestions MCQs",
            "feedbackHeader" => $feedbackHeader,
            "feedbackText" => $feedbackText,
            "reviewItems" => $reviewItems
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while evaluating quiz submission: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

