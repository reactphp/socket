# SocketClient Component

[![Build Status](https://secure.travis-ci.org/reactphp/socket-client.png?branch=master)](http://travis-ci.org/reactphp/socket-client) [![Code Climate](https://codeclimate.com/github/reactphp/socket-client/badges/gpa.svg)](https://codeclimate.com/github/reactphp/socket-client)

Async, streaming plaintext TCP/IP and secure TLS based connections for [ReactPHP](https://reactphp.org/)

You can think of this library as an async version of
[`fsockopen()`](http://www.php.net/function.fsockopen) or
[`stream_socket_client()`](http://php.net/function.stream-socket-client).
If you want to transmit and receive data to/from a remote server, you first
have to establish a connection to the remote end.
Establishing this connection through the internet/network may take some time
as it requires several steps (such as resolving target hostname, completing
TCP/IP handshake and enabling TLS) in order to complete.
This component provides an async version of all this so you can establish and
handle multiple connections without blocking.

**Table of Contents**

* [Usage](#usage)
  * [ConnectorInterface](#connectorinterface)
    * [connect()](#connect)
  * [ConnectionInterface](#connectioninterface)
    * [getRemoteAddress()](#getremoteaddress)
    * [getLocalAddress()](#getlocaladdress)
  * [Plaintext TCP/IP connections](#plaintext-tcpip-connections)
  * [DNS resolution](#dns-resolution)
  * [Secure TLS connections](#secure-tls-connections)
  * [Connection timeout](#connection-timeouts)
  * [Unix domain sockets](#unix-domain-sockets)
* [Install](#install)
* [Tests](#tests)
* [License](#license)

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

#### connect()

The `connect(string $uri): PromiseInterface<ConnectionInterface, Exception>` method
can be used to create a streaming connection to the given remote address.

It returns a [Promise](https://github.com/reactphp/promise) which either
fulfills with a stream implementing [`ConnectionInterface`](#connectioninterface)
on success or rejects with an `Exception` if the connection is not successful:

```php
$connector->connect('google.com:443')->then(
    function (ConnectionInterface $connection) {
        // connection successfully established
    },
    function (Exception $error) {
        // failed to connect due to $error
    }
);
```

See also [`ConnectionInterface`](#connectioninterface) for more details.

The returned Promise MUST be implemented in such a way that it can be
cancelled when it is still pending. Cancelling a pending promise MUST
reject its value with an `Exception`. It SHOULD clean up any underlying
resources and references as applicable:

```php
$promise = $connector->connect($uri);

$promise->cancel();
```

### ConnectionInterface

The `ConnectionInterface` is used to represent any outgoing connection,
such as a normal TCP/IP connection.

An outgoing connection is a duplex stream (both readable and writable) that
implements React's
[`DuplexStreamInterface`](https://github.com/reactphp/stream#duplexstreaminterface).
It contains additional properties for the local and remote address
where this connection has been established to.

Most commonly, instances implementing this `ConnectionInterface` are returned
by all classes implementing the [`ConnectorInterface`](#connectorinterface).

> Note that this interface is only to be used to represent the client-side end
of an outgoing connection.
It MUST NOT be used to represent an incoming connection in a server-side context.
If you want to accept incoming connections,
use the [`Socket`](https://github.com/reactphp/socket) component instead.

Because the `ConnectionInterface` implements the underlying
[`DuplexStreamInterface`](https://github.com/reactphp/stream#duplexstreaminterface)
you can use any of its events and methods as usual:

```php
$connection->on('data', function ($chunk) {
    echo $chunk;
});

$connection->on('end', function () {
    echo 'ended';
});

$connection->on('error', function (Exception $e) {
    echo 'error: ' . $e->getMessage();
});

$connection->on('close', function () {
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

The `getRemoteAddress(): ?string` method can be used to
return the remote address (IP and port) where this connection has been
established to.

```php
$address = $connection->getRemoteAddress();
echo 'Connected to ' . $address . PHP_EOL;
```

If the remote address can not be determined or is unknown at this time (such as
after the connection has been closed), it MAY return a `NULL` value instead.

Otherwise, it will return the full remote address as a string value.
If this is a TCP/IP based connection and you only want the remote IP, you may
use something like this:

```php
$address = $connection->getRemoteAddress();
$ip = trim(parse_url('tcp://' . $address, PHP_URL_HOST), '[]');
echo 'Connected to ' . $ip . PHP_EOL;
```

#### getLocalAddress()

The `getLocalAddress(): ?string` method can be used to
return the full local address (IP and port) where this connection has been
established from.

```php
$address = $connection->getLocalAddress();
echo 'Connected via ' . $address . PHP_EOL;
```

If the local address can not be determined or is unknown at this time (such as
after the connection has been closed), it MAY return a `NULL` value instead.

Otherwise, it will return the full local address as a string value.

This method complements the [`getRemoteAddress()`](#getremoteaddress) method,
so they should not be confused.

If your system has multiple interfaces (e.g. a WAN and a LAN interface),
you can use this method to find out which interface was actually
used for this connection.

### Plaintext TCP/IP connections

The `React\SocketClient\TcpConnector` class implements the
[`ConnectorInterface`](#connectorinterface) and allows you to create plaintext
TCP/IP connections to any IP-port-combination:

```php
$tcpConnector = new React\SocketClient\TcpConnector($loop);

$tcpConnector->connect('127.0.0.1:80')->then(function (ConnectionInterface $connection) {
    $connection->write('...');
    $connection->end();
});

$loop->run();
```

See also the [first example](examples).

Pending connection attempts can be cancelled by cancelling its pending promise like so:

```php
$promise = $tcpConnector->connect('127.0.0.1:80');

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
If the given URI is invalid, does not contain a valid IP address and port
or contains any other scheme, it will reject with an
`InvalidArgumentException`:

If the given URI appears to be valid, but connecting to it fails (such as if
the remote host rejects the connection etc.), it will reject with a
`RuntimeException`.

If you want to connect to hostname-port-combinations, see also the following chapter.

> Advanced usage: Internally, the `TcpConnector` allocates an empty *context*
resource for each stream resource.
If the destination URI contains a `hostname` query parameter, its value will
be used to set up the TLS peer name.
This is used by the `SecureConnector` and `DnsConnector` to verify the peer
name and can also be used if you want a custom TLS peer name.

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
$dns = $dnsResolverFactory->connectCached('8.8.8.8', $loop);

$dnsConnector = new React\SocketClient\DnsConnector($tcpConnector, $dns);

$dnsConnector->connect('www.google.com:80')->then(function (ConnectionInterface $connection) {
    $connection->write('...');
    $connection->end();
});

$loop->run();
```

See also the [first example](examples).

Pending connection attempts can be cancelled by cancelling its pending promise like so:

```php
$promise = $dnsConnector->connect('www.google.com:80');

$promise->cancel();
```

Calling `cancel()` on a pending promise will cancel the underlying DNS lookup
and/or the underlying TCP/IP connection and reject the resulting promise.

The legacy `Connector` class can be used for backwards-compatiblity reasons.
It works very much like the newer `DnsConnector` but instead has to be
set up like this:

```php
$connector = new React\SocketClient\Connector($loop, $dns);

$connector->connect('www.google.com:80')->then($callback);
```

> Advanced usage: Internally, the `DnsConnector` relies on a `Resolver` to
look up the IP address for the given hostname.
It will then replace the hostname in the destination URI with this IP and
append a `hostname` query parameter and pass this updated URI to the underlying
connector.
The underlying connector is thus responsible for creating a connection to the
target IP address, while this query parameter can be used to check the original
hostname and is used by the `TcpConnector` to set up the TLS peer name.
If a `hostname` is given explicitly, this query parameter will not be modified,
which can be useful if you want a custom TLS peer name.

### Secure TLS connections

The `SecureConnector` class implements the
[`ConnectorInterface`](#connectorinterface) and allows you to create secure
TLS (formerly known as SSL) connections to any hostname-port-combination.

It does so by decorating a given `DnsConnector` instance so that it first
creates a plaintext TCP/IP connection and then enables TLS encryption on this
stream.

```php
$secureConnector = new React\SocketClient\SecureConnector($dnsConnector, $loop);

$secureConnector->connect('www.google.com:443')->then(function (ConnectionInterface $connection) {
    $connection->write("GET / HTTP/1.0\r\nHost: www.google.com\r\n\r\n");
    ...
});

$loop->run();
```

See also the [second example](examples).

Pending connection attempts can be cancelled by cancelling its pending promise like so:

```php
$promise = $secureConnector->connect('www.google.com:443');

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

> Advanced usage: Internally, the `SecureConnector` relies on setting up the
required *context options* on the underlying stream resource.
It should therefor be used with a `TcpConnector` somewhere in the connector
stack so that it can allocate an empty *context* resource for each stream
resource and verify the peer name.
Failing to do so may result in a TLS peer name mismatch error or some hard to
trace race conditions, because all stream resources will use a single, shared
*default context* resource otherwise.

### Connection timeouts

The `TimeoutConnector` class implements the
[`ConnectorInterface`](#connectorinterface) and allows you to add timeout
handling to any existing connector instance.

It does so by decorating any given [`ConnectorInterface`](#connectorinterface)
instance and starting a timer that will automatically reject and abort any
underlying connection attempt if it takes too long.

```php
$timeoutConnector = new React\SocketClient\TimeoutConnector($connector, 3.0, $loop);

$timeoutConnector->connect('google.com:80')->then(function (ConnectionInterface $connection) {
    // connection succeeded within 3.0 seconds
});
```

See also any of the [examples](examples).

Pending connection attempts can be cancelled by cancelling its pending promise like so:

```php
$promise = $timeoutConnector->connect('google.com:80');

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

$connector->connect('/tmp/demo.sock')->then(function (ConnectionInterface $connection) {
    $connection->write("HELLO\n");
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
$ composer require react/socket-client:^0.6.1
```

More details about version upgrades can be found in the [CHANGELOG](CHANGELOG.md).

This project supports running on legacy PHP 5.3 through current PHP 7+ and HHVM.
It's *highly recommended to use PHP 7+* for this project, partly due to its vast
performance improvements and partly because legacy PHP versions require several
workarounds as described below.

Secure TLS connections received some major upgrades starting with PHP 5.6, with
the defaults now being more secure, while older versions required explicit
context options.
This library does not take responsibility over these context options, so it's
up to consumers of this library to take care of setting appropriate context
options as described above.

All versions of PHP prior to 5.6.8 suffered from a buffering issue where reading
from a streaming TLS connection could be one `data` event behind.
This library implements a work-around to try to flush the complete incoming
data buffers on these versions, but we have seen reports of people saying this
could still affect some older versions (`5.5.23`, `5.6.7`, and `5.6.8`).
Note that this only affects *some* higher-level streaming protocols, such as
IRC over TLS, but should not affect HTTP over TLS (HTTPS).
Further investigation of this issue is needed.
For more insights, this issue is also covered by our test suite.

This project also supports running on HHVM.
Note that really old HHVM < 3.8 does not support secure TLS connections, as it
lacks the required `stream_socket_enable_crypto()` function.
As such, trying to create a secure TLS connections on affected versions will
return a rejected promise instead.
This issue is also covered by our test suite, which will skip related tests
on affected versions.

## Tests

To run the test suite, you first need to clone this repo and then install all
dependencies [through Composer](http://getcomposer.org):

```bash
$ composer install
```

To run the test suite, go to the project root and run:

```bash
$ php vendor/bin/phpunit
```

## License

MIT, see [LICENSE file](LICENSE).
