<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../common/auth_middleware.php";
require_once __DIR__ . "/../services/educational_resources.php";

// Normalize Authorization header
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

if (!$userId || $userId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid user_id or Authorization Bearer token is required."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$noteId = filter_var($_GET["note_id"] ?? $_GET["noteId"] ?? null, FILTER_VALIDATE_INT);
if (!$noteId || $noteId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid 'note_id' is required to fetch educational resources."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Verify note exists and belongs to user
    $nStmt = $conn->prepare("SELECT id, user_id, title FROM notes WHERE id = ?");
    $nStmt->execute([$noteId]);
    $note = $nStmt->fetch();

    if (!$note) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Note record not found for ID: " . $noteId
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ((int)$note["user_id"] !== $userId) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "Forbidden: You are not authorized to view resources for this note."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $resources = getLectureResources($conn, $noteId, $userId);

    echo json_encode([
        "success" => true,
        "note_id" => $noteId,
        "note_title" => $note["title"],
        "count" => count($resources),
        "resources" => $resources
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error fetching resources: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

