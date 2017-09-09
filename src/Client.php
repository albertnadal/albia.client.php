<?php

//namespace AlbiaSoft;

require_once './third_parties/Requests/library/Requests.php';
require_once './third_parties/RxPHP/vendor/autoload.php';
require_once './third_parties/elephant.io/vendor/autoload.php';

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

class Client
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

    private $db;

    public function __construct(string $apiKey, string $deviceKey)
    {
        $this->apiKey = $apiKey;
        $this->deviceKey = $deviceKey;
        if(!file_exists($this->dbFilename)) {
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

            if ($this->onConnectErrorCallback) {
                $this->onConnectErrorCallback->call($this, $e);
            }
        },
        function () {
            $this->isConnected = true;

            if ($this->onConnectCallback) {
                $this->onConnectCallback->call($this);
            }

            // Launch the event loop of socketIO
            $this->run();
        }

      );
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
          case 'string':       $type = DeviceRecord_RecordType::STRING;
                               $record->setStringValue($data);
                               break;
          case 'array':        $type = DeviceRecord_RecordType::STRING;
                               $record->setStringValue($data);
                               break;
          case 'object':       $type = DeviceRecord_RecordType::STRING;
                               $record->setStringValue($data);
                               break;
          case 'resource':     $type = DeviceRecord_RecordType::STRING;
                               $record->setStringValue($data);
                               break;
          case 'NULL':         $type = DeviceRecord_RecordType::STRING;
                               $record->setStringValue($data);
                               break;
          case 'unknown type': $type = DeviceRecord_RecordType::STRING;
                               $record->setStringValue($data);
                               break;
          default:             $type = DeviceRecord_RecordType::STRING;
                               $record->setStringValue($data);
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
/*
        $result = $this->db->query('SELECT * FROM write_operation');
        while($res = $result->fetchArray(SQLITE3_ASSOC)){
          print_r($res);
        }
*/
    }

    private function run()
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

            if ($this->onDisconnectCallback) {
                $this->onDisconnectCallback->call($this);
            }
        },
        function () {

          // The event loop of the socketIO has ended, it means that the websocket is closed
            $this->isConnected = false;

            if ($this->onDisconnectCallback) {
                $this->onDisconnectCallback->call($this);
            }
        }

      );
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

    /*
        public function read()
        {
            $this->logger->debug('Reading a new message from the socket');
            return $this->engine->read();
        }

        public function emit($event, array $args)
        {
            $this->logger->debug('Sending a new message', ['event' => $event, 'args' => $args]);
            $this->engine->emit($event, $args);

            return $this;
        }

        public function of($namespace)
        {
            $this->logger->debug('Setting the namespace', ['namespace' => $namespace]);
            $this->engine->of($namespace);

            return $this;
        }

        public function close()
        {
            $this->logger->debug('Closing the connection to the websocket');
            $this->engine->close();

            $this->isConnected = false;

            return $this;
        }

        public function getEngine()
        {
            return $this->engine;
        }
    */
}
