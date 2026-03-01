<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\UserPasskey;
use App\Repository\UserPasskeyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialSource;

class WebAuthnService
{
    public function __construct(
        private readonly UserPasskeyRepository $passkeyRepository,
        private readonly EntityManagerInterface $em,
        private readonly ParameterBagInterface $params,
    ) {}

    public function getSerializer(): \Symfony\Component\Serializer\SerializerInterface
    {
        $attestationStatementSupportManager = new AttestationStatementSupportManager([
            new NoneAttestationStatementSupport(),
        ]);

        return (new WebauthnSerializerFactory($attestationStatementSupportManager))->create();
    }

    public function getRpId(Request $request): string
    {
        $host = $request->getHost();
        $parts = explode('.', $host);

        if (count($parts) > 2) {
            return implode('.', array_slice($parts, -2));
        }

        return $host;
    }

    public function getAllowedOrigins(Request $request): array
    {
        $origins = array_filter(
            array_map('trim', explode(',', $this->params->get('app.passkey_allowed_origins') ?? '')),
        );

        $androidOrigin = $this->params->get('app.android_passkey_origin');
        if ($androidOrigin) {
            $origins[] = $androidOrigin;
        }

        $origin = $request->headers->get('Origin');
        if ($origin) {
            $origins[] = $origin;
        } else {
            $scheme = $request->getScheme();
            $host = $request->getHost();
            $port = $request->getPort();

            $computed = $scheme . '://' . $host;
            if (($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80)) {
                $computed .= ':' . $port;
            }
            $origins[] = $computed;
        }

        return array_unique($origins);
    }

    /**
     * Build WebAuthn assertion options for a user's passkeys.
     * Returns [options JSON string, serialized PublicKeyCredentialRequestOptions JSON].
     */
    public function createAssertionOptions(User $user, Request $request): array
    {
        $rpId = $this->getRpId($request);
        $challenge = random_bytes(32);

        $allowCredentials = [];
        foreach ($user->getPasskeys() as $passkey) {
            $allowCredentials[] = PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                base64_decode($passkey->getCredentialId()),
            );
        }

        $requestOptions = PublicKeyCredentialRequestOptions::create(
            challenge: $challenge,
            rpId: $rpId,
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            allowCredentials: $allowCredentials,
            timeout: 60000,
        );

        $serializer = $this->getSerializer();
        $optionsJson = $serializer->serialize($requestOptions, 'json');

        return [
            'optionsJson' => $optionsJson,
        ];
    }

    /**
     * Verify a WebAuthn assertion response.
     * Returns the matched UserPasskey on success, null on failure.
     */
    public function verifyAssertion(string $storedOptionsJson, array $credentialData, Request $request): ?UserPasskey
    {
        $rpId = $this->getRpId($request);
        $serializer = $this->getSerializer();

        /** @var PublicKeyCredentialRequestOptions $requestOptions */
        $requestOptions = $serializer->deserialize($storedOptionsJson, PublicKeyCredentialRequestOptions::class, 'json');

        /** @var PublicKeyCredential $publicKeyCredential */
        $publicKeyCredential = $serializer->denormalize($credentialData, PublicKeyCredential::class, 'json');

        $response = $publicKeyCredential->response;
        if (!$response instanceof AuthenticatorAssertionResponse) {
            return null;
        }

        // Find the passkey by credential ID
        $credentialIdB64 = base64_encode($publicKeyCredential->rawId);
        $passkey = $this->passkeyRepository->findOneByCredentialId($credentialIdB64);

        if (!$passkey) {
            return null;
        }

        /** @var PublicKeyCredentialSource $publicKeyCredentialSource */
        $publicKeyCredentialSource = $serializer->deserialize(
            $passkey->getPublicKeyCredentialSource(),
            PublicKeyCredentialSource::class,
            'json'
        );

        try {
            $factory = new CeremonyStepManagerFactory();
            $factory->setAllowedOrigins($this->getAllowedOrigins($request));
            $validator = AuthenticatorAssertionResponseValidator::create($factory->requestCeremony());

            $updatedSource = $validator->check(
                $publicKeyCredentialSource,
                $response,
                $requestOptions,
                $rpId,
                $publicKeyCredentialSource->userHandle,
            );
        } catch (\Throwable) {
            return null;
        }

        // Update stored credential source (counter, etc.)
        $passkey->setPublicKeyCredentialSource($serializer->serialize($updatedSource, 'json'));
        $passkey->setLastUsedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $passkey;
    }
}
