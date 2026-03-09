<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Notifications\ResetPinNotification;
use Illuminate\Support\Facades\Crypt;

class AuthController extends Controller
{
    /**
     * Register a new user and return an API token
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    /**
     * Login and issue a token
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (!Auth::attempt($credentials)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $user = Auth::user();
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    /**
     * Logout (revoke current token if possible)
     */
    public function logout(Request $request)
    {
        $token = $request->user()->currentAccessToken();

        // personal access tokens support delete(); transient ones do not.
        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        } else {
            // attempt to revoke using the bearer value in case currentAccessToken
            // is a transient instance (or null) but the token exists in the DB.
            if ($bearer = $request->bearerToken()) {
                try {
                    [$id, $plain] = explode('|', $bearer, 2);
                    $model = \Laravel\Sanctum\PersonalAccessToken::find($id);

                    if ($model && hash_equals($model->token, hash('sha256', $plain))) {
                        $model->delete();
                    }
                } catch (\Exception $e) {
                    // ignore invalid format
                }
            }
        }

        // also log the user out of the session guard to avoid falling back to it
        Auth::guard('web')->logout();

        return response()->json(['message' => 'Logged out']);
    }

    /**
     * Send a password reset token (API-friendly).
     *
     * Instead of trying to build a URL (which requires a web route and
     * can fail in testing), we simply create the token and return it to the
     * caller. The client is then responsible for presenting it to the user
     * or including it in a reset form.
     */
    public function forgotPassword(Request $request)
    {
        $request->validate(
            ['email' => ['required', 'email', 'exists:users,email']],
            ['email.exists' => 'Invalid Email.']
        );

        $user = User::where('email', $request->email)->first();

        // generate a numeric 6-digit PIN
        $pin = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // store a hashed version in the password_reset_tokens table
        DB::table(config('auth.passwords.users.table', 'password_reset_tokens'))
            ->updateOrInsert(
                ['email' => $user->email],
                [
                    'token' => hash('sha256', $pin),
                    'created_at' => Carbon::now(),
                ]
            );

        // send the PIN via email
        $result = $user->notify(new ResetPinNotification($pin));
        // dd($result);
        return response()->json([
            'message' => 'If your email exists we have sent a reset PIN',
        ]);
    }

    /**
     * Reset the password using the token from the email
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'reset_token' => ['required'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        // validate the reset token produced by verifyPin
        try {
            $payload = json_decode(Crypt::decryptString($request->reset_token), true);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Invalid or expired reset token'], 422);
        }

        if (! isset($payload['email']) || $payload['email'] !== $request->email) {
            return response()->json(['message' => 'Invalid reset token for this email'], 422);
        }

        if (! isset($payload['expires_at']) || Carbon::now()->gt(Carbon::parse($payload['expires_at']))) {
            return response()->json(['message' => 'Reset token expired'], 422);
        }

        // perform the reset
        $user = User::where('email', $request->email)->first();
        if (! $user) {
            return response()->json(['message' => 'User not found'], 422);
        }

        $user->password = Hash::make($request->password);
        $user->setRememberToken(Str::random(60));
        $user->save();

        event(new PasswordReset($user));

        // delete the used PIN
        DB::table(config('auth.passwords.users.table', 'password_reset_tokens'))
            ->where('email', $request->email)
            ->delete();

        return response()->json(['message' => 'Password reset successfully']);
    }

    /**
     * Verify the 6-digit PIN and return a short-lived reset token.
     */
    public function verifyPin(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'pin' => ['required'],
        ]);

        $row = DB::table(config('auth.passwords.users.table', 'password_reset_tokens'))
                ->where('email', $request->email)
                ->first();

        if (! $row) {
            return response()->json(['message' => 'Invalid PIN'], 422);
        }

        // check expiry (use configured minutes)
        $expireMinutes = config('auth.passwords.users.expire', 60);
        $created = Carbon::parse($row->created_at);
        if (Carbon::now()->subMinutes($expireMinutes)->gt($created)) {
            return response()->json(['message' => 'PIN expired'], 422);
        }

        // verify PIN (stored hashed with sha256)
        if (! hash_equals($row->token, hash('sha256', $request->pin))) {
            return response()->json(['message' => 'Invalid PIN'], 422);
        }

        // generate a short-lived encrypted reset token (e.g., 15 minutes)
        $resetTtl = min(15, $expireMinutes); // don't exceed pin expiry
        $payload = [
            'email' => $request->email,
            'issued_at' => Carbon::now()->toIso8601String(),
            'expires_at' => Carbon::now()->addMinutes($resetTtl)->toIso8601String(),
        ];

        $resetToken = Crypt::encryptString(json_encode($payload));

        // delete the PIN row so it's one-time use
        DB::table(config('auth.passwords.users.table', 'password_reset_tokens'))
            ->where('email', $request->email)
            ->delete();

        return response()->json([
            'message' => 'PIN verified',
            'reset_token' => $resetToken,
            'expires_in_minutes' => $resetTtl,
        ]);
    }
}
