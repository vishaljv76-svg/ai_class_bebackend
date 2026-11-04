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
$userId = filter_var($_GET["user_id"] ?? $_GET["userId"] ?? null, FILTER_VALIDATE_INT);

if (!$userId && $authUser) {
    $userId = (int)$authUser["id"];
}

if ($authUser && $userId && (int)$authUser["id"] !== $userId) {
    http_response_code(403);
    echo json_encode([
        "success" => false,
        "message" => "Access denied. You do not have permission to view another user's notes."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$userId || $userId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid user_id or Authorization Bearer token is required."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$search = trim($_GET["search"] ?? $_GET["query"] ?? $_GET["q"] ?? "");
$category = trim($_GET["category"] ?? $_GET["subject"] ?? "");
$bookmarkedOnly = isset($_GET["bookmarked"]) || isset($_GET["is_bookmarked"]);

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

    // Build query with prepared statements
    $sql = "SELECT id, user_id, transcription_id, title, subject, course_code, tag, professor, duration, read_time,
                   icon_type, summary, is_bookmarked, created_at, updated_at
            FROM notes
            WHERE user_id = ?";
    $params = [$userId];

    // Search by title or content
    if ($search !== "") {
        $sql .= " AND (
            title LIKE ? OR 
            subject LIKE ? OR 
            summary LIKE ? OR 
            full_content LIKE ? OR 
            section1_content LIKE ? OR 
            section2_bullets LIKE ? OR 
            key_takeaways LIKE ? OR 
            important_terms LIKE ?
        )";
        $searchWildcard = "%" . $search . "%";
        for ($i = 0; $i < 8; $i++) {
            $params[] = $searchWildcard;
        }
    }

    // Filter by subject / category
    if ($category !== "" && strcasecmp($category, "all") !== 0 && strcasecmp($category, "all subjects") !== 0) {
        $sql .= " AND subject = ?";
        $params[] = $category;
    }

    // Optional bookmark filter
    if ($bookmarkedOnly) {
        $sql .= " AND is_bookmarked = 1";
    }

    $sql .= " ORDER BY created_at DESC, id DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Calculate real live statistics for this user from MySQL
    $statsStmt = $conn->prepare("SELECT COUNT(*) FROM notes WHERE user_id = ?");
    $statsStmt->execute([$userId]);
    $totalNotes = (int)$statsStmt->fetchColumn();

    $subStmt = $conn->prepare("
        SELECT COUNT(DISTINCT subject_name) FROM (
            SELECT subject AS subject_name FROM notes WHERE user_id = ? AND subject IS NOT NULL AND TRIM(subject) != ''
            UNION
            SELECT s.name AS subject_name FROM user_subjects us JOIN subjects s ON us.subject_id = s.id WHERE us.user_id = ?
        ) AS all_subs
    ");
    $subStmt->execute([$userId, $userId]);
    $subjectsCount = (int)$subStmt->fetchColumn();

    $today = date("Y-m-d");
    $yesterday = date("Y-m-d", strtotime("-1 day"));
    $notes = [];

    foreach ($rows as $row) {
        $createdTime = strtotime($row["created_at"]);
        $noteDate = date("Y-m-d", $createdTime);

        if ($noteDate === $today) {
            $dateText = "Today";
        } elseif ($noteDate === $yesterday) {
            $dateText = "Yesterday";
        } else {
            $dateText = date("d M", $createdTime);
        }

        $iconType = (stripos($row["subject"], "Math") !== false || strcasecmp($row["icon_type"] ?? "", "Math") === 0) 
            ? "Math" 
            : "Database";

        $notes[] = [
            "id" => (string)$row["id"],
            "transcription_id" => !empty($row["transcription_id"]) ? (int)$row["transcription_id"] : null,
            "title" => $row["title"],
            "subject" => $row["subject"],
            "courseCode" => $row["course_code"] ?? "CS301",
            "tag" => $row["tag"] ?? "AI notes",
            "dateText" => $dateText,
            "durationText" => !empty($row["duration"]) ? $row["duration"] : "40 min",
            "readTimeText" => !empty($row["read_time"]) ? $row["read_time"] : "5 min read",
            "iconType" => $iconType,
            "summary" => $row["summary"] ?? "",
            "isBookmarked" => (bool)$row["is_bookmarked"],
            "createdAt" => $row["created_at"],
            "updatedAt" => $row["updated_at"]
        ];
    }

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Notes fetched successfully",
        "user_id" => $userId,
        "total_notes" => $totalNotes,
        "subjects_count" => $subjectsCount,
        "stats_subtitle" => "$totalNotes notes · $subjectsCount subjects · A little more clarity",
        "backup_status_text" => "All notes backed up · Updated just now",
        "count" => count($notes),
        "notes" => $notes
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while fetching notes: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
