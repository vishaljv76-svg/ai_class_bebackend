<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../config/quiz_config.php";
require_once __DIR__ . "/../common/auth_middleware.php";
require_once __DIR__ . "/../ai/ai_provider_service.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed. POST method required."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Normalize Authorization & custom headers in environments where Apache strips them
if (empty($_SERVER["HTTP_AUTHORIZATION"])) {
    $reqHeaders = function_exists("getallheaders") ? getallheaders() : [];
    foreach ($reqHeaders as $hKey => $hVal) {
        if (strcasecmp($hKey, "Authorization") === 0) {
            $_SERVER["HTTP_AUTHORIZATION"] = $hVal;
        } elseif (strcasecmp($hKey, "X-Auth-Token") === 0) {
            $_SERVER["HTTP_X_AUTH_TOKEN"] = $hVal;
        } elseif (strcasecmp($hKey, "X-AI-API-Key") === 0) {
            $_SERVER["HTTP_X_AI_API_KEY"] = $hVal;
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

$topic = trim((string)($data["topic"] ?? $data["subject"] ?? $data["topic_text"] ?? ""));
$noteId = filter_var($data["note_id"] ?? $data["noteId"] ?? null, FILTER_VALIDATE_INT);

if ($topic === "" && (!$noteId || $noteId < 1)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Either 'topic' or 'note_id' is required to generate quiz questions."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$numQuestions = (int)($data["numberOfQuestions"] ?? $data["num_questions"] ?? $data["number_of_questions"] ?? 10);
if ($numQuestions < 1) $numQuestions = 10;
if ($numQuestions > 50) $numQuestions = 50;

$difficulty = trim((string)($data["difficulty"] ?? "Medium"));
$questionType = trim((string)($data["question_type"] ?? $data["questionType"] ?? "MCQ"));
$saveToDb = isset($data["save_to_db"]) ? (bool)$data["save_to_db"] : (isset($data["saveToDb"]) ? (bool)$data["saveToDb"] : true);
$customApiKey = trim((string)($data["ai_api_key"] ?? ($_SERVER["HTTP_X_AI_API_KEY"] ?? "")));
$serviceName = trim((string)($data["service"] ?? ""));

try {
    // 1. Verify user exists
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

    $genQuiz = null;
    $serviceUsed = "";

    if ($topic !== "") {
        // --- 2A. Topic-Based Quiz Generation (Google Gemini) ---
        $aiResult = generateTopicQuizWithGemini($topic, [
            "num_questions" => $numQuestions,
            "difficulty" => $difficulty,
            "question_type" => $questionType
        ]);

        if (!$aiResult["success"]) {
            http_response_code($aiResult["http_status"] ?? 502);
            echo json_encode($aiResult, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit;
        }

        $genQuiz = $aiResult["quiz"];
        $quizTitle = trim((string)($genQuiz["title"] ?? ("Quiz: " . $topic)));
        $quizSubject = trim((string)($genQuiz["subject"] ?? $topic));
        $questions = $genQuiz["questions"] ?? [];
        $totalQuestions = count($questions);
        $serviceUsed = $aiResult["service_used"] . " (" . $aiResult["model_used"] . ")";
        $noteId = null; // Explicit null for topic quizzes
    } else {
        // --- 2B. Note-Based Quiz Generation (Existing flow) ---
        $noteStmt = $conn->prepare("SELECT * FROM notes WHERE id = ?");
        $noteStmt->execute([$noteId]);
        $note = $noteStmt->fetch();

        if (!$note) {
            http_response_code(404);
            echo json_encode([
                "success" => false,
                "message" => "Note not found for ID: " . $noteId
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // User authorization check
        if ((int)$note["user_id"] !== $userId) {
            http_response_code(403);
            echo json_encode([
                "success" => false,
                "message" => "Forbidden: You do not have permission to generate quizzes from this note."
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Validate substantive note content exists
        $contentCheck = trim(($note["summary"] ?? "") . ($note["full_content"] ?? "") . ($note["section1_content"] ?? ""));
        if ($contentCheck === "") {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "The source note has empty content. Cannot generate questions without study content."
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $aiOptions = [
            "api_key" => $customApiKey ?: null,
            "service" => $serviceName ?: null,
            "num_questions" => $numQuestions,
            "difficulty" => $difficulty
        ];

        $aiResult = generateQuizWithLLM($note, $aiOptions);

        if (!$aiResult["success"]) {
            http_response_code($aiResult["http_status"] ?? 502);
            echo json_encode($aiResult, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit;
        }

        $genQuiz = $aiResult["quiz"];
        $quizTitle = trim((string)($genQuiz["title"] ?? ("Quiz: " . $note["title"])));
        $quizSubject = trim((string)($genQuiz["subject"] ?? $note["subject"]));
        $questions = $genQuiz["questions"] ?? [];
        $totalQuestions = count($questions);
        $serviceUsed = $aiResult["service_used"] . " (" . $aiResult["model_used"] . ")";
    }

    $newQuizId = null;
    $formattedQuestions = [];

    // 3. Save in MySQL if requested
    if ($saveToDb) {
        $conn->beginTransaction();

        $quizInsert = $conn->prepare("
            INSERT INTO quizzes (
                user_id, note_id, title, subject, score_percentage, correct_count, total_questions, difficulty, question_type, ai_service_used
            ) VALUES (?, ?, ?, ?, 0, 0, ?, ?, ?, ?)
        ");
        $quizInsert->execute([
            $userId, $noteId ? $noteId : null, $quizTitle, $quizSubject, $totalQuestions, $difficulty, $questionType, $serviceUsed
        ]);
        $newQuizId = (int)$conn->lastInsertId();

        $qInsert = $conn->prepare("
            INSERT INTO quiz_questions (
                quiz_id, question_number, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, topic
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($questions as $idx => $q) {
            $qNum = (int)($q["question_number"] ?? ($idx + 1));
            $qText = trim((string)($q["question_text"] ?? ""));
            $optA = trim((string)($q["option_a"] ?? ""));
            $optB = trim((string)($q["option_b"] ?? ""));
            $optC = trim((string)($q["option_c"] ?? ""));
            $optD = trim((string)($q["option_d"] ?? ""));
            $correctOpt = strtoupper(trim((string)($q["correct_option"] ?? "A")));
            $explanation = trim((string)($q["explanation"] ?? ""));
            $subTopic = trim((string)($q["topic"] ?? $quizSubject));

            $qInsert->execute([
                $newQuizId, $qNum, $qText, $optA, $optB, $optC, $optD, $correctOpt, $explanation, $subTopic
            ]);
            $insertedQId = (int)$conn->lastInsertId();

            $optionsList = [
                ["optionKey" => "A", "text" => $optA],
                ["optionKey" => "B", "text" => $optB]
            ];
            if ($optC !== "" && $optC !== "N/A") {
                $optionsList[] = ["optionKey" => "C", "text" => $optC];
            }
            if ($optD !== "" && $optD !== "N/A") {
                $optionsList[] = ["optionKey" => "D", "text" => $optD];
            }

            $formattedQuestions[] = [
                "id" => $insertedQId,
                "questionNumber" => $qNum,
                "question_number" => $qNum,
                "questionText" => $qText,
                "question_text" => $qText,
                "topic" => $subTopic,
                "topicText" => "$subTopic · $quizSubject",
                "instructionText" => count($optionsList) <= 2 ? "Choose True or False." : "Choose one answer.",
                "modeText" => "$difficulty · $questionType",
                "options" => $optionsList,
                "correctOption" => $correctOpt,
                "correct_option" => $correctOpt,
                "explanation" => $explanation
            ];
        }

        $conn->commit();
    } else {
        // Preview mode
        foreach ($questions as $idx => $q) {
            $qNum = (int)($q["question_number"] ?? ($idx + 1));
            $qText = trim((string)($q["question_text"] ?? ""));
            $optA = trim((string)($q["option_a"] ?? ""));
            $optB = trim((string)($q["option_b"] ?? ""));
            $optC = trim((string)($q["option_c"] ?? ""));
            $optD = trim((string)($q["option_d"] ?? ""));
            $correctOpt = strtoupper(trim((string)($q["correct_option"] ?? "A")));
            $explanation = trim((string)($q["explanation"] ?? ""));
            $subTopic = trim((string)($q["topic"] ?? $quizSubject));

            $optionsList = [
                ["optionKey" => "A", "text" => $optA],
                ["optionKey" => "B", "text" => $optB]
            ];
            if ($optC !== "" && $optC !== "N/A") {
                $optionsList[] = ["optionKey" => "C", "text" => $optC];
            }
            if ($optD !== "" && $optD !== "N/A") {
                $optionsList[] = ["optionKey" => "D", "text" => $optD];
            }

            $formattedQuestions[] = [
                "id" => null,
                "questionNumber" => $qNum,
                "question_number" => $qNum,
                "questionText" => $qText,
                "question_text" => $qText,
                "topic" => $subTopic,
                "topicText" => "$subTopic · $quizSubject",
                "instructionText" => count($optionsList) <= 2 ? "Choose True or False." : "Choose one answer.",
                "modeText" => "$difficulty · $questionType",
                "options" => $optionsList,
                "correctOption" => $correctOpt,
                "correct_option" => $correctOpt,
                "explanation" => $explanation
            ];
        }
    }

    $responseCode = $saveToDb ? 201 : 200;
    http_response_code($responseCode);

    echo json_encode([
        "success" => true,
        "message" => $saveToDb ? "Quiz and questions generated and saved successfully" : "Quiz questions generated successfully (preview mode)",
        "quiz_id" => $newQuizId,
        "note_id" => $noteId,
        "title" => $quizTitle,
        "subject" => $quizSubject,
        "difficulty" => $difficulty,
        "total_questions" => $totalQuestions,
        "estimatedTimeText" => "$totalQuestions multiple-choice questions · About $totalQuestions min",
        "service_used" => $serviceUsed,
        "questions" => $formattedQuestions
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while generating quiz: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

