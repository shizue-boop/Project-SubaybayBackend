<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettingsController extends Controller
{
    private const KEYS = ['school_name', 'school_id', 'school_address', 'school_contact', 'school_email', 'school_logo', 'school_year', 'program_name', 'grade_levels_covered', 'assessment_information'];

    public function show(): JsonResponse
    {
        return response()->json(['settings' => DB::table('app_settings')->pluck('setting_value', 'setting_key')]);
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['principal', 'coordinator'], true), 403);
        $rules = array_fill_keys(self::KEYS, ['nullable', 'string', 'max:5000']);
        $data = $request->validate($rules);
        DB::transaction(function () use ($data, $request) {
            foreach (self::KEYS as $key) {
                DB::table('app_settings')->updateOrInsert(
                    ['setting_key' => $key],
                    ['setting_value' => $data[$key] ?? '', 'updated_by' => $request->user()->id, 'updated_at' => now()],
                );
            }
        });
        return response()->json(['message' => 'School and reading program settings saved.']);
    }
}
