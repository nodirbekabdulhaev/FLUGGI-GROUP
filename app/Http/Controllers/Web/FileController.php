<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Organization;
use App\Models\Student;
use App\Support\Tenant;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/** Personal-data files live outside public/ and are streamed only to authorised users. */
class FileController extends Controller
{
    public function show(string $kind, int $id)
    {
        $path = match ($kind) {
            'student-photo' => $this->studentPhoto($id),
            'expense' => $this->expenseFile($id),
            'logo' => Organization::whereKey(Tenant::id())->value('logo_path'),
            default => abort(404),
        };
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    protected function studentPhoto(int $id): ?string
    {
        $student = Student::visibleTo(auth()->user())->findOrFail($id);
        abort_unless(Gate::any(['students.view', 'groups.view_own']), 403);

        return $student->photo_path;
    }

    protected function expenseFile(int $id): ?string
    {
        Gate::authorize('expenses.view');

        return Expense::findOrFail($id)->file_path;
    }
}
