<?php

namespace JordanMiguel\Wuz\Data;

use Carbon\CarbonImmutable;
use JordanMiguel\Wuz\Models\WuzCallbackLog;
use Spatie\LaravelData\Data;

class CallbackLogData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $event_type,
        public readonly array $payload,
        public readonly ?string $ip_address,
        public readonly ?string $user_agent,
        public readonly ?CarbonImmutable $created_at,
    ) {}

    public static function fromModel(WuzCallbackLog $log): self
    {
        return new self(
            id: $log->id,
            event_type: $log->event_type,
            payload: $log->payload,
            ip_address: $log->ip_address,
            user_agent: $log->user_agent,
            created_at: $log->created_at ? CarbonImmutable::parse($log->created_at) : null,
        );
    }
}
