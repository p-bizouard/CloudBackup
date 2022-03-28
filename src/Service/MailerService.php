<?php

namespace App\Service;

use App\Entity\Backup;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class MailerService
{
    public function __construct(
        private string $from_address,
        private array $report_addresses,
        private MailerInterface $mailer
    ) {
    }

    public function sendFailedBackupReport(Backup $backup): void
    {
        $email = new Email();
        $email
            ->from(new Address($this->from_address))
            ->returnPath($this->from_address)
            ->subject(sprintf('[CloudBackup] %s failed', $backup->getName()))
            ->html(sprintf("Backup %s failed.\nLast logs : %s",
                $backup->getName(),
                $backup->getLogsForReport()
            ))
        ;

        foreach ($this->report_addresses as $address) {
            $email->addTo($address);
        }

        $this->mailer->send($email);
    }
}
