<<<<<<< HEAD
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ForgotPasswordOtpRequest;
use App\Http\Requests\Auth\ResetPasswordWithOtpRequest;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Resources\UserResource;
use App\Mail\PasswordOtpMail;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;


class AuthController extends Controller
{
    /** POST /auth/login */
    public function login(Request $request): JsonResponse
    {
        $cred = $request->validate([
            'email'    => ['required','email'],
            'password' => ['required'],
        ]);

        $email = strtolower(trim($cred['email']));

        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if (!$user || !Hash::check($cred['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        // Check if admin trial has expired
        if ($user->role === 'admin' && $user->isTrialExpired()) {
            $user->expireTrial();
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => new UserResource($user),
        ]);
    }

    /**
     * POST /auth/register
     * Handles registration for researcher, supervisor, and admin roles
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Base attributes common to all roles
        $attrs = [
            'name'            => $data['name'],
            'email'           => strtolower(trim($data['email'])),
            'password'        => Hash::make($data['password']),
            'phone'           => $data['phone'] ?? null,
            'organization'    => $data['organization'] ?? null,
            'status'          => 'active',
            'role'            => $data['role'],
            'terms_agreed_at' => now(),
        ];

        // Role-specific mapping to DB columns
        if ($data['role'] === 'researcher') {
            $attrs['department']    = $data['department'] ?? null;
            $attrs['research_area'] = $data['research_area'] ?? $data['researchArea'] ?? null;
        }

        if ($data['role'] === 'supervisor') {
            $attrs['employee_id']    = $data['employee_id'] ?? $data['employeeId'];
            $attrs['department']     = $data['department'];
            $attrs['specialization'] = $data['specialization'] ?? null;
            $attrs['organization']   = $data['organization'] ?? null;
        }

        if ($data['role'] === 'admin') {
            $attrs['organization'] = $data['organization'];
            
            // Handle admin trial signup
            if (isset($data['trial']) && $data['trial'] == 1) {
                $attrs['trial'] = true;
                $attrs['subscription_status'] = 'trial';
                $attrs['trial_start_date'] = isset($data['trial_start_date']) 
                    ? Carbon::parse($data['trial_start_date']) 
                    : Carbon::now();
                $attrs['trial_end_date'] = isset($data['trial_end_date'])
                    ? Carbon::parse($data['trial_end_date'])
                    : Carbon::now()->addDays(30);
            } else {
                // Admin without trial needs immediate payment
                $attrs['subscription_status'] = 'pending_payment';
                $attrs['trial'] = false;
            }
        } else {
            // Researchers and supervisors get free accounts by default
            $attrs['subscription_status'] = 'active';
            $attrs['trial'] = false;
        }

        $user = User::create($attrs);

        // Create default user settings
        if (method_exists($user, 'settings')) {
            $user->settings()->create([
                'citation_style'     => 'chicago-note-bibliography-short',
                'note_format'        => 'markdown+richtext',
                'language'           => 'en-US',
                'quick_copy_as_html' => false,
                'include_urls'       => false,
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => new UserResource($user),
        ], 201);
    }

    /** GET /me */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        
        // Auto-update expired trials
        if ($user->role === 'admin' && $user->isTrialExpired()) {
            $user->expireTrial();
            $user->refresh();
        }

        return response()->json(new UserResource($user));
    }
    
    /** POST /auth/logout */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        if ($token) {
            $token->delete();
        }
        return response()->json(['ok' => true]);
    }
    
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
    
        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }
    
        // Verify current password
        if (!Hash::check($request->input('current_password'), $user->password)) {
            return response()->json([
                'message' => 'The current password is incorrect.',
                'errors'  => [
                    'current_password' => ['The current password is incorrect.'],
                ],
            ], 422);
        }
    
        // Update password
        $user->password = Hash::make($request->input('password'));
        $user->save();
    
        return response()->json([
            'message' => 'Password updated successfully.',
        ]);
    }
    
    public function sendPasswordOtp(ForgotPasswordOtpRequest $request)
    {
        $email = $request->input('email');
    
        $user = User::where('email', $email)->first();
    
        // For security, we respond success even if user not found
        if (!$user) {
          return response()->json([
             'message' => 'If an account exists for this email, an OTP has been sent.',
          ]);
        }
    
        // Generate 6-digit OTP
        $otp = (string) random_int(100000, 999999);
    
        // Store hashed OTP in password_reset_tokens table
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'token'      => Hash::make($otp),
                'created_at' => Carbon::now(),
            ]
        );
    
        // Send OTP email
        Mail::to($user->email)->send(new PasswordOtpMail($user, $otp));
    
        return response()->json([
            'message' => 'If an account exists for this email, an OTP has been sent.',
        ]);
    }
    
    public function resetPasswordWithOtp(ResetPasswordWithOtpRequest $request)
    {
        $email = $request->input('email');
        $otp   = $request->input('otp');
    
        $record = DB::table('password_reset_tokens')->where('email', $email)->first();
    
        if (!$record) {
            return response()->json([
                'message' => 'Invalid or expired OTP.',
                'errors' => ['otp' => ['Invalid or expired OTP.']],
            ], 422);
        }
    
        // Check expiry (15 minutes)
        $created = Carbon::parse($record->created_at);
        if ($created->lt(Carbon::now()->subMinutes(15))) {
            return response()->json([
                'message' => 'OTP has expired. Please request a new one.',
                'errors'  => ['otp' => ['OTP has expired.']],
            ], 422);
        }
    
        // Check OTP hash
        if (!Hash::check($otp, $record->token)) {
            return response()->json([
                'message' => 'Invalid OTP provided.',
                'errors'  => ['otp' => ['Invalid OTP.']],
            ], 422);
        }
    
        $user = User::where('email', $email)->first();
    
        if (!$user) {
            return response()->json([
                'message' => 'User not found.',
            ], 404);
        }
    
        // Update password
        $user->password = Hash::make($request->input('password'));
        $user->save();
    
        // Clear reset token
        DB::table('password_reset_tokens')->where('email', $email)->delete();
    
        return response()->json([
            'message' => 'Password has been reset successfully.',
        ]);
    }
}
=======
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ForgotPasswordOtpRequest;
use App\Http\Requests\Auth\ResetPasswordWithOtpRequest;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Resources\UserResource;
use App\Mail\PasswordOtpMail;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;


class AuthController extends Controller
{
    /** POST /auth/login */
    public function login(Request $request): JsonResponse
    {
        $cred = $request->validate([
            'email'    => ['required','email'],
            'password' => ['required'],
        ]);

        $email = strtolower(trim($cred['email']));

        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if (!$user || !Hash::check($cred['password'], $user->password)) {
            // generic message avoids leaking whether the email exists
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => new UserResource($user),
        ]);
    }

    /**
     * POST /auth/register
     * Body: name, email, password, password_confirmation, phone?, organization?, terms:true
     * Response: { token, user }
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Base attributes common to all roles
        $attrs = [
            'name'            => $data['name'],
            'email'           => strtolower(trim($data['email'])),
            'password'        => Hash::make($data['password']),
            'phone'           => $data['phone'] ?? null,
            'organization'    => $data['organization'] ?? null,
            'status'          => 'active',
            'role'            => $data['role'],   // <-- use role from request
            'terms_agreed_at' => now(),
        ];

        // Role-specific mapping to DB columns
        if ($data['role'] === 'researcher') {
            // nullable in rules
            $attrs['department']    = $data['department'] ?? null;
            $attrs['research_area'] = $data['researchArea'] ?? null; // camelCase → snake_case
        }

        if ($data['role'] === 'supervisor') {
            // required in rules
            $attrs['employee_id']   = $data['employeeId'];           // camelCase → snake_case
            $attrs['department']    = $data['department'];
            $attrs['specialization'] = $data['specialization'] ?? null;
            $attrs['organization']   = $data['organization'] ?? null;
        }

        if ($data['role'] === 'admin') {
            // organization is required in rules
            $attrs['organization'] = $data['organization'];
        }

        $user = User::create($attrs);

        // default prefs (works if you added $user->settings() hasOne())
        if (method_exists($user, 'settings')) {
            $user->settings()->create([
                'citation_style'     => 'chicago-note-bibliography-short',
                'note_format'        => 'markdown+richtext',
                'language'           => 'en-US',
                'quick_copy_as_html' => false,
                'include_urls'       => false,
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => new UserResource($user),
        ], 201);
    }

    /** GET /me */
    public function me(Request $request): JsonResponse
    {
        return response()->json(new UserResource($request->user()));
    }
    
    
    /** POST /auth/logout */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        if ($token) {
            $token->delete();
        }
        return response()->json(['ok' => true]);
    }
    
    
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
    
        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }
    
        // Verify current password
        if (!Hash::check($request->input('current_password'), $user->password)) {
            return response()->json([
                'message' => 'The current password is incorrect.',
                'errors'  => [
                    'current_password' => ['The current password is incorrect.'],
                ],
            ], 422);
        }
    
        // Update password
        $user->password = Hash::make($request->input('password'));
        $user->save();
    
        return response()->json([
            'message' => 'Password updated successfully.',
        ]);
    }
    
    public function sendPasswordOtp(ForgotPasswordOtpRequest $request)
    {
        $email = $request->input('email');
    
        $user = \App\Models\User::where('email', $email)->first();
    
        // For security, we respond success even if user not found
        if (!$user) {
          return response()->json([
             'message' => 'If an account exists for this email, an OTP has been sent.',
          ]);
        }
    
        // Generate 6-digit OTP
        $otp = (string) random_int(100000, 999999);
    
        // Store hashed OTP in password_reset_tokens table
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'token'      => Hash::make($otp),
                'created_at' => Carbon::now(),
            ]
        );
    
        // Send OTP email
        Mail::to($user->email)->send(new PasswordOtpMail($user, $otp));
    
        return response()->json([
            'message' => 'If an account exists for this email, an OTP has been sent.',
        ]);
    }
    
    public function resetPasswordWithOtp(ResetPasswordWithOtpRequest $request)
    {
        $email = $request->input('email');
        $otp   = $request->input('otp');
    
        $record = DB::table('password_reset_tokens')->where('email', $email)->first();
    
        if (!$record) {
            return response()->json([
                'message' => 'Invalid or expired OTP.',
                'errors' => ['otp' => ['Invalid or expired OTP.']],
            ], 422);
        }
    
        // Check expiry (15 minutes)
        $created = Carbon::parse($record->created_at);
        if ($created->lt(Carbon::now()->subMinutes(15))) {
            return response()->json([
                'message' => 'OTP has expired. Please request a new one.',
                'errors'  => ['otp' => ['OTP has expired.']],
            ], 422);
        }
    
        // Check OTP hash
        if (!Hash::check($otp, $record->token)) {
            return response()->json([
                'message' => 'Invalid OTP provided.',
                'errors'  => ['otp' => ['Invalid OTP.']],
            ], 422);
        }
    
        $user = \App\Models\User::where('email', $email)->first();
    
        if (!$user) {
            return response()->json([
                'message' => 'User not found.',
            ], 404);
        }
    
        // Update password
        $user->password = Hash::make($request->input('password'));
        $user->save();
    
        // Clear reset token
        DB::table('password_reset_tokens')->where('email', $email)->delete();
    
        return response()->json([
            'message' => 'Password has been reset successfully.',
        ]);
    }



}
>>>>>>> f7cd52df7aa68d8ff2d0a1db806176f748b88031
