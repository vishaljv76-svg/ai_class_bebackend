<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../common/auth_middleware.php";
require_once __DIR__ . "/notifications_helper.php";

if ($_SERVER["REQUEST_METHOD"] !== "DELETE" && $_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed. DELETE or POST method required."
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

// Collect params from query or JSON body
$input = $_GET;
$rawInput = file_get_contents("php://input");
if ($rawInput) {
    $jsonData = json_decode($rawInput, true);
    if (is_array($jsonData)) {
        $input = array_merge($input, $jsonData);
    }
}
if (!empty($_POST)) {
    $input = array_merge($input, $_POST);
}

$authUser = getAuthenticatedUser($conn, false);
$userId = $authUser ? (int)$authUser["id"] : filter_var($input["user_id"] ?? $input["userId"] ?? null, FILTER_VALIDATE_INT);
$reminderId = filter_var($input["reminder_id"] ?? $input["id"] ?? null, FILTER_VALIDATE_INT);

if (!$reminderId || $reminderId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid reminder_id is required."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 1. Check if reminder exists
    $stmt = $conn->prepare("SELECT id, user_id, title FROM study_reminders WHERE id = ?");
    $stmt->execute([$reminderId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Study reminder not found for ID: " . $reminderId
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. Check ownership
    if ($userId && (int)$existing["user_id"] !== $userId) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "Access denied. You do not have permission to delete this reminder."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $ownerId = (int)$existing["user_id"];

    // 3. Delete reminder safely
    // Note: Foreign key on notifications table sets reminder_id to NULL on delete, preserving notification history
    $deleteStmt = $conn->prepare("DELETE FROM study_reminders WHERE id = ?");
    $deleteStmt->execute([$reminderId]);

    $summary = getReminderSummary($conn, $ownerId);

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Study reminder deleted successfully",
        "deleted_reminder_id" => $reminderId,
        "reminder_title" => $existing["title"],
        "summary" => $summary
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while deleting reminder: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

