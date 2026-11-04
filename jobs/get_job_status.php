<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../common/auth_middleware.php";
require_once __DIR__ . "/process_lecture_pipeline.php";

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

$authUser = getAuthenticatedUser($conn, false);
$userId = $authUser ? (int)$authUser["id"] : filter_var($_GET["user_id"] ?? $_GET["userId"] ?? null, FILTER_VALIDATE_INT);

if (!$userId || $userId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid user_id or Authorization Bearer token is required."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$jobId = filter_var($_GET["job_id"] ?? $_GET["jobId"] ?? $_GET["id"] ?? null, FILTER_VALIDATE_INT);
$audioId = filter_var($_GET["audio_id"] ?? $_GET["audioId"] ?? null, FILTER_VALIDATE_INT);

if (!$jobId && !$audioId) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Either 'job_id' or 'audio_id' is required to check job status."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $job = null;
    if ($jobId) {
        $stmt = $conn->prepare("SELECT * FROM lecture_processing_jobs WHERE id = ?");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch();
    } elseif ($audioId) {
        $stmt = $conn->prepare("SELECT * FROM lecture_processing_jobs WHERE audio_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$audioId, $userId]);
        $job = $stmt->fetch();
    }

    if (!$job) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "No processing job found for the requested parameter."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Verify ownership
    if ((int)$job["user_id"] !== $userId) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "Forbidden: You are not authorized to view this processing job."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // If job was created in PENDING status and hasn't started yet (or trigger requested), advance it
    if ($job["status"] === "PENDING" && (isset($_GET["advance"]) || isset($_GET["step"]))) {
        runLectureProcessingPipeline($conn, (int)$job["id"]);
        // Reload job record
        $stmt = $conn->prepare("SELECT * FROM lecture_processing_jobs WHERE id = ?");
        $stmt->execute([$job["id"]]);
        $job = $stmt->fetch();
    }

    // Fetch Note details if available
    $noteData = null;
    if (!empty($job["note_id"])) {
        $nStmt = $conn->prepare("
            SELECT id, title, subject, course_code, tag, professor, duration, read_time,
                   summary, full_content, key_takeaways, important_terms,
                   section1_title, section1_content, section2_title, section2_bullets,
                   callout_title, callout_content, section3_title, section3_content1,
                   section3_content2, footer_text, ai_service_used, is_bookmarked, created_at
            FROM notes WHERE id = ?
        ");
        $nStmt->execute([$job["note_id"]]);
        $note = $nStmt->fetch();
        if ($note) {
            $noteData = [
                "id" => (int)$note["id"],
                "title" => $note["title"],
                "subject" => $note["subject"],
                "course_code" => $note["course_code"],
                "tag" => $note["tag"],
                "professor" => $note["professor"],
                "duration" => $note["duration"],
                "read_time" => $note["read_time"],
                "summary" => $note["summary"],
                "full_content" => $note["full_content"],
                "key_takeaways" => json_decode($note["key_takeaways"] ?? "[]", true) ?: [],
                "important_terms" => json_decode($note["important_terms"] ?? "[]", true) ?: [],
                "section1_title" => $note["section1_title"],
                "section1_content" => $note["section1_content"],
                "section2_title" => $note["section2_title"],
                "section2_bullets" => json_decode($note["section2_bullets"] ?? "[]", true) ?: [],
                "callout_title" => $note["callout_title"],
                "callout_content" => $note["callout_content"],
                "section3_title" => $note["section3_title"],
                "section3_content1" => $note["section3_content1"],
                "section3_content2" => $note["section3_content2"],
                "footer_text" => $note["footer_text"],
                "ai_service_used" => $note["ai_service_used"],
                "is_bookmarked" => (bool)$note["is_bookmarked"]
            ];
        }
    }

    // Fetch Educational Resources if available
    $resources = [];
    if (!empty($job["note_id"])) {
        $rStmt = $conn->prepare("
            SELECT id, title, url, source, topic, snippet, is_verified, created_at
            FROM lecture_resources
            WHERE note_id = ? AND user_id = ?
            ORDER BY id ASC
        ");
        $rStmt->execute([$job["note_id"], $userId]);
        $resources = $rStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if (empty($resources) && !empty($job["resources_json"])) {
        $decoded = json_decode($job["resources_json"], true);
        if (is_array($decoded)) {
            $resources = $decoded;
        }
    }

    // Fetch Quiz details if available
    $quizData = null;
    if (!empty($job["quiz_id"])) {
        $qStmt = $conn->prepare("SELECT id, title, subject, total_questions, difficulty, question_type, ai_service_used FROM quizzes WHERE id = ?");
        $qStmt->execute([$job["quiz_id"]]);
        $quiz = $qStmt->fetch();
        if ($quiz) {
            $quizData = [
                "id" => (int)$quiz["id"],
                "title" => $quiz["title"],
                "subject" => $quiz["subject"],
                "total_questions" => (int)$quiz["total_questions"],
                "difficulty" => $quiz["difficulty"],
                "question_type" => $quiz["question_type"],
                "ai_service_used" => $quiz["ai_service_used"]
            ];
        }
    }

    echo json_encode([
        "success" => true,
        "job" => [
            "id" => (int)$job["id"],
            "user_id" => (int)$job["user_id"],
            "audio_id" => (int)$job["audio_id"],
            "transcription_id" => $job["transcription_id"] ? (int)$job["transcription_id"] : null,
            "note_id" => $job["note_id"] ? (int)$job["note_id"] : null,
            "quiz_id" => $job["quiz_id"] ? (int)$job["quiz_id"] : null,
            "status" => $job["status"],
            "progress_percent" => (int)$job["progress_percent"],
            "current_step" => $job["current_step"],
            "error_message" => $job["error_message"],
            "created_at" => $job["created_at"],
            "updated_at" => $job["updated_at"]
        ],
        "note" => $noteData,
        "resources" => $resources,
        "quiz" => $quizData
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error checking job status: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

