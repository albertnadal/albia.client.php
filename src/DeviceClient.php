<?php

//error_reporting(E_ERROR);

ini_set('memory_limit', '512M');

define("MAX_STRING_LENGTH", 2000000); //2MB

require_once './third_parties/Requests/library/Requests.php';
require_once './third_parties/RxPHP/vendor/autoload.php';
require_once './third_parties/elephant.io/vendor/autoload.php';
require_once './third_parties/fork-helper/autoload.php';
require_once './third_parties/protobuf/php/vendor/autoload.php';
require_once './third_parties/protobuf_generated/DeviceRecord.php';
require_once './third_parties/protobuf_generated/DeviceRecord_RecordType.php';
require_once './third_parties/protobuf_generated/GPBMetadata/Proto3/Albia.php';
require_once './third_parties/protobuf_generated/GPBMetadata/Proto3/Timestamp.php';
require_once './third_parties/protobuf_generated/Google/Protobuf/Timestamp.php';

Requests::register_autoloader();

use ElephantIO\Client as SocketIO;
use ElephantIO\Engine\SocketIO\Version2X;
use Google\Protobuf\Timestamp;
use duncan3dc\Forker\Fork;

class DeviceClient
{
    private $apiKey;
    private $deviceKey;
    private $host = "localhost";
    private $apiPort = 3001;
    private $webSocketPort = 3000;
    private $dbFilename = "albia.sqlite";
    private $connectionStatusFilename = "albia.status";

    private $deviceToken;
    private $deviceId;
    private $socketIOnamespace;
    private $socketIO;

    private $onConnectCallback;
    private $onConnectErrorCallback;
    private $onDisconnectCallback;

    private $heartBeatFork = null;
    private $runLoopFork = null;
    private $writeQueueFork = null;

    private $db;

    public function __construct(string $apiKey, string $deviceKey)
    {
        $this->apiKey = $apiKey;
        $this->deviceKey = $deviceKey;
        $this->setConnected(false);

        if (!file_exists($this->dbFilename)) {
            $this->db = new SQLite3($this->dbFilename);
            $this->db->exec("CREATE TABLE write_operation (id_write_operation INTEGER PRIMARY KEY AUTOINCREMENT, id_device INTEGER NOT NULL, timestamp INTEGER NOT NULL, payload BLOB NOT NULL, sending INTEGER DEFAULT 0)");
        } else {
            $this->db = new SQLite3($this->dbFilename, SQLITE3_OPEN_READWRITE);
        }

        $this->db->busyTimeout(5000);
        $this->db->exec('PRAGMA journal_mode = wal;');
    }

    /*
        public function __destruct()
        {
            if (!$this->isConnected()) {
                return;
            }

            $this->disconnect();
        }
    */
    public function connect(string $host)
    {
        if ($this->isConnected()) {
            return false;
        }

        $this->host = $host;

        $this->connectToServer($this->host, $this->apiPort, $this->webSocketPort, $this->apiKey, $this->deviceKey)->subscribe(

        function ($data) {
        },
        function (\Exception $e) {
            $this->setConnected(false);

            if ($this->onConnectErrorCallback) {
                $thread = new Fork;
                $self = $this;
                $thread->call(function () use ($self, $e) {
                    $self->onConnectErrorCallback->call($self, $e);
                });
            }
        },
        function () {
            $this->setConnected(true);
            $this->startWriteQueueThread();
            $this->startHeartBeatTimer();

            if ($this->onConnectCallback) {
                $thread = new Fork;
                $self = $this;
                $thread->call(function () use ($self) {
                    $self->onConnectCallback->call($self);
                });
            }

            // Launch the event loop of the socketIO
            $this->runLoop();
        }

      );
    }

    public function reconnect()
    {
        $this->connect($this->host);
    }

    public function writeData(string $key, $data)
    {
        $record = new DeviceRecord();
        $record->setDeviceId(0);
        $record->setKey($key);
        $phpType = gettype($data);
        switch ($phpType) {
          case 'boolean':      $type = DeviceRecord_RecordType::BOOL;
                               $record->setBoolValue($data);
                               break;
          case 'integer':      $type = DeviceRecord_RecordType::INT64;
                               $record->setInt64Value($data);
                               break;
          case 'double':       $type = DeviceRecord_RecordType::DOUBLE;
                               $record->setDoubleValue($data);
                               break;
          case 'float':        $type = DeviceRecord_RecordType::DOUBLE;
                               $record->setDoubleValue($data);
                               break;
          case 'string':       if (strlen($data) > MAX_STRING_LENGTH) {
              return false;
          }
                               $type = DeviceRecord_RecordType::BYTES;
                               $record->setByteStringValue($data);
                               break;
          case 'array':        $type = DeviceRecord_RecordType::BYTES;
                               $record->setByteStringValue($data);
                               break;
          case 'object':       $type = DeviceRecord_RecordType::BYTES;
                               $record->setByteStringValue($data);
                               break;
          case 'resource':     $type = DeviceRecord_RecordType::BYTES;
                               $record->setByteStringValue($data);
                               break;
          case 'NULL':         $type = DeviceRecord_RecordType::BYTES;
                               $record->setByteStringValue($data);
                               break;
          case 'unknown type': $type = DeviceRecord_RecordType::BYTES;
                               $record->setByteStringValue($data);
                               break;
          default:             $type = DeviceRecord_RecordType::BYTES;
                               $record->setByteStringValue($data);
                               break;
        }

        $record->setType($type);
        $utcDate = new Google\Protobuf\Timestamp();
        date_default_timezone_set("UTC");
        $unixTimestamp = time();
        $utcDate->setSeconds($unixTimestamp);
        $utcDate->setNanos(0);
        $record->setDate($utcDate);

        $query = $this->db->prepare("INSERT INTO write_operation (id_device, timestamp, payload, sending) VALUES (0, $unixTimestamp, ?, 0)");
        $query->bindValue(1, $record->serializeToString(), SQLITE3_BLOB);
        $query->execute();

        $this->startWriteQueueThread();

        return true;
    }

    private function sendPing()
    {
        if ((!$this->isConnected()) || (!$this->socketIO)) {
            return false;
        }

        try {
            $this->socketIO->emitPing();
            return true;
        } catch (Exception $e) {
            $this->disconnect();
            return false;
        }
    }

    private function runLoop()
    {
        if ((!$this->isConnected()) || (!$this->socketIO)) {
            return;
        }

        $this->runLoopFork = new Fork;
        $self = $this;

        $this->runLoopFork->call(function () use ($self) {
            try {
                $self->socketIO->eventLoop()->subscribe(

              function ($data) {
              },
              function (\Exception $e) {
                  $this->disconnect();
              },
              function () {
                  $this->disconnect();
              }

            );
            } catch (Requests_Exception $e) {
            }
        });
    }

    private function startWriteQueueThread()
    {
        if($this->writeQueueFork != null) {
          return;
        }

        $this->writeQueueFork = new Fork;
        $db = $this->db;
        $socketIO = $this->socketIO;
        $self = $this;

        $this->writeQueueFork->call(function () use ($db, $socketIO, $self) {

            $success = true;
            while (($self->isConnected()) && ($success)) {
                try {
                    $empty = false;

                    while ((!$empty) && ($self->isConnected())) {
                        $result = $db->query('SELECT id_write_operation AS id_write_operation, payload AS payload FROM write_operation WHERE timestamp = (SELECT MIN(timestamp) FROM write_operation WHERE sending = 0) AND sending = 0 ORDER BY id_device ASC LIMIT 1');
                        if (($res = $result->fetchArray(SQLITE3_ASSOC)) && ($self->isConnected())) {
                            $id_write_operation = $res['id_write_operation'];
                            $payload = new DeviceRecord();
                            $payload->mergeFromString($res['payload']);
                            $payload->setDeviceId($self->deviceId);
                            $db->query("UPDATE write_operation SET sending = 1 WHERE id_write_operation = $id_write_operation");
                            if ($self->isConnected()) {
                                $socketIO->emitBinary('write', $payload->serializeToString());
                                $db->query("DELETE FROM write_operation WHERE id_write_operation = $id_write_operation");
                            }
                        } else {
                            $empty = TRUE;
                        }
                    }

                } catch (Exception $e) {
                    $self->disconnect();
                    $success = FALSE;
                }

                usleep(500000); // 500ms
            }

            unset($self->writeQueueFork);
            $self->writeQueueFork = null;

        });
    }

    private function startHeartBeatTimer()
    {
        if ($this->heartBeatFork) {
            unset($this->heartBeatFork);
        }

        $this->heartBeatFork = new Fork;
        $self = $this;
        $this->heartBeatFork->call(function () use ($self) {
            $success = true;
            while (($self->isConnected()) && ($success)) {
                if ($success = $self->sendPing()) {
                    sleep(25);
                }
            }
        });
    }

    private function connectToServer($host, $apiPort, $webSocketPort, $apiKey, $deviceKey)
    {
        $db = $this->db;
        return new \Rx\Observable\AnonymousObservable(function (\Rx\ObserverInterface $observer) use ($host, $apiPort, $webSocketPort, $apiKey, $deviceKey, $db) {
            try {
                $request = Requests::get('http://'.$host.':'.$apiPort.'/v1/request-device-token', array('Accept' => 'application/json', 'X-albia-device-key' => $deviceKey, 'X-albia-api-key' => $apiKey));
                $jsonObj = json_decode($request->body);
                $this->deviceToken = $jsonObj->token;
                print "Device token: ".$this->deviceToken."\n";

                $tokenArray = explode(";", base64_decode($this->deviceToken));
                $this->deviceId = count($tokenArray) ? $this->deviceId = intval($tokenArray[0]) : 0;
                print "Device Id: ".$this->deviceId."\n";

                $request = Requests::get('http://'.$host.':'.$apiPort.'/v1/request-namespace', array('Accept' => 'application/json', 'Authorization' => $this->deviceToken));
                $jsonObj = json_decode($request->body);
                $this->socketIOnamespace = $jsonObj->namespace;
                print "Namespace: ".$this->socketIOnamespace."\n";

                if ($this->socketIO) {
                    unset($this->socketIO);
                }

                $this->socketIO = new SocketIO(new Version2X('http://'.$host.':'.$webSocketPort, [
                    'headers' => [ "Authorization: ".$this->deviceToken ],
                    'transport' => 'websocket'
                ]));

                $this->socketIO->initialize();
                $this->socketIO->of('/v1/'.$this->socketIOnamespace);

                // Mark all queued write operations as pending to send
                $db->query("UPDATE write_operation SET sending = 0 WHERE 1");

                $observer->onCompleted();
            } catch (Requests_Exception $e) {
                $observer->onError($e);
            }
        });
    }

    public function isConnected()
    {
        return (file_get_contents($this->connectionStatusFilename) == '1');
    }

    private function setConnected($value)
    {
        file_put_contents($this->connectionStatusFilename, ($value == true) ? '1' : '0');
    }

    public function disconnect()
    {
        unset($this->writeQueueFork);
        $this->writeQueueFork = null;

        if ($this->isConnected()) {
            $this->setConnected(false);

            if ($this->socketIO) {
                try {
                    $this->socketIO->close();
                } catch (Exception $e) {
                }
            }

            if ($this->onDisconnectCallback) {
                $thread = new Fork;
                $self = $this;
                $thread->call(function () use ($self) {
                    $self->onDisconnectCallback->call($self);
                });
            }
        }
    }

    public function onConnect(callable $callback)
    {
        $this->onConnectCallback = $callback;
    }

    public function onConnectError(callable $callback)
    {
        $this->onConnectErrorCallback = $callback;
    }

    public function onDisconnect(callable $callback)
    {
        $this->onDisconnectCallback = $callback;
    }
}
