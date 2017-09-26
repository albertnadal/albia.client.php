<?php

error_reporting(E_ERROR);

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

    private $deviceToken;
    private $deviceId;
    private $socketIOnamespace;
    private $socketIO;

    private $isConnected = false;
    private $onConnectCallback;
    private $onConnectErrorCallback;
    private $onDisconnectCallback;

    private $heartBeatFork = null;
    private $db;

    public function __construct(string $apiKey, string $deviceKey)
    {
        $this->apiKey = $apiKey;
        $this->deviceKey = $deviceKey;
        if (!file_exists($this->dbFilename)) {
            $this->db = new SQLite3($this->dbFilename);
            $this->db->exec("CREATE TABLE write_operation (id_write_operation INTEGER PRIMARY KEY AUTOINCREMENT, id_device INTEGER NOT NULL, timestamp INTEGER NOT NULL, payload BLOB NOT NULL, sending INTEGER DEFAULT 0)");
        } else {
            $this->db = new SQLite3($this->dbFilename, SQLITE3_OPEN_READWRITE);
        }
    }

    public function __destruct()
    {
        if (!$this->isConnected) {
            return;
        }

        $this->disconnect();
    }

    public function connect(string $host)
    {
        $this->host = $host;

        $this->connectToServer($this->host, $this->apiPort, $this->webSocketPort, $this->apiKey, $this->deviceKey)->subscribe(

        function ($data) {
        },
        function (\Exception $e) {
            $this->isConnected = false;
            $this->stopHeartBeatTimer();
            if ($this->onConnectErrorCallback) {
                $this->onConnectErrorCallback->call($this, $e);
            }
        },
        function () {
            $this->isConnected = true;
            $this->startHeartBeatTimer();
            $this->flushQueuedWriteOperations()->subscribe(
              function ($data) {
              },
              function (\Exception $e) {
              },
              function () {
              }
            );
            if ($this->onConnectCallback) {
                $this->onConnectCallback->call($this);
            }

            // Launch the event loop of socketIO
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
        $record->setDeviceId($this->deviceId);
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
          case 'string':       $type = DeviceRecord_RecordType::BYTES;
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

        $query = $this->db->prepare("INSERT INTO write_operation (id_device, timestamp, payload, sending) VALUES (".$this->deviceId.", $unixTimestamp, ?, 0)");
        $query->bindValue(1, $record->serializeToString(), SQLITE3_BLOB);
        $query->execute();

        $this->flushQueuedWriteOperations()->subscribe(
          function ($data) {
          },
          function (\Exception $e) {
          },
          function () {
          }
        );
    }

    private function flushQueuedWriteOperations()
    {
        $db = $this->db;
        $socketIO = $this->socketIO;
        $self = $this;

        return new \Rx\Observable\AnonymousObservable(function (\Rx\ObserverInterface $observer) use ($db, $socketIO, $self) {
            try {
                $empty = false;
                while (!$empty) {
                    $result = $db->query('SELECT id_write_operation AS id_write_operation, payload AS payload FROM write_operation WHERE timestamp = (SELECT MIN(timestamp) FROM write_operation WHERE sending = 0) ORDER BY id_device ASC LIMIT 1');
                    if ($res = $result->fetchArray(SQLITE3_ASSOC)) {
                        $id_write_operation = $res['id_write_operation'];
                        $payload = $res['payload'];
                        $db->query("UPDATE write_operation SET sending = 1 WHERE id_write_operation = $id_write_operation");
                        print "Sending 'write' record...\n";
                        $socketIO->emitBinary('write', $payload);
                        $db->query("DELETE FROM write_operation WHERE id_write_operation = $id_write_operation");
                    } else {
                        $empty = true;
                    }

                    $observer->onCompleted();
                }
            } catch (Exception $e) {
                $this->isConnected = false;
                $this->stopHeartBeatTimer();

                if ($this->onDisconnectCallback) {
                    $this->onDisconnectCallback->call($this);
                }

                $observer->onError($e);
            }
        });
    }

    private function sendPing()
    {
        if ((!$this->isConnected) || (!$this->socketIO)) {
            return;
        }

        try {
            $this->socketIO->emitPing();
        } catch (Exception $e) {
            $this->isConnected = false;
            $this->stopHeartBeatTimer();

            if ($this->onDisconnectCallback) {
                $this->onDisconnectCallback->call($this);
            }

            $observer->onError($e);
        }
    }

    private function runLoop()
    {
        if ((!$this->isConnected) || (!$this->socketIO)) {
            return;
        }

        $this->socketIO->eventLoop()->subscribe(

        function ($data) {
        },
        function (\Exception $e) {
            // If an Exception is thrown during an active websocket then the connection closes
            $this->isConnected = false;
            $this->stopHeartBeatTimer();

            if ($this->onDisconnectCallback) {
                $this->onDisconnectCallback->call($this);
            }
        },
        function () {

          // The event loop of the socketIO has ended, it means that the websocket is closed
            $this->isConnected = false;
            $this->stopHeartBeatTimer();

            if ($this->onDisconnectCallback) {
                $this->onDisconnectCallback->call($this);
            }
        }

      );
    }

    private function startHeartBeatTimer()
    {
        $this->heartBeatFork = new Fork;
        $self = $this;
        $this->heartBeatFork->call(function () use ($self) {
            while ($self->isConnected) {
                $self->sendPing();
                sleep(25);
            }
        });
    }

    private function stopHeartBeatTimer()
    {
        if($this->heartBeatFork != null) {
          $this->heartBeatFork->wait();
        }
    }

    private function connectToServer($host, $apiPort, $webSocketPort, $apiKey, $deviceKey)
    {
        return new \Rx\Observable\AnonymousObservable(function (\Rx\ObserverInterface $observer) use ($host, $apiPort, $webSocketPort, $apiKey, $deviceKey) {
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

                $this->socketIO = new SocketIO(new Version2X('http://'.$host.':'.$webSocketPort, [
                    'headers' => [ "Authorization: ".$this->deviceToken ],
                    'transport' => 'websocket'
                ]));

                $this->socketIO->initialize();
                $this->socketIO->of('/v1/'.$this->socketIOnamespace);

                $observer->onCompleted();
            } catch (Requests_Exception $e) {
                $observer->onError($e);
            }
        });
    }

    public function disconnect()
    {
        if (!$this->isConnected) {
            return;
        }

        if ($this->socketIO) {
            $this->socketIO->close();
        }

        /* TODO */
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