<?php
/**
 * SmartNotes AI - Quiz Generation Service Configuration
 * Reuses the AI provider configuration (Groq, OpenAI, Google Gemini).
 */

require_once __DIR__ . "/ai_notes_config.php";

/**
 * Returns quiz AI service readiness and configuration details.
 */
function getQuizServiceInfo($customKey = null, $requestedService = null) {
    return getAINotesServiceInfo($customKey, $requestedService);
}

/**
 * Checks whether an AI service is configured for quiz generation.
 */
function isQuizServiceConfigured($customKey = null) {
    return isAINotesConfigured($customKey);
}

/**
 * Calls selected LLM API to generate multiple-choice quiz questions from a study note.
 *
 * @param array $note The source study note containing title, summary, key_takeaways, important_terms, full_content, etc.
 * @param array $options Configuration options (num_questions, difficulty, api_key, service)
 * @return array
 */
function generateQuizWithLLM($note, $options = []) {
    $customKey = $options["api_key"] ?? null;
    $serviceReq = $options["service"] ?? null;
    $numQuestions = (int)($options["num_questions"] ?? 5);
    if ($numQuestions < 1) $numQuestions = 5;
    if ($numQuestions > 20) $numQuestions = 20;
    $difficulty = $options["difficulty"] ?? "Moderate";

    $serviceInfo = getQuizServiceInfo($customKey, $serviceReq);

    if (!$serviceInfo["is_configured"]) {
        return [
            "success" => false,
            "error_code" => "AI_SERVICE_NOT_CONFIGURED",
            "http_status" => 422,
            "message" => "No AI language model service is currently configured on this server for quiz generation.",
            "guidance" => [
                "option_1" => "Groq Cloud (Recommended): Set GROQ_API_KEY in environment or config/ai_notes_config.php",
                "option_2" => "OpenAI: Set OPENAI_API_KEY in environment or config/ai_notes_config.php",
                "option_3" => "Google Gemini: Set GEMINI_API_KEY in environment or config/ai_notes_config.php",
                "option_4" => "Pass X-AI-API-Key in HTTP headers or 'ai_api_key' in request body",
                "manual_save" => "You can also save quizzes directly via POST /quiz/save_quiz.php"
            ]
        ];
    }

    $noteTitle = $note["title"] ?? "Lecture";
    $noteSubject = $note["subject"] ?? "Computer Science";
    $noteCourse = $note["course_code"] ?? "CS301";
    $noteSummary = $note["summary"] ?? "";
    $noteFullContent = $note["full_content"] ?? "";
    $noteTakeaways = is_array($note["key_takeaways"]) ? implode("\n", $note["key_takeaways"]) : (string)$note["key_takeaways"];
    $noteTerms = is_array($note["important_terms"]) ? implode(", ", $note["important_terms"]) : (string)$note["important_terms"];

    $systemPrompt = "You are an expert academic professor and exam creator.
Your job is to generate a high quality multiple-choice quiz based directly on the provided study notes.
Rules:
1. Generate exactly $numQuestions questions.
2. Difficulty level: $difficulty.
3. Every question must have exactly 4 options labeled option_a, option_b, option_c, option_d.
4. Exactly one option must be correct. Specify correct_option as 'A', 'B', 'C', or 'D'.
5. Provide a clear, educational explanation for why that answer is correct.
6. The questions must test real knowledge directly mentioned in the notes. Do not hallucinate or use outside trivia.
7. Respond strictly with valid JSON only. Do not wrap in markdown quotes like ```json. Do not include commentary outside the JSON.

Expected JSON format:
{
  \"title\": \"Quiz: $noteTitle\",
  \"difficulty\": \"$difficulty\",
  \"subject\": \"$noteSubject\",
  \"total_questions\": $numQuestions,
  \"questions\": [
    {
      \"question_number\": 1,
      \"question_text\": \"Question text here?\",
      \"topic\": \"Specific sub-topic\",
      \"option_a\": \"Option A text\",
      \"option_b\": \"Option B text\",
      \"option_c\": \"Option C text\",
      \"option_d\": \"Option D text\",
      \"correct_option\": \"B\",
      \"explanation\": \"Detailed explanation of why B is correct.\"
    }
  ]
}";

    $userPrompt = "Study Note Content:
Title: $noteTitle
Subject: $noteSubject ($noteCourse)

Executive Summary:
$noteSummary

Key Takeaways:
$noteTakeaways

Important Terms:
$noteTerms

Detailed Note Content:
$noteFullContent

Generate the $numQuestions $difficulty multiple-choice questions in strict JSON format now.";

    $serviceId = $serviceInfo["service_id"];
    $apiKey = $serviceInfo["active_key"];
    $model = $serviceInfo["model"];
    $endpoint = $serviceInfo["endpoint"];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    if ($serviceId === "gemini") {
        $url = $endpoint . "?key=" . urlencode($apiKey);
        $payload = [
            "contents" => [
                [
                    "role" => "user",
                    "parts" => [
                        ["text" => $systemPrompt . "\n\n" . $userPrompt]
                    ]
                ]
            ],
            "generationConfig" => [
                "temperature" => 0.2,
                "responseMimeType" => "application/json"
            ]
        ];

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    } else {
        $payload = [
            "model" => $model,
            "messages" => [
                ["role" => "system", "content" => $systemPrompt],
                ["role" => "user", "content" => $userPrompt]
            ],
            "temperature" => 0.2,
            "response_format" => ["type" => "json_object"]
        ];

        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $apiKey,
            "Content-Type: application/json"
        ]);
    }

    $rawResponse = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError) {
        return [
            "success" => false,
            "error_code" => "AI_NETWORK_ERROR",
            "http_status" => 502,
            "message" => "Failed to reach AI provider: " . $curlError
        ];
    }

    if ($httpCode === 401 || $httpCode === 403) {
        return [
            "success" => false,
            "error_code" => "AI_AUTHENTICATION_FAILED",
            "http_status" => 401,
            "message" => "AI service authentication failed. The provided API key is invalid or unauthorized."
        ];
    }

    if ($httpCode === 429) {
        return [
            "success" => false,
            "error_code" => "AI_RATE_LIMIT_EXCEEDED",
            "http_status" => 429,
            "message" => "AI service rate limit exceeded or quota exhausted. Please try again in a few moments."
        ];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $parsedErr = json_decode($rawResponse, true);
        $errDetail = $parsedErr["error"]["message"] ?? $rawResponse;
        return [
            "success" => false,
            "error_code" => "AI_PROVIDER_ERROR",
            "http_status" => $httpCode >= 400 && $httpCode < 500 ? $httpCode : 502,
            "message" => "AI service returned HTTP $httpCode: " . $errDetail
        ];
    }

    $responseData = json_decode($rawResponse, true);
    $generatedText = "";

    if ($serviceId === "gemini") {
        $generatedText = $responseData["candidates"][0]["content"]["parts"][0]["text"] ?? "";
    } else {
        $generatedText = $responseData["choices"][0]["message"]["content"] ?? "";
    }

    // Clean markdown code fences if present
    $cleanJson = trim($generatedText);
    if (str_starts_with($cleanJson, "```json")) {
        $cleanJson = substr($cleanJson, 7);
    } elseif (str_starts_with($cleanJson, "```")) {
        $cleanJson = substr($cleanJson, 3);
    }
    if (str_ends_with($cleanJson, "```")) {
        $cleanJson = substr($cleanJson, 0, -3);
    }
    $cleanJson = trim($cleanJson);

    $quizData = json_decode($cleanJson, true);
    if (!is_array($quizData) || empty($quizData["questions"])) {
        return [
            "success" => false,
            "error_code" => "AI_INVALID_JSON_RESPONSE",
            "http_status" => 502,
            "message" => "AI provider response could not be parsed as valid quiz JSON.",
            "raw_output" => $generatedText
        ];
    }

    return [
        "success" => true,
        "service_used" => $serviceInfo["service_name"],
        "model_used" => $model,
        "quiz" => $quizData
    ];
}

