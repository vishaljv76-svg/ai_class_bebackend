<?php
/**
 * SmartNotes AI - AI Notes Generation Service Configuration
 * Supports Groq Cloud (Llama 3), OpenAI (GPT-4o-mini), and Google Gemini.
 */

// Read API keys from environment or local overrides
$groqAiKey = getenv("GROQ_API_KEY") ?: "";
$openaiAiKey = getenv("OPENAI_API_KEY") ?: "";
$geminiAiKey = getenv("GEMINI_API_KEY") ?: "";

// Service configurations
$aiServices = [
    "groq" => [
        "name" => "Groq Cloud (Llama 3.3 70B)",
        "model" => "llama-3.3-70b-versatile",
        "endpoint" => "https://api.groq.com/openai/v1/chat/completions",
        "doc_url" => "https://console.groq.com/docs/models",
        "key_env" => "GROQ_API_KEY",
        "free_tier" => true
    ],
    "openai" => [
        "name" => "OpenAI (GPT-4o Mini)",
        "model" => "gpt-4o-mini",
        "endpoint" => "https://api.openai.com/v1/chat/completions",
        "doc_url" => "https://platform.openai.com/docs/models",
        "key_env" => "OPENAI_API_KEY",
        "free_tier" => false
    ],
    "gemini" => [
        "name" => "Google Gemini (Gemini 1.5 Flash)",
        "model" => "gemini-1.5-flash",
        "endpoint" => "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent",
        "doc_url" => "https://ai.google.dev/gemini-api/docs",
        "key_env" => "GEMINI_API_KEY",
        "free_tier" => true
    ]
];

/**
 * Returns information about active or requested AI service.
 */
function getAINotesServiceInfo($customKey = null, $requestedService = null) {
    global $groqAiKey, $openaiAiKey, $geminiAiKey, $aiServices;

    $req = strtolower(trim((string)$requestedService));
    $key = trim((string)$customKey);

    // If explicit key and service requested
    if ($req && isset($aiServices[$req])) {
        $svcKey = $key ?: ($req === "groq" ? $groqAiKey : ($req === "openai" ? $openaiAiKey : $geminiAiKey));
        return [
            "service_id" => $req,
            "service_name" => $aiServices[$req]["name"],
            "model" => $aiServices[$req]["model"],
            "endpoint" => $aiServices[$req]["endpoint"],
            "is_configured" => !empty($svcKey),
            "key_present" => !empty($svcKey),
            "active_key" => $svcKey,
            "doc_url" => $aiServices[$req]["doc_url"]
        ];
    }

    // Custom key heuristic (gsk_ is Groq, sk- is OpenAI, AIza is Gemini)
    if (!empty($key)) {
        if (str_starts_with($key, "gsk_")) {
            $matched = "groq";
        } elseif (str_starts_with($key, "AIza")) {
            $matched = "gemini";
        } else {
            $matched = "openai";
        }
        return [
            "service_id" => $matched,
            "service_name" => $aiServices[$matched]["name"],
            "model" => $aiServices[$matched]["model"],
            "endpoint" => $aiServices[$matched]["endpoint"],
            "is_configured" => true,
            "key_present" => true,
            "active_key" => $key,
            "doc_url" => $aiServices[$matched]["doc_url"]
        ];
    }

    // Check environment hierarchy: Groq > OpenAI > Gemini
    if (!empty($groqAiKey)) {
        return [
            "service_id" => "groq",
            "service_name" => $aiServices["groq"]["name"],
            "model" => $aiServices["groq"]["model"],
            "endpoint" => $aiServices["groq"]["endpoint"],
            "is_configured" => true,
            "key_present" => true,
            "active_key" => $groqAiKey,
            "doc_url" => $aiServices["groq"]["doc_url"]
        ];
    }

    if (!empty($openaiAiKey)) {
        return [
            "service_id" => "openai",
            "service_name" => $aiServices["openai"]["name"],
            "model" => $aiServices["openai"]["model"],
            "endpoint" => $aiServices["openai"]["endpoint"],
            "is_configured" => true,
            "key_present" => true,
            "active_key" => $openaiAiKey,
            "doc_url" => $aiServices["openai"]["doc_url"]
        ];
    }

    if (!empty($geminiAiKey)) {
        return [
            "service_id" => "gemini",
            "service_name" => $aiServices["gemini"]["name"],
            "model" => $aiServices["gemini"]["model"],
            "endpoint" => $aiServices["gemini"]["endpoint"],
            "is_configured" => true,
            "key_present" => true,
            "active_key" => $geminiAiKey,
            "doc_url" => $aiServices["gemini"]["doc_url"]
        ];
    }

    return [
        "service_id" => "none",
        "service_name" => "No AI service configured",
        "model" => "none",
        "endpoint" => "",
        "is_configured" => false,
        "key_present" => false,
        "active_key" => "",
        "doc_url" => ""
    ];
}

/**
 * Checks whether an AI service is ready to generate notes.
 */
function isAINotesConfigured($customKey = null) {
    $info = getAINotesServiceInfo($customKey);
    return $info["is_configured"];
}

/**
 * Calls selected LLM API to generate structured study notes from transcription text.
 *
 * @param string $transcriptText The speech-to-text lecture transcript
 * @param array $options Configuration and meta context (e.g. lecture_title, subject, custom_key)
 * @return array Result containing status, data, and metadata
 */
function generateNotesWithLLM($transcriptText, $options = []) {
    $customKey = $options["api_key"] ?? null;
    $serviceReq = $options["service"] ?? null;
    $metaTitle = $options["lecture_title"] ?? "";
    $metaSubject = $options["subject"] ?? "";
    $metaCourse = $options["course_code"] ?? "";
    $metaProfessor = $options["professor"] ?? "Prof. Sarah Chen";

    $serviceInfo = getAINotesServiceInfo($customKey, $serviceReq);

    if (!$serviceInfo["is_configured"]) {
        return [
            "success" => false,
            "error_code" => "AI_SERVICE_NOT_CONFIGURED",
            "http_status" => 422,
            "message" => "No AI language model service is currently configured on this server.",
            "guidance" => [
                "option_1" => "Groq Cloud (Recommended): Set GROQ_API_KEY in environment or config/ai_notes_config.php",
                "option_2" => "OpenAI: Set OPENAI_API_KEY in environment or config/ai_notes_config.php",
                "option_3" => "Google Gemini: Set GEMINI_API_KEY in environment or config/ai_notes_config.php",
                "option_4" => "Pass X-AI-API-Key in HTTP headers or 'ai_api_key' in request body",
                "manual_save" => "You can also save completed study notes directly via POST /notes/save_generated_note.php"
            ]
        ];
    }

    $systemPrompt = "You are an expert academic note taker and professor. Your job is to transform a spoken lecture transcription into structured, comprehensive, and beautiful study notes for students.
Respond strictly with valid JSON only. Do not wrap in markdown quotes like ```json. Do not include commentary outside the JSON.

The JSON MUST conform to this exact structure:
{
  \"title\": \"Clear concise lecture title (e.g. Database Normalization)\",
  \"subject\": \"Academic Subject (e.g. Computer Science, Mathematics, Physics)\",
  \"course_code\": \"Short course code (e.g. CS301, MATH201, PHYS101)\",
  \"tag\": \"AI notes\",
  \"professor\": \"$metaProfessor\",
  \"read_time\": \"Estimated read time (e.g. 6 min read)\",
  \"icon_type\": \"Database or Math\",
  \"summary\": \"2-3 sentence executive summary explaining what was taught and why it matters\",
  \"full_content\": \"Thorough 2-3 paragraph foundational overview of the entire lecture concept\",
  \"key_takeaways\": [
    \"First major key point or rule explained in the lecture.\",
    \"Second major key point or rule explained in the lecture.\",
    \"Third major key point or rule explained in the lecture.\"
  ],
  \"important_terms\": [
    \"First important keyword or term\",
    \"Second important keyword or term\",
    \"Third important keyword or term\",
    \"Fourth important keyword or term\"
  ],
  \"section1_title\": \"01 Foundational Overview\",
  \"section1_content\": \"Detailed paragraph explaining the foundation, motivation, and problem statement.\",
  \"section2_title\": \"02 Core Principles & Invariants\",
  \"section2_bullets\": [
    \"Principle 1 — Clear technical explanation.\",
    \"Principle 2 — Clear technical explanation.\",
    \"Principle 3 — Clear technical explanation.\"
  ],
  \"callout_title\": \"Core Definition / Invariant\",
  \"callout_content\": \"Crucial formula, invariant, theorem or rule to memorize.\",
  \"section3_title\": \"03 Practical Application & Next Steps\",
  \"section3_content1\": \"Step-by-step application of these principles in practice or industry.\",
  \"section3_content2\": \"Edge cases, common mistakes, and recommended follow-up study areas.\",
  \"footer_text\": \"AI Generated Notes · SmartNotes AI\"
}";

    $userPrompt = "Lecture Context:
Title: " . ($metaTitle ?: "Lecture") . "
Subject: " . ($metaSubject ?: "Computer Science") . "
Course Code: " . ($metaCourse ?: "CS301") . "

Spoken Lecture Transcript:
\"\"\"
" . $transcriptText . "
\"\"\"

Please generate the structured study notes in strict JSON format now.";

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
        // OpenAI or Groq format
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

    // Clean any markdown code fences if present
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

    $notesData = json_decode($cleanJson, true);
    if (!is_array($notesData)) {
        return [
            "success" => false,
            "error_code" => "AI_INVALID_JSON_RESPONSE",
            "http_status" => 502,
            "message" => "AI provider response could not be parsed as valid JSON.",
            "raw_output" => $generatedText
        ];
    }

    return [
        "success" => true,
        "service_used" => $serviceInfo["service_name"],
        "model_used" => $model,
        "notes" => $notesData
    ];
}

