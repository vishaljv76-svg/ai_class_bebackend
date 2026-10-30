<?php
header("Content-Type: application/json");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../common/auth_middleware.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "POST method required"
    ]);
    exit;
}

$user = getAuthenticatedUser($conn, false);

if ($user && !empty($user["token"])) {
    invalidateAuthToken($conn, $user["token"]);
}

http_response_code(200);
echo json_encode([
    "success" => true,
    "message" => "Logged out successfully"
]);

