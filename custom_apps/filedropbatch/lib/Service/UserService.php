<?php

declare(strict_types=1);

namespace OCA\FileDropBatch\Service;

use OCP\IUserManager;
use OCP\Security\ISecureRandom;

class UserService {
    private const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    private const LOWER = 'abcdefghijkmnopqrstuvwxyz';
    private const DIGITS = '23456789';
    private const SYMBOLS = '!@#$%^&*-_=+';

    public function __construct(
        private IUserManager $userManager,
        private ISecureRandom $random,
    ) {
    }

    /**
     * Sanitizes a theatre name into a valid, stable Nextcloud user ID.
     */
    public function sanitizeUsername(string $theatre): string {
        $clean = strtolower(trim($theatre));
        $clean = preg_replace('/[^a-z0-9._-]+/', '-', $clean) ?? $clean;
        $clean = trim($clean, '-._');

        if ($clean === '') {
            return 'theatre';
        }

        return mb_substr($clean, 0, 64);
    }

    /**
     * Generates a high-entropy password guaranteed to contain at least one
     * upper-case, lower-case, digit, and symbol character.
     */
    public function generatePassword(): string {
        $chars = [
            $this->random->generate(1, self::UPPER),
            $this->random->generate(1, self::LOWER),
            $this->random->generate(1, self::DIGITS),
            $this->random->generate(1, self::SYMBOLS),
        ];

        $all = self::UPPER . self::LOWER . self::DIGITS . self::SYMBOLS;
        for ($i = 0; $i < 12; $i++) {
            $chars[] = $this->random->generate(1, $all);
        }

        // Fisher-Yates shuffle using a CSPRNG (str_shuffle() is not cryptographically secure).
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    /**
     * Returns the Nextcloud account for a theatre, creating it if it doesn't
     * already exist. Re-running for the same theatre name reuses the existing
     * account rather than creating a duplicate.
     *
     * @return array{username: string, password: ?string, created: bool}
     * @throws \RuntimeException if account creation fails
     */
    public function createOrGetTheatreUser(string $theatre): array {
        $username = $this->sanitizeUsername($theatre);

        if ($this->userManager->userExists($username)) {
            return ['username' => $username, 'password' => null, 'created' => false];
        }

        $password = $this->generatePassword();

        try {
            $this->userManager->createUser($username, $password);
        } catch (\Throwable $e) {
            throw new \RuntimeException("Could not create account '$username': " . $e->getMessage(), 0, $e);
        }

        return ['username' => $username, 'password' => $password, 'created' => true];
    }
}
