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
$userId = $authUser ? (int)$authUser["id"] : filter_var($_GET["user_id"] ?? $_GET["userId"] ?? 1, FILTER_VALIDATE_INT);

if (!$userId || $userId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid user identification required"
    ]);
    exit;
}

$search = trim($_GET["search"] ?? $_GET["query"] ?? "");
$category = trim($_GET["category"] ?? $_GET["subject"] ?? "");

try {
    // If user has 0 notes, copy default starter notes for this user
    $countCheck = $conn->prepare("SELECT COUNT(*) FROM notes WHERE user_id = ?");
    $countCheck->execute([$userId]);
    if ((int)$countCheck->fetchColumn() === 0) {
        // Clone initial sample notes to give the user an immediate rich experience
        $copyStmt = $conn->prepare(
            "INSERT INTO notes (
                user_id, title, subject, course_code, tag, professor, duration, 
                read_time, icon_type, summary, full_content, key_takeaways, important_terms, 
                section1_title, section1_content, section2_title, section2_bullets, 
                callout_title, callout_content, section3_title, section3_content1, section3_content2, footer_text
             )
             SELECT 
                ?, title, subject, course_code, tag, professor, duration, 
                read_time, icon_type, summary, full_content, key_takeaways, important_terms, 
                section1_title, section1_content, section2_title, section2_bullets, 
                callout_title, callout_content, section3_title, section3_content1, section3_content2, footer_text
             FROM notes WHERE user_id = 1"
        );
        $copyStmt->execute([$userId]);
    }

    $sql = "SELECT id, title, subject, course_code, tag, professor, duration, read_time, icon_type, summary, is_bookmarked, created_at 
            FROM notes 
            WHERE user_id = ?";
    $params = [$userId];

    if ($search !== "") {
        $sql .= " AND (title LIKE ? OR subject LIKE ? OR summary LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    if ($category !== "" && strtolower($category) !== "all" && strtolower($category) !== "all subjects") {
        $sql .= " AND subject = ?";
        $params[] = $category;
    }

    $sql .= " ORDER BY created_at DESC, id DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Overall stats for this user
    $statsStmt = $conn->prepare(
        "SELECT COUNT(*) AS total_notes, COUNT(DISTINCT subject) AS subjects_count FROM notes WHERE user_id = ?"
    );
    $statsStmt->execute([$userId]);
    $stats = $statsStmt->fetch();

    $totalNotes = (int)($stats["total_notes"] ?? 0);
    $subjectsCount = (int)($stats["subjects_count"] ?? 0);

    $notes = [];
    foreach ($rows as $row) {
        $dateText = date("d M", strtotime($row["created_at"]));
        $notes[] = [
            "id" => (string)$row["id"],
            "title" => $row["title"],
            "subject" => $row["subject"],
            "courseCode" => $row["course_code"] ?? "CS301",
            "dateText" => $dateText,
            "durationText" => $row["duration"] ?? "40 min",
            "readTimeText" => $row["read_time"] ?? "5 min read",
            "iconType" => (stripos($row["subject"], "Math") !== false || $row["icon_type"] === "Math") ? "Math" : "Database",
            "summary" => $row["summary"] ?? "",
            "isBookmarked" => (bool)$row["is_bookmarked"]
        ];
    }

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Notes fetched successfully",
        "total_notes" => $totalNotes,
        "subjects_count" => $subjectsCount,
        "stats_subtitle" => "$totalNotes notes · $subjectsCount subjects · A little more clarity",
        "backup_status_text" => "All notes backed up · Updated just now",
        "notes" => $notes
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Unable to fetch notes: " . $e->getMessage()
    ]);
}

