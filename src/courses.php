<?php
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header("Allow: GET");
    http_response_code(405);
    exit(1);
}

$cache_file = __DIR__ . '/../cache/courses.json';
$config = json_decode(file_get_contents(__DIR__ . '/../config.json'), true);

$overwrite = !file_exists($cache_file);
if (!$overwrite) {
    $mod_time = filemtime($cache_file);
    if ($mod_time <= time() - 24 * 60 * 60) {
        $overwrite = true;
    }
}

if ($overwrite) {
    $db_config = $config['database'];
    $mysqli = new mysqli($db_config['host'], $db_config['username'], $db_config['password'], $db_config['db']);

    if ($mysqli->connect_errno) {
        error_log("Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error);
        http_response_code(500);
        exit(1);
    }

    $query = "SELECT section_id, section_title, activity, instructors FROM course_list";
    if (isset($config['term'])) {
        $query .= " WHERE term='" . $mysqli->escape_string($config['term']) . "'";
    }

    if (!($result = $mysqli->query($query))) {
        error_log("Query failed: (" . $mysqli->errno . ") " . $mysqli->error);
        http_response_code(500);
        exit(1);
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'id' => str_replace(' ', '', $row['section_id']),
            'title' => $row['section_title'],
            'act' => $row['activity'],
            'inst' => json_decode($row['instructors']),
        ];
    }

    $result->free();
    $mysqli->close();
    $file = fopen($cache_file, 'w');
    fwrite($file, json_encode($rows));
    fclose($file);

    $mod_time = strtotime(date('G') < 5 ? '-1 day 5am' : '5am');
    touch($cache_file, $mod_time);
}

header('Content-Type: application/json');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s T', $mod_time));
header("Expires: " . gmdate('D, d M Y H:i:s T', $mod_time + 24 * 60 * 60));
copy($cache_file, "php://output");