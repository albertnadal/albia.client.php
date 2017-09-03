<?php
/**
 * This file is part of the Elephant.io package
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 *
 * @copyright Wisembly
 * @license   http://www.opensource.org/licenses/MIT-License MIT License
 */

namespace ElephantIO\Engine\SocketIO;

require_once './third_parties/RxPHP/vendor/autoload.php';

use DomainException;
use InvalidArgumentException;
use UnexpectedValueException;

use Psr\Log\LoggerInterface;

use ElephantIO\EngineInterface;
use ElephantIO\Payload\Encoder;
use ElephantIO\Engine\AbstractSocketIO;

use ElephantIO\Exception\SocketException;
use ElephantIO\Exception\UnsupportedTransportException;
use ElephantIO\Exception\ServerConnectionFailureException;

/**
 * Implements the dialog with Socket.IO version 1.x
 *
 * Based on the work of Mathieu Lallemand (@lalmat)
 *
 * @author Baptiste Clavié <baptiste@wisembly.com>
 * @link https://tools.ietf.org/html/rfc6455#section-5.2 Websocket's RFC
 */
class Version1X extends AbstractSocketIO
{
    const TRANSPORT_POLLING   = 'polling';
    const TRANSPORT_WEBSOCKET = 'websocket';

    private $huge_payload = null;

    protected static $opcodes = array(
      'continuation' => 0,
      'text'         => 1,
      'binary'       => 2,
      'close'        => 8,
      'ping'         => 9,
      'pong'         => 10,
    );

    /** {@inheritDoc} */
    public function connect()
    {
        if (is_resource($this->stream)) {
            return;
        }

        $protocol = 'http';
        $errors = [null, null];
        $host   = sprintf('%s:%d', $this->url['host'], $this->url['port']);

        if (true === $this->url['secured']) {
            $protocol = 'ssl';
            $host = 'ssl://' . $host;
        }

        // add custom headers
        if (isset($this->options['headers'])) {
            $headers = isset($this->context[$protocol]['header']) ? $this->context[$protocol]['header'] : [];
            $this->context[$protocol]['header'] = array_merge($headers, $this->options['headers']);
        }

        $this->stream = stream_socket_client($host, $errors[0], $errors[1], $this->options['timeout'], STREAM_CLIENT_CONNECT, stream_context_create($this->context));

        if (!is_resource($this->stream)) {
            throw new SocketException($errors[0], $errors[1]);
        }

        stream_set_timeout($this->stream, $this->options['timeout']);

        $this->upgradeTransport();
    }

    public function loop() {

      return new \Rx\Observable\AnonymousObservable(function (\Rx\ObserverInterface $observer) {

        while (!feof($this->stream)) {

            $this->huge_payload = '';
            $response = null;

            try {

              while (is_null($response)) {
                $response = $this->receiveFragment();
              }
              print "RESPONSE: $response\n";

            } catch (SocketException $e) {
                $observer->OnError($e);
            }

        }

        // Connection closed
        $observer->onCompleted();
      });

    }

    /** {@inheritDoc} */
    public function close()
    {
        if (!is_resource($this->stream)) {
            return;
        }

        $this->write(EngineInterface::CLOSE);

        fclose($this->stream);
        $this->stream = null;
        $this->session = null;
        $this->cookies = [];
    }

    /** {@inheritDoc} */
    public function emit($event, array $args)
    {
        $namespace = $this->namespace;

        if ('' !== $namespace) {
            $namespace .= ',';
        }

        return $this->write(EngineInterface::MESSAGE, static::EVENT . $namespace . json_encode([$event, $args]));
    }

    /** {@inheritDoc} */
    public function of($namespace) {
        parent::of($namespace);

        if ('' !== $namespace) {
          $namespace .= ',';
        }

        $this->write(EngineInterface::MESSAGE, static::CONNECT . $namespace);
    }

    /** {@inheritDoc} */
    public function write($code, $message = null)
    {
        if (!is_resource($this->stream)) {
            return;
        }

        if (!is_int($code) || 0 > $code || 6 < $code) {
            throw new InvalidArgumentException('Wrong message type when trying to write on the socket');
        }
print "ENVIANT: $code MSG: $message\n";
        $payload = new Encoder($code . $message, Encoder::OPCODE_TEXT, true);
        $bytes = fwrite($this->stream, (string) $payload);

        // wait a little bit of time after this message was sent
        usleep((int) $this->options['wait']);

        return $bytes;
    }

    /** {@inheritDoc} */
    public function getName()
    {
        return 'SocketIO Version 1.X';
    }

    /** {@inheritDoc} */
    protected function getDefaultOptions()
    {
        $defaults = parent::getDefaultOptions();

        $defaults['version']   = 2;
        $defaults['use_b64']   = false;
        $defaults['transport'] = static::TRANSPORT_POLLING;

        return $defaults;
    }

    /**
     * Upgrades the transport to WebSocket
     *
     * FYI:
     * Version "2" is used for the EIO param by socket.io v1
     * Version "3" is used by socket.io v2
     */
    protected function upgradeTransport()
    {
        $query = [/*'sid'       => $this->session->id,*/
                  'EIO'       => $this->options['version'],
                  'transport' => static::TRANSPORT_WEBSOCKET];

        if ($this->options['version'] === 2) {
            $query['use_b64'] = $this->options['use_b64'];
        }

        $url = sprintf('/%s/?%s', trim($this->url['path'], '/'), http_build_query($query));

        $hash = sha1(uniqid(mt_rand(), true), true);

        if ($this->options['version'] !== 2) {
            $hash = substr($hash, 0, 16);
        }

        $key = base64_encode($hash);

        $origin = '*';
        $headers = isset($this->context['headers']) ? (array) $this->context['headers'] : [] ;

        foreach ($headers as $header) {
            $matches = [];

            if (preg_match('`^Origin:\s*(.+?)$`', $header, $matches)) {
                $origin = $matches[1];
                break;
            }
        }

        $request = "GET {$url} HTTP/1.1\r\n"
                 . "Host: {$this->url['host']}:{$this->url['port']}\r\n"
                 . "Upgrade: websocket\r\n"
                 . "Connection: Upgrade\r\n"
                 . "Sec-WebSocket-Key: {$key}\r\n"
                 . "Sec-WebSocket-Version: 13\r\n"
                 . "Sec-WebSocket-Extensions: permessage-deflate; client_max_window_bits\r\n"
                 . "Origin: {$origin}\r\n";

                 if(isset($this->options['headers'])) {
                   $request .= implode("\r\n", $this->options['headers']);
                   $request .= "\r\n";
                 }

print $request."\n";

        if (!empty($this->cookies)) {
            $request .= "Cookie: " . implode('; ', $this->cookies) . "\r\n";
        }

        $request .= "\r\n";

        fwrite($this->stream, $request);
        $result = fread($this->stream, 12);

        if ('HTTP/1.1 101' !== $result) {
            throw new UnexpectedValueException(sprintf('The server returned an unexpected value. Expected "HTTP/1.1 101", had "%s"', $result));
        }

        // cleaning up the stream
        while ('' !== trim(fgets($this->stream)));
        $result = fread($this->stream, 100);

        $open_curly_at = strpos($result, '{');
        $todecode = substr($result, $open_curly_at, strrpos($result, '}')-$open_curly_at+1);
        $decoded = json_decode($todecode, true);

        $this->cookies = [];
        $this->session = new Session($decoded['sid'], $decoded['pingInterval'], $decoded['pingTimeout'], $decoded['upgrades']);


//print "UPGRADE: ".(EngineInterface::UPGRADE)."\n";
//        $this->write(EngineInterface::UPGRADE);

        //remove message '40' from buffer, emmiting by socket.io after receiving EngineInterface::UPGRADE
        if ($this->options['version'] === 2)
            $this->read();
    }

    protected function receiveFragment() {

        // Just read the main fragment information first.
        $data = $this->readLength(2);
        // Is this the final fragment?  // Bit 0 in byte 0
        /// @todo Handle huge payloads with multiple fragments.
        $final = (boolean) (ord($data[0]) & 1 << 7);
        // Should be unused, and must be false…  // Bits 1, 2, & 3
        $rsv1  = (boolean) (ord($data[0]) & 1 << 6);
        $rsv2  = (boolean) (ord($data[0]) & 1 << 5);
        $rsv3  = (boolean) (ord($data[0]) & 1 << 4);
        // Parse opcode
        $opcode_int = ord($data[0]) & 31; // Bits 4-7
        $opcode_ints = array_flip(self::$opcodes);
        if (!array_key_exists($opcode_int, $opcode_ints)) {
          //throw new ConnectionException("Bad opcode in websocket frame: $opcode_int");
        }
        $opcode = $opcode_ints[$opcode_int];
        // record the opcode if we are not receiving a continutation fragment
        if ($opcode !== 'continuation') {
          $this->last_opcode = $opcode;
        }
        // Masking?
        $mask = (boolean) (ord($data[1]) >> 7);  // Bit 0 in byte 1
        $payload = '';
        // Payload length
        $payload_length = (integer) ord($data[1]) & 127; // Bits 1-7 in byte 1
        if ($payload_length > 125) {
          if ($payload_length === 126) $data = $this->readLength(2); // 126: Payload is a 16-bit unsigned int
          else                         $data = $this->readLength(8); // 127: Payload is a 64-bit unsigned int
          $payload_length = bindec(self::sprintB($data));
        }
        // Get masking key.
        if ($mask) $masking_key = $this->readLength(4);
        // Get the actual payload, if any (might not be for e.g. close frames.
        if ($payload_length > 0) {
          $data = $this->readLength($payload_length);
          if ($mask) {
            // Unmask payload.
            for ($i = 0; $i < $payload_length; $i++) $payload .= ($data[$i] ^ $masking_key[$i % 4]);
          }
          else $payload = $data;
        }
        if ($opcode === 'close') {
          // Get the close status.
          if ($payload_length >= 2) {
            $status_bin = $payload[0] . $payload[1];
            $status = bindec(sprintf("%08b%08b", ord($payload[0]), ord($payload[1])));
            $this->close_status = $status;
            $payload = substr($payload, 2);
            if (!$this->is_closing) $this->send($status_bin . 'Close acknowledged: ' . $status, 'close', true); // Respond.
          }
          if ($this->is_closing) $this->is_closing = false; // A close response, all done.
          // And close the socket.
          fclose($this->stream);
          $this->is_connected = false;
        }
        // if this is not the last fragment, then we need to save the payload
        if (!$final) {
          $this->huge_payload .= $payload;
          return null;
        }
        // this is the last fragment, and we are processing a huge_payload
        else if ($this->huge_payload) {
          // sp we need to retreive the whole payload
          $payload = $this->huge_payload .= $payload;
          $this->huge_payload = null;
        }
        return $payload;

    }

    protected function readLength($length) {

        $data = '';
        while (strlen($data) < $length) {
          $buffer = fread($this->stream, $length - strlen($data));
          if ($buffer === false) {
            $metadata = stream_get_meta_data($this->stream);

            throw new SocketException(0,
              'WebSocket, broken frame, read ' . strlen($data) . ' of stated '
              . $length . ' bytes.  Stream state: '
              . json_encode($metadata)
            );

          }
          if ($buffer === '') {
            $metadata = stream_get_meta_data($this->stream);

            throw new SocketException(0,
              'WebSocket, empty read. Connection dead?  Stream state: ' . json_encode($metadata)
            );

          }
          $data .= $buffer;
        }
        return $data;
    }
}
