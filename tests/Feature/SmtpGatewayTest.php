<?php

namespace Tests\Feature;

use App\Services\EmailDeliveryFailure;
use App\Services\SmtpGateway;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\MailManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\AbstractStream;
use Tests\TestCase;

class SmtpGatewayTest extends TestCase
{
    private function transport(?\Throwable $failure = null): AbstractTransport
    {
        $transport = new class($failure) extends AbstractTransport
        {
            public array $messages = [];

            public function __construct(private ?\Throwable $failure)
            {
                parent::__construct();
            }

            protected function doSend(SentMessage $message): void
            {
                if ($this->failure) {
                    throw $this->failure;
                }
                $this->messages[] = $message->getOriginalMessage();
                $message->setMessageId('reusable-server-queue-id');
            }

            public function __toString(): string
            {
                return 'test-smtp';
            }
        };
        config(['mail.mailers.smtp.transport' => 'test-smtp']);
        app(MailManager::class)->extend('test-smtp', fn () => $transport);

        return $transport;
    }

    private function message(): array
    {
        return [
            'from' => 'Barbearia 85 <sender@example.com>', 'to' => ['client@example.com'],
            'subject' => 'Confirmação', 'html' => '<h1>Olá</h1>', 'text' => 'Olá',
        ];
    }

    public function test_smtp_uses_laravel_mailer_and_preserves_html_text_recipient_and_message_id(): void
    {
        $transport = $this->transport();
        $gateway = app(SmtpGateway::class);
        $key = str_repeat('a', 64);
        $first = $gateway->send($this->message(), $key);
        $this->assertSame($first, $gateway->send($this->message(), $key));
        $this->assertCount(2, $transport->messages);
        $email = $transport->messages[0];
        $this->assertSame('client@example.com', $email->getTo()[0]->getAddress());
        $this->assertSame('sender@example.com', $email->getFrom()[0]->getAddress());
        $this->assertSame('Barbearia 85', $email->getFrom()[0]->getName());
        $this->assertSame('Confirmação', $email->getSubject());
        $this->assertSame('<h1>Olá</h1>', $email->getHtmlBody());
        $this->assertSame('Olá', $email->getTextBody());
        $this->assertSame($key.'@notifications.barbearia85.local', $first);
        $this->assertFalse($email->getHeaders()->has('Idempotency-Key'));
    }

    public function test_explicit_temporary_rejections_can_retry(): void
    {
        $this->transport(new UnexpectedResponseException('private SMTP response', 451));
        try {
            app(SmtpGateway::class)->send($this->message(), 'stable-key');
            $this->fail('SMTP rejection must propagate.');
        } catch (EmailDeliveryFailure $failure) {
            $this->assertSame('smtp_temporary_rejection', $failure->reason);
            $this->assertTrue($failure->transient);
            $this->assertStringNotContainsString('private', $failure->getMessage());
        }
    }

    public function test_permanent_rejections_do_not_retry(): void
    {
        $this->transport(new UnexpectedResponseException('private SMTP response', 550));
        try {
            app(SmtpGateway::class)->send($this->message(), 'stable-key');
            $this->fail('SMTP rejection must propagate.');
        } catch (EmailDeliveryFailure $failure) {
            $this->assertSame('smtp_rejected', $failure->reason);
            $this->assertFalse($failure->transient);
            $this->assertStringNotContainsString('private', $failure->getMessage());
        }
    }

    public function test_connection_failures_and_timeouts_are_ambiguous_and_do_not_retry(): void
    {
        $this->transport(new TransportException('private connection details'));
        try {
            app(SmtpGateway::class)->send($this->message(), 'stable-key');
            $this->fail('Connection failure must propagate.');
        } catch (EmailDeliveryFailure $failure) {
            $this->assertSame('smtp_result_unknown', $failure->reason);
            $this->assertFalse($failure->transient);
            $this->assertStringNotContainsString('private', $failure->getMessage());
        }
    }

    public function test_cancelled_messages_are_not_reported_as_accepted(): void
    {
        $transport = $this->transport();
        app('events')->listen(MessageSending::class, fn () => false);
        $this->expectException(EmailDeliveryFailure::class);
        $this->expectExceptionMessage('smtp_send_cancelled');
        try {
            app(SmtpGateway::class)->send($this->message(), 'stable-key');
        } finally {
            $this->assertCount(0, $transport->messages);
        }
    }

    public static function smtpResponses(): array
    {
        return [
            'accepted' => ["250 Ok queued as REUSED123\r\n", null, false],
            'temporary refusal' => ["451 Please try later\r\n", 'smtp_temporary_rejection', true],
            'permanent refusal' => ["550 Mailbox unavailable\r\n", 'smtp_rejected', false],
            'connection lost after DATA' => [new TransportException('private timeout'), 'smtp_result_unknown', false],
        ];
    }

    #[DataProvider('smtpResponses')]
    public function test_real_smtp_protocol_classifies_data_response_without_reconnecting_after_acceptance(
        string|\Throwable $response, ?string $reason, bool $transient,
    ): void {
        $responses = ["220 Ready\r\n", "250 Hello\r\n", "250 Sender OK\r\n", "250 Recipient OK\r\n", "354 Send data\r\n", $response];
        if ($reason) {
            $responses[] = "250 Reset OK\r\n";
        }
        $responses[] = "221 Bye\r\n";
        $stream = new class($responses) extends AbstractStream
        {
            public int $connections = 0;

            public function __construct(private array $responses) {}

            public function initialize(): void
            {
                $this->connections++;
            }

            public function write(string $bytes, bool $debug = true): void {}

            public function flush(): void {}

            public function readLine(): string
            {
                $response = array_shift($this->responses);
                if ($response instanceof \Throwable) {
                    throw $response;
                }
                if (! is_string($response)) {
                    throw new \RuntimeException('Unexpected SMTP command in test.');
                }

                return $response;
            }

            protected function getReadConnectionDescription(): string
            {
                return 'scripted-smtp';
            }
        };
        $transport = new SmtpTransport($stream);
        $transport->setRestartThreshold(1);
        config(['mail.mailers.smtp.transport' => 'test-smtp']);
        app(MailManager::class)->extend('test-smtp', fn () => $transport);
        try {
            $id = app(SmtpGateway::class)->send($this->message(), 'stable-key');
            $this->assertNull($reason);
            $this->assertSame('stable-key@notifications.barbearia85.local', $id);
        } catch (EmailDeliveryFailure $failure) {
            $this->assertSame($reason, $failure->reason);
            $this->assertSame($transient, $failure->transient);
        } finally {
            $this->assertSame(1, $stream->connections);
            $transport->stop();
        }
    }
}
