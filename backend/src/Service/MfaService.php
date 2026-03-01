<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\UserBackupCode;
use App\Entity\UserTotpSecret;
use App\Repository\UserBackupCodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use OTPHP\TOTP;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class MfaService
{
    private const CHALLENGE_TTL = 300; // 5 minutes
    private const STEPUP_CHALLENGE_TTL = 300; // 5 minutes
    private const VERIFICATION_TOKEN_TTL = 120; // 2 minutes
    private const MAX_ATTEMPTS = 5;
    private const BACKUP_CODE_COUNT = 10;
    private const APP_NAME = 'Storno';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CacheInterface $cache,
        private readonly UserBackupCodeRepository $backupCodeRepository,
    ) {}

    public function generateTotpSecret(User $user): array
    {
        $totp = TOTP::generate();
        $totp->setLabel($user->getEmail());
        $totp->setIssuer(self::APP_NAME);

        // Store unverified secret
        $existing = $user->getTotpSecret();
        if ($existing && !$existing->isVerified()) {
            $existing->setSecret($totp->getSecret());
        } else {
            $totpSecret = new UserTotpSecret();
            $totpSecret->setSecret($totp->getSecret());
            $totpSecret->setVerified(false);
            $totpSecret->setUser($user);
            $user->setTotpSecret($totpSecret);
            $this->em->persist($totpSecret);
        }

        $this->em->flush();

        $otpauthUri = $totp->getProvisioningUri();

        $result = Builder::create()
            ->writer(new PngWriter())
            ->data($otpauthUri)
            ->encoding(new Encoding('UTF-8'))
            ->errorCorrectionLevel(ErrorCorrectionLevel::High)
            ->size(300)
            ->margin(10)
            ->build();

        return [
            'secret' => $totp->getSecret(),
            'qrCode' => $result->getDataUri(),
            'otpauthUri' => $otpauthUri,
        ];
    }

    public function enableTotp(User $user, string $code): ?array
    {
        $totpSecret = $user->getTotpSecret();
        if (!$totpSecret || $totpSecret->isVerified()) {
            return null;
        }

        $totp = TOTP::createFromSecret($totpSecret->getSecret());
        $totp->setLabel($user->getEmail());
        $totp->setIssuer(self::APP_NAME);

        if (!$totp->verify($code, null, 1)) {
            return null;
        }

        $totpSecret->setVerified(true);
        $this->em->flush();

        $backupCodes = $this->generateBackupCodes($user);

        return $backupCodes;
    }

    public function disableTotp(User $user): void
    {
        $totpSecret = $user->getTotpSecret();
        if ($totpSecret) {
            $this->em->remove($totpSecret);
            $user->setTotpSecret(null);
        }

        $this->backupCodeRepository->deleteAllByUser($user);
        $this->em->flush();
    }

    public function verifyTotpCode(User $user, string $code): bool
    {
        $totpSecret = $user->getTotpSecret();
        if (!$totpSecret || !$totpSecret->isVerified()) {
            return false;
        }

        $totp = TOTP::createFromSecret($totpSecret->getSecret());
        $totp->setLabel($user->getEmail());
        $totp->setIssuer(self::APP_NAME);

        if ($totp->verify($code, null, 1)) {
            $totpSecret->setLastUsedAt(new \DateTimeImmutable());
            $this->em->flush();
            return true;
        }

        return false;
    }

    public function generateBackupCodes(User $user): array
    {
        // Delete existing codes
        $this->backupCodeRepository->deleteAllByUser($user);

        $plaintextCodes = [];

        for ($i = 0; $i < self::BACKUP_CODE_COUNT; $i++) {
            $code = $this->generateRandomCode();
            $plaintextCodes[] = $code;

            $backupCode = new UserBackupCode();
            $backupCode->setUser($user);
            $backupCode->setCodeHash(password_hash($code, PASSWORD_DEFAULT));
            $this->em->persist($backupCode);
        }

        $this->em->flush();

        return $plaintextCodes;
    }

    public function verifyBackupCode(User $user, string $code): bool
    {
        $unusedCodes = $this->backupCodeRepository->findUnusedByUser($user);

        // Normalize: remove dashes for comparison
        $normalizedInput = str_replace('-', '', strtolower(trim($code)));

        foreach ($unusedCodes as $backupCode) {
            // Try both with and without dash
            if (password_verify($normalizedInput, $backupCode->getCodeHash())
                || password_verify($this->formatCode($normalizedInput), $backupCode->getCodeHash())) {
                $backupCode->setUsed(true);
                $backupCode->setUsedAt(new \DateTimeImmutable());
                $this->em->flush();
                return true;
            }
        }

        return false;
    }

    public function createMfaChallenge(User $user): string
    {
        $token = bin2hex(random_bytes(32)); // 64-char hex
        $cacheKey = 'mfa_challenge_' . $token;

        $this->cache->delete($cacheKey);
        $this->cache->get($cacheKey, function (ItemInterface $item) use ($user) {
            $item->expiresAfter(self::CHALLENGE_TTL);
            return json_encode([
                'userId' => $user->getId()->toRfc4122(),
                'attempts' => 0,
            ]);
        });

        return $token;
    }

    public function validateMfaChallenge(string $token): ?array
    {
        $cacheKey = 'mfa_challenge_' . $token;

        $data = $this->cache->get($cacheKey, function () {
            return null;
        });

        if ($data === null) {
            return null;
        }

        $decoded = json_decode($data, true);
        if (!$decoded || !isset($decoded['userId'])) {
            return null;
        }

        // Check rate limit
        if (($decoded['attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
            $this->cache->delete($cacheKey);
            return null;
        }

        return $decoded;
    }

    public function incrementMfaAttempts(string $token): void
    {
        $cacheKey = 'mfa_challenge_' . $token;

        $data = $this->cache->get($cacheKey, function () {
            return null;
        });

        if ($data === null) {
            return;
        }

        $decoded = json_decode($data, true);
        $decoded['attempts'] = ($decoded['attempts'] ?? 0) + 1;

        // Delete and re-store with incremented attempts
        $this->cache->delete($cacheKey);
        $this->cache->get($cacheKey, function (ItemInterface $item) use ($decoded) {
            $item->expiresAfter(self::CHALLENGE_TTL);
            return json_encode($decoded);
        });
    }

    public function deleteMfaChallenge(string $token): void
    {
        $this->cache->delete('mfa_challenge_' . $token);
    }

    public function getMfaStatus(User $user): array
    {
        return [
            'totpEnabled' => $user->isMfaEnabled(),
            'backupCodesRemaining' => $this->backupCodeRepository->countUnusedByUser($user),
            'passkeysCount' => $user->getPasskeys()->count(),
        ];
    }

    // ── Step-up MFA challenge ──────────────────────────────────────────

    public function createStepUpChallenge(User $user): string
    {
        $token = bin2hex(random_bytes(32));
        $cacheKey = 'stepup_challenge_' . $token;

        $this->cache->delete($cacheKey);
        $this->cache->get($cacheKey, function (ItemInterface $item) use ($user) {
            $item->expiresAfter(self::STEPUP_CHALLENGE_TTL);
            return json_encode([
                'userId' => $user->getId()->toRfc4122(),
                'attempts' => 0,
            ]);
        });

        return $token;
    }

    public function validateStepUpChallenge(string $token): ?array
    {
        $cacheKey = 'stepup_challenge_' . $token;

        $data = $this->cache->get($cacheKey, function () {
            return null;
        });

        if ($data === null) {
            return null;
        }

        $decoded = json_decode($data, true);
        if (!$decoded || !isset($decoded['userId'])) {
            return null;
        }

        if (($decoded['attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
            $this->cache->delete($cacheKey);
            return null;
        }

        return $decoded;
    }

    public function incrementStepUpAttempts(string $token): void
    {
        $cacheKey = 'stepup_challenge_' . $token;

        $data = $this->cache->get($cacheKey, function () {
            return null;
        });

        if ($data === null) {
            return;
        }

        $decoded = json_decode($data, true);
        $decoded['attempts'] = ($decoded['attempts'] ?? 0) + 1;

        $this->cache->delete($cacheKey);
        $this->cache->get($cacheKey, function (ItemInterface $item) use ($decoded) {
            $item->expiresAfter(self::STEPUP_CHALLENGE_TTL);
            return json_encode($decoded);
        });
    }

    public function deleteStepUpChallenge(string $token): void
    {
        $this->cache->delete('stepup_challenge_' . $token);
    }

    // ── Verification tokens (single-use, short-lived) ───────────────

    public function createVerificationToken(User $user): string
    {
        $token = bin2hex(random_bytes(32));
        $cacheKey = 'mfa_verified_' . $token;

        $this->cache->delete($cacheKey);
        $this->cache->get($cacheKey, function (ItemInterface $item) use ($user) {
            $item->expiresAfter(self::VERIFICATION_TOKEN_TTL);
            return json_encode([
                'userId' => $user->getId()->toRfc4122(),
            ]);
        });

        return $token;
    }

    public function validateVerificationToken(string $token, User $user): bool
    {
        $cacheKey = 'mfa_verified_' . $token;

        $data = $this->cache->get($cacheKey, function () {
            return null;
        });

        if ($data === null) {
            return false;
        }

        $decoded = json_decode($data, true);
        if (!$decoded || ($decoded['userId'] ?? null) !== $user->getId()->toRfc4122()) {
            return false;
        }

        // Single-use: delete after validation
        $this->cache->delete($cacheKey);
        return true;
    }

    private function generateRandomCode(): string
    {
        // Generate 8-char alphanumeric code formatted as a1b2-c3d4
        $chars = 'abcdefghjkmnpqrstuvwxyz23456789'; // no ambiguous chars
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $this->formatCode($code);
    }

    private function formatCode(string $code): string
    {
        $clean = str_replace('-', '', $code);
        if (strlen($clean) === 8) {
            return substr($clean, 0, 4) . '-' . substr($clean, 4, 4);
        }
        return $clean;
    }
}
