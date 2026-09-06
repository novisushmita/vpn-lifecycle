<?php

namespace App\Mail;

use App\Models\AkunVpn;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Satu-satunya jalur kredensial sampai ke pemohon.
 *
 * Pemohon tidak punya akun login (keputusan #6), dan halaman cek status tidak
 * pernah menampilkan password. Email ini yang menutup alur itu.
 */
class KredensialVpn extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public AkunVpn $akun,
        public string $password,
        public string $server,
        public string $ipsecPsk,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Akun VPN Anda sudah aktif');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emel.kredensial-vpn');
    }
}
