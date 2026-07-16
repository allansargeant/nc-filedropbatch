<?php

declare(strict_types=1);

namespace OCA\FileDropBatch\Service;

use OCA\FileDropBatch\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Manages a single, instance-wide Google OAuth connection used to read
 * linked Google Sheets. Client ID/Secret and both tokens are stored via
 * IConfig app values, encrypted at rest through ICrypto - the same pattern
 * RcloneSyncService uses for the remote sync password.
 */
class GoogleAuthService {
    private const CFG_CLIENT_ID = 'google_client_id';
    private const CFG_CLIENT_SECRET = 'google_client_secret';
    private const CFG_ACCESS_TOKEN = 'google_access_token';
    private const CFG_REFRESH_TOKEN = 'google_refresh_token';
    private const CFG_TOKEN_EXPIRES_AT = 'google_token_expires_at';
    private const CFG_ACCOUNT_EMAIL = 'google_account_email';

    private const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const SCOPE = 'https://www.googleapis.com/auth/spreadsheets.readonly';

    public function __construct(
        private IConfig $config,
        private ICrypto $crypto,
        private IURLGenerator $urlGenerator,
        private IClientService $clientService,
        private LoggerInterface $logger,
    ) {
    }

    public function isConfigured(): bool {
        return $this->getClientId() !== '' && $this->getClientSecretPlain() !== '';
    }

    public function isConnected(): bool {
        return $this->isConfigured() && $this->getRefreshTokenPlain() !== '';
    }

    /** @return array<string, mixed> */
    public function getSettings(): array {
        return [
            'clientId' => $this->getClientId(),
            'hasClientSecret' => $this->getClientSecretPlain() !== '',
            'connected' => $this->isConnected(),
            'accountEmail' => $this->config->getAppValue(Application::APP_ID, self::CFG_ACCOUNT_EMAIL, ''),
            'redirectUri' => $this->getRedirectUri(),
            'connectUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.google_auth.connect'),
        ];
    }

    public function saveClientCredentials(string $clientId, ?string $clientSecret): void {
        $appId = Application::APP_ID;
        $this->config->setAppValue($appId, self::CFG_CLIENT_ID, trim($clientId));
        if ($clientSecret !== null && $clientSecret !== '') {
            $this->config->setAppValue($appId, self::CFG_CLIENT_SECRET, $this->crypto->encrypt(trim($clientSecret)));
        }
    }

    public function getRedirectUri(): string {
        return $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->linkToRoute(Application::APP_ID . '.google_auth.callback'),
        );
    }

    public function buildAuthUrl(string $state): string {
        $params = [
            'client_id' => $this->getClientId(),
            'redirect_uri' => $this->getRedirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ];

        return self::AUTH_ENDPOINT . '?' . http_build_query($params);
    }

    /** @throws \RuntimeException on any failure exchanging the code */
    public function exchangeCode(string $code): void {
        $response = $this->postToken([
            'code' => $code,
            'client_id' => $this->getClientId(),
            'client_secret' => $this->getClientSecretPlain(),
            'redirect_uri' => $this->getRedirectUri(),
            'grant_type' => 'authorization_code',
        ]);

        if (!isset($response['refresh_token'])) {
            throw new \RuntimeException(
                'Google did not return a refresh token - disconnect and reconnect ' .
                'to force the consent screen to grant offline access again.',
            );
        }

        $this->storeTokenResponse($response);
        $this->fetchAndStoreAccountEmail((string)$response['access_token']);
    }

    /** @throws \RuntimeException if not connected or the refresh fails */
    public function getValidAccessToken(): string {
        if (!$this->isConnected()) {
            throw new \RuntimeException('Google account is not connected');
        }

        $expiresAt = (int)$this->config->getAppValue(Application::APP_ID, self::CFG_TOKEN_EXPIRES_AT, '0');
        $accessToken = $this->getAccessTokenPlain();
        if ($accessToken !== '' && $expiresAt > time() + 60) {
            return $accessToken;
        }

        $response = $this->postToken([
            'refresh_token' => $this->getRefreshTokenPlain(),
            'client_id' => $this->getClientId(),
            'client_secret' => $this->getClientSecretPlain(),
            'grant_type' => 'refresh_token',
        ]);

        $this->storeTokenResponse($response);

        return (string)$response['access_token'];
    }

    public function disconnect(): void {
        $appId = Application::APP_ID;
        $this->config->deleteAppValue($appId, self::CFG_ACCESS_TOKEN);
        $this->config->deleteAppValue($appId, self::CFG_REFRESH_TOKEN);
        $this->config->deleteAppValue($appId, self::CFG_TOKEN_EXPIRES_AT);
        $this->config->deleteAppValue($appId, self::CFG_ACCOUNT_EMAIL);
    }

    private function getClientId(): string {
        return $this->config->getAppValue(Application::APP_ID, self::CFG_CLIENT_ID, '');
    }

    private function getClientSecretPlain(): string {
        return $this->decryptOrEmpty(self::CFG_CLIENT_SECRET, 'client secret');
    }

    private function getAccessTokenPlain(): string {
        return $this->decryptOrEmpty(self::CFG_ACCESS_TOKEN, 'access token');
    }

    private function getRefreshTokenPlain(): string {
        return $this->decryptOrEmpty(self::CFG_REFRESH_TOKEN, 'refresh token');
    }

    private function decryptOrEmpty(string $key, string $label): string {
        $encrypted = $this->config->getAppValue(Application::APP_ID, $key, '');
        if ($encrypted === '') {
            return '';
        }
        try {
            return $this->crypto->decrypt($encrypted);
        } catch (\Throwable $e) {
            $this->logger->error("Could not decrypt stored Google $label", ['app' => Application::APP_ID, 'exception' => $e]);
            return '';
        }
    }

    /** @param array<string, string> $response */
    private function storeTokenResponse(array $response): void {
        $appId = Application::APP_ID;
        $this->config->setAppValue($appId, self::CFG_ACCESS_TOKEN, $this->crypto->encrypt((string)$response['access_token']));
        if (isset($response['refresh_token'])) {
            $this->config->setAppValue($appId, self::CFG_REFRESH_TOKEN, $this->crypto->encrypt((string)$response['refresh_token']));
        }
        $expiresIn = (int)($response['expires_in'] ?? 3600);
        $this->config->setAppValue($appId, self::CFG_TOKEN_EXPIRES_AT, (string)(time() + $expiresIn));
    }

    private function fetchAndStoreAccountEmail(string $accessToken): void {
        try {
            $client = $this->clientService->newClient();
            $response = $client->get('https://www.googleapis.com/oauth2/v2/userinfo', [
                'headers' => ['Authorization' => 'Bearer ' . $accessToken],
                'http_errors' => false,
            ]);
            $body = json_decode((string)$response->getBody(), true);
            if (is_array($body) && isset($body['email'])) {
                $this->config->setAppValue(Application::APP_ID, self::CFG_ACCOUNT_EMAIL, (string)$body['email']);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Could not fetch connected Google account email', ['app' => Application::APP_ID, 'exception' => $e]);
        }
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>
     * @throws \RuntimeException on a non-2xx response or an unreadable body
     */
    private function postToken(array $params): array {
        $client = $this->clientService->newClient();
        $response = $client->post(self::TOKEN_ENDPOINT, [
            'body' => $params,
            'http_errors' => false,
        ]);

        $body = json_decode((string)$response->getBody(), true);
        if ($response->getStatusCode() >= 300 || !is_array($body) || !isset($body['access_token'])) {
            $message = is_array($body) && isset($body['error_description'])
                ? (string)$body['error_description']
                : 'Google token request failed (HTTP ' . $response->getStatusCode() . ')';
            throw new \RuntimeException($message);
        }

        return $body;
    }
}
