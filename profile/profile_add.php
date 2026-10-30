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

// Support both standard and UI state property names
$user_id = filter_var($data["user_id"] ?? $data["userId"] ?? null, FILTER_VALIDATE_INT);
$course = trim($data["course"] ?? $data["selectedCourse"] ?? "");
$department = trim($data["department"] ?? $data["selectedDepartment"] ?? "");
$semesterInput = $data["semester"] ?? $data["selectedSemester"] ?? null;
$studentName = trim($data["name"] ?? $data["studentName"] ?? "");

$semester = parseSemester($semesterInput);

if (!$user_id || $user_id < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid user_id is required"
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
    // Verify user exists
    $checkUser = $conn->prepare("SELECT id, name, email FROM users WHERE id = ?");
    $checkUser->execute([$user_id]);
    $user = $checkUser->fetch();

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "User not found. Please signup first."
        ]);
        exit;
    }

    // If student name is provided and changed, update user name
    if ($studentName !== "" && $studentName !== $user["name"]) {
        $updateName = $conn->prepare("UPDATE users SET name = ? WHERE id = ?");
        $updateName->execute([$studentName, $user_id]);
        $user["name"] = $studentName;
    }

    // Check if profile already exists (upsert logic)
    $check = $conn->prepare("SELECT id FROM student_profiles WHERE user_id = ?");
    $check->execute([$user_id]);
    $existing = $check->fetch();

    if ($existing) {
        // Update existing profile
        $updateStmt = $conn->prepare(
            "UPDATE student_profiles SET course = ?, department = ?, semester = ? WHERE user_id = ?"
        );
        $updateStmt->execute([$course, $department, $semester, $user_id]);
        $profileId = (int)$existing["id"];
        $statusCode = 200;
        $message = "Profile updated successfully";
    } else {
        // Insert new profile
        $insertStmt = $conn->prepare(
            "INSERT INTO student_profiles (user_id, course, department, semester) VALUES (?, ?, ?, ?)"
        );
        $insertStmt->execute([$user_id, $course, $department, $semester]);
        $profileId = (int)$conn->lastInsertId();
        $statusCode = 201;
        $message = "Profile created successfully";
    }

    http_response_code($statusCode);
    echo json_encode([
        "success" => true,
        "message" => $message,
        "profile_id" => $profileId,
        "profile" => [
            "id" => $profileId,
            "user_id" => $user_id,
            "student_name" => $user["name"],
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
        "message" => "Unable to save profile: " . $e->getMessage()
    ]);
}
