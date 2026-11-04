<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../common/auth_middleware.php";

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
$transcriptionId = filter_var($_GET["transcription_id"] ?? $_GET["transcriptionId"] ?? null, FILTER_VALIDATE_INT);

if (!$noteId && !$transcriptionId) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Either 'id' (note_id) or 'transcription_id' is required."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($noteId) {
        $stmt = $conn->prepare("
            SELECT n.*, 
                   t.lecture_title AS transcription_title, 
                   t.language AS transcription_language, 
                   t.full_transcript,
                   t.confidence AS transcription_confidence,
                   a.id AS audio_file_id,
                   a.file_name AS audio_file_name,
                   a.title AS audio_title,
                   a.duration AS audio_duration
            FROM notes n
            LEFT JOIN transcriptions t ON n.transcription_id = t.id
            LEFT JOIN audio_files a ON t.audio_id = a.id
            WHERE n.id = ?
        ");
        $stmt->execute([$noteId]);
    } else {
        $stmt = $conn->prepare("
            SELECT n.*, 
                   t.lecture_title AS transcription_title, 
                   t.language AS transcription_language, 
                   t.full_transcript,
                   t.confidence AS transcription_confidence,
                   a.id AS audio_file_id,
                   a.file_name AS audio_file_name,
                   a.title AS audio_title,
                   a.duration AS audio_duration
            FROM notes n
            LEFT JOIN transcriptions t ON n.transcription_id = t.id
            LEFT JOIN audio_files a ON t.audio_id = a.id
            WHERE n.transcription_id = ?
            ORDER BY n.id DESC LIMIT 1
        ");
        $stmt->execute([$transcriptionId]);
    }

    $note = $stmt->fetch();

    if (!$note) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "No study notes found for the requested criteria."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Ownership check if user is authenticated or specified
    if ($userId && (int)$note["user_id"] !== $userId) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "Forbidden: You do not have permission to view this note."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Decode section 2 bullets JSON or string
    $section2Bullets = [];
    if (!empty($note["section2_bullets"])) {
        $decoded = json_decode($note["section2_bullets"], true);
        $section2Bullets = is_array($decoded) ? $decoded : array_values(array_filter(array_map('trim', explode("\n", $note["section2_bullets"]))));
    }

    // Decode key takeaways JSON or string
    $keyTakeaways = [];
    if (!empty($note["key_takeaways"])) {
        $decoded = json_decode($note["key_takeaways"], true);
        $keyTakeaways = is_array($decoded) ? $decoded : array_values(array_filter(array_map('trim', explode("\n", $note["key_takeaways"]))));
    }

    // Decode important terms / keywords JSON or string
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

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Study note retrieved successfully",
        "note" => [
            "id" => (string)$note["id"],
            "user_id" => (int)$note["user_id"],
            "transcription_id" => !empty($note["transcription_id"]) ? (int)$note["transcription_id"] : null,
            "audio_id" => !empty($note["audio_file_id"]) ? (int)$note["audio_file_id"] : null,
            "title" => $note["title"],
            "lectureTitle" => $note["title"],
            "subject" => $note["subject"],
            "subjectBadge" => $note["subject"],
            "courseCode" => $note["course_code"] ?: "CS301",
            "tag" => $note["tag"] ?: "AI notes",
            "tagText" => $note["tag"] ?: "AI notes",
            "professor" => $professor,
            "duration" => $note["duration"] ?: "42 min",
            "readTime" => $readTime,
            "metadataText" => $metadataText,
            "iconType" => $iconType,
            "summary" => $summaryText,
            "aiSummaryText" => $summaryText,
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
            "footerText" => $note["footer_text"] ?: "AI Generated Study Notes · SmartNotes AI",
            "isBookmarked" => (bool)$note["is_bookmarked"],
            "isSaved" => true,
            "keyTakeaways" => $keyTakeaways,
            "importantTerms" => $importantTerms,
            "ai_service_used" => $note["ai_service_used"],
            "created_at" => $note["created_at"],
            "updated_at" => $note["updated_at"]
        ],
        "linked_transcription" => !empty($note["transcription_id"]) ? [
            "id" => (int)$note["transcription_id"],
            "lecture_title" => $note["transcription_title"],
            "language" => $note["transcription_language"],
            "audio_title" => $note["audio_title"],
            "audio_file_name" => $note["audio_file_name"]
        ] : null
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while fetching note: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

