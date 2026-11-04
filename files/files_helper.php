<?php
/**
 * File Management Helper Functions
 * SmartNotes AI Backend
 */

if (!function_exists('formatFileBytes')) {
    /**
     * Format raw byte count into human-friendly string (B, KB, MB, GB)
     */
    function formatFileBytes($bytes): string {
        $bytes = (float)$bytes;
        if ($bytes <= 0) return "0 B";
        $units = ["B", "KB", "MB", "GB", "TB"];
        $power = min((int)floor(log($bytes, 1024)), 4);
        return round($bytes / pow(1024, $power), 2) . " " . $units[$power];
    }
}

if (!function_exists('getFileCategory')) {
    /**
     * Categorize file extension into functional study groups
     */
    function getFileCategory(string $extension): string {
        $ext = strtolower(trim($extension));
        switch ($ext) {
            case 'pdf':
            case 'doc':
            case 'docx':
            case 'txt':
            case 'rtf':
            case 'odt':
            case 'md':
                return 'document';

            case 'ppt':
            case 'pptx':
            case 'odp':
                return 'presentation';

            case 'xls':
            case 'xlsx':
            case 'csv':
            case 'ods':
                return 'spreadsheet';

            case 'jpg':
            case 'jpeg':
            case 'png':
            case 'webp':
            case 'gif':
                return 'image';

            case 'zip':
            case 'rar':
            case '7z':
            case 'tar':
            case 'gz':
                return 'archive';

            default:
                return 'other';
        }
    }
}

if (!function_exists('getAllowedExtensions')) {
    /**
     * List of allowed educational file extensions
     */
    function getAllowedExtensions(): array {
        return [
            // Documents
            'pdf', 'doc', 'docx', 'txt', 'rtf', 'odt', 'md',
            // Presentations
            'ppt', 'pptx', 'odp',
            // Spreadsheets & Data
            'xls', 'xlsx', 'csv', 'ods',
            // Educational Diagrams & Images
            'jpg', 'jpeg', 'png', 'webp', 'gif',
            // Study archives
            'zip'
        ];
    }
}

if (!function_exists('isExtensionAllowed')) {
    /**
     * Check if file extension is safe and allowed
     */
    function isExtensionAllowed(string $extension): bool {
        $ext = strtolower(trim($extension));
        $dangerous = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'cgi', 'pl', 'exe', 'sh', 'bat', 'cmd', 'com', 'vbs', 'scr', 'msi', 'jsp', 'asp', 'aspx'];
        if (in_array($ext, $dangerous)) {
            return false;
        }
        return in_array($ext, getAllowedExtensions());
    }
}

if (!function_exists('getUserFilesSummary')) {
    /**
     * Compute aggregate summary of uploaded files for a specific user
     */
    function getUserFilesSummary(PDO $conn, int $userId): array {
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) AS total_files,
                COALESCE(SUM(file_size), 0) AS total_size_bytes,
                SUM(CASE WHEN category = 'document' THEN 1 ELSE 0 END) AS document_count,
                SUM(CASE WHEN category = 'presentation' THEN 1 ELSE 0 END) AS presentation_count,
                SUM(CASE WHEN category = 'spreadsheet' THEN 1 ELSE 0 END) AS spreadsheet_count,
                SUM(CASE WHEN category = 'image' THEN 1 ELSE 0 END) AS image_count,
                SUM(CASE WHEN category = 'archive' THEN 1 ELSE 0 END) AS archive_count
            FROM study_files
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $totalSize = (int)($row["total_size_bytes"] ?? 0);

        return [
            "total_files" => (int)($row["total_files"] ?? 0),
            "total_size_bytes" => $totalSize,
            "total_size_formatted" => formatFileBytes($totalSize),
            "categories" => [
                "documents" => (int)($row["document_count"] ?? 0),
                "presentations" => (int)($row["presentation_count"] ?? 0),
                "spreadsheets" => (int)($row["spreadsheet_count"] ?? 0),
                "images" => (int)($row["image_count"] ?? 0),
                "archives" => (int)($row["archive_count"] ?? 0)
            ]
        ];
    }
}

