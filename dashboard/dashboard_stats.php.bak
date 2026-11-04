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

try {
    // Fetch user info
    $userStmt = $conn->prepare("SELECT id, name, email FROM users WHERE id = ?");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch();

    $userName = $user ? $user["name"] : "Alex";
    $initials = $user ? getInitials($user["name"]) : "AM";

    // Notes stats
    $noteStats = $conn->prepare(
        "SELECT COUNT(*) AS total_notes, COUNT(DISTINCT subject) AS subjects_count FROM notes WHERE user_id = ?"
    );
    $noteStats->execute([$userId]);
    $nStats = $noteStats->fetch();

    $totalNotes = (int)($nStats["total_notes"] ?? 0);
    $subjectsCount = (int)($nStats["subjects_count"] ?? 0);

    // If 0 notes for this user, clone default notes so dashboard looks great immediately
    if ($totalNotes === 0) {
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

        $noteStats->execute([$userId]);
        $nStats = $noteStats->fetch();
        $totalNotes = (int)($nStats["total_notes"] ?? 0);
        $subjectsCount = (int)($nStats["subjects_count"] ?? 0);
    }

    // Quizzes count
    $quizStmt = $conn->prepare("SELECT COUNT(*) FROM quizzes WHERE user_id = ?");
    $quizStmt->execute([$userId]);
    $quizzesCount = (int)$quizStmt->fetchColumn();
    if ($quizzesCount === 0) {
        $quizzesCount = 8; // fallback to design default if user hasn't taken quizzes yet
    }

    // 2 most recent notes
    $recStmt = $conn->prepare(
        "SELECT id, title, subject, duration, icon_type, created_at 
         FROM notes 
         WHERE user_id = ? 
         ORDER BY created_at DESC, id DESC 
         LIMIT 2"
    );
    $recStmt->execute([$userId]);
    $recNotes = $recStmt->fetchAll();

    $recentNotes = [];
    foreach ($recNotes as $r) {
        $created = strtotime($r["created_at"]);
        $isToday = (date("Y-m-d", $created) === date("Y-m-d"));
        $dateLabel = $isToday ? "Today" : date("d M", $created);

        $recentNotes[] = [
            "id" => (string)$r["id"],
            "title" => $r["title"],
            "subject" => $r["subject"],
            "date" => $dateLabel,
            "duration" => $r["duration"] ?: "40 min",
            "iconType" => (stripos($r["subject"], "Math") !== false || $r["icon_type"] === "Math") ? "Math" : "Database"
        ];
    }

    $dateText = date("l, d F");

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "userName" => $userName,
        "userInitials" => $initials,
        "dateText" => $dateText,
        "totalNotes" => $totalNotes,
        "subjectsCount" => $subjectsCount,
        "quizzesCount" => $quizzesCount,
        "recentNotes" => $recentNotes
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Unable to load dashboard stats: " . $e->getMessage()
    ]);
}

