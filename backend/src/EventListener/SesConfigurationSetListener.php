<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;

#[AsEventListener(event: MessageEvent::class, priority: 100)]
class SesConfigurationSetListener
{
    public function __construct(
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(env: 'SES_CONFIGURATION_SET')]
        private readonly ?string $sesConfigurationSet = '',
    ) {}

    public function __invoke(MessageEvent $event): void
    {
        if (!$this->sesConfigurationSet) {
            return;
        }

        $message = $event->getMessage();
        if (!$message instanceof Email) {
            return;
        }

        if ($message->getHeaders()->has('X-SES-CONFIGURATION-SET')) {
            return;
        }

        $message->getHeaders()->addTextHeader('X-SES-CONFIGURATION-SET', $this->sesConfigurationSet);
    }
}
