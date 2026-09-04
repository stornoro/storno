<?php

namespace App\Controller\Api\V1;

use App\Entity\MailerConfig;
use App\Entity\User;
use App\Repository\MailerConfigRepository;
use App\Security\OrganizationContext;
use App\Service\LicenseManager;
use App\Service\OrgMailer;
use App\Service\Security\OutboundUrlPolicy;
use App\Service\Storage\CredentialEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1')]
class MailerConfigController extends AbstractController
{
    private const ENCRYPTIONS = ['none', 'tls', 'ssl'];

    public function __construct(
        private readonly OrganizationContext $organizationContext,
        private readonly MailerConfigRepository $configRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CredentialEncryptor $credentialEncryptor,
        private readonly LicenseManager $licenseManager,
        private readonly OrgMailer $orgMailer,
        private readonly OutboundUrlPolicy $outboundUrlPolicy,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/mailer-config', methods: ['GET'])]
    public function show(): JsonResponse
    {
        if (!$this->organizationContext->hasPermission('settings.view')) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'Organization not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'entitled' => $this->licenseManager->canUseWhiteLabel($org),
            'data' => $this->serialize($this->configRepository->findByOrganization($org)),
        ]);
    }

    #[Route('/mailer-config', methods: ['PUT'])]
    public function upsert(Request $request): JsonResponse
    {
        if (!$this->organizationContext->hasPermission('settings.manage')) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'Organization not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->licenseManager->canUseWhiteLabel($org)) {
            return $this->json(['error' => 'Custom email sender is available on the Business plan.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        $config = $this->configRepository->findByOrganization($org);
        $isNew = $config === null;

        $host = trim((string) ($data['host'] ?? $config?->getHost() ?? ''));
        $fromAddress = trim((string) ($data['fromAddress'] ?? $config?->getFromAddress() ?? ''));
        if ($host === '') {
            return $this->json(['error' => 'Field "host" is required.'], Response::HTTP_BAD_REQUEST);
        }
        try {
            $host = $this->outboundUrlPolicy->assertHostAllowed($host);
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'SMTP host is not allowed.'], Response::HTTP_BAD_REQUEST);
        }
        if (!filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'A valid "fromAddress" is required.'], Response::HTTP_BAD_REQUEST);
        }

        $encryption = $data['encryption'] ?? $config?->getEncryption() ?? 'tls';
        if (!in_array($encryption, self::ENCRYPTIONS, true)) {
            return $this->json(['error' => 'Invalid encryption. Use none, tls, or ssl.'], Response::HTTP_BAD_REQUEST);
        }

        $password = $data['password'] ?? null;
        if ($isNew && ($password === null || $password === '')) {
            return $this->json(['error' => 'Password is required.'], Response::HTTP_BAD_REQUEST);
        }

        if ($isNew) {
            $config = new MailerConfig();
            $config->setOrganization($org);
        }

        $config->setHost($host);
        $config->setPort((int) ($data['port'] ?? $config->getPort() ?? 587));
        $config->setEncryption($encryption);
        $config->setFromAddress($fromAddress);
        if (array_key_exists('username', $data)) {
            $config->setUsername($data['username'] !== '' ? $data['username'] : null);
        }
        if (array_key_exists('fromName', $data)) {
            $config->setFromName($data['fromName'] !== '' ? $data['fromName'] : null);
        }
        if (isset($data['enabled'])) {
            $config->setEnabled((bool) $data['enabled']);
        }
        if ($password !== null && $password !== '') {
            $config->setEncryptedCredentials($this->credentialEncryptor->encrypt(['password' => $password]));
        }

        if ($isNew) {
            $this->entityManager->persist($config);
        }
        $this->entityManager->flush();

        return $this->json(
            ['data' => $this->serialize($config)],
            $isNew ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    #[Route('/mailer-config', methods: ['DELETE'])]
    public function delete(): JsonResponse
    {
        if (!$this->organizationContext->hasPermission('settings.manage')) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'Organization not found.'], Response::HTTP_NOT_FOUND);
        }

        $config = $this->configRepository->findByOrganization($org);
        if (!$config) {
            return $this->json(['error' => 'No mailer configuration found.'], Response::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($config);
        $this->entityManager->flush();

        return $this->json(['message' => 'Mailer configuration deleted.']);
    }

    #[Route('/mailer-config/test', methods: ['POST'])]
    public function test(Request $request): JsonResponse
    {
        if (!$this->organizationContext->hasPermission('settings.manage')) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $org = $this->organizationContext->getOrganization();
        if (!$org) {
            return $this->json(['error' => 'Organization not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->licenseManager->canUseWhiteLabel($org)) {
            return $this->json(['error' => 'Custom email sender is available on the Business plan.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $existing = $this->configRepository->findByOrganization($org);

        $host = trim((string) ($data['host'] ?? $existing?->getHost() ?? ''));
        $fromAddress = trim((string) ($data['fromAddress'] ?? $existing?->getFromAddress() ?? ''));
        $encryption = $data['encryption'] ?? $existing?->getEncryption() ?? 'tls';
        $port = (int) ($data['port'] ?? $existing?->getPort() ?? 587);
        $username = array_key_exists('username', $data) ? $data['username'] : $existing?->getUsername();

        $password = $data['password'] ?? null;
        if (($password === null || $password === '') && $existing && $existing->getEncryptedCredentials() !== '') {
            $password = $this->credentialEncryptor->decrypt($existing->getEncryptedCredentials())['password'] ?? null;
        }

        if ($host === '' || !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['success' => false, 'error' => 'Host and a valid fromAddress are required.'], Response::HTTP_BAD_REQUEST);
        }

        // Any public host on any port is fine (SMTP submission ports vary), but
        // never let the test connect to loopback / private / docker-internal hosts.
        try {
            $host = $this->outboundUrlPolicy->assertHostAllowed($host);
        } catch (\InvalidArgumentException) {
            return $this->json(['success' => false, 'error' => 'SMTP host is not allowed.'], Response::HTTP_BAD_REQUEST);
        }

        // The test message may only go to the caller or to one of the
        // organization's company addresses — never to an arbitrary recipient.
        /** @var User $user */
        $user = $this->getUser();
        $allowedRecipients = array_values(array_unique(array_filter(array_map(
            static fn (?string $e) => $e ? mb_strtolower(trim($e)) : null,
            [$user->getEmail(), ...array_map(static fn ($c) => $c->getEmail(), $org->getCompanies()->toArray())],
        ))));

        $recipient = mb_strtolower(trim((string) ($data['testEmail'] ?? '')));
        if ($recipient === '') {
            $recipient = mb_strtolower((string) $user->getEmail());
        }
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || !in_array($recipient, $allowedRecipients, true)) {
            return $this->json([
                'success' => false,
                'error' => 'testEmail must be your own address or one of your company addresses.',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $transport = $this->orgMailer->buildTransport($host, $port, $encryption, $username, $password);
            $email = (new Email())
                ->from(new Address($fromAddress, $data['fromName'] ?? $existing?->getFromName() ?? ''))
                ->to($recipient)
                ->subject('Storno — test email sender')
                ->text('This is a test message confirming your custom email sender works.');
            (new SymfonyMailer($transport))->send($email);
        } catch (\Throwable $e) {
            $this->logger->info('Mailer config test failed', [
                'organizationId' => $org->getId()?->toRfc4122(),
                'host' => $host,
                'port' => $port,
                'error' => $e->getMessage(),
            ]);

            return $this->json(['success' => false, 'error' => 'Test email could not be sent. Check the SMTP host, port, encryption and credentials.']);
        }

        if ($existing) {
            $existing->setLastTestedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
        }

        return $this->json(['success' => true, 'message' => sprintf('Test email sent to %s.', $recipient)]);
    }

    private function serialize(?MailerConfig $config): ?array
    {
        if (!$config) {
            return null;
        }

        return [
            'id' => (string) $config->getId(),
            'enabled' => $config->isEnabled(),
            'host' => $config->getHost(),
            'port' => $config->getPort(),
            'encryption' => $config->getEncryption(),
            'username' => $config->getUsername(),
            'fromAddress' => $config->getFromAddress(),
            'fromName' => $config->getFromName(),
            'hasPassword' => $config->getEncryptedCredentials() !== '',
            'lastTestedAt' => $config->getLastTestedAt()?->format('c'),
        ];
    }
}
