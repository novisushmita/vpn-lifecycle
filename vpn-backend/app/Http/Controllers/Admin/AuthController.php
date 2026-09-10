<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PencatatAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'Email atau kata sandi salah.',
            ]);
        }

        // Satu sesi aktif per admin; token lama dicabut saat login baru.
        $user->tokens()->delete();
        $token = $user->createToken('dashboard')->plainTextToken;

        auth()->setUser($user);
        PencatatAudit::catat('login', "Admin {$user->email} masuk ke dashboard.", $user);

        return response()->json([
            'token' => $token,
            'user'  => ['id' => $user->id, 'nama' => $user->name, 'email' => $user->email],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Berhasil keluar.']);
    }

    public function me(Request $request): JsonResponse
    {
        $u = $request->user();

        return response()->json(['id' => $u->id, 'nama' => $u->name, 'email' => $u->email]);
    }
}
