<?php

declare(strict_types=1);

namespace OCA\FileDropBatch\Controller;

use OCA\FileDropBatch\AppInfo\Application;
use OCA\FileDropBatch\Service\GoogleAuthService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;

/**
 * No #[NoAdminRequired] attribute anywhere in this controller - connecting
 * or disconnecting the single, instance-wide Google account is an
 * admin-settings concern, same gating as AdminSettingsController.
 */
class GoogleAuthController extends Controller {
    private const SESSION_STATE_KEY = 'filedropbatch_google_oauth_state';

    public function __construct(
        string $appName,
        IRequest $request,
        private GoogleAuthService $googleAuth,
        private ISession $session,
        private ISecureRandom $random,
        private IURLGenerator $urlGenerator,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoCSRFRequired]
    public function connect(): RedirectResponse {
        if (!$this->googleAuth->isConfigured()) {
            return $this->redirectToAdminSettings('google_error', 'Save a Client ID and Client Secret first');
        }

        $state = $this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);
        $this->session->set(self::SESSION_STATE_KEY, $state);

        return new RedirectResponse($this->googleAuth->buildAuthUrl($state));
    }

    #[NoCSRFRequired]
    public function callback(?string $code = null, ?string $state = null, ?string $error = null): RedirectResponse {
        if ($error !== null) {
            return $this->redirectToAdminSettings('google_error', $error);
        }

        $expectedState = $this->session->get(self::SESSION_STATE_KEY);
        $this->session->remove(self::SESSION_STATE_KEY);

        if ($code === null || $state === null || $expectedState === null || !hash_equals((string)$expectedState, $state)) {
            return $this->redirectToAdminSettings('google_error', 'Invalid or expired OAuth state - please try connecting again');
        }

        try {
            $this->googleAuth->exchangeCode($code);
        } catch (\Throwable $e) {
            return $this->redirectToAdminSettings('google_error', $e->getMessage());
        }

        return $this->redirectToAdminSettings('google_connected', '1');
    }

    public function disconnect(): RedirectResponse {
        $this->googleAuth->disconnect();

        return $this->redirectToAdminSettings('google_disconnected', '1');
    }

    private function redirectToAdminSettings(string $flagKey, string $flagValue): RedirectResponse {
        $url = $this->urlGenerator->linkToRoute('settings.AdminSettings.index', ['section' => Application::APP_ID])
            . '?' . http_build_query([$flagKey => $flagValue]);

        return new RedirectResponse($url);
    }
}
