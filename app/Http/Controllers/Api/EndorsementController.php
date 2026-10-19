<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\PaginatesApiResults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class EndorsementController extends Controller
{
    use PaginatesApiResults;

    public function show(Request $request, int $endorsement): JsonResponse
    {
        $reviewer = in_array($request->user()->role, ['principal', 'coordinator'], true);
        $query = DB::table('endorsements')->join('students', 'students.id', '=', 'endorsements.student_id')
            ->join('users as teachers', 'teachers.id', '=', 'endorsements.teacher_id')
            ->leftJoin('users as reviewers', 'reviewers.id', '=', 'endorsements.reviewed_by')
            ->where('endorsements.id', $endorsement)
            ->when(!$reviewer, fn ($q) => $q->where('endorsements.teacher_id', $request->user()->id));
        $record = $query->first([
            'endorsements.*', 'students.full_name', 'students.lrn', 'students.grade_level', 'students.section',
            'teachers.full_name as teacher_name', 'reviewers.full_name as reviewer_name',
        ]);
        abort_unless($record, 404);
        return response()->json(['data' => $record, 'reviewer' => $reviewer]);
    }

    public function document(Request $request, int $endorsement)
    {
        $record = DB::table('endorsements')->where('id', $endorsement)->first(['teacher_id', 'supporting_document']);
        abort_unless($record, 404);
        $reviewer = in_array($request->user()->role, ['principal', 'coordinator'], true);
        abort_unless($reviewer || (int) $record->teacher_id === (int) $request->user()->id, 403);
        abort_unless($record->supporting_document && Storage::disk('local')->exists($record->supporting_document), 404);
        return Storage::disk('local')->download($record->supporting_document, basename($record->supporting_document));
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $reviewer = in_array($user->role, ['principal', 'coordinator'], true);
        $query = DB::table('endorsements')->join('students', 'students.id', '=', 'endorsements.student_id')
            ->join('users as teachers', 'teachers.id', '=', 'endorsements.teacher_id')
            ->when(!$reviewer, fn ($q) => $q->where('endorsements.teacher_id', $user->id))
            ->when($request->query('status') && $request->query('status') !== 'All', fn ($q) => $q->where('endorsements.status', $request->query('status')))
            ->orderByRaw("FIELD(endorsements.status, 'Pending', 'Returned for Revision', 'Approved', 'Rejected')")
            ->orderByDesc('endorsements.created_at');
        if ($search = trim(mb_substr((string) $request->query('search', ''), 0, 100))) {
            $query->where(fn ($q) => $q->where('students.full_name', 'like', '%'.$search.'%')->orWhere('students.grade_level', 'like', '%'.$search.'%')->orWhere('teachers.full_name', 'like', '%'.$search.'%')->orWhere('endorsements.assessment_tool', 'like', '%'.$search.'%')->orWhere('endorsements.reading_level', 'like', '%'.$search.'%'));
        }
        $columns = ['endorsements.*', 'students.full_name', 'students.grade_level', 'students.section', 'teachers.full_name as teacher_name'];
        $page = $this->paginateApiQuery($request, $query, $columns);
        $data = $page['data'] ?? $query->get($columns);
        $counts = DB::table('endorsements')->when(!$reviewer, fn ($q) => $q->where('teacher_id', $user->id))
            ->select('status', DB::raw('COUNT(*) as total'))->groupBy('status')->pluck('total', 'status');
        $counts = ['All' => $counts->sum(), 'Pending' => (int) ($counts['Pending'] ?? 0), 'Approved' => (int) ($counts['Approved'] ?? 0), 'Returned for Revision' => (int) ($counts['Returned for Revision'] ?? 0), 'Rejected' => (int) ($counts['Rejected'] ?? 0)];
        $students = $reviewer ? [] : DB::table('students')->where('teacher_id', $user->id)->orderBy('full_name')->get(['id', 'full_name', 'grade_level', 'section']);
        return response()->json(array_merge(['data' => $data, 'counts' => $counts, 'students' => $students, 'reviewer' => $reviewer], $page ? ['pagination' => $page['pagination']] : []));
    }

    public function submit(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'teacher', 403);
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'], 'assessment_tool' => ['required', 'string', 'max:80'],
            'assessment_date' => ['nullable', 'date'], 'assessment_result' => ['required', 'string', 'max:255'],
            'reading_level' => ['required', 'string', 'max:50'], 'reading_difficulty' => ['nullable', 'string'],
            'teacher_remarks' => ['nullable', 'string'],
            'supporting_document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);
        $student = DB::table('students')->where('id', $data['student_id'])->where('teacher_id', $request->user()->id)->first();
        abort_unless($student, 403, 'Select one of your assigned pupils.');
        $path = $request->file('supporting_document')?->store('endorsements', 'local');
        $id = DB::transaction(function () use ($data, $request, $path) {
            $id = DB::table('endorsements')->insertGetId([
                'student_id' => $data['student_id'], 'teacher_id' => $request->user()->id,
                'assessment_tool' => $data['assessment_tool'], 'assessment_date' => $data['assessment_date'] ?? null,
                'assessment_result' => $data['assessment_result'], 'reading_level' => $data['reading_level'],
                'reading_difficulty' => $data['reading_difficulty'] ?? '', 'teacher_remarks' => $data['teacher_remarks'] ?? '',
                'supporting_document' => $path,
            ]);
            DB::table('endorsement_history')->insert(['endorsement_id' => $id, 'status' => 'Pending', 'changed_by' => $request->user()->id, 'remarks' => 'Submitted by teacher', 'created_at' => now()]);
            return $id;
        });
        return response()->json(['data' => DB::table('endorsements')->where('id', $id)->first()], 201);
    }

    public function review(Request $request, int $endorsement): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['principal', 'coordinator'], true), 403);
        $data = $request->validate(['status' => ['required', 'in:Approved,Returned for Revision,Rejected'], 'review_remarks' => ['nullable', 'string']]);
        abort_unless(DB::table('endorsements')->where('id', $endorsement)->exists(), 404);
        DB::transaction(function () use ($data, $request, $endorsement) {
            DB::table('endorsements')->where('id', $endorsement)->update(['status' => $data['status'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
            DB::table('endorsement_history')->insert(['endorsement_id' => $endorsement, 'status' => $data['status'], 'changed_by' => $request->user()->id, 'remarks' => $data['review_remarks'] ?? '', 'created_at' => now()]);
        });
        return response()->json(['message' => 'Endorsement status updated.']);
    }

    public function history(Request $request, int $endorsement): JsonResponse
    {
        $record = DB::table('endorsements')->where('id', $endorsement)->first();
        abort_unless($record, 404);
        $reviewer = in_array($request->user()->role, ['principal', 'coordinator'], true);
        abort_unless($reviewer || (int) $record->teacher_id === (int) $request->user()->id, 403);
        return response()->json(['data' => DB::table('endorsement_history')->join('users', 'users.id', '=', 'endorsement_history.changed_by')
            ->where('endorsement_history.endorsement_id', $endorsement)->orderBy('endorsement_history.created_at')
            ->get(['endorsement_history.*', 'users.full_name as changed_by_name'])]);
    }
}
