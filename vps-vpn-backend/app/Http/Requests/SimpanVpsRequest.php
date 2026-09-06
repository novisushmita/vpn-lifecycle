<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SimpanVpsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('vp')?->id ?? $this->route('vps')?->id;

        // Keunikan hanya terhadap baris yang BELUM dihapus. MySQL tidak
        // mendukung partial unique index, jadi ditegakkan di sini.
        $unik = fn (string $kolom) => Rule::unique('vps', $kolom)
            ->whereNull('deleted_at')
            ->ignore($id);

        return [
            'nama'       => ['required', 'string', 'max:64', $unik('nama')],
            'alamat_ip'  => ['required', 'ipv4', $unik('alamat_ip')],
            'keterangan' => ['nullable', 'string', 'max:2000'],
            'aktif'      => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'nama.unique'      => 'Nama VPS sudah dipakai.',
            'alamat_ip.unique' => 'Alamat IP sudah dipakai VPS lain.',
        ];
    }
}
