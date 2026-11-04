<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../common/auth_middleware.php";

$method = $_SERVER["REQUEST_METHOD"];
if ($method !== "POST" && $method !== "PUT") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed. POST or PUT method required."
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

if (!is_array($data) || empty($data)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Invalid or empty JSON payload."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$authUser = getAuthenticatedUser($conn, false);
$noteId = filter_var($data["id"] ?? $data["note_id"] ?? $_GET["id"] ?? $_GET["note_id"] ?? null, FILTER_VALIDATE_INT);

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
    $stmt = $conn->prepare("SELECT * FROM notes WHERE id = ?");
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

    // Enforce ownership: users can edit only their own notes
    $userId = $authUser ? (int)$authUser["id"] : filter_var($data["user_id"] ?? $data["userId"] ?? $_GET["user_id"] ?? $_GET["userId"] ?? null, FILTER_VALIDATE_INT);
    if ($userId && (int)$note["user_id"] !== $userId) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "You are not authorized to edit this note."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $updates = [];
    $params = [];

    // Title
    if (isset($data["title"]) || isset($data["lectureTitle"])) {
        $updates[] = "title = ?";
        $params[] = trim($data["title"] ?? $data["lectureTitle"]);
    }

    // Subject
    if (isset($data["subject"]) || isset($data["subjectBadge"])) {
        $subVal = trim($data["subject"] ?? $data["subjectBadge"]);
        $updates[] = "subject = ?";
        $params[] = $subVal;

        // Auto-update iconType if not explicitly given
        if (!isset($data["icon_type"]) && !isset($data["iconType"])) {
            $updates[] = "icon_type = ?";
            $params[] = (stripos($subVal, "Math") !== false) ? "Math" : "Database";
        }
    }

    // Course Code
    if (isset($data["course_code"]) || isset($data["courseCode"])) {
        $updates[] = "course_code = ?";
        $params[] = trim($data["course_code"] ?? $data["courseCode"]);
    }

    // Tag
    if (isset($data["tag"]) || isset($data["tagText"])) {
        $updates[] = "tag = ?";
        $params[] = trim($data["tag"] ?? $data["tagText"]);
    }

    // Professor
    if (isset($data["professor"])) {
        $updates[] = "professor = ?";
        $params[] = trim($data["professor"]);
    }

    // Duration
    if (isset($data["duration"])) {
        $updates[] = "duration = ?";
        $params[] = trim($data["duration"]);
    }

    // Read Time
    if (isset($data["read_time"]) || isset($data["readTime"])) {
        $updates[] = "read_time = ?";
        $params[] = trim($data["read_time"] ?? $data["readTime"]);
    }

    // Icon Type
    if (isset($data["icon_type"]) || isset($data["iconType"])) {
        $iconVal = $data["icon_type"] ?? $data["iconType"];
        $updates[] = "icon_type = ?";
        $params[] = (stripos($iconVal, "Math") !== false) ? "Math" : "Database";
    }

    // Summary
    if (isset($data["summary"]) || isset($data["aiSummaryText"])) {
        $updates[] = "summary = ?";
        $params[] = trim($data["summary"] ?? $data["aiSummaryText"]);
    }

    // Full Content
    if (isset($data["full_content"]) || isset($data["fullContent"]) || isset($data["fullText"]) || isset($data["content"])) {
        $updates[] = "full_content = ?";
        $params[] = trim($data["full_content"] ?? $data["fullContent"] ?? $data["fullText"] ?? $data["content"]);
    }

    // Bookmark Toggle
    if (isset($data["isBookmarked"]) || isset($data["is_bookmarked"])) {
        $bm = $data["isBookmarked"] ?? $data["is_bookmarked"];
        $updates[] = "is_bookmarked = ?";
        $params[] = ($bm === true || $bm === 1 || $bm === "1" || $bm === "true") ? 1 : 0;
    }

    // Key Takeaways
    if (isset($data["keyTakeaways"]) || isset($data["key_takeaways"])) {
        $kt = $data["keyTakeaways"] ?? $data["key_takeaways"];
        $updates[] = "key_takeaways = ?";
        $params[] = is_array($kt) ? json_encode($kt, JSON_UNESCAPED_UNICODE) : $kt;
    }

    // Important Terms
    if (isset($data["importantTerms"]) || isset($data["important_terms"])) {
        $it = $data["importantTerms"] ?? $data["important_terms"];
        $updates[] = "important_terms = ?";
        $params[] = is_array($it) ? json_encode($it, JSON_UNESCAPED_UNICODE) : $it;
    }

    // Section 1
    if (isset($data["section1Title"]) || isset($data["section1_title"])) {
        $updates[] = "section1_title = ?";
        $params[] = trim($data["section1Title"] ?? $data["section1_title"]);
    }
    if (isset($data["section1Content"]) || isset($data["section1_content"])) {
        $updates[] = "section1_content = ?";
        $params[] = trim($data["section1Content"] ?? $data["section1_content"]);
    }

    // Section 2
    if (isset($data["section2Title"]) || isset($data["section2_title"])) {
        $updates[] = "section2_title = ?";
        $params[] = trim($data["section2Title"] ?? $data["section2_title"]);
    }
    if (isset($data["section2Bullets"]) || isset($data["section2_bullets"])) {
        $sb = $data["section2Bullets"] ?? $data["section2_bullets"];
        $updates[] = "section2_bullets = ?";
        $params[] = is_array($sb) ? json_encode($sb, JSON_UNESCAPED_UNICODE) : $sb;
    }

    // Callout
    if (isset($data["calloutTitle"]) || isset($data["callout_title"])) {
        $updates[] = "callout_title = ?";
        $params[] = trim($data["calloutTitle"] ?? $data["callout_title"]);
    }
    if (isset($data["calloutContent"]) || isset($data["callout_content"])) {
        $updates[] = "callout_content = ?";
        $params[] = trim($data["calloutContent"] ?? $data["callout_content"]);
    }

    // Section 3
    if (isset($data["section3Title"]) || isset($data["section3_title"])) {
        $updates[] = "section3_title = ?";
        $params[] = trim($data["section3Title"] ?? $data["section3_title"]);
    }
    if (isset($data["section3Content1"]) || isset($data["section3_content1"])) {
        $updates[] = "section3_content1 = ?";
        $params[] = trim($data["section3Content1"] ?? $data["section3_content1"]);
    }
    if (isset($data["section3Content2"]) || isset($data["section3_content2"])) {
        $updates[] = "section3_content2 = ?";
        $params[] = trim($data["section3Content2"] ?? $data["section3_content2"]);
    }

    // Footer Text
    if (isset($data["footerText"]) || isset($data["footer_text"])) {
        $updates[] = "footer_text = ?";
        $params[] = trim($data["footerText"] ?? $data["footer_text"]);
    }

    if (empty($updates)) {
        http_response_code(200);
        echo json_encode([
            "success" => true,
            "message" => "No fields provided to update.",
            "note_id" => $noteId
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Always update timestamp
    $updates[] = "updated_at = NOW()";

    $params[] = $noteId;
    $sql = "UPDATE notes SET " . implode(", ", $updates) . " WHERE id = ?";
    $upStmt = $conn->prepare($sql);
    $upStmt->execute($params);

    // Fetch updated note record
    $fetchUpdated = $conn->prepare("SELECT id, title, subject, summary, is_bookmarked, updated_at FROM notes WHERE id = ?");
    $fetchUpdated->execute([$noteId]);
    $updatedRow = $fetchUpdated->fetch();

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Note updated successfully",
        "note_id" => $noteId,
        "note" => [
            "id" => (string)$updatedRow["id"],
            "title" => $updatedRow["title"],
            "subject" => $updatedRow["subject"],
            "summary" => $updatedRow["summary"],
            "isBookmarked" => (bool)$updatedRow["is_bookmarked"],
            "updated_at" => $updatedRow["updated_at"]
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while updating note: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
