<?php

declare(strict_types=1);

namespace Tracing\Sdk\Event;

trait EventEmitterTrait
{
    /** @var array<string, callable[]> */
    private $listeners = [];

    public function on(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    /**
     * @param mixed $payload
     */
    protected function emit(string $event, $payload = null): void
    {
        if (empty($this->listeners[$event])) {
            return;
        }

        foreach ($this->listeners[$event] as $listener) {
            $listener($payload);
        }
    }
}
