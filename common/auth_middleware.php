<?php
require_once __DIR__ . "/../config/config.php";

/**
 * Generates and stores a secure session token for a user (valid for 30 days).
 */
function generateAuthToken($conn, $userId) {
    $token = bin2hex(random_bytes(32));
    $expiresAt = date("Y-m-d H:i:s", strtotime("+30 days"));

    $stmt = $conn->prepare(
        "INSERT INTO user_tokens (user_id, token, expires_at) VALUES (?, ?, ?)"
    );
    $stmt->execute([$userId, $token, $expiresAt]);

    return [
        "token" => $token,
        "expires_at" => $expiresAt
    ];
}

/**
 * Invalidates (deletes) an auth token on logout.
 */
function invalidateAuthToken($conn, $token) {
    if (empty($token)) return false;
    $stmt = $conn->prepare("DELETE FROM user_tokens WHERE token = ?");
    return $stmt->execute([$token]);
}

/**
 * Extracts and verifies the authenticated user from the request.
 * Checks Authorization header, X-Auth-Token header, or payload token.
 * 
 * @param PDO $conn
 * @param bool $required If true, exits with 401 when unauthenticated.
 * @return array|null The authenticated user array (with 'id', 'name', 'email') or null if optional.
 */
function getAuthenticatedUser($conn, $required = true) {
    $token = null;

    // 1. Check Authorization: Bearer <token>
    $authHeader = $_SERVER["HTTP_AUTHORIZATION"] ?? $_SERVER["REDIRECT_HTTP_AUTHORIZATION"] ?? "";
    if (preg_match('/Bearer\s(\S+)/i', $authHeader, $matches)) {
        $token = $matches[1];
    }

    // 2. Check X-Auth-Token header
    if (!$token && !empty($_SERVER["HTTP_X_AUTH_TOKEN"])) {
        $token = $_SERVER["HTTP_X_AUTH_TOKEN"];
    }

    // 3. Fallback: GET or POST query/body token parameter
    if (!$token) {
        $token = $_GET["token"] ?? null;
    }
    if (!$token) {
        $input = json_decode(file_get_contents("php://input"), true);
        if (is_array($input) && !empty($input["token"])) {
            $token = $input["token"];
        }
    }

    if (!$token) {
        if ($required) {
            http_response_code(401);
            echo json_encode([
                "success" => false,
                "message" => "Authentication required. Please provide a valid Bearer token."
            ]);
            exit;
        }
        return null;
    }

    try {
        $stmt = $conn->prepare(
            "SELECT u.id, u.name, u.email 
             FROM user_tokens t
             INNER JOIN users u ON t.user_id = u.id
             WHERE t.token = ? AND t.expires_at > NOW()"
        );
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if (!$user) {
            if ($required) {
                http_response_code(401);
                echo json_encode([
                    "success" => false,
                    "message" => "Invalid or expired session token. Please log in again."
                ]);
                exit;
            }
            return null;
        }

        $user["token"] = $token;
        return $user;

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Authentication verification error"
        ]);
        exit;
    }
}

