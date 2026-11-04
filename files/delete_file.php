<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../common/auth_middleware.php";
require_once __DIR__ . "/files_helper.php";

$method = $_SERVER["REQUEST_METHOD"];
if ($method !== "DELETE" && $method !== "POST") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed. DELETE or POST method required."
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

$input = $_GET;
$rawInput = file_get_contents("php://input");
if ($rawInput) {
    $jsonData = json_decode($rawInput, true);
    if (is_array($jsonData)) {
        $input = array_merge($input, $jsonData);
    }
}
if (!empty($_POST)) {
    $input = array_merge($input, $_POST);
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
    // 1. Fetch file record from database
    $stmt = $conn->prepare("SELECT * FROM study_files WHERE id = ?");
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

    // 2. Prevent unauthorized deletion
    if ($userId && (int)$file["user_id"] !== $userId) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "Access denied. You do not have permission to delete this file."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $ownerId = (int)$file["user_id"];

    // 3. Safely delete physical file from disk storage
    $physicalPath = __DIR__ . "/../" . $file["file_path"];
    $physicalDeleted = false;
    if (!empty($file["file_path"]) && file_exists($physicalPath) && is_file($physicalPath)) {
        $physicalDeleted = @unlink($physicalPath);
    }

    // 4. Delete record from MySQL
    $delStmt = $conn->prepare("DELETE FROM study_files WHERE id = ?");
    $delStmt->execute([$fileId]);

    // 5. Fetch updated summary
    $summary = getUserFilesSummary($conn, $ownerId);

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Study file and storage record deleted successfully",
        "deleted_file_id" => $fileId,
        "file_name" => $file["file_name"],
        "physical_file_deleted" => $physicalDeleted,
        "summary" => $summary
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while deleting file: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

