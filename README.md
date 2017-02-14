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
    * [getAddress()](#getaddress)
    * [close()](#close)
  * [Server](#server)
  * [SecureServer](#secureserver)
  * [ConnectionInterface](#connectioninterface)
    * [getRemoteAddress()](#getremoteaddress)
    * [getLocalAddress()](#getlocaladdress)
* [Install](#install)
* [Tests](#tests)
* [License](#license)

## Quickstart example

Here is a server that closes the connection if you send it anything:

```php
$loop = React\EventLoop\Factory::create();

$socket = new React\Socket\Server(8080, $loop);
$socket->on('connection', function (ConnectionInterface $conn) {
    $conn->write("Hello " . $conn->getRemoteAddress() . "!\n");
    $conn->write("Welcome to this amazing server!\n");
    $conn->write("Here's a tip: don't say anything.\n");

    $conn->on('data', function ($data) use ($conn) {
        $conn->close();
    });
});

$loop->run();
```

See also the [examples](examples).

Here's a client that outputs the output of said server and then attempts to
send it a string.
For anything more complex, consider using the
[`SocketClient`](https://github.com/reactphp/socket-client) component instead.

```php
$loop = React\EventLoop\Factory::create();

$client = stream_socket_client('tcp://127.0.0.1:8080');
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


#### getAddress()

The `getAddress(): ?string` method can be used to
return the full address (IP and port) this server is currently listening on.

```php
$address = $server->getAddress();
echo 'Server listening on ' . $address . PHP_EOL;
```

It will return the full address (IP and port) or `NULL` if it is unknown
(not applicable to this server socket or already closed).

If this is a TCP/IP based server and you only want the local port, you may
use something like this:

```php
$address = $server->getAddress();
$port = parse_url('tcp://' . $address, PHP_URL_PORT);
echo 'Server listening on port ' . $port . PHP_EOL;
```

#### close()

The `close(): void` method can be used to
shut down this listening socket.

This will stop listening for new incoming connections on this socket.

```php
echo 'Shutting down server socket' . PHP_EOL;
$server->close();
```

Calling this method more than once on the same instance is a NO-OP.

### Server

The `Server` class implements the [`ServerInterface`](#serverinterface) and
is responsible for accepting plaintext TCP/IP connections.

```php
$server = new Server(8080, $loop);
```

As above, the `$uri` parameter can consist of only a port, in which case the
server will default to listening on the localhost address `127.0.0.1`,
which means it will not be reachable from outside of this system.

In order to use a random port assignment, you can use the port `0`:

```php
$server = new Server(0, $loop);
$address = $server->getAddress();
```

In order to change the host the socket is listening on, you can provide an IP
address through the first parameter provided to the constructor, optionally
preceded by the `tcp://` scheme:

```php
$server = new Server('192.168.0.1:8080', $loop);
```

If you want to listen on an IPv6 address, you MUST enclose the host in square
brackets:

```php
$server = new Server('[::1]:8080', $loop);
```

If the given URI is invalid, does not contain a port, any other scheme or if it
contains a hostname, it will throw an `InvalidArgumentException`:

```php
// throws InvalidArgumentException due to missing port
$server = new Server('127.0.0.1', $loop);
```

If the given URI appears to be valid, but listening on it fails (such as if port
is already in use or port below 1024 may require root access etc.), it will
throw a `RuntimeException`:

```php
$first = new Server(8080, $loop);

// throws RuntimeException because port is already in use
$second = new Server(8080, $loop);
```

> Note that these error conditions may vary depending on your system and/or
configuration.
See the exception message and code for more details about the actual error
condition.

Optionally, you can specify [socket context options](http://php.net/manual/en/context.socket.php)
for the underlying stream socket resource like this:

```php
$server = new Server('[::1]:8080', $loop, array(
    'backlog' => 200,
    'so_reuseport' => true,
    'ipv6_v6only' => true
));
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
$server = new Server(8000, $loop);
$server = new SecureServer($server, $loop, array(
    'local_cert' => 'server.pem'
));
```

> Note that the certificate file will not be loaded on instantiation but when an
incoming connection initializes its TLS context.
This implies that any invalid certificate file paths or contents will only cause
an `error` event at a later time.

If your private key is encrypted with a passphrase, you have to specify it
like this:

```php
$server = new Server(8000, $loop);
$server = new SecureServer($server, $loop, array(
    'local_cert' => 'server.pem',
    'passphrase' => 'secret'
));
```

> Note that available [TLS context options](http://php.net/manual/en/context.ssl.php),
their defaults and effects of changing these may vary depending on your system
and/or PHP version.
Passing unknown context options has no effect.

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

Note that the `SecureServer` class is a concrete implementation for TLS sockets.
If you want to typehint in your higher-level protocol implementation, you SHOULD
use the generic [`ServerInterface`](#serverinterface) instead.

> Advanced usage: Despite allowing any `ServerInterface` as first parameter,
you SHOULD pass a `Server` instance as first parameter, unless you
know what you're doing.
Internally, the `SecureServer` has to set the required TLS context options on
the underlying stream resources.
These resources are not exposed through any of the interfaces defined in this
package, but only through the `React\Stream\Stream` class.
The `Server` class is guaranteed to emit connections that implement
the `ConnectionInterface` and also extend the `Stream` class in order to
expose these underlying resources.
If you use a custom `ServerInterface` and its `connection` event does not
meet this requirement, the `SecureServer` will emit an `error` event and
then close the underlying connection.

### ConnectionInterface

The `ConnectionInterface` is used to represent any incoming connection.

An incoming connection is a duplex stream (both readable and writable) that
implements React's
[`DuplexStreamInterface`](https://github.com/reactphp/stream#duplexstreaminterface).
It contains additional properties for the local and remote address (client IP)
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

The `getRemoteAddress(): ?string` method returns the full remote address
(client IP and port) where this connection has been established from.

```php
$address = $connection->getRemoteAddress();
echo 'Connection from ' . $address . PHP_EOL;
```

If the remote address can not be determined or is unknown at this time (such as
after the connection has been closed), it MAY return a `NULL` value instead.

Otherwise, it will return the full remote address as a string value.
If this is a TCP/IP based connection and you only want the remote IP, you may
use something like this:

```php
$address = $connection->getRemoteAddress();
$ip = trim(parse_url('tcp://' . $address, PHP_URL_HOST), '[]');
echo 'Connection from ' . $ip . PHP_EOL;
```

#### getLocalAddress()

The `getLocalAddress(): ?string` method returns the full local address
(client IP and port) where this connection has been established to.

```php
$address = $connection->getLocalAddress();
echo 'Connection to ' . $address . PHP_EOL;
```

If the local address can not be determined or is unknown at this time (such as
after the connection has been closed), it MAY return a `NULL` value instead.

Otherwise, it will return the full local address as a string value.

This method complements the [`getRemoteAddress()`](#getremoteaddress) method,
so they should not be confused.
If your `Server` instance is listening on multiple interfaces (e.g. using
the address `0.0.0.0`), you can use this method to find out which interface
actually accepted this connection (such as a public or local interface).

## Install

The recommended way to install this library is [through Composer](http://getcomposer.org).
[New to Composer?](http://getcomposer.org/doc/00-intro.md)

This will install the latest supported version:

```bash
$ composer require react/socket:^0.5
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
