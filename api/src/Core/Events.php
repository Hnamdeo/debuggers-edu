<?php
declare(strict_types=1);

namespace School\Core;

/**
 * In-process event bus. Modules announce what happened ("attendance.marked") and other
 * modules react, without importing each other. Later a listener can push to a queue
 * (SMS, WhatsApp) without the attendance code changing.
 */
final class Events
{
    /** @var array<string, list<callable(array, Request|null):void>> */
    private array $listeners = [];

    public function on(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    public function emit(string $event, array $payload, ?Request $req = null): void
    {
        foreach ([...($this->listeners[$event] ?? []), ...($this->listeners['*'] ?? [])] as $listener) {
            $listener(['event' => $event] + $payload, $req);
        }
    }
}
