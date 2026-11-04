<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../config/ai_notes_config.php";
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
        } elseif (strcasecmp($hKey, "X-AI-API-Key") === 0) {
            $_SERVER["HTTP_X_AI_API_KEY"] = $hVal;
        }
    }
}

$customKey = $_SERVER["HTTP_X_AI_API_KEY"] ?? ($_GET["ai_api_key"] ?? null);
$serviceInfo = getAINotesServiceInfo($customKey);

http_response_code(200);
echo json_encode([
    "success" => true,
    "ai_configured" => $serviceInfo["is_configured"],
    "active_service" => $serviceInfo["service_id"],
    "service_name" => $serviceInfo["service_name"],
    "model" => $serviceInfo["model"],
    "supported_services" => [
        "groq" => [
            "name" => "Groq Cloud (Recommended - Ultra fast & generous free tier)",
            "model" => "llama-3.3-70b-versatile",
            "key_config" => "Set GROQ_API_KEY in environment or pass X-AI-API-Key header",
            "documentation" => "https://console.groq.com/docs/models"
        ],
        "openai" => [
            "name" => "OpenAI",
            "model" => "gpt-4o-mini",
            "key_config" => "Set OPENAI_API_KEY in environment or pass X-AI-API-Key header",
            "documentation" => "https://platform.openai.com/docs/models"
        ],
        "gemini" => [
            "name" => "Google Gemini",
            "model" => "gemini-1.5-flash",
            "key_config" => "Set GEMINI_API_KEY in environment or pass X-AI-API-Key header",
            "documentation" => "https://ai.google.dev/gemini-api/docs"
        ]
    ],
    "message" => $serviceInfo["is_configured"] 
        ? "AI service is configured and ready to generate study notes."
        : "AI service is not configured. Study notes can still be saved via POST /notes/save_generated_note.php or activate live AI with a Groq, OpenAI, or Gemini key."
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

