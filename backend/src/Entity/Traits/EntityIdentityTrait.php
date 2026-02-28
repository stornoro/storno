<?php

namespace App\Entity\Traits;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation as SymfonySerializer;
use Symfony\Component\Uid\Uuid;

trait EntityIdentityTrait
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    #[ORM\GeneratedValue]
    #[SymfonySerializer\Groups(['autocomplete', 'survey_list'])]
    protected $id;

    #[ORM\Column(type: 'uuid')]
    #[SymfonySerializer\Groups(['user_profile', 'idea_list_read', 'my_committees', 'idea_thread_comment_read', 'idea_read', 'idea_thread_list_read', 'approach_list_read', 'event_read', 'event_list_read', 'coalition_read', 'cause_read', 'zone_read', 'profile_read', 'poll_read'])]
    protected ?Uuid $uuid = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): ?Uuid
    {
        return $this->uuid;
    }
}
