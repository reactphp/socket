# Socket Component

[![Build Status](https://secure.travis-ci.org/reactphp/socket.png?branch=master)](http://travis-ci.org/reactphp/socket)

Async, streaming plaintext TCP/IP and secure TLS socket server for React PHP

The socket component provides a more usable interface for a socket-layer
server based on the [`EventLoop`](https://github.com/reactphp/event-loop)
and [`Stream`](https://github.com/reactphp/stream) components.

**Table of Contents**

* [Quickstart example](#quickstart-example)
* [Usage](#usage)
  * [ServerInterface](#serverinterface)
    * [connection event](#connection-event)
    * [error event](#error-event)
    * [listen()](#listen)
    * [getPort()](#getport)
    * [shutdown()](#shutdown)
  * [Server](#server)
  * [SecureServer](#secureserver)
  * [ConnectionInterface](#connectioninterface)
    * [getRemoteAddress()](#getremoteaddress)
* [Install](#install)
* [Tests](#tests)
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

### ServerInterface

The `ServerInterface` is responsible for providing an interface for accepting
incoming streaming connections, such as a normal TCP/IP connection.

Most higher-level components (such as a HTTP server) accept an instance
implementing this interface to accept incoming streaming connections.
This is usually done via dependency injection, so it's fairly simple to actually
swap this implementation against any other implementation of this interface.
This means that you SHOULD typehint against this interface instead of a concrete
implementation of this interface.

Besides defining a few methods, this interface also implements the
[`EventEmitterInterface`](https://github.com/igorw/evenement)
which allows you to react to certain events.

#### connection event

The `connection` event will be emitted whenever a new connection has been
established, i.e. a new client connects to this server socket:

```php
$server->on('connection', function (ConnectionInterface $connection) {
    echo 'new connection' . PHP_EOL;
});
```

See also the [`ConnectionInterface`](#connectioninterface) for more details
about handling the incoming connection.

#### error event

The `error` event will be emitted whenever there's an error accepting a new
connection from a client.

```php
$server->on('error', function (Exception $e) {
    echo 'error: ' . $e->getMessage() . PHP_EOL;
});
```

Note that this is not a fatal error event, i.e. the server keeps listening for
new connections even after this event.

#### listen()

The `listen(int $port, string $host = '127.0.0.1'): void` method can be used to
start listening on the given address.

This starts accepting new incoming connections on the given address.
See also the [connection event](#connection-event) for more details.

```php
$server->listen(8080);
```

By default, the server will listen on the localhost address and will not be
reachable from the outside.
You can change the host the socket is listening on through a second parameter 
provided to the listen method:

```php
$socket->listen(1337, '192.168.0.1');
```

This method MUST NOT be called more than once on the same instance.

#### getPort()

The `getPort(): int` method can be used to
return the port this server is currently listening on.

```php
$port = $server->getPort();
echo 'Server listening on port ' . $port . PHP_EOL;
```

This method MUST NOT be called before calling [`listen()`](#listen).
This method MUST NOT be called after calling [`shutdown()`](#shutdown).

#### shutdown()

The `shutdown(): void` method can be used to
shut down this listening socket.

This will stop listening for new incoming connections on this socket.

```php
echo 'Shutting down server socket' . PHP_EOL;
$server->shutdown();
```

This method MUST NOT be called before calling [`listen()`](#listen).
This method MUST NOT be called after calling [`shutdown()`](#shutdown).

### Server

The `Server` class implements the [`ServerInterface`](#serverinterface) and
is responsible for accepting plaintext TCP/IP connections.

```php
$server = new Server($loop);

$server->listen(8080);
```

Optionally, you can specify [socket context options](http://php.net/manual/en/context.socket.php)
for the underlying stream socket resource like this:

```php
$server = new Server($loop, array(
    'backlog' => 200,
    'so_reuseport' => true,
    'ipv6_v6only' => true
));

$server->listen(8080, '::1');
```

> Note that available [socket context options](http://php.net/manual/en/context.socket.php),
their defaults and effects of changing these may vary depending on your system
and/or PHP version.
Passing unknown context options has no effect.

Whenever a client connects, it will emit a `connection` event with a connection
instance implementing [`ConnectionInterface`](#connectioninterface):

```php
$server->on('connection', function (ConnectionInterface $connection) {
    echo 'Plaintext connection from ' . $connection->getRemoteAddress() . PHP_EOL;
    
    $connection->write('hello there!' . PHP_EOL);
    …
});
```

See also the [`ServerInterface`](#serverinterface) for more details.

Note that the `Server` class is a concrete implementation for TCP/IP sockets.
If you want to typehint in your higher-level protocol implementation, you SHOULD
use the generic [`ServerInterface`](#serverinterface) instead.

### SecureServer

The `SecureServer` class implements the [`ServerInterface`](#serverinterface)
and is responsible for providing a secure TLS (formerly known as SSL) server.

It does so by wrapping a [`Server`](#server) instance which waits for plaintext
TCP/IP connections and then performs a TLS handshake for each connection.
It thus requires valid [TLS context options](http://php.net/manual/en/context.ssl.php),
which in its most basic form may look something like this if you're using a
PEM encoded certificate file:

```php
$server = new Server($loop);

$server = new SecureServer($server, $loop, array(
    'local_cert' => 'server.pem'
));

$server->listen(8000);
```

> Note that the certificate file will not be loaded on instantiation but when an
incoming connection initializes its TLS context.
This implies that any invalid certificate file paths or contents will only cause
an `error` event at a later time.

If your private key is encrypted with a passphrase, you have to specify it
like this:

```php
$server = new SecureServer($server, $loop, array(
    'local_cert' => 'server.pem',
    'passphrase' => 'secret'
));
```

Whenever a client completes the TLS handshake, it will emit a `connection` event
with a connection instance implementing [`ConnectionInterface`](#connectioninterface):

```php
$server->on('connection', function (ConnectionInterface $connection) {
    echo 'Secure connection from' . $connection->getRemoteAddress() . PHP_EOL;
    
    $connection->write('hello there!' . PHP_EOL);
    …
});
```

Whenever a client fails to perform a successful TLS handshake, it will emit an
`error` event and then close the underlying TCP/IP connection:

```php
$server->on('error', function (Exception $e) {
    echo 'Error' . $e->getMessage() . PHP_EOL;
});
```

See also the [`ServerInterface`](#serverinterface) for more details.

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
$ composer require react/socket:^0.4.6
```

More details about version upgrades can be found in the [CHANGELOG](CHANGELOG.md).

## Tests

To run the test suite, you first need to clone this repo and then install all
dependencies [through Composer](http://getcomposer.org).
Because the test suite contains some circular dependencies, you may have to
manually specify the root package version like this:

```bash
$ COMPOSER_ROOT_VERSION=`git describe --abbrev=0` composer install
```

To run the test suite, go to the project root and run:

```bash
$ php vendor/bin/phpunit
```

## License

MIT, see [LICENSE file](LICENSE).
