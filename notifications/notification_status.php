<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../common/auth_middleware.php";
require_once __DIR__ . "/notifications_helper.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET" && $_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed. GET or POST method required."
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

// Collect input params from GET or JSON body
$input = $_GET;
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $rawInput = file_get_contents("php://input");
    $jsonData = json_decode($rawInput, true);
    if (is_array($jsonData)) {
        $input = array_merge($input, $jsonData);
    } elseif (!empty($_POST)) {
        $input = array_merge($input, $_POST);
    }
}

$authUser = getAuthenticatedUser($conn, false);
$userId = filter_var($input["user_id"] ?? $input["userId"] ?? null, FILTER_VALIDATE_INT);

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

try {
    // 1. Verify user exists
    $userStmt = $conn->prepare("SELECT id, name, email FROM users WHERE id = ?");
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

    // 2. Fetch reminder & notification summaries
    $remindersSummary = getReminderSummary($conn, $userId);
    $notifSummary = getNotificationSummary($conn, $userId);
    $pushInspection = getPushServiceInspection();

    // 3. Return comprehensive status
    http_response_code(200);
    echo json_encode([
        "success" => true,
        "user_id" => $userId,
        "user_name" => $user["name"],
        "badge_count" => $notifSummary["unread"],
        "has_unread_notifications" => ($notifSummary["unread"] > 0),
        "notifications" => [
            "unread_count" => $notifSummary["unread"],
            "read_count" => $notifSummary["read"],
            "total_count" => $notifSummary["total"]
        ],
        "reminders" => [
            "pending_count" => $remindersSummary["pending"],
            "completed_count" => $remindersSummary["completed"],
            "cancelled_count" => $remindersSummary["cancelled"],
            "overdue_count" => $remindersSummary["overdue"],
            "today_count" => $remindersSummary["today"],
            "total_count" => $remindersSummary["total"]
        ],
        "push_service" => $pushInspection
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while fetching notification status: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

