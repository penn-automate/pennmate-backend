<?php
declare(strict_types=1);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit(1);
}

if (!isset($_POST['device'])) {
    http_response_code(400);
    exit(1);
}
$addition = array_unique($_POST['topics'] ?? []);
$device = $_POST['device'];

foreach ($addition as $topic) {
    if (!preg_match('/^[a-zA-Z0-9-_.~%]{1,900}$/', $topic)) {
        echo "Topic name '$topic' is not valid";
        http_response_code(400);
        exit(1);
    }
}

$config = json_decode(file_get_contents(__DIR__ . '/../config.json'), true);

require __DIR__ . '/../vendor/autoload.php';

$firebase = (new Kreait\Firebase\Factory)
    ->withServiceAccount($config['firebase']);

$messaging = $firebase->createMessaging();
$message = Kreait\Firebase\Messaging\CloudMessage::withTarget('token', $device);
try {
    $messaging->validate($message);
} catch (\Kreait\Firebase\Exception\FirebaseException $e) {
    http_response_code($e->getCode());
    echo $e->getMessage();
    exit(1);
}

// ----- Connect database -------

$db_config = $config['database'];
$mysqli = new mysqli($db_config['host'], $db_config['username'], $db_config['password'], $db_config['db']);

if ($mysqli->connect_errno) {
    error_log("Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error);
    http_response_code(500);
    exit(1);
}

if (!($stmt = $mysqli->prepare("SELECT topic FROM device_subscribe WHERE device=? AND valid=0b1"))) {
    error_log("Prepare failed: (" . $mysqli->errno . ") " . $mysqli->error);
    http_response_code(500);
    exit(1);
}

if (!$stmt->bind_param("s", $device)) {
    error_log("Binding parameters failed: (" . $stmt->errno . ") " . $stmt->error);
    http_response_code(500);
    exit(1);
}

if (!$stmt->execute()) {
    error_log("Execute failed: (" . $stmt->errno . ") " . $stmt->error);
    http_response_code(500);
    exit(1);
}

// ----- Calculate difference -------
$result = $stmt->get_result();
$deletion = [];

while ($row = $result->fetch_row()) {
    if (($index = array_search($row[0], $addition, true)) !== false) {
        unset($addition[$index]);
    } else {
        $deletion[] = $row[0];
    }
}

$stmt->close();

foreach ($addition as $topic) {
    $messaging->subscribeToTopic($topic, $device);
}

foreach ($deletion as $topic) {
    $messaging->unsubscribeFromTopic($topic, $device);
}

// ----- Update database -------
if (!empty($addition)) {
    unset($stmt);

    if (!($stmt = $mysqli->prepare("INSERT INTO device_subscribe (device, topic) VALUES (?,?) "
        . "ON DUPLICATE KEY UPDATE valid=0b1"))) {
        error_log("Prepare failed: (" . $mysqli->errno . ") " . $mysqli->error);
        http_response_code(500);
        exit(1);
    }

    if (!$stmt->bind_param("ss", $device, $topic)) {
        error_log("Binding parameters failed: (" . $stmt->errno . ") " . $stmt->error);
        http_response_code(500);
        exit(1);
    }

    foreach ($addition as $topic) {
        if (!$stmt->execute()) {
            error_log("Execute failed: (" . $stmt->errno . ") " . $stmt->error);
            http_response_code(500);
            exit(1);
        }
    }

    $stmt->close();
}

if (!empty($deletion)) {
    unset($stmt);

    if (!($stmt = $mysqli->prepare("UPDATE device_subscribe SET valid=0b0 WHERE device=? AND topic=?"))) {
        error_log("Prepare failed: (" . $mysqli->errno . ") " . $mysqli->error);
        http_response_code(500);
        exit(1);
    }

    if (!$stmt->bind_param("ss", $device, $topic)) {
        error_log("Binding parameters failed: (" . $stmt->errno . ") " . $stmt->error);
        http_response_code(500);
        exit(1);
    }

    foreach ($deletion as $topic) {
        if (!$stmt->execute()) {
            error_log("Execute failed: (" . $stmt->errno . ") " . $stmt->error);
            http_response_code(500);
            exit(1);
        }
    }

    $stmt->close();
}

$mysqli->close();