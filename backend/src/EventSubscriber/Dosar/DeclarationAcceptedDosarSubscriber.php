<?php

namespace App\EventSubscriber\Dosar;

use App\Entity\Dosar;
use App\Enum\DeclarationType;
use App\Event\Declaration\DeclarationAcceptedEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Moves the dosar forward as soon as ANAF accepts a declaration filed from it,
 * without waiting for the recipisa to be pulled from the SPV inbox:
 * a C168 registration marks the contract as registered and drops the 30-day
 * deadline, a termination closes the dosar, a D212 records the filing.
 */
final class DeclarationAcceptedDosarSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [DeclarationAcceptedEvent::class => 'onDeclarationAccepted'];
    }

    public function onDeclarationAccepted(DeclarationAcceptedEvent $event): void
    {
        $declaration = $event->getDeclaration();
        $dosar = $declaration->getDosar();
        if ($dosar === null) {
            return;
        }

        $index = $declaration->getAnafUploadId();
        $reference = $index !== null ? sprintf(' (index %s)', $index) : '';

        if ($declaration->getType() === DeclarationType::C168) {
            $input = $declaration->getData()['input'] ?? [];
            $actions = [];
            foreach (is_array($input['contracte'] ?? null) ? $input['contracte'] : [] as $contract) {
                $actions[] = (string) ($contract['actiune'] ?? 'inregistrare');
            }

            if (in_array('incetare', $actions, true)) {
                $dosar->setStatus(Dosar::STATUS_CLOSED)
                    ->setNextStep('Încetarea contractului a fost înregistrată la ANAF' . $reference . '.');
            } elseif (in_array('modificare', $actions, true)) {
                $dosar->setNextStep('Modificarea contractului a fost înregistrată la ANAF' . $reference . '. Chiria intră în D212 pentru fiecare an.');
            } else {
                $dosar->setNextStep('Înregistrat la ANAF' . $reference . '. Chiria intră în D212 pentru fiecare an.');
            }
            $dosar->setDeadlineAt(null)->setDeadlineLabel(null);
            if ($dosar->getStatus() === Dosar::STATUS_ATTENTION) {
                $dosar->setStatus(Dosar::STATUS_ACTIVE);
            }
        } elseif ($declaration->getType() === DeclarationType::D212) {
            $dosar->setNextStep('Declarația unică a fost acceptată de ANAF' . $reference . '.');
            $dosar->setDeadlineAt(null)->setDeadlineLabel(null);
            if ($dosar->getStatus() === Dosar::STATUS_ATTENTION) {
                $dosar->setStatus(Dosar::STATUS_ACTIVE);
            }
        } else {
            return;
        }

        $dosar->touch();
        $this->entityManager->flush();
    }
}
