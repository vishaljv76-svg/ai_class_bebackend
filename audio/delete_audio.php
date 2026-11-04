<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../common/auth_middleware.php";

$method = $_SERVER["REQUEST_METHOD"];
if ($method !== "POST" && $method !== "DELETE") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed. POST or DELETE method required."
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

$authUser = getAuthenticatedUser($conn, false);
$audioId = filter_var($_GET["id"] ?? $_GET["audio_id"] ?? $data["id"] ?? $data["audio_id"] ?? null, FILTER_VALIDATE_INT);

if (!$audioId || $audioId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid audio id is required."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Check audio record exists in database
    $stmt = $conn->prepare("SELECT * FROM audio_files WHERE id = ?");
    $stmt->execute([$audioId]);
    $audio = $stmt->fetch();

    if (!$audio) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Audio record not found for ID: " . $audioId
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Enforce ownership: users can delete only their own audio files
    $userId = $authUser ? (int)$authUser["id"] : filter_var($_GET["user_id"] ?? $_GET["userId"] ?? $data["user_id"] ?? $data["userId"] ?? null, FILTER_VALIDATE_INT);
    if ($userId && (int)$audio["user_id"] !== $userId) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "You are not authorized to delete this audio file."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Safely delete physical file from disk
    $physicalPath = __DIR__ . "/../" . $audio["file_path"];
    $fileDeletedFromDisk = false;
    if (!empty($audio["file_path"]) && file_exists($physicalPath) && is_file($physicalPath)) {
        $fileDeletedFromDisk = @unlink($physicalPath);
    }

    // Delete record from MySQL
    $del = $conn->prepare("DELETE FROM audio_files WHERE id = ?");
    $del->execute([$audioId]);

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Audio file and database record deleted successfully",
        "deleted_id" => $audioId,
        "file_name" => $audio["file_name"],
        "physical_file_deleted" => $fileDeletedFromDisk
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while deleting audio file: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

