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

$authUser = getAuthenticatedUser($conn, false);

// Determine user ID
$userId = null;
if ($authUser) {
    $userId = (int)$authUser["id"];
} else {
    $userId = filter_var($_POST["userId"] ?? $_POST["user_id"] ?? $_GET["userId"] ?? $_GET["user_id"] ?? null, FILTER_VALIDATE_INT);
}

// Check JSON body if multipart is empty
$jsonData = null;
if (!$userId || (!isset($_FILES["audio"]) && !isset($_FILES["file"]) && !isset($_FILES["audio_file"]))) {
    $rawInput = file_get_contents("php://input");
    if (!empty($rawInput)) {
        $jsonData = json_decode($rawInput, true);
        if (is_array($jsonData)) {
            if (!$userId) {
                $userId = filter_var($jsonData["userId"] ?? $jsonData["user_id"] ?? null, FILTER_VALIDATE_INT);
            }
        }
    }
}

if (!$userId || $userId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid user_id or Authorization Bearer token is required."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Verify user exists in database
$checkUser = $conn->prepare("SELECT id, name FROM users WHERE id = ?");
$checkUser->execute([$userId]);
$user = $checkUser->fetch();

if (!$user) {
    http_response_code(404);
    echo json_encode([
        "success" => false,
        "message" => "User account not found for ID: " . $userId
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Ensure target uploads folder exists
$uploadDir = __DIR__ . "/../uploads/audio/";
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$allowedExtensions = ["mp3", "wav", "m4a", "aac", "ogg", "oga", "flac", "opus", "3gp", "3gpp", "wma"];
$allowedMimePrefixes = ["audio/", "application/ogg", "application/x-flac", "video/3gpp"];
$maxSizeBytes = 100 * 1024 * 1024; // 100 MB max size

$savedRelativePath = null;
$originalFileName = "";
$storedFileName = "";
$fileSize = 0;
$fileType = "";
$fileExtension = "";
$duration = trim($_POST["duration"] ?? ($jsonData["duration"] ?? ""));
$title = trim($_POST["title"] ?? ($jsonData["title"] ?? ""));

// Helper to format file size
function formatBytes($bytes) {
    if ($bytes <= 0) return "0 B";
    $units = ["B", "KB", "MB", "GB"];
    $power = min((int)floor(log($bytes, 1024)), 3);
    return round($bytes / pow(1024, $power), 1) . " " . $units[$power];
}

// Case 1: Standard multipart/form-data upload
$fileItem = $_FILES["audio"] ?? $_FILES["file"] ?? $_FILES["audio_file"] ?? null;

if ($fileItem && isset($fileItem["tmp_name"]) && is_uploaded_file($fileItem["tmp_name"])) {
    if ($fileItem["error"] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE => "File exceeds upload_max_filesize directive in php.ini",
            UPLOAD_ERR_FORM_SIZE => "File exceeds MAX_FILE_SIZE directive in HTML form",
            UPLOAD_ERR_PARTIAL => "File was only partially uploaded",
            UPLOAD_ERR_NO_FILE => "No file was uploaded",
            UPLOAD_ERR_NO_TMP_DIR => "Missing temporary upload folder",
            UPLOAD_ERR_CANT_WRITE => "Failed to write file to disk",
            UPLOAD_ERR_EXTENSION => "A PHP extension stopped the file upload"
        ];
        $msg = $uploadErrors[$fileItem["error"]] ?? "Unknown upload error (" . $fileItem["error"] . ")";
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Upload failed: " . $msg
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $originalFileName = basename($fileItem["name"]);
    $fileSize = (int)$fileItem["size"];

    if ($fileSize <= 0) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Uploaded file is empty."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($fileSize > $maxSizeBytes) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Audio file exceeds 100 MB limit."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $fileExtension = strtolower(pathinfo($originalFileName, PATHINFO_EXTENSION));
    $detectedMime = mime_content_type($fileItem["tmp_name"]) ?: ($fileItem["type"] ?? "application/octet-stream");

    // Validate extension
    if (!in_array($fileExtension, $allowedExtensions)) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Invalid audio format (." . $fileExtension . "). Allowed formats: MP3, WAV, M4A, AAC, FLAC, OGG, OPUS, 3GP."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Validate MIME type
    $isMimeValid = false;
    foreach ($allowedMimePrefixes as $prefix) {
        if (strpos($detectedMime, $prefix) === 0 || $detectedMime === "application/octet-stream") {
            $isMimeValid = true;
            break;
        }
    }

    if (!$isMimeValid) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Invalid file MIME type: " . $detectedMime . ". Please upload an audio file."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $fileType = $detectedMime;
    $storedFileName = "audio_" . $userId . "_" . time() . "_" . bin2hex(random_bytes(4)) . "." . $fileExtension;
    $targetPath = $uploadDir . $storedFileName;

    if (!move_uploaded_file($fileItem["tmp_name"], $targetPath)) {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Failed to save uploaded audio file to server storage."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $savedRelativePath = "uploads/audio/" . $storedFileName;

} elseif ($jsonData && (!empty($jsonData["audio_base64"]) || !empty($jsonData["file_base64"]))) {
    // Case 2: Base64 JSON payload
    $base64String = $jsonData["audio_base64"] ?? $jsonData["file_base64"];
    $originalFileName = trim($jsonData["fileName"] ?? $jsonData["file_name"] ?? "lecture_recording.mp3");

    // Extract MIME from Data URI if present
    if (preg_match('/^data:([^;]+);base64,/', $base64String, $matches)) {
        $fileType = strtolower($matches[1]);
        $base64String = substr($base64String, strpos($base64String, ',') + 1);
    }

    $decodedData = base64_decode($base64String, true);
    if ($decodedData === false) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Invalid base64 encoded audio data."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $fileSize = strlen($decodedData);
    if ($fileSize <= 0) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Decoded audio file is empty."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($fileSize > $maxSizeBytes) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Audio file exceeds 100 MB limit."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $fileExtension = strtolower(pathinfo($originalFileName, PATHINFO_EXTENSION));
    if (empty($fileExtension) || !in_array($fileExtension, $allowedExtensions)) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Invalid audio format (." . ($fileExtension ?: "unknown") . "). Allowed formats: MP3, WAV, M4A, AAC, FLAC, OGG, OPUS, 3GP."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($fileType)) {
        switch ($fileExtension) {
            case "mp3": $fileType = "audio/mpeg"; break;
            case "wav": $fileType = "audio/wav"; break;
            case "m4a": $fileType = "audio/mp4"; break;
            case "aac": $fileType = "audio/aac"; break;
            case "ogg": $fileType = "audio/ogg"; break;
            case "flac": $fileType = "audio/flac"; break;
            default: $fileType = "audio/" . $fileExtension; break;
        }
    }

    $storedFileName = "audio_" . $userId . "_" . time() . "_" . bin2hex(random_bytes(4)) . "." . $fileExtension;
    $targetPath = $uploadDir . $storedFileName;

    if (file_put_contents($targetPath, $decodedData) === false) {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Failed to write audio file to server storage."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $savedRelativePath = "uploads/audio/" . $storedFileName;

} else {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "No audio file received. Please provide a multipart file (key: 'audio') or base64 JSON payload."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Set default title if not provided
if ($title === "") {
    $title = pathinfo($originalFileName, PATHINFO_FILENAME);
}

try {
    // Save audio file details in MySQL database
    $insertStmt = $conn->prepare(
        "INSERT INTO audio_files (
            user_id, title, file_name, stored_name, file_path, file_size, file_type, file_extension, duration
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $insertStmt->execute([
        $userId,
        $title,
        $originalFileName,
        $storedFileName,
        $savedRelativePath,
        $fileSize,
        $fileType,
        $fileExtension,
        $duration ?: null
    ]);

    $audioId = (int)$conn->lastInsertId();

    http_response_code(201);
    echo json_encode([
        "success" => true,
        "message" => "Audio file uploaded and saved successfully",
        "audio_id" => $audioId,
        "audio" => [
            "id" => (string)$audioId,
            "user_id" => $userId,
            "title" => $title,
            "fileName" => $originalFileName,
            "storedName" => $storedFileName,
            "filePath" => $savedRelativePath,
            "fileUrl" => $savedRelativePath,
            "fileSize" => $fileSize,
            "fileSizeFormatted" => formatBytes($fileSize),
            "fileType" => $fileType,
            "fileExtension" => $fileExtension,
            "duration" => $duration ?: "Ready",
            "createdAt" => date("Y-m-d H:i:s")
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    // If DB fails, remove uploaded physical file to prevent orphaned storage
    if (file_exists($uploadDir . $storedFileName)) {
        @unlink($uploadDir . $storedFileName);
    }

    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while saving audio record: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

