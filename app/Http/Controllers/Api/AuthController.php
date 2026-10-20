<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['Invalid email or password.']]);
        }

        if ($user->approval_status !== 'approved' || $user->status !== 'active') {
            return response()->json([
                'message' => 'Your account is pending approval or has been deactivated. Please contact your school administrator.',
            ], 403);
        }

        $token = $user->createToken('reading-web')->plainTextToken;

        return response()->json(['token' => $token, 'user' => $this->userData($user)]);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'role' => ['required', 'in:principal,coordinator,teacher'],
            'first_name' => ['required', 'string', 'max:50'],
            'last_name' => ['required', 'string', 'max:49'],
            'employee_id' => ['nullable', 'string', 'max:80', 'required_if:role,teacher'],
            'grade_level' => ['nullable', 'string', 'max:20', 'required_if:role,teacher'],
            'section' => ['nullable', 'string', 'max:50', 'required_if:role,teacher'],
            'email' => ['required', 'email', 'max:100', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', 'min:8', 'regex:/[A-Z]/', 'regex:/[0-9]/'],
        ]);

        $user = User::create([
            'full_name' => trim($data['first_name'].' '.$data['last_name']),
            'email' => $data['email'],
            'employee_id' => $data['employee_id'] ?? null,
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
            'section' => $data['section'] ?? null,
            'grade_level' => $data['grade_level'] ?? null,
            'status' => 'inactive',
            'approval_status' => 'pending',
            'joined_date' => now()->toDateString(),
        ]);

        $labels = ['principal' => 'Principal', 'coordinator' => 'Reading Coordinator', 'teacher' => 'Teacher'];
        $details = ['Role' => $labels[$user->role], 'Name' => $user->full_name];
        if ($user->role === 'teacher') $details['Employee ID'] = $user->employee_id;
        $details['Email'] = $user->email;
        if ($user->role === 'teacher') {
            $details['Assigned To'] = trim(($user->grade_level ?? '').' — '.($user->section ?? ''), ' —');
        }

        return response()->json([
            'message' => 'Registration submitted successfully. Your account is pending approval.',
            'details' => $details,
        ], 201);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userData($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();
        return response()->json(['message' => 'Logged out.']);
    }

    private function userData(User $user): array
    {
        return $user->only(['id', 'full_name', 'email', 'role', 'section', 'grade_level', 'profile_image']);
    }
}
