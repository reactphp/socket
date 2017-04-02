# Socket Component

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
  * [Connector](#connector)
* [Advanced Usage](#advanced-usage)
  * [TcpConnector](#tcpconnector)
  * [DnsConnector](#dnsconnector)
  * [SecureConnector](#secureconnector)
  * [TimeoutConnector](#timeoutconnector)
  * [UnixConnector](#unixconnector)
* [Install](#install)
* [Tests](#tests)
* [License](#license)

## Usage

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

### Connector

The `Connector` class is the main class in this package that implements the
[`ConnectorInterface`](#connectorinterface) and allows you to create streaming connections.

You can use this connector to create any kind of streaming connections, such
as plaintext TCP/IP, secure TLS or local Unix connection streams.

It binds to the main event loop and can be used like this:

```php
$loop = React\EventLoop\Factory::create();
$connector = new Connector($loop);

$connector->connect($uri)->then(function (ConnectionInterface $connection) {
    $connection->write('...');
    $connection->end();
});

$loop->run();
```

In order to create a plaintext TCP/IP connection, you can simply pass a host
and port combination like this:

```php
$connector->connect('www.google.com:80')->then(function (ConnectionInterface $connection) {
    $connection->write('...');
    $connection->end();
});
```

> If you do no specify a URI scheme in the destination URI, it will assume
  `tcp://` as a default and establish a plaintext TCP/IP connection.
  Note that TCP/IP connections require a host and port part in the destination
  URI like above, all other URI components are optional.

In order to create a secure TLS connection, you can use the `tls://` URI scheme
like this:

```php
$connector->connect('tls://www.google.com:443')->then(function (ConnectionInterface $connection) {
    $connection->write('...');
    $connection->end();
});
```

In order to create a local Unix domain socket connection, you can use the
`unix://` URI scheme like this:

```php
$connector->connect('unix:///tmp/demo.sock')->then(function (ConnectionInterface $connection) {
    $connection->write('...');
    $connection->end();
});
```

Under the hood, the `Connector` is implemented as a *higher-level facade*
for the lower-level connectors implemented in this package. This means it
also shares all of their features and implementation details.
If you want to typehint in your higher-level protocol implementation, you SHOULD
use the generic [`ConnectorInterface`](#connectorinterface) instead.

In particular, the `Connector` class uses Google's public DNS server `8.8.8.8`
to resolve all hostnames into underlying IP addresses by default.
This implies that it also ignores your `hosts` file and `resolve.conf`, which
means you won't be able to connect to `localhost` and other non-public
hostnames by default.
If you want to use a custom DNS server (such as a local DNS relay), you can set
up the `Connector` like this:

```php
$connector = new Connector($loop, array(
    'dns' => '127.0.1.1'
));

$connector->connect('localhost:80')->then(function (ConnectionInterface $connection) {
    $connection->write('...');
    $connection->end();
});
```

If you do not want to use a DNS resolver at all and want to connect to IP
addresses only, you can also set up your `Connector` like this:

```php
$connector = new Connector($loop, array(
    'dns' => false
));

$connector->connect('127.0.0.1:80')->then(function (ConnectionInterface $connection) {
    $connection->write('...');
    $connection->end();
});
```

Advanced: If you need a custom DNS `Resolver` instance, you can also set up
your `Connector` like this:

```php
$dnsResolverFactory = new React\Dns\Resolver\Factory();
$resolver = $dnsResolverFactory->createCached('127.0.1.1', $loop);

$connector = new Connector($loop, array(
    'dns' => $resolver
));

$connector->connect('localhost:80')->then(function (ConnectionInterface $connection) {
    $connection->write('...');
    $connection->end();
});
```

By default, the `tcp://` and `tls://` URI schemes will use timeout value that
repects your `default_socket_timeout` ini setting (which defaults to 60s).
If you want a custom timeout value, you can simply pass this like this:

```php
$connector = new Connector($loop, array(
    'timeout' => 10.0
));
```

Similarly, if you do not want to apply a timeout at all and let the operating
system handle this, you can pass a boolean flag like this:

```php
$connector = new Connector($loop, array(
    'timeout' => false
));
```

By default, the `Connector` supports the `tcp://`, `tls://` and `unix://`
URI schemes. If you want to explicitly prohibit any of these, you can simply
pass boolean flags like this:

```php
// only allow secure TLS connections
$connector = new Connector($loop, array(
    'tcp' => false,
    'tls' => true,
    'unix' => false,
));

$connector->connect('tls://google.com:443')->then(function (ConnectionInterface $connection) {
    $connection->write('...');
    $connection->end();
});
```

The `tcp://` and `tls://` also accept additional context options passed to
the underlying connectors.
If you want to explicitly pass additional context options, you can simply
pass arrays of context options like this:

```php
// allow insecure TLS connections
$connector = new Connector($loop, array(
    'tcp' => array(
        'bindto' => '192.168.0.1:0'
    ),
    'tls' => array(
        'verify_peer' => false,
        'verify_peer_name' => false
    ),
));

$connector->connect('tls://localhost:443')->then(function (ConnectionInterface $connection) {
    $connection->write('...');
    $connection->end();
});
```

> For more details about context options, please refer to the PHP documentation
  about [socket context options](http://php.net/manual/en/context.socket.php)
  and [SSL context options](http://php.net/manual/en/context.ssl.php).

Advanced: By default, the `Connector` supports the `tcp://`, `tls://` and
`unix://` URI schemes.
For this, it sets up the required connector classes automatically.
If you want to explicitly pass custom connectors for any of these, you can simply
pass an instance implementing the `ConnectorInterface` like this:

```php
$dnsResolverFactory = new React\Dns\Resolver\Factory();
$resolver = $dnsResolverFactory->createCached('127.0.1.1', $loop);
$tcp = new DnsConnector(new TcpConnector($loop), $resolver);

$tls = new SecureConnector($tcp, $loop);

$unix = new UnixConnector($loop);

$connector = new Connector($loop, array(
    'tcp' => $tcp,
    'tls' => $tls,
    'unix' => $unix,

    'dns' => false,
    'timeout' => false,
));

$connector->connect('google.com:80')->then(function (ConnectionInterface $connection) {
    $connection->write('...');
    $connection->end();
});
```

> Internally, the `tcp://` connector will always be wrapped by the DNS resolver,
  unless you disable DNS like in the above example. In this case, the `tcp://`
  connector receives the actual hostname instead of only the resolved IP address
  and is thus responsible for performing the lookup.
  Internally, the automatically created `tls://` connector will always wrap the
  underlying `tcp://` connector for establishing the underlying plaintext
  TCP/IP connection before enabling secure TLS mode. If you want to use a custom
  underlying `tcp://` connector for secure TLS connections only, you may
  explicitly pass a `tls://` connector like above instead.
  Internally, the `tcp://` and `tls://` connectors will always be wrapped by
  `TimeoutConnector`, unless you disable timeouts like in the above example.

## Advanced Usage

### TcpConnector

The `React\Socket\TcpConnector` class implements the
[`ConnectorInterface`](#connectorinterface) and allows you to create plaintext
TCP/IP connections to any IP-port-combination:

```php
$tcpConnector = new React\Socket\TcpConnector($loop);

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
$tcpConnector = new React\Socket\TcpConnector($loop, array(
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

### DnsConnector

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

$dnsConnector = new React\Socket\DnsConnector($tcpConnector, $dns);

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

### SecureConnector

The `SecureConnector` class implements the
[`ConnectorInterface`](#connectorinterface) and allows you to create secure
TLS (formerly known as SSL) connections to any hostname-port-combination.

It does so by decorating a given `DnsConnector` instance so that it first
creates a plaintext TCP/IP connection and then enables TLS encryption on this
stream.

```php
$secureConnector = new React\Socket\SecureConnector($dnsConnector, $loop);

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
$secureConnector = new React\Socket\SecureConnector($dnsConnector, $loop, array(
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

### TimeoutConnector

The `TimeoutConnector` class implements the
[`ConnectorInterface`](#connectorinterface) and allows you to add timeout
handling to any existing connector instance.

It does so by decorating any given [`ConnectorInterface`](#connectorinterface)
instance and starting a timer that will automatically reject and abort any
underlying connection attempt if it takes too long.

```php
$timeoutConnector = new React\Socket\TimeoutConnector($connector, 3.0, $loop);

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

### UnixConnector

The `UnixConnector` class implements the
[`ConnectorInterface`](#connectorinterface) and allows you to connect to
Unix domain socket (UDS) paths like this:

```php
$connector = new React\Socket\UnixConnector($loop);

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
$ composer require react/socket-client:^0.7
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
