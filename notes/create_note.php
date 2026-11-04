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

try {
    // Verify user exists in database
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

    $title = trim($data["title"] ?? $data["lectureTitle"] ?? "");
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
    $duration = trim($data["duration"] ?? "42 min");
    $readTime = trim($data["readTime"] ?? $data["read_time"] ?? "6 min read");

    // Icon type mapping
    if (!empty($data["iconType"]) || !empty($data["icon_type"])) {
        $iconRaw = $data["iconType"] ?? $data["icon_type"];
        $iconType = (stripos($iconRaw, "Math") !== false) ? "Math" : "Database";
    } else {
        $iconType = (stripos($subject, "Math") !== false) ? "Math" : "Database";
    }

    $summary = trim($data["summary"] ?? $data["aiSummaryText"] ?? "");
    $fullContent = trim($data["full_content"] ?? $data["fullContent"] ?? $data["fullText"] ?? $data["content"] ?? "");
    if ($fullContent === "" && $summary !== "") {
        $fullContent = $summary;
    }

    $keyTakeaways = $data["keyTakeaways"] ?? $data["key_takeaways"] ?? [];
    $keyTakeawaysJson = is_array($keyTakeaways) ? json_encode($keyTakeaways, JSON_UNESCAPED_UNICODE) : $keyTakeaways;

    $importantTerms = $data["importantTerms"] ?? $data["important_terms"] ?? [];
    $importantTermsJson = is_array($importantTerms) ? json_encode($importantTerms, JSON_UNESCAPED_UNICODE) : $importantTerms;

    $section1Title = trim($data["section1Title"] ?? $data["section1_title"] ?? "01 Overview");
    $section1Content = trim($data["section1Content"] ?? $data["section1_content"] ?? ($summary ?: "Summary of the lecture concept."));

    $section2Title = trim($data["section2Title"] ?? $data["section2_title"] ?? "02 Key Concepts");
    $section2Bullets = $data["section2Bullets"] ?? $data["section2_bullets"] ?? [
        "Core principle and architectural foundations.",
        "System invariants and fault tolerance mechanisms.",
        "Practical implementation constraints and best practices."
    ];
    $section2BulletsJson = is_array($section2Bullets) ? json_encode($section2Bullets, JSON_UNESCAPED_UNICODE) : $section2Bullets;

    $calloutTitle = trim($data["calloutTitle"] ?? $data["callout_title"] ?? "Core Principle");
    $calloutContent = trim($data["calloutContent"] ?? $data["callout_content"] ?? "Key takeaway from this lecture.");

    $section3Title = trim($data["section3Title"] ?? $data["section3_title"] ?? "03 In Practice");
    $section3Content1 = trim($data["section3Content1"] ?? $data["section3_content1"] ?? "Practical application of concepts discussed.");
    $section3Content2 = trim($data["section3Content2"] ?? $data["section3_content2"] ?? "Follow-up notes and reference materials.");

    $footerText = trim($data["footerText"] ?? $data["footer_text"] ?? "Excerpt · Next: In-depth practice");
    $isBookmarked = (!empty($data["isBookmarked"]) || !empty($data["is_bookmarked"])) ? 1 : 0;
    $transcriptionId = filter_var($data["transcription_id"] ?? $data["transcriptionId"] ?? null, FILTER_VALIDATE_INT) ?: null;

    $stmt = $conn->prepare(
        "INSERT INTO notes (
            user_id, transcription_id, title, subject, course_code, tag, professor, duration, read_time, icon_type,
            summary, full_content, key_takeaways, important_terms, section1_title, section1_content,
            section2_title, section2_bullets, callout_title, callout_content,
            section3_title, section3_content1, section3_content2, footer_text, is_bookmarked
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $stmt->execute([
        $userId, $transcriptionId, $title, $subject, $courseCode, $tag, $professor, $duration, $readTime, $iconType,
        $summary, $fullContent, $keyTakeawaysJson, $importantTermsJson, $section1Title, $section1Content,
        $section2Title, $section2BulletsJson, $calloutTitle, $calloutContent,
        $section3Title, $section3Content1, $section3Content2, $footerText, $isBookmarked
    ]);

    $newId = (int)$conn->lastInsertId();

    http_response_code(201);
    echo json_encode([
        "success" => true,
        "message" => "Note saved to database successfully",
        "note_id" => $newId,
        "note" => [
            "id" => (string)$newId,
            "user_id" => $userId,
            "transcription_id" => $transcriptionId,
            "title" => $title,
            "subject" => $subject,
            "courseCode" => $courseCode,
            "tag" => $tag,
            "dateText" => "Today",
            "durationText" => $duration,
            "readTimeText" => $readTime,
            "iconType" => $iconType,
            "summary" => $summary,
            "isBookmarked" => (bool)$isBookmarked
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while creating note: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
