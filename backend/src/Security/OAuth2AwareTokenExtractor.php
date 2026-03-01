<?php

declare(strict_types=1);

namespace App\Security;

use Lexik\Bundle\JWTAuthenticationBundle\TokenExtractor\TokenExtractorInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Wraps the default JWT token extractor to skip OAuth2 access tokens.
 * This prevents the JWT authenticator from trying to decode storno_oat_* tokens.
 */
class OAuth2AwareTokenExtractor implements TokenExtractorInterface
{
    public function __construct(
        private readonly TokenExtractorInterface $inner,
    ) {}

    public function extract(Request $request): string|false
    {
        $token = $this->inner->extract($request);

        if ($token !== false && str_starts_with($token, 'storno_oat_')) {
            return false;
        }

        return $token;
    }
}
