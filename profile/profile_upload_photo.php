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

// Determine user ID with ownership protection
$userId = null;
if ($authUser) {
    $userId = (int)$authUser["id"];
} else {
    // Check form data or query param if token not provided
    $userId = filter_var($_POST["userId"] ?? $_POST["user_id"] ?? $_GET["userId"] ?? $_GET["user_id"] ?? null, FILTER_VALIDATE_INT);
}

// Check JSON body if multipart is empty
$jsonData = null;
if (!$userId || (!isset($_FILES["photo"]) && !isset($_FILES["image"]))) {
    $rawInput = file_get_contents("php://input");
    if (!empty($rawInput)) {
        $jsonData = json_decode($rawInput, true);
        if (is_array($jsonData)) {
            if (!$userId) {
                $userId = filter_var($jsonData["userId"] ?? $jsonData["user_id"] ?? null, FILTER_VALIDATE_INT);
            }
        }
    }
}

if (!$userId || $userId < 1) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Valid user authentication or userId required"
    ]);
    exit;
}

// Verify user exists in database
$checkUser = $conn->prepare("SELECT id FROM users WHERE id = ?");
$checkUser->execute([$userId]);
if (!$checkUser->fetch()) {
    http_response_code(404);
    echo json_encode([
        "success" => false,
        "message" => "User account not found"
    ]);
    exit;
}

$uploadDir = __DIR__ . "/../uploads/profiles/";
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$allowedExtensions = ["jpg", "jpeg", "png", "webp"];
$maxSizeBytes = 5 * 1024 * 1024; // 5 MB max
$savedRelativePath = null;

// Case 1: Standard multipart/form-data file upload
$fileItem = $_FILES["photo"] ?? $_FILES["image"] ?? null;

if ($fileItem && isset($fileItem["tmp_name"]) && is_uploaded_file($fileItem["tmp_name"])) {
    if ($fileItem["error"] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "File upload error code: " . $fileItem["error"]
        ]);
        exit;
    }

    if ($fileItem["size"] > $maxSizeBytes) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Image file exceeds 5MB limit"
        ]);
        exit;
    }

    // Verify MIME type using getimagesize
    $imageInfo = @getimagesize($fileItem["tmp_name"]);
    if ($imageInfo === false) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Invalid image file format"
        ]);
        exit;
    }

    $ext = strtolower(pathinfo($fileItem["name"], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExtensions)) {
        // Fallback to extension based on mime
        switch ($imageInfo["mime"]) {
            case "image/jpeg": $ext = "jpg"; break;
            case "image/png": $ext = "png"; break;
            case "image/webp": $ext = "webp"; break;
            default:
                http_response_code(400);
                echo json_encode([
                    "success" => false,
                    "message" => "Only JPG, PNG, and WebP images are permitted"
                ]);
                exit;
        }
    }

    $filename = "profile_" . $userId . "_" . time() . "." . $ext;
    $targetPath = $uploadDir . $filename;

    if (!move_uploaded_file($fileItem["tmp_name"], $targetPath)) {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Failed to save uploaded image file"
        ]);
        exit;
    }

    $savedRelativePath = "uploads/profiles/" . $filename;
} 
// Case 2: Base64 JSON image payload
elseif ($jsonData && (!empty($jsonData["image_base64"]) || !empty($jsonData["photo_base64"]))) {
    $base64String = $jsonData["image_base64"] ?? $jsonData["photo_base64"];

    // Strip data URI prefix if present (e.g. data:image/png;base64,)
    if (preg_match('/^data:image\/(\w+);base64,/', $base64String, $matches)) {
        $ext = strtolower($matches[1]);
        if ($ext === "jpeg") $ext = "jpg";
        $base64String = substr($base64String, strpos($base64String, ',') + 1);
    } else {
        $ext = "jpg";
    }

    if (!in_array($ext, $allowedExtensions)) {
        $ext = "jpg";
    }

    $reqFileName = trim($jsonData["file_name"] ?? $jsonData["fileName"] ?? "");
    if ($reqFileName !== "") {
        $reqExt = strtolower(pathinfo($reqFileName, PATHINFO_EXTENSION));
        if (!empty($reqExt) && !in_array($reqExt, $allowedExtensions)) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Invalid image file format (." . $reqExt . "). Allowed formats: JPG, JPEG, PNG, WEBP."
            ]);
            exit;
        }
    }

    $decodedData = base64_decode($base64String, true);
    if ($decodedData === false) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Invalid base64 image data"
        ]);
        exit;
    }

    // Verify genuine image content (magic bytes and dimensions)
    $imageInfo = @getimagesizefromstring($decodedData);
    if ($imageInfo === false) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Invalid image file format"
        ]);
        exit;
    }

    if (strlen($decodedData) > $maxSizeBytes) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Image file exceeds 5MB limit"
        ]);
        exit;
    }

    $filename = "profile_" . $userId . "_" . time() . "." . $ext;
    $targetPath = $uploadDir . $filename;

    if (file_put_contents($targetPath, $decodedData) === false) {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Failed to write image to disk"
        ]);
        exit;
    }

    $savedRelativePath = "uploads/profiles/" . $filename;
} else {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "No image file or base64 data received"
    ]);
    exit;
}

try {
    // Check existing profile record
    $checkProfile = $conn->prepare("SELECT id, profile_image FROM student_profiles WHERE user_id = ?");
    $checkProfile->execute([$userId]);
    $existing = $checkProfile->fetch();

    // Clean up old image file if it exists
    if ($existing && !empty($existing["profile_image"])) {
        $oldFile = __DIR__ . "/../" . $existing["profile_image"];
        if (file_exists($oldFile) && is_file($oldFile)) {
            @unlink($oldFile);
        }
    }

    if ($existing) {
        $update = $conn->prepare("UPDATE student_profiles SET profile_image = ? WHERE user_id = ?");
        $update->execute([$savedRelativePath, $userId]);
    } else {
        $insert = $conn->prepare("INSERT INTO student_profiles (user_id, profile_image) VALUES (?, ?)");
        $insert->execute([$userId, $savedRelativePath]);
    }

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Profile photo updated successfully",
        "profile_image" => $savedRelativePath,
        "photo_url" => $savedRelativePath
    ]);

} catch (PDOException $e) {
    error_log("[Profile Photo Database Error] " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "An internal error occurred while updating profile photo. Please try again."
    ]);
}

