<?php

namespace JordanMiguel\Wuz\Enums;

/**
 * Strings WUZ accepts in its per-user `events` subscription column.
 *
 * Distinct from {@see WuzEventType}, which represents the types WUZ emits in
 * webhook payloads. `All` is a subscription sentinel meaning "all events";
 * it is never emitted.
 */
enum WuzEventSubscription: string
{
    case ALL = 'All';
    case MESSAGE = 'Message';
    case READ_RECEIPT = 'ReadReceipt';
    case PRESENCE = 'Presence';
    case HISTORY_SYNC = 'HistorySync';
    case CHAT_PRESENCE = 'ChatPresence';
}
