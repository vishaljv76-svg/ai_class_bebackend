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

$email = trim($data["email"] ?? "");
$password = $data["password"] ?? "";

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !is_string($password) || $password === "") {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid email and password are required"
    ]);
    exit;
}

try {
    $stmt = $conn->prepare(
        "SELECT id, name, email, password FROM users WHERE email = ?"
    );
    $stmt->execute([$email]);

    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user["password"])) {
        http_response_code(401);
        echo json_encode([
            "success" => false,
            "message" => "Invalid email or password"
        ]);
        exit;
    }

    $userId = (int)$user["id"];

    // Check student profile status
    $profileStmt = $conn->prepare(
        "SELECT id, course, department, semester FROM student_profiles WHERE user_id = ?"
    );
    $profileStmt->execute([$userId]);
    $profileData = $profileStmt->fetch();

    $profile = null;
    $hasProfile = false;

    if ($profileData) {
        $hasProfile = true;
        $sem = $profileData["semester"] ? (int)$profileData["semester"] : null;
        $profile = [
            "id" => (int)$profileData["id"],
            "course" => $profileData["course"],
            "department" => $profileData["department"],
            "semester" => $sem,
            "semester_badge" => $sem ? "Semester " . $sem : "",
            "year_badge" => getYearBadge($sem)
        ];
    }

    // Generate secure session token
    $tokenData = generateAuthToken($conn, $userId);

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Login successful",
        "token" => $tokenData["token"],
        "token_expires_at" => $tokenData["expires_at"],
        "user" => [
            "id" => $userId,
            "name" => $user["name"],
            "email" => $user["email"],
            "initials" => getInitials($user["name"])
        ],
        "has_profile" => $hasProfile,
        "profile" => $profile
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Login failed: " . $e->getMessage()
    ]);
}
