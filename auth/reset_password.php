<?php
header("Content-Type: application/json");

require_once __DIR__ . "/../config/config.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "POST method required"
    ]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON payload"
    ]);
    exit;
}

$email = trim($data["email"] ?? "");
$token = trim($data["token"] ?? $data["reset_token"] ?? "");
$newPassword = $data["new_password"] ?? $data["password"] ?? "";
$confirmPassword = $data["confirm_password"] ?? $data["confirmPassword"] ?? null;

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Please provide a valid email address"
    ]);
    exit;
}

if ($token === "") {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Reset token is required"
    ]);
    exit;
}

if (!is_string($newPassword) || strlen($newPassword) < 6) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "New password must be at least 6 characters long"
    ]);
    exit;
}

if ($confirmPassword !== null && $newPassword !== $confirmPassword) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Passwords do not match"
    ]);
    exit;
}

try {
    $stmt = $conn->prepare(
        "SELECT id, reset_token, reset_expires_at FROM users WHERE email = ?"
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "User account not found"
        ]);
        exit;
    }

    if (empty($user["reset_token"]) || $user["reset_token"] !== $token) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Invalid reset token"
        ]);
        exit;
    }

    if (strtotime($user["reset_expires_at"]) < time()) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Reset token has expired. Please request a new one."
        ]);
        exit;
    }

    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

    $updateStmt = $conn->prepare(
        "UPDATE users SET password = ?, reset_token = NULL, reset_expires_at = NULL WHERE id = ?"
    );
    $updateStmt->execute([$hashedPassword, $user["id"]]);

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Password reset successfully. You can now login with your new password."
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Unable to reset password: " . $e->getMessage()
    ]);
}

