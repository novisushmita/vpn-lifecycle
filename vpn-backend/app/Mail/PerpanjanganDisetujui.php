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
 * Menutup alur perpanjangan (keputusan #9): pemohon mengajukan lewat nomor
 * pengajuan lama, admin ACC, dan email ini yang mengabarkan hasilnya.
 *
 * Dikirim dari OperasiAkunJob SETELAH perpanjang() sukses di router, sama
 * seperti KredensialVpn — jangan kabari pemohon sebelum perubahan benar-benar
 * berlaku, karena operasi router bisa saja gagal.
 */
class PerpanjanganDisetujui extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public AkunVpn $akun) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Perpanjangan akses VPN Anda disetujui');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emel.perpanjangan-disetujui');
    }
}
