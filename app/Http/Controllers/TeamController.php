<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class TeamController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('Hub/Team', ['members' => $request->user()->workspace()->users()->orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'email' => 'required|email|max:255|unique:users', 'password' => ['required', Password::min(12)->letters()->numbers()], 'role' => 'required|in:admin,developer,viewer']);
        DB::transaction(function () use ($request, $data) {
            $user = User::create(collect($data)->except('role')->all());
            $workspace = $request->user()->workspace();
            $workspace->users()->attach($user, ['role' => $data['role'], 'active' => true]);
            Audit::record($workspace->id, $request->user()->id, 'member.created', 'user:'.$user->id, ['role' => $data['role']]);
        });

        return back()->with('message', 'Member created. Grant project access separately and share initial credentials through your secure channel.');
    }

    public function update(Request $request, User $user)
    {
        $workspace = $request->user()->workspace();
        $member = $workspace->users()->where('users.id', $user->id)->firstOrFail();
        abort_if($member->pivot->role === 'owner' || $request->user()->id === $user->id, 422, 'Owner and your own membership cannot be changed here.');
        $data = $request->validate(['role' => 'required|in:admin,developer,viewer', 'active' => 'required|boolean']);
        $workspace->users()->updateExistingPivot($user->id, $data);
        Audit::record($workspace->id, $request->user()->id, 'member.access_changed', 'user:'.$user->id, $data);

        return back()->with('message', 'Membership updated. Queued jobs recheck authorization.');
    }
}
