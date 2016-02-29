# Socket Component

[![Build Status](https://secure.travis-ci.org/reactphp/socket.png?branch=master)](http://travis-ci.org/reactphp/socket)

Library for building an evented socket server.

The socket component provides a more usable interface for a socket-layer
server or client based on the [`EventLoop`](https://github.com/reactphp/event-loop)
and [`Stream`](https://github.com/reactphp/stream) components.

## Server

The server can listen on a port and will emit a `connection` event whenever a
client connects.

## Connection

The `Connection` is a readable and writable [`Stream`](https://github.com/reactphp/stream).
The incoming connection represents the server-side end of the connection.

It MUST NOT be used to represent an outgoing connection in a client-side context.
If you want to establish an outgoing connection,
use the [`SocketClient`](https://github.com/reactphp/socket-client) component instead.

## Usage

Here is a server that closes the connection if you send it anything.
```php
    $loop = React\EventLoop\Factory::create();

    $socket = new React\Socket\Server($loop);
    $socket->on('connection', function ($conn) {
        $conn->write("Hello there!\n");
        $conn->write("Welcome to this amazing server!\n");
        $conn->write("Here's a tip: don't say anything.\n");

        $conn->on('data', function ($data) use ($conn) {
            $conn->close();
        });
    });
    $socket->listen(1337);

    $loop->run();
```    
You can change the host the socket is listening on through a second parameter 
provided to the listen method:
```php
    $socket->listen(1337, '192.168.0.1');
```
Here's a client that outputs the output of said server and then attempts to
send it a string.
For anything more complex, consider using the
[`SocketClient`](https://github.com/reactphp/socket-client) component instead.
```php
    $loop = React\EventLoop\Factory::create();

    $client = stream_socket_client('tcp://127.0.0.1:1337');
    $conn = new React\Stream\Stream($client, $loop);
    $conn->pipe(new React\Stream\Stream(STDOUT, $loop));
    $conn->write("Hello World!\n");

    $loop->run();
```

## Advanced Usage

### Disabling TCP Nagle
Nagle helps to improve the efficiency of TCP communication by reducing the
number of packets that need to be sent over the network.

This helps to reduce TCP overheads.

Sometimes you want to disable Nagle to ensure that data will be sent
immediately.
```php
    $socket = new React\Socket\Server($loop);
    $socket->setTCPOption(TCP_NODELAY, true);
    $socket->on('connection', function ($conn) {
        $conn->write("This data will be sent.\n");
        usleep(200);
        $conn->write("... after 200ms!\n");
        $conn->close();
    });
    $socket->listen(1337);
```

### Setting `SO_REUSEADDR`
The `SO_REUSEADDR` socket option allows a socket to forcibly bind to a
port in use by another socket. This means multiple processes could be
launched listening on the same port.
```php
    $socket->setSocketOption(SO_REUSEADDR, true);
```
