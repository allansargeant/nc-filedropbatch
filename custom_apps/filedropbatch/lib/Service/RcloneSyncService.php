<?php

declare(strict_types=1);

namespace OCA\FileDropBatch\Service;

use OCA\FileDropBatch\AppInfo\Application;
use OCP\Authentication\Token\IProvider as ITokenProvider;
use OCP\Authentication\Token\IToken;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Syncs a user's base folder to a remote Nextcloud instance over WebDAV using
 * rclone. The source leg authenticates with a freshly-minted, short-lived
 * app token (revoked immediately after the sync) rather than a stored
 * password; the destination leg uses credentials configured once via the
 * admin settings page, encrypted at rest.
 */
class RcloneSyncService {
    private const CFG_REMOTE_URL = 'remote_url';
    private const CFG_REMOTE_USER = 'remote_user';
    private const CFG_REMOTE_PASSWORD = 'remote_password';
    private const CFG_REMOTE_BASE_PATH = 'remote_base_path';
    private const CFG_RCLONE_BINARY = 'rclone_binary';
    private const CFG_LOCAL_BASE_URL = 'local_base_url';
    private const CFG_SYNC_ENABLED = 'sync_enabled';
    private const CFG_LAST_SYNC_AT = 'last_sync_at';
    private const CFG_LAST_SYNC_STATUS = 'last_sync_status';
    private const CFG_LAST_SYNC_MESSAGE = 'last_sync_message';

    public function __construct(
        private IConfig $config,
        private ICrypto $crypto,
        private IURLGenerator $urlGenerator,
        private ISecureRandom $random,
        private ITokenProvider $tokenProvider,
        private LoggerInterface $logger,
    ) {
    }

    public function isEnabled(): bool {
        return $this->config->getAppValue(Application::APP_ID, self::CFG_SYNC_ENABLED, '0') === '1';
    }

    public function isConfigured(): bool {
        return $this->getRemoteUrl() !== '' && $this->getRemoteUser() !== '' && $this->getRemotePasswordPlain() !== '';
    }

    /** @return array<string, mixed> */
    public function getSettings(): array {
        return [
            'remoteUrl' => $this->getRemoteUrl(),
            'remoteUser' => $this->getRemoteUser(),
            'remoteBasePath' => $this->config->getAppValue(Application::APP_ID, self::CFG_REMOTE_BASE_PATH, ''),
            'rcloneBinary' => $this->getRcloneBinary(),
            'localBaseUrl' => $this->config->getAppValue(Application::APP_ID, self::CFG_LOCAL_BASE_URL, ''),
            'syncEnabled' => $this->isEnabled(),
            'hasPassword' => $this->getRemotePasswordPlain() !== '',
            'lastSyncAt' => $this->config->getAppValue(Application::APP_ID, self::CFG_LAST_SYNC_AT, ''),
            'lastSyncStatus' => $this->config->getAppValue(Application::APP_ID, self::CFG_LAST_SYNC_STATUS, ''),
            'lastSyncMessage' => $this->config->getAppValue(Application::APP_ID, self::CFG_LAST_SYNC_MESSAGE, ''),
        ];
    }

    public function saveSettings(
        string $remoteUrl,
        string $remoteUser,
        ?string $remotePassword,
        string $remoteBasePath,
        string $rcloneBinary,
        string $localBaseUrl,
        bool $syncEnabled,
    ): void {
        $appId = Application::APP_ID;
        $this->config->setAppValue($appId, self::CFG_REMOTE_URL, rtrim($remoteUrl, '/'));
        $this->config->setAppValue($appId, self::CFG_REMOTE_USER, $remoteUser);
        if ($remotePassword !== null && $remotePassword !== '') {
            $this->config->setAppValue($appId, self::CFG_REMOTE_PASSWORD, $this->crypto->encrypt($remotePassword));
        }
        $this->config->setAppValue($appId, self::CFG_REMOTE_BASE_PATH, trim($remoteBasePath, '/'));
        $this->config->setAppValue($appId, self::CFG_RCLONE_BINARY, $rcloneBinary !== '' ? $rcloneBinary : 'rclone');
        $this->config->setAppValue($appId, self::CFG_LOCAL_BASE_URL, rtrim($localBaseUrl, '/'));
        $this->config->setAppValue($appId, self::CFG_SYNC_ENABLED, $syncEnabled ? '1' : '0');
    }

    /**
     * Syncs $baseFolder from $uid's Nextcloud storage on this instance to the
     * configured remote instance.
     *
     * @throws \RuntimeException if the remote isn't configured or the sync fails
     */
    public function syncBaseFolder(string $uid, string $baseFolder): void {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Remote sync destination is not configured');
        }

        $rawToken = $this->random->generate(72, ISecureRandom::CHAR_ALPHANUMERIC);
        $token = $this->tokenProvider->generateToken(
            $rawToken,
            $uid,
            $uid,
            null,
            'File Drop Batch sync',
            IToken::TEMPORARY_TOKEN,
        );

        try {
            $localBaseUrl = $this->config->getAppValue(Application::APP_ID, self::CFG_LOCAL_BASE_URL, '');
            if ($localBaseUrl === '') {
                $localBaseUrl = $this->urlGenerator->getAbsoluteURL('/');
            }
            $srcUrl = rtrim($localBaseUrl, '/') . '/remote.php/dav/files/' . rawurlencode($uid) . '/';
            $srcRemote = $this->buildWebdavRemote($srcUrl, $uid, $this->rcloneObscure($rawToken), $baseFolder);

            $remoteBasePath = $this->config->getAppValue(Application::APP_ID, self::CFG_REMOTE_BASE_PATH, '');
            $dstUrl = rtrim($this->getRemoteUrl(), '/') . '/remote.php/dav/files/' . rawurlencode($this->getRemoteUser()) . '/';
            $dstPath = $remoteBasePath !== '' ? $remoteBasePath . '/' . $baseFolder : $baseFolder;
            $dstRemote = $this->buildWebdavRemote($dstUrl, $this->getRemoteUser(), $this->rcloneObscure($this->getRemotePasswordPlain()), $dstPath);

            $this->runRclone(['sync', $srcRemote, $dstRemote, '--create-empty-src-dirs']);

            $this->recordResult('success', '');
        } catch (\Throwable $e) {
            $this->recordResult('error', $e->getMessage());
            throw $e;
        } finally {
            $this->tokenProvider->invalidateTokenById($uid, $token->getId());
        }
    }

    private function getRemoteUrl(): string {
        return $this->config->getAppValue(Application::APP_ID, self::CFG_REMOTE_URL, '');
    }

    private function getRemoteUser(): string {
        return $this->config->getAppValue(Application::APP_ID, self::CFG_REMOTE_USER, '');
    }

    private function getRemotePasswordPlain(): string {
        $encrypted = $this->config->getAppValue(Application::APP_ID, self::CFG_REMOTE_PASSWORD, '');
        if ($encrypted === '') {
            return '';
        }
        try {
            return $this->crypto->decrypt($encrypted);
        } catch (\Throwable $e) {
            $this->logger->error('Could not decrypt stored remote sync password', ['app' => Application::APP_ID, 'exception' => $e]);
            return '';
        }
    }

    private function getRcloneBinary(): string {
        return $this->config->getAppValue(Application::APP_ID, self::CFG_RCLONE_BINARY, 'rclone');
    }

    private function recordResult(string $status, string $message): void {
        $appId = Application::APP_ID;
        $this->config->setAppValue($appId, self::CFG_LAST_SYNC_AT, (new \DateTime())->format(DATE_ATOM));
        $this->config->setAppValue($appId, self::CFG_LAST_SYNC_STATUS, $status);
        $this->config->setAppValue($appId, self::CFG_LAST_SYNC_MESSAGE, $message);
    }

    private function rcloneObscure(string $plaintext): string {
        return $this->runRclone(['obscure', $plaintext]);
    }

    private function buildWebdavRemote(string $url, string $user, string $obscuredPass, string $path): string {
        $params = ['url' => $url, 'user' => $user, 'pass' => $obscuredPass, 'vendor' => 'nextcloud'];
        $parts = [];
        foreach ($params as $key => $value) {
            $parts[] = $key . '=' . $this->quoteRcloneValue($value);
        }

        return ':webdav,' . implode(',', $parts) . ':' . ltrim($path, '/');
    }

    private function quoteRcloneValue(string $value): string {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * Runs rclone with the given arguments via proc_open using the array
     * command form - no shell is invoked, so there is no escaping/injection
     * surface regardless of what the arguments contain.
     */
    private function runRclone(array $args): string {
        $binary = $this->getRcloneBinary();
        $cmd = array_merge([$binary], $args);

        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException("Could not start '$binary' - is it installed and on PATH?");
        }

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $subcommand = $args[0] ?? '';
            $safeStderr = $this->redactSecrets(trim($stderr));
            $this->logger->error('rclone command failed', ['app' => Application::APP_ID, 'subcommand' => $subcommand, 'stderr' => $safeStderr]);
            throw new \RuntimeException("rclone $subcommand failed (exit $exitCode): " . $safeStderr);
        }

        return trim($stdout);
    }

    /**
     * rclone's own error output can echo back the full connection string it
     * failed to use, including the (obscured, but trivially reversible)
     * password - strip that before the text ever reaches a log, an
     * exception message, or the admin UI.
     */
    private function redactSecrets(string $text): string {
        return preg_replace("/pass='(?:[^']|'')*'/", "pass='***'", $text) ?? $text;
    }
}
