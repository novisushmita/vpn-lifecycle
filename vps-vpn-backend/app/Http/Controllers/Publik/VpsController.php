<?php

namespace App\Http\Controllers\Publik;

use App\Http\Controllers\Controller;
use App\Http\Resources\VpsPublikResource;
use App\Models\Vps;

class VpsController extends Controller
{
    /** Daftar VPS untuk form pengajuan. Tanpa alamat IP. */
    public function index()
    {
        return VpsPublikResource::collection(
            Vps::publik()->orderBy('nama')->get()
        );
    }
}
