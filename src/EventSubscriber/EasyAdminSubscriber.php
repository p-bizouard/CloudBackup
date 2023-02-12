<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeEntityPersistedEvent;
use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeEntityUpdatedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class EasyAdminSubscriber implements EventSubscriberInterface
{
    public function __construct(private EntityManagerInterface $entityManager, private UserPasswordHasherInterface $passwordHasher)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BeforeEntityPersistedEvent::class => ['addUser'],
            BeforeEntityUpdatedEvent::class => ['updateUser'],
        ];
    }

    public function updateUser(BeforeEntityUpdatedEvent $event): void
    {
        $entity = $event->getEntityInstance();

        if (!($entity instanceof User)) {
            return;
        }
        $this->setPassword($entity);
    }

    public function addUser(BeforeEntityPersistedEvent $event): void
    {
        $entity = $event->getEntityInstance();

        if (!($entity instanceof User)) {
            return;
        }
        $this->setPassword($entity);
    }

    public function setPassword(User $entity): void
    {
        $pass = $entity->getPlainPassword();

        if (null !== $pass) {
            $entity->setPassword(
                $this->passwordHasher->hashPassword(
                    $entity,
                    $pass
                )
            );
            $this->entityManager->persist($entity);
            $this->entityManager->flush();
        }
    }
}
