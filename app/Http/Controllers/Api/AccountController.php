<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\PaginatesApiResults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    use PaginatesApiResults;

    public function profile(Request $request): JsonResponse
    {
        $user = $request->user();
        $stats = $user->role === 'teacher' ? [
            'total_students' => DB::table('students')->where('teacher_id', $user->id)->count(),
            'sessions' => DB::table('remedial_sessions')->where('teacher_id', $user->id)->count(),
            'assessments' => DB::table('assessments')->join('students', 'students.id', '=', 'assessments.student_id')->where('students.teacher_id', $user->id)->count(),
        ] : [
            'total_teachers' => DB::table('users')->where('role', 'teacher')->count(),
            'total_students' => DB::table('students')->count(),
            'total_reports' => DB::table('reports')->count(),
        ];
        return response()->json(['user' => $user->only(['id', 'full_name', 'email', 'role', 'status', 'section', 'grade_level', 'profile_image', 'contact_number', 'joined_date']), 'stats' => $stats]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:100', Rule::unique('users', 'email')->ignore($user->id)],
            'section' => ['nullable', 'string', 'max:50'], 'grade_level' => ['nullable', 'string', 'max:20'],
            'contact_number' => ['nullable', 'string', 'max:40'],
        ]);
        DB::table('users')->where('id', $user->id)->update($data);
        return response()->json(['message' => 'Profile updated successfully!', 'user' => DB::table('users')->where('id', $user->id)->first(['id', 'full_name', 'email', 'role', 'status', 'section', 'grade_level', 'profile_image', 'contact_number', 'joined_date'])]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string'], 'new_password' => ['required', 'string', 'min:6'], 'confirm_password' => ['required', 'same:new_password']]);
        abort_unless(Hash::check($data['current_password'], $request->user()->password), 422, 'Current password is incorrect.');
        DB::table('users')->where('id', $request->user()->id)->update(['password' => Hash::make($data['new_password'])]);
        return response()->json(['message' => 'Password changed successfully!']);
    }

    public function uploadProfileImage(Request $request): JsonResponse
    {
        $request->validate(['profile_image' => ['required', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:5120']]);
        $user = $request->user();
        $filename = 'profile_'.$user->id.'_'.bin2hex(random_bytes(8)).'.'.$request->file('profile_image')->extension();
        $path = 'uploads/profiles/'.$filename;
        Storage::disk('local')->putFileAs('uploads/profiles', $request->file('profile_image'), $filename);
        $previous = $user->profile_image;
        DB::table('users')->where('id', $user->id)->update(['profile_image' => $path]);
        if ($previous && Storage::disk('local')->exists($previous)) Storage::disk('local')->delete($previous);
        return response()->json(['message' => 'Profile image updated successfully!', 'profile_image' => $path]);
    }

    public function image(int $user)
    {
        $path = DB::table('users')->where('id', $user)->value('profile_image');
        abort_unless($path && Storage::disk('local')->exists($path), 404);
        return response()->file(Storage::disk('local')->path($path));
    }

    public function teachers(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['principal', 'coordinator'], true), 403);
        $studentCounts = DB::table('students')
            ->select('teacher_id', DB::raw('COUNT(*) as student_count'))
            ->groupBy('teacher_id');
        $query = DB::table('users')->leftJoinSub($studentCounts, 'student_counts', function ($join) {
            $join->on('users.id', '=', 'student_counts.teacher_id');
        })->where('users.role', 'teacher')->orderByDesc('users.joined_date')->orderByDesc('users.id');
        if ($search = trim(mb_substr((string) $request->query('search', ''), 0, 100))) {
            $query->where(fn ($q) => $q->where('users.full_name', 'like', '%'.$search.'%')->orWhere('users.email', 'like', '%'.$search.'%')->orWhere('users.section', 'like', '%'.$search.'%')->orWhere('users.grade_level', 'like', '%'.$search.'%'));
        }
        $teacherColumns = ['users.id', 'users.full_name', 'users.email', 'users.section', 'users.grade_level', 'users.status', 'users.joined_date', DB::raw('COALESCE(student_counts.student_count, 0) as student_count')];
        $page = $this->paginateApiQuery($request, $query, $teacherColumns);
        $teachers = $page['data'] ?? $query->get($teacherColumns);
        $principal = DB::table('users')->where('role', 'principal')->orderBy('id')->first(['id', 'full_name', 'email', 'status', 'joined_date']);
        $pendingRegistrations = $request->user()->role === 'coordinator'
            ? DB::table('users')->where('approval_status', 'pending')->orderBy('joined_date')->orderBy('id')
                ->get(['id', 'full_name', 'email', 'role', 'employee_id', 'section', 'grade_level', 'joined_date'])
            : collect();
        return response()->json(array_merge([
            'teachers' => $teachers,
            'principal' => $principal,
            'pending_registrations' => $pendingRegistrations,
        ], $page ? ['pagination' => $page['pagination']] : []));
    }

    public function reviewRegistration(Request $request, int $user, string $action): JsonResponse
    {
        abort_unless($request->user()->role === 'coordinator', 403, 'Only the reading coordinator can approve registrations.');
        $record = DB::table('users')->where('id', $user)->where('approval_status', 'pending')->first(['id', 'role']);
        abort_unless($record, 404, 'Pending registration not found.');

        if ($action === 'approve') {
            DB::table('users')->where('id', $user)->update(['approval_status' => 'approved', 'status' => 'active']);
            return response()->json(['message' => 'Registration approved. The account can now sign in.']);
        }

        if ($action === 'reject') {
            DB::table('users')->where('id', $user)->update(['approval_status' => 'rejected', 'status' => 'inactive']);
            return response()->json(['message' => 'Registration rejected.']);
        }

        abort(404);
    }

    public function teacherProfile(Request $request, int $teacher): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['principal', 'coordinator'], true) || (int) $request->user()->id === $teacher, 403);
        $record = DB::table('users')->where('id', $teacher)->where('role', 'teacher')->first(['id', 'full_name', 'email', 'section', 'grade_level', 'status', 'joined_date', 'profile_image']);
        abort_unless($record, 404);
        return response()->json([
            'teacher' => $record,
            'students' => DB::table('students')->where('teacher_id', $teacher)->orderBy('full_name')->get(),
            'assessments' => DB::table('assessments')->join('students', 'students.id', '=', 'assessments.student_id')->where('students.teacher_id', $teacher)->orderByDesc('assessment_date')->limit(20)->get(['assessments.*', 'students.full_name']),
            'sessions' => DB::table('remedial_sessions')->where('teacher_id', $teacher)->orderByDesc('session_date')->limit(20)->get(),
        ]);
    }

    public function createTeacher(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['principal', 'coordinator'], true), 403);
        $data = $request->validate(['full_name' => ['required', 'string', 'max:100'], 'email' => ['required', 'email', 'max:100', 'unique:users,email'], 'section' => ['nullable', 'string', 'max:50'], 'grade_level' => ['nullable', 'string', 'max:20']]);
        $id = DB::table('users')->insertGetId($data + ['password' => Hash::make('password'), 'role' => 'teacher', 'status' => 'active', 'joined_date' => now()->toDateString()]);
        return response()->json(['message' => 'Teacher account created successfully!', 'id' => $id, 'temporary_password' => 'password'], 201);
    }

    public function updateTeacher(Request $request, int $teacher): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['principal', 'coordinator'], true), 403);
        $data = $request->validate(['full_name' => ['required', 'string', 'max:100'], 'email' => ['required', 'email', 'max:100', Rule::unique('users', 'email')->ignore($teacher)], 'section' => ['nullable', 'string', 'max:50'], 'grade_level' => ['nullable', 'string', 'max:20']]);
        abort_unless(DB::table('users')->where('id', $teacher)->where('role', 'teacher')->exists(), 404);
        DB::table('users')->where('id', $teacher)->where('role', 'teacher')->update($data);
        return response()->json(['message' => 'Teacher profile updated.']);
    }

    public function teacherAction(Request $request, int $teacher, string $action): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['principal', 'coordinator'], true), 403);
        $record = DB::table('users')->where('id', $teacher)->where('role', 'teacher')->first();
        abort_unless($record, 404);
        if ($action === 'toggle') DB::table('users')->where('id', $teacher)->update(['status' => $record->status === 'active' ? 'inactive' : 'active']);
        elseif ($action === 'reset-password') DB::table('users')->where('id', $teacher)->update(['password' => Hash::make('password')]);
        elseif ($action === 'delete') DB::table('users')->where('id', $teacher)->delete();
        else abort(404);
        return response()->json(['message' => $action === 'reset-password' ? 'Teacher account reset. Temporary password: password' : 'Teacher account updated.']);
    }

    public function principal(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['principal', 'coordinator'], true), 403);
        $principal = DB::table('users')->where('role', 'principal')->orderBy('id')->first(['id', 'full_name', 'email', 'status', 'joined_date']);
        abort_unless($principal, 404);
        return response()->json(['data' => $principal]);
    }

    public function updatePrincipal(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['principal', 'coordinator'], true), 403);
        $principal = DB::table('users')->where('role', 'principal')->orderBy('id')->first(['id']);
        abort_unless($principal, 404);
        $data = $request->validate(['full_name' => ['required', 'string', 'max:100'], 'email' => ['required', 'email', 'max:100', Rule::unique('users', 'email')->ignore($principal->id)]]);
        DB::table('users')->where('id', $principal->id)->update($data);
        return response()->json(['message' => 'Principal account updated.']);
    }

    public function principalAction(Request $request, string $action): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['principal', 'coordinator'], true), 403);
        $principal = DB::table('users')->where('role', 'principal')->orderBy('id')->first(['id', 'status']);
        abort_unless($principal, 404);
        if ($action === 'toggle') DB::table('users')->where('id', $principal->id)->update(['status' => $principal->status === 'active' ? 'inactive' : 'active']);
        elseif ($action === 'reset-password') DB::table('users')->where('id', $principal->id)->update(['password' => Hash::make('password')]);
        else abort(404);
        return response()->json(['message' => $action === 'reset-password' ? 'Principal account reset. Temporary password: password' : 'Principal account status updated.']);
    }

    public function toggleMyStatus(Request $request): JsonResponse
    {
        $status = $request->user()->status === 'active' ? 'inactive' : 'active';
        DB::table('users')->where('id', $request->user()->id)->update(['status' => $status]);
        return response()->json(['message' => 'Account status updated to '.$status.'.', 'status' => $status]);
    }
}
