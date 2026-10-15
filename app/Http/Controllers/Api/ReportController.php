<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $isTeacher = $user->role === 'teacher';
        $reports = DB::table('reports')->leftJoin('users', 'users.id', '=', 'reports.generated_by')
            ->when($isTeacher, fn ($query) => $query->where('reports.generated_by', $user->id))
            ->orderByDesc('created_at')->get(['reports.*', 'users.full_name as generator_name']);
        $studentFilter = fn ($query) => $query->when($isTeacher, fn ($q) => $q->where('teacher_id', $user->id));
        $avgQuery = DB::table('students')->where('score', '>', 0);
        if ($isTeacher) $avgQuery->where('teacher_id', $user->id);
        $totalAssessedQuery = DB::table('assessments')->join('students', 'students.id', '=', 'assessments.student_id');
        if ($isTeacher) $totalAssessedQuery->where('students.teacher_id', $user->id);
        return response()->json(['data' => $reports, 'total_reports' => $reports->count(), 'average_score' => (float) ($avgQuery->avg('score') ?? 0), 'total_assessed' => $totalAssessedQuery->count()]);
    }

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate(['report_type' => ['required', 'in:activity,progress,attendance'], 'start_date' => ['required', 'date'], 'end_date' => ['required', 'date', 'after_or_equal:start_date']]);
        $user = $request->user();
        $teacher = $user->role === 'teacher';
        $start = $data['start_date']; $end = $data['end_date']; $type = $data['report_type'];
        $reportRows = []; $headers = [];

        if ($type === 'progress') {
            $query = DB::table('assessments')->join('students', 'students.id', '=', 'assessments.student_id')
                ->whereBetween('assessment_date', [$start, $end])->when($teacher, fn ($q) => $q->where('students.teacher_id', $user->id))
                ->orderByDesc('assessment_date')->orderBy('students.full_name');
            $reportRows = $query->get(['students.full_name', 'students.grade_level', 'students.section', 'assessments.tool', 'assessments.total_score', 'assessments.profile', 'assessments.assessment_date'])->map(fn ($row) => [$row->full_name, $row->grade_level.' - '.$row->section, $row->tool, $row->total_score, $row->profile, $row->assessment_date])->all();
            $headers = ['Student', 'Grade / Section', 'Assessment', 'Score', 'Reading Level', 'Date'];
        } elseif ($type === 'attendance') {
            $query = DB::table('remedial_attendance')->join('students', 'students.id', '=', 'remedial_attendance.student_id')
                ->whereBetween('attendance_date', [$start, $end])->when($teacher, fn ($q) => $q->where('students.teacher_id', $user->id))
                ->orderByDesc('attendance_date')->orderBy('students.full_name');
            $reportRows = $query->get(['students.full_name', 'students.grade_level', 'students.section', 'remedial_attendance.status', 'remedial_attendance.remarks', 'remedial_attendance.attendance_date'])->map(fn ($row) => [$row->full_name, $row->grade_level.' - '.$row->section, $row->status, $row->remarks ?: '-', $row->attendance_date])->all();
            $headers = ['Student', 'Grade / Section', 'Status', 'Remarks', 'Date'];
        } else {
            $activityDefinitions = [
                ['Assessments', 'assessments', 'assessment_date', 'recorded_by'],
                ['Progress updates', 'progress_updates', 'update_date', 'updated_by'],
                ['Attendance records', 'remedial_attendance', 'attendance_date', 'recorded_by'],
                ['Remedial sessions', 'remedial_sessions', 'session_date', 'teacher_id'],
                ['Endorsements', 'endorsements', 'created_at', 'teacher_id'],
                ['Materials distributed', 'material_distribution', 'date_given', 'given_by'],
            ];
            foreach ($activityDefinitions as [$label, $table, $dateColumn, $ownerColumn]) {
                $query = DB::table($table)->whereBetween(DB::raw($table === 'endorsements' ? 'DATE(created_at)' : $dateColumn), [$start, $end]);
                if ($teacher) $query->where($ownerColumn, $user->id);
                $reportRows[] = [$label, (int) $query->count()];
            }
            $headers = ['Activity', 'Total'];
        }

        $titles = ['activity' => 'Teacher Activity Report', 'progress' => 'Student Progress Report', 'attendance' => 'Attendance Report'];
        $title = $titles[$type]." ({$start} to {$end})";
        $escape = fn ($value) => e((string) ($value ?? '-'));
        $html = '<!doctype html><html><head><meta charset="utf-8"><title>'.$escape($title).'</title><style>body{font-family:Arial,sans-serif;color:#1e293b;margin:40px}h1{margin-bottom:4px}p{color:#64748b}table{border-collapse:collapse;width:100%;margin-top:24px}th,td{border:1px solid #cbd5e1;padding:10px;text-align:left}th{background:#e2e8f0}</style></head><body><h1>'.$escape($title).'</h1><p>Generated on '.$escape(now()->format('M d, Y h:i A')).'</p><table><thead><tr>';
        foreach ($headers as $header) $html .= '<th>'.$escape($header).'</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($reportRows as $row) { $html .= '<tr>'; foreach ($row as $value) $html .= '<td>'.$escape($value).'</td>'; $html .= '</tr>'; }
        if (!$reportRows) $html .= '<tr><td colspan="'.count($headers).'">No activity found for this period.</td></tr>';
        $html .= '</tbody></table></body></html>';

        $path = 'uploads/reports/report_'.$user->id.'_'.now()->format('YmdHis').'_'.bin2hex(random_bytes(3)).'.html';
        Storage::disk('local')->put($path, $html);
        $id = DB::table('reports')->insertGetId(['report_title' => $title, 'report_type' => $type, 'generated_by' => $user->id, 'generated_date' => now(), 'file_format' => 'HTML', 'status' => 'Ready', 'file_path' => $path, 'created_at' => now()]);
        return response()->json(['message' => 'Report generated successfully.', 'data' => DB::table('reports')->where('id', $id)->first()], 201);
    }

    public function download(Request $request, int $report)
    {
        $query = DB::table('reports')->where('id', $report);
        if ($request->user()->role === 'teacher') $query->where('generated_by', $request->user()->id);
        $row = $query->first();
        abort_unless($row, 404);
        abort_unless(Storage::disk('local')->exists($row->file_path), 404);
        return Storage::disk('local')->download($row->file_path, basename($row->file_path), ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
