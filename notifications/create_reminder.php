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

$title = trim($data["title"] ?? "");
if ($title === "") {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Reminder title is required."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$reminderDate = trim($data["reminder_date"] ?? $data["reminderDate"] ?? $data["date"] ?? "");
if ($reminderDate === "") {
    $reminderDate = date("Y-m-d");
} elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reminderDate)) {
    $parsedDate = strtotime($reminderDate);
    if ($parsedDate !== false) {
        $reminderDate = date("Y-m-d", $parsedDate);
    } else {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Invalid reminder_date format. Use YYYY-MM-DD."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$reminderTime = trim($data["reminder_time"] ?? $data["reminderTime"] ?? $data["time"] ?? "");
if ($reminderTime === "") {
    $reminderTime = date("H:i:s", strtotime("+1 hour"));
} else {
    $parsedTime = strtotime($reminderTime);
    if ($parsedTime !== false) {
        $reminderTime = date("H:i:s", $parsedTime);
    } else {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Invalid reminder_time format. Use HH:MM:SS or HH:MM."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$description = trim($data["description"] ?? "");
$noteId = filter_var($data["note_id"] ?? $data["noteId"] ?? null, FILTER_VALIDATE_INT) ?: null;
$repeatFrequency = trim($data["repeat_frequency"] ?? $data["repeatFrequency"] ?? "once");
$status = in_array(strtolower(trim($data["status"] ?? "")), ["completed", "cancelled"]) 
    ? strtolower(trim($data["status"])) 
    : "pending";

$createNotification = isset($data["create_notification"]) ? (bool)$data["create_notification"] : true;

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

    // 2. If note_id is provided, verify it belongs to user
    if ($noteId) {
        $noteStmt = $conn->prepare("SELECT id, title, subject FROM notes WHERE id = ? AND user_id = ?");
        $noteStmt->execute([$noteId, $userId]);
        $note = $noteStmt->fetch();
        if (!$note) {
            $noteId = null; // Disassociate if invalid or not owned
        }
    }

    // 3. Insert study reminder
    $stmt = $conn->prepare("
        INSERT INTO study_reminders (
            user_id, note_id, title, description, reminder_date, reminder_time, status, repeat_frequency
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $userId, $noteId, $title, $description ?: null, $reminderDate, $reminderTime, $status, $repeatFrequency
    ]);
    $reminderId = (int)$conn->lastInsertId();

    // 4. Optionally create an associated in-app notification alert
    $notificationId = null;
    if ($createNotification) {
        $notifTitle = "Study Reminder Set: " . $title;
        $notifMsg = $description ?: "Scheduled for " . date("d M Y", strtotime($reminderDate)) . " at " . date("h:i A", strtotime($reminderTime));
        $notifStmt = $conn->prepare("
            INSERT INTO notifications (user_id, reminder_id, title, message, type, is_read)
            VALUES (?, ?, ?, ?, 'study_reminder', 0)
        ");
        $notifStmt->execute([$userId, $reminderId, $notifTitle, $notifMsg]);
        $notificationId = (int)$conn->lastInsertId();
    }

    $formattedDateTime = date("d M Y", strtotime($reminderDate)) . " · " . date("h:i A", strtotime($reminderTime));

    http_response_code(201);
    echo json_encode([
        "success" => true,
        "message" => "Study reminder created successfully",
        "reminder_id" => $reminderId,
        "notification_id" => $notificationId,
        "reminder" => [
            "id" => $reminderId,
            "user_id" => $userId,
            "note_id" => $noteId,
            "title" => $title,
            "description" => $description,
            "reminder_date" => $reminderDate,
            "reminder_time" => $reminderTime,
            "formatted_schedule" => $formattedDateTime,
            "status" => $status,
            "repeat_frequency" => $repeatFrequency,
            "created_at" => date("Y-m-d H:i:s")
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while creating reminder: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

