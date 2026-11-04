<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../common/auth_middleware.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed. POST method required."
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

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    $data = $_POST;
}

if (!is_array($data) || empty($data)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Invalid or empty JSON payload."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$authUser = getAuthenticatedUser($conn, false);

$userId = null;
if ($authUser) {
    $userId = (int)$authUser["id"];
} else {
    $userId = filter_var($data["userId"] ?? $data["user_id"] ?? null, FILTER_VALIDATE_INT);
}

if (!$userId || $userId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid user_id or Authorization Bearer token is required."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$audioId = filter_var($data["audioId"] ?? $data["audio_id"] ?? null, FILTER_VALIDATE_INT);

if (!$audioId || $audioId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid audio_id is required to link transcription."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$fullTranscript = trim($data["fullTranscript"] ?? $data["full_transcript"] ?? $data["text"] ?? "");

if ($fullTranscript === "") {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Transcription text cannot be empty."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 1. Verify audio file exists and belongs to this user
    $audioStmt = $conn->prepare("SELECT id, user_id, title, file_name, duration FROM audio_files WHERE id = ?");
    $audioStmt->execute([$audioId]);
    $audio = $audioStmt->fetch();

    if (!$audio) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Audio record not found for ID: " . $audioId
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($authUser && (int)$audio["user_id"] !== (int)$authUser["id"]) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "You are not authorized to create a transcription for another user's audio file."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $lectureTitle = trim($data["lectureTitle"] ?? $data["title"] ?? ($audio["title"] ?: pathinfo($audio["file_name"], PATHINFO_FILENAME)));
    $language = trim($data["language"] ?? "English");
    $duration = trim($data["duration"] ?? ($audio["duration"] ?: "42:18"));
    $confidence = isset($data["confidence"]) ? (float)$data["confidence"] : 98.0;
    $serviceUsed = trim($data["serviceUsed"] ?? $data["service_used"] ?? "Manual");

    // Paragraphs formatting
    $paragraphs = $data["paragraphs"] ?? [];
    if (empty($paragraphs)) {
        // Automatically segment paragraphs by double newline or sentence boundaries
        $blocks = preg_split('/\n\s*\n/', $fullTranscript);
        foreach ($blocks as $i => $block) {
            $timeLabel = sprintf("%02d:00", $i * 2);
            $paragraphs[] = [
                "timestamp" => $timeLabel,
                "speaker" => "Speaker",
                "text" => trim($block)
            ];
        }
    }
    $paragraphsJson = json_encode($paragraphs, JSON_UNESCAPED_UNICODE);

    // Save transcription in MySQL
    $insStmt = $conn->prepare(
        "INSERT INTO transcriptions (
            user_id, audio_id, lecture_title, language, duration, confidence, full_transcript, paragraphs_json, status, service_used
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?)"
    );

    $insStmt->execute([
        $userId,
        $audioId,
        $lectureTitle,
        $language,
        $duration,
        $confidence,
        $fullTranscript,
        $paragraphsJson,
        $serviceUsed
    ]);

    $transcriptionId = (int)$conn->lastInsertId();

    http_response_code(201);
    echo json_encode([
        "success" => true,
        "message" => "Transcription saved to database successfully",
        "transcription_id" => $transcriptionId,
        "transcription" => [
            "id" => (string)$transcriptionId,
            "user_id" => $userId,
            "audio_id" => $audioId,
            "lectureTitle" => $lectureTitle,
            "language" => $language,
            "durationAndConfidence" => "$duration · {$confidence}% confidence",
            "fullTranscript" => $fullTranscript,
            "paragraphs" => $paragraphs,
            "serviceUsed" => $serviceUsed,
            "createdAt" => date("Y-m-d H:i:s")
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while saving transcription: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

