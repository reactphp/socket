# Upgrading to Socket v3

## Native type declarations

Socket APIs now declare parameter and return types. Custom implementations and
subclasses must use compatible signatures:

| Interface | Method |
| --- | --- |
| `ConnectorInterface` | `connect(string $uri): React\Promise\PromiseInterface` |
| `ServerInterface` | `getAddress(): ?string` |
| `ServerInterface` | `pause(): void`, `resume(): void`, `close(): void` |
| `ConnectionInterface` | `getRemoteAddress(): ?string`, `getLocalAddress(): ?string` |

Connector promises still resolve with `ConnectionInterface`. Addresses may still
be `null` when a connection or server has closed or its address is unknown.

Pass URI and path strings to connectors and servers, a `float` timeout to
`TimeoutConnector`, and an `int` or `null` connection limit and a `bool` pause flag
to `LimitingServer`. `TcpServer` still accepts a port-only string, such as `'8080'`.
Callers using `declare(strict_types=1)` must convert integer ports to strings.

Values incompatible with the declarations now raise `TypeError` immediately.
Malformed URI strings retain the existing exception or rejected-promise behavior.
Scalar coercion continues to follow PHP's normal `strict_types` rules.

Methods inherited from the current Stream and EventEmitter dependencies retain
compatible parameter signatures. PHPDoc describes resource handles and union
types that PHP 7.1 cannot express natively.
