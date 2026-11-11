<?php

namespace App\Services;

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;

class SmtpGateway
{
    public function send(array $message, string $key): string
    {
        try {
            $mailer = Mail::mailer('smtp');
            $transport = $mailer->getSymfonyTransport();
            if ($transport instanceof SmtpTransport) {
                // Reconnecting after DATA acceptance can turn a successful send into a retryable error.
                $transport->setRestartThreshold(PHP_INT_MAX);
            }
            $sent = $mailer->send([
                'html' => new HtmlString($message['html']),
                'text' => new HtmlString($message['text']),
            ], [], function (Message $mail) use ($message, $key) {
                $email = $mail->getSymfonyMessage();
                $email->from($message['from'])->to(...$message['to'])->subject($message['subject']);
                // A stable Message-ID helps trace retries, but SMTP does not deduplicate it.
                $email->getHeaders()->addIdHeader('Message-ID', $key.'@notifications.barbearia85.local');
            });
        } catch (UnexpectedResponseException $exception) {
            $code = $exception->getCode();
            if ($code >= 400 && $code < 500) {
                throw new EmailDeliveryFailure('smtp_temporary_rejection', true);
            }
            if ($code >= 500 && $code < 600) {
                throw new EmailDeliveryFailure('smtp_rejected', false);
            }
            throw new EmailDeliveryFailure('smtp_result_unknown', false);
        } catch (TransportExceptionInterface $exception) {
            // The server may have accepted DATA before the connection was lost.
            throw new EmailDeliveryFailure('smtp_result_unknown', false);
        }
        if (! $sent) {
            throw new EmailDeliveryFailure('smtp_send_cancelled', false);
        }

        // SMTP queue IDs can be reused; persist our globally unique Message-ID instead.
        return $sent->getOriginalMessage()->getHeaders()->get('Message-ID')->getId();
    }
}
