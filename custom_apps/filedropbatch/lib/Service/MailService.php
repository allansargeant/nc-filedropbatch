<?php

declare(strict_types=1);

namespace OCA\FileDropBatch\Service;

use OCP\Mail\IMailer;

class MailService {
    public function __construct(
        private IMailer $mailer,
    ) {
    }

    public function sendFileDropLink(
        string $toEmail,
        string $presenterName,
        string $theatre,
        string $date,
        string $startTime,
        string $link,
    ): void {
        $subject = sprintf('File drop link: %s - %s %s', $theatre, $date, $startTime);

        $template = $this->mailer->createEMailTemplate('filedropbatch.presenterInvite', [
            'theatre' => $theatre,
            'date' => $date,
            'time' => $startTime,
        ]);
        $template->setSubject($subject);
        $template->addHeader();
        $template->addHeading('Your file drop link');
        $template->addBodyText(sprintf(
            'Hi %s, please use the link below to upload your materials for %s on %s at %s.',
            $presenterName,
            $theatre,
            $date,
            $startTime
        ));
        $template->addBodyButton('Upload your files', $link);
        $template->addFooter();

        $message = $this->mailer->createMessage();
        $message->setTo([$toEmail => $presenterName]);
        $message->useTemplate($template);

        $this->mailer->send($message);
    }
}
