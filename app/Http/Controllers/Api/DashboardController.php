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
        $studentMetricsQuery = (clone $students)
            ->selectRaw('COUNT(*) as students')
            ->selectRaw("COALESCE(SUM(CASE WHEN reading_profile = 'Independent' THEN 1 ELSE 0 END), 0) as independent")
            ->selectRaw("COALESCE(SUM(CASE WHEN reading_profile = 'Instructional' THEN 1 ELSE 0 END), 0) as instructional")
            ->selectRaw("COALESCE(SUM(CASE WHEN reading_profile = 'Frustration' THEN 1 ELSE 0 END), 0) as frustration")
            ->selectSub(
                DB::table('assessments')->whereIn('student_id', $studentIds)->selectRaw('COUNT(*)'),
                'assessments'
            )
            ->selectSub(
                DB::table('endorsements')
                    ->when($isTeacher, fn ($query) => $query->where('teacher_id', $user->id))
                    ->where('status', 'Pending')->selectRaw('COUNT(*)'),
                'pending_endorsements'
            )
            ->selectSub(
                DB::table('remedial_sessions')->whereIn('student_id', $studentIds)->selectRaw('COUNT(*)'),
                'remedial_sessions'
            )
            ->selectSub(
                DB::table('remedial_sessions')->whereIn('student_id', $studentIds)
                    ->whereIn('status', ['Scheduled', 'In Progress'])->selectRaw('COUNT(DISTINCT student_id)'),
                'remedial_pupils'
            );
        if (!$isTeacher) {
            $studentMetricsQuery->selectSub(
                DB::table('users')->where('role', 'teacher')->where('status', 'active')->selectRaw('COUNT(*)'),
                'teachers'
            );
        }
        $studentMetrics = $studentMetricsQuery->first();
        $presentQuery = DB::table('remedial_attendance')->when($isTeacher, fn ($query) => $query->join('remedial_sessions', 'remedial_sessions.id', '=', 'remedial_attendance.session_id')->where('remedial_sessions.teacher_id', $user->id));
        $attendanceSummary = (clone $presentQuery)
            ->selectRaw("COALESCE(SUM(CASE WHEN remedial_attendance.status = 'Present' THEN 1 ELSE 0 END), 0) as present")
            ->selectRaw("COALESCE(SUM(CASE WHEN remedial_attendance.status = 'Absent' THEN 1 ELSE 0 END), 0) as absent")
            ->first();
        $present = (int) $attendanceSummary->present;
        $absent = (int) $attendanceSummary->absent;
        $studentCount = (int) $studentMetrics->students;
        $independentCount = (int) $studentMetrics->independent;
        $instructionalCount = (int) $studentMetrics->instructional;
        $frustrationCount = (int) $studentMetrics->frustration;
        $gradeDistribution = (clone $students)->select('grade_level')
            ->selectRaw("SUM(CASE WHEN reading_profile = 'Independent' THEN 1 ELSE 0 END) as independent")
            ->selectRaw("SUM(CASE WHEN reading_profile = 'Instructional' THEN 1 ELSE 0 END) as instructional")
            ->selectRaw("SUM(CASE WHEN reading_profile = 'Frustration' THEN 1 ELSE 0 END) as frustration")
            ->groupBy('grade_level')->orderBy('grade_level')->get();
        $recentProgress = DB::table('progress_updates')->join('students', 'students.id', '=', 'progress_updates.student_id')
            ->leftJoin('users', 'users.id', '=', 'progress_updates.updated_by')->whereIn('students.id', $studentIds)
            ->orderByDesc('progress_updates.update_date')->limit(6)
            ->get(['progress_updates.*', 'students.full_name', 'students.grade_level', 'students.section', 'users.full_name as updated_by_name']);
        $upcomingSessions = DB::table('remedial_sessions')->join('students', 'students.id', '=', 'remedial_sessions.student_id')
            ->whereIn('students.id', $studentIds)->where('session_date', '>=', now()->toDateString())
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
                'students' => $studentCount,
                'teachers' => $isTeacher ? 0 : (int) $studentMetrics->teachers,
                'assessments' => (int) $studentMetrics->assessments,
                'pending_endorsements' => (int) $studentMetrics->pending_endorsements,
                'remedial_sessions' => (int) $studentMetrics->remedial_sessions,
                'independent' => $independentCount,
                'instructional' => $instructionalCount,
                'frustration' => $frustrationCount,
                'remedial_pupils' => (int) $studentMetrics->remedial_pupils,
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
            'intervention_pupils' => (clone $students)->where('students.reading_profile', 'Frustration')->orderBy('students.full_name')->limit(10)
                ->select('students.id', 'students.full_name', 'students.grade_level', 'students.reading_profile')
                ->addSelect(['last_assessment' => DB::table('assessments')->select('assessment_date')
                    ->whereColumn('assessments.student_id', 'students.id')->orderByDesc('assessment_date')->orderByDesc('id')->limit(1)])
                ->get(),
            'student_overview' => [],
            'level_distribution' => [
                'independent' => $independentCount,
                'instructional' => $instructionalCount,
                'frustration' => $frustrationCount,
            ],
            'grade_distribution' => $gradeDistribution,
            'recent_progress' => $recentProgress,
            'upcoming_sessions' => $upcomingSessions,
            'material_history' => $materialHistory,
        ]);
    }
}
