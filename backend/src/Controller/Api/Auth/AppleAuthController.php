<?php

namespace App\Controller\Api\Auth;

use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use App\Entity\User;
use App\Entity\UserBilling;
use App\Enum\OrganizationRole;
use App\Service\LicenseValidationService;
use App\Service\MfaService;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

class AppleAuthController extends AbstractController
{
    private const APPLE_JWKS_URL = 'https://appleid.apple.com/auth/keys';
    private const APPLE_ISSUER = 'https://appleid.apple.com';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly RefreshTokenGeneratorInterface $refreshTokenGenerator,
        private readonly RefreshTokenManagerInterface $refreshTokenManager,
        private readonly SluggerInterface $slugger,
        private readonly LicenseValidationService $licenseValidationService,
        private readonly MfaService $mfaService,
    ) {}

    #[Route('/api/auth/apple', name: 'api_auth_apple', methods: ['POST'])]
    public function __invoke(Request $request, RateLimiterFactory $oauthLoginLimiter): JsonResponse
    {
        $limiter = $oauthLoginLimiter->create($request->getClientIp());
        if (!$limiter->consume()->isAccepted()) {
            return $this->json(['error' => 'Too many requests.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $data = json_decode($request->getContent(), true);
        $identityToken = $data['identityToken'] ?? null;
        $fullName = $data['fullName'] ?? null;
        $providedEmail = $data['email'] ?? null;

        if (!$identityToken) {
            return $this->json(['error' => 'Missing identityToken.'], Response::HTTP_BAD_REQUEST);
        }

        // Verify the Apple identity token
        try {
            $payload = $this->verifyAppleToken($identityToken);
        } catch (\Exception) {
            return $this->json(['error' => 'Invalid Apple token.'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$payload) {
            return $this->json(['error' => 'Invalid Apple token.'], Response::HTTP_UNAUTHORIZED);
        }

        $appleId = $payload['sub'];
        $email = $payload['email'] ?? $providedEmail;
        $emailVerified = ($payload['email_verified'] ?? 'false') === 'true';

        if (!$email) {
            return $this->json(['error' => 'Email not available.'], Response::HTTP_BAD_REQUEST);
        }

        // Parse name from fullName (Apple only sends name on first sign-in)
        $firstName = null;
        $lastName = null;
        if ($fullName) {
            $parts = explode(' ', $fullName, 2);
            $firstName = $parts[0] ?? null;
            $lastName = $parts[1] ?? null;
        }

        // 1. Try find by appleId
        $user = $this->em->getRepository(User::class)->findOneBy(['appleId' => $appleId]);

        if (!$user) {
            // 2. Try find by email
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);

            if ($user) {
                // Link Apple account to existing user
                $user->setAppleId($appleId);
                if ($emailVerified) {
                    $user->setEmailVerified(true);
                }
            } else {
                // Self-hosted: block creating additional organizations
                if ($this->licenseValidationService->isSelfHosted()) {
                    $existingOrgCount = (int) $this->em->getConnection()
                        ->fetchOne('SELECT COUNT(*) FROM organization');
                    if ($existingOrgCount > 0) {
                        return $this->json([
                            'error' => 'Self-hosted instances support a single organization. Ask the admin to invite you.',
                        ], Response::HTTP_FORBIDDEN);
                    }
                }

                // 3. Create new user
                $user = new User();
                $user->setEmail($email);
                $user->setFirstName($firstName);
                $user->setLastName($lastName);
                $user->setEmailVerified($emailVerified);
                $user->setAppleId($appleId);
                $user->setActive(true);
                $user->setRoles(['ROLE_USER']);
                $user->setUserBilling(
                    (new UserBilling())
                        ->setFirstName($firstName)
                        ->setLastName($lastName)
                );

                // Create default organization
                $orgName = trim(($firstName ?? '') . ' ' . ($lastName ?? '')) ?: $email;
                $organization = new Organization();
                $organization->setName($orgName . "'s Organization");
                $organization->setSlug(
                    $this->slugger->slug($orgName)->lower()->toString() . '-' . substr(md5(uniqid()), 0, 6)
                );

                $membership = new OrganizationMembership();
                $membership->setUser($user);
                $membership->setOrganization($organization);
                $membership->setRole(OrganizationRole::OWNER);
                $membership->setIsActive(true);

                $this->em->persist($organization);
                $this->em->persist($membership);
                $this->em->persist($user);
            }
        }

        $user->setLastConnectedAt(new \DateTimeImmutable());
        $this->em->flush();

        // Check if MFA is required
        if ($user->requiresMfa()) {
            $mfaToken = $this->mfaService->createMfaChallenge($user);
            $methods = $user->getAvailableMfaMethods();
            $status = $this->mfaService->getMfaStatus($user);
            if ($status['backupCodesRemaining'] > 0) {
                $methods[] = 'backup_code';
            }
            $methods[] = 'email_otp';

            return $this->json([
                'mfa_required' => true,
                'mfa_token' => $mfaToken,
                'mfa_methods' => $methods,
            ]);
        }

        // Generate JWT + refresh token
        $jwt = $this->jwtManager->create($user);

        $refreshToken = $this->refreshTokenGenerator->createForUserWithTtl(
            $user,
            (new \DateTime())->modify('+30 days')->getTimestamp()
        );
        $this->refreshTokenManager->save($refreshToken);

        return $this->json([
            'token' => $jwt,
            'refresh_token' => $refreshToken->getRefreshToken(),
        ]);
    }

    /**
     * Verify Apple identity token using Apple's public keys (JWKS).
     */
    private function verifyAppleToken(string $identityToken): ?array
    {
        // Decode header to get kid
        $parts = explode('.', $identityToken);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Invalid JWT format.');
        }

        $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
        $kid = $header['kid'] ?? null;
        $alg = $header['alg'] ?? null;

        if (!$kid || !$alg) {
            throw new \RuntimeException('Missing kid or alg in token header.');
        }

        // Fetch Apple's public keys
        $jwksJson = file_get_contents(self::APPLE_JWKS_URL);
        $jwks = json_decode($jwksJson, true);

        // Find matching key
        $matchingKey = null;
        foreach ($jwks['keys'] as $key) {
            if ($key['kid'] === $kid) {
                $matchingKey = $key;
                break;
            }
        }

        if (!$matchingKey) {
            throw new \RuntimeException('No matching Apple public key found.');
        }

        // Convert JWK to PEM
        $pem = $this->jwkToPem($matchingKey);

        // Verify and decode
        $payload = base64_decode(strtr($parts[1], '-_', '+/'));
        $signature = base64_decode(strtr($parts[2], '-_', '+/'));
        $signatureInput = $parts[0] . '.' . $parts[1];

        $publicKey = openssl_pkey_get_public($pem);
        $algMap = ['RS256' => OPENSSL_ALGO_SHA256, 'ES256' => OPENSSL_ALGO_SHA256];
        $opensslAlg = $algMap[$alg] ?? OPENSSL_ALGO_SHA256;

        // For ES256, convert DER signature to raw format
        if ($alg === 'ES256') {
            $verified = openssl_verify($signatureInput, $this->derToRaw($signature), $publicKey, $opensslAlg);
        } else {
            $verified = openssl_verify($signatureInput, $signature, $publicKey, $opensslAlg);
        }

        if ($verified !== 1) {
            throw new \RuntimeException('Token signature verification failed.');
        }

        $claims = json_decode($payload, true);

        // Validate issuer and expiration
        if (($claims['iss'] ?? '') !== self::APPLE_ISSUER) {
            throw new \RuntimeException('Invalid issuer.');
        }

        if (($claims['exp'] ?? 0) < time()) {
            throw new \RuntimeException('Token expired.');
        }

        return $claims;
    }

    /**
     * Convert a JWK RSA/EC key to PEM format.
     */
    private function jwkToPem(array $jwk): string
    {
        if (($jwk['kty'] ?? '') === 'RSA') {
            $n = $this->base64UrlDecode($jwk['n']);
            $e = $this->base64UrlDecode($jwk['e']);

            $modulus = "\x00" . $n;
            $publicKey = $this->asn1Sequence(
                $this->asn1Sequence(
                    $this->asn1Oid("\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01") . "\x05\x00"
                ) .
                $this->asn1BitString(
                    $this->asn1Sequence(
                        $this->asn1Integer($modulus) . $this->asn1Integer($e)
                    )
                )
            );

            return "-----BEGIN PUBLIC KEY-----\n" .
                chunk_split(base64_encode($publicKey), 64, "\n") .
                "-----END PUBLIC KEY-----\n";
        }

        if (($jwk['kty'] ?? '') === 'EC') {
            $x = $this->base64UrlDecode($jwk['x']);
            $y = $this->base64UrlDecode($jwk['y']);

            // P-256 curve OID
            $ecPoint = "\x04" . str_pad($x, 32, "\x00", STR_PAD_LEFT) . str_pad($y, 32, "\x00", STR_PAD_LEFT);

            $publicKey = $this->asn1Sequence(
                $this->asn1Sequence(
                    $this->asn1Oid("\x2a\x86\x48\xce\x3d\x02\x01") .
                    $this->asn1Oid("\x2a\x86\x48\xce\x3d\x03\x01\x07")
                ) .
                $this->asn1BitString($ecPoint)
            );

            return "-----BEGIN PUBLIC KEY-----\n" .
                chunk_split(base64_encode($publicKey), 64, "\n") .
                "-----END PUBLIC KEY-----\n";
        }

        throw new \RuntimeException('Unsupported key type: ' . ($jwk['kty'] ?? 'unknown'));
    }

    private function derToRaw(string $der): string
    {
        // ES256 signature: DER encoded, need raw r||s
        $pos = 0;
        if (ord($der[$pos++]) !== 0x30) return $der;
        $pos++; // skip length
        if (ord($der[$pos++]) !== 0x02) return $der;
        $rLen = ord($der[$pos++]);
        $r = substr($der, $pos, $rLen);
        $pos += $rLen;
        if (ord($der[$pos++]) !== 0x02) return $der;
        $sLen = ord($der[$pos++]);
        $s = substr($der, $pos, $sLen);

        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");

        return str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
    }

    private function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    private function asn1Sequence(string $data): string
    {
        return "\x30" . $this->asn1Length(strlen($data)) . $data;
    }

    private function asn1Integer(string $data): string
    {
        return "\x02" . $this->asn1Length(strlen($data)) . $data;
    }

    private function asn1BitString(string $data): string
    {
        return "\x03" . $this->asn1Length(strlen($data) + 1) . "\x00" . $data;
    }

    private function asn1Oid(string $oid): string
    {
        return "\x06" . $this->asn1Length(strlen($oid)) . $oid;
    }

    private function asn1Length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = '';
        $temp = $length;
        while ($temp > 0) {
            $bytes = chr($temp & 0xff) . $bytes;
            $temp >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }
}
