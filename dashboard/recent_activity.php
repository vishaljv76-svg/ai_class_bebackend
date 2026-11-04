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
        "message" => "Access denied. You do not have permission to view this activity."
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

$limit = filter_var($_GET["limit"] ?? 10, FILTER_VALIDATE_INT) ?: 10;
if ($limit < 1) $limit = 1;
if ($limit > 50) $limit = 50;

try {
    // Verify user exists
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

    $activities = [];
    $today = date("Y-m-d");

    // 1. Check if dedicated activities table exists
    $tableCheck = $conn->query("SHOW TABLES LIKE 'activities'");
    if ($tableCheck && $tableCheck->rowCount() > 0) {
        $stmt = $conn->prepare("SELECT * FROM activities WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            $createdTime = strtotime($row["created_at"]);
            $activities[] = [
                "id" => (string)$row["id"],
                "type" => $row["activity_type"] ?? $row["type"] ?? "activity",
                "title" => $row["title"] ?? "Activity",
                "description" => $row["description"] ?? "",
                "timestamp" => $row["created_at"],
                "formatted_time" => date("d M Y, h:i A", $createdTime),
                "date_label" => (date("Y-m-d", $createdTime) === $today) ? "Today" : date("d M", $createdTime)
            ];
        }
    } else {
        // Derive real activities from actual database records
        $derived = [];

        // Notes activities
        $nStmt = $conn->prepare("SELECT id, title, subject, created_at FROM notes WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
        $nStmt->execute([$userId]);
        foreach ($nStmt->fetchAll() as $nRow) {
            $time = strtotime($nRow["created_at"]);
            $derived[] = [
                "id" => "note_" . $nRow["id"],
                "type" => "note_created",
                "title" => $nRow["title"],
                "description" => "Created note in " . $nRow["subject"],
                "timestamp" => $nRow["created_at"],
                "formatted_time" => date("d M Y, h:i A", $time),
                "date_label" => (date("Y-m-d", $time) === $today) ? "Today" : date("d M", $time)
            ];
        }

        // Quizzes activities
        $qStmt = $conn->prepare("SELECT id, title, subject, score_percentage, created_at FROM quizzes WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
        $qStmt->execute([$userId]);
        foreach ($qStmt->fetchAll() as $qRow) {
            $time = strtotime($qRow["created_at"]);
            $derived[] = [
                "id" => "quiz_" . $qRow["id"],
                "type" => "quiz_completed",
                "title" => $qRow["title"],
                "description" => "Completed quiz with score " . $qRow["score_percentage"] . "%",
                "timestamp" => $qRow["created_at"],
                "formatted_time" => date("d M Y, h:i A", $time),
                "date_label" => (date("Y-m-d", $time) === $today) ? "Today" : date("d M", $time)
            ];
        }

        // Profile update activity
        $pStmt = $conn->prepare("SELECT id, course, department, created_at FROM student_profiles WHERE user_id = ?");
        $pStmt->execute([$userId]);
        if ($pRow = $pStmt->fetch()) {
            $time = strtotime($pRow["created_at"]);
            $derived[] = [
                "id" => "profile_" . $pRow["id"],
                "type" => "profile_updated",
                "title" => "Profile Updated",
                "description" => "Updated student profile details" . (!empty($pRow["course"]) ? " (" . $pRow["course"] . ")" : ""),
                "timestamp" => $pRow["created_at"],
                "formatted_time" => date("d M Y, h:i A", $time),
                "date_label" => (date("Y-m-d", $time) === $today) ? "Today" : date("d M", $time)
            ];
        }

        // Audio upload activity
        $audioTableCheck = $conn->query("SHOW TABLES LIKE 'audio_files'");
        if ($audioTableCheck && $audioTableCheck->rowCount() > 0) {
            $aStmt = $conn->prepare("SELECT id, title, file_name, created_at FROM audio_files WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
            $aStmt->execute([$userId]);
            foreach ($aStmt->fetchAll() as $aRow) {
                $time = strtotime($aRow["created_at"]);
                $derived[] = [
                    "id" => "audio_" . $aRow["id"],
                    "type" => "audio_uploaded",
                    "title" => "Audio Lecture Uploaded",
                    "description" => "Uploaded audio: " . ($aRow["title"] ?: $aRow["file_name"]),
                    "timestamp" => $aRow["created_at"],
                    "formatted_time" => date("d M Y, h:i A", $time),
                    "date_label" => (date("Y-m-d", $time) === $today) ? "Today" : date("d M", $time)
                ];
            }
        }

        usort($derived, function ($a, $b) {
            return strtotime($b["timestamp"]) - strtotime($a["timestamp"]);
        });

        $activities = array_slice($derived, 0, $limit);
    }

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Recent activities retrieved successfully",
        "user_id" => $userId,
        "count" => count($activities),
        "activities" => $activities
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while fetching activities: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
