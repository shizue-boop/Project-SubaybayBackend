<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MaterialController extends Controller
{
    public function index(): JsonResponse
    {
        $data = DB::table('learning_materials')->leftJoin('users', 'users.id', '=', 'learning_materials.uploaded_by')
            ->orderByDesc('upload_date')->get(['learning_materials.*', 'users.full_name as uploader_name']);
        return response()->json(['data' => $data]);
    }

    public function upload(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['principal', 'coordinator'], true), 403);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string'],
            'material_type' => ['required', 'string', 'max:80'], 'grade_level' => ['required', 'string', 'max:20'],
            'reading_level' => ['nullable', 'string', 'max:50'], 'language' => ['required', 'string', 'max:80'],
            'alignment_part' => ['required', 'string', 'max:80'], 'skill_focus' => ['required', 'string', 'max:80'],
            'file' => ['required', 'file', 'mimes:pdf,doc,docx,mp3,ppt,pptx,xls,xlsx', 'max:20480'],
        ]);
        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        $formats = ['pdf' => 'PDF', 'doc' => 'DOCX', 'docx' => 'DOCX', 'mp3' => 'MP3', 'ppt' => 'PPT', 'pptx' => 'PPT', 'xls' => 'XLSX', 'xlsx' => 'XLSX'];
        abort_unless(isset($formats[$extension]), 422, 'Unsupported file type.');
        $name = 'material_'.$request->user()->id.'_'.bin2hex(random_bytes(8)).'.'.$extension;
        $relativePath = 'uploads/materials/'.$name;
        Storage::disk('local')->putFileAs('uploads/materials', $file, $name);
        $id = DB::table('learning_materials')->insertGetId([
            'title' => $data['title'], 'description' => $data['description'] ?? null,
            'material_type' => $data['material_type'], 'grade_level' => $data['grade_level'],
            'reading_level' => $data['reading_level'] ?? null, 'language' => $data['language'],
            'alignment_part' => $data['alignment_part'], 'skill_focus' => $data['skill_focus'],
            'file_path' => $relativePath, 'file_format' => $formats[$extension],
            'file_size' => number_format($file->getSize() / 1048576, 2).' MB',
            'uploaded_by' => $request->user()->id, 'upload_date' => now(),
        ]);
        return response()->json(['data' => DB::table('learning_materials')->where('id', $id)->first()], 201);
    }

    public function update(Request $request, int $material): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['principal', 'coordinator'], true), 403);
        abort_unless(DB::table('learning_materials')->where('id', $material)->exists(), 404);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string'],
            'material_type' => ['required', 'string', 'max:80'], 'grade_level' => ['required', 'string', 'max:20'],
            'reading_level' => ['nullable', 'string', 'max:50'], 'language' => ['required', 'string', 'max:80'],
            'alignment_part' => ['required', 'string', 'max:80'], 'skill_focus' => ['required', 'string', 'max:80'],
        ]);
        DB::table('learning_materials')->where('id', $material)->update($data);
        return response()->json(['data' => DB::table('learning_materials')->where('id', $material)->first()]);
    }

    public function destroy(Request $request, int $material): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['principal', 'coordinator'], true), 403);
        $row = DB::table('learning_materials')->where('id', $material)->first();
        abort_unless($row, 404);
        $path = $row->file_path;
        DB::table('learning_materials')->where('id', $material)->delete();
        if ($path && Storage::disk('local')->exists($path)) Storage::disk('local')->delete($path);
        return response()->json(['message' => 'Material removed.']);
    }

    public function file(Request $request, int $material)
    {
        $row = DB::table('learning_materials')->where('id', $material)->first();
        abort_unless($row && $row->file_path, 404);
        $path = $row->file_path;
        if (Storage::disk('local')->exists($path)) {
            return $request->boolean('view')
                ? response()->file(Storage::disk('local')->path($path))
                : Storage::disk('local')->download($path, basename($path));
        }

        // Existing seeded PDF references stay readable from their original repository path.
        $legacyRoot = dirname(__DIR__, 5).DIRECTORY_SEPARATOR.'frontend'.DIRECTORY_SEPARATOR.'legacy-php';
        $legacyPath = $legacyRoot.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        abort_unless(is_file($legacyPath) && str_starts_with(realpath($legacyPath), realpath($legacyRoot.DIRECTORY_SEPARATOR.'uploads')), 404);
        return $request->boolean('view') ? response()->file($legacyPath) : response()->download($legacyPath, basename($legacyPath));
    }
}
