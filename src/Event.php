<?php

declare(strict_types=1);

namespace Kodhe\Framework\Events;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Generic payload event — the workhorse for quick integration work.
 *
 * Use it when you do not need a dedicated event class:
 *
 *     $dispatcher->dispatch(new Event('user.registered', ['id' => 7]));
 *
 * Listeners registered for 'user.registered' (or for the Event::class
 * wildcard) receive the Event instance and can read `$event->payload()`.
 * Pass a callable as the third argument to make the event stoppable:
 *
 *     $event = new Event('checkout.validate', $cart, fn () => $cart->hasErrors());
 *
 * The dispatcher stops propagating once `isPropagationStopped()` is true.
 */
class Event implements StoppableEventInterface
{
    /** @var string */
    protected string $name;

    /** @var array<string, mixed> */
    protected array $payload;

    /** @var callable|null */
    protected $stopped;

    /** @var string|null payload key whose truthiness halts propagation */
    protected ?string $stopFlag = null;

    /**
     * @param string            $name   Dot-namespaced event name, e.g. 'order.shipped'.
     * @param array             $payload Arbitrary data carried with the event.
     * @param callable|null     $stopped Optional predicate; when it returns TRUE
     *                                   propagation stops after the current listener.
     */
    public function __construct(string $name, array $payload = [], ?callable $stopped = null)
    {
        $this->name    = $name;
        $this->payload = $payload;
        $this->stopped = $stopped;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * Convenience accessor for a single payload key.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->payload[$key] ?? $default;
    }

    /**
     * Set (or unset with null) a payload key — the canonical way for a
     * listener to record results or flip a stop flag.
     */
    public function set(string $key, mixed $value): static
    {
        if ($value === null) {
            unset($this->payload[$key]);
        } else {
            $this->payload[$key] = $value;
        }

        return $this;
    }

    /**
     * Designate a payload key as the stop flag: as soon as any listener sets
     * $payload[$key] to a truthy value, propagation halts. This is the usual
     * way to build "veto" events without hand-writing a predicate closure.
     *
     *     $event = new Event('checkout.validate', ['ok' => true]);
     *     $event->attachStopFlag('ok_not');  // listener sets payload['ok_not']=true to veto
     */
    public function attachStopFlag(string $payloadKey): static
    {
        $this->stopFlag = $payloadKey;

        return $this;
    }

    /**
     * Explicitly mark this event as stopped (e.g. from inside a dedicated
     * event subclass or helper).
     */
    public function stopPropagation(): static
    {
        if ($this->stopFlag !== null) {
            $this->payload[$this->stopFlag] = true;
        } else {
            $predicate = $this->stopped;
            $this->stopped = fn () => true || ($predicate !== null && $predicate());
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * Stopped when: an explicit predicate returns TRUE, or the designated
     * stop-flag payload key has become truthy (listeners mutate payload via
     * reference-returning access below).
     */
    public function isPropagationStopped(): bool
    {
        if ($this->stopFlag !== null && !empty($this->payload[$this->stopFlag])) {
            return true;
        }

        if ($this->stopped === null) {
            return false;
        }

        return (bool) ($this->stopped)();
    }
}
