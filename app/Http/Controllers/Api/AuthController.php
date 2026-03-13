<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PortalNotification;
use App\Models\ProfileUpdateRequest;
use App\Models\User;
use App\Support\PortalFormatter;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'company' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'mobileNumber' => ['nullable', 'string', 'max:30'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'passwordConfirmation' => ['required', 'same:password'],
            'profilePhotoUrl' => ['nullable', 'string'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'company' => $validated['company'],
            'address' => $validated['address'] ?? null,
            'mobile_number' => $validated['mobileNumber'] ?? null,
            'email' => strtolower($validated['email']),
            'profile_photo_url' => $validated['profilePhotoUrl'] ?? null,
            'two_factor_enabled' => false,
            'password' => $validated['password'],
            'role' => 'customer',
            'is_enabled' => false,
            'registration_status' => 'pending',
        ]);

        $this->notifyAdminsAboutRegistrationRequest($user);

        return response()->json([
            'message' => 'Your account registration was submitted successfully and is now waiting for admin approval.',
            'pendingApproval' => true,
            'user' => PortalFormatter::sanitizeUser($user),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'role' => ['nullable', Rule::in(['customer', 'admin'])],
            'challengeId' => ['nullable', 'string'],
            'twoFactorCode' => ['nullable', 'string'],
            'sessionMeta' => ['nullable', 'array'],
            'sessionMeta.locationLabel' => ['nullable', 'string', 'max:255'],
            'sessionMeta.deviceLabel' => ['nullable', 'string', 'max:255'],
            'sessionMeta.userAgent' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = User::where('email', strtolower($validated['email']))->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials. Please try again.'], 401);
        }

        if ($user->role === 'customer' && $user->registration_status === 'pending') {
            return response()->json(['message' => 'Your registration is still waiting for admin approval. Please check your email for updates.'], 403);
        }

        if ($user->role === 'customer' && $user->registration_status === 'rejected') {
            $message = 'Your registration was rejected by the admin.';

            if (! empty($user->registration_admin_notes)) {
                $message .= ' Admin note: '.$user->registration_admin_notes;
            }

            return response()->json(['message' => $message], 403);
        }

        if (! $user->is_enabled) {
            return response()->json(['message' => 'This account is currently disabled. Please contact an administrator.'], 403);
        }

        if (! empty($validated['role']) && $user->role !== $validated['role']) {
            return response()->json(['message' => 'You do not have access to this portal.'], 403);
        }

        $sessionMeta = $validated['sessionMeta'] ?? [];

        if ($user->two_factor_enabled && $this->isUnusualLogin($user, $sessionMeta)) {
            $challenge = $this->resolveTwoFactorChallenge($user, $validated, $sessionMeta);

            if ($challenge instanceof JsonResponse) {
                return $challenge;
            }
        }

        $token = $user->createToken($sessionMeta['deviceLabel'] ?? 'portal');
        $this->storeTokenMetadata($token->accessToken->id, $request, $sessionMeta);

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => PortalFormatter::sanitizeUser($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $this->syncCurrentTokenMetadata($request);

        return response()->json([
            'user' => PortalFormatter::sanitizeUser($request->user()->load('latestProfileUpdateRequest.reviewer')),
        ]);
    }

    public function security(Request $request): JsonResponse
    {
        $this->syncCurrentTokenMetadata($request);

        return response()->json([
            'twoFactorEnabled' => (bool) $request->user()->two_factor_enabled,
            'sessions' => $this->formatSessions($request),
        ]);
    }

    public function updateTwoFactor(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $request->user()->update([
            'two_factor_enabled' => $validated['enabled'],
        ]);

        return response()->json([
            'message' => $validated['enabled']
                ? 'Two-factor authentication is now enabled.'
                : 'Two-factor authentication has been turned off.',
            'twoFactorEnabled' => (bool) $request->user()->fresh()->two_factor_enabled,
        ]);
    }

    public function destroySession(Request $request, PersonalAccessToken $token): JsonResponse
    {
        abort_unless($token->tokenable_type === User::class && (int) $token->tokenable_id === (int) $request->user()->id, 403);

        $isCurrent = optional($request->user()->currentAccessToken())->id === $token->id;

        $token->delete();

        return response()->json([
            'message' => $isCurrent ? 'Current session signed out.' : 'Session ended successfully.',
            'loggedOutCurrentSession' => $isCurrent,
        ]);
    }

    public function destroyOtherSessions(Request $request): JsonResponse
    {
        $currentTokenId = optional($request->user()->currentAccessToken())->id;

        $deleted = $request->user()->tokens()
            ->when($currentTokenId, fn ($query) => $query->where('id', '!=', $currentTokenId))
            ->delete();

        return response()->json([
            'message' => $deleted > 0
                ? 'All other sessions were logged out successfully.'
                : 'There are no other active sessions to log out.',
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'company' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'mobileNumber' => ['nullable', 'string', 'max:30'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'profilePhotoUrl' => ['nullable', 'string'],
        ]);

        $requestedProfile = [
            'name' => $validated['name'],
            'email' => strtolower($validated['email']),
            'company' => $validated['company'],
            'address' => $validated['address'] ?? null,
            'mobile_number' => $validated['mobileNumber'] ?? null,
            'profile_photo_url' => $validated['profilePhotoUrl'] ?? null,
        ];

        if ($user->role !== 'customer') {
            $user->update($requestedProfile);

            return response()->json([
                'message' => 'Admin profile updated successfully.',
                'user' => PortalFormatter::sanitizeUser($user->fresh()->load('latestProfileUpdateRequest.reviewer')),
            ]);
        }

        $pendingRequest = $user->profileUpdateRequests()->where('status', 'pending')->latest()->first();

        if (! $pendingRequest && ! $this->profileHasChanges($user, $requestedProfile)) {
            return response()->json([
                'message' => 'No new profile changes were detected.',
                'user' => PortalFormatter::sanitizeUser($user->load('latestProfileUpdateRequest.reviewer')),
            ]);
        }

        if ($pendingRequest && $this->profileRequestMatches($pendingRequest, $requestedProfile)) {
            return response()->json([
                'message' => 'Your pending profile update request is already waiting for admin approval.',
                'user' => PortalFormatter::sanitizeUser($user->load('latestProfileUpdateRequest.reviewer')),
            ]);
        }

        $wasExistingPending = (bool) $pendingRequest;

        DB::transaction(function () use ($user, $requestedProfile, &$pendingRequest) {
            if ($pendingRequest) {
                $pendingRequest->update([
                    ...$requestedProfile,
                    'admin_notes' => null,
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                ]);

                return;
            }

            $pendingRequest = $user->profileUpdateRequests()->create($requestedProfile);
        });

        $this->notifyAdminsAboutProfileUpdateRequest($user, $wasExistingPending);

        return response()->json([
            'message' => $wasExistingPending
                ? 'Your pending profile update request was updated and sent back to the admin for confirmation.'
                : 'Your profile update request was sent to the admin for confirmation.',
            'user' => PortalFormatter::sanitizeUser($user->fresh()->load('latestProfileUpdateRequest.reviewer')),
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($validated['currentPassword'], $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $user->update([
            'password' => $validated['newPassword'],
        ]);

        return response()->json([
            'message' => 'Password updated successfully.',
        ]);
    }

    private function validatedSessionMeta(Request $request): array
    {
        try {
            return $request->validate([
                'sessionMeta' => ['nullable', 'array'],
                'sessionMeta.locationLabel' => ['nullable', 'string', 'max:255'],
                'sessionMeta.deviceLabel' => ['nullable', 'string', 'max:255'],
                'sessionMeta.userAgent' => ['nullable', 'string', 'max:1000'],
            ])['sessionMeta'] ?? [];
        } catch (HttpResponseException) {
            return [];
        }
    }

    private function storeTokenMetadata(int $tokenId, Request $request, array $sessionMeta): void
    {
        DB::table('personal_access_tokens')
            ->where('id', $tokenId)
            ->update([
                'ip_address' => $request->ip(),
                'device_label' => $sessionMeta['deviceLabel'] ?? 'Unknown device',
                'user_agent' => $sessionMeta['userAgent'] ?? (string) $request->userAgent(),
                'location_label' => $sessionMeta['locationLabel'] ?? $request->ip(),
                'updated_at' => now(),
            ]);
    }

    private function syncCurrentTokenMetadata(Request $request): void
    {
        $currentToken = $request->user()?->currentAccessToken();

        if (! $currentToken) {
            return;
        }

        DB::table('personal_access_tokens')
            ->where('id', $currentToken->id)
            ->whereNull('device_label')
            ->update([
                'ip_address' => $request->ip(),
                'device_label' => 'This browser',
                'user_agent' => (string) $request->userAgent(),
                'location_label' => $request->ip(),
                'updated_at' => now(),
            ]);
    }

    private function formatSessions(Request $request): array
    {
        $currentTokenId = optional($request->user()->currentAccessToken())->id;

        return $request->user()->tokens()
            ->latest('last_used_at')
            ->latest('created_at')
            ->get()
            ->map(function (PersonalAccessToken $token) use ($currentTokenId) {
                return [
                    'id' => (string) $token->id,
                    'deviceLabel' => $token->device_label ?: 'Unknown device',
                    'locationLabel' => $token->location_label ?: ($token->ip_address ?: 'Unknown location'),
                    'ipAddress' => $token->ip_address,
                    'lastUsedAt' => optional($token->last_used_at)->toISOString(),
                    'createdAt' => optional($token->created_at)->toISOString(),
                    'isCurrent' => $token->id === $currentTokenId,
                ];
            })
            ->values()
            ->all();
    }

    private function profileHasChanges(User $user, array $requestedProfile): bool
    {
        return $user->name !== $requestedProfile['name']
            || strtolower($user->email) !== $requestedProfile['email']
            || $user->company !== $requestedProfile['company']
            || $user->address !== $requestedProfile['address']
            || $user->mobile_number !== $requestedProfile['mobile_number']
            || $user->profile_photo_url !== $requestedProfile['profile_photo_url'];
    }

    private function profileRequestMatches(ProfileUpdateRequest $profileUpdateRequest, array $requestedProfile): bool
    {
        return $profileUpdateRequest->name === $requestedProfile['name']
            && strtolower($profileUpdateRequest->email) === $requestedProfile['email']
            && $profileUpdateRequest->company === $requestedProfile['company']
            && $profileUpdateRequest->address === $requestedProfile['address']
            && $profileUpdateRequest->mobile_number === $requestedProfile['mobile_number']
            && $profileUpdateRequest->profile_photo_url === $requestedProfile['profile_photo_url'];
    }

    private function notifyAdminsAboutProfileUpdateRequest(User $user, bool $wasExistingPending): void
    {
        $timestamp = now();
        $message = $wasExistingPending
            ? $user->name.' updated a pending profile change request.'
            : $user->name.' submitted a profile update request for approval.';

        $notifications = User::query()
            ->where('role', 'admin')
            ->get(['id'])
            ->map(fn (User $admin) => [
                'user_id' => $admin->id,
                'title' => 'Profile update approval needed',
                'message' => $message,
                'type' => 'warning',
                'is_read' => false,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ])
            ->all();

        if ($notifications !== []) {
            PortalNotification::insert($notifications);
        }
    }

    private function notifyAdminsAboutRegistrationRequest(User $user): void
    {
        $timestamp = now();

        $notifications = User::query()
            ->where('role', 'admin')
            ->get(['id'])
            ->map(fn (User $admin) => [
                'user_id' => $admin->id,
                'title' => 'New customer registration pending',
                'message' => $user->name.' submitted a new portal registration and is waiting for approval.',
                'type' => 'warning',
                'is_read' => false,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ])
            ->all();

        if ($notifications !== []) {
            PortalNotification::insert($notifications);
        }

        try {
            Mail::raw(
                "Hello {$user->name},\n\nYour WSI portal registration has been received and is waiting for admin approval. We will email you once your account has been approved or rejected.\n\nRegards,\nWSI Portal",
                fn ($message) => $message->to($user->email)->subject('WSI Portal registration submitted')
            );
        } catch (\Throwable) {
        }
    }

    private function isUnusualLogin(User $user, array $sessionMeta): bool
    {
        $existingTokens = $user->tokens()->get();

        if ($existingTokens->isEmpty()) {
            return false;
        }

        $location = trim((string) ($sessionMeta['locationLabel'] ?? ''));
        $device = trim((string) ($sessionMeta['deviceLabel'] ?? ''));

        if ($location === '' && $device === '') {
            return true;
        }

        return ! $existingTokens->contains(function (PersonalAccessToken $token) use ($location, $device) {
            return ($location !== '' && strcasecmp((string) $token->location_label, $location) === 0)
                || ($device !== '' && strcasecmp((string) $token->device_label, $device) === 0);
        });
    }

    private function resolveTwoFactorChallenge(User $user, array $validated, array $sessionMeta): ?JsonResponse
    {
        $challengeId = $validated['challengeId'] ?? null;
        $submittedCode = trim((string) ($validated['twoFactorCode'] ?? ''));

        if ($challengeId && $submittedCode !== '') {
            $cachedChallenge = Cache::get('2fa:'.$challengeId);

            if ($cachedChallenge
                && (int) $cachedChallenge['user_id'] === (int) $user->id
                && hash_equals((string) $cachedChallenge['code'], $submittedCode)) {
                Cache::forget('2fa:'.$challengeId);

                return null;
            }
        }

        $newChallengeId = (string) Str::uuid();
        $code = (string) random_int(100000, 999999);

        Cache::put('2fa:'.$newChallengeId, [
            'user_id' => $user->id,
            'code' => $code,
            'sessionMeta' => $sessionMeta,
        ], now()->addMinutes(10));

        return response()->json([
            'requiresTwoFactor' => true,
            'challengeId' => $newChallengeId,
            'message' => $submittedCode !== ''
                ? 'Verification code is invalid. Please try again.'
                : 'We noticed an unusual login. Enter the verification code to continue.',
            'demoCode' => $code,
        ]);
    }
}
