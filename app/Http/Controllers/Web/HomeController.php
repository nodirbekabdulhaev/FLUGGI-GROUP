<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\SearchService;
use App\Support\Menu;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index()
    {
        return redirect(Menu::home());
    }

    public function locale(Request $request, string $locale)
    {
        abort_unless(in_array($locale, ['ru', 'uz'], true), 404);
        $request->session()->put('locale', $locale);
        if ($user = $request->user()) {
            $user->forceFill(['locale' => $locale])->saveQuietly();
        }

        return back();
    }

    public function switchBranch(Request $request)
    {
        abort_if($request->user()->branch_id, 403);   // branch-restricted users cannot switch
        $id = (int) $request->input('branch_id');
        if ($id && ! Branch::active()->whereKey($id)->exists()) {
            abort(422);
        }
        $request->session()->put('branch_id', $id ?: null);

        return back();
    }

    public function search(Request $request, SearchService $search)
    {
        return response()->json(['results' => $search->search((string) $request->query('q'), $request->user())]);
    }
}
