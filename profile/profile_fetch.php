<?php
header("Content-Type: application/json");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../common/auth_middleware.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "GET method required"
    ]);
    exit;
}

$authUser = getAuthenticatedUser($conn, false);

$user_id = filter_var($_GET["user_id"] ?? $_GET["userId"] ?? null, FILTER_VALIDATE_INT);
$email = trim($_GET["email"] ?? "");

// Default to authenticated user if not explicitly passed
if (!$user_id && empty($email) && $authUser) {
    $user_id = (int)$authUser["id"];
}

if ((!$user_id || $user_id < 1) && $email === "") {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid user_id, email, or Authorization token is required"
    ]);
    exit;
}

try {
    if ($user_id) {
        $stmt = $conn->prepare(
            "SELECT 
                u.id AS user_id,
                u.name,
                u.email,
                p.id AS profile_id,
                p.course,
                p.department,
                p.semester
             FROM users u
             LEFT JOIN student_profiles p ON u.id = p.user_id
             WHERE u.id = ?"
        );
        $stmt->execute([$user_id]);
    } else {
        $stmt = $conn->prepare(
            "SELECT 
                u.id AS user_id,
                u.name,
                u.email,
                p.id AS profile_id,
                p.course,
                p.department,
                p.semester
             FROM users u
             LEFT JOIN student_profiles p ON u.id = p.user_id
             WHERE u.email = ?"
        );
        $stmt->execute([$email]);
    }

    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "User account not found"
        ]);
        exit;
    }

    $hasProfile = !empty($row["profile_id"]);
    $sem = $row["semester"] ? (int)$row["semester"] : null;

    $profile = null;
    if ($hasProfile) {
        $profile = [
            "id" => (int)$row["profile_id"],
            "user_id" => (int)$row["user_id"],
            "course" => $row["course"] ?? "",
            "department" => $row["department"] ?? "",
            "semester" => $sem,
            "semester_badge" => $sem ? "Semester " . $sem : "",
            "year_badge" => getYearBadge($sem)
        ];
    }

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Profile fetched successfully",
        "has_profile" => $hasProfile,
        "user" => [
            "id" => (int)$row["user_id"],
            "name" => $row["name"],
            "email" => $row["email"],
            "initials" => getInitials($row["name"])
        ],
        "profile" => $profile
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Unable to fetch profile: " . $e->getMessage()
    ]);
}
