<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SimpanPaketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('paket')?->id;

        return [
            'nama'        => ['required', 'string', 'max:32', Rule::unique('paket_bandwidth', 'nama')->ignore($id)],
            // Format rate-limit RouterOS, mis. 2M, 512k, 10M.
            'rx_rate'     => ['required', 'string', 'max:16', 'regex:/^\d+[kMG]?$/'],
            'tx_rate'     => ['required', 'string', 'max:16', 'regex:/^\d+[kMG]?$/'],
            'ppp_profile' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.-]+$/', Rule::unique('paket_bandwidth', 'ppp_profile')->ignore($id)],
            'keterangan'  => ['nullable', 'string', 'max:160'],
            'aktif'       => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'rx_rate.regex'     => 'Format kecepatan tidak sah. Contoh yang benar: 512k, 2M, 10M.',
            'tx_rate.regex'     => 'Format kecepatan tidak sah. Contoh yang benar: 512k, 2M, 10M.',
            'ppp_profile.regex' => 'Nama profile hanya boleh berisi huruf, angka, titik, garis bawah, dan tanda hubung.',
            'nama.unique'       => 'Nama paket sudah dipakai.',
            'ppp_profile.unique' => 'Nama profile sudah dipakai paket lain.',
        ];
    }
}
