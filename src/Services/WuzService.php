<?php

namespace JordanMiguel\Wuz\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JordanMiguel\Wuz\Exceptions\WuzApiException;

class WuzService
{
    private PendingRequest $httpClient;

    public function __construct(
        public readonly ?string $apiUrl = null,
        public readonly ?string $userToken = null,
        public readonly ?string $adminToken = null,
    ) {
        $resolvedApiUrl = $this->apiUrl ?? config('wuz.api_url');

        $this->httpClient = Http::baseUrl($resolvedApiUrl)
            ->withHeaders(array_filter([
                'Accept' => 'application/json',
                'Authorization' => $this->adminToken ?? config('wuz.admin_token'),
                'token' => $this->userToken,
            ]));
    }

    // ── Admin methods ──────────────────────────────────────────────

    public function listUsers(): array
    {
        return $this->request('get', '/admin/users');
    }

    public function addUser(string $name, string $token, string $webhookUrl, bool $history = false): array
    {
        return $this->request('post', '/admin/users', [
            'name' => $name,
            'token' => $token,
            'events' => 'All',
            'webhook' => $webhookUrl,
            'history' => $history ? 1 : 0,
        ]);
    }

    public function showUser(string $id): array
    {
        return $this->request('get', '/admin/users/' . $id);
    }

    public function deleteUser(string $id): array
    {
        return $this->request('delete', '/admin/users/' . $id . '/full');
    }

    // ── Session methods ────────────────────────────────────────────

    public function sessionConnect(): array
    {
        return $this->request('post', '/session/connect', [
            'Immediate' => true,
        ]);
    }

    public function sessionDisconnect(): array
    {
        return $this->request('post', '/session/disconnect');
    }

    public function sessionLogout(): array
    {
        return $this->request('post', '/session/logout');
    }

    public function sessionStatus(): array
    {
        return $this->request('get', '/session/status');
    }

    public function sessionQr(): array
    {
        return $this->request('get', '/session/qr');
    }

    // ── Phone methods ──────────────────────────────────────────────

    public function phoneToJid(string $phone): array
    {
        return $this->request('get', '/user/lid/' . $phone);
    }

    public function isPhoneRegistered(array $phones): array
    {
        return $this->request('post', '/user/check/', [
            'Phone' => $phones,
        ]);
    }

    // ── Messaging methods ──────────────────────────────────────────

    public function sendMessageText(string $to, string $message, bool $linkPreview = false): array
    {
        return $this->request('post', '/chat/send/text', [
            'Phone' => $to,
            'Body' => $message,
            'Id' => uniqid() . time(),
            'LinkPreview' => $linkPreview,
        ]);
    }

    public function sendMessageImage(string $to, string $base64Image, string $caption = ''): array
    {
        return $this->request('post', '/chat/send/image', [
            'Phone' => $to,
            'Image' => $base64Image,
            'Caption' => $caption,
            'Id' => uniqid() . time(),
        ]);
    }

    public function sendMessageVideo(string $to, string $base64Video, string $caption = ''): array
    {
        return $this->request('post', '/chat/send/video', [
            'Phone' => $to,
            'Video' => $base64Video,
            'Caption' => $caption,
            'Id' => uniqid() . time(),
        ]);
    }

    public function sendMessageDocument(string $to, string $base64Document, string $filename): array
    {
        return $this->request('post', '/chat/send/document', [
            'Phone' => $to,
            'Document' => $base64Document,
            'FileName' => $filename,
            'Id' => uniqid() . time(),
        ]);
    }

    public function sendMessageButton(string $to, string $message, array $buttons): array
    {
        return $this->request('post', '/chat/send/buttons', [
            'Phone' => $to,
            'Body' => $message,
            'Buttons' => $buttons,
            'Id' => uniqid() . time(),
        ]);
    }

    public function sendChatPresence(string $to, string $state = 'composing', string $media = ''): array
    {
        return $this->request('post', '/chat/presence', [
            'Phone' => $to,
            'State' => $state,
            'Media' => $media,
        ]);
    }

    // ── Media download methods ─────────────────────────────────────

    public function downloadImage(string $url, string $directPath, string $mediaKey, string $mimetype, string $fileEncSHA256, string $fileSHA256, int $fileLength): array
    {
        return $this->downloadMedia('/chat/downloadimage', $url, $directPath, $mediaKey, $mimetype, $fileEncSHA256, $fileSHA256, $fileLength);
    }

    public function downloadDocument(string $url, string $directPath, string $mediaKey, string $mimetype, string $fileEncSHA256, string $fileSHA256, int $fileLength): array
    {
        return $this->downloadMedia('/chat/downloaddocument', $url, $directPath, $mediaKey, $mimetype, $fileEncSHA256, $fileSHA256, $fileLength);
    }

    public function downloadVideo(string $url, string $directPath, string $mediaKey, string $mimetype, string $fileEncSHA256, string $fileSHA256, int $fileLength): array
    {
        return $this->downloadMedia('/chat/downloadvideo', $url, $directPath, $mediaKey, $mimetype, $fileEncSHA256, $fileSHA256, $fileLength);
    }

    // ── Webhook methods ────────────────────────────────────────────

    public function setWebhookEvents(string $webhookUrl): array
    {
        return $this->request('put', '/webhook', [
            'webhook' => $webhookUrl,
            'events' => ['All'],
            'Active' => true,
        ]);
    }

    // ── Private helpers ────────────────────────────────────────────

    private function downloadMedia(string $endpoint, string $url, string $directPath, string $mediaKey, string $mimetype, string $fileEncSHA256, string $fileSHA256, int $fileLength): array
    {
        return $this->request('post', $endpoint, [
            'Url' => $url,
            'DirectPath' => $directPath,
            'MediaKey' => $mediaKey,
            'Mimetype' => $mimetype,
            'FileEncSHA256' => $fileEncSHA256,
            'FileSHA256' => $fileSHA256,
            'FileLength' => $fileLength,
        ]);
    }

    private function request(string $method, string $url, array $data = []): array
    {
        $response = match ($method) {
            'get' => $this->httpClient->get($url),
            'post' => $this->httpClient->post($url, $data),
            'put' => $this->httpClient->put($url, $data),
            'delete' => $this->httpClient->delete($url),
            default => throw new WuzApiException("Unsupported HTTP method: {$method}"),
        };

        if ($response->failed()) {
            Log::error("Wuz API error [{$method} {$url}]: " . $response->body());

            throw new WuzApiException(
                "Wuz API request failed: [{$method}] {$url}",
                $response->body(),
                $response->status(),
            );
        }

        return $response->json() ?? [];
    }
}
