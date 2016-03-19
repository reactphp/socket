# Changelog

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
