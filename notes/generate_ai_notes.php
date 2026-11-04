<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../config/ai_notes_config.php";
require_once __DIR__ . "/../common/auth_middleware.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed. POST method required."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Normalize Authorization & custom headers in environments where Apache strips them
if (empty($_SERVER["HTTP_AUTHORIZATION"])) {
    $reqHeaders = function_exists("getallheaders") ? getallheaders() : [];
    foreach ($reqHeaders as $hKey => $hVal) {
        if (strcasecmp($hKey, "Authorization") === 0) {
            $_SERVER["HTTP_AUTHORIZATION"] = $hVal;
        } elseif (strcasecmp($hKey, "X-Auth-Token") === 0) {
            $_SERVER["HTTP_X_AUTH_TOKEN"] = $hVal;
        } elseif (strcasecmp($hKey, "X-AI-API-Key") === 0) {
            $_SERVER["HTTP_X_AI_API_KEY"] = $hVal;
        }
    }
}

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

if (!is_array($data)) {
    $data = $_POST;
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
$audioId = filter_var($data["audio_id"] ?? $data["audioId"] ?? null, FILTER_VALIDATE_INT);
$saveToDb = isset($data["save_to_db"]) ? (bool)$data["save_to_db"] : (isset($data["saveToDb"]) ? (bool)$data["saveToDb"] : true);
$customApiKey = trim($data["ai_api_key"] ?? ($_SERVER["HTTP_X_AI_API_KEY"] ?? ""));
$serviceName = trim($data["service"] ?? "");

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

    // 2. Locate transcription record
    $transcription = null;
    if ($transcriptionId && $transcriptionId > 0) {
        $stmt = $conn->prepare("
            SELECT t.*, a.title AS audio_title, a.duration AS audio_duration
            FROM transcriptions t
            LEFT JOIN audio_files a ON t.audio_id = a.id
            WHERE t.id = ?
        ");
        $stmt->execute([$transcriptionId]);
        $transcription = $stmt->fetch();
    } elseif ($audioId && $audioId > 0) {
        $stmt = $conn->prepare("
            SELECT t.*, a.title AS audio_title, a.duration AS audio_duration
            FROM transcriptions t
            LEFT JOIN audio_files a ON t.audio_id = a.id
            WHERE t.audio_id = ?
            ORDER BY t.id DESC LIMIT 1
        ");
        $stmt->execute([$audioId]);
        $transcription = $stmt->fetch();
    } else {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Either 'transcription_id' or 'audio_id' is required to generate study notes."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!$transcription) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "No saved transcription found for the requested ID."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 3. User authorization check
    if ((int)$transcription["user_id"] !== $userId) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "Forbidden: You do not have permission to access this transcription."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 4. Validate transcription content
    $fullTranscript = trim($transcription["full_transcript"] ?? "");
    if ($fullTranscript === "") {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Transcription text is empty. Cannot generate notes from an empty transcription."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 5. Generate structured notes using AI service
    $aiOptions = [
        "api_key" => $customApiKey ?: null,
        "service" => $serviceName ?: null,
        "lecture_title" => $data["lecture_title"] ?? $transcription["lecture_title"] ?? $transcription["audio_title"] ?? "Lecture",
        "subject" => $data["subject"] ?? "Computer Science",
        "course_code" => $data["course_code"] ?? "CS301",
        "professor" => $data["professor"] ?? "Prof. Sarah Chen"
    ];

    $aiResult = generateNotesWithLLM($fullTranscript, $aiOptions);

    if (!$aiResult["success"]) {
        http_response_code($aiResult["http_status"] ?? 502);
        echo json_encode($aiResult, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    $gen = $aiResult["notes"];

    // Format & sanitize extracted notes fields
    $title = trim($gen["title"] ?? ($transcription["lecture_title"] ?: "Lecture Study Notes"));
    $subject = trim($gen["subject"] ?? ($data["subject"] ?? "Computer Science"));
    $courseCode = trim($gen["course_code"] ?? ($data["course_code"] ?? "CS301"));
    $tag = trim($gen["tag"] ?? "AI notes");
    $professor = trim($gen["professor"] ?? ($data["professor"] ?? "Prof. Sarah Chen"));
    $duration = trim($transcription["duration"] ?? ($transcription["audio_duration"] ?? "42 min"));
    $readTime = trim($gen["read_time"] ?? "6 min read");
    $iconType = !empty($gen["icon_type"]) ? $gen["icon_type"] : ((stripos($subject, "Math") !== false) ? "Math" : "Database");
    
    $summary = trim($gen["summary"] ?? "");
    $fullContent = trim($gen["full_content"] ?? ($summary ?: $fullTranscript));

    $keyTakeaways = $gen["key_takeaways"] ?? [];
    if (!is_array($keyTakeaways)) {
        $keyTakeaways = array_values(array_filter(array_map('trim', explode("\n", (string)$keyTakeaways))));
    }
    $keyTakeawaysJson = json_encode($keyTakeaways, JSON_UNESCAPED_UNICODE);

    $importantTerms = $gen["important_terms"] ?? [];
    if (!is_array($importantTerms)) {
        $importantTerms = array_values(array_filter(array_map('trim', explode(",", (string)$importantTerms))));
    }
    $importantTermsJson = json_encode($importantTerms, JSON_UNESCAPED_UNICODE);

    $section1Title = trim($gen["section1_title"] ?? "01 Overview");
    $section1Content = trim($gen["section1_content"] ?? ($fullContent ?: $summary));

    $section2Title = trim($gen["section2_title"] ?? "02 Key Concepts & Invariants");
    $section2Bullets = $gen["section2_bullets"] ?? [
        "Core foundational concept identified from lecture.",
        "System mechanism and technical principles.",
        "Implementation trade-offs and rules."
    ];
    if (!is_array($section2Bullets)) {
        $section2Bullets = array_values(array_filter(array_map('trim', explode("\n", (string)$section2Bullets))));
    }
    $section2BulletsJson = json_encode($section2Bullets, JSON_UNESCAPED_UNICODE);

    $calloutTitle = trim($gen["callout_title"] ?? "Core Principle");
    $calloutContent = trim($gen["callout_content"] ?? "Key rule or theorem derived from this lecture.");

    $section3Title = trim($gen["section3_title"] ?? "03 Practical Application");
    $section3Content1 = trim($gen["section3_content1"] ?? "Real-world application and engineering context.");
    $section3Content2 = trim($gen["section3_content2"] ?? "Next study steps and potential pitfalls.");

    $footerText = trim($gen["footer_text"] ?? "AI Generated Study Notes · SmartNotes AI");
    $serviceUsed = $aiResult["service_used"] . " (" . $aiResult["model_used"] . ")";

    $newNoteId = null;

    // 6. Save in MySQL if requested
    if ($saveToDb) {
        $insertStmt = $conn->prepare("
            INSERT INTO notes (
                user_id, transcription_id, title, subject, course_code, tag, professor, duration, read_time, icon_type,
                summary, full_content, key_takeaways, important_terms, section1_title, section1_content,
                section2_title, section2_bullets, callout_title, callout_content,
                section3_title, section3_content1, section3_content2, footer_text, ai_service_used, is_bookmarked
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
        ");

        $insertStmt->execute([
            $userId, $transcription["id"], $title, $subject, $courseCode, $tag, $professor, $duration, $readTime, $iconType,
            $summary, $fullContent, $keyTakeawaysJson, $importantTermsJson, $section1Title, $section1Content,
            $section2Title, $section2BulletsJson, $calloutTitle, $calloutContent,
            $section3Title, $section3Content1, $section3Content2, $footerText, $serviceUsed
        ]);

        $newNoteId = (int)$conn->lastInsertId();
    }

    $responseCode = $saveToDb ? 201 : 200;
    http_response_code($responseCode);

    echo json_encode([
        "success" => true,
        "message" => $saveToDb ? "Study notes successfully generated and saved to database" : "Study notes generated successfully (preview mode)",
        "note_id" => $newNoteId,
        "transcription_id" => (int)$transcription["id"],
        "audio_id" => (int)$transcription["audio_id"],
        "service_used" => $serviceUsed,
        "note" => [
            "id" => $newNoteId ? (string)$newNoteId : null,
            "user_id" => $userId,
            "transcription_id" => (int)$transcription["id"],
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
            "isBookmarked" => false,
            "isSaved" => $saveToDb
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while generating study notes: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

