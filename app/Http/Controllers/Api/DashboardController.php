<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $isTeacher = $user->role === 'teacher';
        $students = DB::table('students')->when($isTeacher, fn ($query) => $query->where('teacher_id', $user->id));
        $studentIds = (clone $students)->select('id');
        $levelCount = fn (string $profile) => (clone $students)->where('reading_profile', $profile)->count();
        $presentQuery = DB::table('remedial_attendance')->when($isTeacher, fn ($query) => $query->join('remedial_sessions', 'remedial_sessions.id', '=', 'remedial_attendance.session_id')->where('remedial_sessions.teacher_id', $user->id));
        $present = (clone $presentQuery)->where('remedial_attendance.status', 'Present')->count();
        $absent = (clone $presentQuery)->where('remedial_attendance.status', 'Absent')->count();
        $gradeDistribution = (clone $students)->select('grade_level')
            ->selectRaw("SUM(CASE WHEN reading_profile = 'Independent' THEN 1 ELSE 0 END) as independent")
            ->selectRaw("SUM(CASE WHEN reading_profile = 'Instructional' THEN 1 ELSE 0 END) as instructional")
            ->selectRaw("SUM(CASE WHEN reading_profile = 'Frustration' THEN 1 ELSE 0 END) as frustration")
            ->groupBy('grade_level')->orderBy('grade_level')->get();
        $latestAssessments = DB::table('assessments')->select('student_id')->selectRaw('MAX(assessment_date) as latest_assessment')->groupBy('student_id');
        $studentOverview = (clone $students)->leftJoinSub($latestAssessments, 'latest_assessments', fn ($join) => $join->on('students.id', '=', 'latest_assessments.student_id'))
            ->orderBy('students.full_name')->get(['students.id', 'students.full_name', 'students.lrn', 'students.grade_level', 'students.section', 'students.reading_profile', 'students.score', 'latest_assessments.latest_assessment']);
        $recentProgress = DB::table('progress_updates')->join('students', 'students.id', '=', 'progress_updates.student_id')
            ->leftJoin('users', 'users.id', '=', 'progress_updates.updated_by')->whereIn('students.id', $studentIds)
            ->orderByDesc('progress_updates.update_date')->limit(6)
            ->get(['progress_updates.*', 'students.full_name', 'students.grade_level', 'students.section', 'users.full_name as updated_by_name']);
        $upcomingSessions = DB::table('remedial_sessions')->join('students', 'students.id', '=', 'remedial_sessions.student_id')
            ->whereIn('students.id', $studentIds)->whereDate('session_date', '>=', now()->toDateString())
            ->orderBy('session_date')->orderBy('start_time')->limit(5)
            ->get(['remedial_sessions.*', 'students.full_name', 'students.grade_level', 'students.section']);
        $materialHistory = DB::table('material_distribution')->join('learning_materials', 'learning_materials.id', '=', 'material_distribution.material_id')
            ->leftJoin('students', 'students.full_name', '=', 'material_distribution.given_to')
            ->leftJoin('users', 'users.id', '=', 'material_distribution.given_by')
            ->when($isTeacher, fn ($query) => $query->where(fn ($q) => $q->where('material_distribution.given_by', $user->id)->orWhereIn('students.id', $studentIds)))
            ->orderByDesc('material_distribution.date_given')->limit(6)
            ->get(['learning_materials.title', 'learning_materials.material_type', 'material_distribution.given_to', 'material_distribution.grade_level', 'material_distribution.date_given', 'users.full_name as given_by_name']);

        return response()->json([
            'role' => $user->role,
            'metrics' => [
                'students' => (clone $students)->count(),
                'teachers' => $isTeacher ? 0 : DB::table('users')->where('role', 'teacher')->where('status', 'active')->count(),
                'assessments' => DB::table('assessments')->whereIn('student_id', $studentIds)->count(),
                'pending_endorsements' => DB::table('endorsements')
                    ->when($isTeacher, fn ($query) => $query->where('teacher_id', $user->id))
                    ->where('status', 'Pending')->count(),
                'remedial_sessions' => DB::table('remedial_sessions')->whereIn('student_id', $studentIds)->count(),
                'independent' => $levelCount('Independent'),
                'instructional' => $levelCount('Instructional'),
                'frustration' => $levelCount('Frustration'),
                'remedial_pupils' => DB::table('remedial_sessions')->whereIn('student_id', $studentIds)->whereIn('status', ['Scheduled', 'In Progress'])->distinct('student_id')->count('student_id'),
                'present_attendance' => $present,
                'absent_attendance' => $absent,
                'attendance_rate' => ($present + $absent) > 0 ? (int) round($present / ($present + $absent) * 100) : 0,
            ],
            'recent_assessments' => DB::table('assessments')->join('students', 'students.id', '=', 'assessments.student_id')
                ->whereIn('assessments.student_id', $studentIds)
                ->orderByDesc('assessments.assessment_date')->limit(5)
                ->get(['assessments.id', 'students.full_name', 'students.grade_level', 'assessments.tool', 'assessments.total_score', 'assessments.profile', 'assessments.assessment_date']),
            'students_needing_support' => (clone $students)->whereIn('reading_profile', ['Frustration', 'Low Emerging Reader'])
                ->orderBy('full_name')->limit(5)->get(['id', 'full_name', 'grade_level', 'section', 'reading_profile', 'score']),
            'intervention_pupils' => (clone $students)->leftJoinSub($latestAssessments, 'latest_assessments', fn ($join) => $join->on('students.id', '=', 'latest_assessments.student_id'))
                ->where('students.reading_profile', 'Frustration')->orderBy('students.full_name')->limit(10)
                ->get(['students.id', 'students.full_name', 'students.grade_level', 'students.reading_profile', 'latest_assessments.latest_assessment as last_assessment']),
            'student_overview' => $studentOverview,
            'level_distribution' => [
                'independent' => $levelCount('Independent'),
                'instructional' => $levelCount('Instructional'),
                'frustration' => $levelCount('Frustration'),
            ],
            'grade_distribution' => $gradeDistribution,
            'recent_progress' => $recentProgress,
            'upcoming_sessions' => $upcomingSessions,
            'material_history' => $materialHistory,
        ]);
    }
}
