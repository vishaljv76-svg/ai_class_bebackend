<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../common/auth_middleware.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST" && $_SERVER["REQUEST_METHOD"] !== "PUT") {
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
$userId = $authUser ? (int)$authUser["id"] : filter_var($data["user_id"] ?? $data["userId"] ?? null, FILTER_VALIDATE_INT);

$noteId = filter_var($data["id"] ?? $data["note_id"] ?? null, FILTER_VALIDATE_INT);

if (!$noteId || $noteId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid note 'id' is required for update."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 1. Fetch existing note
    $stmt = $conn->prepare("SELECT * FROM notes WHERE id = ?");
    $stmt->execute([$noteId]);
    $existing = $stmt->fetch();

    if (!$existing) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Note not found with ID: " . $noteId
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. Ownership check
    if ($userId && (int)$existing["user_id"] !== $userId) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "Forbidden: You do not have permission to modify this note."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 3. Compute updated fields
    $title = isset($data["title"]) ? trim($data["title"]) : (isset($data["lectureTitle"]) ? trim($data["lectureTitle"]) : $existing["title"]);
    $subject = isset($data["subject"]) ? trim($data["subject"]) : $existing["subject"];
    $courseCode = isset($data["courseCode"]) ? trim($data["courseCode"]) : (isset($data["course_code"]) ? trim($data["course_code"]) : $existing["course_code"]);
    $tag = isset($data["tag"]) ? trim($data["tag"]) : (isset($data["tagText"]) ? trim($data["tagText"]) : $existing["tag"]);
    $professor = isset($data["professor"]) ? trim($data["professor"]) : $existing["professor"];
    $duration = isset($data["duration"]) ? trim($data["duration"]) : $existing["duration"];
    $readTime = isset($data["readTime"]) ? trim($data["readTime"]) : (isset($data["read_time"]) ? trim($data["read_time"]) : $existing["read_time"]);
    
    $summary = isset($data["summary"]) ? trim($data["summary"]) : (isset($data["aiSummaryText"]) ? trim($data["aiSummaryText"]) : $existing["summary"]);
    $fullContent = isset($data["fullContent"]) ? trim($data["fullContent"]) : (isset($data["full_content"]) ? trim($data["full_content"]) : $existing["full_content"]);

    // Key Takeaways
    if (isset($data["keyTakeaways"]) || isset($data["key_takeaways"])) {
        $kt = $data["keyTakeaways"] ?? $data["key_takeaways"];
        $keyTakeawaysJson = is_array($kt) ? json_encode($kt, JSON_UNESCAPED_UNICODE) : (string)$kt;
    } else {
        $keyTakeawaysJson = $existing["key_takeaways"];
    }

    // Important terms / keywords
    if (isset($data["importantTerms"]) || isset($data["important_terms"])) {
        $it = $data["importantTerms"] ?? $data["important_terms"];
        $importantTermsJson = is_array($it) ? json_encode($it, JSON_UNESCAPED_UNICODE) : (string)$it;
    } else {
        $importantTermsJson = $existing["important_terms"];
    }

    $section1Title = isset($data["section1Title"]) ? trim($data["section1Title"]) : (isset($data["section1_title"]) ? trim($data["section1_title"]) : $existing["section1_title"]);
    $section1Content = isset($data["section1Content"]) ? trim($data["section1Content"]) : (isset($data["section1_content"]) ? trim($data["section1_content"]) : $existing["section1_content"]);

    $section2Title = isset($data["section2Title"]) ? trim($data["section2Title"]) : (isset($data["section2_title"]) ? trim($data["section2_title"]) : $existing["section2_title"]);
    if (isset($data["section2Bullets"]) || isset($data["section2_bullets"])) {
        $sb = $data["section2Bullets"] ?? $data["section2_bullets"];
        $section2BulletsJson = is_array($sb) ? json_encode($sb, JSON_UNESCAPED_UNICODE) : (string)$sb;
    } else {
        $section2BulletsJson = $existing["section2_bullets"];
    }

    $calloutTitle = isset($data["calloutTitle"]) ? trim($data["calloutTitle"]) : (isset($data["callout_title"]) ? trim($data["callout_title"]) : $existing["callout_title"]);
    $calloutContent = isset($data["calloutContent"]) ? trim($data["calloutContent"]) : (isset($data["callout_content"]) ? trim($data["callout_content"]) : $existing["callout_content"]);

    $section3Title = isset($data["section3Title"]) ? trim($data["section3Title"]) : (isset($data["section3_title"]) ? trim($data["section3_title"]) : $existing["section3_title"]);
    $section3Content1 = isset($data["section3Content1"]) ? trim($data["section3Content1"]) : (isset($data["section3_content1"]) ? trim($data["section3_content1"]) : $existing["section3_content1"]);
    $section3Content2 = isset($data["section3Content2"]) ? trim($data["section3Content2"]) : (isset($data["section3_content2"]) ? trim($data["section3_content2"]) : $existing["section3_content2"]);

    $footerText = isset($data["footerText"]) ? trim($data["footerText"]) : (isset($data["footer_text"]) ? trim($data["footer_text"]) : $existing["footer_text"]);
    $isBookmarked = isset($data["isBookmarked"]) ? ((bool)$data["isBookmarked"] ? 1 : 0) : (isset($data["is_bookmarked"]) ? ((bool)$data["is_bookmarked"] ? 1 : 0) : (int)$existing["is_bookmarked"]);

    // 4. Update row
    $updateStmt = $conn->prepare("
        UPDATE notes SET
            title = ?,
            subject = ?,
            course_code = ?,
            tag = ?,
            professor = ?,
            duration = ?,
            read_time = ?,
            summary = ?,
            full_content = ?,
            key_takeaways = ?,
            important_terms = ?,
            section1_title = ?,
            section1_content = ?,
            section2_title = ?,
            section2_bullets = ?,
            callout_title = ?,
            callout_content = ?,
            section3_title = ?,
            section3_content1 = ?,
            section3_content2 = ?,
            footer_text = ?,
            is_bookmarked = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");

    $updateStmt->execute([
        $title, $subject, $courseCode, $tag, $professor, $duration, $readTime,
        $summary, $fullContent, $keyTakeawaysJson, $importantTermsJson,
        $section1Title, $section1Content, $section2Title, $section2BulletsJson,
        $calloutTitle, $calloutContent, $section3Title, $section3Content1, $section3Content2,
        $footerText, $isBookmarked, $noteId
    ]);

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Study note updated successfully",
        "note_id" => $noteId,
        "updated_fields" => [
            "title" => $title,
            "subject" => $subject,
            "summary" => $summary,
            "isBookmarked" => (bool)$isBookmarked
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while updating note: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

