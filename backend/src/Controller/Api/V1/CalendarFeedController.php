<?php

namespace App\Controller\Api\V1;

use App\Entity\CalendarFeed;
use App\Entity\User;
use App\Repository\CalendarFeedRepository;
use App\Security\OrganizationContext;
use App\Security\Permission;
use App\Service\Calendar\CalendarFeedService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The caller's calendar subscription in the current organization: a secret iCalendar link with the
 * expiries (vehicles, contracts, certificates) and the fiscal deadlines of the companies they can see.
 *
 *   GET    /api/v1/calendar-feed   {enabled: false} or the feed with its links
 *   POST   /api/v1/calendar-feed   turn it on, or issue a new link ({regenerate: true}) that invalidates the old one
 *   PATCH  /api/v1/calendar-feed   {includeExpiries?, includeFiscal?}
 *   DELETE /api/v1/calendar-feed   turn it off; every link stops working
 */
#[Route('/api/v1/calendar-feed')]
class CalendarFeedController extends AbstractController
{
    public function __construct(
        private readonly OrganizationContext $organizationContext,
        private readonly CalendarFeedRepository $feeds,
        private readonly CalendarFeedService $service,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function show(Request $request): JsonResponse
    {
        [$user, $organization, $error] = $this->member();
        if ($error) {
            return $error;
        }
        $feed = $this->feeds->findForMember($user, $organization);

        return $this->json($this->payload($feed, $request));
    }

    #[Route('', methods: ['POST'])]
    public function enable(Request $request): JsonResponse
    {
        [$user, $organization, $error] = $this->member();
        if ($error) {
            return $error;
        }
        $input = json_decode($request->getContent() ?: '{}', true);
        $input = is_array($input) ? $input : [];
        $feed = $this->feeds->findForMember($user, $organization);
        $status = Response::HTTP_OK;
        if ($feed === null) {
            $feed = new CalendarFeed($user, $organization);
            $this->em->persist($feed);
            $status = Response::HTTP_CREATED;
        } elseif (filter_var($input['regenerate'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $feed->rotate();
        }
        $this->applyOptions($feed, $input);
        $this->em->flush();

        return $this->json($this->payload($feed, $request), $status);
    }

    #[Route('', methods: ['PATCH'])]
    public function update(Request $request): JsonResponse
    {
        [$user, $organization, $error] = $this->member();
        if ($error) {
            return $error;
        }
        $feed = $this->feeds->findForMember($user, $organization);
        if ($feed === null) {
            return $this->json(['error' => 'Abonamentul de calendar nu este activ.', 'code' => 'NOT_ENABLED'], Response::HTTP_NOT_FOUND);
        }
        $input = json_decode($request->getContent(), true);
        if (!is_array($input)) {
            return $this->json(['error' => 'Corpul cererii trebuie să fie JSON.', 'code' => 'INVALID_JSON'], Response::HTTP_BAD_REQUEST);
        }
        $this->applyOptions($feed, $input);
        $this->em->flush();

        return $this->json($this->payload($feed, $request));
    }

    #[Route('', methods: ['DELETE'])]
    public function disable(): JsonResponse
    {
        [$user, $organization, $error] = $this->member();
        if ($error) {
            return $error;
        }
        $feed = $this->feeds->findForMember($user, $organization);
        if ($feed !== null) {
            $this->em->remove($feed);
            $this->em->flush();
        }

        return $this->json(['enabled' => false]);
    }

    /** @return array{0: ?User, 1: ?\App\Entity\Organization, 2: ?JsonResponse} */
    private function member(): array
    {
        $user = $this->getUser();
        $organization = $this->organizationContext->getOrganization();
        $membership = $this->organizationContext->getMembership();
        if (!$user instanceof User || $organization === null || $membership === null || !$membership->isActive()) {
            return [null, null, $this->json(['error' => 'Organization not found.'], Response::HTTP_NOT_FOUND)];
        }

        return [$user, $organization, null];
    }

    /** @param array<string, mixed> $input */
    private function applyOptions(CalendarFeed $feed, array $input): void
    {
        if (array_key_exists('includeExpiries', $input)) {
            $feed->setIncludeExpiries(filter_var($input['includeExpiries'], FILTER_VALIDATE_BOOLEAN));
        }
        if (array_key_exists('includeFiscal', $input)) {
            $feed->setIncludeFiscal(filter_var($input['includeFiscal'], FILTER_VALIDATE_BOOLEAN));
        }
    }

    /** @return array<string, mixed> */
    private function payload(?CalendarFeed $feed, Request $request): array
    {
        $canSeeExpiries = $this->organizationContext->hasPermission(Permission::SETTINGS_VIEW);
        $canSeeFiscal = $this->organizationContext->hasPermission(Permission::DECLARATION_VIEW);
        if ($feed === null) {
            return ['enabled' => false, 'canSeeExpiries' => $canSeeExpiries, 'canSeeFiscal' => $canSeeFiscal];
        }
        $url = $this->service->url($feed, $request->getSchemeAndHttpHost());
        $webcal = preg_replace('#^https?://#', 'webcal://', $url);

        return [
            'enabled' => true,
            'url' => $url,
            'webcalUrl' => $webcal,
            'googleCalendarUrl' => 'https://calendar.google.com/calendar/render?cid=' . rawurlencode($webcal),
            'outlookUrl' => 'https://outlook.live.com/calendar/0/addfromweb?url=' . rawurlencode($url) . '&name=' . rawurlencode('Storno'),
            'includeExpiries' => $feed->includesExpiries(),
            'includeFiscal' => $feed->includesFiscal(),
            'canSeeExpiries' => $canSeeExpiries,
            'canSeeFiscal' => $canSeeFiscal,
            'createdAt' => $feed->getCreatedAt()->format('c'),
            'lastFetchedAt' => $feed->getLastFetchedAt()?->format('c'),
        ];
    }
}
