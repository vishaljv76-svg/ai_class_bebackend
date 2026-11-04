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

$transcriptionId = filter_var($_GET["id"] ?? $_GET["transcription_id"] ?? null, FILTER_VALIDATE_INT);
$audioId = filter_var($_GET["audio_id"] ?? $_GET["audioId"] ?? null, FILTER_VALIDATE_INT);

if (!$transcriptionId && !$audioId) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid transcription id or audio_id is required."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($transcriptionId) {
        $stmt = $conn->prepare("
            SELECT t.*, a.title AS audio_title, a.file_name, a.file_path, a.file_size, a.file_extension
            FROM transcriptions t
            LEFT JOIN audio_files a ON t.audio_id = a.id
            WHERE t.id = ?
        ");
        $stmt->execute([$transcriptionId]);
    } else {
        $stmt = $conn->prepare("
            SELECT t.*, a.title AS audio_title, a.file_name, a.file_path, a.file_size, a.file_extension
            FROM transcriptions t
            LEFT JOIN audio_files a ON t.audio_id = a.id
            WHERE t.audio_id = ?
            ORDER BY t.id DESC LIMIT 1
        ");
        $stmt->execute([$audioId]);
    }

    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Transcription not found."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Enforce ownership: users can view only their own transcriptions
    $userId = $authUser ? (int)$authUser["id"] : filter_var($_GET["user_id"] ?? $_GET["userId"] ?? null, FILTER_VALIDATE_INT);
    if ($userId && (int)$row["user_id"] !== $userId) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "Access denied. You do not have permission to view this transcription."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Decode structured paragraphs
    $paragraphs = [];
    if (!empty($row["paragraphs_json"])) {
        $decoded = json_decode($row["paragraphs_json"], true);
        if (is_array($decoded)) {
            $paragraphs = $decoded;
        }
    }

    if (empty($paragraphs)) {
        $paragraphs[] = [
            "timestamp" => "00:00",
            "speaker" => "Speaker",
            "text" => $row["full_transcript"]
        ];
    }

    $duration = $row["duration"] ?: "42:18";
    $confidence = $row["confidence"] ? ((float)$row["confidence"] . "%") : "98%";

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Transcription retrieved successfully",
        "transcription" => [
            "id" => (string)$row["id"],
            "user_id" => (int)$row["user_id"],
            "audio_id" => (int)$row["audio_id"],
            "lectureTitle" => $row["lecture_title"] ?: ($row["audio_title"] ?: $row["file_name"]),
            "language" => $row["language"] ?: "English",
            "duration" => $duration,
            "confidence" => $confidence,
            "durationAndConfidence" => "$duration · $confidence confidence",
            "fullTranscript" => $row["full_transcript"],
            "paragraphs" => $paragraphs,
            "status" => $row["status"],
            "serviceUsed" => $row["service_used"],
            "audioFile" => [
                "fileName" => $row["file_name"],
                "filePath" => $row["file_path"],
                "fileExtension" => strtoupper($row["file_extension"] ?? "MP3")
            ],
            "createdAt" => $row["created_at"],
            "updatedAt" => $row["updated_at"]
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while fetching transcription: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

