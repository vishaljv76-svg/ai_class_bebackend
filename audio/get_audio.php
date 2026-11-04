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
$userId = $authUser ? (int)$authUser["id"] : filter_var($_GET["user_id"] ?? $_GET["userId"] ?? null, FILTER_VALIDATE_INT);
$audioId = filter_var($_GET["id"] ?? $_GET["audio_id"] ?? null, FILTER_VALIDATE_INT);

if (!$audioId || $audioId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid audio id is required."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function formatBytes($bytes) {
    if ($bytes <= 0) return "0 B";
    $units = ["B", "KB", "MB", "GB"];
    $power = min((int)floor(log($bytes, 1024)), 3);
    return round($bytes / pow(1024, $power), 1) . " " . $units[$power];
}

try {
    $stmt = $conn->prepare("SELECT * FROM audio_files WHERE id = ?");
    $stmt->execute([$audioId]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Audio record not found for ID: " . $audioId
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Enforce ownership: users can access only their own audio files
    if ($userId && (int)$row["user_id"] !== $userId) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "Access denied. You do not have permission to view this audio file."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $physicalExists = file_exists(__DIR__ . "/../" . $row["file_path"]);

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Audio details retrieved successfully",
        "audio" => [
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
            "physicalFileExists" => $physicalExists,
            "createdAt" => $row["created_at"],
            "updatedAt" => $row["updated_at"]
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while fetching audio: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

