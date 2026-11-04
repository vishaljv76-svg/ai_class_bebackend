<?php
/**
 * Automated Verification Suite for Backend Security & Validation
 * SmartNotes AI Backend
 */

$baseUrl = "http://localhost/ai_class_be";
$tokenUser7 = "e0955a80dac4aeab5ca36214f89b7f9799f6689065aa0975eb005c48c47fb67c";
$tokenUser6 = "3ebebbd6c1d0315c42f9fd3b0fe85984a1a33029485796a8420c5b285fac1c71";
$userId7 = 7;
$userId6 = 6;
$noteId7 = 17; // User 7's private note

$passCount = 0;
$failCount = 0;

function httpRequest(string $method, string $url, array $headers = [], $body = null): array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $formattedHeaders = [];
    foreach ($headers as $k => $v) {
        $formattedHeaders[] = "$k: $v";
    }

    if ($body !== null) {
        $payload = is_array($body) ? json_encode($body) : $body;
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        if (!isset($headers["Content-Type"])) {
            $formattedHeaders[] = "Content-Type: application/json";
        }
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $formattedHeaders);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $decoded = json_decode($response, true);
    return [
        "code" => $httpCode,
        "body" => $decoded,
        "raw" => $response,
        "content_type" => $contentType,
        "error" => $curlError
    ];
}

function assertTest(string $name, bool $condition, string $detail = "") {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "[PASS] {$name}\n";
    } else {
        $failCount++;
        echo "[FAIL] {$name} - {$detail}\n";
    }
}

echo "====================================================\n";
echo "SMARTNOTES AI - BACKEND SECURITY & VALIDATION AUDIT\n";
echo "====================================================\n\n";

// ---------------------------------------------------------
// 1. SQL INJECTION DEFENSE & PREPARED STATEMENTS
// ---------------------------------------------------------
echo "--- 1. Testing SQL Injection Defense ---\n";

// 1.1 SQL Injection in Auth Login
$sqliPayload = "' OR '1'='1";
$res = httpRequest("POST", "$baseUrl/auth/login.php", [], [
    "email" => "admin@campus.edu$sqliPayload",
    "password" => "' OR '1'='1"
]);
assertTest("1.1 SQL injection in login rejected (400 or 401)", in_array($res["code"], [400, 401]) && ($res["body"]["success"] ?? true) === false);

// 1.2 SQL Injection in Note ID parameter
$res = httpRequest("GET", "$baseUrl/notes/get_note.php?id=1%20OR%201=1", [
    "Authorization" => "Bearer $tokenUser7"
]);
// FILTER_VALIDATE_INT strips or fails on non-integers, returning 400 Bad Request
assertTest("1.2 SQL injection in Note ID parameter rejected (400 Bad Request)", $res["code"] === 400);

// 1.3 SQL Injection in Search Query (Prepared Statement check)
$res = httpRequest("GET", "$baseUrl/notes/list_notes.php?user_id=$userId7&search=" . urlencode("'; DROP TABLE test; --"));
assertTest("1.3 Malicious search string handled safely without syntax error (200 OK)", $res["code"] === 200 && ($res["body"]["success"] ?? false) === true);

// 1.4 SQL Injection in User ID parameter
$res = httpRequest("GET", "$baseUrl/dashboard/dashboard_stats.php?user_id=" . urlencode("7 UNION SELECT 1,2,3"));
assertTest("1.4 SQL injection in user_id rejected (400 Bad Request)", $res["code"] === 400);


// ---------------------------------------------------------
// 2. USER ISOLATION & ACCESS CONTROL (TENANT SECURITY)
// ---------------------------------------------------------
echo "\n--- 2. Testing User & Tenant Isolation ---\n";

// 2.1 Cross-user Note Access: User 6 attempting to view User 7's note (ID 17)
$res = httpRequest("GET", "$baseUrl/notes/get_note.php?id=$noteId7", [
    "Authorization" => "Bearer $tokenUser6"
]);
assertTest("2.1 Cross-user Note View blocked with 403 Forbidden", $res["code"] === 403);

// 2.2 Cross-user Note Edit: User 6 attempting to update User 7's note (ID 17)
$res = httpRequest("POST", "$baseUrl/notes/update_note.php", [
    "Authorization" => "Bearer $tokenUser6"
], [
    "id" => $noteId7,
    "title" => "Hacked Note Title"
]);
assertTest("2.2 Cross-user Note Update blocked with 403 Forbidden", $res["code"] === 403);

// 2.3 Cross-user Note Delete: User 6 attempting to delete User 7's note (ID 17)
$res = httpRequest("POST", "$baseUrl/notes/delete_note.php", [
    "Authorization" => "Bearer $tokenUser6"
], [
    "id" => $noteId7
]);
assertTest("2.3 Cross-user Note Deletion blocked with 403 Forbidden", $res["code"] === 403);

// 2.4 Cross-user Dashboard Access: User 6 trying to read User 7's dashboard
$res = httpRequest("GET", "$baseUrl/dashboard/dashboard_stats.php?user_id=$userId7", [
    "Authorization" => "Bearer $tokenUser6"
]);
assertTest("2.4 Cross-user Dashboard stats blocked with 403 Forbidden", $res["code"] === 403);

// 2.5 Cross-user Notes List: User 6 trying to list User 7's notes
$res = httpRequest("GET", "$baseUrl/notes/list_notes.php?user_id=$userId7", [
    "Authorization" => "Bearer $tokenUser6"
]);
assertTest("2.5 Cross-user Notes Listing blocked with 403 Forbidden", $res["code"] === 403);

// 2.6 Cross-user Audio List: User 6 trying to list User 7's audio files
$res = httpRequest("GET", "$baseUrl/audio/list_audio.php?user_id=$userId7", [
    "Authorization" => "Bearer $tokenUser6"
]);
assertTest("2.6 Cross-user Audio Listing blocked with 403 Forbidden", $res["code"] === 403);

// 2.7 Cross-user Reminders List: User 6 trying to list User 7's reminders
$res = httpRequest("GET", "$baseUrl/notifications/list_reminders.php?user_id=$userId7", [
    "Authorization" => "Bearer $tokenUser6"
]);
assertTest("2.7 Cross-user Reminders Listing blocked with 403 Forbidden", $res["code"] === 403);

// 2.8 Cross-user Progress Access: User 6 trying to view User 7's study progress
$res = httpRequest("GET", "$baseUrl/progress/get_progress.php?user_id=$userId7", [
    "Authorization" => "Bearer $tokenUser6"
]);
assertTest("2.8 Cross-user Study Progress blocked with 403 Forbidden", $res["code"] === 403);

// 2.9 Cross-user Files List: User 6 trying to list User 7's uploaded files
$res = httpRequest("GET", "$baseUrl/files/list_files.php?user_id=$userId7", [
    "Authorization" => "Bearer $tokenUser6"
]);
assertTest("2.9 Cross-user Files Listing blocked with 403 Forbidden", $res["code"] === 403);


// ---------------------------------------------------------
// 3. SENSITIVE FILE PROTECTION & DIRECTORY INDEXING (.htaccess)
// ---------------------------------------------------------
echo "\n--- 3. Testing Sensitive File & Directory Protection ---\n";

// 3.1 Block direct HTTP access to database dumps (.sql)
$res = httpRequest("GET", "$baseUrl/database/ai_class.sql");
assertTest("3.1 Direct access to ai_class.sql blocked (403 Forbidden)", $res["code"] === 403);

// 3.2 Block direct HTTP access to backup SQL files
$res = httpRequest("GET", "$baseUrl/database/backup_before_security_hardening.sql");
assertTest("3.2 Direct access to backup SQL dump blocked (403 Forbidden)", $res["code"] === 403);

// 3.3 Block direct HTTP access to configuration files
$res = httpRequest("GET", "$baseUrl/config/config.php");
assertTest("3.3 Direct access to config/config.php blocked (403 Forbidden)", $res["code"] === 403);

// 3.4 Block direct HTTP access to database folder listing
$res = httpRequest("GET", "$baseUrl/database/");
assertTest("3.4 Database directory listing disabled (403 Forbidden)", $res["code"] === 403);

// 3.5 Block direct HTTP access to uploads folder listing
$res = httpRequest("GET", "$baseUrl/uploads/");
assertTest("3.5 Uploads directory listing disabled (403 Forbidden)", $res["code"] === 403);

// 3.6 Block direct access to hidden files (.gitignore, .git, etc.)
$res = httpRequest("GET", "$baseUrl/.gitignore");
assertTest("3.6 Direct access to .gitignore blocked (403 Forbidden)", $res["code"] === 403);


// ---------------------------------------------------------
// 4. MALICIOUS SCRIPT UPLOAD PREVENTION (ANTI-RCE)
// ---------------------------------------------------------
echo "\n--- 4. Testing Upload Validation & Anti-RCE ---\n";

// 4.1 Reject malicious script in files module
$res = httpRequest("POST", "$baseUrl/files/upload_file.php", [
    "Authorization" => "Bearer $tokenUser7"
], [
    "file_base64" => base64_encode("<?php phpinfo(); ?>"),
    "file_name" => "exploit.php"
]);
assertTest("4.1 Reject .php upload in files module (400 Bad Request)", $res["code"] === 400);

// 4.2 Reject executable extensions in audio module (.exe)
$res = httpRequest("POST", "$baseUrl/audio/upload_audio.php", [
    "Authorization" => "Bearer $tokenUser7"
], [
    "audio_base64" => base64_encode("MZ9000ExecutableMock"),
    "fileName" => "trojan.exe"
]);
assertTest("4.2 Reject .exe upload in audio module (400 Bad Request)", $res["code"] === 400);

// 4.3 Reject non-image upload in profile photo module
$res = httpRequest("POST", "$baseUrl/profile/profile_upload_photo.php", [
    "Authorization" => "Bearer $tokenUser7"
], [
    "image_base64" => base64_encode("GIF89a<?php system(\$_GET['cmd']); ?>"),
    "file_name" => "webshell.php"
]);
assertTest("4.3 Reject fake image script in profile photo module (400 Bad Request)", $res["code"] === 400);


// ---------------------------------------------------------
// 5. INPUT VALIDATION & SAFE ERROR HANDLING
// ---------------------------------------------------------
echo "\n--- 5. Testing Input Validation & Error Handling ---\n";

// 5.1 Invalid Email Validation in Signup
$res = httpRequest("POST", "$baseUrl/auth/signup.php", [], [
    "name" => "Test Student",
    "email" => "invalid-not-an-email",
    "password" => "password123"
]);
assertTest("5.1 Reject invalid email format (400 Bad Request)", $res["code"] === 400 && strpos($res["body"]["message"] ?? "", "valid email") !== false);

// 5.2 Short Password Validation in Signup
$res = httpRequest("POST", "$baseUrl/auth/signup.php", [], [
    "name" => "Test Student",
    "email" => "student_test_sec@campus.edu",
    "password" => "123"
]);
assertTest("5.2 Reject password shorter than 6 characters (400 Bad Request)", $res["code"] === 400);

// 5.3 Empty Payload Validation
$res = httpRequest("POST", "$baseUrl/notes/create_note.php", [
    "Authorization" => "Bearer $tokenUser7"
], []);
assertTest("5.3 Reject empty payload (400 Bad Request)", $res["code"] === 400);

// 5.4 Method Not Allowed (GET on signup.php)
$res = httpRequest("GET", "$baseUrl/auth/signup.php");
assertTest("5.4 Method Not Allowed returns 405", $res["code"] === 405);

// 5.5 Check Information Disclosure Prevention: Error responses should not leak SQL/credentials
$res = httpRequest("POST", "$baseUrl/auth/login.php", [], [
    "email" => "nonexistent_student_test@campus.edu",
    "password" => "wrong_password"
]);
$rawBody = $res["raw"] ?? "";
$noLeakedCredentials = (strpos($rawBody, "root") === false && strpos($rawBody, "SQLSTATE") === false && strpos($rawBody, "ai_class") === false);
assertTest("5.5 Login error response does NOT leak database credentials or SQL syntax", $noLeakedCredentials);


echo "\n====================================================\n";
echo "TEST RESULTS: PASS: $passCount | FAIL: $failCount\n";
echo "====================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
