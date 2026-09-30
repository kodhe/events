<?php

declare(strict_types=1);

/**
 * Test suite for the kodhe/events package.
 *
 * The PSR-14 interfaces are stubbed here so the suite runs without Composer;
 * when psr/event-dispatcher is installed, its real interfaces load first and
 * these stubs are skipped (guarded by interface_exists).
 */

namespace Psr\EventDispatcher {
    if (!interface_exists(EventDispatcherInterface::class, false)) {
        interface EventDispatcherInterface
        {
            public function dispatch(object $event): object;
        }
    }
    if (!interface_exists(ListenerProviderInterface::class, false)) {
        interface ListenerProviderInterface
        {
            public function getListenersForEvent(object $event): iterable;
        }
    }
    if (!interface_exists(StoppableEventInterface::class, false)) {
        interface StoppableEventInterface
        {
            public function isPropagationStopped(): bool;
        }
    }
}

namespace Kodhe\Framework\Events\Tests {

    require_once __DIR__ . '/../src/Event.php';
    require_once __DIR__ . '/../src/EventDispatcher.php';

    use Kodhe\Framework\Events\Event;
    use Kodhe\Framework\Events\EventDispatcher;

    $passed = 0;
    $failed = 0;

    function check(string $label, bool $condition): void
    {
        global $passed, $failed;
        if ($condition) {
            $passed++;
            echo "ok   - $label\n";
        } else {
            $failed++;
            echo "FAIL - $label\n";
        }
    }

    // Dedicated event class for class-name listener tests
    class OrderShipped
    {
        public function __construct(public int $id)
        {
        }
    }

    class BaseAudit
    {
    }

    class SpecificAudit extends BaseAudit
    {
    }

    // Fake legacy hooks object
    class FakeHooks
    {
        /** @var list<string> */
        public array $called = [];

        public function call_hook($which = ''): bool
        {
            if ($which === 'missing') {
                return false;
            }
            $this->called[] = $which;

            return true;
        }
    }

    // ---------------------------------------------------------------------
    $d = new EventDispatcher();
    $log = [];
    $d->on('user.registered', function (Event $e) use (&$log) {
        $log[] = 'a:' . $e->get('id');
    });
    $d->dispatch(new Event('user.registered', ['id' => 7]));
    check('named listener receives payload', $log === ['a:7']);

    // priority: higher first
    $d2 = new EventDispatcher();
    $order = [];
    $d2->on('x', function () use (&$order) { $order[] = 'low'; }, 0);
    $d2->on('x', function () use (&$order) { $order[] = 'high'; }, 10);
    $d2->on('x', function () use (&$order) { $order[] = 'mid'; }, 5);
    $d2->dispatch(new Event('x'));
    check('priority ordering high->low', $order === ['high', 'mid', 'low']);

    // same priority keeps insertion order
    $d3 = new EventDispatcher();
    $seq = [];
    $d3->on('y', function () use (&$seq) { $seq[] = 1; });
    $d3->on('y', function () use (&$seq) { $seq[] = 2; });
    $d3->dispatch(new Event('y'));
    check('insertion order within same priority', $seq === [1, 2]);

    // wildcard names: 'group.*' subscribes to every event in the group,
    // regardless of segment depth (Laravel-style grouping).
    $d4 = new EventDispatcher();
    $hits = 0;
    $d4->on('order.*', function () use (&$hits) { $hits++; });
    $d4->dispatch(new Event('order.shipped'));       // match
    $d4->dispatch(new Event('order.refunded.sub'));  // match (deeper)
    $d4->dispatch(new Event('invoice.paid'));        // no match
    $d4->dispatch(new Event('orders.archive'));      // no match (different literal)
    check('wildcard matches whole group at any depth', $hits === 2);

    // class-name listeners + inheritance
    $d5 = new EventDispatcher();
    $seen = [];
    $d5->on(OrderShipped::class, function (OrderShipped $e) use (&$seen) { $seen[] = 'exact:' . $e->id; });
    $d5->dispatch(new OrderShipped(42));
    check('class-name listener', $seen === ['exact:42']);

    $d6 = new EventDispatcher();
    $base = 0;
    $d6->on(BaseAudit::class, function () use (&$base) { $base++; });
    $d6->dispatch(new SpecificAudit());
    check('parent-class listener matches child event', $base === 1);

    // stoppable via returning false
    $d7 = new EventDispatcher();
    $ran = [];
    $d7->on('z', function () use (&$ran) { $ran[] = 1; return false; }, 10);
    $d7->on('z', function () use (&$ran) { $ran[] = 2; }, 0);
    $d7->dispatch(new Event('z'));
    check('FALSE return stops propagation', $ran === [1]);

    // stoppable via StoppableEventInterface predicate: a listener marks the
    // event payload, and the stop predicate reads that flag.
    // PSR-14 semantics: a stopped event still lets the current listener run,
    // but propagation halts before any subsequent one.
    $preStopped = new Event('t', [], fn () => true);
    $ranFirst = false; $ranSecond = false;
    $d8b = new EventDispatcher();
    $d8b->on('t', function () use (&$ranFirst) { $ranFirst = true; }, 10);
    $d8b->on('t', function () use (&$ranSecond) { $ranSecond = true; }, 0);
    $d8b->dispatch($preStopped);
    check('stopped event runs highest-priority listener only', $ranFirst === true && $ranSecond === false);

    // stop-flag: first listener flips payload flag via set(), later ones halt
    $ev2 = new Event('s', ['halt' => false]);
    $ev2->attachStopFlag('halt');
    $ran2 = false;
    $d8c = new EventDispatcher();
    $d8c->on('s', function (Event $e) { $e->set('halt', true); }, 10);
    $d8c->on('s', function () use (&$ran2) { $ran2 = true; }, 0);
    $d8c->dispatch($ev2);
    check('attachStopFlag halts later listeners when flag set', $ran2 === false);

    // stopPropagation() convenience without a flag key
    $ev3 = new Event('u');
    $ran3 = false;
    $d8d = new EventDispatcher();
    $d8d->on('u', function (Event $e) { $e->stopPropagation(); }, 10);
    $d8d->on('u', function () use (&$ran3) { $ran3 = true; }, 0);
    $d8d->dispatch($ev3);
    check('stopPropagation() halts later listeners', $ran3 === false);

    // dispatch returns the event instance
    $d9 = new EventDispatcher();
    $returned = $d9->dispatch($ev = new Event('nothing.listened'));
    check('dispatch returns event even with no listeners', $returned === $ev);

    // once() fires exactly one time
    $d10 = new EventDispatcher();
    $onceCount = 0;
    $listener = function () use (&$onceCount) { $onceCount++; };
    $d10->once('one', $listener);
    $d10->dispatch(new Event('one'));
    $d10->dispatch(new Event('one'));
    check('once() auto-removes after first call', $onceCount === 1);

    // remove() cancels an on() listener
    $d11 = new EventDispatcher();
    $removed = 0;
    $fn = function () use (&$removed) { $removed++; };
    $d11->on('r', $fn);
    $d11->remove('r', $fn);
    $d11->dispatch(new Event('r'));
    check('remove() cancels listener', $removed === 0);

    // remove() finds original callable registered via once()
    $d12 = new EventDispatcher();
    $never = 0;
    $orig = function () use (&$never) { $never++; };
    $d12->once('ro', $orig);
    $d12->remove('ro', $orig);
    $d12->dispatch(new Event('ro'));
    check('remove(original) cancels once() registration', $never === 0);

    // getListenersForEvent (PSR-14 provider) merges every matching key:
    // exact name ('m.sub'), wildcard group ('m.*'), plus the event's own
    // class/interface names. A plain 'm' registration does NOT receive
    // 'm.sub' — hierarchy subscription is explicit via the wildcard.
    $d13 = new EventDispatcher();
    $l1 = fn () => null; $l2 = fn () => null; $l3 = fn () => null; $l4 = fn () => null;
    $d13->on('m', $l1, 5);          // sibling name: must NOT match m.sub
    $d13->on('m.sub', $l2, 10);     // exact name key
    $d13->on('m.*', $l3, 1);        // wildcard group key
    $d13->on(Event::class, $l4, 7); // class-name key for generic Events
    $collected = array_values(iterator_to_array($d13->getListenersForEvent(new Event('m.sub'))));
    check('provider yields matching listeners high->low (no implicit parent)', $collected === [$l2, $l4, $l3]);

    // helpers.php: event()/event_dispatcher() global functions
    require_once __DIR__ . '/../src/helpers.php';
    event_dispatcher()->on('helper.test', function (Event $e) { $GLOBALS['helperHit'] = $e->get('v'); });
    $GLOBALS['helperHit'] = null;
    $ret = event('helper.test', ['v' => 'yes']);
    check('event() string form dispatches generic Event', $GLOBALS['helperHit'] === 'yes' && $ret instanceof Event);
    $objRet = event(new OrderShipped(9));
    check('event() object form returns same instance', $objRet instanceof OrderShipped && $objRet->id === 9);

    echo "\n$passed passed, $failed failed\n";
    exit($failed === 0 ? 0 : 1);
}
