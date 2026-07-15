<?php

declare(strict_types=1);

namespace OCA\FileDropBatch\Service;

use OCP\Constants;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IURLGenerator;
use OCP\Share\IManager;
use OCP\Share\IShare;

class ShareService {
    public function __construct(
        private IManager $shareManager,
        private IURLGenerator $urlGenerator,
    ) {
    }

    /**
     * Creates an upload-only "file drop" public link share for the given folder.
     */
    public function createFileDropShare(Folder $folder, string $ownerUid, \DateTimeInterface $expiry): IShare {
        $share = $this->shareManager->newShare();
        $share->setNode($folder);
        $share->setShareType(IShare::TYPE_LINK);
        // CREATE only, no READ = Nextcloud's "File drop (upload only)" link type.
        $share->setPermissions(Constants::PERMISSION_CREATE);
        $share->setSharedBy($ownerUid);
        $share->setShareOwner($ownerUid);
        $share->setExpirationDate(\DateTime::createFromInterface($expiry));

        return $this->shareManager->createShare($share);
    }

    public function getPublicUrl(IShare $share): string {
        return $this->urlGenerator->linkToRouteAbsolute(
            'files_sharing.sharecontroller.showShare',
            ['token' => $share->getToken()]
        );
    }

    /**
     * Shares $node with $targetUid if it isn't already, so re-running a batch
     * doesn't create duplicate shares for the same theatre user.
     */
    public function ensureUserShare(Node $node, string $ownerUid, string $targetUid, int $permissions): void {
        $existing = $this->shareManager->getSharesBy($ownerUid, IShare::TYPE_USER, $node, false, -1, 0);
        foreach ($existing as $share) {
            if ($share->getSharedWith() === $targetUid) {
                return;
            }
        }

        $share = $this->shareManager->newShare();
        $share->setNode($node);
        $share->setShareType(IShare::TYPE_USER);
        $share->setSharedWith($targetUid);
        $share->setPermissions($permissions);
        $share->setSharedBy($ownerUid);
        $share->setShareOwner($ownerUid);

        $this->shareManager->createShare($share);
    }
}
