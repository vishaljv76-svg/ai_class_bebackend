<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../common/auth_middleware.php";

// Allow GET and POST for session validation
if ($_SERVER["REQUEST_METHOD"] !== "GET" && $_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed. GET or POST method required."
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
        }
    }
}

// Require valid authenticated user session (401 if missing, expired, or revoked)
$authUser = getAuthenticatedUser($conn, true);
$userId = (int)$authUser["id"];

try {
    // 1. Fetch fresh user record
    $userStmt = $conn->prepare("SELECT id, name, email, created_at FROM users WHERE id = ?");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch();

    if (!$user) {
        http_response_code(401);
        echo json_encode([
            "success" => false,
            "message" => "User account no longer exists. Please register or login again."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. Fetch student profile if present
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

    // 3. Return active session confirmation
    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Session is valid",
        "user_id" => $userId,
        "token" => $authUser["token"],
        "user" => [
            "id" => $userId,
            "name" => $user["name"],
            "email" => $user["email"],
            "initials" => getInitials($user["name"])
        ],
        "has_profile" => $hasProfile,
        "profile" => $profile
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error validating session: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
