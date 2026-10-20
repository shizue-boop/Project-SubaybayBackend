<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SubaybayApiTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'teacher', string $status = 'active', ?int $id = null): User
    {
        return User::create([
            'full_name' => ucfirst($role).' Test', 'email' => $role.($id ?? '').'@example.test',
            'password' => Hash::make('CorrectPass1'), 'role' => $role, 'status' => $status,
            'section' => 'Rizal', 'grade_level' => 'Grade 2', 'joined_date' => now()->toDateString(),
        ]);
    }

    public function test_active_user_can_login_and_use_authenticated_profile(): void
    {
        $user = $this->user();
        $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'CorrectPass1']);
        $response->assertOk()->assertJsonPath('user.role', 'teacher')->assertJsonStructure(['token', 'user' => ['id', 'full_name', 'email', 'role']]);

        $this->withToken($response->json('token'))->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.id', $user->id);
    }

    public function test_inactive_account_cannot_login(): void
    {
        $user = $this->user('teacher', 'inactive');
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'CorrectPass1'])
            ->assertForbidden()->assertJsonPath('message', 'Your account is pending approval or has been deactivated. Please contact your school administrator.');
    }

    public function test_registration_keeps_new_accounts_pending_approval(): void
    {
        $this->postJson('/api/auth/register', [
            'role' => 'teacher', 'first_name' => 'Ava', 'last_name' => 'Rivera', 'employee_id' => 'EMP-1',
            'grade_level' => 'Grade 2', 'section' => 'Rizal', 'email' => 'ava@example.test',
            'password' => 'StrongPass1', 'password_confirmation' => 'StrongPass1',
        ])->assertCreated()->assertJsonPath('message', 'Registration submitted successfully. Your account is pending approval.');
        $this->assertDatabaseHas('users', ['email' => 'ava@example.test', 'status' => 'inactive', 'approval_status' => 'pending', 'role' => 'teacher']);
    }

    public function test_only_coordinator_can_approve_a_registered_account(): void
    {
        $this->postJson('/api/auth/register', [
            'role' => 'teacher', 'first_name' => 'Ava', 'last_name' => 'Rivera', 'employee_id' => 'EMP-APPROVE',
            'grade_level' => 'Grade 2', 'section' => 'Rizal', 'email' => 'approve@example.test',
            'password' => 'StrongPass1', 'password_confirmation' => 'StrongPass1',
        ])->assertCreated();
        $registered = User::where('email', 'approve@example.test')->firstOrFail();

        $this->actingAs($this->user('principal'), 'sanctum')
            ->postJson("/api/account-approvals/{$registered->id}/approve")
            ->assertForbidden();

        $coordinator = $this->user('coordinator');
        $this->actingAs($coordinator, 'sanctum')->getJson('/api/teachers')
            ->assertOk()->assertJsonPath('pending_registrations.0.email', 'approve@example.test');
        $this->actingAs($coordinator, 'sanctum')
            ->postJson("/api/account-approvals/{$registered->id}/approve")
            ->assertOk()->assertJsonPath('message', 'Registration approved. The account can now sign in.');

        $this->assertDatabaseHas('users', ['id' => $registered->id, 'status' => 'active', 'approval_status' => 'approved']);
        $this->postJson('/api/auth/login', ['email' => 'approve@example.test', 'password' => 'StrongPass1'])->assertOk();
    }

    public function test_coordinator_can_reject_pending_registration(): void
    {
        $pending = User::create([
            'full_name' => 'Pending Teacher', 'email' => 'pending@example.test', 'password' => Hash::make('CorrectPass1'),
            'role' => 'teacher', 'status' => 'inactive', 'approval_status' => 'pending', 'joined_date' => now()->toDateString(),
        ]);

        $this->actingAs($this->user('coordinator'), 'sanctum')
            ->postJson("/api/account-approvals/{$pending->id}/reject")
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $pending->id, 'status' => 'inactive', 'approval_status' => 'rejected']);
        $this->postJson('/api/auth/login', ['email' => $pending->email, 'password' => 'CorrectPass1'])->assertForbidden();
    }

    public function test_teacher_only_sees_assigned_students_and_cannot_enroll(): void
    {
        $teacher = $this->user();
        $other = $this->user('teacher', 'active', 2);
        $ownedId = \Illuminate\Support\Facades\DB::table('students')->insertGetId(['lrn' => 'L-1', 'full_name' => 'Owned Pupil', 'section' => 'Rizal', 'grade_level' => 'Grade 2', 'teacher_id' => $teacher->id, 'score' => 12]);
        \Illuminate\Support\Facades\DB::table('students')->insert(['lrn' => 'L-2', 'full_name' => 'Other Pupil', 'section' => 'Rizal', 'grade_level' => 'Grade 2', 'teacher_id' => $other->id, 'score' => 20]);

        $this->actingAs($teacher, 'sanctum')->getJson('/api/students')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ownedId);
        $this->actingAs($teacher, 'sanctum')->getJson('/api/students?options=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ownedId)->assertJsonMissingPath('data.0.lrn');
        $this->actingAs($teacher, 'sanctum')->postJson('/api/students', ['full_name' => 'Unauthorized', 'lrn' => 'L-3', 'section' => 'A', 'grade_level' => 'Grade 1'])->assertForbidden();
    }

    public function test_student_search_and_pagination_keep_role_scoping_and_latest_assessment(): void
    {
        $teacher = $this->user();
        $other = $this->user('teacher', 'active', 2);
        $ownedId = \Illuminate\Support\Facades\DB::table('students')->insertGetId([
            'lrn' => 'PAGE-1', 'full_name' => 'Searchable Pupil', 'section' => 'Rizal', 'grade_level' => 'Grade 2', 'teacher_id' => $teacher->id, 'score' => 12,
        ]);
        \Illuminate\Support\Facades\DB::table('students')->insert([
            'lrn' => 'PAGE-2', 'full_name' => 'Other Searchable Pupil', 'section' => 'Rizal', 'grade_level' => 'Grade 2', 'teacher_id' => $other->id, 'score' => 20,
        ]);
        \Illuminate\Support\Facades\DB::table('assessments')->insert([
            ['student_id' => $ownedId, 'tool' => 'CRLA-M', 'profile' => 'Instructional', 'assessment_date' => '2026-08-01'],
            ['student_id' => $ownedId, 'tool' => 'CRLA-M', 'profile' => 'Independent', 'assessment_date' => '2026-09-15'],
        ]);

        $this->actingAs($teacher, 'sanctum')->getJson('/api/students?search=Searchable&per_page=1&page=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.id', $ownedId)->assertJsonPath('data.0.latest_assessment', '2026-09-15');
    }

    public function test_assessment_list_supports_search_and_page_metadata(): void
    {
        $teacher = $this->user();
        $studentId = \Illuminate\Support\Facades\DB::table('students')->insertGetId([
            'lrn' => 'ASSESS-PAGE', 'full_name' => 'Assessment Pupil', 'section' => 'Rizal', 'grade_level' => 'Grade 2', 'teacher_id' => $teacher->id, 'score' => 20,
        ]);
        \Illuminate\Support\Facades\DB::table('assessments')->insert([
            ['student_id' => $studentId, 'tool' => 'CRLA-M', 'profile' => 'Independent', 'assessment_date' => '2026-08-01'],
            ['student_id' => $studentId, 'tool' => 'Phil-IRI', 'profile' => 'Instructional', 'assessment_date' => '2026-09-01'],
        ]);

        $this->actingAs($teacher, 'sanctum')->getJson('/api/assessments?search=Phil-IRI&per_page=1&page=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.tool', 'Phil-IRI');
    }

    public function test_schedule_can_fetch_only_the_visible_week_with_teacher_scope(): void
    {
        $teacher = $this->user();
        $other = $this->user('teacher', 'active', 2);
        $visibleId = \Illuminate\Support\Facades\DB::table('schedule_events')->insertGetId([
            'title' => 'Visible session', 'event_date' => '2026-09-02', 'start_time' => '09:00:00', 'teacher_id' => $teacher->id,
        ]);
        \Illuminate\Support\Facades\DB::table('schedule_events')->insert([
            ['title' => 'Future session', 'event_date' => '2026-09-09', 'start_time' => '09:00:00', 'teacher_id' => $teacher->id],
            ['title' => 'Other teacher session', 'event_date' => '2026-09-03', 'start_time' => '09:00:00', 'teacher_id' => $other->id],
        ]);

        $this->actingAs($teacher, 'sanctum')->getJson('/api/schedule?from=2026-09-01&to=2026-09-05')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $visibleId)
            ->assertJsonMissingPath('data.0.description');
    }

    public function test_material_detail_loads_one_record_instead_of_the_full_listing(): void
    {
        $teacher = $this->user();
        $materialId = \Illuminate\Support\Facades\DB::table('learning_materials')->insertGetId([
            'title' => 'Single material', 'material_type' => 'Reading Passage', 'grade_level' => 'Grade 2',
            'description' => 'Material details', 'uploaded_by' => $teacher->id,
        ]);

        $this->actingAs($teacher, 'sanctum')->getJson('/api/materials/'.$materialId)
            ->assertOk()->assertJsonPath('data.id', $materialId)->assertJsonPath('data.title', 'Single material')
            ->assertJsonPath('data.uploader_name', $teacher->full_name);
    }

    public function test_dashboard_returns_the_original_monitoring_summaries(): void
    {
        $teacher = $this->user();

        $this->actingAs($teacher, 'sanctum')->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('role', 'teacher')
            ->assertJsonPath('metrics.students', 0)
            ->assertJsonPath('metrics.attendance_rate', 0)
            ->assertJsonPath('level_distribution.independent', 0)
            ->assertJsonPath('grade_distribution', [])
            ->assertJsonPath('recent_progress', [])
            ->assertJsonPath('upcoming_sessions', [])
            ->assertJsonPath('material_history', []);
    }

    public function test_dashboard_includes_intervention_latest_assessment_and_assessment_grade(): void
    {
        $coordinator = $this->user('coordinator');
        $studentId = \Illuminate\Support\Facades\DB::table('students')->insertGetId([
            'lrn' => 'DASH-1', 'full_name' => 'Intervention Pupil', 'section' => 'Rizal',
            'grade_level' => 'Grade 3', 'reading_profile' => 'Frustration', 'score' => 12,
        ]);
        \Illuminate\Support\Facades\DB::table('assessments')->insert([
            ['student_id' => $studentId, 'tool' => 'CRLA-M', 'profile' => 'Frustration', 'assessment_date' => '2026-08-01'],
            ['student_id' => $studentId, 'tool' => 'CRLA-M', 'profile' => 'Frustration', 'assessment_date' => '2026-09-15'],
        ]);

        $this->actingAs($coordinator, 'sanctum')->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('intervention_pupils.0.id', $studentId)
            ->assertJsonPath('intervention_pupils.0.last_assessment', '2026-09-15')
            ->assertJsonPath('recent_assessments.0.grade_level', 'Grade 3');
    }
}
