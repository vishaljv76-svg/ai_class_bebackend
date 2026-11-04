<?php
/**
 * Speech-to-Text (STT) Service Configuration for SmartNotes AI
 * 
 * Supports OpenAI Whisper and Groq Whisper APIs.
 * Keys can be provided via environment variables or set directly below.
 */

// Define API keys (Check environment variable first, then fallback to local configuration)
$openaiApiKey = getenv("OPENAI_API_KEY") ?: "";
$groqApiKey = getenv("GROQ_API_KEY") ?: "";

// Active provider detection
$activeSTTService = null;
$activeSTTKey = "";
$activeSTTEndpoint = "";
$activeSTTModel = "";

if (!empty($groqApiKey)) {
    $activeSTTService = "Groq Whisper";
    $activeSTTKey = $groqApiKey;
    $activeSTTEndpoint = "https://api.groq.com/openai/v1/audio/transcriptions";
    $activeSTTModel = "whisper-large-v3";
} elseif (!empty($openaiApiKey)) {
    $activeSTTService = "OpenAI Whisper";
    $activeSTTKey = $openaiApiKey;
    $activeSTTEndpoint = "https://api.openai.com/v1/audio/transcriptions";
    $activeSTTModel = "whisper-1";
}

/**
 * Check whether a speech-to-text service is currently configured
 */
function isSTTConfigured() {
    global $activeSTTService, $activeSTTKey;
    return !empty($activeSTTService) && !empty($activeSTTKey);
}

/**
 * Returns service status and setup guidance
 */
function getSTTServiceInfo() {
    global $activeSTTService, $activeSTTModel;

    return [
        "configured" => isSTTConfigured(),
        "active_service" => $activeSTTService ?: "None (Unconfigured)",
        "active_model" => $activeSTTModel ?: "None",
        "supported_services" => [
            [
                "provider" => "Groq Whisper API (Recommended - Fast & Free Tier available)",
                "config_key" => "GROQ_API_KEY",
                "model" => "whisper-large-v3",
                "api_url" => "https://api.groq.com/openai/v1/audio/transcriptions",
                "documentation" => "https://console.groq.com/docs/speech-text"
            ],
            [
                "provider" => "OpenAI Whisper API",
                "config_key" => "OPENAI_API_KEY",
                "model" => "whisper-1",
                "api_url" => "https://api.openai.com/v1/audio/transcriptions",
                "documentation" => "https://platform.openai.com/docs/guides/speech-to-text"
            ]
        ]
    ];
}

