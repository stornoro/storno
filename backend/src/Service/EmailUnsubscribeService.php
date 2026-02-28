<?php

namespace App\Service;

class EmailUnsubscribeService
{
    public function __construct(
        private readonly string $appSecret,
        private readonly string $frontendUrl,
    ) {}

    public function generateUrl(string $email, string $category, ?string $userId = null): string
    {
        $expiry = (new \DateTimeImmutable('+30 days'))->getTimestamp();
        $payload = implode('|', [$email, $category, $userId ?? '', $expiry]);
        $token = base64_encode($payload);
        $sig = hash_hmac('sha256', $token, $this->appSecret);

        return sprintf(
            '%s/unsubscribe?token=%s&sig=%s',
            rtrim($this->frontendUrl, '/'),
            urlencode($token),
            $sig,
        );
    }

    /**
     * @return array{email: string, category: string, userId: string|null}|null
     */
    public function verify(string $token, string $sig): ?array
    {
        $expectedSig = hash_hmac('sha256', $token, $this->appSecret);

        if (!hash_equals($expectedSig, $sig)) {
            return null;
        }

        $decoded = base64_decode($token, true);
        if ($decoded === false) {
            return null;
        }

        $parts = explode('|', $decoded);
        if (count($parts) !== 4) {
            return null;
        }

        [$email, $category, $userId, $expiry] = $parts;

        if ((int) $expiry < time()) {
            return null;
        }

        return [
            'email' => $email,
            'category' => $category,
            'userId' => $userId !== '' ? $userId : null,
        ];
    }
}
