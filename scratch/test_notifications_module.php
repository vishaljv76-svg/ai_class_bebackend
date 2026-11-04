<?php
/**
 * Automated Test Suite for Notifications & Reminders Module
 * SmartNotes AI Backend
 */

$baseUrl = "http://localhost/ai_class_be";
$tokenUser7 = "e0955a80dac4aeab5ca36214f89b7f9799f6689065aa0975eb005c48c47fb67c";
$tokenUser6 = "3ebebbd6c1d0315c42f9fd3b0fe85984a1a33029485796a8420c5b285fac1c71";
$userId7 = 7;
$userId6 = 6;
$noteId = 17;

$passCount = 0;
$failCount = 0;

function httpRequest(string $method, string $url, array $headers = [], $body = null): array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $formattedHeaders = [];
    foreach ($headers as $k => $v) {
        $formattedHeaders[] = "$k: $v";
    }

    if ($body !== null) {
        $payload = is_array($body) ? json_encode($body) : $body;
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        if (!isset($headers["Content-Type"])) {
            $formattedHeaders[] = "Content-Type: application/json";
        }
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $formattedHeaders);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $decoded = json_decode($response, true);
    return [
        "code" => $httpCode,
        "body" => $decoded,
        "raw" => $response,
        "error" => $curlError
    ];
}

function assertTest(string $name, bool $condition, string $detail = "") {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "[PASS] {$name}\n";
    } else {
        $failCount++;
        echo "[FAIL] {$name} - {$detail}\n";
    }
}

echo "====================================================\n";
echo "SMARTNOTES AI - NOTIFICATIONS & REMINDERS TEST SUITE\n";
echo "====================================================\n\n";

// ---------------------------------------------------------
// 1. CREATE REMINDER TESTS
// ---------------------------------------------------------
echo "--- 1. Testing Create Reminder (POST /notifications/create_reminder.php) ---\n";

// 1.1 Valid Reminder Creation with Token
$res = httpRequest("POST", "$baseUrl/notifications/create_reminder.php", [
    "Authorization" => "Bearer $tokenUser7"
], [
    "title" => "Review 3NF & BCNF Decomposition",
    "description" => "Study multi-valued dependencies and normal forms before midterm exam.",
    "reminder_date" => date("Y-m-d", strtotime("+2 days")),
    "reminder_time" => "14:30:00",
    "note_id" => $noteId,
    "repeat_frequency" => "once"
]);
assertTest("1.1 Create reminder with Bearer token", $res["code"] === 201 && ($res["body"]["success"] ?? false) === true);
$createdReminderId1 = $res["body"]["reminder_id"] ?? null;
$createdNotificationId1 = $res["body"]["notification_id"] ?? null;
assertTest("1.1 Reminder ID returned and valid", $createdReminderId1 > 0);
assertTest("1.1 Associated Notification ID created", $createdNotificationId1 > 0);

// 1.2 Valid Reminder Creation with user_id parameter
$res = httpRequest("POST", "$baseUrl/notifications/create_reminder.php", [], [
    "user_id" => $userId7,
    "title" => "Algorithm Practice: Raft Log Replication",
    "description" => "Solve quiz questions for distributed consensus.",
    "reminder_date" => date("Y-m-d"),
    "reminder_time" => "18:00:00",
    "repeat_frequency" => "daily"
]);
assertTest("1.2 Create reminder with user_id param", $res["code"] === 201 && ($res["body"]["success"] ?? false) === true);
$createdReminderId2 = $res["body"]["reminder_id"] ?? null;

// 1.3 Validation Error: Missing title
$res = httpRequest("POST", "$baseUrl/notifications/create_reminder.php", [
    "Authorization" => "Bearer $tokenUser7"
], [
    "title" => "",
    "reminder_date" => "2026-12-01"
]);
assertTest("1.3 Reject empty title (400)", $res["code"] === 400 && ($res["body"]["success"] ?? true) === false);

// 1.4 Validation Error: Invalid date
$res = httpRequest("POST", "$baseUrl/notifications/create_reminder.php", [
    "Authorization" => "Bearer $tokenUser7"
], [
    "title" => "Invalid Date Test",
    "reminder_date" => "not-a-valid-date"
]);
assertTest("1.4 Reject invalid date (400)", $res["code"] === 400);

// 1.5 Validation Error: Non-existent user
$res = httpRequest("POST", "$baseUrl/notifications/create_reminder.php", [], [
    "user_id" => 999999,
    "title" => "Ghost User Test"
]);
assertTest("1.5 Reject non-existent user (404)", $res["code"] === 404);

// 1.6 Method Not Allowed
$res = httpRequest("GET", "$baseUrl/notifications/create_reminder.php");
assertTest("1.6 Reject GET method (405)", $res["code"] === 405);


// ---------------------------------------------------------
// 2. LIST REMINDERS TESTS
// ---------------------------------------------------------
echo "\n--- 2. Testing List Reminders (GET /notifications/list_reminders.php) ---\n";

// 2.1 List Reminders for User 7
$res = httpRequest("GET", "$baseUrl/notifications/list_reminders.php?user_id=$userId7");
assertTest("2.1 List reminders returns 200", $res["code"] === 200 && ($res["body"]["success"] ?? false) === true);
assertTest("2.1 Returns summary structure", isset($res["body"]["summary"]["total"]) && isset($res["body"]["summary"]["pending"]));
assertTest("2.1 Returns reminders array", is_array($res["body"]["reminders"]) && count($res["body"]["reminders"]) >= 2);

// Check schedule formatting and note join
$firstRem = $res["body"]["reminders"][0] ?? [];
assertTest("2.1 Reminder has formatted_schedule", !empty($firstRem["formatted_schedule"]));
assertTest("2.1 Reminder has is_overdue flag", isset($firstRem["is_overdue"]));

// 2.2 Filter by status=pending
$res = httpRequest("GET", "$baseUrl/notifications/list_reminders.php?user_id=$userId7&status=pending");
assertTest("2.2 Filter by status=pending returns 200", $res["code"] === 200);
$allPending = true;
foreach ($res["body"]["reminders"] ?? [] as $r) {
    if ($r["status"] !== "pending") $allPending = false;
}
assertTest("2.2 All returned reminders are pending", $allPending);

// 2.3 Filter by note_id
$res = httpRequest("GET", "$baseUrl/notifications/list_reminders.php?user_id=$userId7&note_id=$noteId");
assertTest("2.3 Filter by note_id returns 200", $res["code"] === 200);
$matchedNote = true;
foreach ($res["body"]["reminders"] ?? [] as $r) {
    if ($r["note_id"] !== $noteId) $matchedNote = false;
}
assertTest("2.3 All filtered reminders match note_id", $matchedNote && count($res["body"]["reminders"]) >= 1);

// 2.4 Pagination: limit=1, offset=0
$res = httpRequest("GET", "$baseUrl/notifications/list_reminders.php?user_id=$userId7&limit=1&offset=0");
assertTest("2.4 Pagination limit=1 works", $res["code"] === 200 && count($res["body"]["reminders"]) === 1);

// 2.5 Error: Missing user identifier
$res = httpRequest("GET", "$baseUrl/notifications/list_reminders.php");
assertTest("2.5 Reject missing user_id (400)", $res["code"] === 400);

// 2.6 Error: Method not allowed (DELETE)
$res = httpRequest("DELETE", "$baseUrl/notifications/list_reminders.php");
assertTest("2.6 Reject DELETE method on list (405)", $res["code"] === 405);


// ---------------------------------------------------------
// 3. UPDATE REMINDER TESTS
// ---------------------------------------------------------
echo "\n--- 3. Testing Update Reminder (POST /notifications/update_reminder.php) ---\n";

// 3.1 Update title and description
$res = httpRequest("POST", "$baseUrl/notifications/update_reminder.php", [
    "Authorization" => "Bearer $tokenUser7"
], [
    "reminder_id" => $createdReminderId1,
    "title" => "Updated: Master 3NF & Boyce-Codd Normal Forms",
    "description" => "Revised focus: lossless-join decomposition and dependency preservation."
]);
assertTest("3.1 Update title and description", $res["code"] === 200 && ($res["body"]["success"] ?? false) === true);
assertTest("3.1 Updated title reflected", ($res["body"]["reminder"]["title"] ?? "") === "Updated: Master 3NF & Boyce-Codd Normal Forms");

// 3.2 Update status to 'completed' (triggers completion notification)
$res = httpRequest("POST", "$baseUrl/notifications/update_reminder.php", [
    "Authorization" => "Bearer $tokenUser7"
], [
    "reminder_id" => $createdReminderId1,
    "status" => "completed"
]);
assertTest("3.2 Update status to completed", $res["code"] === 200 && ($res["body"]["reminder"]["status"] ?? "") === "completed");

// 3.3 Reject empty title
$res = httpRequest("POST", "$baseUrl/notifications/update_reminder.php", [
    "Authorization" => "Bearer $tokenUser7"
], [
    "reminder_id" => $createdReminderId1,
    "title" => ""
]);
assertTest("3.3 Reject empty title update (400)", $res["code"] === 400);

// 3.4 Reject invalid status
$res = httpRequest("POST", "$baseUrl/notifications/update_reminder.php", [
    "Authorization" => "Bearer $tokenUser7"
], [
    "reminder_id" => $createdReminderId1,
    "status" => "invalid_status"
]);
assertTest("3.4 Reject invalid status (400)", $res["code"] === 400);

// 3.5 Reject non-existent reminder
$res = httpRequest("POST", "$baseUrl/notifications/update_reminder.php", [
    "Authorization" => "Bearer $tokenUser7"
], [
    "reminder_id" => 999999,
    "title" => "Non Existent"
]);
assertTest("3.5 Reject non-existent reminder (404)", $res["code"] === 404);

// 3.6 Cross-user update isolation: User 6 attempting to modify User 7's reminder
$res = httpRequest("POST", "$baseUrl/notifications/update_reminder.php", [
    "Authorization" => "Bearer $tokenUser6"
], [
    "reminder_id" => $createdReminderId1,
    "title" => "Malicious Cross-User Hijack Attempt"
]);
assertTest("3.6 Cross-user update blocked with 403 Forbidden", $res["code"] === 403);


// ---------------------------------------------------------
// 4. LIST NOTIFICATIONS TESTS
// ---------------------------------------------------------
echo "\n--- 4. Testing List Notifications (GET /notifications/list_notifications.php) ---\n";

// 4.1 Fetch notifications for User 7
$res = httpRequest("GET", "$baseUrl/notifications/list_notifications.php?user_id=$userId7");
assertTest("4.1 List notifications returns 200", $res["code"] === 200 && ($res["body"]["success"] ?? false) === true);
assertTest("4.1 Returns unread_count and total_count", isset($res["body"]["unread_count"]) && isset($res["body"]["total_count"]));
assertTest("4.1 Has notification list", is_array($res["body"]["notifications"]) && count($res["body"]["notifications"]) >= 1);

// Verify relative time and Android UI fields
$firstNotif = $res["body"]["notifications"][0] ?? [];
assertTest("4.1 Notification has relative_time", !empty($firstNotif["relative_time"]));
assertTest("4.1 Notification has timeText (for Android UI)", !empty($firstNotif["timeText"]));

// 4.2 Filter by is_read=0 (unread only)
$res = httpRequest("GET", "$baseUrl/notifications/list_notifications.php?user_id=$userId7&is_read=0");
assertTest("4.2 Filter by unread returns 200", $res["code"] === 200);
$allUnread = true;
foreach ($res["body"]["notifications"] ?? [] as $n) {
    if ((int)$n["is_read"] !== 0) $allUnread = false;
}
assertTest("4.2 All filtered notifications are unread", $allUnread);

// 4.3 Error on missing user
$res = httpRequest("GET", "$baseUrl/notifications/list_notifications.php");
assertTest("4.3 Reject missing user (400)", $res["code"] === 400);


// ---------------------------------------------------------
// 5. MARK NOTIFICATION TESTS
// ---------------------------------------------------------
echo "\n--- 5. Testing Mark Notification (POST /notifications/mark_notification.php) ---\n";

// 5.1 Mark single notification as read
$targetNotifId = $createdNotificationId1;
$res = httpRequest("POST", "$baseUrl/notifications/mark_notification.php", [
    "Authorization" => "Bearer $tokenUser7"
], [
    "notification_id" => $targetNotifId,
    "is_read" => 1
]);
assertTest("5.1 Mark single notification as read returns 200", $res["code"] === 200 && ($res["body"]["success"] ?? false) === true);
assertTest("5.1 Returns is_read = 1", ($res["body"]["is_read"] ?? null) === 1);

// 5.2 Mark single notification as unread
$res = httpRequest("POST", "$baseUrl/notifications/mark_notification.php", [
    "Authorization" => "Bearer $tokenUser7"
], [
    "notification_id" => $targetNotifId,
    "is_read" => 0
]);
assertTest("5.2 Mark single notification as unread returns 200", $res["code"] === 200 && ($res["body"]["is_read"] ?? null) === 0);

// 5.3 Cross-user mark isolation: User 6 attempting to mark User 7's notification
$res = httpRequest("POST", "$baseUrl/notifications/mark_notification.php", [
    "Authorization" => "Bearer $tokenUser6"
], [
    "notification_id" => $targetNotifId,
    "is_read" => 1
]);
assertTest("5.3 Cross-user mark blocked with 403 Forbidden", $res["code"] === 403);

// 5.4 Reject non-existent notification
$res = httpRequest("POST", "$baseUrl/notifications/mark_notification.php", [
    "Authorization" => "Bearer $tokenUser7"
], [
    "notification_id" => 999999,
    "is_read" => 1
]);
assertTest("5.4 Non-existent notification rejected with 404", $res["code"] === 404);

// 5.5 Bulk Mark All as Read (Matches Android "Mark All as Read" button)
$res = httpRequest("POST", "$baseUrl/notifications/mark_notification.php", [
    "Authorization" => "Bearer $tokenUser7"
], [
    "mark_all" => true
]);
assertTest("5.5 Mark All as Read returns 200", $res["code"] === 200 && ($res["body"]["marked_all"] ?? false) === true);
assertTest("5.5 Unread count becomes 0", ($res["body"]["unread_count"] ?? -1) === 0);


// ---------------------------------------------------------
// 6. NOTIFICATION STATUS TESTS
// ---------------------------------------------------------
echo "\n--- 6. Testing Notification Status (GET /notifications/notification_status.php) ---\n";

// 6.1 Get Notification Status
$res = httpRequest("GET", "$baseUrl/notifications/notification_status.php?user_id=$userId7");
assertTest("6.1 Notification status returns 200", $res["code"] === 200 && ($res["body"]["success"] ?? false) === true);
assertTest("6.1 Returns badge_count", isset($res["body"]["badge_count"]));
assertTest("6.1 Returns pending reminders count", isset($res["body"]["reminders"]["pending_count"]));
assertTest("6.1 Returns transparent push_service inspection", isset($res["body"]["push_service"]["fcm_configured"]));
assertTest("6.1 Push service reports requirements truthfully", !empty($res["body"]["push_service"]["requirements"]["server_credentials"]));


// ---------------------------------------------------------
// 7. DELETE REMINDER TESTS
// ---------------------------------------------------------
echo "\n--- 7. Testing Delete Reminder (DELETE /notifications/delete_reminder.php) ---\n";

// 7.1 Cross-user delete attempt: User 6 attempting to delete User 7's reminder
$res = httpRequest("DELETE", "$baseUrl/notifications/delete_reminder.php?reminder_id=$createdReminderId2", [
    "Authorization" => "Bearer $tokenUser6"
]);
assertTest("7.1 Cross-user delete blocked with 403 Forbidden", $res["code"] === 403);

// 7.2 Delete reminder by User 7
$res = httpRequest("DELETE", "$baseUrl/notifications/delete_reminder.php?reminder_id=$createdReminderId2", [
    "Authorization" => "Bearer $tokenUser7"
]);
assertTest("7.2 Delete reminder returns 200", $res["code"] === 200 && ($res["body"]["success"] ?? false) === true);
assertTest("7.2 Deleted reminder ID returned", ($res["body"]["deleted_reminder_id"] ?? null) === $createdReminderId2);

// 7.3 Attempt to delete already deleted reminder
$res = httpRequest("DELETE", "$baseUrl/notifications/delete_reminder.php?reminder_id=$createdReminderId2", [
    "Authorization" => "Bearer $tokenUser7"
]);
assertTest("7.3 Deleting non-existent/already deleted reminder returns 404", $res["code"] === 404);

// 7.4 Also delete reminder 1 to clean up and verify associated notification survives
$res = httpRequest("POST", "$baseUrl/notifications/delete_reminder.php", [
    "Authorization" => "Bearer $tokenUser7"
], [
    "reminder_id" => $createdReminderId1
]);
assertTest("7.4 POST method on delete_reminder works (200)", $res["code"] === 200);

// 7.5 Verify notification survived with reminder_id = null (foreign key SET NULL)
require_once __DIR__ . '/../config/config.php';
$stmt = $conn->prepare("SELECT reminder_id FROM notifications WHERE id = ?");
$stmt->execute([$createdNotificationId1]);
$notifRow = $stmt->fetch(PDO::FETCH_ASSOC);
assertTest("7.5 Notification preserved with reminder_id set to NULL", $notifRow && $notifRow["reminder_id"] === null);


// ---------------------------------------------------------
// 8. USER ISOLATION & PRIVACY TESTS
// ---------------------------------------------------------
echo "\n--- 8. Testing User Isolation & Privacy ---\n";

// User 6 should not see any of User 7's reminders
$res = httpRequest("GET", "$baseUrl/notifications/list_reminders.php?user_id=$userId6");
assertTest("8.1 User 6 lists own reminders without seeing User 7", $res["code"] === 200);
$leakFound = false;
foreach ($res["body"]["reminders"] ?? [] as $r) {
    if ((int)$r["user_id"] !== $userId6) $leakFound = true;
}
assertTest("8.1 No reminder data leakage across users", !$leakFound);

// User 6 should not see User 7's notifications
$res = httpRequest("GET", "$baseUrl/notifications/list_notifications.php?user_id=$userId6");
assertTest("8.2 User 6 lists own notifications without seeing User 7", $res["code"] === 200);
$notifLeak = false;
foreach ($res["body"]["notifications"] ?? [] as $n) {
    if ((int)$n["user_id"] !== $userId6) $notifLeak = true;
}
assertTest("8.2 No notification data leakage across users", !$notifLeak);

echo "\n====================================================\n";
echo "TEST RESULTS: PASS: $passCount | FAIL: $failCount\n";
echo "====================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);

