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
        "message" => "Access denied. You do not have permission to view another user's transcriptions."
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

$search = trim($_GET["search"] ?? $_GET["query"] ?? "");
$limit = filter_var($_GET["limit"] ?? 50, FILTER_VALIDATE_INT) ?: 50;
if ($limit < 1) $limit = 1;
if ($limit > 100) $limit = 100;

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

    $sql = "SELECT t.id, t.user_id, t.audio_id, t.lecture_title, t.language, t.duration, t.confidence,
                   t.full_transcript, t.status, t.service_used, t.created_at, t.updated_at,
                   a.file_name, a.file_path, a.file_size, a.file_extension
            FROM transcriptions t
            LEFT JOIN audio_files a ON t.audio_id = a.id
            WHERE t.user_id = ?";
    $params = [$userId];

    if ($search !== "") {
        $sql .= " AND (t.lecture_title LIKE ? OR t.full_transcript LIKE ? OR a.file_name LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $sql .= " ORDER BY t.created_at DESC, t.id DESC LIMIT ?";

    $stmt = $conn->prepare($sql);
    foreach ($params as $idx => $param) {
        $stmt->bindValue($idx + 1, $param);
    }
    $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $transcriptions = [];
    $today = date("Y-m-d");
    $yesterday = date("Y-m-d", strtotime("-1 day"));

    foreach ($rows as $row) {
        $createdTime = strtotime($row["created_at"]);
        $dateStr = date("Y-m-d", $createdTime);

        if ($dateStr === $today) {
            $dateLabel = "Today, " . date("h:i A", $createdTime);
        } elseif ($dateStr === $yesterday) {
            $dateLabel = "Yesterday, " . date("h:i A", $createdTime);
        } else {
            $dateLabel = date("d M Y, h:i A", $createdTime);
        }

        $duration = $row["duration"] ?: "42:18";
        $confidence = $row["confidence"] ? ((float)$row["confidence"] . "%") : "98%";
        $previewText = mb_strlen($row["full_transcript"]) > 160 
            ? mb_substr($row["full_transcript"], 0, 157) . "..." 
            : $row["full_transcript"];

        $transcriptions[] = [
            "id" => (string)$row["id"],
            "user_id" => (int)$row["user_id"],
            "audio_id" => (int)$row["audio_id"],
            "lectureTitle" => $row["lecture_title"] ?: ($row["file_name"] ?: "Lecture Transcript"),
            "language" => $row["language"] ?: "English",
            "duration" => $duration,
            "confidence" => $confidence,
            "durationAndConfidence" => "$duration · $confidence confidence",
            "previewText" => $previewText,
            "status" => $row["status"] ?: "completed",
            "serviceUsed" => $row["service_used"] ?: "Speech-to-Text",
            "dateLabel" => $dateLabel,
            "audioFile" => [
                "fileName" => $row["file_name"],
                "filePath" => $row["file_path"],
                "fileExtension" => strtoupper($row["file_extension"] ?? "MP3")
            ],
            "createdAt" => $row["created_at"]
        ];
    }

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Transcriptions fetched successfully",
        "user_id" => $userId,
        "count" => count($transcriptions),
        "transcriptions" => $transcriptions
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while fetching transcriptions: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

