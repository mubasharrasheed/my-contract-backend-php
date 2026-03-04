<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

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
            'password' => ['required', 'string', 'min:8', 'confirmed'],
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
        $request->validate(['email' => ['required', 'email']]);

        $user = User::where('email', $request->email)->first();
        if (! $user) {
            // don't reveal the existence of the email address
            return response()->json(['message' => 'If your email exists we have sent a reset token']);
        }

        $token = Password::broker()->createToken($user);

        return response()->json([
            'message' => 'Reset token generated',
            'token' => $token,
        ]);
    }

    /**
     * Reset the password using the token from the email
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, $password) {
                $user->password = Hash::make($password);
                $user->setRememberToken(Str::random(60));
                $user->save();

                event(new PasswordReset($user));
            }
        );

        return $status === Password::PASSWORD_RESET
                    ? response()->json(['message' => __($status)])
                    : response()->json(['message' => __($status)], 422);
    }
}
