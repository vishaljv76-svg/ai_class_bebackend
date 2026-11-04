<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../common/auth_middleware.php";
require_once __DIR__ . "/notifications_helper.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST" && $_SERVER["REQUEST_METHOD"] !== "PUT" && $_SERVER["REQUEST_METHOD"] !== "PATCH") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed. POST, PUT, or PATCH method required."
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

$markAll = !empty($data["mark_all"]) || !empty($data["markAll"]);
$notificationId = filter_var($data["notification_id"] ?? $data["id"] ?? null, FILTER_VALIDATE_INT);
$isRead = isset($data["is_read"]) ? ((int)$data["is_read"] ? 1 : 0) : 1;

if (!$markAll && (!$notificationId || $notificationId < 1)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Either notification_id or mark_all=true is required."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Mode 1: Bulk "Mark All as Read"
    if ($markAll) {
        if (!$userId || $userId < 1) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Valid user_id or Authorization Bearer token is required for mark_all."
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Verify user exists
        $userStmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
        $userStmt->execute([$userId]);
        if (!$userStmt->fetch()) {
            http_response_code(404);
            echo json_encode([
                "success" => false,
                "message" => "User account not found for ID: " . $userId
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $updateStmt = $conn->prepare("UPDATE notifications SET is_read = ? WHERE user_id = ?");
        $updateStmt->execute([$isRead, $userId]);
        $affectedRows = $updateStmt->rowCount();

        $summary = getNotificationSummary($conn, $userId);

        http_response_code(200);
        echo json_encode([
            "success" => true,
            "message" => $isRead === 1 ? "All notifications marked as read." : "All notifications marked as unread.",
            "marked_all" => true,
            "updated_count" => $affectedRows,
            "unread_count" => $summary["unread"],
            "total_count" => $summary["total"]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // Mode 2: Single Notification update
    $stmt = $conn->prepare("SELECT * FROM notifications WHERE id = ?");
    $stmt->execute([$notificationId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Notification not found for ID: " . $notificationId
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Ownership check
    if ($userId && (int)$existing["user_id"] !== $userId) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "Access denied. You do not have permission to update this notification."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $ownerId = (int)$existing["user_id"];

    $updateStmt = $conn->prepare("UPDATE notifications SET is_read = ? WHERE id = ?");
    $updateStmt->execute([$isRead, $notificationId]);

    $summary = getNotificationSummary($conn, $ownerId);

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => $isRead === 1 ? "Notification marked as read." : "Notification marked as unread.",
        "notification_id" => $notificationId,
        "is_read" => $isRead,
        "unread_count" => $summary["unread"],
        "total_count" => $summary["total"]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while updating notification: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

