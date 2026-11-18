<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/config.php";
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

$topic = trim((string)($data["topic"] ?? $data["subject"] ?? ""));
if ($topic === "") {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "A subject or topic name is required to generate AI flashcards."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$numCards = (int)($data["num_cards"] ?? $data["numberOfCards"] ?? 10);
if ($numCards < 1) $numCards = 10;
if ($numCards > 30) $numCards = 30;

$aiResult = generateTopicFlashcardsWithGemini($topic, [
    "num_cards" => $numCards
]);

if (!$aiResult["success"]) {
    http_response_code($aiResult["http_status"] ?? 502);
    echo json_encode($aiResult, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$deck = $aiResult["deck"];
$deckTitle = trim((string)($deck["deck_title"] ?? ($topic . " Flashcards")));
$subject = trim((string)($deck["subject"] ?? $topic));
$cards = $deck["flashcards"] ?? [];
$totalCards = count($cards);

http_response_code(200);
echo json_encode([
    "success" => true,
    "deck_title" => $deckTitle,
    "subject" => $subject,
    "topic" => $deck["topic"] ?? $topic,
    "total_cards" => $totalCards,
    "flashcards" => $cards,
    "service_used" => $aiResult["service_used"] ?? "Google Gemini",
    "model_used" => $aiResult["model_used"] ?? "gemini-3.5-flash-lite"
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
