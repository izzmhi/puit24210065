<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * A small SMTP client (plain text mail, AUTH LOGIN, implicit TLS or STARTTLS).
 * It has no dependencies, so the project runs on XAMPP/Laragon without Composer.
 * Server certificates are always verified.
 */
final class SmtpClient
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $secure, // 'tls' (STARTTLS), 'ssl' (implicit TLS) or 'none'
        private readonly string $username,
        private readonly string $password,
        private readonly int $timeout = 10,
    ) {
    }

    public function send(string $from, string $to, string $subject, string $body): void
    {
        foreach ([$from, $to, $subject] as $field) {
            if (preg_match('/[\r\n]/', $field) === 1) {
                throw new \RuntimeException('Illegal line break in mail header.');
            }
        }
        if (filter_var($from, FILTER_VALIDATE_EMAIL) === false || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('Invalid sender or recipient address.');
        }

        $remote = ($this->secure === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $this->host,
        ]]);
        $socket = @stream_socket_client($remote, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new \RuntimeException('SMTP connection failed (error ' . $errno . ').');
        }
        stream_set_timeout($socket, $this->timeout);

        try {
            $this->expect($socket, [220]);
            $this->command($socket, 'EHLO localhost', [250]);

            if ($this->secure === 'tls') {
                $this->command($socket, 'STARTTLS', [220]);
                if (stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                    throw new \RuntimeException('SMTP TLS negotiation failed.');
                }
                $this->command($socket, 'EHLO localhost', [250]);
            }

            if ($this->username !== '') {
                $this->command($socket, 'AUTH LOGIN', [334]);
                $this->command($socket, base64_encode($this->username), [334]);
                $this->command($socket, base64_encode($this->password), [235]);
            }

            $this->command($socket, 'MAIL FROM:<' . $from . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);

            $message = implode("\r\n", [
                'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
                'From: Secure Login <' . $from . '>',
                'To: <' . $to . '>',
                'Subject: ' . $subject,
                'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $this->host . '>',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
                '',
                $this->prepareBody($body),
            ]);
            $this->command($socket, $message . "\r\n.", [250]);
            $this->command($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }
    }

    private function prepareBody(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = (string) preg_replace('/^\./m', '..', $body); // SMTP dot-stuffing
        return str_replace("\n", "\r\n", $body);
    }

    /**
     * @param resource  $socket
     * @param list<int> $expected
     */
    private function command($socket, string $line, array $expected): void
    {
        fwrite($socket, $line . "\r\n");
        $this->expect($socket, $expected);
    }

    /**
     * @param resource  $socket
     * @param list<int> $expected
     */
    private function expect($socket, array $expected): void
    {
        $code = 0;
        do {
            $line = fgets($socket, 1024);
            if ($line === false) {
                throw new \RuntimeException('SMTP connection closed or timed out.');
            }
            $code = (int) substr($line, 0, 3);
            $more = strlen($line) > 3 && $line[3] === '-';
        } while ($more);

        if (!in_array($code, $expected, true)) {
            throw new \RuntimeException('Unexpected SMTP response code ' . $code . '.');
        }
    }
}
