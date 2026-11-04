<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../common/auth_middleware.php";
require_once __DIR__ . "/progress_helper.php";

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
        "message" => "Access denied. You do not have permission to view another user's activities."
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

$limit = filter_var($_GET["limit"] ?? 10, FILTER_VALIDATE_INT) ?: 10;
if ($limit < 1) $limit = 5;
if ($limit > 50) $limit = 50;

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

    // 2. Fetch unified recent activities
    $activities = getUserRecentActivities($conn, $userId, $limit);

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "user_id" => $userId,
        "count" => count($activities),
        "activities" => $activities
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while fetching recent activities: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

