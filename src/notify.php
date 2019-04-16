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

if (empty($_POST) || !isset($_POST['course_section']) || !isset($_POST['status'])) {
    http_response_code(400);
    exit(1);
}

if ($_POST['status'] !== 'O') {
    exit;
}

$course_id = $_POST['course_section'];
$course_id_topic = str_replace(' ', '%', $course_id);
$course_id_readable =
    substr($course_id, 0, 4) . '-'
    . substr($course_id, 4, 3) . '-'
    . substr($course_id, 7, 3);

require __DIR__ . '/../vendor/autoload.php';

$serviceAccount = Kreait\Firebase\ServiceAccount::fromJsonFile($config['firebase']);

$firebase = (new Kreait\Firebase\Factory)
    ->withServiceAccount($serviceAccount)
    ->create();

$messaging = $firebase->getMessaging();
$message = Kreait\Firebase\Messaging\CloudMessage::withTarget('topic', $course_id_topic)
    ->withNotification(
        Kreait\Firebase\Messaging\Notification::create($course_id_readable, 'The course opens just now.'))
    ->withAndroidConfig(
        Kreait\Firebase\Messaging\AndroidConfig::fromArray(['ttl' => '600s', 'priority' => 'high']));
$messaging->send($message);