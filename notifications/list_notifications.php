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
        "message" => "Access denied. You do not have permission to view another user's notifications."
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

$typeFilter = trim($input["type"] ?? "");
$readFilter = isset($input["is_read"]) ? trim($input["is_read"]) : null;
$limit = min(100, max(1, filter_var($input["limit"] ?? 30, FILTER_VALIDATE_INT) ?: 30));
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
    $whereClauses = ["n.user_id = ?"];
    $params = [$userId];

    if ($typeFilter !== "") {
        $whereClauses[] = "n.type = ?";
        $params[] = $typeFilter;
    }

    if ($readFilter !== null && $readFilter !== "") {
        if ($readFilter === "1" || strtolower($readFilter) === "true" || strtolower($readFilter) === "read") {
            $whereClauses[] = "n.is_read = 1";
        } elseif ($readFilter === "0" || strtolower($readFilter) === "false" || strtolower($readFilter) === "unread") {
            $whereClauses[] = "n.is_read = 0";
        }
    }

    $whereSql = implode(" AND ", $whereClauses);

    $sql = "
        SELECT 
            n.id,
            n.user_id,
            n.reminder_id,
            n.title,
            n.message,
            n.type,
            n.is_read,
            n.created_at,
            sr.title AS reminder_title,
            sr.status AS reminder_status,
            sr.reminder_date,
            sr.reminder_time
        FROM notifications n
        LEFT JOIN study_reminders sr ON n.reminder_id = sr.id
        WHERE {$whereSql}
        ORDER BY n.created_at DESC
        LIMIT ? OFFSET ?
    ";

    $stmt = $conn->prepare($sql);
    $paramIndex = 1;
    for ($i = 0; $i < count($params); $i++) {
        $stmt->bindValue($paramIndex++, $params[$i]);
    }
    $stmt->bindValue($paramIndex++, $limit, PDO::PARAM_INT);
    $stmt->bindValue($paramIndex++, $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $notifications = [];
    foreach ($rows as $row) {
        $relTime = formatRelativeTime($row["created_at"]);

        $notifications[] = [
            "id" => (int)$row["id"],
            "user_id" => (int)$row["user_id"],
            "reminder_id" => $row["reminder_id"] !== null ? (int)$row["reminder_id"] : null,
            "title" => $row["title"],
            "message" => $row["message"],
            "type" => $row["type"],
            "is_read" => (int)$row["is_read"],
            "is_read_bool" => ((int)$row["is_read"] === 1),
            "relative_time" => $relTime,
            "timeText" => $relTime, // Matches Android Notifications dialog
            "time" => $relTime,
            "created_at" => $row["created_at"],
            "reminder_details" => $row["reminder_id"] ? [
                "id" => (int)$row["reminder_id"],
                "title" => $row["reminder_title"],
                "status" => $row["reminder_status"],
                "schedule" => $row["reminder_date"] . " " . $row["reminder_time"]
            ] : null
        ];
    }

    $summary = getNotificationSummary($conn, $userId);

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "user_id" => $userId,
        "count" => count($notifications),
        "limit" => $limit,
        "offset" => $offset,
        "unread_count" => $summary["unread"],
        "read_count" => $summary["read"],
        "total_count" => $summary["total"],
        "notifications" => $notifications
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while fetching notifications: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

