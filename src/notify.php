<?php
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

$cred = $config['credential'][0];

if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])
    || $_SERVER['PHP_AUTH_USER'] !== $cred['username'] || $_SERVER['PHP_AUTH_PW'] !== $cred['password']) {
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

if ((isset($config['term']) && $_POST['term'] !== $config['term']) || $_POST['status'] !== 'O') {
    exit;
}

$course_id = $_POST['section_id_normalized'];
$course_id_topic = strtr($course_id, ['-' => '', ' ' => '%']);

error_log($course_id);
exit;

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