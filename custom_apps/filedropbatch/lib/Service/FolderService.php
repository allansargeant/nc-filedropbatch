<?php

declare(strict_types=1);

namespace OCA\FileDropBatch\Service;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;

class FolderService {
    public function __construct(
        private IRootFolder $rootFolder,
    ) {
    }

    /**
     * Walks the given path segments under the user's home folder, creating any
     * that don't already exist, and returns the resulting (innermost) folder.
     *
     * @param string[] $segments
     */
    public function getOrCreatePath(string $userId, array $segments): Folder {
        $current = $this->rootFolder->getUserFolder($userId);

        foreach ($segments as $segment) {
            $current = $this->getOrCreateChild($current, $segment);
        }

        return $current;
    }

    /** Resolves a node from the full path stored on a Session (as returned by Node::getPath()). */
    public function getNodeByPath(string $fullPath): Node {
        return $this->rootFolder->get($fullPath);
    }

    private function getOrCreateChild(Folder $parent, string $name): Folder {
        try {
            $node = $parent->get($name);
        } catch (NotFoundException) {
            return $parent->newFolder($name);
        }

        if (!$node instanceof Folder) {
            throw new \RuntimeException("'$name' already exists in '{$parent->getPath()}' and is not a folder");
        }

        return $node;
    }

    /**
     * Creates the leaf folder for a row under $parent, auto-suffixing
     * " (2)", " (3)", ... on name collisions rather than failing.
     */
    public function createUniqueLeaf(Folder $parent, string $desiredName): Folder {
        $name = $desiredName;

        for ($i = 2; $i <= 50; $i++) {
            if (!$parent->nodeExists($name)) {
                try {
                    return $parent->newFolder($name);
                } catch (NotPermittedException $e) {
                    if (!$parent->nodeExists($name)) {
                        throw $e;
                    }
                    // Lost a race with another request creating the same name; fall through and retry.
                }
            }

            $name = "$desiredName ($i)";
        }

        throw new \RuntimeException("Too many name collisions for '$desiredName'");
    }

    /**
     * Moves $node to be a child of $newParent named $desiredName, auto-suffixing
     * " (2)", " (3)", ... on collisions - mirrors createUniqueLeaf() but for an
     * existing node being relocated (e.g. editing a session) rather than a new
     * folder. A no-op if $node is already exactly there.
     */
    public function moveToUniqueLeaf(Node $node, Folder $newParent, string $desiredName): Node {
        $name = $desiredName;

        for ($i = 2; $i <= 50; $i++) {
            $targetPath = rtrim($newParent->getPath(), '/') . '/' . $name;

            if ($node->getPath() === $targetPath) {
                return $node;
            }

            if (!$newParent->nodeExists($name)) {
                try {
                    $node->move($targetPath);
                    return $node;
                } catch (NotPermittedException $e) {
                    if (!$newParent->nodeExists($name)) {
                        throw $e;
                    }
                    // Lost a race with another request creating the same name; fall through and retry.
                }
            }

            $name = "$desiredName ($i)";
        }

        throw new \RuntimeException("Too many name collisions for '$desiredName'");
    }
}
