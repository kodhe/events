<?php

declare(strict_types=1);

namespace Kodhe\Framework\Events;

use Kodhe\Framework\Support\Legacy\HookDispatcherInterface;

/**
 * Two-way adapter between the legacy CI3-style hook system and PSR-14 events.
 *
 * Hooks -> Events (recommended migration path):
 *
 *     $bridge = new HookBridge($hooks, $dispatcher);
 *     // When configured as the container 'hooks' service, every call_hook()
 *     // also fires an Event named "hook.<name>" on the dispatcher.
 *
 * Events -> Hooks (gradual adoption; let new event listeners reuse existing
 * hook definitions from config/hooks.php):
 *
 *     $dispatcher->on('post_controller', $bridge->asListener());
 *     // or broadly: $dispatcher->on('*', ...) is not supported; register
 *     // per-name instead.
 *
 * The bridge never modifies either system's behaviour; it only mirrors
 * signals, so you can migrate one hook at a time.
 */
class HookBridge implements HookDispatcherInterface
{
    public const EVENT_PREFIX = 'hook.';

    private ?EventDispatcher $dispatcher;

    /**
     * @param object $hooks Concrete legacy Hooks instance (or any object
     *                      exposing call_hook()); typed loosely to avoid a
     *                      hard dependency on kodhe/framework internals.
     */
    public function __construct(private readonly object $hooks, ?EventDispatcher $dispatcher = null)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * Attach a dispatcher so call_hook() also emits PSR-14 events.
     */
    public function setDispatcher(?EventDispatcher $dispatcher): static
    {
        $this->dispatcher = $dispatcher;

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * Runs the legacy hook, then mirrors it as Event("hook.<name>").
     */
    public function call_hook($which = ''): bool
    {
        $ran = (bool) $this->hooks->call_hook($which);

        if ($ran && $this->dispatcher !== null && $which !== '') {
            $this->dispatcher->dispatch(new Event(self::EVENT_PREFIX . $which, ['hook' => $which]));
        }

        return $ran;
    }

    /**
     * Returns a listener that forwards any dispatched Event back into the
     * legacy hook system, using the event name (stripped of the 'hook.'
     * prefix) as the hook name. Useful while old hook definitions still
     * exist but code has started dispatching PSR-14 events.
     */
    public function asListener(): callable
    {
        return function (object $event): void {
            $name = $event instanceof Event ? $event->name() : $event::class;

            if (str_starts_with($name, self::EVENT_PREFIX)) {
                $name = substr($name, strlen(self::EVENT_PREFIX));
            }

            $this->hooks->call_hook($name);
        };
    }
}
