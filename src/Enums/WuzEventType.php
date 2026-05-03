<?php

namespace JordanMiguel\Wuz\Enums;

enum WuzEventType: string
{
    case MESSAGE = 'Message';
    case UNDECRYPTABLE_MESSAGE = 'UndecryptableMessage';
    case READ_RECEIPT = 'ReadReceipt';
    case MEDIA_RETRY = 'MediaRetry';
    case GROUP_INFO = 'GroupInfo';
    case JOINED_GROUP = 'JoinedGroup';
    case PICTURE = 'Picture';
    case BLOCKLIST_CHANGE = 'BlocklistChange';
    case BLOCKLIST = 'Blocklist';
    case CONNECTED = 'Connected';
    case DISCONNECTED = 'Disconnected';
    case CONNECT_FAILURE = 'ConnectFailure';
    case KEEP_ALIVE_RESTORED = 'KeepAliveRestored';
    case KEEP_ALIVE_TIMEOUT = 'KeepAliveTimeout';
    case LOGGED_OUT = 'LoggedOut';
    case CLIENT_OUTDATED = 'ClientOutdated';
    case TEMPORARY_BAN = 'TemporaryBan';
    case STREAM_ERROR = 'StreamError';
    case PAIR_SUCCESS = 'PairSuccess';
    case PAIR_ERROR = 'PairError';
    case QR = 'QR';
    case QR_TIMEOUT = 'QRTimeout';
    case PRIVACY_SETTINGS = 'PrivacySettings';
    case USER_ABOUT = 'UserAbout';
    case HISTORY_SYNC = 'HistorySync';
    case OFFLINE_SYNC_COMPLETED = 'OfflineSyncCompleted';
    case OFFLINE_SYNC_PREVIEW = 'OfflineSyncPreview';
    case CALL_OFFER = 'CallOffer';
    case CALL_ACCEPT = 'CallAccept';
    case CALL_TERMINATE = 'CallTerminate';
    case CALL_OFFER_NOTICE = 'CallOfferNotice';
    case CALL_RELAY_LATENCY = 'CallRelayLatency';
    case PRESENCE = 'Presence';
    case CHAT_PRESENCE = 'ChatPresence';
    case IDENTITY_CHANGE = 'IdentityChange';
    case NEWSLETTER_JOIN = 'NewsletterJoin';
    case NEWSLETTER_LEAVE = 'NewsletterLeave';
    case NEWSLETTER_MUTE_CHANGE = 'NewsletterMuteChange';
    case NEWSLETTER_LIVE_UPDATE = 'NewsletterLiveUpdate';
    case FB_MESSAGE = 'FBMessage';
    case UNKNOWN = 'Unknown';

    public function label(): string
    {
        return match ($this) {
            self::MESSAGE => 'Message',
            self::UNDECRYPTABLE_MESSAGE => 'Undecryptable Message',
            self::READ_RECEIPT => 'Read Receipt',
            self::MEDIA_RETRY => 'Media Retry',
            self::GROUP_INFO => 'Group Info',
            self::JOINED_GROUP => 'Joined Group',
            self::PICTURE => 'Picture',
            self::BLOCKLIST_CHANGE => 'Blocklist Change',
            self::BLOCKLIST => 'Blocklist',
            self::CONNECTED => 'Connected',
            self::DISCONNECTED => 'Disconnected',
            self::CONNECT_FAILURE => 'Connection Failure',
            self::KEEP_ALIVE_RESTORED => 'Keep Alive Restored',
            self::KEEP_ALIVE_TIMEOUT => 'Keep Alive Timeout',
            self::LOGGED_OUT => 'Logged Out',
            self::CLIENT_OUTDATED => 'Client Outdated',
            self::TEMPORARY_BAN => 'Temporary Ban',
            self::STREAM_ERROR => 'Stream Error',
            self::PAIR_SUCCESS => 'Pair Success',
            self::PAIR_ERROR => 'Pair Error',
            self::QR => 'QR Code',
            self::QR_TIMEOUT => 'QR Timeout',
            self::PRIVACY_SETTINGS => 'Privacy Settings',
            self::USER_ABOUT => 'User About',
            self::HISTORY_SYNC => 'History Sync',
            self::OFFLINE_SYNC_COMPLETED => 'Offline Sync Completed',
            self::OFFLINE_SYNC_PREVIEW => 'Offline Sync Preview',
            self::CALL_OFFER => 'Incoming Call',
            self::CALL_ACCEPT => 'Call Accepted',
            self::CALL_TERMINATE => 'Call Terminated',
            self::CALL_OFFER_NOTICE => 'Call Notice',
            self::CALL_RELAY_LATENCY => 'Call Latency',
            self::PRESENCE => 'User Presence',
            self::CHAT_PRESENCE => 'Chat Presence',
            self::IDENTITY_CHANGE => 'Identity Change',
            self::NEWSLETTER_JOIN => 'Newsletter Joined',
            self::NEWSLETTER_LEAVE => 'Newsletter Left',
            self::NEWSLETTER_MUTE_CHANGE => 'Newsletter Mute Change',
            self::NEWSLETTER_LIVE_UPDATE => 'Newsletter Live Update',
            self::FB_MESSAGE => 'Facebook Message',
            self::UNKNOWN => 'Unknown',
        };
    }

    public static function detect(array $data): self
    {
        $type = $data['type'] ?? null;

        if (! is_string($type)) {
            return self::UNKNOWN;
        }

        return self::tryFrom($type) ?? self::UNKNOWN;
    }

    /** @return array<int, self> */
    public static function defaultLoggingTypes(): array
    {
        return [
            self::CONNECTED,
            self::DISCONNECTED,
            self::LOGGED_OUT,
            self::PAIR_SUCCESS,
            self::PAIR_ERROR,
            self::QR,
            self::QR_TIMEOUT,
            self::CONNECT_FAILURE,
            self::STREAM_ERROR,
            self::TEMPORARY_BAN,
            self::CLIENT_OUTDATED,
            self::UNKNOWN,
        ];
    }

    /** @return array<int, self> */
    public static function defaultDispatchTypes(): array
    {
        return [
            self::MESSAGE,
            self::CONNECTED,
            self::DISCONNECTED,
            self::LOGGED_OUT,
        ];
    }

    public function shouldLog(?string $rawType = null): bool
    {
        return $this->isAllowedBy(
            $this->normalizeAllowed(config('wuz.logging.event_types'), self::defaultLoggingTypes()),
            $rawType,
        );
    }

    public function shouldDispatch(?string $rawType = null): bool
    {
        return $this->isAllowedBy(
            $this->normalizeAllowed(config('wuz.webhook_event.event_types'), self::defaultDispatchTypes()),
            $rawType,
        );
    }

    /**
     * Falls back to defaults on null and lifts a scalar value into a single-entry list.
     * Tolerates consumers writing `'event_types' => '*'` or `=> WuzEventType::CONNECTED`
     * instead of wrapping in an array.
     *
     * @param  self|string|array<int, self|string>|null  $allowed
     * @param  array<int, self>  $defaults
     * @return array<int, self|string>
     */
    private function normalizeAllowed(self|string|array|null $allowed, array $defaults): array
    {
        if ($allowed === null) {
            return $defaults;
        }

        return is_array($allowed) ? $allowed : [$allowed];
    }

    /**
     * @param  array<int, self|string>  $allowed
     */
    private function isAllowedBy(array $allowed, ?string $rawType): bool
    {
        if (in_array('*', $allowed, true)) {
            return true;
        }

        foreach ($allowed as $entry) {
            if ($entry instanceof self && $entry === $this) {
                return true;
            }

            if (is_string($entry)) {
                if ($entry === $this->value) {
                    return true;
                }

                if ($rawType !== null && $entry === $rawType) {
                    return true;
                }
            }
        }

        return false;
    }
}
