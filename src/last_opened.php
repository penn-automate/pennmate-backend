<?php
declare(strict_types=1);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header("Allow: GET");
    http_response_code(405);
    exit(1);
}

if (!isset($_GET['course'])) {
    http_response_code(406);
    echo '`course` not found in query';
    exit(1);
}

$config = json_decode(file_get_contents(__DIR__ . '/../config.json'), true);
$db_config = $config['database'];
$mysqli = new mysqli($db_config['host'], $db_config['username'], $db_config['password'], $db_config['db']);

if ($mysqli->connect_errno) {
    error_log("Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error);
    http_response_code(500);
    exit(1);
}

if (!($stmt = $mysqli->prepare(
    "SELECT `status`, UNIX_TIMESTAMP(change_time) FROM course_status_change" .
    " WHERE section_id=?" .
    (isset($config['term']) ? (" AND term='" . $mysqli->escape_string($config['term']) . "'") : '') .
    " ORDER BY id DESC LIMIT 1"))) {
    error_log("Prepare failed: (" . $mysqli->errno . ") " . $mysqli->error);
    http_response_code(500);
    exit(1);
}

if (!$stmt->bind_param("s", $_GET['course'])) {
    error_log("Binding parameters failed: (" . $stmt->errno . ") " . $stmt->error);
    http_response_code(500);
    exit(1);
}

if (!$stmt->execute()) {
    error_log("Execute failed: (" . $stmt->errno . ") " . $stmt->error);
    http_response_code(500);
    exit(1);
}

$status = null;
$change = null;

if (!$stmt->bind_result($status, $change)) {
    error_log("Binding output parameters failed: (" . $stmt->errno . ") " . $stmt->error);
    http_response_code(500);
    exit(1);
}

$stmt->fetch();
$stmt->close();

if ($status === 'O') {
    echo '-1';
} else if ($status === 'C') {
    echo $change;
}
$mysqli->close();


