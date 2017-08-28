<?php

//namespace AlbiaSoft;

require_once './third_parties/Requests/library/Requests.php';
require_once './third_parties/RxPHP/vendor/autoload.php';
require_once './third_parties/elephant.io/vendor/autoload.php';

Requests::register_autoloader();

use ElephantIO\Client as SocketIO;
use ElephantIO\Engine\SocketIO\Version2X;

class Client
{
    private $apiKey;
    private $deviceKey;
    private $host = "localhost";
    private $apiPort = 3001;
    private $webSocketPort = 3000;

    private $deviceToken;
    private $socketIOnamespace;
    private $socketIO;

    private $isConnected = false;
    private $onConnectCallback;
    private $onConnectErrorCallback;
    private $onDisconnectCallback;

    public function __construct(string $apiKey, string $deviceKey)
    {
        $this->apiKey = $apiKey;
        $this->deviceKey = $deviceKey;
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

    private function run() {

      if((!$this->isConnected) || (!$this->socketIO)) {
          return;
      }

      $this->socketIO->eventLoop()->subscribe(

        function ($data) {
        },
        function (\Exception $e) {
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

        if($this->socketIO) {
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
