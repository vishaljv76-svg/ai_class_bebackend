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

if ($authUser && $userId && (int)$authUser["id"] !== $userId) {
    http_response_code(403);
    echo json_encode([
        "success" => false,
        "message" => "Access denied. You do not have permission to view another user's quizzes."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$userId || $userId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid user_id or Authorization Bearer token is required."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$noteId = filter_var($_GET["note_id"] ?? $_GET["noteId"] ?? null, FILTER_VALIDATE_INT);
$subjectFilter = trim($_GET["subject"] ?? "");

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

    // 2. Query quizzes
    $sql = "
        SELECT q.id, q.user_id, q.note_id, q.title, q.subject, q.score_percentage, q.correct_count,
               q.total_questions, q.difficulty, q.question_type, q.ai_service_used, q.created_at, q.updated_at,
               n.title AS note_title, n.course_code,
               (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.quiz_id = q.id) AS attempts_count,
               (SELECT MAX(qa.score_percentage) FROM quiz_attempts qa WHERE qa.quiz_id = q.id) AS best_score
        FROM quizzes q
        LEFT JOIN notes n ON q.note_id = n.id
        WHERE q.user_id = ?
    ";
    $params = [$userId];

    if ($noteId && $noteId > 0) {
        $sql .= " AND q.note_id = ?";
        $params[] = $noteId;
    }

    if ($subjectFilter !== "" && strcasecmp($subjectFilter, "all") !== 0) {
        $sql .= " AND q.subject = ?";
        $params[] = $subjectFilter;
    }

    $sql .= " ORDER BY q.created_at DESC, q.id DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $quizzes = [];
    foreach ($rows as $r) {
        $quizzes[] = [
            "id" => (int)$r["id"],
            "user_id" => (int)$r["user_id"],
            "note_id" => !empty($r["note_id"]) ? (int)$r["note_id"] : null,
            "title" => $r["title"],
            "subject" => $r["subject"],
            "courseCode" => $r["course_code"] ?: "CS301",
            "noteTitle" => $r["note_title"],
            "difficulty" => $r["difficulty"] ?: "Moderate",
            "questionType" => $r["question_type"] ?: "MCQ",
            "totalQuestions" => (int)$r["total_questions"],
            "latestScore" => (int)$r["score_percentage"],
            "bestScore" => $r["best_score"] !== null ? (int)$r["best_score"] : null,
            "attemptsCount" => (int)$r["attempts_count"],
            "aiServiceUsed" => $r["ai_service_used"],
            "createdAt" => $r["created_at"],
            "created_at" => $r["created_at"]
        ];
    }

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "user_id" => $userId,
        "count" => count($quizzes),
        "quizzes" => $quizzes
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while listing quizzes: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

