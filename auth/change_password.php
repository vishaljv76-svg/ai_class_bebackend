<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../common/auth_middleware.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed. POST method required."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

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

$oldPassword = $data["old_password"] ?? $data["current_password"] ?? $data["currentPassword"] ?? "";
$newPassword = $data["new_password"] ?? $data["newPassword"] ?? $data["password"] ?? "";
$confirmPassword = $data["confirm_password"] ?? $data["confirmPassword"] ?? null;

if (!is_string($oldPassword) || $oldPassword === "") {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Current password is required."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_string($newPassword) || strlen($newPassword) < 6) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "New password must be at least 6 characters long."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($confirmPassword !== null && $newPassword !== $confirmPassword) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "New password and confirmation do not match."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmt = $conn->prepare("SELECT id, password FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "User account not found."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!password_verify($oldPassword, $user["password"])) {
        http_response_code(401);
        echo json_encode([
            "success" => false,
            "message" => "Current password is incorrect."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    $upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    $upd->execute([$hashedPassword, $userId]);

    echo json_encode([
        "success" => true,
        "message" => "Password changed successfully."
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while updating password: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
