<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\PaginatesApiResults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AcademicController extends Controller
{
    use PaginatesApiResults;

    public function assessments(Request $request): JsonResponse
    {
        $query = DB::table('assessments')->join('students', 'students.id', '=', 'assessments.student_id')
            ->leftJoin('users', 'users.id', '=', 'assessments.recorded_by');
        if ($request->user()->role === 'teacher') $query->where('students.teacher_id', $request->user()->id);
        if ($search = trim(mb_substr((string) $request->query('search', ''), 0, 100))) {
            $query->where(fn ($q) => $q->where('students.full_name', 'like', '%'.$search.'%')->orWhere('students.grade_level', 'like', '%'.$search.'%')->orWhere('assessments.tool', 'like', '%'.$search.'%')->orWhere('assessments.profile', 'like', '%'.$search.'%'));
        }
        $query->orderByDesc('assessment_date')->orderByDesc('assessments.id');
        $columns = ['assessments.*', 'students.full_name', 'students.grade_level', 'students.section', 'users.full_name as recorded_by_name'];
        $page = $this->paginateApiQuery($request, $query, $columns);
        return response()->json($page ?? ['data' => $query->get($columns)]);
    }

    public function recordAssessment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'tool' => ['required', Rule::in(['CRLA-M', 'Phil-IRI'])],
            'task1_score' => ['required', 'integer', 'min:0'],
            'words_score' => ['required', 'integer', 'min:0'],
            'sentences_score' => ['required', 'integer', 'min:0'],
            'profile' => ['required', Rule::in(['Grade Ready', 'Light Refresher', 'Moderate Refresher', 'Full Refresher', 'Independent', 'Instructional', 'Frustration'])],
            'assessment_date' => ['required', 'date'],
        ]);
        $student = DB::table('students')->where('id', $data['student_id'])->first();
        abort_unless($request->user()->role !== 'teacher' || (int) $student->teacher_id === (int) $request->user()->id, 403);
        $data['total_score'] = $data['task1_score'] + $data['words_score'] + $data['sentences_score'];
        $data['recorded_by'] = $request->user()->id;

        $assessmentId = DB::transaction(function () use ($data) {
            $id = DB::table('assessments')->insertGetId($data);
            DB::table('students')->where('id', $data['student_id'])->update([
                'reading_profile' => $data['profile'], 'score' => $data['total_score'],
            ]);
            return $id;
        });
        return response()->json(['data' => DB::table('assessments')->where('id', $assessmentId)->first()], 201);
    }

    public function progress(Request $request): JsonResponse
    {
        $query = DB::table('progress_updates')->join('students', 'students.id', '=', 'progress_updates.student_id')
            ->leftJoin('users', 'users.id', '=', 'progress_updates.updated_by');
        if ($request->user()->role === 'teacher') $query->where('students.teacher_id', $request->user()->id);
        if ($search = trim(mb_substr((string) $request->query('search', ''), 0, 100))) {
            $query->where(fn ($q) => $q->where('students.full_name', 'like', '%'.$search.'%')->orWhere('students.grade_level', 'like', '%'.$search.'%')->orWhere('progress_updates.current_profile', 'like', '%'.$search.'%')->orWhere('progress_updates.trend', 'like', '%'.$search.'%'));
        }
        $query->orderByDesc('update_date')->orderByDesc('progress_updates.id');
        $columns = ['progress_updates.*', 'students.full_name', 'students.grade_level', 'students.section', 'users.full_name as updated_by_name'];
        $page = $this->paginateApiQuery($request, $query, $columns);
        return response()->json($page ?? ['data' => $query->get($columns)]);
    }

    public function addProgress(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'previous_profile' => ['required', 'string', 'max:50'],
            'current_profile' => ['required', 'string', 'max:50'],
            'trend' => ['required', 'in:improved,no_change,declined'],
            'update_date' => ['required', 'date'],
            'remarks' => ['nullable', 'string'],
        ]);
        $student = DB::table('students')->where('id', $data['student_id'])->first();
        abort_unless($request->user()->role !== 'teacher' || (int) $student->teacher_id === (int) $request->user()->id, 403);
        $data['updated_by'] = $request->user()->id;
        $id = DB::table('progress_updates')->insertGetId($data);
        DB::table('students')->where('id', $student->id)->update(['reading_profile' => $data['current_profile']]);
        return response()->json(['data' => DB::table('progress_updates')->where('id', $id)->first()], 201);
    }

    public function categories(): JsonResponse
    {
        return response()->json(['data' => DB::table('grade_categories')->orderBy('grade_level')->orderBy('category_name')->get()]);
    }

    public function saveCategory(Request $request, ?int $category = null): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['principal', 'coordinator'], true), 403);
        $data = $request->validate([
            'grade_level' => ['required', 'string', 'max:20'],
            'assessment_type' => ['nullable', 'string', 'max:100'],
            'category_name' => ['required', Rule::in(['Full Refresher', 'Moderate Refresher', 'Light Refresher', 'Grade Ready'])],
            'score_range' => ['required', 'string', 'max:50'],
            'recommended_action' => ['required', 'string'],
            'min_score' => ['nullable', 'integer', 'min:0'],
            'max_score' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string'],
        ]);
        $data['min_score'] = $data['min_score'] ?? 0;
        $data['max_score'] = $data['max_score'] ?? 100;
        if ($category) {
            abort_unless(DB::table('grade_categories')->where('id', $category)->exists(), 404);
            DB::table('grade_categories')->where('id', $category)->update($data);
            return response()->json(['data' => DB::table('grade_categories')->where('id', $category)->first()]);
        }
        $id = DB::table('grade_categories')->insertGetId($data);
        return response()->json(['data' => DB::table('grade_categories')->where('id', $id)->first()], 201);
    }

    public function deleteCategory(Request $request, int $category): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['principal', 'coordinator'], true), 403);
        abort_unless(DB::table('grade_categories')->where('id', $category)->delete(), 404);
        return response()->json(['message' => 'Category removed.']);
    }

    public function schedule(Request $request): JsonResponse
    {
        $query = DB::table('schedule_events')->leftJoin('users', 'users.id', '=', 'schedule_events.teacher_id');
        if ($request->user()->role === 'teacher') $query->where('schedule_events.teacher_id', $request->user()->id);
        $range = $request->validate([
            'from' => ['required_with:to', 'date'],
            'to' => ['required_with:from', 'date', 'after_or_equal:from'],
        ]);
        if (isset($range['from'], $range['to'])) {
            $query->whereBetween('schedule_events.event_date', [$range['from'], $range['to']]);
        }
        $columns = isset($range['from'], $range['to'])
            ? ['schedule_events.id', 'schedule_events.title', 'schedule_events.event_type', 'schedule_events.grade_level', 'schedule_events.section', 'schedule_events.event_date', 'schedule_events.start_time', 'schedule_events.end_time', 'users.full_name as teacher_name']
            : ['schedule_events.*', 'users.full_name as teacher_name'];

        return response()->json(['data' => $query->orderBy('event_date')->orderBy('start_time')->get($columns)]);
    }

    public function addSchedule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'], 'event_type' => ['nullable', Rule::in(['Remedial', 'Group', 'Assessment', 'Review', 'Meeting'])],
            'grade_level' => ['nullable', 'string', 'max:20'], 'section' => ['nullable', 'string', 'max:50'],
            'event_date' => ['required', 'date'], 'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'], 'teacher_id' => ['nullable', 'integer', 'exists:users,id'],
            'description' => ['nullable', 'string'],
        ]);
        if ($request->user()->role === 'teacher') $data['teacher_id'] = $request->user()->id;
        else abort_unless(in_array($request->user()->role, ['principal', 'coordinator'], true), 403);
        $id = DB::table('schedule_events')->insertGetId($data);
        return response()->json(['data' => DB::table('schedule_events')->where('id', $id)->first()], 201);
    }
}
