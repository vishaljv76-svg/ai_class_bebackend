<?php
/**
 * SmartNotes AI - Local Configuration Example Template
 * 
 * Secure Setup Instructions:
 * 1. Copy this file to "config/local_config.php" in your project:
 *    copy config\local_config.example.php config\local_config.php
 * 
 * 2. Add your secret API key below (Groq, OpenAI, or Google Gemini).
 *    Note: "config/local_config.php" is already listed in .gitignore and protected
 *    from direct web access by config/.htaccess, so your secrets will NEVER be committed to Git.
 * 
 * Recommended Free Provider:
 * - Groq Cloud (Ultra-fast, generous free tier for both Whisper STT and Llama 3.3 LLM):
 *   Get a free API key at: https://console.groq.com/keys
 */

// Option 1: Groq Cloud (Recommended - Free Tier for Audio STT & AI Notes/Quiz)
// putenv("GROQ_API_KEY=gsk_your_groq_api_key_here");
// $localGroqApiKey = "gsk_your_groq_api_key_here";

// Option 2: OpenAI (Paid credits required)
// putenv("OPENAI_API_KEY=sk-your_openai_api_key_here");
// $localOpenaiApiKey = "sk-your_openai_api_key_here";

// Option 3: Google Gemini (Free Tier on Google AI Studio)
// putenv("GEMINI_API_KEY=AIzaSyYourGeminiApiKeyHere");
// $localGeminiApiKey = "AIzaSyYourGeminiApiKeyHere";

