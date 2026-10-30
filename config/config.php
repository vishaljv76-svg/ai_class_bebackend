<?php
// CORS headers for Android App, Web & API clients
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

$host = "localhost";
$dbname = "ai_class";
$username = "root";
$password = "";

try {
    $conn = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );

    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

} catch (PDOException $e) {
    // If ai_class fails, fallback to smartnotes_ai
    try {
        $conn = new PDO(
            "mysql:host=$host;dbname=smartnotes_ai;charset=utf8mb4",
            $username,
            $password
        );
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    } catch (PDOException $e2) {
        header("Content-Type: application/json");
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Database connection failed"
        ]);
        exit;
    }
}

/**
 * Helper to compute user initials (e.g. "Alex Morgan" -> "AM")
 */
function getInitials($name) {
    $words = preg_split('/\s+/', trim($name));
    $initials = "";
    foreach ($words as $w) {
        if (!empty($w)) {
            $initials .= strtoupper(mb_substr($w, 0, 1));
        }
    }
    return substr($initials, 0, 2) ?: "U";
}

/**
 * Helper to parse semester string/int to integer
 */
function parseSemester($input) {
    if (is_numeric($input)) {
        return (int)$input;
    }
    if (is_string($input) && preg_match('/\d+/', $input, $matches)) {
        return (int)$matches[0];
    }
    return null;
}

/**
 * Helper to compute academic year text
 */
function getYearBadge($semester) {
    if (!$semester || $semester < 1) return "";
    $year = ceil($semester / 2);
    if ($year >= 4) return "Final year";
    if ($year == 1) return "1st year";
    if ($year == 2) return "2nd year";
    if ($year == 3) return "3rd year";
    return "Year " . $year;
}
