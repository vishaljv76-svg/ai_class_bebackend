<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../common/auth_middleware.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST" && $_SERVER["REQUEST_METHOD"] !== "DELETE") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed. POST or DELETE method required."
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

$authUser = getAuthenticatedUser($conn, false);
$userId = $authUser ? (int)$authUser["id"] : filter_var($data["user_id"] ?? $data["userId"] ?? ($_GET["user_id"] ?? null), FILTER_VALIDATE_INT);

$quizId = filter_var($data["id"] ?? $data["quiz_id"] ?? ($_GET["id"] ?? null), FILTER_VALIDATE_INT);

if (!$quizId || $quizId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid quiz 'id' is required for deletion."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 1. Fetch quiz to verify existence and ownership
    $stmt = $conn->prepare("SELECT id, user_id, title FROM quizzes WHERE id = ?");
    $stmt->execute([$quizId]);
    $quiz = $stmt->fetch();

    if (!$quiz) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Quiz not found for ID: " . $quizId
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($userId && (int)$quiz["user_id"] !== $userId) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "Forbidden: You do not have permission to delete this quiz."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. Delete quiz (foreign key ON DELETE CASCADE automatically removes quiz_questions and quiz_attempts)
    $delStmt = $conn->prepare("DELETE FROM quizzes WHERE id = ?");
    $delStmt->execute([$quizId]);

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Quiz and associated questions deleted successfully",
        "quiz_id" => $quizId
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while deleting quiz: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

