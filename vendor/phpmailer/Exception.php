<?php
/**
 * PHPMailer Exception class.
 * Simplified version bundled with MCTBS.
 * Full library: https://github.com/PHPMailer/PHPMailer
 */
namespace PHPMailer\PHPMailer;

class Exception extends \Exception
{
    public function errorMessage()
    {
        return $this->getMessage();
    }
}