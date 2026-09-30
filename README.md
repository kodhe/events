# kodhe/events

A standalone, general-purpose **PSR-14 event dispatcher** for the Kodhe framework.

The package intentionally has **no knowledge of hooks or auth**: it is a pure
event system you can use anywhere (controllers, services, queues, domains).
Bridges to other subsystems live in those subsystems' own packages:

| Bridge | Lives in | Purpose |
|---|---|---|
| `Support\Legacy\Bridge\HookBridge` | `kodhe/framework` | Mirrors legacy CI3-style hooks onto any PSR-14 dispatcher (and vice versa) |
| `Auth\Bridge\PsrAuthEventDispatcher` | `kodhe/auth` | Forwards `AuthEvents` emissions to any PSR-14 dispatcher |

## Install

```bash
composer require kodhe/events psr/event-dispatcher
```

## Usage

```php
use Kodhe\Framework\Events\Event;
use Kodhe\Framework\Events\EventDispatcher;

$dispatcher = new EventDispatcher();

// Named listeners (exact name, wildcard group, priority — higher runs first)
$dispatcher->on('user.registered', fn (Event $e) => sendWelcome($e->get('id')));
$dispatcher->on('order.*', fn (Event $e) => audit($e->name()), priority: 100);
$dispatcher->once('cache.cleared', $clearStats);   // auto-removed after firing

// Class-based listeners (PSR-14 style): match FQCN, parents and interfaces
$dispatcher->on(App\OrderShipped::class, $notifyCustomer);

// Dispatching
$dispatcher->dispatch(new Event('user.registered', ['id' => 7]));
$dispatcher->dispatch(new App\OrderShipped($order));   // returns the (possibly modified) event

// Propagation control: return FALSE from a listener, or implement
// Psr\EventDispatcher\StoppableEventInterface on your event class.
```

### Global helper

```php
event('user.registered', ['id' => 7]);   // string form -> generic Event
event(new App\OrderShipped($order));     // object form -> dispatched as-is

event_dispatcher()->on('mail.sent', $logger);   // shared default dispatcher
```

## Notes

- Implements both `EventDispatcherInterface` and `ListenerProviderInterface`,
  so it interoperates with any PSR-14-aware container/tooling.
- Listener ordering is deterministic: descending priority, registration order
  preserved on ties.
- Wildcards are explicit prefix groups (`order.*` matches `order.shipped`,
  not `order` itself).
