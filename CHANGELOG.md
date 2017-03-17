# Changelog

## 0.6.2 (2017-03-17)

* Feature / Fix: Support SNI on legacy PHP < 5.6 and add documentation for
  supported PHP and HHVM versions.
  (#90 and #91 by @clue)

## 0.6.1 (2017-03-10)

* Feature: Forward compatibility with Stream v0.5 and upcoming v0.6
  (#89 by @clue)

* Fix: Fix examples to use updated API
  (#88 by @clue)

## 0.6.0 (2017-02-17)

* Feature / BC break: Use `connect($uri)` instead of `create($host, $port)`
  and resolve with a `ConnectionInterface` instead of `Stream`
  and expose remote and local addresses through this interface
  and remove superfluous and undocumented `ConnectionException`.
  (#74, #82 and #84 by @clue)

  ```php
  // old
  $connector->create('google.com', 80)->then(function (Stream $conn) {
      echo 'Connected' . PHP_EOL;
      $conn->write("GET / HTTP/1.0\r\n\r\n");
  });

  // new
  $connector->connect('google.com:80')->then(function (ConnectionInterface $conn) {
      echo 'Connected to ' . $conn->getRemoteAddress() . PHP_EOL;
      $conn->write("GET / HTTP/1.0\r\n\r\n");
  });
  ```

  > Note that both the old `Stream` and the new `ConnectionInterface` implement
    the same underlying `DuplexStreamInterface`, so their streaming behavior is
    actually equivalent.
    In order to upgrade, simply use the new typehints.
    Existing stream handlers should continue to work unchanged.

* Feature / BC break: All connectors now MUST offer cancellation support.
  You can now rely on getting a rejected promise when calling `cancel()` on a
  pending connection attempt.
  (#79 by @clue)

  ```php
  // old: promise resolution not enforced and thus unreliable
  $promise = $connector->create($host, $port);
  $promise->cancel();
  $promise->then(/* MAY still be called */, /* SHOULD be called */);

  // new: rejecting after cancellation is mandatory
  $promise = $connector->connect($uri);
  $promise->cancel();
  $promise->then(/* MUST NOT be called */, /* MUST be called */);
  ```

  > Note that this behavior is only mandatory for *pending* connection attempts.
    Once the promise is settled (resolved), calling `cancel()` will have no effect.

* BC break: All connector classes are now marked `final`
  and you can no longer `extend` them
  (which was never documented or recommended anyway).
  Please use composition instead of extension.
  (#85 by @clue)

## 0.5.3 (2016-12-24)

* Fix: Skip IPv6 tests if not supported by the system
  (#76 by @clue)

* Documentation for `ConnectorInterface`
  (#77 by @clue)

## 0.5.2 (2016-12-19)

* Feature: Replace `SecureStream` with unlimited read buffer from react/stream v0.4.5
  (#72 by @clue)

* Feature: Add examples
  (#75 by @clue)

## 0.5.1 (2016-11-20)

* Feature: Support Promise cancellation for all connectors
  (#71 by @clue)

  ```php
  $promise = $connector->create($host, $port);

  $promise->cancel();
  ```

* Feature: Add TimeoutConnector decorator
  (#51 by @clue)

  ```php
  $timeout = new TimeoutConnector($connector, 3.0, $loop);
  $timeout->create($host, $port)->then(function(Stream $stream) {
      // connection resolved within 3.0s
  });
  ```

## 0.5.0 (2016-03-19)

* Feature / BC break: Support Connector without DNS
  (#46 by @clue)

  BC break: The `Connector` class now serves as a BC layer only.
  The `TcpConnector` and `DnsConnector` classes replace its functionality.
  If you're merely *using* this class, then you're *recommended* to upgrade as
  per the below snippet – existing code will still work unchanged.
  If you're `extend`ing the `Connector` (generally not recommended), then you
  may have to rework your class hierarchy.

  ```php
// old (still supported, but marked deprecated)
$connector = new Connector($loop, $resolver);

// new equivalent
$connector = new DnsConnector(new TcpConnector($loop), $resolver);

// new feature: supports connecting to IP addresses only
$connector = new TcpConnector($loop);
```

* Feature: Add socket and SSL/TLS context options to connectors
  (#52 by @clue)

* Fix: PHP 5.6+ uses new SSL/TLS context options
  (#61 by @clue)

* Fix: Move SSL/TLS context options to SecureConnector
  (#43 by @clue)

* Fix: Fix error reporting for invalid addresses
  (#47 by @clue)

* Fix: Close stream resource if connection fails
  (#48 by @clue)

* First class support for PHP 5.3 through PHP 7 and HHVM
  (#53, #54 by @clue)

* Add integration tests for SSL/TLS sockets
  (#62 by @clue)

## 0.4.4 (2015-09-23)

* Feature: Add support for Unix domain sockets (UDS) (#41 by @clue)
* Bugfix: Explicitly set supported TLS versions for PHP 5.6+ (#31 by @WyriHaximus)
* Bugfix: Ignore SSL non-draining buffer workaround for PHP 5.6.8+ (#33 by @alexmace)

## 0.4.3 (2015-03-20)

* Bugfix: Set peer name to hostname to correct security concern in PHP 5.6 (@WyriHaximus)
* Bugfix: Always wrap secure to pull buffer due to regression in PHP
* Bugfix: SecureStream extends Stream to match documentation preventing BC (@clue)

## 0.4.2 (2014-10-16)

* Bugfix: Only toggle the stream crypto handshake once (@DaveRandom and @rdlowrey)
* Bugfix: Workaround for ext-openssl buffering bug (@DaveRandom)
* Bugfix: SNI fix for PHP < 5.6 (@DaveRandom)

## 0.4.(0/1) (2014-02-02)

* BC break: Bump minimum PHP version to PHP 5.4, remove 5.3 specific hacks
* BC break: Update to React/Promise 2.0
* Dependency: Autoloading and filesystem structure now PSR-4 instead of PSR-0
* Bump React dependencies to v0.4

## 0.3.1 (2013-04-21)

* Feature: [SocketClient] Support connecting to IPv6 addresses (@clue)

## 0.3.0 (2013-04-14)

* Feature: [SocketClient] New SocketClient component extracted from HttpClient (@clue)
