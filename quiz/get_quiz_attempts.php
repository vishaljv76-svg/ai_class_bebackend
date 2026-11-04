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
$userId = filter_var($_GET["user_id"] ?? $_GET["userId"] ?? null, FILTER_VALIDATE_INT);

if (!$userId && $authUser) {
    $userId = (int)$authUser["id"];
}

if (!$userId || $userId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid user_id or Authorization Bearer token is required."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$quizId = filter_var($_GET["quiz_id"] ?? $_GET["quizId"] ?? null, FILTER_VALIDATE_INT);
$attemptId = filter_var($_GET["attempt_id"] ?? $_GET["id"] ?? null, FILTER_VALIDATE_INT);

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

    // 2. Build query
    $sql = "
        SELECT qa.*, q.title AS quiz_title, q.subject, q.difficulty
        FROM quiz_attempts qa
        JOIN quizzes q ON qa.quiz_id = q.id
        WHERE qa.user_id = ?
    ";
    $params = [$userId];

    if ($attemptId && $attemptId > 0) {
        $sql .= " AND qa.id = ?";
        $params[] = $attemptId;
    }

    if ($quizId && $quizId > 0) {
        $sql .= " AND qa.quiz_id = ?";
        $params[] = $quizId;
    }

    $sql .= " ORDER BY qa.created_at DESC, qa.id DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $attempts = [];
    foreach ($rows as $r) {
        $answers = json_decode($r["answers_json"], true) ?: [];
        $reviewItems = json_decode($r["review_json"], true) ?: [];

        $attempts[] = [
            "id" => (int)$r["id"],
            "quiz_id" => (int)$r["quiz_id"],
            "quizTitle" => $r["quiz_title"],
            "subject" => $r["subject"],
            "difficulty" => $r["difficulty"],
            "scorePercentage" => (int)$r["score_percentage"],
            "correctCount" => (int)$r["correct_count"],
            "totalQuestions" => (int)$r["total_questions"],
            "feedbackHeader" => $r["feedback_header"],
            "feedbackText" => $r["feedback_text"],
            "submittedAnswers" => $answers,
            "reviewItems" => $reviewItems,
            "createdAt" => $r["created_at"],
            "created_at" => $r["created_at"]
        ];
    }

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "user_id" => $userId,
        "count" => count($attempts),
        "attempts" => $attempts
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while fetching quiz attempts: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

