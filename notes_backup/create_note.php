<?php
header("Content-Type: application/json");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../common/auth_middleware.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "POST method required"
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
$userId = $authUser ? (int)$authUser["id"] : filter_var($data["user_id"] ?? $data["userId"] ?? 1, FILTER_VALIDATE_INT);

$title = trim($data["title"] ?? $data["lectureTitle"] ?? "");
$subject = trim($data["subject"] ?? $data["subjectBadge"] ?? "Computer Science");
$courseCode = trim($data["courseCode"] ?? $data["course_code"] ?? "CS301");
$tag = trim($data["tag"] ?? $data["tagText"] ?? "AI notes");
$professor = trim($data["professor"] ?? "Prof. Sarah Chen");
$duration = trim($data["duration"] ?? "42 min");
$readTime = trim($data["readTime"] ?? $data["read_time"] ?? "6 min read");
$iconType = (stripos($subject, "Math") !== false) ? "Math" : "Database";
$summary = trim($data["summary"] ?? $data["aiSummaryText"] ?? "");

$keyTakeaways = $data["keyTakeaways"] ?? $data["key_takeaways"] ?? [];
$keyTakeawaysJson = is_array($keyTakeaways) ? json_encode($keyTakeaways, JSON_UNESCAPED_UNICODE) : $keyTakeaways;

$importantTerms = $data["importantTerms"] ?? $data["important_terms"] ?? [];
$importantTermsJson = is_array($importantTerms) ? json_encode($importantTerms, JSON_UNESCAPED_UNICODE) : $importantTerms;

$section1Title = trim($data["section1Title"] ?? "01 Overview");
$section1Content = trim($data["section1Content"] ?? ($summary ?: "Summary of the lecture concept."));
$section2Title = trim($data["section2Title"] ?? "02 Key Concepts");
$section2Bullets = $data["section2Bullets"] ?? [
    "1NF — Atomic values in each cell; no repeating groups.",
    "2NF — In 1NF, and every non-key attribute depends on the whole candidate key.",
    "3NF — In 2NF, with no transitive dependency between non-key attributes."
];
$section2BulletsJson = is_array($section2Bullets) ? json_encode($section2Bullets, JSON_UNESCAPED_UNICODE) : $section2Bullets;

$calloutTitle = trim($data["calloutTitle"] ?? "Core Principle");
$calloutContent = trim($data["calloutContent"] ?? "Key takeaway from this class session.");
$section3Title = trim($data["section3Title"] ?? "03 In Practice");
$section3Content1 = trim($data["section3Content1"] ?? "Practical application of concepts discussed.");
$section3Content2 = trim($data["section3Content2"] ?? "Follow-up notes and reference materials.");
$footerText = trim($data["footerText"] ?? "Excerpt · Next: In-depth practice");

if ($title === "") {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Note title is required"
    ]);
    exit;
}

try {
    $stmt = $conn->prepare(
        "INSERT INTO notes (
            user_id, title, subject, course_code, tag, professor, duration, read_time, icon_type,
            summary, key_takeaways, important_terms, section1_title, section1_content,
            section2_title, section2_bullets, callout_title, callout_content,
            section3_title, section3_content1, section3_content2, footer_text
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $stmt->execute([
        $userId, $title, $subject, $courseCode, $tag, $professor, $duration, $readTime, $iconType,
        $summary, $keyTakeawaysJson, $importantTermsJson, $section1Title, $section1Content,
        $section2Title, $section2BulletsJson, $calloutTitle, $calloutContent,
        $section3Title, $section3Content1, $section3Content2, $footerText
    ]);

    $newId = (int)$conn->lastInsertId();

    http_response_code(201);
    echo json_encode([
        "success" => true,
        "message" => "Note saved to database successfully",
        "note_id" => $newId,
        "note" => [
            "id" => (string)$newId,
            "title" => $title,
            "subject" => $subject,
            "courseCode" => $courseCode,
            "dateText" => date("d M"),
            "durationText" => $duration,
            "readTimeText" => $readTime,
            "iconType" => $iconType,
            "summary" => $summary
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Unable to create note: " . $e->getMessage()
    ]);
}

