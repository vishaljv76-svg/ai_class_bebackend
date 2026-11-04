<?php
require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../common/auth_middleware.php";

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
$fileId = filter_var($_GET["file_id"] ?? $_GET["id"] ?? null, FILTER_VALIDATE_INT);
$isInline = !empty($_GET["inline"]) || !empty($_GET["view"]);

if (!$fileId || $fileId < 1) {
    header("Content-Type: application/json; charset=UTF-8");
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid file_id is required."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 1. Fetch file record from database
    $stmt = $conn->prepare("SELECT * FROM study_files WHERE id = ?");
    $stmt->execute([$fileId]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file) {
        header("Content-Type: application/json; charset=UTF-8");
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Study file record not found for ID: " . $fileId
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. Prevent unauthorized access (Strict Authorization Check)
    if ($userId && (int)$file["user_id"] !== $userId) {
        header("Content-Type: application/json; charset=UTF-8");
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "Access denied. You do not have permission to access or download this file."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // If neither token nor userId was provided, require authentication
    if (!$userId) {
        header("Content-Type: application/json; charset=UTF-8");
        http_response_code(401);
        echo json_encode([
            "success" => false,
            "message" => "Authentication required to access or download this file."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 3. Verify physical file exists on disk
    $physicalPath = __DIR__ . "/../" . $file["file_path"];

    if (!file_exists($physicalPath) || !is_file($physicalPath)) {
        header("Content-Type: application/json; charset=UTF-8");
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Physical file not found on server storage.",
            "stored_name" => $file["stored_name"]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 4. Send headers and stream file
    $contentType = $file["file_type"] ?: "application/octet-stream";
    $downloadName = $file["file_name"] ?: $file["stored_name"];
    // Sanitize download filename for Content-Disposition header
    $safeName = str_replace(['"', "\r", "\n"], '', basename($downloadName));

    $disposition = $isInline ? "inline" : "attachment";

    if (ob_get_level()) {
        ob_end_clean();
    }

    header("Content-Description: File Transfer");
    header("Content-Type: " . $contentType);
    header('Content-Disposition: ' . $disposition . '; filename="' . $safeName . '"');
    header("Content-Length: " . filesize($physicalPath));
    header("Cache-Control: private, max-age=3600");
    header("Pragma: private");

    readfile($physicalPath);
    exit;

} catch (PDOException $e) {
    header("Content-Type: application/json; charset=UTF-8");
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error during file download: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

