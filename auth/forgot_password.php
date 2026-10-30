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

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Please enter a valid email address"
    ]);
    exit;
}

try {
    $stmt = $conn->prepare("SELECT id, name FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "No account found with this email address"
        ]);
        exit;
    }

    // Generate a 6-digit OTP reset token
    $resetToken = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $expiresAt = date("Y-m-d H:i:s", strtotime("+30 minutes"));

    $updateStmt = $conn->prepare(
        "UPDATE users SET reset_token = ?, reset_expires_at = ? WHERE id = ?"
    );
    $updateStmt->execute([$resetToken, $expiresAt, $user["id"]]);

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "A password reset code has been sent. It expires in 30 minutes.",
        "reset_token" => $resetToken,
        "expires_in_minutes" => 30
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Password reset request failed: " . $e->getMessage()
    ]);
}

