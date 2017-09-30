<?php
/*
$db = new SQLite3("albia.sqlite", SQLITE3_OPEN_READWRITE);
$result = $db->query('SELECT id_write_operation AS id_write_operation, sending AS sending, timestamp AS timestamp FROM write_operation WHERE 1 ORDER BY id_device');
while($res = $result->fetchArray(SQLITE3_ASSOC)) {
    $id_write_operation = $res['id_write_operation'];
    $sending = $res['sending'];
    $timestamp = $res['timestamp'];
    print "ID: $id_write_operation | Sending: $sending | Timestamp: $timestamp\n";
}
*/

require_once 'src/DeviceClient.php';

$client = new DeviceClient('app1234', 'key1234');

$client->onConnect(function() use ($client) {
  print "Connected\n";
});

$client->onConnectError(function(Exception $e) use ($client) {
  print "onConnectError::Connection failed: ".$e->getMessage()."\n";
  print "onConnectError::Reconnecting in 10 seconds...\n";
  usleep(10000000); // 10s
  print "onConnectError::Reconnecting\n";
  $client->reconnect();
});

$client->onDisconnect(function() use ($client){
  print "onDisconnect::Disconnected\n";
  print "onDisconnect::Reconnecting in 10 seconds...\n";
  usleep(10000000); // 10s
  print "onDisconnect::Reconnecting\n";
  $client->reconnect();
});

$client->connect('maduixa.lafruitera.com');

while(true) {
  if($handle = opendir('./images/')) {
    while(false !== ($entry = readdir($handle))) {
        if($entry != "." && $entry != ".." && $entry != "") {
            print "Reading file ./images/$entry...\n";
            if(!$success = $client->writeData("picture", file_get_contents("./images/$entry"))) {
              print "Error: Invalid file size.\n";
            }
            unlink("./images/$entry");
        }
    }
    closedir($handle);
    usleep(500000); // 500ms
  }
  usleep(500000); // 500ms
}

?>
