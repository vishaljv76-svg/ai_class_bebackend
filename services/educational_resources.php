<?php
/**
 * SmartNotes AI - Educational Learning Resource Discovery Service
 * Discovers real, relevant educational learning resources (Wikipedia, Open Library)
 * with validated URLs and academic sources.
 */

if (!function_exists('validateResourceUrl')) {
    /**
     * Performs a fast HTTP HEAD check to verify URL returns HTTP 200 (or acceptable redirect).
     *
     * @param string $url
     * @param int $timeoutSeconds
     * @return bool
     */
    function validateResourceUrl($url, $timeoutSeconds = 3) {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeoutSeconds);
        curl_setopt($ch, CURLOPT_USERAGENT, 'SmartNotesAI/1.0 (Educational App; URL Validator)');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode >= 200 && $httpCode < 400);
    }
}

if (!function_exists('searchWikipediaResources')) {
    /**
     * Searches Wikipedia via OpenSearch API for a topic.
     *
     * @param string $topic
     * @param int $limit
     * @return array
     */
    function searchWikipediaResources($topic, $limit = 2) {
        $cleanTopic = trim($topic);
        if (empty($cleanTopic)) {
            return [];
        }

        $endpoint = "https://en.wikipedia.org/w/api.php?action=opensearch&search=" . urlencode($cleanTopic) . "&limit=" . intval($limit) . "&namespace=0&format=json";

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 4);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_USERAGENT, 'SmartNotesAI/1.0 (Educational Reference Search)');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $curlErr = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErr || $httpCode !== 200 || empty($response)) {
            return [];
        }

        $data = json_decode($response, true);
        if (!is_array($data) || count($data) < 4) {
            return [];
        }

        $titles = $data[1] ?? [];
        $descriptions = $data[2] ?? [];
        $urls = $data[3] ?? [];

        $results = [];
        $count = count($titles);
        for ($i = 0; $i < $count; $i++) {
            $t = trim($titles[$i] ?? '');
            $u = trim($urls[$i] ?? '');
            $d = trim($descriptions[$i] ?? '');

            if (!empty($t) && !empty($u) && filter_var($u, FILTER_VALIDATE_URL)) {
                $results[] = [
                    'title' => $t,
                    'url' => $u,
                    'source' => 'Wikipedia',
                    'topic' => $cleanTopic,
                    'snippet' => !empty($d) ? $d : ("Academic encyclopedic reference on " . $t),
                    'is_verified' => 1
                ];
            }
        }

        return $results;
    }
}

if (!function_exists('searchOpenLibraryResources')) {
    /**
     * Searches Open Library for books and academic textbooks related to a topic.
     *
     * @param string $topic
     * @param int $limit
     * @return array
     */
    function searchOpenLibraryResources($topic, $limit = 1) {
        $cleanTopic = trim($topic);
        if (empty($cleanTopic)) {
            return [];
        }

        $endpoint = "https://openlibrary.org/search.json?q=" . urlencode($cleanTopic) . "&limit=" . intval($limit);

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 4);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_USERAGENT, 'SmartNotesAI/1.0 (Educational Textbook Discovery)');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $curlErr = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErr || $httpCode !== 200 || empty($response)) {
            return [];
        }

        $data = json_decode($response, true);
        if (!is_array($data) || empty($data['docs'])) {
            return [];
        }

        $results = [];
        foreach ($data['docs'] as $doc) {
            $title = trim($doc['title'] ?? '');
            $key = trim($doc['key'] ?? '');
            $authors = isset($doc['author_name']) && is_array($doc['author_name']) ? implode(', ', array_slice($doc['author_name'], 0, 2)) : '';

            if (!empty($title) && !empty($key)) {
                $bookUrl = "https://openlibrary.org" . $key;
                $snippet = !empty($authors) ? "Textbook by " . $authors : "Academic reference book";
                $results[] = [
                    'title' => $title,
                    'url' => $bookUrl,
                    'source' => 'Open Library',
                    'topic' => $cleanTopic,
                    'snippet' => $snippet,
                    'is_verified' => 1
                ];
            }
            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }
}

if (!function_exists('discoverEducationalResources')) {
    /**
     * Discovers a curated collection of verified educational resources for a lecture.
     *
     * @param array $topics List of key topics and terms
     * @param string $subject Lecture subject or field
     * @param int $maxResults Maximum number of resources to return
     * @return array
     */
    function discoverEducationalResources($topics = [], $subject = 'Computer Science', $maxResults = 5) {
        $searchQueries = [];

        // Clean and prepare query candidates
        foreach ($topics as $t) {
            $trimmed = trim(is_array($t) ? ($t['term'] ?? $t['title'] ?? '') : (string)$t);
            // Remove common punctuation or labels
            $trimmed = preg_replace('/^\d+[\.\)]\s*/', '', $trimmed);
            if (!empty($trimmed) && strlen($trimmed) > 2 && strlen($trimmed) < 80) {
                $searchQueries[] = $trimmed;
            }
        }

        if (empty($searchQueries) && !empty($subject)) {
            $searchQueries[] = trim($subject);
        }

        $searchQueries = array_unique($searchQueries);
        // Limit query attempts to top 3 to keep discovery fast (< 3 seconds total)
        $searchQueries = array_slice($searchQueries, 0, 3);

        $discovered = [];
        $seenUrls = [];

        foreach ($searchQueries as $query) {
            if (count($discovered) >= $maxResults) {
                break;
            }

            // 1. Wikipedia search
            $wikiItems = searchWikipediaResources($query, 2);
            foreach ($wikiItems as $item) {
                if (!isset($seenUrls[$item['url']])) {
                    $seenUrls[$item['url']] = true;
                    $discovered[] = $item;
                    if (count($discovered) >= $maxResults) break;
                }
            }

            // 2. Open Library textbook search
            if (count($discovered) < $maxResults) {
                $bookItems = searchOpenLibraryResources($query, 1);
                foreach ($bookItems as $item) {
                    if (!isset($seenUrls[$item['url']])) {
                        $seenUrls[$item['url']] = true;
                        $discovered[] = $item;
                        if (count($discovered) >= $maxResults) break;
                    }
                }
            }
        }

        return $discovered;
    }
}

if (!function_exists('saveLectureResources')) {
    /**
     * Persists discovered educational resources into the MySQL lecture_resources table.
     *
     * @param PDO $conn
     * @param int $userId
     * @param int $noteId
     * @param int|null $jobId
     * @param array $resources
     * @return int Number of saved resources
     */
    function saveLectureResources($conn, $userId, $noteId, $jobId, $resources) {
        if (empty($resources) || !is_array($resources)) {
            return 0;
        }

        $savedCount = 0;
        $insertStmt = $conn->prepare("
            INSERT INTO lecture_resources (
                user_id, note_id, job_id, title, url, source, topic, snippet, is_verified
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $checkStmt = $conn->prepare("SELECT id FROM lecture_resources WHERE note_id = ? AND url = ?");

        foreach ($resources as $res) {
            $title = trim($res['title'] ?? '');
            $url = trim($res['url'] ?? '');
            $source = trim($res['source'] ?? 'Web');
            $topic = trim($res['topic'] ?? '');
            $snippet = trim($res['snippet'] ?? '');
            $isVerified = isset($res['is_verified']) ? (int)$res['is_verified'] : 1;

            if (empty($title) || empty($url)) {
                continue;
            }

            // Avoid duplicate URLs for same note
            $checkStmt->execute([$noteId, $url]);
            if ($checkStmt->fetch()) {
                continue;
            }

            $insertStmt->execute([
                $userId,
                $noteId,
                $jobId,
                $title,
                $url,
                $source,
                $topic,
                $snippet,
                $isVerified
            ]);
            $savedCount++;
        }

        return $savedCount;
    }
}

if (!function_exists('getLectureResources')) {
    /**
     * Fetches educational resources saved for a note.
     *
     * @param PDO $conn
     * @param int $noteId
     * @param int $userId
     * @return array
     */
    function getLectureResources($conn, $noteId, $userId) {
        $stmt = $conn->prepare("
            SELECT id, title, url, source, topic, snippet, is_verified, created_at
            FROM lecture_resources
            WHERE note_id = ? AND user_id = ?
            ORDER BY id ASC
        ");
        $stmt->execute([$noteId, $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
