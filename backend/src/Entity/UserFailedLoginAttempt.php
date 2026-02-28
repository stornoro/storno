<?php

namespace App\Entity;

use App\Entity\Traits\EntityIdentityTrait;
use App\Repository\UserFailedLoginAttemptRepository;
use App\Security\LoginAttemptSignature;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserFailedLoginAttemptRepository::class)]
class UserFailedLoginAttempt
{
    use EntityIdentityTrait;
    #[ORM\Column(type: 'datetime')]
    private readonly \DateTime|bool $at;

    private function __construct(
        #[ORM\Column]
        private readonly string $signature,
        #[ORM\Column(type: 'json')]
        private readonly array $extra
    ) {
        $this->uuid = Uuid::v4();
        $this->at = \DateTime::createFromFormat('U', time());
    }

    public static function createFromRequest(Request $request): self
    {
        $attempt = LoginAttemptSignature::createFromRequest($request);

        return new self(
            $attempt->getSignature(),
            [
                'login' => $attempt->getLogin(),
                'ip' => $attempt->getIp(),
                'user_agent' => $request->headers->get('User-Agent'),
            ]
        );
    }

    public function getSignature(): string
    {
        return $this->signature;
    }
}
