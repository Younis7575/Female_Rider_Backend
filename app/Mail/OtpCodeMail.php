<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** One-time verification code (registration, login, password reset). Sent synchronously. */
class OtpCodeMail extends Mailable
{
    public function __construct(public string $code, public string $purpose, public int $minutes) {}

    public function envelope(): Envelope
    {
        $subject = match ($this->purpose) {
            'register' => 'Verify your FemaleRider email',
            'reset' => 'Reset your FemaleRider password',
            default => 'Your FemaleRider login code',
        };

        return new Envelope(subject: $subject.' – '.$this->code);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.otp-code');
    }
}
