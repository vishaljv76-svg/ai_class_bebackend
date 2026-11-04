<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../common/auth_middleware.php";

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

$reminderId = filter_var($data["reminder_id"] ?? $data["id"] ?? null, FILTER_VALIDATE_INT);

if (!$reminderId || $reminderId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid reminder_id is required."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 1. Fetch existing reminder to check existence and ownership
    $stmt = $conn->prepare("SELECT * FROM study_reminders WHERE id = ?");
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

    // Check ownership if user_id was resolved
    if ($userId && (int)$existing["user_id"] !== $userId) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "Access denied. You do not have permission to modify this reminder."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $ownerUserId = (int)$existing["user_id"];

    // 2. Collect fields to update
    $updates = [];
    $params = [];

    if (isset($data["title"])) {
        $title = trim($data["title"]);
        if ($title === "") {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Title cannot be empty."
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $updates[] = "`title` = ?";
        $params[] = $title;
    }

    if (array_key_exists("description", $data)) {
        $updates[] = "`description` = ?";
        $params[] = trim($data["description"]) !== "" ? trim($data["description"]) : null;
    }

    if (isset($data["reminder_date"]) || isset($data["reminderDate"]) || isset($data["date"])) {
        $rawDate = trim($data["reminder_date"] ?? $data["reminderDate"] ?? $data["date"]);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) {
            $updates[] = "`reminder_date` = ?";
            $params[] = $rawDate;
        } else {
            $parsedDate = strtotime($rawDate);
            if ($parsedDate !== false) {
                $updates[] = "`reminder_date` = ?";
                $params[] = date("Y-m-d", $parsedDate);
            } else {
                http_response_code(400);
                echo json_encode([
                    "success" => false,
                    "message" => "Invalid reminder_date format. Use YYYY-MM-DD."
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }

    if (isset($data["reminder_time"]) || isset($data["reminderTime"]) || isset($data["time"])) {
        $rawTime = trim($data["reminder_time"] ?? $data["reminderTime"] ?? $data["time"]);
        $parsedTime = strtotime($rawTime);
        if ($parsedTime !== false) {
            $updates[] = "`reminder_time` = ?";
            $params[] = date("H:i:s", $parsedTime);
        } else {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Invalid reminder_time format. Use HH:MM:SS or HH:MM."
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $statusChangedToCompleted = false;
    if (isset($data["status"])) {
        $statusVal = strtolower(trim($data["status"]));
        if (!in_array($statusVal, ["pending", "completed", "cancelled"])) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Invalid status. Allowed values: pending, completed, cancelled."
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($statusVal === "completed" && $existing["status"] !== "completed") {
            $statusChangedToCompleted = true;
        }
        $updates[] = "`status` = ?";
        $params[] = $statusVal;
    }

    if (isset($data["repeat_frequency"]) || isset($data["repeatFrequency"])) {
        $freq = trim($data["repeat_frequency"] ?? $data["repeatFrequency"]);
        $updates[] = "`repeat_frequency` = ?";
        $params[] = $freq ?: "once";
    }

    if (array_key_exists("note_id", $data) || array_key_exists("noteId", $data)) {
        $rawNoteId = $data["note_id"] ?? $data["noteId"] ?? null;
        if ($rawNoteId === null || $rawNoteId === "") {
            $updates[] = "`note_id` = NULL";
        } else {
            $noteId = filter_var($rawNoteId, FILTER_VALIDATE_INT);
            if ($noteId) {
                // Verify note exists for owner
                $noteStmt = $conn->prepare("SELECT id FROM notes WHERE id = ? AND user_id = ?");
                $noteStmt->execute([$noteId, $ownerUserId]);
                if ($noteStmt->fetch()) {
                    $updates[] = "`note_id` = ?";
                    $params[] = $noteId;
                } else {
                    $updates[] = "`note_id` = NULL";
                }
            } else {
                $updates[] = "`note_id` = NULL";
            }
        }
    }

    if (empty($updates)) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "No valid fields provided to update."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 3. Execute update
    $sql = "UPDATE study_reminders SET " . implode(", ", $updates) . " WHERE id = ?";
    $params[] = $reminderId;

    $updateStmt = $conn->prepare($sql);
    $updateStmt->execute($params);

    // 4. If status changed to completed, create an alert notification
    if ($statusChangedToCompleted) {
        $compTitle = "Goal Completed: " . ($data["title"] ?? $existing["title"]);
        $compMsg = "Great job completing your study reminder!";
        $notifStmt = $conn->prepare("
            INSERT INTO notifications (user_id, reminder_id, title, message, type, is_read)
            VALUES (?, ?, ?, ?, 'study_completed', 0)
        ");
        $notifStmt->execute([$ownerUserId, $reminderId, $compTitle, $compMsg]);
    }

    // 5. Fetch fresh updated reminder
    $fetchStmt = $conn->prepare("
        SELECT 
            sr.*,
            n.title AS note_title,
            n.subject AS note_subject,
            n.course_code AS note_course_code
        FROM study_reminders sr
        LEFT JOIN notes n ON sr.note_id = n.id
        WHERE sr.id = ?
    ");
    $fetchStmt->execute([$reminderId]);
    $updated = $fetchStmt->fetch(PDO::FETCH_ASSOC);

    $formattedDate = date("d M Y", strtotime($updated["reminder_date"]));
    $formattedTime = date("h:i A", strtotime($updated["reminder_time"]));
    $formattedSchedule = "{$formattedDate} · {$formattedTime}";

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Study reminder updated successfully",
        "reminder" => [
            "id" => (int)$updated["id"],
            "user_id" => (int)$updated["user_id"],
            "note_id" => $updated["note_id"] !== null ? (int)$updated["note_id"] : null,
            "title" => $updated["title"],
            "description" => $updated["description"] ?? "",
            "reminder_date" => $updated["reminder_date"],
            "reminder_time" => $updated["reminder_time"],
            "formatted_date" => $formattedDate,
            "formatted_time" => $formattedTime,
            "formatted_schedule" => $formattedSchedule,
            "status" => $updated["status"],
            "repeat_frequency" => $updated["repeat_frequency"],
            "note" => $updated["note_id"] ? [
                "id" => (int)$updated["note_id"],
                "title" => $updated["note_title"],
                "subject" => $updated["note_subject"],
                "course_code" => $updated["note_course_code"]
            ] : null,
            "created_at" => $updated["created_at"],
            "updated_at" => $updated["updated_at"]
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while updating reminder: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

