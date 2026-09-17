<?php

namespace App\Controller\Api\V1;

use App\Service\InvoicingActivityService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints for platforms that integrate with Storno.
 *
 * Not customer endpoints: the caller asks about a third party's fiscal code,
 * so it authenticates with a shared integration key rather than a user token,
 * and it only ever gets back the minimum needed to decide something on its
 * own side.
 *
 * Note the word: "partners" elsewhere in this API means a company's clients and
 * suppliers. These are integration partners — other platforms — which is a
 * different thing entirely.
 */
#[Route('/api/v1/integrations')]
class IntegrationController extends AbstractController
{
    public function __construct(
        private readonly InvoicingActivityService $invoicingActivity,
        private readonly LoggerInterface $apiLogger,
        #[Autowire('%env(default::INTEGRATION_API_KEY)%')]
        private readonly ?string $integrationApiKey = null,
    ) {}

    /**
     * Does this fiscal code invoice through Storno, and how much lately?
     *
     * Used by platforms that reward their own users for invoicing here, for
     * instance with a discount. The answer is a boolean and a count; nothing
     * identifying, nothing financial.
     */
    #[Route('/invoicing-activity', name: 'integration_invoicing_activity', methods: ['GET'])]
    public function invoicingActivity(Request $request, RateLimiterFactory $integrationLookupLimiter): JsonResponse
    {
        if (!$this->integrationApiKey) {
            /*
             * Nobody configured a key, so nobody may ask. Failing closed matters
             * here: the alternative is an open endpoint that tells the world
             * which companies use Storno and how busy they are.
             */
            return $this->json(['error' => 'Integration lookup is not enabled.'], Response::HTTP_NOT_FOUND);
        }

        /*
         * Throttled by address before the key is even looked at, otherwise the
         * key itself could be guessed at whatever rate the network allows.
         */
        if (!$integrationLookupLimiter->create('ip:' . $request->getClientIp())->consume()->isAccepted()) {
            return $this->json(['error' => 'Too many requests.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $presented = (string) $request->headers->get('X-Integration-Key', '');

        if (!hash_equals($this->integrationApiKey, $presented)) {
            $this->apiLogger->warning('[INTEGRATION] Rejected lookup', [
                'ip' => $request->getClientIp(),
                'has_key' => '' !== $presented,
            ]);

            return $this->json(['error' => 'Invalid integration key.'], Response::HTTP_UNAUTHORIZED);
        }

        // Even a valid key should not be able to walk the whole fiscal register.
        if (!$integrationLookupLimiter->create('key:' . $presented)->consume()->isAccepted()) {
            return $this->json(['error' => 'Too many requests.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $cui = trim((string) $request->query->get('cui', ''));
        if ('' === $cui) {
            return $this->json(['error' => 'cui is required'], Response::HTTP_BAD_REQUEST);
        }

        $window = $request->query->getInt('window', InvoicingActivityService::DEFAULT_WINDOW_DAYS);

        return $this->json($this->invoicingActivity->lookup($cui, $window));
    }
}
