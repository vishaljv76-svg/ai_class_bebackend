<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../common/auth_middleware.php";

// Allow only GET method
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

// Authenticate via token if present, or fallback to query parameter
$authUser = getAuthenticatedUser($conn, false);
$userId = filter_var($_GET["user_id"] ?? $_GET["userId"] ?? null, FILTER_VALIDATE_INT);

if (!$userId && $authUser) {
    $userId = (int)$authUser["id"];
}

if ($authUser && $userId && (int)$authUser["id"] !== $userId) {
    http_response_code(403);
    echo json_encode([
        "success" => false,
        "message" => "Access denied. You do not have permission to view this dashboard."
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

try {
    // 1. Fetch user account and profile information
    $userStmt = $conn->prepare(
        "SELECT 
            u.id AS user_id,
            u.name,
            u.email,
            p.id AS profile_id,
            p.course,
            p.department,
            p.semester,
            p.profile_image
         FROM users u
         LEFT JOIN student_profiles p ON u.id = p.user_id
         WHERE u.id = ?"
    );
    $userStmt->execute([$userId]);
    $userRow = $userStmt->fetch();

    if (!$userRow) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "User account not found for ID: " . $userId
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $userName = $userRow["name"];
    $userEmail = $userRow["email"];
    $initials = getInitials($userName);
    $hasProfile = !empty($userRow["profile_id"]);
    $sem = ($userRow["semester"] !== null && $userRow["semester"] !== "") ? (int)$userRow["semester"] : null;
    $profileImage = $userRow["profile_image"] ?: null;

    $profileData = null;
    if ($hasProfile) {
        $profileData = [
            "id" => (int)$userRow["profile_id"],
            "user_id" => (int)$userRow["user_id"],
            "course" => $userRow["course"] ?? "",
            "department" => $userRow["department"] ?? "",
            "semester" => $sem,
            "semester_badge" => $sem ? "Semester " . $sem : "",
            "year_badge" => getYearBadge($sem),
            "profile_image" => $profileImage
        ];
    }

    // 2. Real Calculations for Notes and Subjects
    // Calculate total notes created by this user
    $noteStmt = $conn->prepare("SELECT COUNT(*) FROM notes WHERE user_id = ?");
    $noteStmt->execute([$userId]);
    $totalNotes = (int)$noteStmt->fetchColumn();

    // Calculate distinct subjects from user's notes and enrolled subjects
    $subStmt = $conn->prepare("
        SELECT COUNT(DISTINCT subject_name) FROM (
            SELECT subject AS subject_name FROM notes WHERE user_id = ? AND subject IS NOT NULL AND TRIM(subject) != ''
            UNION
            SELECT s.name AS subject_name FROM user_subjects us JOIN subjects s ON us.subject_id = s.id WHERE us.user_id = ?
        ) AS all_subs
    ");
    $subStmt->execute([$userId, $userId]);
    $subjectsCount = (int)$subStmt->fetchColumn();

    // 3. Real Calculation for Quizzes
    $quizStmt = $conn->prepare("SELECT COUNT(*) FROM quizzes WHERE user_id = ?");
    $quizStmt->execute([$userId]);
    $quizzesCount = (int)$quizStmt->fetchColumn();

    // 4. Fetch Recently Created Notes (real notes from MySQL)
    $notesLimit = filter_var($_GET["notes_limit"] ?? 5, FILTER_VALIDATE_INT) ?: 5;
    if ($notesLimit < 1) $notesLimit = 1;
    if ($notesLimit > 20) $notesLimit = 20;

    $recStmt = $conn->prepare(
        "SELECT id, title, subject, course_code, tag, professor, duration, read_time, icon_type, summary, is_bookmarked, created_at 
         FROM notes 
         WHERE user_id = ? 
         ORDER BY created_at DESC, id DESC 
         LIMIT ?"
    );
    $recStmt->bindValue(1, $userId, PDO::PARAM_INT);
    $recStmt->bindValue(2, $notesLimit, PDO::PARAM_INT);
    $recStmt->execute();
    $recNotes = $recStmt->fetchAll();

    $recentNotes = [];
    $today = date("Y-m-d");
    $yesterday = date("Y-m-d", strtotime("-1 day"));

    foreach ($recNotes as $r) {
        $createdTime = strtotime($r["created_at"]);
        $noteDate = date("Y-m-d", $createdTime);

        if ($noteDate === $today) {
            $dateLabel = "Today";
        } elseif ($noteDate === $yesterday) {
            $dateLabel = "Yesterday";
        } else {
            $dateLabel = date("d M", $createdTime);
        }

        // Icon type strictly mapped for mobile app: Math or Database
        $iconType = (stripos($r["subject"], "Math") !== false || strcasecmp($r["icon_type"] ?? "", "Math") === 0) 
            ? "Math" 
            : "Database";

        $recentNotes[] = [
            "id" => (string)$r["id"],
            "title" => $r["title"],
            "subject" => $r["subject"],
            "course_code" => $r["course_code"] ?? "",
            "tag" => $r["tag"] ?? "AI notes",
            "date" => $dateLabel,
            "duration" => !empty($r["duration"]) ? $r["duration"] : "40 min",
            "read_time" => !empty($r["read_time"]) ? $r["read_time"] : "5 min read",
            "iconType" => $iconType,
            "summary" => $r["summary"] ?? "",
            "is_bookmarked" => (bool)$r["is_bookmarked"],
            "created_at" => $r["created_at"]
        ];
    }

    // 5. Fetch Recent Activities (if activity records exist in database)
    $activitiesLimit = filter_var($_GET["activities_limit"] ?? 10, FILTER_VALIDATE_INT) ?: 10;
    if ($activitiesLimit < 1) $activitiesLimit = 1;
    if ($activitiesLimit > 20) $activitiesLimit = 20;

    $recentActivities = [];

    // Check if a dedicated activities table exists
    $tableCheck = $conn->query("SHOW TABLES LIKE 'activities'");
    if ($tableCheck && $tableCheck->rowCount() > 0) {
        $actStmt = $conn->prepare("SELECT * FROM activities WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
        $actStmt->bindValue(1, $userId, PDO::PARAM_INT);
        $actStmt->bindValue(2, $activitiesLimit, PDO::PARAM_INT);
        $actStmt->execute();
        $actRows = $actStmt->fetchAll();
        foreach ($actRows as $act) {
            $actTime = strtotime($act["created_at"]);
            $recentActivities[] = [
                "id" => (string)$act["id"],
                "type" => $act["activity_type"] ?? $act["type"] ?? "activity",
                "title" => $act["title"] ?? "Activity",
                "description" => $act["description"] ?? "",
                "timestamp" => $act["created_at"],
                "formatted_time" => date("d M Y, h:i A", $actTime),
                "date_label" => (date("Y-m-d", $actTime) === $today) ? "Today" : date("d M", $actTime)
            ];
        }
    } else {
        // Derive real chronological activity events from existing MySQL records for this user
        $derived = [];

        // Note creation activities
        $nActStmt = $conn->prepare("SELECT id, title, subject, created_at FROM notes WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
        $nActStmt->execute([$userId]);
        foreach ($nActStmt->fetchAll() as $nRow) {
            $actTime = strtotime($nRow["created_at"]);
            $derived[] = [
                "id" => "note_" . $nRow["id"],
                "type" => "note_created",
                "title" => $nRow["title"],
                "description" => "Created note in " . $nRow["subject"],
                "timestamp" => $nRow["created_at"],
                "formatted_time" => date("d M Y, h:i A", $actTime),
                "date_label" => (date("Y-m-d", $actTime) === $today) ? "Today" : date("d M", $actTime)
            ];
        }

        // Quiz completion activities
        $qActStmt = $conn->prepare("SELECT id, title, subject, score_percentage, created_at FROM quizzes WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
        $qActStmt->execute([$userId]);
        foreach ($qActStmt->fetchAll() as $qRow) {
            $actTime = strtotime($qRow["created_at"]);
            $derived[] = [
                "id" => "quiz_" . $qRow["id"],
                "type" => "quiz_completed",
                "title" => $qRow["title"],
                "description" => "Completed quiz with score " . $qRow["score_percentage"] . "%",
                "timestamp" => $qRow["created_at"],
                "formatted_time" => date("d M Y, h:i A", $actTime),
                "date_label" => (date("Y-m-d", $actTime) === $today) ? "Today" : date("d M", $actTime)
            ];
        }

        // Profile update activities
        $pActStmt = $conn->prepare("SELECT id, course, department, created_at FROM student_profiles WHERE user_id = ?");
        $pActStmt->execute([$userId]);
        if ($pRow = $pActStmt->fetch()) {
            $actTime = strtotime($pRow["created_at"]);
            $derived[] = [
                "id" => "profile_" . $pRow["id"],
                "type" => "profile_updated",
                "title" => "Profile Updated",
                "description" => "Updated student profile details" . (!empty($pRow["course"]) ? " (" . $pRow["course"] . ")" : ""),
                "timestamp" => $pRow["created_at"],
                "formatted_time" => date("d M Y, h:i A", $actTime),
                "date_label" => (date("Y-m-d", $actTime) === $today) ? "Today" : date("d M", $actTime)
            ];
        }

        // Audio upload activities
        $audioTableCheck = $conn->query("SHOW TABLES LIKE 'audio_files'");
        if ($audioTableCheck && $audioTableCheck->rowCount() > 0) {
            $aActStmt = $conn->prepare("SELECT id, title, file_name, created_at FROM audio_files WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
            $aActStmt->execute([$userId]);
            foreach ($aActStmt->fetchAll() as $aRow) {
                $actTime = strtotime($aRow["created_at"]);
                $derived[] = [
                    "id" => "audio_" . $aRow["id"],
                    "type" => "audio_uploaded",
                    "title" => "Audio Lecture Uploaded",
                    "description" => "Uploaded audio: " . ($aRow["title"] ?: $aRow["file_name"]),
                    "timestamp" => $aRow["created_at"],
                    "formatted_time" => date("d M Y, h:i A", $actTime),
                    "date_label" => (date("Y-m-d", $actTime) === $today) ? "Today" : date("d M", $actTime)
                ];
            }
        }

        // Sort derived activities descending by timestamp
        usort($derived, function ($a, $b) {
            return strtotime($b["timestamp"]) - strtotime($a["timestamp"]);
        });

        $recentActivities = array_slice($derived, 0, $activitiesLimit);
    }

    $dateText = date("l, d F");

    // Optional study progress & streak enrichment
    $progStmt = $conn->prepare("SELECT overall_progress_percentage, study_streak_days, average_quiz_score, mastery_level FROM user_study_progress WHERE user_id = ?");
    $progStmt->execute([$userId]);
    $progRow = $progStmt->fetch(PDO::FETCH_ASSOC);

    // Return structured JSON response matching Android app specifications and enriched schema
    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Dashboard data loaded successfully",
        // Direct compatibility fields for Android DashboardUiState & DashboardRepository
        "userName" => $userName,
        "userInitials" => $initials,
        "dateText" => $dateText,
        "totalNotes" => $totalNotes,
        "subjectsCount" => $subjectsCount,
        "quizzesCount" => $quizzesCount,
        "recentNotes" => $recentNotes,
        "studyProgress" => [
            "overallProgressPercentage" => (int)($progRow["overall_progress_percentage"] ?? 0),
            "studyStreakDays" => (int)($progRow["study_streak_days"] ?? 0),
            "averageQuizScore" => (float)($progRow["average_quiz_score"] ?? 0.0),
            "masteryLevel" => $progRow["mastery_level"] ?? "Beginner"
        ],
        // Detailed structures
        "user" => [
            "id" => (int)$userRow["user_id"],
            "name" => $userName,
            "email" => $userEmail,
            "initials" => $initials,
            "profile_image" => $profileImage
        ],
        "profile" => $profileData,
        "stats" => [
            "total_notes" => $totalNotes,
            "subjects_count" => $subjectsCount,
            "quizzes_count" => $quizzesCount
        ],
        "recentActivities" => $recentActivities,
        "hasRecentActivities" => count($recentActivities) > 0
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while loading dashboard: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
