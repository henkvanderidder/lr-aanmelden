<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AanmeldingMail extends Mailable
{
    use Queueable, SerializesModels;

    public $laptop;

    /**
     * Create a new message instance.
     */
    public function __construct($laptop)
    {
        $this->laptop = $laptop;
        /**
         * voorbeeld van $laptop:
         *   [submissionid] => 6
         *   [manufacturer] => Bull
         *   [productname] => DPS2000
         *   [serialnumber] => DPS2000-1234
         *   [email] => henk.vande.ridder@solcon.nl
         *   [lrnummer] => LR00006
         */ 
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Aanmelding laptop',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'mail.aanmelding',
            with: [
                'laptop' => $this->laptop,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
