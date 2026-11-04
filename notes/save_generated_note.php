<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../common/auth_middleware.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed. POST method required."
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

if (!$userId || $userId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid user_id or Authorization Bearer token is required."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$transcriptionId = filter_var($data["transcription_id"] ?? $data["transcriptionId"] ?? null, FILTER_VALIDATE_INT);

if (!$transcriptionId || $transcriptionId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid 'transcription_id' is required to link generated notes."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 1. Verify user exists
    $userStmt = $conn->prepare("SELECT id, name FROM users WHERE id = ?");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch();

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "User account not found for ID: " . $userId
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. Verify transcription exists and belongs to user
    $transStmt = $conn->prepare("
        SELECT t.*, a.title AS audio_title, a.duration AS audio_duration
        FROM transcriptions t
        LEFT JOIN audio_files a ON t.audio_id = a.id
        WHERE t.id = ?
    ");
    $transStmt->execute([$transcriptionId]);
    $transcription = $transStmt->fetch();

    if (!$transcription) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Transcription not found for ID: " . $transcriptionId
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ((int)$transcription["user_id"] !== $userId) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "Forbidden: You do not have permission to link notes to this transcription."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 3. Extract and sanitize fields
    $title = trim($data["title"] ?? $data["lectureTitle"] ?? ($transcription["lecture_title"] ?: ""));
    if ($title === "") {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Note title is required."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $subject = trim($data["subject"] ?? $data["subjectBadge"] ?? "Computer Science");
    $courseCode = trim($data["courseCode"] ?? $data["course_code"] ?? "CS301");
    $tag = trim($data["tag"] ?? $data["tagText"] ?? "AI notes");
    $professor = trim($data["professor"] ?? "Prof. Sarah Chen");
    $duration = trim($data["duration"] ?? ($transcription["duration"] ?: "42 min"));
    $readTime = trim($data["readTime"] ?? $data["read_time"] ?? "6 min read");
    $iconType = !empty($data["iconType"]) || !empty($data["icon_type"])
        ? ($data["iconType"] ?? $data["icon_type"])
        : ((stripos($subject, "Math") !== false) ? "Math" : "Database");

    $summary = trim($data["summary"] ?? $data["aiSummaryText"] ?? "");
    $fullContent = trim($data["full_content"] ?? $data["fullContent"] ?? ($summary ?: $transcription["full_transcript"]));

    // Key Takeaways / points
    $keyTakeaways = $data["keyTakeaways"] ?? $data["key_takeaways"] ?? [];
    if (!is_array($keyTakeaways)) {
        $keyTakeaways = array_values(array_filter(array_map('trim', explode("\n", (string)$keyTakeaways))));
    }
    $keyTakeawaysJson = json_encode($keyTakeaways, JSON_UNESCAPED_UNICODE);

    // Important Terms / keywords
    $importantTerms = $data["importantTerms"] ?? $data["important_terms"] ?? [];
    if (!is_array($importantTerms)) {
        $importantTerms = array_values(array_filter(array_map('trim', explode(",", (string)$importantTerms))));
    }
    $importantTermsJson = json_encode($importantTerms, JSON_UNESCAPED_UNICODE);

    $section1Title = trim($data["section1Title"] ?? $data["section1_title"] ?? "01 Overview");
    $section1Content = trim($data["section1Content"] ?? $data["section1_content"] ?? ($fullContent ?: $summary));

    $section2Title = trim($data["section2Title"] ?? $data["section2_title"] ?? "02 Key Concepts & Invariants");
    $section2Bullets = $data["section2Bullets"] ?? $data["section2_bullets"] ?? [
        "Core foundational concept identified from lecture.",
        "System mechanism and technical principles.",
        "Implementation trade-offs and rules."
    ];
    if (!is_array($section2Bullets)) {
        $section2Bullets = array_values(array_filter(array_map('trim', explode("\n", (string)$section2Bullets))));
    }
    $section2BulletsJson = json_encode($section2Bullets, JSON_UNESCAPED_UNICODE);

    $calloutTitle = trim($data["calloutTitle"] ?? $data["callout_title"] ?? "Core Principle");
    $calloutContent = trim($data["calloutContent"] ?? $data["callout_content"] ?? "Key rule or theorem derived from this lecture.");

    $section3Title = trim($data["section3Title"] ?? $data["section3_title"] ?? "03 Practical Application");
    $section3Content1 = trim($data["section3Content1"] ?? $data["section3_content1"] ?? "Real-world application and engineering context.");
    $section3Content2 = trim($data["section3Content2"] ?? $data["section3_content2"] ?? "Next study steps and potential pitfalls.");

    $footerText = trim($data["footerText"] ?? $data["footer_text"] ?? "AI Generated Study Notes · SmartNotes AI");
    $serviceUsed = trim($data["service_used"] ?? $data["serviceUsed"] ?? "AI Notes Generator");
    $isBookmarked = (!empty($data["isBookmarked"]) || !empty($data["is_bookmarked"])) ? 1 : 0;

    // 4. Insert into notes table
    $stmt = $conn->prepare("
        INSERT INTO notes (
            user_id, transcription_id, title, subject, course_code, tag, professor, duration, read_time, icon_type,
            summary, full_content, key_takeaways, important_terms, section1_title, section1_content,
            section2_title, section2_bullets, callout_title, callout_content,
            section3_title, section3_content1, section3_content2, footer_text, ai_service_used, is_bookmarked
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $userId, $transcriptionId, $title, $subject, $courseCode, $tag, $professor, $duration, $readTime, $iconType,
        $summary, $fullContent, $keyTakeawaysJson, $importantTermsJson, $section1Title, $section1Content,
        $section2Title, $section2BulletsJson, $calloutTitle, $calloutContent,
        $section3Title, $section3Content1, $section3Content2, $footerText, $serviceUsed, $isBookmarked
    ]);

    $newId = (int)$conn->lastInsertId();

    http_response_code(201);
    echo json_encode([
        "success" => true,
        "message" => "Generated study notes saved to database successfully",
        "note_id" => $newId,
        "transcription_id" => $transcriptionId,
        "audio_id" => (int)$transcription["audio_id"],
        "note" => [
            "id" => (string)$newId,
            "user_id" => $userId,
            "transcription_id" => $transcriptionId,
            "title" => $title,
            "lectureTitle" => $title,
            "subject" => $subject,
            "subjectBadge" => $subject,
            "courseCode" => $courseCode,
            "tag" => $tag,
            "tagText" => $tag,
            "professor" => $professor,
            "duration" => $duration,
            "readTime" => $readTime,
            "metadataText" => "$professor · " . date("d M Y") . " · $readTime",
            "iconType" => $iconType,
            "summary" => $summary,
            "aiSummaryText" => $summary,
            "fullContent" => $fullContent,
            "keyTakeaways" => $keyTakeaways,
            "importantTerms" => $importantTerms,
            "section1Title" => $section1Title,
            "section1Content" => $section1Content,
            "section2Title" => $section2Title,
            "section2Bullets" => $section2Bullets,
            "calloutTitle" => $calloutTitle,
            "calloutContent" => $calloutContent,
            "section3Title" => $section3Title,
            "section3Content1" => $section3Content1,
            "section3Content2" => $section3Content2,
            "footerText" => $footerText,
            "isBookmarked" => (bool)$isBookmarked,
            "isSaved" => true
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while saving study note: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

