<?php
/**
 * Envoi de courriels MIME (texte + HTML + pièces jointes) sans dépendance,
 * via SMTP authentifié (STARTTLS / SSL) ou via la fonction mail() de PHP.
 */
declare(strict_types=1);

final class Mailer
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * @param string[] $to
     * @param array<int, array{path:string, name:string}> $attachments
     */
    public function send(array $to, string $subject, string $text, string $html, array $attachments = [], ?string $replyTo = null): void
    {
        $to = array_values(array_filter($to, fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL)));
        if (!$to) {
            throw new RuntimeException('Aucun destinataire valide configuré.');
        }
        if ($replyTo !== null && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $replyTo = null;
        }

        $from = $this->config['from_email'];
        $fromName = $this->config['from_name'] ?? '';
        $boundaryMixed = 'mix_' . bin2hex(random_bytes(12));
        $boundaryAlt = 'alt_' . bin2hex(random_bytes(12));

        $headers = [
            'Date: ' . date('r'),
            'From: ' . $this->encodeHeader($fromName) . " <{$from}>",
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . substr(strrchr($from, '@'), 1) . '>',
            'MIME-Version: 1.0',
            "Content-Type: multipart/mixed; boundary=\"{$boundaryMixed}\"",
        ];
        if ($replyTo) {
            $headers[] = "Reply-To: {$replyTo}";
        }

        $body = "--{$boundaryMixed}\r\n"
            . "Content-Type: multipart/alternative; boundary=\"{$boundaryAlt}\"\r\n\r\n"
            . "--{$boundaryAlt}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text))
            . "--{$boundaryAlt}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode('<!DOCTYPE html><html><body>' . $html . '</body></html>'))
            . "--{$boundaryAlt}--\r\n";

        foreach ($attachments as $file) {
            $data = file_get_contents($file['path']);
            if ($data === false) {
                throw new RuntimeException('Lecture impossible d\'une pièce jointe.');
            }
            $name = $this->encodeHeader(str_replace(['"', "\r", "\n"], '', $file['name']));
            $mime = $this->mimeType($file['name']);
            $body .= "--{$boundaryMixed}\r\n"
                . "Content-Type: {$mime}; name=\"{$name}\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . "Content-Disposition: attachment; filename=\"{$name}\"\r\n\r\n"
                . chunk_split(base64_encode($data));
        }
        $body .= "--{$boundaryMixed}--\r\n";

        $encodedSubject = $this->encodeHeader($subject);

        if (($this->config['transport'] ?? 'mail') === 'smtp') {
            $all = array_merge($headers, ['To: ' . implode(', ', $to), "Subject: {$encodedSubject}"]);
            $this->sendSmtp($from, $to, implode("\r\n", $all) . "\r\n\r\n" . $body);
            return;
        }

        $ok = mail(implode(', ', $to), $encodedSubject, $body, implode("\r\n", $headers), '-f' . $from);
        if (!$ok) {
            throw new RuntimeException('La fonction mail() a refusé le message.');
        }
    }

    private function encodeHeader(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }

    private function mimeType(string $name): string
    {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };
    }

    // ---------------- SMTP ----------------

    /** @var resource|null */
    private $socket = null;

    private function sendSmtp(string $from, array $to, string $message): void
    {
        $c = $this->config['smtp'];
        $enc = strtolower((string)($c['encryption'] ?? ''));
        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $c['host'] . ':' . (int)$c['port'];
        $timeout = (int)($c['timeout'] ?? 20);

        $this->socket = @stream_socket_client($remote, $errno, $errstr, $timeout);
        if (!$this->socket) {
            throw new RuntimeException("Connexion SMTP impossible ({$errstr}).");
        }
        stream_set_timeout($this->socket, $timeout);

        try {
            $this->expect(220);
            $helo = $_SERVER['SERVER_NAME'] ?? 'localhost';
            $this->command("EHLO {$helo}", 250);

            if ($enc === 'tls') {
                $this->command('STARTTLS', 220);
                if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('Échec de la négociation TLS.');
                }
                $this->command("EHLO {$helo}", 250);
            }

            if (!empty($c['username'])) {
                $this->command('AUTH LOGIN', 334);
                $this->command(base64_encode($c['username']), 334);
                $this->command(base64_encode($c['password']), 235);
            }

            $this->command("MAIL FROM:<{$from}>", 250);
            foreach ($to as $rcpt) {
                $this->command("RCPT TO:<{$rcpt}>", [250, 251]);
            }
            $this->command('DATA', 354);
            // Normalise les fins de ligne et protège les lignes commençant par un point.
            $message = preg_replace("/\r?\n/", "\r\n", $message);
            $message = preg_replace('/^\./m', '..', $message);
            fwrite($this->socket, $message . "\r\n.\r\n");
            $this->expect(250);
            $this->command('QUIT', 221);
        } finally {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    private function command(string $line, int|array $expected): void
    {
        fwrite($this->socket, $line . "\r\n");
        $this->expect($expected);
    }

    private function expect(int|array $expected): void
    {
        $expected = (array)$expected;
        $response = '';
        while (($line = fgets($this->socket, 515)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new RuntimeException('Réponse SMTP inattendue : ' . trim($response));
        }
    }
}
