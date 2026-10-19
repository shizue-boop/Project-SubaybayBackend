<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\PaginatesApiResults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class StudentController extends Controller
{
    use PaginatesApiResults;

    public function index(Request $request): JsonResponse
    {
        if ($request->boolean('options')) {
            $options = DB::table('students')->select('id', 'full_name', 'grade_level', 'section')->orderBy('grade_level')->orderBy('section')->orderBy('full_name');
            if ($request->user()->role === 'teacher') $options->where('teacher_id', $request->user()->id);
            return response()->json(['data' => $options->get()]);
        }

        $query = DB::table('students')
            ->leftJoin('users as teachers', 'teachers.id', '=', 'students.teacher_id')
            ->select('students.id', 'students.lrn', 'students.full_name', 'students.section', 'students.grade_level', 'students.teacher_id', 'students.date_enrolled', 'students.profile_image', 'students.parent_guardian', 'students.contact_number', 'students.reading_profile', 'students.score', 'teachers.full_name as teacher_name')
            ->addSelect(['latest_assessment' => DB::table('assessments')->select('assessment_date')->whereColumn('assessments.student_id', 'students.id')->orderByDesc('assessment_date')->orderByDesc('id')->limit(1)])
            ->orderBy('students.grade_level')->orderBy('students.section')->orderBy('students.full_name');
        if ($request->user()->role === 'teacher') {
            $query->where('students.teacher_id', $request->user()->id);
        }
        if ($section = trim((string) $request->query('section', ''))) {
            if ($section !== 'All Sections') $query->where('students.section', $section);
        }
        if ($search = trim(mb_substr((string) $request->query('search', ''), 0, 100))) {
            $query->where(fn ($q) => $q->where('students.full_name', 'like', '%'.$search.'%')->orWhere('students.lrn', 'like', '%'.$search.'%'));
        }

        $payload = $this->paginateApiQuery($request, $query);
        $payload ??= ['data' => $query->get()];
        $payload['sections'] = ($request->boolean('include_options') || !$request->hasAny(['page', 'per_page']))
            ? DB::table('students')->distinct()->orderBy('section')->pluck('section')->filter()->values()
            : [];
        if (in_array($request->user()->role, ['principal', 'coordinator'], true)) {
            $payload['teachers'] = DB::table('users')->where('role', 'teacher')->where('status', 'active')->orderBy('full_name')->get(['id', 'full_name', 'section', 'grade_level']);
        }
        return response()->json($payload);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['principal', 'coordinator'], true), 403);

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:100'],
            'lrn' => ['required', 'string', 'max:12', 'unique:students,lrn'],
            'section' => ['required', 'string', 'max:50'],
            'grade_level' => ['required', 'string', 'max:20'],
            'date_enrolled' => ['nullable', 'date'],
            'parent_guardian' => ['nullable', 'string', 'max:150'],
            'contact_number' => ['nullable', 'string', 'max:40'],
            'teacher_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('role', 'teacher')->where('status', 'active')],
            'profile_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:5120'],
        ]);

        if (empty($data['teacher_id'])) {
            $data['teacher_id'] = DB::table('users')->where('section', $data['section'])
                ->where('role', 'teacher')->where('status', 'active')->value('id');
        }
        $data['date_enrolled'] = $data['date_enrolled'] ?? now()->toDateString();
        if ($request->file('profile_image')) {
            $filename = 'student_'.now()->timestamp.'_'.bin2hex(random_bytes(6)).'.'.$request->file('profile_image')->extension();
            Storage::disk('local')->putFileAs('uploads/students', $request->file('profile_image'), $filename);
            $data['profile_image'] = 'uploads/students/'.$filename;
        }
        $id = DB::table('students')->insertGetId($data);

        return response()->json(['data' => DB::table('students')->where('id', $id)->first()], 201);
    }

    public function show(Request $request, int $student): JsonResponse
    {
        $query = DB::table('students')->where('id', $student);
        if ($request->user()->role === 'teacher') $query->where('teacher_id', $request->user()->id);
        $record = $query->first();
        abort_unless($record, 404);

        return response()->json(['data' => $record]);
    }

    public function details(Request $request, int $student): JsonResponse
    {
        $record = DB::table('students')->leftJoin('users as teachers', 'teachers.id', '=', 'students.teacher_id')
            ->where('students.id', $student)
            ->when($request->user()->role === 'teacher', fn ($query) => $query->where('students.teacher_id', $request->user()->id))
            ->first(['students.*', 'teachers.full_name as teacher_name']);
        abort_unless($record, 404);
        return response()->json([
            'student' => $record,
            'assessments' => DB::table('assessments')->where('student_id', $student)->orderByDesc('assessment_date')->get(),
            'progress' => DB::table('progress_updates')->where('student_id', $student)->orderByDesc('update_date')->get(),
            'endorsements' => DB::table('endorsements')->where('student_id', $student)->orderByDesc('created_at')->get(),
            'sessions' => DB::table('remedial_sessions')->where('student_id', $student)->orderByDesc('session_date')->get(),
        ]);
    }

    public function update(Request $request, int $student): JsonResponse
    {
        abort_unless($request->user()->role === 'coordinator', 403, 'Only the reading coordinator can edit student profiles.');
        $record = DB::table('students')->where('id', $student)->first();
        abort_unless($record, 404);

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:100'],
            'lrn' => ['required', 'string', 'max:12', Rule::unique('students', 'lrn')->ignore($student)],
            'section' => ['required', 'string', 'max:50'],
            'grade_level' => ['required', 'string', 'max:20'],
            'date_enrolled' => ['nullable', 'date'],
            'parent_guardian' => ['nullable', 'string', 'max:150'],
            'contact_number' => ['nullable', 'string', 'max:40'],
            'teacher_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'teacher')->where('status', 'active')],
        ]);
        DB::table('students')->where('id', $student)->update($data);

        return response()->json(['data' => DB::table('students')->where('id', $student)->first()]);
    }

    public function destroy(Request $request, int $student): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['principal', 'coordinator'], true), 403);
        $deleted = DB::table('students')->where('id', $student)->delete();
        abort_unless($deleted, 404);

        return response()->json(['message' => 'Student removed.']);
    }

    public function image(Request $request, int $student)
    {
        $record = DB::table('students')->where('id', $student)->first(['teacher_id', 'profile_image']);
        abort_unless($record && $record->profile_image, 404);
        abort_unless($request->user()->role !== 'teacher' || (int) $record->teacher_id === (int) $request->user()->id, 403);
        if (Storage::disk('local')->exists($record->profile_image)) return response()->file(Storage::disk('local')->path($record->profile_image));
        $legacy = dirname(__DIR__, 5).DIRECTORY_SEPARATOR.'frontend'.DIRECTORY_SEPARATOR.'legacy-php'.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $record->profile_image);
        abort_unless(is_file($legacy), 404);
        return response()->file($legacy);
    }
}
