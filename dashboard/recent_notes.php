<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../common/auth_middleware.php";

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
        "message" => "Access denied. You do not have permission to view these notes."
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

$limit = filter_var($_GET["limit"] ?? 5, FILTER_VALIDATE_INT) ?: 5;
if ($limit < 1) $limit = 1;
if ($limit > 50) $limit = 50;

try {
    // Verify user exists
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

    $recStmt = $conn->prepare(
        "SELECT id, title, subject, course_code, tag, professor, duration, read_time, icon_type, summary, is_bookmarked, created_at 
         FROM notes 
         WHERE user_id = ? 
         ORDER BY created_at DESC, id DESC 
         LIMIT ?"
    );
    $recStmt->bindValue(1, $userId, PDO::PARAM_INT);
    $recStmt->bindValue(2, $limit, PDO::PARAM_INT);
    $recStmt->execute();
    $recNotes = $recStmt->fetchAll();

    $today = date("Y-m-d");
    $yesterday = date("Y-m-d", strtotime("-1 day"));
    $recentNotes = [];

    foreach ($recNotes as $r) {
        $createdTime = strtotime($r["created_at"]);
        $noteDate = date("Y-m-d", $createdTime);

        if ($noteDate === $today) {
            $dateLabel = "Today";
        } elseif ($noteDate === $yesterday) {
            $dateLabel = "Yesterday";
        } else {
            $dateLabel = date("d M", $createdTime);
        }

        $iconType = (stripos($r["subject"], "Math") !== false || strcasecmp($r["icon_type"] ?? "", "Math") === 0) 
            ? "Math" 
            : "Database";

        $recentNotes[] = [
            "id" => (string)$r["id"],
            "title" => $r["title"],
            "subject" => $r["subject"],
            "course_code" => $r["course_code"] ?? "",
            "tag" => $r["tag"] ?? "AI notes",
            "date" => $dateLabel,
            "duration" => !empty($r["duration"]) ? $r["duration"] : "40 min",
            "read_time" => !empty($r["read_time"]) ? $r["read_time"] : "5 min read",
            "iconType" => $iconType,
            "summary" => $r["summary"] ?? "",
            "is_bookmarked" => (bool)$r["is_bookmarked"],
            "created_at" => $r["created_at"]
        ];
    }

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Recent notes retrieved successfully",
        "user_id" => $userId,
        "count" => count($recentNotes),
        "recentNotes" => $recentNotes
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while fetching recent notes: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
