<?php
header("Content-Type: application/json; charset=UTF-8");

// 1. Strictly enforce POST requests before database connection
if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "error_code" => "METHOD_NOT_ALLOWED",
        "message" => "Method not allowed. POST method required."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../common/auth_middleware.php";
require_once __DIR__ . "/ai_provider_service.php";

// 2. Normalize Authorization headers for Apache environments
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

// 3. Enforce Authentication
$authUser = getAuthenticatedUser($conn, true);
$userId = (int)$authUser["id"];

// 4. Parse incoming JSON or form payload
$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    $data = $_POST;
}

$action = strtolower(trim((string)($data["action"] ?? "send_message")));

try {
    // Check if migration has been executed
    $tableCheck = $conn->query("SHOW TABLES LIKE 'ai_chat_conversations'")->fetch();
    if (!$tableCheck) {
        http_response_code(503);
        echo json_encode([
            "success" => false,
            "error_code" => "MIGRATION_REQUIRED",
            "message" => "Chat history tables not found in database. Please execute database/migration_ai_chat.sql manually."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =========================================================================
    // ACTION: get_history (Retrieve conversation messages)
    // =========================================================================
    if ($action === "get_history") {
        $conversationId = filter_var($data["conversation_id"] ?? $data["conversationId"] ?? null, FILTER_VALIDATE_INT);
        if (!$conversationId || $conversationId < 1) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "error_code" => "INVALID_CONVERSATION_ID",
                "message" => "A valid conversation_id is required to fetch history."
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Verify conversation ownership
        $convStmt = $conn->prepare("SELECT id, user_id, conversation_title, created_at, updated_at FROM ai_chat_conversations WHERE id = ?");
        $convStmt->execute([$conversationId]);
        $conversation = $convStmt->fetch();

        if (!$conversation) {
            http_response_code(404);
            echo json_encode([
                "success" => false,
                "error_code" => "CONVERSATION_NOT_FOUND",
                "message" => "Conversation not found."
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ((int)$conversation["user_id"] !== $userId) {
            http_response_code(403);
            echo json_encode([
                "success" => false,
                "error_code" => "FORBIDDEN",
                "message" => "You do not have permission to access this conversation."
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Retrieve messages in chronological order
        $msgStmt = $conn->prepare("
            SELECT id, role, message_content, provider, created_at
            FROM ai_chat_messages
            WHERE conversation_id = ? AND user_id = ?
            ORDER BY created_at ASC, id ASC
        ");
        $msgStmt->execute([$conversationId, $userId]);
        $messages = $msgStmt->fetchAll();

        echo json_encode([
            "success" => true,
            "conversation" => [
                "id" => (int)$conversation["id"],
                "title" => $conversation["conversation_title"],
                "created_at" => $conversation["created_at"],
                "updated_at" => $conversation["updated_at"]
            ],
            "messages" => $messages
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // =========================================================================
    // ACTION: list_conversations (List user's conversations)
    // =========================================================================
    if ($action === "list_conversations") {
        $listStmt = $conn->prepare("
            SELECT c.id, c.conversation_title, c.created_at, c.updated_at,
                   (SELECT COUNT(*) FROM ai_chat_messages m WHERE m.conversation_id = c.id) as message_count
            FROM ai_chat_conversations c
            WHERE c.user_id = ?
            ORDER BY c.updated_at DESC
            LIMIT 50
        ");
        $listStmt->execute([$userId]);
        $conversations = $listStmt->fetchAll();

        echo json_encode([
            "success" => true,
            "conversations" => $conversations
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // =========================================================================
    // ACTION: send_message (Default flow)
    // =========================================================================
    $messageText = trim((string)($data["message"] ?? $data["question"] ?? ""));

    // Validation: Empty message check
    if ($messageText === "") {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "error_code" => "EMPTY_MESSAGE",
            "message" => "Message or question cannot be empty."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Validation: Length limitation (Max 2,000 characters)
    if (mb_strlen($messageText) > 2000) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "error_code" => "MESSAGE_TOO_LONG",
            "message" => "Message exceeds the maximum allowed length of 2000 characters."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Security: Basic hourly rate limiting (Max 30 messages/hour per user)
    $rateLimitStmt = $conn->prepare("
        SELECT COUNT(*) as recent_count
        FROM ai_chat_messages
        WHERE user_id = ? AND role = 'user' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $rateLimitStmt->execute([$userId]);
    $rateRow = $rateLimitStmt->fetch();
    $recentCount = (int)($rateRow["recent_count"] ?? 0);

    if ($recentCount >= 30) {
        http_response_code(429);
        echo json_encode([
            "success" => false,
            "error_code" => "RATE_LIMIT_EXCEEDED",
            "message" => "Hourly message limit reached (30/30). Please try again later."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Validate or create conversation
    $conversationId = filter_var($data["conversation_id"] ?? $data["conversationId"] ?? null, FILTER_VALIDATE_INT);
    $conversationTitle = "";

    if ($conversationId && $conversationId > 0) {
        // Validate existing conversation and ownership
        $convCheckStmt = $conn->prepare("SELECT id, user_id, conversation_title FROM ai_chat_conversations WHERE id = ?");
        $convCheckStmt->execute([$conversationId]);
        $existingConv = $convCheckStmt->fetch();

        if (!$existingConv) {
            http_response_code(404);
            echo json_encode([
                "success" => false,
                "error_code" => "CONVERSATION_NOT_FOUND",
                "message" => "Conversation with ID {$conversationId} does not exist."
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ((int)$existingConv["user_id"] !== $userId) {
            http_response_code(403);
            echo json_encode([
                "success" => false,
                "error_code" => "FORBIDDEN",
                "message" => "You do not have permission to access this conversation."
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $conversationTitle = $existingConv["conversation_title"];
    }

    // Begin database transaction for conversation creation and initial message write
    $conn->beginTransaction();

    if (!$conversationId || $conversationId < 1) {
        // Generate automatic conversation title from the first ~40 characters
        $autoTitle = mb_substr($messageText, 0, 40);
        if (mb_strlen($messageText) > 40) {
            $autoTitle .= "...";
        }
        $conversationTitle = $autoTitle;

        $createConvStmt = $conn->prepare("INSERT INTO ai_chat_conversations (user_id, conversation_title) VALUES (?, ?)");
        $createConvStmt->execute([$userId, $conversationTitle]);
        $conversationId = (int)$conn->lastInsertId();
    }

    // Save the user's message in MySQL
    $insertUserMsg = $conn->prepare("
        INSERT INTO ai_chat_messages (conversation_id, user_id, role, message_content, provider)
        VALUES (?, ?, 'user', ?, NULL)
    ");
    $insertUserMsg->execute([$conversationId, $userId, $messageText]);
    $userMsgId = (int)$conn->lastInsertId();

    // Touch conversation updated_at
    $touchStmt = $conn->prepare("UPDATE ai_chat_conversations SET updated_at = NOW() WHERE id = ?");
    $touchStmt->execute([$conversationId]);

    // Commit transaction once conversation and user message are saved
    $conn->commit();

    // Retrieve recent messages for multi-turn context (last 10 turns) with deterministic ordering
    $contextStmt = $conn->prepare("
        SELECT id, role, message_content, provider, created_at
        FROM ai_chat_messages
        WHERE conversation_id = ? AND user_id = ?
        ORDER BY created_at DESC, id DESC
        LIMIT 10
    ");
    $contextStmt->execute([$conversationId, $userId]);
    $recentMessages = array_reverse($contextStmt->fetchAll());

    // Prepare context for AI provider dispatch
    $contextPayload = [];
    foreach ($recentMessages as $msg) {
        $contextPayload[] = [
            "role" => $msg["role"],
            "content" => $msg["message_content"]
        ];
    }

    // Call provider abstraction service
    $aiResult = dispatchChatMessage($contextPayload, [
        "subject" => trim((string)($data["subject"] ?? "")),
        "lecture_title" => trim((string)($data["lecture_title"] ?? ""))
    ]);

    $assistantMsgId = null;

    // If an assistant response was successfully generated (future phases)
    if (!empty($aiResult["success"]) && !empty($aiResult["assistant_content"])) {
        $insertAssistantMsg = $conn->prepare("
            INSERT INTO ai_chat_messages (conversation_id, user_id, role, message_content, provider)
            VALUES (?, ?, 'assistant', ?, ?)
        ");
        $insertAssistantMsg->execute([
            $conversationId,
            $userId,
            $aiResult["assistant_content"],
            $aiResult["provider"] ?? "online_ai"
        ]);
        $assistantMsgId = (int)$conn->lastInsertId();
    }

    // Return structured JSON response
    echo json_encode([
        "success" => true,
        "conversation_id" => $conversationId,
        "conversation_title" => $conversationTitle,
        "user_message" => [
            "id" => $userMsgId,
            "role" => "user",
            "content" => $messageText
        ],
        "assistant_message" => $assistantMsgId ? [
            "id" => $assistantMsgId,
            "role" => "assistant",
            "content" => $aiResult["assistant_content"],
            "provider" => $aiResult["provider"]
        ] : null,
        "ai_status" => $aiResult["status"] ?? "provider_not_configured",
        "message" => $aiResult["message"] ?? "User message saved successfully.",
        "recent_messages" => $recentMessages
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }
    sendSafeJsonError(500, "A database error occurred while processing your request.", $e);
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }
    sendSafeJsonError(500, "An internal server error occurred.", $e);
}
