# Socket Component

[![Build Status](https://secure.travis-ci.org/reactphp/socket.png?branch=master)](http://travis-ci.org/reactphp/socket)

Async, streaming plaintext TCP/IP and secure TLS socket server for React PHP

The socket component provides a more usable interface for a socket-layer
server based on the [`EventLoop`](https://github.com/reactphp/event-loop)
and [`Stream`](https://github.com/reactphp/stream) components.

**Table of Contents**

* [Quickstart example](#quickstart-example)
* [Usage](#usage)
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
    echo 'Plaintext connection from ' . $connection->getRemoteAddress() . PHP_EOL;
    
    $connection->write('hello there!' . PHP_EOL);
    …
});
```

### SecureServer

The `SecureServer` class implements the `ServerInterface` and is responsible
for providing a secure TLS (formerly known as SSL) server.

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
$ composer require react/socket:^0.4.5
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

To run the test suite, you need PHPUnit. Go to the project root and run:

```bash
$ phpunit
```

## License

MIT, see [LICENSE file](LICENSE).
