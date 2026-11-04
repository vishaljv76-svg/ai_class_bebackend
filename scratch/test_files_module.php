<?php
/**
 * Automated Verification Suite for File Management Module
 * SmartNotes AI Backend
 */

$baseUrl = "http://localhost/ai_class_be";
$tokenUser7 = "e0955a80dac4aeab5ca36214f89b7f9799f6689065aa0975eb005c48c47fb67c";
$tokenUser6 = "3ebebbd6c1d0315c42f9fd3b0fe85984a1a33029485796a8420c5b285fac1c71";
$userId7 = 7;
$userId6 = 6;
$userId8 = 8;
$noteId = 17;

$passCount = 0;
$failCount = 0;

function httpRequest(string $method, string $url, array $headers = [], $body = null, bool $isMultipart = false): array {
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
        if ($isMultipart) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } else {
            $payload = is_array($body) ? json_encode($body) : $body;
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            if (!isset($headers["Content-Type"])) {
                $formattedHeaders[] = "Content-Type: application/json";
            }
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
echo "SMARTNOTES AI - FILE MANAGEMENT MODULE TEST SUITE\n";
echo "====================================================\n\n";

// ---------------------------------------------------------
// 1. UPLOAD FILE TESTS (Multipart & Base64)
// ---------------------------------------------------------
echo "--- 1. Testing Upload File (POST /files/upload_file.php) ---\n";

// Create temporary dummy study files for testing
$tmpPdfPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "test_normalization_guide.pdf";
$pdfContent = "%PDF-1.4\n1 0 obj\n<< /Title (Database Normalization Guide) >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";
file_put_contents($tmpPdfPath, $pdfContent);

$tmpDocxPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "lecture_notes_distributed_systems.docx";
$docxContent = "PK\x03\x04\x14\x00\x06\x00Sample Mock DOCX Binary Content For Testing File Upload";
file_put_contents($tmpDocxPath, $docxContent);

// 1.1 Upload PDF via Multipart Form-Data with Bearer Token
$cfilePdf = new CURLFile($tmpPdfPath, "application/pdf", "test_normalization_guide.pdf");
$res = httpRequest("POST", "$baseUrl/files/upload_file.php", [
    "Authorization" => "Bearer $tokenUser7"
], [
    "file" => $cfilePdf,
    "title" => "Database Normalization Study Guide",
    "note_id" => $noteId
], true);

assertTest("1.1 Upload PDF with Bearer token (201 Created)", $res["code"] === 201 && ($res["body"]["success"] ?? false) === true);
$createdFileId1 = $res["body"]["file_id"] ?? null;
assertTest("1.1 File ID returned", $createdFileId1 > 0);
assertTest("1.1 File category is 'document'", ($res["body"]["file"]["category"] ?? "") === "document");
assertTest("1.1 File extension is 'PDF'", ($res["body"]["file"]["file_extension"] ?? "") === "PDF");

// Verify physical existence on disk
$storedName1 = $res["body"]["file"]["stored_name"] ?? "";
$physicalPath1 = __DIR__ . "/../uploads/documents/" . $storedName1;
assertTest("1.1 Physical file exists on server disk", file_exists($physicalPath1) && filesize($physicalPath1) > 0);

// Verify MySQL database record
require_once __DIR__ . '/../config/config.php';
$stmt = $conn->prepare("SELECT * FROM study_files WHERE id = ?");
$stmt->execute([$createdFileId1]);
$dbRow1 = $stmt->fetch(PDO::FETCH_ASSOC);
assertTest("1.1 MySQL record saved correctly", $dbRow1 && (int)$dbRow1["user_id"] === $userId7 && (int)$dbRow1["note_id"] === $noteId);

// 1.2 Upload DOCX via Multipart Form-Data with user_id parameter
$cfileDocx = new CURLFile($tmpDocxPath, "application/vnd.openxmlformats-officedocument.wordprocessingml.document", "lecture_notes_distributed_systems.docx");
$res = httpRequest("POST", "$baseUrl/files/upload_file.php", [], [
    "user_id" => $userId7,
    "file" => $cfileDocx,
    "title" => "Distributed Systems Consensus Notes"
], true);

assertTest("1.2 Upload DOCX with user_id param (201 Created)", $res["code"] === 201 && ($res["body"]["success"] ?? false) === true);
$createdFileId2 = $res["body"]["file_id"] ?? null;
assertTest("1.2 DOCX File ID returned", $createdFileId2 > 0);

// 1.3 Upload Text File via Base64 JSON Payload
$txtContent = "Quick Study Guide: Boyce-Codd Normal Form requires every determinant to be a candidate key.";
$base64Txt = base64_encode($txtContent);
$res = httpRequest("POST", "$baseUrl/files/upload_file.php", [
    "Authorization" => "Bearer $tokenUser7"
], [
    "file_base64" => $base64Txt,
    "file_name" => "bcnf_rules_summary.txt",
    "title" => "BCNF Rules Summary"
]);

assertTest("1.3 Upload file via Base64 JSON (201 Created)", $res["code"] === 201 && ($res["body"]["success"] ?? false) === true);
$createdFileId3 = $res["body"]["file_id"] ?? null;
assertTest("1.3 Base64 File ID returned", $createdFileId3 > 0);

// ---------------------------------------------------------
// 2. VALIDATION & SECURITY TESTS ON UPLOAD
// ---------------------------------------------------------
echo "\n--- 2. Testing File Validation & Upload Security ---\n";

// 2.1 Reject Dangerous Script Extension (.php)
$tmpPhpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "malicious_script.php";
file_put_contents($tmpPhpPath, "<?php phpinfo(); ?>");
$cfilePhp = new CURLFile($tmpPhpPath, "application/x-php", "malicious_script.php");
$res = httpRequest("POST", "$baseUrl/files/upload_file.php", [
    "Authorization" => "Bearer $tokenUser7"
], [
    "file" => $cfilePhp
], true);
assertTest("2.1 Reject dangerous PHP extension (400 Bad Request)", $res["code"] === 400 && ($res["body"]["success"] ?? true) === false);
@unlink($tmpPhpPath);

// 2.2 Reject Empty File (0 Bytes)
$tmpEmptyPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "empty_file.pdf";
file_put_contents($tmpEmptyPath, "");
$cfileEmpty = new CURLFile($tmpEmptyPath, "application/pdf", "empty_file.pdf");
$res = httpRequest("POST", "$baseUrl/files/upload_file.php", [
    "Authorization" => "Bearer $tokenUser7"
], [
    "file" => $cfileEmpty
], true);
assertTest("2.2 Reject empty file 0 bytes (400 Bad Request)", $res["code"] === 400);
@unlink($tmpEmptyPath);

// 2.3 Reject Missing User Identifier
$res = httpRequest("POST", "$baseUrl/files/upload_file.php", [], [
    "file_base64" => $base64Txt,
    "file_name" => "orphan.txt"
]);
assertTest("2.3 Reject upload with missing user (400 Bad Request)", $res["code"] === 400);

// 2.4 Reject Non-Existent User
$res = httpRequest("POST", "$baseUrl/files/upload_file.php", [], [
    "user_id" => 999999,
    "file_base64" => $base64Txt,
    "file_name" => "ghost_user.txt"
]);
assertTest("2.4 Reject non-existent user (404 Not Found)", $res["code"] === 404);

// 2.5 Method Not Allowed (GET on upload_file.php)
$res = httpRequest("GET", "$baseUrl/files/upload_file.php");
assertTest("2.5 Reject GET method on upload (405 Method Not Allowed)", $res["code"] === 405);


// ---------------------------------------------------------
// 3. LIST FILES TESTS
// ---------------------------------------------------------
echo "\n--- 3. Testing List Files (GET /files/list_files.php) ---\n";

// 3.1 List files for User 7
$res = httpRequest("GET", "$baseUrl/files/list_files.php?user_id=$userId7");
assertTest("3.1 List files returns 200 OK", $res["code"] === 200 && ($res["body"]["success"] ?? false) === true);
assertTest("3.1 Summary contains total_files >= 3", ($res["body"]["summary"]["total_files"] ?? 0) >= 3);
assertTest("3.1 Summary contains total_size_formatted", !empty($res["body"]["summary"]["total_size_formatted"]));
assertTest("3.1 Files array is populated", is_array($res["body"]["files"]) && count($res["body"]["files"]) >= 3);

// 3.2 List files with Bearer Token
$res = httpRequest("GET", "$baseUrl/files/list_files.php", [
    "Authorization" => "Bearer $tokenUser7"
]);
assertTest("3.2 List files with Bearer token (200 OK)", $res["code"] === 200 && ($res["body"]["user_id"] ?? 0) === $userId7);

// 3.3 Filter by category = 'document'
$res = httpRequest("GET", "$baseUrl/files/list_files.php?user_id=$userId7&category=document");
assertTest("3.3 Filter by category=document returns 200 OK", $res["code"] === 200);
$allDocs = true;
foreach ($res["body"]["files"] ?? [] as $f) {
    if ($f["category"] !== "document") $allDocs = false;
}
assertTest("3.3 All returned files belong to 'document' category", $allDocs && count($res["body"]["files"]) >= 2);

// 3.4 Filter by extension = 'pdf'
$res = httpRequest("GET", "$baseUrl/files/list_files.php?user_id=$userId7&extension=pdf");
assertTest("3.4 Filter by extension=pdf returns 200 OK", $res["code"] === 200);
$allPdf = true;
foreach ($res["body"]["files"] ?? [] as $f) {
    if ($f["file_extension"] !== "PDF") $allPdf = false;
}
assertTest("3.4 All returned files have PDF extension", $allPdf && count($res["body"]["files"]) >= 1);

// 3.5 Search by filename/title ('BCNF')
$res = httpRequest("GET", "$baseUrl/files/list_files.php?user_id=$userId7&search=BCNF");
assertTest("3.5 Search files by keyword returns 200 OK", $res["code"] === 200 && count($res["body"]["files"]) >= 1);

// 3.6 Pagination (limit=1, offset=0)
$res = httpRequest("GET", "$baseUrl/files/list_files.php?user_id=$userId7&limit=1&offset=0");
assertTest("3.6 Pagination limit=1 works", $res["code"] === 200 && count($res["body"]["files"]) === 1);

// 3.7 Method Not Allowed (DELETE on list_files.php)
$res = httpRequest("DELETE", "$baseUrl/files/list_files.php");
assertTest("3.7 Reject DELETE method on list (405 Method Not Allowed)", $res["code"] === 405);


// ---------------------------------------------------------
// 4. GET FILE DETAILS TESTS
// ---------------------------------------------------------
echo "\n--- 4. Testing Get File Details (GET /files/get_file.php) ---\n";

// 4.1 Fetch file details by valid ID
$res = httpRequest("GET", "$baseUrl/files/get_file.php?file_id=$createdFileId1", [
    "Authorization" => "Bearer $tokenUser7"
]);
assertTest("4.1 Get file details returns 200 OK", $res["code"] === 200 && ($res["body"]["success"] ?? false) === true);
assertTest("4.1 Returns file title", ($res["body"]["file"]["title"] ?? "") === "Database Normalization Study Guide");
assertTest("4.1 Physical file exists flag is true", ($res["body"]["file"]["physical_file_exists"] ?? false) === true);
assertTest("4.1 Linked note details joined", ($res["body"]["file"]["note"]["id"] ?? null) === $noteId);

// 4.2 Non-Existent File ID
$res = httpRequest("GET", "$baseUrl/files/get_file.php?file_id=999999", [
    "Authorization" => "Bearer $tokenUser7"
]);
assertTest("4.2 Non-existent file ID returns 404 Not Found", $res["code"] === 404);

// 4.3 Missing file_id parameter
$res = httpRequest("GET", "$baseUrl/files/get_file.php", [
    "Authorization" => "Bearer $tokenUser7"
]);
assertTest("4.3 Missing file_id returns 400 Bad Request", $res["code"] === 400);

// 4.4 Cross-User Access Prevention: User 6 attempting to get User 7's file
$res = httpRequest("GET", "$baseUrl/files/get_file.php?file_id=$createdFileId1", [
    "Authorization" => "Bearer $tokenUser6"
]);
assertTest("4.4 Cross-user file access blocked with 403 Forbidden", $res["code"] === 403);


// ---------------------------------------------------------
// 5. DOWNLOAD & ACCESS FILE TESTS
// ---------------------------------------------------------
echo "\n--- 5. Testing Download & Access File (GET /files/download_file.php) ---\n";

// 5.1 Authorized Download (Attachment) with Bearer Token
$res = httpRequest("GET", "$baseUrl/files/download_file.php?file_id=$createdFileId1", [
    "Authorization" => "Bearer $tokenUser7"
]);
assertTest("5.1 Download file returns 200 OK", $res["code"] === 200);
assertTest("5.1 Content-Type is application/pdf", strpos($res["content_type"], "application/pdf") !== false);
assertTest("5.1 Downloaded content matches uploaded PDF", strpos($res["raw"], "%PDF-1.4") !== false);

// 5.2 Authorized View/Preview (Inline) with view=1
$res = httpRequest("GET", "$baseUrl/files/download_file.php?file_id=$createdFileId3&view=1", [
    "Authorization" => "Bearer $tokenUser7"
]);
assertTest("5.2 View file inline returns 200 OK", $res["code"] === 200);
assertTest("5.2 Inline content matches uploaded TXT", strpos($res["raw"], "Boyce-Codd Normal Form") !== false);

// 5.3 Cross-User Download Prevention: User 6 attempting to download User 7's file
$res = httpRequest("GET", "$baseUrl/files/download_file.php?file_id=$createdFileId1", [
    "Authorization" => "Bearer $tokenUser6"
]);
assertTest("5.3 Cross-user file download blocked with 403 Forbidden", $res["code"] === 403);

// 5.4 Unauthenticated Download Attempt (No token and no userId)
$res = httpRequest("GET", "$baseUrl/files/download_file.php?file_id=$createdFileId1");
assertTest("5.4 Unauthenticated download rejected with 401 Unauthorized", $res["code"] === 401);

// 5.5 Download Non-Existent File
$res = httpRequest("GET", "$baseUrl/files/download_file.php?file_id=999999", [
    "Authorization" => "Bearer $tokenUser7"
]);
assertTest("5.5 Non-existent file download returns 404 Not Found", $res["code"] === 404);


// ---------------------------------------------------------
// 6. DELETE FILE TESTS
// ---------------------------------------------------------
echo "\n--- 6. Testing Delete File (DELETE /files/delete_file.php) ---\n";

// 6.1 Cross-User Delete Prevention: User 6 attempting to delete User 7's file
$res = httpRequest("DELETE", "$baseUrl/files/delete_file.php?file_id=$createdFileId3", [
    "Authorization" => "Bearer $tokenUser6"
]);
assertTest("6.1 Cross-user delete blocked with 403 Forbidden", $res["code"] === 403);

// 6.2 Safe Delete of File 3 by Owner (User 7)
$res = httpRequest("DELETE", "$baseUrl/files/delete_file.php?file_id=$createdFileId3", [
    "Authorization" => "Bearer $tokenUser7"
]);
assertTest("6.2 Delete file returns 200 OK", $res["code"] === 200 && ($res["body"]["success"] ?? false) === true);
assertTest("6.2 Deleted file ID returned", ($res["body"]["deleted_file_id"] ?? null) === $createdFileId3);
assertTest("6.2 Physical file reported deleted", ($res["body"]["physical_file_deleted"] ?? false) === true);

// Verify record deleted from MySQL
$stmt = $conn->prepare("SELECT * FROM study_files WHERE id = ?");
$stmt->execute([$createdFileId3]);
assertTest("6.2 File record removed from MySQL database", $stmt->fetch() === false);

// 6.3 Attempt to delete already deleted file
$res = httpRequest("DELETE", "$baseUrl/files/delete_file.php?file_id=$createdFileId3", [
    "Authorization" => "Bearer $tokenUser7"
]);
assertTest("6.3 Deleting already deleted file returns 404 Not Found", $res["code"] === 404);

// 6.4 Delete File 2 via POST method
$res = httpRequest("POST", "$baseUrl/files/delete_file.php", [
    "Authorization" => "Bearer $tokenUser7"
], [
    "file_id" => $createdFileId2
]);
assertTest("6.4 POST method on delete_file works (200 OK)", $res["code"] === 200);


// ---------------------------------------------------------
// 7. USER ISOLATION & PRIVACY VERIFICATION
// ---------------------------------------------------------
echo "\n--- 7. Testing User Isolation & Privacy ---\n";

// User 8 has 0 files uploaded; verify no data leakage
$res = httpRequest("GET", "$baseUrl/files/list_files.php?user_id=$userId8");
assertTest("7.1 User 8 list files returns 200 OK", $res["code"] === 200);
assertTest("7.1 User 8 has 0 files (zero dummy data)", ($res["body"]["summary"]["total_files"] ?? -1) === 0);
assertTest("7.1 User 8 files array is empty", empty($res["body"]["files"]));

// User 6 should not see any files belonging to User 7
$res = httpRequest("GET", "$baseUrl/files/list_files.php?user_id=$userId6");
assertTest("7.2 User 6 lists files without leakage", $res["code"] === 200);
$leakFound = false;
foreach ($res["body"]["files"] ?? [] as $f) {
    if ((int)$f["user_id"] !== $userId6) $leakFound = true;
}
assertTest("7.2 No files leaked from other users", !$leakFound);


// Cleanup local temporary test files
@unlink($tmpPdfPath);
@unlink($tmpDocxPath);

echo "\n====================================================\n";
echo "TEST RESULTS: PASS: $passCount | FAIL: $failCount\n";
echo "====================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);

