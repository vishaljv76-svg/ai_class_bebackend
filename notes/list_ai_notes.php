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

if (!$userId || $userId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid user_id or Authorization Bearer token is required."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$search = trim($_GET["search"] ?? $_GET["query"] ?? $_GET["q"] ?? "");
$subjectFilter = trim($_GET["subject"] ?? $_GET["category"] ?? "");
$transcriptionId = filter_var($_GET["transcription_id"] ?? $_GET["transcriptionId"] ?? null, FILTER_VALIDATE_INT);

try {
    // 1. Verify user exists in database
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

    // 2. Build SQL query
    $sql = "
        SELECT n.id, n.user_id, n.transcription_id, n.title, n.subject, n.course_code, n.tag,
               n.professor, n.duration, n.read_time, n.icon_type, n.summary, n.key_takeaways,
               n.important_terms, n.ai_service_used, n.is_bookmarked, n.created_at, n.updated_at,
               t.lecture_title AS transcription_title,
               t.language AS transcription_language,
               a.id AS audio_id,
               a.file_name AS audio_file_name,
               a.title AS audio_title
        FROM notes n
        LEFT JOIN transcriptions t ON n.transcription_id = t.id
        LEFT JOIN audio_files a ON t.audio_id = a.id
        WHERE n.user_id = ? AND (n.transcription_id IS NOT NULL OR n.tag = 'AI notes')
    ";
    $params = [$userId];

    if ($transcriptionId && $transcriptionId > 0) {
        $sql .= " AND n.transcription_id = ?";
        $params[] = $transcriptionId;
    }

    if ($subjectFilter !== "" && strcasecmp($subjectFilter, "all") !== 0) {
        $sql .= " AND n.subject = ?";
        $params[] = $subjectFilter;
    }

    if ($search !== "") {
        $sql .= " AND (
            n.title LIKE ? OR 
            n.subject LIKE ? OR 
            n.summary LIKE ? OR 
            n.full_content LIKE ? OR 
            n.key_takeaways LIKE ? OR 
            n.important_terms LIKE ? OR 
            t.lecture_title LIKE ?
        )";
        $wildcard = "%" . $search . "%";
        for ($i = 0; $i < 7; $i++) {
            $params[] = $wildcard;
        }
    }

    $sql .= " ORDER BY n.created_at DESC, n.id DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $aiNotes = [];
    $today = date("Y-m-d");
    $yesterday = date("Y-m-d", strtotime("-1 day"));

    foreach ($rows as $row) {
        $createdTime = strtotime($row["created_at"]);
        $noteDate = date("Y-m-d", $createdTime);

        if ($noteDate === $today) {
            $dateText = "Today";
        } elseif ($noteDate === $yesterday) {
            $dateText = "Yesterday";
        } else {
            $dateText = date("d M Y", $createdTime);
        }

        // Decode key takeaways
        $keyTakeaways = [];
        if (!empty($row["key_takeaways"])) {
            $dec = json_decode($row["key_takeaways"], true);
            $keyTakeaways = is_array($dec) ? $dec : array_values(array_filter(array_map('trim', explode("\n", $row["key_takeaways"]))));
        }

        // Decode important terms (keywords)
        $importantTerms = [];
        if (!empty($row["important_terms"])) {
            $dec = json_decode($row["important_terms"], true);
            $importantTerms = is_array($dec) ? $dec : array_values(array_filter(array_map('trim', explode(",", $row["important_terms"]))));
        }

        $iconType = (stripos($row["subject"], "Math") !== false || strcasecmp($row["icon_type"] ?? "", "Math") === 0) 
            ? "Math" 
            : "Database";

        $aiNotes[] = [
            "id" => (string)$row["id"],
            "user_id" => (int)$row["user_id"],
            "transcription_id" => !empty($row["transcription_id"]) ? (int)$row["transcription_id"] : null,
            "audio_id" => !empty($row["audio_id"]) ? (int)$row["audio_id"] : null,
            "title" => $row["title"],
            "lectureTitle" => $row["title"],
            "subject" => $row["subject"],
            "courseCode" => $row["course_code"] ?: "CS301",
            "tag" => $row["tag"] ?: "AI notes",
            "dateText" => $dateText,
            "durationText" => !empty($row["duration"]) ? $row["duration"] : "42 min",
            "readTimeText" => !empty($row["read_time"]) ? $row["read_time"] : "6 min read",
            "iconType" => $iconType,
            "summary" => $row["summary"] ?? "",
            "keyTakeaways" => $keyTakeaways,
            "importantTerms" => $importantTerms,
            "ai_service_used" => $row["ai_service_used"],
            "isBookmarked" => (bool)$row["is_bookmarked"],
            "linked_transcription_title" => $row["transcription_title"],
            "audio_file_name" => $row["audio_file_name"],
            "created_at" => $row["created_at"]
        ];
    }

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "user_id" => $userId,
        "count" => count($aiNotes),
        "notes" => $aiNotes
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while listing AI study notes: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

