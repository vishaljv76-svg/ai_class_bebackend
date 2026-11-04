<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../common/auth_middleware.php";
require_once __DIR__ . "/process_lecture_pipeline.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed. POST method required."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Normalize Authorization header
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
$userId = $authUser ? (int)$authUser["id"] : filter_var($data["user_id"] ?? $data["userId"] ?? null, FILTER_VALIDATE_INT);

if (!$userId || $userId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid user_id or Authorization Bearer token is required."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$audioId = filter_var($data["audio_id"] ?? $data["audioId"] ?? null, FILTER_VALIDATE_INT);
if (!$audioId || $audioId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid 'audio_id' is required to start lecture processing."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$runSync = isset($data["run_sync"]) ? (bool)$data["run_sync"] : (isset($data["sync"]) ? (bool)$data["sync"] : false);

try {
    // 1. Verify user exists
    $uStmt = $conn->prepare("SELECT id, name FROM users WHERE id = ?");
    $uStmt->execute([$userId]);
    $user = $uStmt->fetch();

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "User not found for ID: " . $userId
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. Verify audio file belongs to user
    $aStmt = $conn->prepare("SELECT id, user_id, title, file_path, file_name FROM audio_files WHERE id = ?");
    $aStmt->execute([$audioId]);
    $audio = $aStmt->fetch();

    if (!$audio) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Audio record not found for ID: " . $audioId
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ((int)$audio["user_id"] !== $userId) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "Forbidden: You are not authorized to process this audio file."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 3. Check for existing active job
    $checkStmt = $conn->prepare("
        SELECT id, status, progress_percent, current_step
        FROM lecture_processing_jobs
        WHERE audio_id = ? AND user_id = ? AND status NOT IN ('COMPLETED', 'FAILED')
        ORDER BY id DESC LIMIT 1
    ");
    $checkStmt->execute([$audioId, $userId]);
    $activeJob = $checkStmt->fetch();

    if ($activeJob) {
        $jobId = (int)$activeJob["id"];
        if ($runSync) {
            $pipelineResult = runLectureProcessingPipeline($conn, $jobId);
            echo json_encode([
                "success" => true,
                "message" => "Lecture processing completed",
                "job_id" => $jobId,
                "pipeline" => $pipelineResult
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode([
            "success" => true,
            "message" => "Lecture processing is already in progress",
            "job_id" => $jobId,
            "status" => $activeJob["status"],
            "progress_percent" => (int)$activeJob["progress_percent"],
            "current_step" => $activeJob["current_step"]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 4. Create new job record
    $insStmt = $conn->prepare("
        INSERT INTO lecture_processing_jobs (
            user_id, audio_id, status, progress_percent, current_step
        ) VALUES (?, ?, 'PENDING', 5, 'Queued for processing')
    ");
    $insStmt->execute([$userId, $audioId]);
    $jobId = (int)$conn->lastInsertId();

    // 5. Execute pipeline
    if ($runSync) {
        $pipelineResult = runLectureProcessingPipeline($conn, $jobId);
        http_response_code(200);
        echo json_encode([
            "success" => true,
            "message" => "Lecture processing pipeline completed successfully",
            "job_id" => $jobId,
            "pipeline" => $pipelineResult
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Launch background process
    $phpExe = "C:\\xampp\\php\\php.exe";
    if (!file_exists($phpExe)) {
        $phpExe = "php";
    }
    $runnerScript = __DIR__ . "/process_lecture_pipeline.php";

    if (strtoupper(substr(PHP_OS, 0, 3)) === "WIN") {
        $bgCmd = "start /B " . escapeshellarg($phpExe) . " " . escapeshellarg($runnerScript) . " " . intval($jobId) . " > NUL 2>&1";
        pclose(popen($bgCmd, "r"));
    } else {
        $bgCmd = escapeshellarg($phpExe) . " " . escapeshellarg($runnerScript) . " " . intval($jobId) . " > /dev/null 2>&1 &";
        exec($bgCmd);
    }

    http_response_code(201);
    echo json_encode([
        "success" => true,
        "message" => "Lecture processing pipeline started successfully in background",
        "job_id" => $jobId,
        "audio_id" => $audioId,
        "status" => "PENDING",
        "progress_percent" => 5,
        "current_step" => "Queued for processing"
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while starting lecture processing: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
