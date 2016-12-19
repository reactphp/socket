# Socket Component

[![Build Status](https://secure.travis-ci.org/reactphp/socket.png?branch=master)](http://travis-ci.org/reactphp/socket)

Library for building an evented socket server.

The socket component provides a more usable interface for a socket-layer
server or client based on the [`EventLoop`](https://github.com/reactphp/event-loop)
and [`Stream`](https://github.com/reactphp/stream) components.

**Table of Contents**

* [Quickstart example](#quickstart-example)
* [Usage](#usage)
  * [Server](#server)
  * [ConnectionInterface](#connectioninterface)
    * [getRemoteAddress()](#getremoteaddress)
* [Install](#install)
* [License](#license)

## Quickstart example

Here is a server that closes the connection if you send it anything:

```php
$loop = React\EventLoop\Factory::create();

$socket = new React\Socket\Server($loop);
$socket->on('connection', function (ConnectionInterface $conn) {
    $conn->write("Hello " . $conn->getRemoteAddress() . "!\n");
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

See also the [examples](examples).

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

## Usage

### Server

The `Server` class is responsible for listening on a port and waiting for new connections.

Whenever a client connects, it will emit a `connection` event with a connection
instance implementing [`ConnectionInterface`](#connectioninterface):

```php
$server->on('connection', function (ConnectionInterface $connection) {
    …
});
```

### ConnectionInterface

The `ConnectionInterface` is used to represent any incoming connection.

An incoming connection is a duplex stream (both readable and writable) that
implements React's
[`DuplexStreamInterface`](https://github.com/reactphp/stream#duplexstreaminterface)
and contains only a single additional property, the remote address (client IP)
where this connection has been established from.

> Note that this interface is only to be used to represent the server-side end
of an incoming connection.
It MUST NOT be used to represent an outgoing connection in a client-side context.
If you want to establish an outgoing connection,
use the [`SocketClient`](https://github.com/reactphp/socket-client) component instead.

Because the `ConnectionInterface` implements the underlying
[`DuplexStreamInterface`](https://github.com/reactphp/stream#duplexstreaminterface)
you can use any of its events and methods as usual:

```php
$connection->on('data', function ($chunk) {
    echo $data;
});

$conenction->on('close', function () {
    echo 'closed';
});

$connection->write($data);
$connection->end($data = null);
$connection->close();
// …
```

For more details, see the
[`DuplexStreamInterface`](https://github.com/reactphp/stream#duplexstreaminterface).

#### getRemoteAddress()

The `getRemoteAddress(): ?string` method returns the remote address
(client IP) where this connection has been established from.

```php
$ip = $connection->getRemoteAddress();
```

It will return the remote address as a string value.
If the remote address can not be determined or is unknown at this time (such as
after the connection has been closed), it MAY return a `NULL` value instead.

## Install

The recommended way to install this library is [through Composer](http://getcomposer.org).
[New to Composer?](http://getcomposer.org/doc/00-intro.md)

This will install the latest supported version:

```bash
$ composer require react/socket:^0.4.4
```

More details about version upgrades can be found in the [CHANGELOG](CHANGELOG.md).

## License

MIT, see [LICENSE file](LICENSE).
