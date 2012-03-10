<?php

class Mail
{

    private $from = ['name' => '', 'email' => ''];
    private $to = [];
    private $subject = '';
    private $message = '';
    private $files = [];
    private $multipart = false;
    private $boundary = '';

    const CRLF = "\r\n";

    /**
     * @param $name string unique file name
     * @param $data string
     */
    public function addTextFile($name , $data)
    {
        $this->files[trim($name)] = chunk_split(base64_encode($data), 76, self::CRLF);
    }

    /**
     * @param $name string
     * @return int
     */
    public function getFileLength($name)
    {
        $name = trim($name);
        return isset($this->files[$name]) ? strlen($this->files[$name]) : 0;
    }

    public function dropFile($name)
    {
        $name = trim($name);
        unset($this->files[$name]);
    }

    /**
     * @param string $email
     * @param string $name
     */
    public function setFrom($email, $name = '')
    {
        $this->from = ['email' => trim($email), 'name' => trim($name)];
    }

    /**
     * @param string $email
     * @param string $name
     */
    public function addTo($email, $name = '')
    {
        $this->to[] = ['email' => trim($email), 'name' => trim($name)];
    }

    /**
     * @param $subject string
     */
    public function setSubject($subject)
    {
        $this->subject = trim($subject);
    }

    /**
     * @param $message string
     */
    public function setMessage($message)
    {
        $this->message = $message;
    }


    /**
     * @param $name string
     * @param $value string
     * @return string
     */
    private function makeHeader($name, $value)
    {
        return $name . ': ' . $value;
    }

    /**
     * @param array $address
     * @return string
     */
    private function makeAddress(array $address)
    {
        return $address['name'] ? $this->utf8SafeEncode($address['name'], 100) . ' <'. $address['email']  . '>' : $address['email'];
    }

    /**
     * @param $value string
     * @param int $maxLenght
     * @return string
     */
    private function utf8SafeEncode($value, $maxLenght = null)
    {
        if ($maxLenght) $value = mb_substr($value, 0, $maxLenght);
        return mb_encode_mimeheader($value, 'UTF-8', 'Q');;
    }

    /**
     * @return string
     */
    private function makeHeaders()
    {
        $headers = [];
        $headers[] = $this->makeHeader('From', $this->makeAddress($this->from));
        $uniq =  'php-mail-' . md5(microtime()) . mt_rand();
        $headers[] = $this->makeHeader('Message-ID', '<' . $uniq . '@git.php.net>');
        $headers[] = $this->makeHeader('MIME-Version', '1.0');
        $headers[] = $this->makeHeader('Date', date(DATE_RFC2822, time()));
        if ($this->multipart) {
            $this->boundary = sha1($uniq);
            $headers[] = $this->makeHeader('Content-Type', 'multipart/mixed; boundary="' . $this->boundary . '"');
        } else {
            $headers[] = $this->makeHeader('Content-Type', 'text/plain; charset="utf-8"');
            // we use base64 for avoiding some problems sush string length limit, safety encoding etc.
            $headers[] = $this->makeHeader('Content-Transfer-Encoding', 'quoted-printable');
        }
        return implode(self::CRLF , $headers);
    }

    /**
     * @return string
     */
    private function makeBody()
    {
        $body = '';
        if ($this->multipart) {
                $body .= '--' . $this->boundary . self::CRLF;
                $body .= $this->makeHeader('Content-Type', 'text/plain; charset="utf-8"') . self::CRLF;
                $body .= $this->makeHeader('Content-Transfer-Encoding', 'quoted-printable') . self::CRLF;
                $body .= self::CRLF;
                $body .= quoted_printable_encode($this->message);
            foreach ($this->files as $name => $data) {
                $body .= self::CRLF . '--' . $this->boundary . self::CRLF;
                $body .= $this->makeHeader('Content-Type', 'text/plain; charset="utf-8"') . self::CRLF;
                $body .= $this->makeHeader('Content-Transfer-Encoding', 'base64') . self::CRLF;
                $body .= $this->makeHeader('Content-Disposition', 'attachment; filename="' . $name . '"') . self::CRLF;
                $body .= self::CRLF;
                $body .= $data;
            }
            $body .= self::CRLF . '--' . $this->boundary . '--';
        } else {
            $body = quoted_printable_encode($this->message);
        }
        return $body;
    }

    /**
     * @return bool
     */
    public function send()
    {
        $this->multipart = (bool) count($this->files);

        $receivers = implode(', ', array_map([$this, 'makeAddress'], $this->to));
        $subject = $this->utf8SafeEncode($this->subject, 450);
        $headers = $this->makeHeaders();
        $body = $this->makeBody();

        return mail($receivers, $subject, $body, $headers);
    }
}