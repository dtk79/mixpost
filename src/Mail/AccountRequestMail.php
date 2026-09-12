<?php

namespace Inovector\Mixpost\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AccountRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $email) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Peachy Posting account request',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mixpost::mail.accountRequest',
            with: [
                'email' => $this->email,
            ],
        );
    }
}
