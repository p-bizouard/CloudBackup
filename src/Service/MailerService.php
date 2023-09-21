<?php

namespace App\Service;

use App\Entity\Backup;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class MailerService
{
    public function __construct(
        private string $from_address,
        private array $report_addresses,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
    ) {
    }

    public function sendFailedBackupReport(Backup $backup): void
    {
        try {
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
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Cannot send email [%s]', $e->getMessage()));
        }
    }
}
