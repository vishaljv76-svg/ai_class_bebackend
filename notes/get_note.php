<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../common/auth_middleware.php";
require_once __DIR__ . "/../services/educational_resources.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed. GET method required."
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
$userId = $authUser ? (int)$authUser["id"] : filter_var($_GET["user_id"] ?? $_GET["userId"] ?? null, FILTER_VALIDATE_INT);
$noteId = filter_var($_GET["id"] ?? $_GET["note_id"] ?? null, FILTER_VALIDATE_INT);

if (!$noteId || $noteId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid note id is required."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmt = $conn->prepare("SELECT * FROM notes WHERE id = ?");
    $stmt->execute([$noteId]);
    $note = $stmt->fetch();

    if (!$note) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Note not found with ID: " . $noteId
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Enforce ownership: users can access their own notes or default onboarding notes (user_id 1)
    if ($userId && (int)$note["user_id"] !== $userId && (int)$note["user_id"] !== 1) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "Access denied. You do not have permission to view this note."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Decode section 2 bullets JSON or string
    $section2Bullets = [];
    if (!empty($note["section2_bullets"])) {
        $decoded = json_decode($note["section2_bullets"], true);
        $section2Bullets = is_array($decoded) ? $decoded : array_values(array_filter(array_map('trim', explode("\n", $note["section2_bullets"]))));
    }
    if (empty($section2Bullets)) {
        $section2Bullets = [
            "Core principle and architectural foundations.",
            "System invariants and fault tolerance mechanisms.",
            "Practical implementation constraints and best practices."
        ];
    }

    // Decode key takeaways JSON or string
    $keyTakeaways = [];
    if (!empty($note["key_takeaways"])) {
        $decoded = json_decode($note["key_takeaways"], true);
        $keyTakeaways = is_array($decoded) ? $decoded : array_values(array_filter(array_map('trim', explode("\n", $note["key_takeaways"]))));
    }

    // Decode important terms JSON or string
    $importantTerms = [];
    if (!empty($note["important_terms"])) {
        $decoded = json_decode($note["important_terms"], true);
        $importantTerms = is_array($decoded) ? $decoded : array_values(array_filter(array_map('trim', explode(",", $note["important_terms"]))));
    }

    $dateFormatted = date("d M Y", strtotime($note["created_at"]));
    $professor = !empty($note["professor"]) ? $note["professor"] : "Prof. Sarah Chen";
    $readTime = !empty($note["read_time"]) ? $note["read_time"] : "6 min read";
    $metadataText = "$professor · $dateFormatted · $readTime";

    $iconType = (stripos($note["subject"], "Math") !== false || strcasecmp($note["icon_type"] ?? "", "Math") === 0) 
        ? "Math" 
        : "Database";

    $summaryText = $note["summary"] ?: "";
    $fullContentText = $note["full_content"] ?: ($summaryText ?: "Summary of the lecture concept.");

    $educationalResources = getLectureResources($conn, (int)$note["id"], (int)$note["user_id"]);

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Note fetched successfully",
        "note" => [
            "id" => (string)$note["id"],
            "user_id" => (int)$note["user_id"],
            "transcription_id" => !empty($note["transcription_id"]) ? (int)$note["transcription_id"] : null,
            "courseCode" => $note["course_code"] ?: "CS301",
            "tagText" => $note["tag"] ?: "AI notes",
            "lectureTitle" => $note["title"],
            "title" => $note["title"],
            "subject" => $note["subject"],
            "professor" => $professor,
            "duration" => $note["duration"] ?: "42 min",
            "readTime" => $readTime,
            "metadataText" => $metadataText,
            "iconType" => $iconType,
            "summary" => $summaryText,
            "fullContent" => $fullContentText,
            "section1Title" => $note["section1_title"] ?: "01 Overview",
            "section1Content" => $note["section1_content"] ?: $fullContentText,
            "section2Title" => $note["section2_title"] ?: "02 Key Concepts",
            "section2Bullets" => $section2Bullets,
            "calloutTitle" => $note["callout_title"] ?: "Core Principle",
            "calloutContent" => $note["callout_content"] ?: "Key takeaway from this lecture.",
            "section3Title" => $note["section3_title"] ?: "03 In Practice",
            "section3Content1" => $note["section3_content1"] ?: "Practical application of concepts discussed.",
            "section3Content2" => $note["section3_content2"] ?: "Follow-up notes and reference materials.",
            "footerText" => $note["footer_text"] ?: "Excerpt · Next: In-depth practice",
            "isBookmarked" => (bool)$note["is_bookmarked"],
            "keyTakeaways" => $keyTakeaways,
            "importantTerms" => $importantTerms,
            "educationalResources" => $educationalResources,
            "created_at" => $note["created_at"],
            "updated_at" => $note["updated_at"]
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while fetching note: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
