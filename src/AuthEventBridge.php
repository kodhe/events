<?php

declare(strict_types=1);

namespace Kodhe\Framework\Events;

use Kodhe\Framework\Auth\Contracts\AuthEventDispatcherInterface;

/**
 * Adapts the PSR-14 EventDispatcher to kodhe/auth's lightweight
 * AuthEventDispatcherInterface contract.
 *
 *     $auth->setEventDispatcher(new AuthEventBridge($dispatcher));
 *
 * AuthEvents constants already carry the 'auth.' prefix (e.g.
 * AuthEvents::FAILED === 'auth.failed'), so emissions are forwarded to the
 * dispatcher under their original names — listeners registered directly on
 * the constant keep working:
 *
 *     $dispatcher->on(AuthEvents::FAILED, fn (Event $e) => throttle($e->get('identity')));
 *     $dispatcher->on('auth.*', fn (Event $e) => audit($e->name(), $e->payload()));
 */
class AuthEventBridge implements AuthEventDispatcherInterface
{
    public function __construct(private readonly EventDispatcher $dispatcher)
    {
    }

    /**
     * @param string $event One of the Kodhe\Framework\Auth\AuthEvents constants.
     * @param array  $payload Event-specific data (see AuthEvents docblock).
     */
    public function dispatch(string $event, array $payload = []): void
    {
        // AuthEvents constants are already namespaced ('auth.xxx'); pass the
        // name through unchanged so subscriptions match the documented values.
        $this->dispatcher->dispatch(new Event($event, $payload));
    }
}
