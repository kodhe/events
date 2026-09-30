<?php

declare(strict_types=1);

use Kodhe\Framework\Events\Event;
use Kodhe\Framework\Events\EventDispatcher;

if (!function_exists('event_dispatcher')) {
    /**
     * Resolve the application-wide EventDispatcher singleton.
     *
     * Prefers the container service 'events' when a callable container
     * accessor (`app()`) exists (kodhe/framework apps); otherwise lazily
     * creates and reuses a process-wide default instance so standalone
     * packages can dispatch without bootstrapping the full framework.
     */
    function event_dispatcher(): EventDispatcher
    {
        static $default = null;

        if (function_exists('app')) {
            $fromContainer = app('events');
            if ($fromContainer instanceof EventDispatcher) {
                return $fromContainer;
            }
        }

        return $default ??= new EventDispatcher();
    }
}

if (!function_exists('event')) {
    /**
     * Fire an application event.
     *
     *   event('user.registered', ['id' => $id]);   // generic payload event
     *   event(new OrderShipped($order));           // dedicated event object
     *
     * @return object The (possibly mutated by listeners) event instance.
     */
    function event(object|string $event, array $payload = []): object
    {
        if (is_string($event)) {
            $event = new Event($event, $payload);
        }

        return event_dispatcher()->dispatch($event);
    }
}
