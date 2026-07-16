<?php

declare(strict_types=1);

namespace OCA\FileDropBatch\Controller;

use OCA\FileDropBatch\AppInfo\Application;
use OCA\FileDropBatch\Service\GoogleAuthService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Util;

class PageController extends Controller {
    public const PREDEFINED_ROOT_FOLDERS = ['Holding slides', 'fonts', 'schedules', 'all show'];

    public function __construct(
        string $appName,
        IRequest $request,
        private IConfig $config,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private GoogleAuthService $googleAuth,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        Util::addScript(Application::APP_ID, 'filedropbatch-main');
        Util::addStyle(Application::APP_ID, 'style');

        $user = $this->userSession->getUser();
        $baseFolder = $user !== null
            ? $this->config->getUserValue($user->getUID(), Application::APP_ID, 'base_folder', 'File Drops')
            : 'File Drops';

        return new TemplateResponse(Application::APP_ID, 'main', [
            'baseFolder' => $baseFolder,
            'predefinedRootFolders' => self::PREDEFINED_ROOT_FOLDERS,
            'canCreateUsers' => $user !== null && $this->canManageTheatreUsers($user),
            'googleConnected' => $this->googleAuth->isConnected(),
        ]);
    }

    private function canManageTheatreUsers(IUser $user): bool {
        if ($this->groupManager->isAdmin($user->getUID())) {
            return true;
        }

        if (method_exists($this->groupManager, 'getSubAdmin')) {
            return $this->groupManager->getSubAdmin()->isSubAdmin($user);
        }

        return false;
    }
}
