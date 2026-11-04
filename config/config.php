<?php
// CORS headers for Android App, Web & API clients
if (!headers_sent()) {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Auth-Token");
}

if (isset($_SERVER["REQUEST_METHOD"]) && $_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// Database Credentials (with Environment Variable Override Support)
$host = getenv('DB_HOST') ?: "localhost";
$dbname = getenv('DB_NAME') ?: "ai_class";
$username = getenv('DB_USER') ?: "root";
$password = getenv('DB_PASS') !== false ? getenv('DB_PASS') : "";

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
        error_log("[Database Connection Error]: " . $e2->getMessage());
        if (!headers_sent()) {
            header("Content-Type: application/json; charset=UTF-8");
            http_response_code(500);
        }
        echo json_encode([
            "success" => false,
            "message" => "Database connection failed. Please verify database server is running."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/**
 * Helper to emit standardized safe JSON error responses without leaking internal server/SQL traces
 */
if (!function_exists('sendSafeJsonError')) {
    function sendSafeJsonError(int $httpCode, string $publicMessage, ?Throwable $e = null) {
        if ($e !== null) {
            error_log("[SmartNotes Backend Error] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        }
        if (!headers_sent()) {
            header("Content-Type: application/json; charset=UTF-8");
            http_response_code($httpCode);
        }
        echo json_encode([
            "success" => false,
            "message" => $publicMessage
        ], JSON_UNESCAPED_UNICODE);
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
