<?php

namespace JordanMiguel\Wuz\Enums;

enum WuzEventType: string
{
    case MESSAGE = 'Message';
    case UNDECRYPTABLE_MESSAGE = 'UndecryptableMessage';
    case RECEIPT = 'Receipt';
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
    case STREAM_REPLACED = 'StreamReplaced';
    case PAIR_SUCCESS = 'PairSuccess';
    case PAIR_ERROR = 'PairError';
    case QR = 'QR';
    case QR_SCANNED_WITHOUT_MULTIDEVICE = 'QRScannedWithoutMultidevice';
    case PRIVACY_SETTINGS = 'PrivacySettings';
    case PUSH_NAME_SETTING = 'PushNameSetting';
    case USER_ABOUT = 'UserAbout';
    case APP_STATE = 'AppState';
    case APP_STATE_SYNC_COMPLETE = 'AppStateSyncComplete';
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
    case CAT_REFRESH_ERROR = 'CATRefreshError';
    case NEWSLETTER_JOIN = 'NewsletterJoin';
    case NEWSLETTER_LEAVE = 'NewsletterLeave';
    case NEWSLETTER_MUTE_CHANGE = 'NewsletterMuteChange';
    case NEWSLETTER_LIVE_UPDATE = 'NewsletterLiveUpdate';
    case FB_MESSAGE = 'FBMessage';
    case ALL = 'All';
    case UNKNOWN = 'Unknown';

    public function label(): string
    {
        return match ($this) {
            self::MESSAGE => 'Message',
            self::UNDECRYPTABLE_MESSAGE => 'Undecryptable Message',
            self::RECEIPT => 'Receipt',
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
            self::STREAM_REPLACED => 'Stream Replaced',
            self::PAIR_SUCCESS => 'Pair Success',
            self::PAIR_ERROR => 'Pair Error',
            self::QR => 'QR Code',
            self::QR_SCANNED_WITHOUT_MULTIDEVICE => 'QR Scanned (No Multi-Device)',
            self::PRIVACY_SETTINGS => 'Privacy Settings',
            self::PUSH_NAME_SETTING => 'Push Name Setting',
            self::USER_ABOUT => 'User About',
            self::APP_STATE => 'App State',
            self::APP_STATE_SYNC_COMPLETE => 'App State Sync Complete',
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
            self::CAT_REFRESH_ERROR => 'CAT Refresh Error',
            self::NEWSLETTER_JOIN => 'Newsletter Joined',
            self::NEWSLETTER_LEAVE => 'Newsletter Left',
            self::NEWSLETTER_MUTE_CHANGE => 'Newsletter Mute Change',
            self::NEWSLETTER_LIVE_UPDATE => 'Newsletter Live Update',
            self::FB_MESSAGE => 'Facebook Message',
            self::ALL => 'All Events',
            self::UNKNOWN => 'Unknown',
        };
    }

    public static function detect(array $data): self
    {
        $type = $data['type'] ?? null;

        return self::tryFrom($type) ?? self::UNKNOWN;
    }
}
