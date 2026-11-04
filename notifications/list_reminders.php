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

if ($authUser && $userId && (int)$authUser["id"] !== $userId) {
    http_response_code(403);
    echo json_encode([
        "success" => false,
        "message" => "Access denied. You do not have permission to view another user's reminders."
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

$statusFilter = strtolower(trim($input["status"] ?? "all"));
$dateFilter = trim($input["date"] ?? "");
$noteIdFilter = filter_var($input["note_id"] ?? $input["noteId"] ?? null, FILTER_VALIDATE_INT) ?: null;
$limit = min(200, max(1, filter_var($input["limit"] ?? 50, FILTER_VALIDATE_INT) ?: 50));
$offset = max(0, filter_var($input["offset"] ?? 0, FILTER_VALIDATE_INT) ?: 0);

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

    // 2. Build Query
    $whereClauses = ["sr.user_id = ?"];
    $params = [$userId];

    if ($statusFilter !== "" && $statusFilter !== "all") {
        if (in_array($statusFilter, ["pending", "completed", "cancelled"])) {
            $whereClauses[] = "sr.status = ?";
            $params[] = $statusFilter;
        }
    }

    if ($dateFilter !== "") {
        if ($dateFilter === "today") {
            $whereClauses[] = "sr.reminder_date = CURDATE()";
        } elseif ($dateFilter === "upcoming") {
            $whereClauses[] = "(sr.reminder_date > CURDATE() OR (sr.reminder_date = CURDATE() AND sr.reminder_time >= CURTIME()))";
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter)) {
            $whereClauses[] = "sr.reminder_date = ?";
            $params[] = $dateFilter;
        }
    }

    if ($noteIdFilter !== null) {
        $whereClauses[] = "sr.note_id = ?";
        $params[] = $noteIdFilter;
    }

    $whereSql = implode(" AND ", $whereClauses);

    $sql = "
        SELECT 
            sr.id,
            sr.user_id,
            sr.note_id,
            sr.title,
            sr.description,
            sr.reminder_date,
            sr.reminder_time,
            sr.status,
            sr.repeat_frequency,
            sr.created_at,
            sr.updated_at,
            n.title AS note_title,
            n.subject AS note_subject,
            n.course_code AS note_course_code
        FROM study_reminders sr
        LEFT JOIN notes n ON sr.note_id = n.id
        WHERE {$whereSql}
        ORDER BY sr.reminder_date ASC, sr.reminder_time ASC
        LIMIT ? OFFSET ?
    ";

    $params[] = $limit;
    $params[] = $offset;

    $stmt = $conn->prepare($sql);
    // Explicit binding for LIMIT and OFFSET to ensure numeric types in PDO
    $paramIndex = 1;
    for ($i = 0; $i < count($params) - 2; $i++) {
        $stmt->bindValue($paramIndex++, $params[$i]);
    }
    $stmt->bindValue($paramIndex++, $limit, PDO::PARAM_INT);
    $stmt->bindValue($paramIndex++, $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $nowTs = time();
    $reminders = [];
    foreach ($rows as $row) {
        $reminderDateTime = $row["reminder_date"] . " " . $row["reminder_time"];
        $remTs = strtotime($reminderDateTime);
        $isOverdue = ($row["status"] === "pending" && $remTs !== false && $remTs < $nowTs);

        $formattedDate = date("d M Y", strtotime($row["reminder_date"]));
        $formattedTime = date("h:i A", strtotime($row["reminder_time"]));
        $formattedSchedule = "{$formattedDate} · {$formattedTime}";

        $reminders[] = [
            "id" => (int)$row["id"],
            "user_id" => (int)$row["user_id"],
            "note_id" => $row["note_id"] !== null ? (int)$row["note_id"] : null,
            "title" => $row["title"],
            "description" => $row["description"] ?? "",
            "reminder_date" => $row["reminder_date"],
            "reminder_time" => $row["reminder_time"],
            "formatted_date" => $formattedDate,
            "formatted_time" => $formattedTime,
            "formatted_schedule" => $formattedSchedule,
            "status" => $row["status"],
            "repeat_frequency" => $row["repeat_frequency"],
            "is_overdue" => $isOverdue,
            "note" => $row["note_id"] ? [
                "id" => (int)$row["note_id"],
                "title" => $row["note_title"],
                "subject" => $row["note_subject"],
                "course_code" => $row["note_course_code"]
            ] : null,
            "created_at" => $row["created_at"],
            "updated_at" => $row["updated_at"]
        ];
    }

    $summary = getReminderSummary($conn, $userId);

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "user_id" => $userId,
        "count" => count($reminders),
        "limit" => $limit,
        "offset" => $offset,
        "summary" => $summary,
        "reminders" => $reminders
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while fetching reminders: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

