<?php
declare(strict_types=1);
function validate_credential(array $credentials): int
{
    if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
        $user = $_SERVER['PHP_AUTH_USER'];
        $pass = $_SERVER['PHP_AUTH_PW'];

        foreach ($credentials as $index => $cred)
            if ($user === $cred['username'] && $pass === $cred['password'])
                return $index;
    }
    return -1;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Allow: POST");
    http_response_code(405);
    exit(1);
}

$config = json_decode(file_get_contents(__DIR__ . '/../config.json'), true);

if (empty($config) || empty($config['credential']) || empty($config['firebase'])) {
    http_response_code(500);
    exit(1);
}

if (($cred_index = validate_credential($config['credential'])) === -1) {
    header('WWW-Authenticate: Basic realm="Penn Automate"');
    http_response_code(401);
    exit(1);
}

if (empty($_POST)) {
    $_POST = json_decode(file_get_contents('php://input'), true);
}

if (empty($_POST) || !isset($_POST['section_id']) || !isset($_POST['status']) || !isset($_POST['term'])) {
    http_response_code(400);
    exit(1);
}

// ----- Connect database -------

$course_id = $_POST['section_id'];
$db_config = $config['database'];
$mysqli = new mysqli($db_config['host'], $db_config['username'], $db_config['password'], $db_config['db']);

if ($mysqli->connect_errno) {
    error_log("Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error);
    http_response_code(500);
    exit(1);
}

// ----- Query duplicate from database -------

if (!($stmt = $mysqli->prepare(
    "SELECT COUNT(*) AS c FROM course_status_change_new WHERE " .
    "section_id=? AND `status`=? AND `term`=? AND DATE_SUB(NOW(), INTERVAL 30 SECOND)<change_time"))) {
    error_log("Prepare failed: (" . $mysqli->errno . ") " . $mysqli->error);
    http_response_code(500);
    exit(1);
}

if (!$stmt->bind_param("sss",
    $_POST['section_id'],
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
$stmt->close();

if ($count != 0) {
    if ($cred_index > 0) {
        http_response_code(202);
    }
    exit;
}

// ----- Save to database -------

unset($stmt);
if (!($stmt = $mysqli->prepare(
    "INSERT INTO course_status_change_new (section_id, previous_status, `status`, term) VALUES (?,?,?,?)"))) {
    error_log("Prepare failed: (" . $mysqli->errno . ") " . $mysqli->error);
    http_response_code(500);
    exit(1);
}

if (empty($_POST['previous_status'])) {
    $_POST['previous_status'] = 'X';
}

if (!$stmt->bind_param("ssss",
    $_POST['section_id'],
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
$stmt->close();
$mysqli->close();

// ------------------------

if ((isset($config['term']) && $_POST['term'] !== $config['term']) || $_POST['status'] !== 'O') {
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

$firebase = (new Kreait\Firebase\Factory)
    ->withServiceAccount($config['firebase']);

$messaging = $firebase->createMessaging();
$message = Kreait\Firebase\Messaging\CloudMessage::withTarget('topic', $course_id)
    ->withNotification(
        Kreait\Firebase\Messaging\Notification::create($course_id, 'The course opens just now.'))
    ->withAndroidConfig(
        Kreait\Firebase\Messaging\AndroidConfig::fromArray(['ttl' => '600s', 'priority' => 'high']))
    ->withWebPushConfig(Kreait\Firebase\Messaging\WebPushConfig::fromArray([
        'headers' => ['TTL' => '600', 'Urgency' => 'high'],
        'notification' => ['icon' => 'icon.png'],
        'fcmOptions' => ['link' => 'https://courses.upenn.edu']
    ]));
try {
    $messaging->send($message);
} catch (\Kreait\Firebase\Exception\FirebaseException $e) {
}
