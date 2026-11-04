<?php
header("Content-Type: application/json");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../common/auth_middleware.php";

$method = $_SERVER["REQUEST_METHOD"];
if ($method !== "POST" && $method !== "DELETE") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "POST or DELETE method required"
    ]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$authUser = getAuthenticatedUser($conn, false);

$noteId = filter_var($_GET["id"] ?? $_GET["note_id"] ?? $data["id"] ?? $data["note_id"] ?? null, FILTER_VALIDATE_INT);

if (!$noteId || $noteId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid note id is required"
    ]);
    exit;
}

try {
    $stmt = $conn->prepare("SELECT id, user_id FROM notes WHERE id = ?");
    $stmt->execute([$noteId]);
    $note = $stmt->fetch();

    if (!$note) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Note not found"
        ]);
        exit;
    }

    // Enforce ownership if authenticated
    if ($authUser && (int)$note["user_id"] !== (int)$authUser["id"]) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "You are not authorized to delete this note"
        ]);
        exit;
    }

    $del = $conn->prepare("DELETE FROM notes WHERE id = ?");
    $del->execute([$noteId]);

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Note deleted successfully"
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Unable to delete note: " . $e->getMessage()
    ]);
}

