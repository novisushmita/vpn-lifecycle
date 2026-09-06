<?php

namespace App\Http\Requests;

use App\Models\Vps;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Batas kepercayaan: seluruh isi request ini datang dari publik tanpa autentikasi. */
class SimpanPengajuanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nama'             => ['required', 'string', 'max:120'],
            'identitas'        => ['required', 'string', 'max:64'],
            'instansi'         => ['required', 'string', 'max:160'],
            'email'            => ['required', 'email:rfc', 'max:160'],
            // Hanya VPS yang benar-benar tampil di daftar publik yang boleh diajukan.
            'vps_id'           => ['required', Rule::exists('vps', 'id')->where(
                fn ($q) => $q->where('aktif', true)->where('sedang_dihapus', false)->whereNull('deleted_at')
            )],
            'keperluan'        => ['required', 'string', 'max:120'],
            'keperluan_detail' => ['nullable', 'string', 'max:2000'],
            'durasi_mulai'     => ['required', 'date', 'after_or_equal:today'],
            'durasi_selesai'   => ['required', 'date', 'after_or_equal:durasi_mulai'],
        ];
    }

    public function messages(): array
    {
        return [
            'vps_id.exists'                  => 'VPS yang dipilih tidak tersedia.',
            'durasi_mulai.after_or_equal'    => 'Tanggal mulai tidak boleh di masa lalu.',
            'durasi_selesai.after_or_equal'  => 'Tanggal selesai harus setelah tanggal mulai.',
        ];
    }
}
