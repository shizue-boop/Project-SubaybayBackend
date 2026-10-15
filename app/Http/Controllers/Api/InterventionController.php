<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InterventionController extends Controller
{
    public function sessions(Request $request): JsonResponse
    {
        $query = DB::table('remedial_sessions')->join('students', 'students.id', '=', 'remedial_sessions.student_id')
            ->leftJoin('users', 'users.id', '=', 'remedial_sessions.teacher_id');
        if ($request->user()->role === 'teacher') $query->where('students.teacher_id', $request->user()->id);
        return response()->json(['data' => $query->orderByDesc('session_date')
            ->get(['remedial_sessions.*', 'students.full_name', 'students.grade_level', 'students.section', 'users.full_name as teacher_name'])]);
    }

    public function schedule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'teacher_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('role', 'teacher')],
            'session_date' => ['required', 'date'], 'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'], 'focus_area' => ['required', 'string', 'max:100'],
            'session_type' => ['required', Rule::in(['Phonics Drill', 'Fluency Practice', 'Comprehension Guide', 'Guided Reading', 'Vocabulary Building', 'Oral Reading Fluency', 'Word Recognition'])], 'notes' => ['nullable', 'string'],
        ]);
        $user = $request->user();
        $student = DB::table('students')->where('id', $data['student_id'])->first();
        if ($user->role === 'teacher') {
            abort_unless((int) $student->teacher_id === (int) $user->id, 403, 'Select one of your assigned pupils.');
            $data['teacher_id'] = $user->id;
        } else {
            abort_unless(in_array($user->role, ['principal', 'coordinator'], true), 403);
            $data['teacher_id'] = $data['teacher_id'] ?? $student->teacher_id;
        }
        $data['status'] = 'Scheduled';
        $data['notes'] = $data['notes'] ?? null;
        $id = DB::table('remedial_sessions')->insertGetId($data);
        return response()->json(['data' => DB::table('remedial_sessions')->where('id', $id)->first()], 201);
    }

    public function attendance(Request $request): JsonResponse
    {
        $isTeacher = $request->user()->role === 'teacher';
        $sessions = DB::table('remedial_sessions')->join('students', 'students.id', '=', 'remedial_sessions.student_id')
            ->leftJoin('users as teachers', 'teachers.id', '=', 'remedial_sessions.teacher_id')
            ->leftJoin('remedial_attendance', function ($join) {
                $join->on('remedial_attendance.session_id', '=', 'remedial_sessions.id')
                    ->where('remedial_attendance.attendance_date', '=', now()->toDateString());
            });
        if ($isTeacher) $sessions->where('students.teacher_id', $request->user()->id);
        $rows = $sessions->orderByDesc('remedial_sessions.session_date')->get([
            'remedial_sessions.id', 'remedial_sessions.session_date', 'remedial_sessions.start_time', 'remedial_sessions.end_time',
            'students.id as student_id', 'students.full_name', 'students.grade_level', 'students.section',
            'teachers.full_name as teacher_name', 'remedial_attendance.status as attendance_status',
            'remedial_attendance.attendance_date', 'remedial_attendance.remarks',
        ]);
        $history = DB::table('remedial_attendance')->join('students', 'students.id', '=', 'remedial_attendance.student_id')
            ->leftJoin('users', 'users.id', '=', 'remedial_attendance.recorded_by')
            ->when($isTeacher, fn ($query) => $query->where('students.teacher_id', $request->user()->id))
            ->orderByDesc('attendance_date')->orderByDesc('remedial_attendance.id')
            ->get(['remedial_attendance.*', 'students.full_name', 'students.grade_level', 'students.section', 'users.full_name as recorded_by_name']);
        return response()->json(['sessions' => $rows, 'history' => $history]);
    }

    public function recordAttendance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'integer', 'exists:remedial_sessions,id'],
            'attendance_date' => ['required', 'date'], 'status' => ['required', 'in:Present,Absent'],
            'remarks' => ['nullable', 'string'],
        ]);
        $session = DB::table('remedial_sessions')->join('students', 'students.id', '=', 'remedial_sessions.student_id')
            ->where('remedial_sessions.id', $data['session_id'])
            ->when($request->user()->role === 'teacher', fn ($query) => $query->where('students.teacher_id', $request->user()->id))
            ->first(['remedial_sessions.id', 'remedial_sessions.student_id']);
        abort_unless($session, 403, 'That remedial session is not available for attendance recording.');
        DB::table('remedial_attendance')->updateOrInsert(
            ['session_id' => $session->id, 'attendance_date' => $data['attendance_date']],
            ['student_id' => $session->student_id, 'status' => $data['status'], 'remarks' => $data['remarks'] ?? '', 'recorded_by' => $request->user()->id],
        );
        return response()->json(['message' => 'Attendance recorded.']);
    }
}
