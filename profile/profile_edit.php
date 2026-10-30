<?php
header("Content-Type: application/json");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../common/auth_middleware.php";

$method = $_SERVER["REQUEST_METHOD"];
if ($method !== "POST" && $method !== "PUT") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "POST or PUT method required"
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

$authUser = getAuthenticatedUser($conn, false);

// Prioritize authenticated user from token to enforce ownership
$user_id = $authUser ? (int)$authUser["id"] : filter_var($data["user_id"] ?? $data["userId"] ?? null, FILTER_VALIDATE_INT);

$course = trim($data["course"] ?? $data["selectedCourse"] ?? "");
$department = trim($data["department"] ?? $data["selectedDepartment"] ?? "");
$semesterInput = $data["semester"] ?? $data["selectedSemester"] ?? null;
$studentName = trim($data["name"] ?? $data["studentName"] ?? "");

$semester = parseSemester($semesterInput);

if (!$user_id || $user_id < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid user_id or Authorization token is required"
    ]);
    exit;
}

if ($course === "" || $department === "" || !$semester || $semester < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Course, department, and valid semester are required"
    ]);
    exit;
}

try {
    // Check user exists
    $checkUser = $conn->prepare("SELECT id, name, email FROM users WHERE id = ?");
    $checkUser->execute([$user_id]);
    $user = $checkUser->fetch();

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "User not found"
        ]);
        exit;
    }

    // Update user name in users table if provided
    if ($studentName !== "" && $studentName !== $user["name"]) {
        $updateName = $conn->prepare("UPDATE users SET name = ? WHERE id = ?");
        $updateName->execute([$studentName, $user_id]);
        $user["name"] = $studentName;
    }

    $check = $conn->prepare("SELECT id FROM student_profiles WHERE user_id = ?");
    $check->execute([$user_id]);
    $existing = $check->fetch();

    if ($existing) {
        $stmt = $conn->prepare(
            "UPDATE student_profiles SET course = ?, department = ?, semester = ? WHERE user_id = ?"
        );
        $stmt->execute([$course, $department, $semester, $user_id]);
        $profileId = (int)$existing["id"];
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO student_profiles (user_id, course, department, semester) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$user_id, $course, $department, $semester]);
        $profileId = (int)$conn->lastInsertId();
    }

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Profile updated successfully",
        "user" => [
            "id" => $user_id,
            "name" => $user["name"],
            "email" => $user["email"],
            "initials" => getInitials($user["name"])
        ],
        "profile" => [
            "id" => $profileId,
            "user_id" => $user_id,
            "course" => $course,
            "department" => $department,
            "semester" => $semester,
            "semester_badge" => "Semester " . $semester,
            "year_badge" => getYearBadge($semester)
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Unable to update profile: " . $e->getMessage()
    ]);
}
