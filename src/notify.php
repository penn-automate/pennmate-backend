<?php
declare(strict_types=1);
function validate_credential(array $credentials): bool
{
    if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
        $user = $_SERVER['PHP_AUTH_USER'];
        $pass = $_SERVER['PHP_AUTH_PW'];

        foreach ($credentials as $cred)
            if ($user === $cred['username'] && $pass === $cred['password'])
                return true;
    }
    return false;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Allow: POST");
    http_response_code(405);
    exit(1);
}

$config = json_decode(file_get_contents('../config.json'), true);

if (empty($config) || empty($config['credential']) || empty($config['firebase'])) {
    http_response_code(500);
    exit(1);
}

if (!validate_credential($config['credential'])) {
    header('WWW-Authenticate: Basic realm="Penn Automate"');
    http_response_code(401);
    exit(1);
}

if (empty($_POST)) {
    $_POST = json_decode(file_get_contents('php://input'), true);
}

if (empty($_POST) || !isset($_POST['section_id_normalized']) || !isset($_POST['status']) || !isset($_POST['term'])) {
    http_response_code(400);
    exit(1);
}

// ----- Connect database -------

$course_id = $_POST['section_id_normalized'];
$course_id_topic = strtr($course_id, ['-' => '', ' ' => '%']);

$db_config = $config['database'];
$mysqli = new mysqli($db_config['host'], $db_config['username'], $db_config['password'], $db_config['db']);

if ($mysqli->connect_errno) {
    error_log("Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error);
    http_response_code(500);
    exit(1);
}

// ----- Query duplicate from database -------

if (!($stmt = $mysqli->prepare(
    "SELECT COUNT(*) AS c FROM course_status_change WHERE " .
    "section_id=? AND `status`=? AND `term`=? AND DATE_SUB(NOW(), INTERVAL 30 SECOND)<change_time"))) {
    error_log("Prepare failed: (" . $mysqli->errno . ") " . $mysqli->error);
    http_response_code(500);
    exit(1);
}

if (!$stmt->bind_param("sss",
    $_POST['section_id_normalized'],
    $_POST['status'],
    $_POST['term'])) {
    error_log("Binding parameters failed: (" . $stmt->errno . ") " . $stmt->error);
    http_response_code(500);
    exit(1);
}

if (!$stmt->execute()) {
    error_log("Execute failed: (" . $stmt->errno . ") " . $stmt->error);
    http_response_code(500);
    exit(1);
}

$count = 0;
if (!$stmt->bind_result($count)) {
    error_log("Binding output parameters failed: (" . $stmt->errno . ") " . $stmt->error);
    http_response_code(500);
    exit(1);
}
$stmt->fetch();

if ($count != 0) {
    http_response_code(202);
    exit;
}

// ----- Save to database -------

unset($stmt);
if (!($stmt = $mysqli->prepare(
    "INSERT INTO course_status_change (section_id, previous_status, `status`, term) VALUES (?,?,?,?)"))) {
    error_log("Prepare failed: (" . $mysqli->errno . ") " . $mysqli->error);
    http_response_code(500);
    exit(1);
}

if (!$stmt->bind_param("ssss",
    $_POST['section_id_normalized'],
    $_POST['previous_status'],
    $_POST['status'],
    $_POST['term'])) {
    error_log("Binding parameters failed: (" . $stmt->errno . ") " . $stmt->error);
    http_response_code(500);
    exit(1);
}

if (!$stmt->execute()) {
    error_log("Execute failed: (" . $stmt->errno . ") " . $stmt->error);
    http_response_code(500);
    exit(1);
}

// ------------------------

if ((isset($config['term']) && $_POST['term'] !== $config['term']) || $_POST['status'] !== 'O') {
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

$serviceAccount = Kreait\Firebase\ServiceAccount::fromJsonFile($config['firebase']);

$firebase = (new Kreait\Firebase\Factory)
    ->withServiceAccount($serviceAccount)
    ->create();

$messaging = $firebase->getMessaging();
$message = Kreait\Firebase\Messaging\CloudMessage::withTarget('topic', $course_id_topic)
    ->withNotification(
        Kreait\Firebase\Messaging\Notification::create($course_id, 'The course opens just now.'))
    ->withAndroidConfig(
        Kreait\Firebase\Messaging\AndroidConfig::fromArray(['ttl' => '600s', 'priority' => 'high']));
$messaging->send($message);