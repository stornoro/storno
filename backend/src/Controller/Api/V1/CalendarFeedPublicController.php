<?php

namespace App\Controller\Api\V1;

use App\Service\Calendar\CalendarFeedService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The iCalendar body behind a calendar subscription link. Public on purpose (calendar apps cannot
 * log in); the link itself is the credential. Unknown, stale or revoked links all answer 404.
 */
class CalendarFeedPublicController extends AbstractController
{
    /** lastFetchedAt is informative; calendar apps poll often, so it is written at most hourly. */
    private const FETCH_STAMP_INTERVAL = 3600;

    #[Route('/api/v1/calendar/feed/{id}/{signature}.ics', methods: ['GET', 'HEAD'], requirements: ['id' => '[0-9a-f-]{36}', 'signature' => '[0-9a-f]{40}'])]
    public function feed(string $id, string $signature, Request $request, CalendarFeedService $service, EntityManagerInterface $em, RateLimiterFactory $calendarFeedLimiter): Response
    {
        if (!$calendarFeedLimiter->create('feed:' . $id)->consume()->isAccepted()) {
            return new Response('Too many requests', Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => '600']);
        }
        $feed = $service->findByLink($id, $signature);
        $membership = $feed !== null ? $service->activeMembership($feed) : null;
        if ($feed === null || $membership === null) {
            return new Response('Not found', Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $body = $service->render($feed, $membership);
        $now = new \DateTimeImmutable();
        if ($feed->getLastFetchedAt() === null || $now->getTimestamp() - $feed->getLastFetchedAt()->getTimestamp() > self::FETCH_STAMP_INTERVAL) {
            $feed->markFetched($now);
            $em->flush();
        }

        return new Response($request->isMethod('HEAD') ? '' : $body, Response::HTTP_OK, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="storno.ics"',
            'Cache-Control' => 'private, max-age=900',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
