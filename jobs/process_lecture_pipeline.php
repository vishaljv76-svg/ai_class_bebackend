<?php
/**
 * SmartNotes AI - Real-Time Lecture Processing Pipeline Runner
 * 
 * Sequentially executes:
 * 1. Audio validation
 * 2. Speech-to-text transcription
 * 3. Structured AI notes generation
 * 4. MCQ quiz generation
 * 5. Educational learning resource discovery
 * 6. MySQL persistence and finalization
 * 
 * Can be run synchronously or via background CLI execution.
 */

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../config/transcription_config.php";
require_once __DIR__ . "/../config/ai_notes_config.php";
require_once __DIR__ . "/../config/quiz_config.php";
require_once __DIR__ . "/../services/educational_resources.php";

/**
 * Executes the complete real-time lecture processing pipeline for a job.
 *
 * @param PDO $conn
 * @param int $jobId
 * @param array $options Optional overrides (custom_key, run_async, etc.)
 * @return array Pipeline result
 */
function runLectureProcessingPipeline($conn, $jobId, $options = []) {
    // 1. Fetch job record
    $jobStmt = $conn->prepare("SELECT * FROM lecture_processing_jobs WHERE id = ?");
    $jobStmt->execute([$jobId]);
    $job = $jobStmt->fetch();

    if (!$job) {
        return [
            "success" => false,
            "error_code" => "JOB_NOT_FOUND",
            "message" => "Processing job not found for ID: " . $jobId
        ];
    }

    $userId = (int)$job["user_id"];
    $audioId = (int)$job["audio_id"];

    // Helper to update job status
    $updateJob = function($status, $progress, $step, $extra = []) use ($conn, $jobId) {
        $fields = ["status = ?", "progress_percent = ?", "current_step = ?"];
        $params = [$status, $progress, $step];

        foreach ($extra as $col => $val) {
            $fields[] = "$col = ?";
            $params[] = $val;
        }

        $params[] = $jobId;
        $sql = "UPDATE lecture_processing_jobs SET " . implode(", ", $fields) . ", updated_at = NOW() WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
    };

    try {
        // -------------------------------------------------------------
        // STAGE 1: Audio Validation
        // -------------------------------------------------------------
        $updateJob("UPLOADING", 10, "Verifying audio recording...");

        $audioStmt = $conn->prepare("SELECT * FROM audio_files WHERE id = ?");
        $audioStmt->execute([$audioId]);
        $audio = $audioStmt->fetch();

        if (!$audio) {
            $errMsg = "Audio file record not found for ID: " . $audioId;
            $updateJob("FAILED", 10, "Audio file missing", ["error_message" => $errMsg]);
            return ["success" => false, "message" => $errMsg];
        }

        if ((int)$audio["user_id"] !== $userId) {
            $errMsg = "Unauthorized: Audio does not belong to user ID: " . $userId;
            $updateJob("FAILED", 10, "Unauthorized audio access", ["error_message" => $errMsg]);
            return ["success" => false, "message" => $errMsg];
        }

        $physicalAudioPath = __DIR__ . "/../" . $audio["file_path"];
        if (!file_exists($physicalAudioPath) || filesize($physicalAudioPath) <= 0) {
            $errMsg = "Audio file is missing or empty on server storage: " . $audio["file_path"];
            $updateJob("FAILED", 10, "Storage file missing", ["error_message" => $errMsg]);
            return ["success" => false, "message" => $errMsg];
        }

        $lectureTitle = trim($audio["title"] ?: pathinfo($audio["file_name"], PATHINFO_FILENAME));
        $audioDuration = $audio["duration"] ?: "42:00";

        // -------------------------------------------------------------
        // STAGE 2: Speech-to-Text Transcription
        // -------------------------------------------------------------
        $updateJob("TRANSCRIBING", 25, "Transcribing lecture speech to text...");

        $transcriptionId = (int)($job["transcription_id"] ?? 0);
        $transcriptionRecord = null;

        // Check if transcription already exists for this audio
        if ($transcriptionId > 0) {
            $tStmt = $conn->prepare("SELECT * FROM transcriptions WHERE id = ?");
            $tStmt->execute([$transcriptionId]);
            $transcriptionRecord = $tStmt->fetch();
        }

        if (!$transcriptionRecord) {
            $tStmt = $conn->prepare("SELECT * FROM transcriptions WHERE audio_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1");
            $tStmt->execute([$audioId, $userId]);
            $transcriptionRecord = $tStmt->fetch();
            if ($transcriptionRecord) {
                $transcriptionId = (int)$transcriptionRecord["id"];
                $updateJob("TRANSCRIBING", 35, "Reusing existing transcript...", ["transcription_id" => $transcriptionId]);
            }
        }

        // If no transcription exists, run Whisper transcription if configured
        if (!$transcriptionRecord) {
            if (!isSTTConfigured()) {
                $errMsg = "STT_SERVICE_NOT_CONFIGURED: Speech-to-text service is not configured. Set GROQ_API_KEY or OPENAI_API_KEY in config/local_config.php.";
                $updateJob("FAILED", 25, "STT service not configured", ["error_message" => $errMsg]);
                return ["success" => false, "message" => $errMsg];
            }

            global $activeSTTService, $activeSTTKey, $activeSTTEndpoint, $activeSTTModel;
            $ext = strtolower($audio["file_extension"]);
            $mime = mime_content_type($physicalAudioPath) ?: "audio/" . $ext;
            $cfile = new CURLFile($physicalAudioPath, $mime, basename($physicalAudioPath));

            $postFields = [
                "file" => $cfile,
                "model" => $activeSTTModel,
                "response_format" => "verbose_json",
                "temperature" => 0.0
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $activeSTTEndpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer " . $activeSTTKey,
                "Accept: application/json"
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 120);

            $apiResponse = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($curlErr || $httpCode < 200 || $httpCode >= 300) {
                $errMsg = "Transcription API call failed (HTTP $httpCode): " . ($curlErr ?: $apiResponse);
                $updateJob("FAILED", 25, "Transcription failed", ["error_message" => $errMsg]);
                return ["success" => false, "message" => $errMsg];
            }

            $apiJson = json_decode($apiResponse, true);
            $fullTranscript = trim($apiJson["text"] ?? "");
            $detectedLang = $apiJson["language"] ?? "English";

            if (empty($fullTranscript)) {
                $errMsg = "Transcription API returned empty transcript text.";
                $updateJob("FAILED", 25, "Empty transcription", ["error_message" => $errMsg]);
                return ["success" => false, "message" => $errMsg];
            }

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
            } else {
                $paragraphs[] = ["timestamp" => "00:00", "speaker" => "Speaker", "text" => $fullTranscript];
            }

            $insStmt = $conn->prepare(
                "INSERT INTO transcriptions (
                    user_id, audio_id, lecture_title, language, duration, confidence, full_transcript, paragraphs_json, status, service_used
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?)"
            );
            $insStmt->execute([
                $userId,
                $audioId,
                $lectureTitle,
                $detectedLang,
                $audioDuration,
                98.0,
                $fullTranscript,
                json_encode($paragraphs, JSON_UNESCAPED_UNICODE),
                $activeSTTService
            ]);
            $transcriptionId = (int)$conn->lastInsertId();

            $tStmt = $conn->prepare("SELECT * FROM transcriptions WHERE id = ?");
            $tStmt->execute([$transcriptionId]);
            $transcriptionRecord = $tStmt->fetch();
        }

        $updateJob("TRANSCRIBING", 40, "Transcript ready", ["transcription_id" => $transcriptionId]);
        $fullTranscript = trim($transcriptionRecord["full_transcript"] ?? "");

        // -------------------------------------------------------------
        // STAGE 3: Structured AI Notes Generation
        // -------------------------------------------------------------
        $updateJob("GENERATING_NOTES", 50, "Generating structured notes, summaries & key takeaways...");

        $noteId = (int)($job["note_id"] ?? 0);
        $noteRecord = null;

        if ($noteId > 0) {
            $nStmt = $conn->prepare("SELECT * FROM notes WHERE id = ?");
            $nStmt->execute([$noteId]);
            $noteRecord = $nStmt->fetch();
        }

        if (!$noteRecord) {
            $nStmt = $conn->prepare("SELECT * FROM notes WHERE transcription_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1");
            $nStmt->execute([$transcriptionId, $userId]);
            $noteRecord = $nStmt->fetch();
            if ($noteRecord) {
                $noteId = (int)$noteRecord["id"];
                $updateJob("GENERATING_NOTES", 60, "Reusing existing note...", ["note_id" => $noteId]);
            }
        }

        if (!$noteRecord) {
            if (!isAINotesConfigured()) {
                $errMsg = "AI_SERVICE_NOT_CONFIGURED: No AI language model service is configured. Set GROQ_API_KEY, OPENAI_API_KEY, or GEMINI_API_KEY in config/local_config.php.";
                $updateJob("FAILED", 50, "AI service not configured", ["error_message" => $errMsg]);
                return ["success" => false, "message" => $errMsg];
            }

            $aiOptions = [
                "lecture_title" => $lectureTitle,
                "subject" => "Computer Science",
                "course_code" => "CS301",
                "professor" => "Prof. Sarah Chen"
            ];

            $aiResult = generateNotesWithLLM($fullTranscript, $aiOptions);

            if (!$aiResult["success"]) {
                $errMsg = "AI note generation failed: " . ($aiResult["message"] ?? "Unknown LLM error");
                $updateJob("FAILED", 50, "Note generation failed", ["error_message" => $errMsg]);
                return ["success" => false, "message" => $errMsg];
            }

            $gen = $aiResult["notes"];

            $title = trim($gen["title"] ?? ($lectureTitle ?: "Lecture Study Notes"));
            $subject = trim($gen["subject"] ?? "Computer Science");
            $courseCode = trim($gen["course_code"] ?? "CS301");
            $tag = trim($gen["tag"] ?? "AI notes");
            $professor = trim($gen["professor"] ?? "Prof. Sarah Chen");
            $duration = trim($audioDuration);
            $readTime = trim($gen["read_time"] ?? "6 min read");
            $iconType = !empty($gen["icon_type"]) ? $gen["icon_type"] : "Database";

            $summary = trim($gen["summary"] ?? "");
            $fullContent = trim($gen["full_content"] ?? ($summary ?: $fullTranscript));

            $keyTakeaways = $gen["key_takeaways"] ?? [];
            if (!is_array($keyTakeaways)) {
                $keyTakeaways = array_values(array_filter(array_map('trim', explode("\n", (string)$keyTakeaways))));
            }
            $keyTakeawaysJson = json_encode($keyTakeaways, JSON_UNESCAPED_UNICODE);

            $importantTerms = $gen["important_terms"] ?? [];
            if (!is_array($importantTerms)) {
                $importantTerms = array_values(array_filter(array_map('trim', explode(",", (string)$importantTerms))));
            }
            $importantTermsJson = json_encode($importantTerms, JSON_UNESCAPED_UNICODE);

            $section1Title = trim($gen["section1_title"] ?? "01 Overview");
            $section1Content = trim($gen["section1_content"] ?? ($fullContent ?: $summary));
            $section2Title = trim($gen["section2_title"] ?? "02 Key Concepts & Invariants");
            $section2Bullets = $gen["section2_bullets"] ?? ["Core foundational concept", "System mechanism"];
            if (!is_array($section2Bullets)) {
                $section2Bullets = array_values(array_filter(array_map('trim', explode("\n", (string)$section2Bullets))));
            }
            $section2BulletsJson = json_encode($section2Bullets, JSON_UNESCAPED_UNICODE);

            $calloutTitle = trim($gen["callout_title"] ?? "Core Principle");
            $calloutContent = trim($gen["callout_content"] ?? "Key rule or theorem derived from this lecture.");
            $section3Title = trim($gen["section3_title"] ?? "03 Practical Application");
            $section3Content1 = trim($gen["section3_content1"] ?? "Real-world engineering application.");
            $section3Content2 = trim($gen["section3_content2"] ?? "Next study steps.");
            $footerText = trim($gen["footer_text"] ?? "AI Generated Study Notes · SmartNotes AI");
            $serviceUsed = $aiResult["service_used"] . " (" . $aiResult["model_used"] . ")";

            $insNote = $conn->prepare("
                INSERT INTO notes (
                    user_id, transcription_id, title, subject, course_code, tag, professor,
                    duration, read_time, icon_type, summary, full_content, key_takeaways,
                    important_terms, section1_title, section1_content, section2_title,
                    section2_bullets, callout_title, callout_content, section3_title,
                    section3_content1, section3_content2, footer_text, ai_service_used, is_bookmarked
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
            ");
            $insNote->execute([
                $userId, $transcriptionId, $title, $subject, $courseCode, $tag, $professor,
                $duration, $readTime, $iconType, $summary, $fullContent, $keyTakeawaysJson,
                $importantTermsJson, $section1Title, $section1Content, $section2Title,
                $section2BulletsJson, $calloutTitle, $calloutContent, $section3Title,
                $section3Content1, $section3Content2, $footerText, $serviceUsed
            ]);
            $noteId = (int)$conn->lastInsertId();

            $nStmt = $conn->prepare("SELECT * FROM notes WHERE id = ?");
            $nStmt->execute([$noteId]);
            $noteRecord = $nStmt->fetch();
        }

        $updateJob("GENERATING_NOTES", 65, "Notes generated successfully", ["note_id" => $noteId]);

        // -------------------------------------------------------------
        // STAGE 4: AI MCQ Quiz Generation
        // -------------------------------------------------------------
        $updateJob("GENERATING_QUIZ", 70, "Creating interactive multiple-choice quiz questions...");

        $quizId = (int)($job["quiz_id"] ?? 0);
        $quizRecord = null;

        if ($quizId > 0) {
            $qStmt = $conn->prepare("SELECT * FROM quizzes WHERE id = ?");
            $qStmt->execute([$quizId]);
            $quizRecord = $qStmt->fetch();
        }

        if (!$quizRecord) {
            $qStmt = $conn->prepare("SELECT * FROM quizzes WHERE note_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1");
            $qStmt->execute([$noteId, $userId]);
            $quizRecord = $qStmt->fetch();
            if ($quizRecord) {
                $quizId = (int)$quizRecord["id"];
                $updateJob("GENERATING_QUIZ", 78, "Reusing existing quiz...", ["quiz_id" => $quizId]);
            }
        }

        if (!$quizRecord) {
            if (isQuizServiceConfigured()) {
                $quizResult = generateQuizWithLLM($noteRecord, [
                    "num_questions" => 5,
                    "difficulty" => "Moderate"
                ]);

                if ($quizResult["success"]) {
                    $genQuiz = $quizResult["quiz"];
                    $quizTitle = trim($genQuiz["title"] ?? ("Quiz: " . $noteRecord["title"]));
                    $quizSubject = trim($genQuiz["subject"] ?? $noteRecord["subject"]);
                    $questions = $genQuiz["questions"] ?? [];
                    $totalQuestions = count($questions);
                    $serviceUsed = $quizResult["service_used"] . " (" . $quizResult["model_used"] . ")";

                    $conn->beginTransaction();
                    $quizInsert = $conn->prepare("
                        INSERT INTO quizzes (
                            user_id, note_id, title, subject, score_percentage, correct_count, total_questions, difficulty, question_type, ai_service_used
                        ) VALUES (?, ?, ?, ?, 0, 0, ?, 'Moderate', 'MCQ', ?)
                    ");
                    $quizInsert->execute([$userId, $noteId, $quizTitle, $quizSubject, $totalQuestions, $serviceUsed]);
                    $quizId = (int)$conn->lastInsertId();

                    $qInsert = $conn->prepare("
                        INSERT INTO quiz_questions (
                            quiz_id, question_number, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, topic
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");

                    foreach ($questions as $idx => $q) {
                        $qNum = (int)($q["question_number"] ?? ($idx + 1));
                        $qText = trim($q["question_text"] ?? "");
                        $optA = trim($q["option_a"] ?? "");
                        $optB = trim($q["option_b"] ?? "");
                        $optC = trim($q["option_c"] ?? "");
                        $optD = trim($q["option_d"] ?? "");
                        $correctOpt = strtoupper(trim($q["correct_option"] ?? "A"));
                        $explanation = trim($q["explanation"] ?? "");
                        $topic = trim($q["topic"] ?? $noteRecord["subject"]);

                        $qInsert->execute([
                            $quizId, $qNum, $qText, $optA, $optB, $optC, $optD, $correctOpt, $explanation, $topic
                        ]);
                    }
                    $conn->commit();
                }
            }
        }

        $updateJob("GENERATING_QUIZ", 80, "Quiz questions prepared", ["quiz_id" => $quizId ?: null]);

        // -------------------------------------------------------------
        // STAGE 5: Educational Resource Discovery
        // -------------------------------------------------------------
        $updateJob("SEARCHING_RESOURCES", 85, "Discovering educational resources & academic references...");

        $terms = [];
        if (!empty($noteRecord["important_terms"])) {
            $decodedTerms = json_decode($noteRecord["important_terms"], true);
            if (is_array($decodedTerms)) {
                $terms = $decodedTerms;
            }
        }
        if (empty($terms) && !empty($noteRecord["title"])) {
            $terms[] = $noteRecord["title"];
        }

        $discoveredResources = discoverEducationalResources($terms, $noteRecord["subject"] ?? "Computer Science", 4);
        saveLectureResources($conn, $userId, $noteId, $jobId, $discoveredResources);

        $resourcesJson = json_encode($discoveredResources, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $updateJob("SAVING_RESULTS", 95, "Finalizing study package...", [
            "resources_json" => $resourcesJson,
            "transcription_id" => $transcriptionId,
            "note_id" => $noteId,
            "quiz_id" => $quizId ?: null
        ]);

        // -------------------------------------------------------------
        // STAGE 6: Completion
        // -------------------------------------------------------------
        $updateJob("COMPLETED", 100, "All learning materials ready", [
            "error_message" => null
        ]);

        return [
            "success" => true,
            "job_id" => $jobId,
            "status" => "COMPLETED",
            "progress_percent" => 100,
            "transcription_id" => $transcriptionId,
            "note_id" => $noteId,
            "quiz_id" => $quizId,
            "resources_count" => count($discoveredResources)
        ];

    } catch (Exception $e) {
        $errMsg = "Pipeline exception: " . $e->getMessage();
        $updateJob("FAILED", 0, "Execution failed", ["error_message" => $errMsg]);
        return ["success" => false, "message" => $errMsg];
    }
}

// -------------------------------------------------------------
// CLI Execution Handler (When triggered via background process)
// -------------------------------------------------------------
if (php_sapi_name() === 'cli' && isset($argv[1])) {
    $cliJobId = intval($argv[1]);
    if ($cliJobId > 0) {
        runLectureProcessingPipeline($conn, $cliJobId);
    }
}
