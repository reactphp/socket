# SocketClient Component

[![Build Status](https://secure.travis-ci.org/reactphp/socket-client.png?branch=master)](http://travis-ci.org/reactphp/socket-client) [![Code Climate](https://codeclimate.com/github/reactphp/socket-client/badges/gpa.svg)](https://codeclimate.com/github/reactphp/socket-client)

Async Connector to open TCP/IP and SSL/TLS based connections.

## Introduction

Think of this library as an async version of
[`fsockopen()`](http://www.php.net/function.fsockopen) or
[`stream_socket_client()`](http://php.net/function.stream-socket-client).

Before you can actually transmit and receive data to/from a remote server, you
have to establish a connection to the remote end. Establishing this connection
through the internet/network takes some time as it requires several steps in
order to complete:

1. Resolve remote target hostname via DNS (+cache)
2. Complete TCP handshake (2 roundtrips) with remote target IP:port
3. Optionally enable SSL/TLS on the new resulting connection

## Usage

In order to use this project, you'll need the following react boilerplate code
to initialize the main loop.

```php
$loop = React\EventLoop\Factory::create();
```

### ConnectorInterface

The `ConnectorInterface` is responsible for providing an interface for
establishing streaming connections, such as a normal TCP/IP connection.

This is the main interface defined in this package and it is used throughout
React's vast ecosystem.

Most higher-level components (such as HTTP, database or other networking
service clients) accept an instance implementing this interface to create their
TCP/IP connection to the underlying networking service.
This is usually done via dependency injection, so it's fairly simple to actually
swap this implementation against any other implementation of this interface.

The interface only offers a single method:

#### create()

The `create(string $host, int $port): PromiseInterface<Stream, Exception>` method
can be used to establish a streaming connection.
It returns a [Promise](https://github.com/reactphp/promise) which either
fulfills with a [Stream](https://github.com/reactphp/stream) or
rejects with an `Exception`:

```php
$connector->create('google.com', 443)->then(
    function (Stream $stream) {
        // connection successfully established
    },
    function (Exception $error) {
        // failed to connect due to $error
    }
);
```

The returned Promise SHOULD be implemented in such a way that it can be
cancelled when it is still pending. Cancelling a pending promise SHOULD
reject its value with an `Exception`. It SHOULD clean up any underlying
resources and references as applicable:

```php
$promise = $connector->create($host, $port);

$promise->cancel();
```

### Async TCP/IP connections

The `React\SocketClient\TcpConnector` class implements the
[`ConnectorInterface`](#connectorinterface) and allows you to create plaintext
TCP/IP connections to any IP-port-combination:

```php
$tcpConnector = new React\SocketClient\TcpConnector($loop);

$tcpConnector->create('127.0.0.1', 80)->then(function (React\Stream\Stream $stream) {
    $stream->write('...');
    $stream->end();
});

$loop->run();
```

See also the [first example](examples).

Pending connection attempts can be cancelled by cancelling its pending promise like so:

```php
$promise = $tcpConnector->create($host, $port);

$promise->cancel();
```

Calling `cancel()` on a pending promise will close the underlying socket
resource, thus cancelling the pending TCP/IP connection, and reject the
resulting promise.

You can optionally pass additional
[socket context options](http://php.net/manual/en/context.socket.php)
to the constructor like this:

```php
$tcpConnector = new React\SocketClient\TcpConnector($loop, array(
    'bindto' => '192.168.0.1:0'
));
```

Note that this class only allows you to connect to IP-port-combinations.
If you want to connect to hostname-port-combinations, see also the following chapter.

### DNS resolution

The `DnsConnector` class implements the
[`ConnectorInterface`](#connectorinterface) and allows you to create plaintext
TCP/IP connections to any hostname-port-combination.

It does so by decorating a given `TcpConnector` instance so that it first
looks up the given domain name via DNS (if applicable) and then establishes the
underlying TCP/IP connection to the resolved target IP address.

Make sure to set up your DNS resolver and underlying TCP connector like this:

```php
$dnsResolverFactory = new React\Dns\Resolver\Factory();
$dns = $dnsResolverFactory->createCached('8.8.8.8', $loop);

$dnsConnector = new React\SocketClient\DnsConnector($tcpConnector, $dns);

$dnsConnector->create('www.google.com', 80)->then(function (React\Stream\Stream $stream) {
    $stream->write('...');
    $stream->end();
});

$loop->run();
```

See also the [first example](examples).

Pending connection attempts can be cancelled by cancelling its pending promise like so:

```php
$promise = $dnsConnector->create($host, $port);

$promise->cancel();
```

Calling `cancel()` on a pending promise will cancel the underlying DNS lookup
and/or the underlying TCP/IP connection and reject the resulting promise.

The legacy `Connector` class can be used for backwards-compatiblity reasons.
It works very much like the newer `DnsConnector` but instead has to be
set up like this:

```php
$connector = new React\SocketClient\Connector($loop, $dns);

$connector->create('www.google.com', 80)->then($callback);
```

### Async SSL/TLS connections

The `SecureConnector` class implements the
[`ConnectorInterface`](#connectorinterface) and allows you to create secure
TLS (formerly known as SSL) connections to any hostname-port-combination.

It does so by decorating a given `DnsConnector` instance so that it first
creates a plaintext TCP/IP connection and then enables TLS encryption on this
stream.

```php
$secureConnector = new React\SocketClient\SecureConnector($dnsConnector, $loop);

$secureConnector->create('www.google.com', 443)->then(function (React\Stream\Stream $stream) {
    $stream->write("GET / HTTP/1.0\r\nHost: www.google.com\r\n\r\n");
    ...
});

$loop->run();
```

See also the [second example](examples).

Pending connection attempts can be cancelled by cancelling its pending promise like so:

```php
$promise = $secureConnector->create($host, $port);

$promise->cancel();
```

Calling `cancel()` on a pending promise will cancel the underlying TCP/IP
connection and/or the SSL/TLS negonation and reject the resulting promise.

You can optionally pass additional
[SSL context options](http://php.net/manual/en/context.ssl.php)
to the constructor like this:

```php
$secureConnector = new React\SocketClient\SecureConnector($dnsConnector, $loop, array(
    'verify_peer' => false,
    'verify_peer_name' => false
));
```

> Advanced usage: Internally, the `SecureConnector` has to set the required
*context options* on the underlying stream resource.
It should therefor be used with a `TcpConnector` somewhere in the connector
stack so that it can allocate an empty *context* resource for each stream
resource.
Failing to do so may result in some hard to trace race conditions, because all
stream resources will use a single, shared *default context* resource otherwise.

### Connection timeouts

The `TimeoutConnector` class implements the
[`ConnectorInterface`](#connectorinterface) and allows you to add timeout
handling to any existing connector instance.

It does so by decorating any given [`ConnectorInterface`](#connectorinterface)
instance and starting a timer that will automatically reject and abort any
underlying connection attempt if it takes too long.

```php
$timeoutConnector = new React\SocketClient\TimeoutConnector($connector, 3.0, $loop);

$timeoutConnector->create('google.com', 80)->then(function (React\Stream\Stream $stream) {
    // connection succeeded within 3.0 seconds
});
```

See also any of the [examples](examples).

Pending connection attempts can be cancelled by cancelling its pending promise like so:

```php
$promise = $timeoutConnector->create($host, $port);

$promise->cancel();
```

Calling `cancel()` on a pending promise will cancel the underlying connection
attempt, abort the timer and reject the resulting promise.

### Unix domain sockets

The `UnixConnector` class implements the
[`ConnectorInterface`](#connectorinterface) and allows you to connect to
Unix domain socket (UDS) paths like this:

```php
$connector = new React\SocketClient\UnixConnector($loop);

$connector->create('/tmp/demo.sock')->then(function (React\Stream\Stream $stream) {
    $stream->write("HELLO\n");
});

$loop->run();
```

Connecting to Unix domain sockets is an atomic operation, i.e. its promise will
settle (either resolve or reject) immediately.
As such, calling `cancel()` on the resulting promise has no effect.

## Install

The recommended way to install this library is [through Composer](http://getcomposer.org).
[New to Composer?](http://getcomposer.org/doc/00-intro.md)

This will install the latest supported version:

```bash
$ composer require react/socket-client:^0.5.3
```

More details about version upgrades can be found in the [CHANGELOG](CHANGELOG.md).

## Tests

To run the test suite, you need PHPUnit. Go to the project root and run:

```bash
$ phpunit
```

The test suite also contains some optional integration tests which operate on a
TCP/IP socket server and an optional TLS/SSL terminating proxy in front of it.
The underlying TCP/IP socket server will be started automatically, whereas the
TLS/SSL terminating proxy has to be started and enabled like this:

```bash
$ stunnel -f -p stunnel.pem -d 6001 -r 6000 &
$ TEST_SECURE=6001 TEST_PLAIN=6000 phpunit
```

See also the [Travis configuration](.travis.yml) for details on how to set up
the TLS/SSL terminating proxy and the required certificate file (`stunnel.pem`)
if you're unsure.
