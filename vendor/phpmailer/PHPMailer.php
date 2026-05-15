<?php
/**
 * MCTBS Mailer — SMTP wrapper
 * A lightweight standalone SMTP mailer. No Composer needed.
 * Handles TLS/SSL, authentication, and HTML emails.
 *
 * Based on the PHPMailer interface so config is compatible
 * if you later install the full library via Composer.
 */
namespace PHPMailer\PHPMailer;

class PHPMailer
{
    // ── Public settings (mirrors PHPMailer API) ──────────────
    public $isSMTP      = false;
    public $Host        = '';
    public $SMTPAuth    = true;
    public $Username    = '';
    public $Password    = '';
    public $SMTPSecure  = '';   // 'tls' or 'ssl' or ''
    public $Port        = 587;
    public $From        = '';
    public $FromName    = '';
    public $isHTML      = false;
    public $Subject     = '';
    public $Body        = '';
    public $AltBody     = '';
    public $SMTPDebug   = 0;
    public $CharSet     = 'UTF-8';
    public $exceptions  = false;

    public $ErrorInfo   = '';

    private $to         = [];
    private $replyTo    = [];
    private $smtp       = null;

    public function __construct($exceptions = false)
    {
        $this->exceptions = $exceptions;
    }

    public function isSMTP()
    {
        $this->isSMTP = true;
    }

    public function isHTML($flag = true)
    {
        $this->isHTML = $flag;
    }

    public function addAddress($address, $name = '')
    {
        $this->to[] = ['address' => $address, 'name' => $name];
    }

    public function addReplyTo($address, $name = '')
    {
        $this->replyTo[] = ['address' => $address, 'name' => $name];
    }

    public function clearAddresses()
    {
        $this->to = [];
    }

    /**
     * Send the email via SMTP.
     * Returns true on success, false on failure.
     */
    public function send()
    {
        try {
            if (empty($this->to)) {
                throw new Exception('No recipients specified.');
            }
            if (empty($this->Subject)) {
                throw new Exception('Subject cannot be empty.');
            }

            $this->smtp = $this->connectSMTP();
            $this->smtpHello();
            $this->smtpStartTLS();
            $this->smtpAuthenticate();
            $this->smtpSendMail();
            $this->smtpQuit();
            return true;

        } catch (Exception $e) {
            $this->ErrorInfo = $e->getMessage();
            if ($this->exceptions) throw $e;
            return false;
        } catch (\Exception $e) {
            $this->ErrorInfo = $e->getMessage();
            if ($this->exceptions) throw new Exception($e->getMessage());
            return false;
        }
    }

    // ── SMTP Protocol ────────────────────────────────────────

    private function connectSMTP()
    {
        $host = $this->Host;
        $port = $this->Port;

        // For SSL (port 465) wrap immediately
        if (strtolower($this->SMTPSecure) === 'ssl') {
            $host = 'ssl://' . $host;
        }

        $errno  = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            "{$host}:{$port}",
            $errno, $errstr,
            30,
            STREAM_CLIENT_CONNECT
        );

        if (!$socket) {
            throw new Exception("SMTP connect failed to {$this->Host}:{$port} — {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, 30);
        $this->debug('Connected to ' . $this->Host . ':' . $port);

        $response = $this->getResponse($socket);
        if (substr($response, 0, 3) !== '220') {
            throw new Exception("SMTP server not ready: {$response}");
        }

        return $socket;
    }

    private function smtpHello()
    {
        $domain = $this->getLocalHostname();
        $response = $this->sendCommand("EHLO {$domain}");
        if (substr($response, 0, 1) !== '2') {
            // Fall back to HELO
            $response = $this->sendCommand("HELO {$domain}");
            if (substr($response, 0, 1) !== '2') {
                throw new Exception("EHLO/HELO failed: {$response}");
            }
        }
    }

    private function smtpStartTLS()
    {
        if (strtolower($this->SMTPSecure) !== 'tls') return;

        $response = $this->sendCommand('STARTTLS');
        if (substr($response, 0, 3) !== '220') {
            throw new Exception("STARTTLS failed: {$response}");
        }

        if (!stream_socket_enable_crypto($this->smtp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new Exception('Failed to enable TLS encryption.');
        }

        $this->debug('TLS enabled');

        // Re-issue EHLO after TLS handshake
        $this->smtpHello();
    }

    private function smtpAuthenticate()
    {
        if (!$this->SMTPAuth) return;

        $response = $this->sendCommand('AUTH LOGIN');
        if (substr($response, 0, 3) !== '334') {
            throw new Exception("AUTH LOGIN failed: {$response}");
        }

        $response = $this->sendCommand(base64_encode($this->Username));
        if (substr($response, 0, 3) !== '334') {
            throw new Exception("Username rejected: {$response}");
        }

        $response = $this->sendCommand(base64_encode($this->Password));
        if (substr($response, 0, 3) !== '235') {
            throw new Exception("Password rejected — check SMTP credentials. Response: {$response}");
        }

        $this->debug('Authenticated as ' . $this->Username);
    }

    private function smtpSendMail()
    {
        // MAIL FROM
        $from     = $this->encodeAddress($this->From, $this->FromName);
        $response = $this->sendCommand("MAIL FROM:<{$this->From}>");
        if (substr($response, 0, 3) !== '250') {
            throw new Exception("MAIL FROM rejected: {$response}");
        }

        // RCPT TO
        foreach ($this->to as $recipient) {
            $response = $this->sendCommand("RCPT TO:<{$recipient['address']}>");
            if (substr($response, 0, 3) !== '250') {
                throw new Exception("RCPT TO rejected for {$recipient['address']}: {$response}");
            }
        }

        // DATA
        $response = $this->sendCommand('DATA');
        if (substr($response, 0, 3) !== '354') {
            throw new Exception("DATA command failed: {$response}");
        }

        $message = $this->buildMessage();
        fwrite($this->smtp, $message . "\r\n.\r\n");
        $this->debug('>> [message body]');

        $response = $this->getResponse($this->smtp);
        if (substr($response, 0, 3) !== '250') {
            throw new Exception("Message rejected: {$response}");
        }

        $this->debug('Message sent successfully.');
    }

    private function smtpQuit()
    {
        $this->sendCommand('QUIT');
        fclose($this->smtp);
        $this->smtp = null;
    }

    // ── Message Building ─────────────────────────────────────

    private function buildMessage()
    {
        $boundary = md5(uniqid(time()));
        $toList   = implode(', ', array_map(
            fn($r) => $this->encodeAddress($r['address'], $r['name']),
            $this->to
        ));

        $replyToHeader = '';
        if ($this->replyTo) {
            $replyList = implode(', ', array_map(
                fn($r) => $this->encodeAddress($r['address'], $r['name']),
                $this->replyTo
            ));
            $replyToHeader = "Reply-To: {$replyList}\r\n";
        }

        $subject = $this->encodeHeader($this->Subject);
        $from    = $this->encodeAddress($this->From, $this->FromName);
        $date    = date('r');
        $msgId   = '<' . uniqid('mctbs', true) . '@' . $this->getLocalHostname() . '>';

        if ($this->isHTML) {
            $plainText = $this->AltBody ?: strip_tags(str_replace(['<br>','<br/>','</p>'], "\n", $this->Body));
            $headers  = "Date: {$date}\r\n";
            $headers .= "From: {$from}\r\n";
            $headers .= "To: {$toList}\r\n";
            $headers .= "{$replyToHeader}";
            $headers .= "Message-ID: {$msgId}\r\n";
            $headers .= "Subject: {$subject}\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
            $headers .= "X-Mailer: MCTBS-Mailer/1.0\r\n";

            $body  = "--{$boundary}\r\n";
            $body .= "Content-Type: text/plain; charset={$this->CharSet}\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $body .= chunk_split(base64_encode($plainText)) . "\r\n";
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: text/html; charset={$this->CharSet}\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $body .= chunk_split(base64_encode($this->Body)) . "\r\n";
            $body .= "--{$boundary}--\r\n";
        } else {
            $headers  = "Date: {$date}\r\n";
            $headers .= "From: {$from}\r\n";
            $headers .= "To: {$toList}\r\n";
            $headers .= "{$replyToHeader}";
            $headers .= "Message-ID: {$msgId}\r\n";
            $headers .= "Subject: {$subject}\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/plain; charset={$this->CharSet}\r\n";
            $headers .= "Content-Transfer-Encoding: base64\r\n";
            $headers .= "X-Mailer: MCTBS-Mailer/1.0\r\n";
            $body = chunk_split(base64_encode($this->Body));
        }

        // Dot-stuffing: lines starting with '.' must be doubled
        $body = preg_replace('/^\.$/m', '..', $body);

        return $headers . "\r\n" . $body;
    }

    // ── Helpers ──────────────────────────────────────────────

    private function sendCommand($command)
    {
        $this->debug('>> ' . $command);
        fwrite($this->smtp, $command . "\r\n");
        $response = $this->getResponse($this->smtp);
        $this->debug('<< ' . $response);
        return $response;
    }

    private function getResponse($socket)
    {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            // Multi-line responses: if 4th char is space, response is complete
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return trim($response);
    }

    private function encodeAddress($address, $name = '')
    {
        if ($name) {
            return $this->encodeHeader($name) . " <{$address}>";
        }
        return "<{$address}>";
    }

    private function encodeHeader($value)
    {
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?' . $this->CharSet . '?B?' . base64_encode($value) . '?=';
        }
        // Wrap in quotes if it contains special chars
        if (preg_match('/[",;<>@()\[\]\\\\]/', $value)) {
            return '"' . addslashes($value) . '"';
        }
        return $value;
    }

    private function getLocalHostname()
    {
        return gethostname() ?: 'localhost';
    }

    private function debug($message)
    {
        if ($this->SMTPDebug > 0) {
            echo htmlspecialchars($message) . "<br>\n";
            flush();
        }
    }
}