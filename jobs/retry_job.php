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
$userId = $authUser ? (int)$authUser["id"] : filter_var($data["user_id"] ?? $data["userId"] ?? $_GET["user_id"] ?? null, FILTER_VALIDATE_INT);

if (!$userId || $userId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid user_id or Authorization Bearer token is required."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$jobId = filter_var($data["job_id"] ?? $data["jobId"] ?? $_GET["job_id"] ?? null, FILTER_VALIDATE_INT);
if (!$jobId || $jobId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid 'job_id' is required to retry processing."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$runSync = isset($data["run_sync"]) ? (bool)$data["run_sync"] : (isset($data["sync"]) ? (bool)$data["sync"] : false);

try {
    $stmt = $conn->prepare("SELECT * FROM lecture_processing_jobs WHERE id = ?");
    $stmt->execute([$jobId]);
    $job = $stmt->fetch();

    if (!$job) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Processing job not found for ID: " . $jobId
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ((int)$job["user_id"] !== $userId) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "Forbidden: You are not authorized to retry this job."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Reset status to PENDING
    $resetStmt = $conn->prepare("
        UPDATE lecture_processing_jobs
        SET status = 'PENDING', progress_percent = 5, current_step = 'Restarting processing...', error_message = NULL, updated_at = NOW()
        WHERE id = ?
    ");
    $resetStmt->execute([$jobId]);

    if ($runSync) {
        $result = runLectureProcessingPipeline($conn, $jobId);
        echo json_encode([
            "success" => true,
            "message" => "Job retried and completed successfully",
            "job_id" => $jobId,
            "pipeline" => $result
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Launch background runner
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

    echo json_encode([
        "success" => true,
        "message" => "Job reset and restarted in background",
        "job_id" => $jobId,
        "status" => "PENDING",
        "progress_percent" => 5
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error retrying job: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

