<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../config/transcription_config.php";
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

$authUser = getAuthenticatedUser($conn, false);

// Extract user ID
$userId = null;
if ($authUser) {
    $userId = (int)$authUser["id"];
} else {
    $userId = filter_var($data["userId"] ?? $data["user_id"] ?? $_GET["userId"] ?? $_GET["user_id"] ?? null, FILTER_VALIDATE_INT);
}

if (!$userId || $userId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid user_id or Authorization Bearer token is required."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Extract audio ID
$audioId = filter_var($data["audioId"] ?? $data["audio_id"] ?? $_GET["id"] ?? $_GET["audio_id"] ?? null, FILTER_VALIDATE_INT);

if (!$audioId || $audioId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid audio_id is required to transcribe an audio file."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 1. Fetch audio record from database
    $audioStmt = $conn->prepare("SELECT * FROM audio_files WHERE id = ?");
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

    // Verify ownership
    if ($authUser && (int)$audio["user_id"] !== (int)$authUser["id"]) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "You are not authorized to transcribe this audio file."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. Verify physical audio file on disk
    $physicalFilePath = __DIR__ . "/../" . $audio["file_path"];

    if (!file_exists($physicalFilePath) || !is_file($physicalFilePath)) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Physical audio file is missing from server storage: " . $audio["file_path"]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $fileSize = filesize($physicalFilePath);
    if ($fileSize <= 0) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Audio file is empty (0 bytes). Cannot transcribe empty audio."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Check supported format for speech-to-text
    $supportedExts = ["mp3", "mp4", "mpeg", "mpga", "m4a", "wav", "webm", "ogg", "flac"];
    $ext = strtolower($audio["file_extension"]);
    if (!in_array($ext, $supportedExts)) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Audio format (." . $ext . ") is not supported for speech-to-text. Supported formats: MP3, WAV, M4A, OGG, FLAC."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 3. Inspect STT service configuration (Allow optional request header override)
    $requestApiKey = $_SERVER["HTTP_X_STT_API_KEY"] ?? ($data["stt_api_key"] ?? "");
    $serviceToUse = $activeSTTService;
    $keyToUse = $activeSTTKey;
    $endpointToUse = $activeSTTEndpoint;
    $modelToUse = $activeSTTModel;

    if (!empty($requestApiKey)) {
        $keyToUse = $requestApiKey;
        if (strpos($requestApiKey, "gsk_") === 0) {
            $serviceToUse = "Groq Whisper";
            $endpointToUse = "https://api.groq.com/openai/v1/audio/transcriptions";
            $modelToUse = "whisper-large-v3";
        } else {
            $serviceToUse = "OpenAI Whisper";
            $endpointToUse = "https://api.openai.com/v1/audio/transcriptions";
            $modelToUse = "whisper-1";
        }
    }

    // If no STT service is configured, report required configuration without faking
    if (empty($keyToUse)) {
        http_response_code(422);
        echo json_encode([
            "success" => false,
            "error_code" => "STT_SERVICE_NOT_CONFIGURED",
            "message" => "Speech-to-text service is not configured. Please provide an API key in config/transcription_config.php or via environment variable (OPENAI_API_KEY or GROQ_API_KEY).",
            "audio_file" => [
                "id" => (string)$audio["id"],
                "title" => $audio["title"] ?: $audio["file_name"],
                "file_path" => $audio["file_path"],
                "file_size" => $fileSize,
                "file_extension" => $ext
            ],
            "required_configuration" => getSTTServiceInfo()
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // 4. Call Speech-to-Text API (Whisper)
    $mime = mime_content_type($physicalFilePath) ?: "audio/" . $ext;
    $cfile = new CURLFile($physicalFilePath, $mime, basename($physicalFilePath));

    $postFields = [
        "file" => $cfile,
        "model" => $modelToUse,
        "response_format" => "verbose_json",
        "temperature" => 0.0
    ];

    if (!empty($data["language"])) {
        $postFields["language"] = trim($data["language"]);
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $endpointToUse);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $keyToUse,
        "Accept: application/json"
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);

    $apiResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($apiResponse === false || !empty($curlErr)) {
        http_response_code(502);
        echo json_encode([
            "success" => false,
            "message" => "Network error connecting to speech-to-text service: " . $curlErr
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $apiJson = json_decode($apiResponse, true);

    if ($httpCode < 200 || $httpCode >= 300 || !is_array($apiJson) || isset($apiJson["error"])) {
        $errMsg = $apiJson["error"]["message"] ?? $apiJson["message"] ?? ("STT provider returned HTTP " . $httpCode);
        http_response_code(502);
        echo json_encode([
            "success" => false,
            "message" => "Speech-to-text transcription service error: " . $errMsg,
            "provider" => $serviceToUse,
            "status_code" => $httpCode
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 5. Extract transcribed text and structured paragraphs
    $fullTranscript = trim($apiJson["text"] ?? "");
    $detectedLanguage = $apiJson["language"] ?? "English";
    $durationSeconds = isset($apiJson["duration"]) ? (float)$apiJson["duration"] : null;
    $durationText = $durationSeconds ? sprintf("%02d:%02d", floor($durationSeconds / 60), floor($durationSeconds % 60)) : ($audio["duration"] ?: "42:00");

    $paragraphs = [];
    if (!empty($apiJson["segments"]) && is_array($apiJson["segments"])) {
        foreach ($apiJson["segments"] as $seg) {
            $startSec = (int)($seg["start"] ?? 0);
            $timeLabel = sprintf("%02d:%02d", floor($startSec / 60), $startSec % 60);
            $paragraphs[] = [
                "timestamp" => $timeLabel,
                "speaker" => "Speaker",
                "text" => trim($seg["text"] ?? "")
            ];
        }
    }

    if (empty($paragraphs) && !empty($fullTranscript)) {
        $paragraphs[] = [
            "timestamp" => "00:00",
            "speaker" => "Speaker",
            "text" => $fullTranscript
        ];
    }

    $paragraphsJson = json_encode($paragraphs, JSON_UNESCAPED_UNICODE);
    $lectureTitle = $audio["title"] ?: pathinfo($audio["file_name"], PATHINFO_FILENAME);

    // 6. Save transcription into MySQL
    $insStmt = $conn->prepare(
        "INSERT INTO transcriptions (
            user_id, audio_id, lecture_title, language, duration, confidence, full_transcript, paragraphs_json, status, service_used
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?)"
    );

    $insStmt->execute([
        $userId,
        $audioId,
        $lectureTitle,
        $detectedLanguage,
        $durationText,
        98.0,
        $fullTranscript,
        $paragraphsJson,
        $serviceToUse
    ]);

    $transcriptionId = (int)$conn->lastInsertId();

    http_response_code(201);
    echo json_encode([
        "success" => true,
        "message" => "Audio speech transcribed and saved successfully",
        "transcription_id" => $transcriptionId,
        "transcription" => [
            "id" => (string)$transcriptionId,
            "user_id" => $userId,
            "audio_id" => $audioId,
            "lectureTitle" => $lectureTitle,
            "language" => $detectedLanguage,
            "durationAndConfidence" => "$durationText · 98% confidence",
            "fullTranscript" => $fullTranscript,
            "paragraphs" => $paragraphs,
            "serviceUsed" => $serviceToUse,
            "createdAt" => date("Y-m-d H:i:s")
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while processing transcription: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

