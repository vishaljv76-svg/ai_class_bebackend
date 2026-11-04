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
$noteId = filter_var($_GET["id"] ?? $_GET["note_id"] ?? $data["id"] ?? $data["note_id"] ?? null, FILTER_VALIDATE_INT);

if (!$noteId || $noteId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid note id is required."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Check note exists in database
    $stmt = $conn->prepare("SELECT id, user_id, title FROM notes WHERE id = ?");
    $stmt->execute([$noteId]);
    $note = $stmt->fetch();

    if (!$note) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Note not found for ID: " . $noteId
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Enforce ownership: users can delete only their own notes
    $userId = $authUser ? (int)$authUser["id"] : filter_var($_GET["user_id"] ?? $_GET["userId"] ?? $data["user_id"] ?? $data["userId"] ?? null, FILTER_VALIDATE_INT);
    if ($userId && (int)$note["user_id"] !== $userId) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "You are not authorized to delete this note."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Unlink any quizzes referring to this note safely
    $unlinkStmt = $conn->prepare("UPDATE quizzes SET note_id = NULL WHERE note_id = ?");
    $unlinkStmt->execute([$noteId]);

    // Delete note safely
    $del = $conn->prepare("DELETE FROM notes WHERE id = ?");
    $del->execute([$noteId]);

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Note deleted successfully",
        "deleted_id" => $noteId,
        "title" => $note["title"]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while deleting note: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
