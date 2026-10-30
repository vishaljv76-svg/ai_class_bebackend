<?php
header("Content-Type: application/json");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../common/auth_middleware.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "GET method required"
    ]);
    exit;
}

$authUser = getAuthenticatedUser($conn, false);
$noteId = filter_var($_GET["id"] ?? $_GET["note_id"] ?? null, FILTER_VALIDATE_INT);

if (!$noteId || $noteId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid note id is required"
    ]);
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
            "message" => "Note not found"
        ]);
        exit;
    }

    // Decode JSON bullets or parse if stored as string
    $section2Bullets = [];
    if (!empty($note["section2_bullets"])) {
        $decoded = json_decode($note["section2_bullets"], true);
        $section2Bullets = is_array($decoded) ? $decoded : explode("\n", $note["section2_bullets"]);
    } else {
        $section2Bullets = [
            "1NF — Atomic values in each cell; no repeating groups.",
            "2NF — In 1NF, and every non-key attribute depends on the whole candidate key.",
            "3NF — In 2NF, with no transitive dependency between non-key attributes."
        ];
    }

    $keyTakeaways = [];
    if (!empty($note["key_takeaways"])) {
        $decoded = json_decode($note["key_takeaways"], true);
        $keyTakeaways = is_array($decoded) ? $decoded : explode("\n", $note["key_takeaways"]);
    }

    $importantTerms = [];
    if (!empty($note["important_terms"])) {
        $decoded = json_decode($note["important_terms"], true);
        $importantTerms = is_array($decoded) ? $decoded : explode(",", $note["important_terms"]);
    }

    $dateFormatted = date("d M Y", strtotime($note["created_at"]));
    $professor = $note["professor"] ?: "Prof. Sarah Chen";
    $readTime = $note["read_time"] ?: "6 min read";
    $metadataText = "$professor · $dateFormatted · $readTime";

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Note fetched successfully",
        "note" => [
            "id" => (string)$note["id"],
            "courseCode" => $note["course_code"] ?: "CS301",
            "tagText" => $note["tag"] ?: "AI notes",
            "lectureTitle" => $note["title"],
            "subject" => $note["subject"],
            "metadataText" => $metadataText,
            "summary" => $note["summary"] ?: "",
            "section1Title" => $note["section1_title"] ?: "01 Overview",
            "section1Content" => $note["section1_content"] ?: ($note["summary"] ?: "Summary of the lecture concept."),
            "section2Title" => $note["section2_title"] ?: "02 Key Concepts",
            "section2Bullets" => $section2Bullets,
            "calloutTitle" => $note["callout_title"] ?: "Core Principle",
            "calloutContent" => $note["callout_content"] ?: "Key takeaway from this class session.",
            "section3Title" => $note["section3_title"] ?: "03 In Practice",
            "section3Content1" => $note["section3_content1"] ?: "Practical application of concepts discussed.",
            "section3Content2" => $note["section3_content2"] ?: "Follow-up notes and reference materials.",
            "footerText" => $note["footer_text"] ?: "Excerpt · Next: In-depth practice",
            "isBookmarked" => (bool)$note["is_bookmarked"],
            "keyTakeaways" => $keyTakeaways,
            "importantTerms" => $importantTerms
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Unable to fetch note details: " . $e->getMessage()
    ]);
}

