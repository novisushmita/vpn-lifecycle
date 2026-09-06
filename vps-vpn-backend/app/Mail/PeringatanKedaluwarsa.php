<?php

namespace App\Mail;

use App\Models\AkunVpn;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PeringatanKedaluwarsa extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public AkunVpn $akun, public int $sisaHari) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Akses VPN Anda berakhir dalam {$this->sisaHari} hari");
    }

    public function content(): Content
    {
        return new Content(markdown: 'emel.peringatan-kedaluwarsa');
    }
}
