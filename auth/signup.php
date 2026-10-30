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

$data = json_decode(file_get_contents("php://input"), true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON payload"
    ]);
    exit;
}

// Support both 'name' and 'studentName'
$name = trim($data["name"] ?? $data["studentName"] ?? "");
$email = trim($data["email"] ?? "");
$password = $data["password"] ?? "";
$confirmPassword = $data["confirm_password"] ?? $data["confirmPassword"] ?? null;

if ($name === "") {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Student name is required"
    ]);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Please enter a valid email address"
    ]);
    exit;
}

if (!is_string($password) || strlen($password) < 6) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Password must be at least 6 characters long"
    ]);
    exit;
}

if ($confirmPassword !== null && $password !== $confirmPassword) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Passwords do not match"
    ]);
    exit;
}

try {
    $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $check->execute([$email]);

    if ($check->fetch()) {
        http_response_code(409);
        echo json_encode([
            "success" => false,
            "message" => "Email already registered. Please login instead."
        ]);
        exit;
    }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $conn->prepare(
        "INSERT INTO users (name, email, password) VALUES (?, ?, ?)"
    );
    $stmt->execute([$name, $email, $hashedPassword]);

    $newUserId = (int)$conn->lastInsertId();

    // Generate secure session token
    $tokenData = generateAuthToken($conn, $newUserId);

    http_response_code(201);
    echo json_encode([
        "success" => true,
        "message" => "Account created successfully",
        "token" => $tokenData["token"],
        "token_expires_at" => $tokenData["expires_at"],
        "user_id" => $newUserId,
        "user" => [
            "id" => $newUserId,
            "name" => $name,
            "email" => $email,
            "initials" => getInitials($name)
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Signup failed: " . $e->getMessage()
    ]);
}
