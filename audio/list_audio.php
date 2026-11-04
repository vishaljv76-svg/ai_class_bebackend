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
        "message" => "Access denied. You do not have permission to view another user's audio files."
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

function formatBytes($bytes) {
    if ($bytes <= 0) return "0 B";
    $units = ["B", "KB", "MB", "GB"];
    $power = min((int)floor(log($bytes, 1024)), 3);
    return round($bytes / pow(1024, $power), 1) . " " . $units[$power];
}

$search = trim($_GET["search"] ?? $_GET["query"] ?? "");
$limit = filter_var($_GET["limit"] ?? 50, FILTER_VALIDATE_INT) ?: 50;
if ($limit < 1) $limit = 1;
if ($limit > 100) $limit = 100;

try {
    // Verify user exists in database
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

    $sql = "SELECT id, user_id, title, file_name, stored_name, file_path, file_size, file_type, file_extension, duration, created_at 
            FROM audio_files 
            WHERE user_id = ?";
    $params = [$userId];

    if ($search !== "") {
        $sql .= " AND (title LIKE ? OR file_name LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $sql .= " ORDER BY created_at DESC, id DESC LIMIT ?";

    $stmt = $conn->prepare($sql);
    foreach ($params as $idx => $param) {
        $stmt->bindValue($idx + 1, $param);
    }
    $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    // Summary stats for user's audio library
    $statsStmt = $conn->prepare("SELECT COUNT(*) AS total_count, COALESCE(SUM(file_size), 0) AS total_size FROM audio_files WHERE user_id = ?");
    $statsStmt->execute([$userId]);
    $stats = $statsStmt->fetch();

    $totalFiles = (int)($stats["total_count"] ?? 0);
    $totalSizeBytes = (int)($stats["total_size"] ?? 0);

    $audioList = [];
    $today = date("Y-m-d");
    $yesterday = date("Y-m-d", strtotime("-1 day"));

    foreach ($rows as $row) {
        $createdTime = strtotime($row["created_at"]);
        $dateStr = date("Y-m-d", $createdTime);

        if ($dateStr === $today) {
            $dateLabel = "Today, " . date("h:i A", $createdTime);
        } elseif ($dateStr === $yesterday) {
            $dateLabel = "Yesterday, " . date("h:i A", $createdTime);
        } else {
            $dateLabel = date("d M Y, h:i A", $createdTime);
        }

        $audioList[] = [
            "id" => (string)$row["id"],
            "user_id" => (int)$row["user_id"],
            "title" => $row["title"] ?: $row["file_name"],
            "fileName" => $row["file_name"],
            "storedName" => $row["stored_name"],
            "filePath" => $row["file_path"],
            "fileUrl" => $row["file_path"],
            "fileSize" => (int)$row["file_size"],
            "fileSizeFormatted" => formatBytes((int)$row["file_size"]),
            "fileType" => $row["file_type"],
            "fileExtension" => strtoupper($row["file_extension"]),
            "duration" => $row["duration"] ?: "Audio lecture",
            "dateLabel" => $dateLabel,
            "createdAt" => $row["created_at"]
        ];
    }

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Audio files retrieved successfully",
        "user_id" => $userId,
        "total_files" => $totalFiles,
        "total_size_bytes" => $totalSizeBytes,
        "total_size_formatted" => formatBytes($totalSizeBytes),
        "count" => count($audioList),
        "audio_files" => $audioList
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while fetching audio files: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

