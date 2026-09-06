<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EditAkunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9._-]+$/', Rule::unique('akun_vpn', 'username')->ignore($this->route('akun')->id)],
            'password' => ['nullable', 'string', 'min:8', 'max:128'],
            'paket_bandwidth_id' => ['required', 'integer', Rule::exists('paket_bandwidth', 'id')->where('aktif', 1)],
            'vps_id' => ['required', 'integer', Rule::exists('vps', 'id')->whereNull('deleted_at')->where('aktif', 1)->where('sedang_dihapus', 0)],
            'putus_sesi' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'username.required' => 'Username wajib diisi.',
            'username.regex' => 'Username hanya boleh berisi huruf, angka, titik, garis bawah, dan tanda hubung.',
            'username.unique' => 'Username sudah digunakan akun lain.',
            'username.max' => 'Username maksimal 64 karakter.',
            'password.min' => 'Password baru minimal 8 karakter.',
            'password.max' => 'Password baru maksimal 128 karakter.',
            'vps_id.exists' => 'VPS tujuan tidak tersedia atau sedang dihapus.',
            'vps_id.required' => 'Pilih VPS tujuan.',
            'paket_bandwidth_id.exists' => 'Paket bandwidth tidak tersedia.',
            'paket_bandwidth_id.required' => 'Pilih paket bandwidth.',
        ];
    }
}
