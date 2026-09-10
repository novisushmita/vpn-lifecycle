<?php

namespace App\Jobs;

use App\Models\OperasiRouter;
use App\Services\Vpn\EditAkunService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class EditAkunJob implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public int $operasiId, public array $data) {}

    public function handle(EditAkunService $service): void
    {
        $service->jalankan($this->operasiId, $this->data);
    }

    public function failed(?Throwable $exception): void
    {
        OperasiRouter::whereKey($this->operasiId)->whereIn('status', ['antre', 'berjalan'])->update([
            'status' => 'gagal',
            'pesan_error' => 'Pekerjaan edit terhenti. Periksa sinkronisasi sebelum mencoba kembali.',
            'selesai_pada' => now(),
        ]);
    }
}
