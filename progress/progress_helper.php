<?php
/**
 * SmartNotes AI - Study Progress & Performance Analytics Engine
 * Calculates metrics using real database records from Notes, Audio, and Quiz modules.
 */

/**
 * Calculates complete study progress, quiz performance, study streak, and persists snapshot.
 *
 * @param PDO $conn Database PDO connection
 * @param int $userId Target user ID
 * @return array Calculated progress and performance details
 */
function calculateUserProgress($conn, $userId) {
    // 1. Notes Analytics
    $notesStmt = $conn->prepare("
        SELECT id, subject, read_time, duration, tag, transcription_id, is_bookmarked, created_at
        FROM notes
        WHERE user_id = ?
    ");
    $notesStmt->execute([$userId]);
    $notes = $notesStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalNotes = count($notes);
    $bookmarkedNotes = 0;
    $aiNotesCount = 0;
    $notesBySubject = [];
    $estimatedReadMinutes = 0;

    foreach ($notes as $n) {
        if (!empty($n["is_bookmarked"])) {
            $bookmarkedNotes++;
        }
        if (!empty($n["transcription_id"]) || strcasecmp($n["tag"] ?? "", "AI notes") === 0) {
            $aiNotesCount++;
        }
        $sub = $n["subject"] ?: "General";
        $notesBySubject[$sub] = ($notesBySubject[$sub] ?? 0) + 1;

        // Extract minutes from read_time (e.g., "6 min read" -> 6)
        if (!empty($n["read_time"]) && preg_match('/(\d+)/', $n["read_time"], $m)) {
            $estimatedReadMinutes += (int)$m[1];
        } else {
            $estimatedReadMinutes += 5;
        }
    }

    // 2. Quiz Analytics
    $quizCountStmt = $conn->prepare("SELECT COUNT(*) FROM quizzes WHERE user_id = ?");
    $quizCountStmt->execute([$userId]);
    $totalQuizzesCreated = (int)$quizCountStmt->fetchColumn();

    $attemptsStmt = $conn->prepare("
        SELECT qa.*, q.title AS quiz_title, q.subject, q.difficulty
        FROM quiz_attempts qa
        JOIN quizzes q ON qa.quiz_id = q.id
        WHERE qa.user_id = ?
        ORDER BY qa.created_at DESC
    ");
    $attemptsStmt->execute([$userId]);
    $attempts = $attemptsStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalQuizzesCompleted = count($attempts);
    $scoresSum = 0;
    $bestScore = null;
    $latestScore = null;
    $totalQuestionsAnswered = 0;
    $totalCorrectAnswers = 0;
    $quizDifficultyBreakdown = ["Easy" => 0, "Moderate" => 0, "Hard" => 0];
    $quizSubjectPerformance = [];

    if ($totalQuizzesCompleted > 0) {
        $latestScore = (int)$attempts[0]["score_percentage"];

        foreach ($attempts as $att) {
            $score = (int)$att["score_percentage"];
            $scoresSum += $score;
            if ($bestScore === null || $score > $bestScore) {
                $bestScore = $score;
            }

            $totalQuestionsAnswered += (int)$att["total_questions"];
            $totalCorrectAnswers += (int)$att["correct_count"];

            $diff = ucfirst(strtolower($att["difficulty"] ?: "Moderate"));
            if (isset($quizDifficultyBreakdown[$diff])) {
                $quizDifficultyBreakdown[$diff]++;
            }

            $qSub = $att["subject"] ?: "General";
            if (!isset($quizSubjectPerformance[$qSub])) {
                $quizSubjectPerformance[$qSub] = ["attempts" => 0, "scores_sum" => 0];
            }
            $quizSubjectPerformance[$qSub]["attempts"]++;
            $quizSubjectPerformance[$qSub]["scores_sum"] += $score;
        }
        $averageQuizScore = round($scoresSum / $totalQuizzesCompleted, 2);
    } else {
        // Fallback check on quizzes table if quizzes were completed directly without attempt rows
        $qFallback = $conn->prepare("
            SELECT score_percentage FROM quizzes WHERE user_id = ? AND score_percentage > 0
        ");
        $qFallback->execute([$userId]);
        $fallbackScores = $qFallback->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($fallbackScores)) {
            $totalQuizzesCompleted = count($fallbackScores);
            $scoresSum = array_sum($fallbackScores);
            $averageQuizScore = round($scoresSum / $totalQuizzesCompleted, 2);
            $bestScore = max($fallbackScores);
            $latestScore = end($fallbackScores);
        } else {
            $averageQuizScore = 0.00;
        }
    }

    $overallAccuracy = $totalQuestionsAnswered > 0 
        ? round(($totalCorrectAnswers / $totalQuestionsAnswered) * 100, 2) 
        : ($averageQuizScore > 0 ? (float)$averageQuizScore : 0.00);

    // Format subject performance list
    $subjectPerformanceList = [];
    foreach ($quizSubjectPerformance as $subName => $sp) {
        $subjectPerformanceList[] = [
            "subject" => $subName,
            "attempts" => $sp["attempts"],
            "averageScore" => round($sp["scores_sum"] / $sp["attempts"], 1)
        ];
    }

    // 3. Audio & Transcription counts
    $audioStmt = $conn->prepare("SELECT COUNT(*) FROM audio_files WHERE user_id = ?");
    $audioStmt->execute([$userId]);
    $totalAudioUploaded = (int)$audioStmt->fetchColumn();

    $transStmt = $conn->prepare("SELECT COUNT(*) FROM transcriptions WHERE user_id = ?");
    $transStmt->execute([$userId]);
    $totalTranscriptions = (int)$transStmt->fetchColumn();

    // 4. Study Streak Calculation
    $streakStmt = $conn->prepare("
        SELECT DISTINCT DATE(created_at) AS act_date FROM (
            SELECT created_at FROM notes WHERE user_id = ? AND created_at IS NOT NULL
            UNION
            SELECT created_at FROM audio_files WHERE user_id = ? AND created_at IS NOT NULL
            UNION
            SELECT created_at FROM transcriptions WHERE user_id = ? AND created_at IS NOT NULL
            UNION
            SELECT created_at FROM quiz_attempts WHERE user_id = ? AND created_at IS NOT NULL
        ) AS all_activity
        ORDER BY act_date DESC
    ");
    $streakStmt->execute([$userId, $userId, $userId, $userId]);
    $activeDates = $streakStmt->fetchAll(PDO::FETCH_COLUMN);

    $studyStreakDays = 0;
    if (!empty($activeDates)) {
        $today = date("Y-m-d");
        $yesterday = date("Y-m-d", strtotime("-1 day"));

        // If active today or yesterday, streak is currently live
        $checkDate = in_array($today, $activeDates) ? $today : (in_array($yesterday, $activeDates) ? $yesterday : null);

        if ($checkDate !== null) {
            $expectedTime = strtotime($checkDate);
            foreach ($activeDates as $d) {
                $actualTime = strtotime($d);
                if ($actualTime === $expectedTime) {
                    $studyStreakDays++;
                    $expectedTime = strtotime("-1 day", $expectedTime);
                } elseif ($actualTime < $expectedTime) {
                    break;
                }
            }
        }
    }

    // 5. Total Estimated Study Time (Minutes)
    // Notes reading (~5-6m per note) + Audio listening (~30m per audio) + Quiz attempts (~5m per quiz)
    $totalStudyTimeMinutes = $estimatedReadMinutes + ($totalAudioUploaded * 30) + ($totalQuizzesCompleted * 5);

    // 6. Overall Study Progress Calculation (0 to 100%)
    // Zero-dummy rule: Users with no activity get exactly 0%
    if ($totalNotes === 0 && $totalQuizzesCompleted === 0 && $totalAudioUploaded === 0) {
        $overallProgressPercentage = 0;
        $masteryLevel = "Beginner";
    } else {
        // Balanced score based on real metrics:
        // - Notes creation: 40% (Target: 8 notes = 40 pts)
        $notesScore = min(40, ($totalNotes / 8) * 40);

        // - Quiz attempts: 30% (Target: 4 quizzes = 30 pts)
        $quizVolScore = min(30, ($totalQuizzesCompleted / 4) * 30);

        // - Quiz performance / accuracy: 30%
        $quizAccScore = ($averageQuizScore / 100) * 30;

        $overallProgressPercentage = (int)round($notesScore + $quizVolScore + $quizAccScore);
        if ($overallProgressPercentage > 100) $overallProgressPercentage = 100;
        if ($overallProgressPercentage < 1 && ($totalNotes > 0 || $totalQuizzesCompleted > 0)) {
            $overallProgressPercentage = 5;
        }

        // Mastery Level
        if ($overallProgressPercentage >= 75) {
            $masteryLevel = "Mastery";
        } elseif ($overallProgressPercentage >= 50) {
            $masteryLevel = "Proficient";
        } elseif ($overallProgressPercentage >= 25) {
            $masteryLevel = "Developing";
        } else {
            $masteryLevel = "Beginner";
        }
    }

    // 7. Last Activity Timestamp
    $lastActStmt = $conn->prepare("
        SELECT MAX(created_at) AS last_act FROM (
            SELECT created_at FROM notes WHERE user_id = ?
            UNION
            SELECT created_at FROM audio_files WHERE user_id = ?
            UNION
            SELECT created_at FROM transcriptions WHERE user_id = ?
            UNION
            SELECT created_at FROM quiz_attempts WHERE user_id = ?
        ) AS all_activity
    ");
    $lastActStmt->execute([$userId, $userId, $userId, $userId]);
    $lastActivityAt = $lastActStmt->fetchColumn() ?: null;

    // 8. Persist progress snapshot into user_study_progress in MySQL
    $upsertStmt = $conn->prepare("
        INSERT INTO user_study_progress (
            user_id, total_notes, total_quizzes_completed, average_quiz_score,
            total_study_time_minutes, study_streak_days, overall_progress_percentage,
            mastery_level, last_activity_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            total_notes = VALUES(total_notes),
            total_quizzes_completed = VALUES(total_quizzes_completed),
            average_quiz_score = VALUES(average_quiz_score),
            total_study_time_minutes = VALUES(total_study_time_minutes),
            study_streak_days = VALUES(study_streak_days),
            overall_progress_percentage = VALUES(overall_progress_percentage),
            mastery_level = VALUES(mastery_level),
            last_activity_at = VALUES(last_activity_at),
            updated_at = CURRENT_TIMESTAMP
    ");
    $upsertStmt->execute([
        $userId, $totalNotes, $totalQuizzesCompleted, $averageQuizScore,
        $totalStudyTimeMinutes, $studyStreakDays, $overallProgressPercentage,
        $masteryLevel, $lastActivityAt
    ]);

    // Format subject breakdown list
    $subjectBreakdown = [];
    foreach ($notesBySubject as $subName => $cnt) {
        $subjectBreakdown[] = [
            "subject" => $subName,
            "notesCount" => $cnt
        ];
    }

    return [
        "user_id" => (int)$userId,
        "totalNotes" => $totalNotes,
        "total_notes" => $totalNotes,
        "bookmarkedNotesCount" => $bookmarkedNotes,
        "aiNotesCount" => $aiNotesCount,
        "totalQuizzesCompleted" => $totalQuizzesCompleted,
        "total_quizzes_completed" => $totalQuizzesCompleted,
        "totalQuizzesCreated" => $totalQuizzesCreated,
        "averageQuizScore" => $averageQuizScore,
        "average_quiz_score" => $averageQuizScore,
        "bestScore" => $bestScore,
        "latestScore" => $latestScore,
        "totalQuestionsAnswered" => $totalQuestionsAnswered,
        "totalCorrectAnswers" => $totalCorrectAnswers,
        "overallAccuracy" => $overallAccuracy,
        "studyStreakDays" => $studyStreakDays,
        "study_streak_days" => $studyStreakDays,
        "totalStudyTimeMinutes" => $totalStudyTimeMinutes,
        "formattedStudyTime" => floor($totalStudyTimeMinutes / 60) > 0 
            ? floor($totalStudyTimeMinutes / 60) . "h " . ($totalStudyTimeMinutes % 60) . "m"
            : $totalStudyTimeMinutes . "m",
        "overallProgressPercentage" => $overallProgressPercentage,
        "overall_progress_percentage" => $overallProgressPercentage,
        "masteryLevel" => $masteryLevel,
        "mastery_level" => $masteryLevel,
        "totalAudioUploaded" => $totalAudioUploaded,
        "totalTranscriptions" => $totalTranscriptions,
        "subjectBreakdown" => $subjectBreakdown,
        "subjectPerformance" => $subjectPerformanceList,
        "quizDifficultyBreakdown" => $quizDifficultyBreakdown,
        "lastActivityAt" => $lastActivityAt
    ];
}

/**
 * Returns learning activity timeline grouped by date over the past N days.
 */
function getUserActivityTimeline($conn, $userId, $days = 30) {
    if ($days < 1) $days = 7;
    if ($days > 90) $days = 90;

    $startDate = date("Y-m-d", strtotime("-$days days"));

    // Aggregate counts by date
    $stmt = $conn->prepare("
        SELECT act_date, 
               SUM(is_note) AS notes_count, 
               SUM(is_audio) AS audio_count, 
               SUM(is_quiz) AS quiz_count,
               COUNT(*) AS total_activities
        FROM (
            SELECT DATE(created_at) AS act_date, 1 AS is_note, 0 AS is_audio, 0 AS is_quiz
            FROM notes WHERE user_id = ? AND created_at >= ?
            UNION ALL
            SELECT DATE(created_at) AS act_date, 0 AS is_note, 1 AS is_audio, 0 AS is_quiz
            FROM audio_files WHERE user_id = ? AND created_at >= ?
            UNION ALL
            SELECT DATE(created_at) AS act_date, 0 AS is_note, 0 AS is_audio, 1 AS is_quiz
            FROM quiz_attempts WHERE user_id = ? AND created_at >= ?
        ) AS activity_agg
        GROUP BY act_date
        ORDER BY act_date ASC
    ");
    $stmt->execute([$userId, $startDate, $userId, $startDate, $userId, $startDate]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $dateMap = [];
    foreach ($rows as $r) {
        $dateMap[$r["act_date"]] = $r;
    }

    $timeline = [];
    $today = date("Y-m-d");

    // Populate every day in the range
    for ($i = $days; $i >= 0; $i--) {
        $currDate = date("Y-m-d", strtotime("-$i days"));
        $dayLabel = ($currDate === $today) ? "Today" : date("d M", strtotime($currDate));
        $dayOfWeek = date("D", strtotime($currDate));

        if (isset($dateMap[$currDate])) {
            $rec = $dateMap[$currDate];
            $timeline[] = [
                "date" => $currDate,
                "label" => $dayLabel,
                "dayOfWeek" => $dayOfWeek,
                "notesCount" => (int)$rec["notes_count"],
                "audioCount" => (int)$rec["audio_count"],
                "quizCount" => (int)$rec["quiz_count"],
                "totalActivities" => (int)$rec["total_activities"],
                "hasActivity" => true
            ];
        } else {
            $timeline[] = [
                "date" => $currDate,
                "label" => $dayLabel,
                "dayOfWeek" => $dayOfWeek,
                "notesCount" => 0,
                "audioCount" => 0,
                "quizCount" => 0,
                "totalActivities" => 0,
                "hasActivity" => false
            ];
        }
    }

    return $timeline;
}

/**
 * Returns unified chronological feed of recent study activities.
 */
function getUserRecentActivities($conn, $userId, $limit = 10) {
    if ($limit < 1) $limit = 5;
    if ($limit > 50) $limit = 50;

    $activities = [];

    // 1. Notes created
    $nStmt = $conn->prepare("
        SELECT id, title, subject, created_at FROM notes WHERE user_id = ? ORDER BY created_at DESC LIMIT ?
    ");
    $nStmt->bindValue(1, $userId, PDO::PARAM_INT);
    $nStmt->bindValue(2, $limit, PDO::PARAM_INT);
    $nStmt->execute();
    foreach ($nStmt->fetchAll(PDO::FETCH_ASSOC) as $n) {
        $activities[] = [
            "id" => "note_" . $n["id"],
            "type" => "note_created",
            "title" => $n["title"],
            "description" => "Created study note in " . $n["subject"],
            "icon_type" => (stripos($n["subject"], "Math") !== false) ? "Math" : "Database",
            "timestamp" => $n["created_at"],
            "time_unix" => strtotime($n["created_at"])
        ];
    }

    // 2. Audio files uploaded
    $aStmt = $conn->prepare("
        SELECT id, title, file_name, created_at FROM audio_files WHERE user_id = ? ORDER BY created_at DESC LIMIT ?
    ");
    $aStmt->bindValue(1, $userId, PDO::PARAM_INT);
    $aStmt->bindValue(2, $limit, PDO::PARAM_INT);
    $aStmt->execute();
    foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $activities[] = [
            "id" => "audio_" . $a["id"],
            "type" => "audio_uploaded",
            "title" => $a["title"] ?: $a["file_name"],
            "description" => "Uploaded lecture audio file",
            "icon_type" => "Audio",
            "timestamp" => $a["created_at"],
            "time_unix" => strtotime($a["created_at"])
        ];
    }

    // 3. Quizzes completed
    $qStmt = $conn->prepare("
        SELECT qa.id, qa.score_percentage, qa.correct_count, qa.total_questions, qa.created_at, q.title, q.subject
        FROM quiz_attempts qa
        JOIN quizzes q ON qa.quiz_id = q.id
        WHERE qa.user_id = ?
        ORDER BY qa.created_at DESC
        LIMIT ?
    ");
    $qStmt->bindValue(1, $userId, PDO::PARAM_INT);
    $qStmt->bindValue(2, $limit, PDO::PARAM_INT);
    $qStmt->execute();
    foreach ($qStmt->fetchAll(PDO::FETCH_ASSOC) as $q) {
        $activities[] = [
            "id" => "quiz_" . $q["id"],
            "type" => "quiz_completed",
            "title" => $q["title"],
            "description" => "Completed quiz with {$q['score_percentage']}% score ({$q['correct_count']}/{$q['total_questions']})",
            "icon_type" => "Quiz",
            "score_percentage" => (int)$q["score_percentage"],
            "timestamp" => $q["created_at"],
            "time_unix" => strtotime($q["created_at"])
        ];
    }

    // Sort descending by time
    usort($activities, function($a, $b) {
        return $b["time_unix"] - $a["time_unix"];
    });

    $activities = array_slice($activities, 0, $limit);
    $today = date("Y-m-d");
    $yesterday = date("Y-m-d", strtotime("-1 day"));

    // Add formatted labels
    foreach ($activities as &$act) {
        $t = $act["time_unix"];
        $actDate = date("Y-m-d", $t);
        if ($actDate === $today) {
            $act["date_label"] = "Today";
        } elseif ($actDate === $yesterday) {
            $act["date_label"] = "Yesterday";
        } else {
            $act["date_label"] = date("d M Y", $t);
        }
        $act["formatted_time"] = date("d M Y, h:i A", $t);
        unset($act["time_unix"]);
    }

    return $activities;
}
