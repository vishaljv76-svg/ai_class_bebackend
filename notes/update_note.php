<?php
header("Content-Type: application/json");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../common/auth_middleware.php";

$method = $_SERVER["REQUEST_METHOD"];
if ($method !== "POST" && $method !== "PUT") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "POST or PUT method required"
    ]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON payload"
    ]);
    exit;
}

$authUser = getAuthenticatedUser($conn, false);
$noteId = filter_var($data["id"] ?? $data["note_id"] ?? null, FILTER_VALIDATE_INT);

if (!$noteId || $noteId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid note id is required"
    ]);
    exit;
}

try {
    // Check note exists
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

    // Enforce ownership if user authenticated
    if ($authUser && (int)$note["user_id"] !== (int)$authUser["id"]) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "You are not authorized to edit this note"
        ]);
        exit;
    }

    $updates = [];
    $params = [];

    if (isset($data["title"])) {
        $updates[] = "title = ?";
        $params[] = trim($data["title"]);
    }
    if (isset($data["summary"])) {
        $updates[] = "summary = ?";
        $params[] = trim($data["summary"]);
    }
    if (isset($data["isBookmarked"]) || isset($data["is_bookmarked"])) {
        $val = $data["isBookmarked"] ?? $data["is_bookmarked"];
        $updates[] = "is_bookmarked = ?";
        $params[] = ($val === true || $val === 1 || $val === "1") ? 1 : 0;
    }
    if (isset($data["subject"])) {
        $updates[] = "subject = ?";
        $params[] = trim($data["subject"]);
    }

    if (empty($updates)) {
        echo json_encode([
            "success" => true,
            "message" => "No fields provided to update"
        ]);
        exit;
    }

    $params[] = $noteId;
    $sql = "UPDATE notes SET " . implode(", ", $updates) . " WHERE id = ?";
    $upStmt = $conn->prepare($sql);
    $upStmt->execute($params);

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Note updated successfully"
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Unable to update note: " . $e->getMessage()
    ]);
}

