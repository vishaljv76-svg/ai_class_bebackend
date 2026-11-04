<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../common/auth_middleware.php";
require_once __DIR__ . "/files_helper.php";

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

// Collect input params
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
$userId = $authUser ? (int)$authUser["id"] : filter_var($input["user_id"] ?? $input["userId"] ?? null, FILTER_VALIDATE_INT);
$fileId = filter_var($input["file_id"] ?? $input["id"] ?? null, FILTER_VALIDATE_INT);

if (!$fileId || $fileId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid file_id is required."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 1. Fetch file record
    $stmt = $conn->prepare("
        SELECT 
            sf.*,
            n.title AS note_title,
            n.subject AS note_subject,
            n.course_code AS note_course_code
        FROM study_files sf
        LEFT JOIN notes n ON sf.note_id = n.id
        WHERE sf.id = ?
    ");
    $stmt->execute([$fileId]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Study file not found for ID: " . $fileId
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. Prevent unauthorized access
    if ($userId && (int)$file["user_id"] !== $userId) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "Access denied. You do not have permission to access this file."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $fileSize = (int)$file["file_size"];
    $physicalPath = __DIR__ . "/../" . $file["file_path"];
    $physicalExists = file_exists($physicalPath) && is_file($physicalPath);

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "File details retrieved successfully",
        "file" => [
            "id" => (int)$file["id"],
            "user_id" => (int)$file["user_id"],
            "note_id" => $file["note_id"] !== null ? (int)$file["note_id"] : null,
            "title" => $file["title"] ?: $file["file_name"],
            "file_name" => $file["file_name"],
            "stored_name" => $file["stored_name"],
            "file_size" => $fileSize,
            "file_size_formatted" => formatFileBytes($fileSize),
            "file_type" => $file["file_type"],
            "file_extension" => strtoupper($file["file_extension"]),
            "category" => $file["category"],
            "physical_file_exists" => $physicalExists,
            "download_url" => "files/download_file.php?file_id=" . $file["id"],
            "view_url" => "files/download_file.php?file_id=" . $file["id"] . "&view=1",
            "note" => $file["note_id"] ? [
                "id" => (int)$file["note_id"],
                "title" => $file["note_title"],
                "subject" => $file["note_subject"],
                "course_code" => $file["note_course_code"]
            ] : null,
            "created_at" => $file["created_at"],
            "updated_at" => $file["updated_at"],
            "formatted_date" => date("d M Y", strtotime($file["created_at"]))
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while fetching file: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

