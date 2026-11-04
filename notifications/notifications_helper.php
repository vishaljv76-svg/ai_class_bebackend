<?php
/**
 * Notifications & Reminders Helper Functions
 * SmartNotes AI Backend
 */

if (!function_exists('formatRelativeTime')) {
    /**
     * Format a timestamp or datetime string into a human-friendly relative time string.
     * Aligns with Android UI labels ("10m ago", "2h ago", "Yesterday", "3d ago", "12 Oct 2026")
     */
    function formatRelativeTime(string $datetime): string {
        $timestamp = strtotime($datetime);
        if ($timestamp === false) {
            return $datetime;
        }

        $now = time();
        $diff = $now - $timestamp;

        // If the date is slightly in the future (within 60s) due to clock skew
        if ($diff < 0 && $diff > -60) {
            return "Just now";
        }

        if ($diff < 60) {
            return "Just now";
        }

        if ($diff < 3600) {
            $mins = max(1, (int)floor($diff / 60));
            return "{$mins}m ago";
        }

        $todayStart = strtotime("today 00:00:00");
        if ($timestamp >= $todayStart) {
            $hours = max(1, (int)floor($diff / 3600));
            return "{$hours}h ago";
        }

        $yesterdayStart = strtotime("yesterday 00:00:00");
        if ($timestamp >= $yesterdayStart && $timestamp < $todayStart) {
            return "Yesterday";
        }

        if ($diff < 7 * 86400) {
            $days = max(1, (int)floor($diff / 86400));
            return "{$days}d ago";
        }

        return date("d M Y", $timestamp);
    }
}

if (!function_exists('getReminderSummary')) {
    /**
     * Get aggregate counts of study reminders for a user
     */
    function getReminderSummary(PDO $conn, int $userId): array {
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
                SUM(CASE WHEN status = 'pending' AND reminder_date < CURDATE() THEN 1 
                         WHEN status = 'pending' AND reminder_date = CURDATE() AND reminder_time < CURTIME() THEN 1 
                         ELSE 0 END) AS overdue_count,
                SUM(CASE WHEN reminder_date = CURDATE() THEN 1 ELSE 0 END) AS today_count
            FROM study_reminders
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            "total" => (int)($row["total"] ?? 0),
            "pending" => (int)($row["pending_count"] ?? 0),
            "completed" => (int)($row["completed_count"] ?? 0),
            "cancelled" => (int)($row["cancelled_count"] ?? 0),
            "overdue" => (int)($row["overdue_count"] ?? 0),
            "today" => (int)($row["today_count"] ?? 0)
        ];
    }
}

if (!function_exists('getNotificationSummary')) {
    /**
     * Get aggregate counts of in-app notifications for a user
     */
    function getNotificationSummary(PDO $conn, int $userId): array {
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) AS total,
                SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread_count,
                SUM(CASE WHEN is_read = 1 THEN 1 ELSE 0 END) AS read_count
            FROM notifications
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            "total" => (int)($row["total"] ?? 0),
            "unread" => (int)($row["unread_count"] ?? 0),
            "read" => (int)($row["read_count"] ?? 0)
        ];
    }
}

if (!function_exists('getPushServiceInspection')) {
    /**
     * Inspect FCM / Push Notification setup and report clear configuration requirements.
     * Prevents fake push notifications and provides transparent status.
     */
    function getPushServiceInspection(): array {
        $fcmServerKey = getenv('FCM_SERVER_KEY') ?: null;
        $fcmServiceAccountFile = getenv('FIREBASE_CONFIG_PATH') ?: (__DIR__ . '/../config/firebase_credentials.json');

        $isConfigured = !empty($fcmServerKey) || (is_string($fcmServiceAccountFile) && file_exists($fcmServiceAccountFile));

        return [
            "fcm_configured" => $isConfigured,
            "provider" => "Firebase Cloud Messaging (FCM)",
            "in_app_notifications_active" => true,
            "status" => $isConfigured ? "configured" : "pending_credentials",
            "message" => $isConfigured 
                ? "FCM credentials loaded. Push notifications enabled." 
                : "External push delivery requires Firebase Cloud Messaging setup. In-app database notifications are fully active.",
            "requirements" => [
                "firebase_console" => "Create a Firebase project at https://console.firebase.google.com/",
                "server_credentials" => "Obtain google-services.json for Android and service-account.json or Server Key for Backend",
                "android_fcm_token" => "Register device FCM token via user profile/device register API",
                "backend_path" => "Place credentials at config/firebase_credentials.json or set FCM_SERVER_KEY in environment"
            ]
        ];
    }
}

