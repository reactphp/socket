<?php

namespace React\Socket;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\Promise;
use function React\Promise\reject;

final class TcpConnector implements ConnectorInterface
{
    private $loop;
    private $context;

    public function __construct(?LoopInterface $loop = null, array $context = [])
    {
        $this->loop = $loop ?? Loop::get();
        $this->context = $context;
    }

    public function connect($uri)
    {
        if (\strpos($uri, '://') === false) {
            $uri = 'tcp://' . $uri;
        }

        $parts = \parse_url($uri);
        if (!$parts || !isset($parts['scheme'], $parts['host'], $parts['port']) || $parts['scheme'] !== 'tcp') {
            return reject(new \InvalidArgumentException(
                'Given URI "' . $uri . '" is invalid (EINVAL)',
                \defined('SOCKET_EINVAL') ? \SOCKET_EINVAL : (\defined('PCNTL_EINVAL') ? \PCNTL_EINVAL : 22)
            ));
        }

        $ip = \trim($parts['host'], '[]');
        if (@\inet_pton($ip) === false) {
            return reject(new \InvalidArgumentException(
                'Given URI "' . $uri . '" does not contain a valid host IP (EINVAL)',
                \defined('SOCKET_EINVAL') ? \SOCKET_EINVAL : (\defined('PCNTL_EINVAL') ? \PCNTL_EINVAL : 22)
            ));
        }

        // use context given in constructor
        $context = [
            'socket' => $this->context
        ];

        // parse arguments from query component of URI
        $args = [];
        if (isset($parts['query'])) {
            \parse_str($parts['query'], $args);
        }

        // If an original hostname has been given, use this for TLS setup.
        // This can happen due to layers of nested connectors, such as a
        // DnsConnector reporting its original hostname.
        // These context options are here in case TLS is enabled later on this stream.
        // If TLS is not enabled later, this doesn't hurt either.
        if (isset($args['hostname'])) {
            $context['ssl'] = [
                'SNI_enabled' => true,
                'peer_name' => $args['hostname']
            ];
        }

        // PHP 7.1.4 does not accept any other URI components (such as a query with no path), so let's simplify our URI here
        $remote = 'tcp://' . $parts['host'] . ':' . $parts['port'];

        $stream = @\stream_socket_client(
            $remote,
            $errno,
            $errstr,
            0,
            \STREAM_CLIENT_CONNECT | \STREAM_CLIENT_ASYNC_CONNECT,
            \stream_context_create($context)
        );

        if (false === $stream) {
            return reject(new \RuntimeException(
                'Connection to ' . $uri . ' failed: ' . $errstr . SocketServer::errconst($errno),
                $errno
            ));
        }

        // Support socket_set_option($socket, SOL_SOCKET, $option, $value)
        // Sets socket options for the socket: https://www.php.net/manual/en/function.socket-set-option.php
        if (isset($context['socket']['socket_options']) && \function_exists('socket_import_stream')) {
            $socket = \socket_import_stream($stream);
            foreach ($context['socket']['socket_options'] as $option => $value) {
                \socket_set_option($socket, \SOL_SOCKET, $option, $value);
            }
            $stream = \socket_export_stream($socket);
        }

        // wait for connection
        return new Promise(function ($resolve, $reject) use ($stream, $uri) {
            $this->loop->addWriteStream($stream, function ($stream) use ($resolve, $reject, $uri) {
                $this->loop->removeWriteStream($stream);

                // The following hack looks like the only way to
                // detect connection refused errors with PHP's stream sockets.
                if (false === \stream_socket_get_name($stream, true)) {
                    // If we reach this point, we know the connection is dead, but we don't know the underlying error condition.
                    // @codeCoverageIgnoreStart
                    if (\function_exists('socket_import_stream')) {
                        // actual socket errno and errstr can be retrieved with ext-sockets
                        $socket = \socket_import_stream($stream);
                        $errno = \socket_get_option($socket, \SOL_SOCKET, \SO_ERROR);
                        $errstr = \socket_strerror($errno);
                    } elseif (\PHP_OS === 'Linux') {
                        // Linux reports socket errno and errstr again when trying to write to the dead socket.
                        // Suppress error reporting to get error message below and close dead socket before rejecting.
                        // This is only known to work on Linux, Mac and Windows are known to not support this.
                        $errno = 0;
                        $errstr = '';
                        \set_error_handler(function ($_, $error) use (&$errno, &$errstr) {
                            // Match errstr from PHP's warning message.
                            // fwrite(): send of 1 bytes failed with errno=111 Connection refused
                            \preg_match('/errno=(\d+) (.+)/', $error, $m);
                            $errno = (int) ($m[1] ?? 0);
                            $errstr = $m[2] ?? $error;
                        });

                        \fwrite($stream, \PHP_EOL);

                        \restore_error_handler();
                    } else {
                        // Not on Linux and ext-sockets not available? Too bad.
                        $errno = \defined('SOCKET_ECONNREFUSED') ? \SOCKET_ECONNREFUSED : 111;
                        $errstr = 'Connection refused?';
                    }
                    // @codeCoverageIgnoreEnd

                    \fclose($stream);
                    $reject(new \RuntimeException(
                        'Connection to ' . $uri . ' failed: ' . $errstr . SocketServer::errconst($errno),
                        $errno
                    ));
                } else {
                    $resolve(new Connection($stream, $this->loop));
                }
            });
        }, function () use ($stream, $uri) {
            $this->loop->removeWriteStream($stream);
            \fclose($stream);

            throw new \RuntimeException(
                'Connection to ' . $uri . ' cancelled during TCP/IP handshake (ECONNABORTED)',
                \defined('SOCKET_ECONNABORTED') ? \SOCKET_ECONNABORTED : 103
            );
        });
    }
}
