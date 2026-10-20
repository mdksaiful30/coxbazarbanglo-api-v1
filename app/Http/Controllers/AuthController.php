<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    private function credentials(Request $r): User
    {
        $data = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        $u = User::where('email', $data['email'])->first();
        if (! $u || ! $u->active || ! Hash::check($data['password'], $u->password)) {
            throw ValidationException::withMessages(['email' => 'Invalid credentials or disabled account.']);
        }

        return $u;
    }

    public function login(Request $r)
    {
        $u = $this->credentials($r);
        Auth::login($u);
        $r->session()->regenerate();

        return response()->json(['data' => $u]);
    }

    public function mobile(Request $r)
    {
        $r->validate(['device_name' => 'required|string|max:100']);
        $u = $this->credentials($r);

        return response()->json(['data' => ['user' => $u, 'token' => $u->createToken($r->device_name, ['*'], now()->addDays(30))->plainTextToken, 'expires_at' => now()->addDays(30)->toIso8601String()]]);
    }

    public function logout(Request $r)
    {
        $token = $r->user()?->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        } if ($r->hasSession()) {
            Auth::guard('web')->logout();
            $r->session()->invalidate();
            $r->session()->regenerateToken();
        }

return response()->json(['message' => 'Signed out.']);
    }

    public function password(Request $r)
    {
        $d = $r->validate(['current_password' => 'required|string', 'password' => 'required|confirmed|min:12']);
        abort_unless(Hash::check($d['current_password'], $r->user()->password), 422, 'Current password is incorrect.');
        $r->user()->update(['password' => $d['password']]);
        $r->user()->tokens()->delete();

        return response()->json(['message' => 'Password changed. Mobile sessions were revoked.']);
    }

    public function forgot(Request $r)
    {
        $r->validate(['email' => 'required|email']);
        Password::sendResetLink($r->only('email'));

        return response()->json(['message' => 'If this account exists, a reset link has been requested.']);
    }

    public function reset(Request $r)
    {
        $data = $r->validate(['email' => 'required|email', 'token' => 'required|string', 'password' => 'required|confirmed|min:12']);
        $status = Password::reset($data, function ($u, $password) {
            $u->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            $u->tokens()->delete();
        });
        abort_unless($status === Password::PASSWORD_RESET, 422, __($status));

        return response()->json(['message' => 'Password reset.']);
    }

    public function devices(Request $r)
    {
        return response()->json(['data' => $r->user()->tokens()->get(['id', 'name', 'last_used_at', 'expires_at', 'created_at'])]);
    }

    public function revoke(Request $r, int $id)
    {
        $r->user()->tokens()->findOrFail($id)->delete();

        return response()->json(['message' => 'Device revoked.']);
    }
}
