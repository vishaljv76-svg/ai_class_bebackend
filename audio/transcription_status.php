<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../config/transcription_config.php";

echo json_encode([
    "success" => true,
    "service_info" => getSTTServiceInfo()
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

