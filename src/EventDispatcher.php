<?php

declare(strict_types=1);

namespace Kodhe\Framework\Events;

/**
 * PSR-14 event dispatcher with priority listeners and wildcard names.
 *
 * Registration:
 *
 *     $dispatcher->on('user.registered', fn (Event $e) => sendWelcome($e->get('id')));
 *     $dispatcher->on(AuditRequested::class, $listener, priority: 100); // higher runs first
 *     $dispatcher->on('order.*', fn ($e) => log($e));                   // wildcard match
 *     $dispatcher->once('cache.cleared', $clearStats);                  // auto-remove after fire
 *
 * Dispatching:
 *
 *     $dispatcher->dispatch(new Event('user.registered', ['id' => 7]));
 *     $dispatcher->dispatch(new AuditRequested($user));   // class-name listeners
 *
 * A listener receives the event object. Returning FALSE from a listener
 * stops propagation for that dispatch run (in addition to the PSR-14
 * StoppableEventInterface contract).
 *
 * Implements both psr/event-dispatcher interfaces so it can be used as an
 * EventDispatcher anywhere a PSR-14 dispatcher is expected, and plugged
 * into a ListenerProvider-aware container.
 */
class EventDispatcher implements \Psr\EventDispatcher\EventDispatcherInterface, \Psr\EventDispatcher\ListenerProviderInterface
{
    /** @var array<string, array<int, list<callable>>> listeners keyed by name, then priority */
    private array $listeners = [];

    /** @var array<int, array{event: string, priority: int, listener: callable}> once() registrations by slot id */
    private array $onceRegistry = [];

    /** @var int monotonically increasing listener slot id (also preserves insertion order) */
    private int $sequence = 0;

    /**
     * Register a listener for an event name or class.
     *
     * @param string   $event    Exact name ('user.registered'), exact class
     *                           ('App\\Event\\AuditRequested'), or wildcard
     *                           prefix ending in '.*' ('order.*').
     * @param callable $listener Receives the event object as its only argument.
     * @param int      $priority Higher priority runs first; ties keep registration order.
     */
    public function on(string $event, callable $listener, int $priority = 0): static
    {
        // Keyed by the global sequence so ordering across priorities and
        // registration keys can be resolved deterministically (see collectMatching()).
        $this->listeners[$event][$priority][++$this->sequence] = $listener;

        return $this;
    }

    /**
     * Register a listener that is removed automatically after its first call.
     *
     * Pass the ORIGINAL callable to remove() to cancel it before it fires.
     */
    public function once(string $event, callable $listener, int $priority = 0): static
    {
        $id = ++$this->sequence;
        $wrapped = function (object $payload) use ($listener, $event, $priority, $id): mixed {
            // Remove our own registration by id before invoking the callback,
            // so a re-dispatch from within a listener cannot double-fire it.
            unset($this->listeners[$event][$priority][$id]);
            unset($this->onceRegistry[$id]);

            return $listener($payload);
        };

        // Track wrapper -> original so remove(original) can cancel it first.
        $this->onceRegistry[$id] = ['event' => $event, 'priority' => $priority, 'listener' => $listener];
        $this->listeners[$event][$priority][$id] = $wrapped;

        return $this;
    }

    /**
     * Remove a previously registered listener (on() or once(); for once()
     * pass the ORIGINAL callable).
     */
    public function remove(string $event, callable $listener, int $priority = 0): static
    {
        if (!isset($this->listeners[$event][$priority])) {
            return $this;
        }

        foreach ($this->listeners[$event][$priority] as $i => $registered) {
            $original = $this->onceRegistry[$i]['listener'] ?? null;
            $isMatch = $registered === $listener || $original === $listener;

            if ($isMatch) {
                unset($this->listeners[$event][$priority][$i], $this->onceRegistry[$i]);
                break;
            }
        }

        if (($this->listeners[$event][$priority] ?? []) === []) {
            unset($this->listeners[$event][$priority]);
        }
        if (($this->listeners[$event] ?? []) === []) {
            unset($this->listeners[$event]);
        }

        return $this;
    }

    /**
     * All listeners currently registered under an exact key (introspection/testing).
     *
     * @return array<int, list<callable>> priority => listeners
     */
    public function listenersFor(string $event): array
    {
        return $this->listeners[$event] ?? [];
    }

    /**
     * {@inheritdoc}
     *
     * Resolution order for a dispatched event:
     *  1. Listeners bound to the event's exact name (Event::name()) — for
     *     generic payload events.
     *  2. Listeners bound to the event's class name and any parent classes /
     *     interfaces it implements — for dedicated event objects.
     *  3. Wildcard listeners ('prefix.*') matching either of the above.
     *
     * All matches are merged, sorted by descending priority, and invoked
     * until one returns FALSE or the event reports propagation stopped.
     */
    public function dispatch(object $event): object
    {
        foreach ($this->getListenersForEvent($event) as $callable) {
            $result = $callable($event);

            if ($result === false) {
                break;
            }
            if ($event instanceof \Psr\EventDispatcher\StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }
        }

        return $event;
    }

    /**
     * PSR-14 ListenerProviderInterface: expose resolved listeners so this
     * dispatcher can also feed a Symfony-style EventDispatcher, etc.
     *
     * @return iterable<callable>
     */
    public function getListenersForEvent(object $event): iterable
    {
        foreach ($this->collectMatching($event) as $entry) {
            yield $entry[2];
        }
    }

    /**
     * Resolve every matching listener into sortable [priority, sequence, callable]
     * entries, ordered high-priority first with registration order preserved
     * on ties. Shared by dispatch() and the PSR-14 provider method so both
     * always agree on ordering.
     *
     * @return list<array{int, int, callable}>
     */
    private function collectMatching(object $event): array
    {
        $keys = array_flip($this->matchKeys($event));
        $entries = [];

        foreach ($this->listeners as $key => $byPriority) {
            if (!isset($keys[$key])) {
                continue;
            }
            foreach ($byPriority as $priority => $callables) {
                foreach ($callables as $sequence => $callable) {
                    $entries[] = [$priority, $sequence, $callable];
                }
            }
        }

        usort(
            $entries,
            static fn (array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]
        );

        return $entries;
    }

    /**
     * All registration keys that apply to the given event object.
     *
     * @return list<string>
     */
    private function matchKeys(object $event): array
    {
        $candidates = [];

        if ($event instanceof Event) {
            $candidates[] = $event->name();
        }

        $candidates[] = $event::class;
        foreach (class_parents($event) ?: [] as $parent) {
            $candidates[] = $parent;
        }
        foreach (class_implements($event) ?: [] as $interface) {
            $candidates[] = $interface;
        }

        $keys = [];
        foreach ($candidates as $candidate) {
            $keys[] = $candidate;
            // wildcard prefixes: 'order.shipped' -> 'order.*'
            $parts = explode('.', $candidate);
            while (count($parts) > 1) {
                array_pop($parts);
                $keys[] = implode('.', $parts) . '.*';
            }
        }

        // de-duplicate, keep deterministic order
        return array_values(array_unique($keys));
    }
}
