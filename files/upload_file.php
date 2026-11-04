<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../common/auth_middleware.php";
require_once __DIR__ . "/files_helper.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed. POST method required."
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

$authUser = getAuthenticatedUser($conn, false);

// Determine user ID
$userId = null;
if ($authUser) {
    $userId = (int)$authUser["id"];
} else {
    $userId = filter_var($_POST["user_id"] ?? $_POST["userId"] ?? $_GET["user_id"] ?? $_GET["userId"] ?? null, FILTER_VALIDATE_INT);
}

// Check JSON body if multipart is empty
$jsonData = null;
$rawInput = file_get_contents("php://input");
if (!empty($rawInput)) {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $jsonData = $decoded;
        if (!$userId) {
            $userId = filter_var($jsonData["user_id"] ?? $jsonData["userId"] ?? null, FILTER_VALIDATE_INT);
        }
    }
}

if (!$userId || $userId < 1) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid user_id or Authorization Bearer token is required."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 1. Verify user exists in database
    $userStmt = $conn->prepare("SELECT id, name FROM users WHERE id = ?");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch();

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "User account not found for ID: " . $userId
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. Ensure target upload directory exists
    $uploadDir = __DIR__ . "/../uploads/documents/";
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $maxSizeBytes = 50 * 1024 * 1024; // 50 MB max document size

    $originalFileName = "";
    $storedFileName = "";
    $fileSize = 0;
    $fileType = "";
    $fileExtension = "";
    $title = trim($_POST["title"] ?? ($jsonData["title"] ?? ""));
    $rawNoteId = $_POST["note_id"] ?? $_POST["noteId"] ?? ($jsonData["note_id"] ?? ($jsonData["noteId"] ?? null));
    $noteId = filter_var($rawNoteId, FILTER_VALIDATE_INT) ?: null;

    // Verify note ownership if note_id is provided
    if ($noteId) {
        $noteStmt = $conn->prepare("SELECT id FROM notes WHERE id = ? AND user_id = ?");
        $noteStmt->execute([$noteId, $userId]);
        if (!$noteStmt->fetch()) {
            $noteId = null; // Do not link to a note the user does not own
        }
    }

    // Check upload source (Multipart vs Base64 JSON)
    $fileItem = $_FILES["file"] ?? $_FILES["document"] ?? $_FILES["study_file"] ?? null;

    if ($fileItem && isset($fileItem["tmp_name"]) && is_uploaded_file($fileItem["tmp_name"])) {
        // Mode A: Standard multipart upload
        if ($fileItem["error"] !== UPLOAD_ERR_OK) {
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE => "File exceeds server upload_max_filesize limit",
                UPLOAD_ERR_FORM_SIZE => "File exceeds form MAX_FILE_SIZE limit",
                UPLOAD_ERR_PARTIAL => "File was only partially uploaded",
                UPLOAD_ERR_NO_FILE => "No file was uploaded",
                UPLOAD_ERR_NO_TMP_DIR => "Missing temporary upload folder on server",
                UPLOAD_ERR_CANT_WRITE => "Failed to write uploaded file to disk",
                UPLOAD_ERR_EXTENSION => "A server extension stopped the file upload"
            ];
            $errorMsg = $uploadErrors[$fileItem["error"]] ?? "Upload error code: " . $fileItem["error"];
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Upload failed: " . $errorMsg
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $originalFileName = basename($fileItem["name"]);
        $fileSize = (int)$fileItem["size"];

        if ($fileSize <= 0) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Uploaded file is empty (0 bytes)."
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($fileSize > $maxSizeBytes) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "File exceeds the 50 MB size limit."
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $fileExtension = strtolower(pathinfo($originalFileName, PATHINFO_EXTENSION));

        if (!isExtensionAllowed($fileExtension)) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Unsupported file type (." . $fileExtension . "). Allowed types: " . implode(", ", getAllowedExtensions())
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $detectedMime = mime_content_type($fileItem["tmp_name"]) ?: ($fileItem["type"] ?? "application/octet-stream");
        $fileType = $detectedMime;

        $storedFileName = "doc_" . $userId . "_" . time() . "_" . bin2hex(random_bytes(6)) . "." . $fileExtension;
        $targetPath = $uploadDir . $storedFileName;

        if (!move_uploaded_file($fileItem["tmp_name"], $targetPath)) {
            http_response_code(500);
            echo json_encode([
                "success" => false,
                "message" => "Failed to store uploaded document on server."
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

    } elseif ($jsonData && (!empty($jsonData["file_base64"]) || !empty($jsonData["document_base64"]))) {
        // Mode B: Base64 JSON payload
        $base64Data = $jsonData["file_base64"] ?? $jsonData["document_base64"];
        $originalFileName = trim($jsonData["file_name"] ?? $jsonData["fileName"] ?? "study_document.pdf");

        // Parse Data URI prefix if present (e.g. data:application/pdf;base64,...)
        if (preg_match('/^data:([^;]+);base64,/', $base64Data, $matches)) {
            $fileType = strtolower($matches[1]);
            $base64Data = substr($base64Data, strpos($base64Data, ',') + 1);
        }

        $binaryData = base64_decode($base64Data, true);
        if ($binaryData === false) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Invalid base64 encoded document data."
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $fileSize = strlen($binaryData);
        if ($fileSize <= 0) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Decoded document is empty (0 bytes)."
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($fileSize > $maxSizeBytes) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "File exceeds the 50 MB size limit."
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $fileExtension = strtolower(pathinfo($originalFileName, PATHINFO_EXTENSION));
        if (empty($fileExtension) || !isExtensionAllowed($fileExtension)) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Unsupported file type (." . $fileExtension . "). Allowed types: " . implode(", ", getAllowedExtensions())
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (empty($fileType)) {
            switch ($fileExtension) {
                case 'pdf': $fileType = 'application/pdf'; break;
                case 'doc': $fileType = 'application/msword'; break;
                case 'docx': $fileType = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'; break;
                case 'ppt': $fileType = 'application/vnd.ms-powerpoint'; break;
                case 'pptx': $fileType = 'application/vnd.openxmlformats-officedocument.presentationml.presentation'; break;
                case 'xls': $fileType = 'application/vnd.ms-excel'; break;
                case 'xlsx': $fileType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'; break;
                case 'txt': $fileType = 'text/plain'; break;
                case 'csv': $fileType = 'text/csv'; break;
                case 'png': $fileType = 'image/png'; break;
                case 'jpg':
                case 'jpeg': $fileType = 'image/jpeg'; break;
                case 'zip': $fileType = 'application/zip'; break;
                default: $fileType = 'application/octet-stream'; break;
            }
        }

        $storedFileName = "doc_" . $userId . "_" . time() . "_" . bin2hex(random_bytes(6)) . "." . $fileExtension;
        $targetPath = $uploadDir . $storedFileName;

        if (file_put_contents($targetPath, $binaryData) === false) {
            http_response_code(500);
            echo json_encode([
                "success" => false,
                "message" => "Failed to write document to server storage."
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

    } else {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "No file uploaded. Send multipart/form-data with 'file' or JSON with 'file_base64' and 'file_name'."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Fallback title if none provided
    if ($title === "") {
        $title = pathinfo($originalFileName, PATHINFO_FILENAME);
    }

    $category = getFileCategory($fileExtension);
    $savedRelativePath = "uploads/documents/" . $storedFileName;

    // 3. Save file metadata in MySQL
    $stmt = $conn->prepare("
        INSERT INTO study_files (
            user_id, note_id, title, file_name, stored_name, file_path, file_size, file_type, file_extension, category
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $userId,
        $noteId,
        $title,
        $originalFileName,
        $storedFileName,
        $savedRelativePath,
        $fileSize,
        $fileType,
        $fileExtension,
        $category
    ]);

    $fileId = (int)$conn->lastInsertId();

    // 4. Return structured JSON response with secure access endpoints
    http_response_code(201);
    echo json_encode([
        "success" => true,
        "message" => "Study file uploaded and saved successfully",
        "file_id" => $fileId,
        "file" => [
            "id" => $fileId,
            "user_id" => $userId,
            "note_id" => $noteId,
            "title" => $title,
            "file_name" => $originalFileName,
            "stored_name" => $storedFileName,
            "file_size" => $fileSize,
            "file_size_formatted" => formatFileBytes($fileSize),
            "file_type" => $fileType,
            "file_extension" => strtoupper($fileExtension),
            "category" => $category,
            "download_url" => "files/download_file.php?file_id=" . $fileId,
            "view_url" => "files/download_file.php?file_id=" . $fileId . "&view=1",
            "created_at" => date("Y-m-d H:i:s")
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error while saving file record: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

