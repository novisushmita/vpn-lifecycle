<?php

namespace App\Mail;

use App\Models\Pengajuan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PengajuanDiterima extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Pengajuan $pengajuan) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Pengajuan VPN {$this->pengajuan->nomor} diterima");
    }

    public function content(): Content
    {
        return new Content(markdown: 'emel.pengajuan-diterima');
    }
}
