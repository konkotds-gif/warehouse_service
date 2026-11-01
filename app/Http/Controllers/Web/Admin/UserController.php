<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Users\Role;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Users', [
            'users' => User::with('location')->orderBy('name')->get()->map(fn ($u) => [
                'id' => $u->id, 'login' => $u->login, 'name' => $u->name, 'role' => $u->role->value, 'role_label' => $u->role->label(),
                'location_id' => $u->location_id, 'location' => $u->location?->code, 'is_active' => $u->is_active,
                'last_login_at' => $u->last_login_at?->toIso8601String(),
            ]),
            'roles' => collect(Role::cases())->map(fn ($r) => ['value' => $r->value, 'label' => $r->label()]),
            'locations' => Location::orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        User::create($this->validated($request, null));

        return back()->with('success', 'Пользователь создан');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validated($request, $user);
        if (empty($data['password'])) {
            unset($data['password']);
        }
        if ($user->id === $request->user()->id && (! ($data['is_active'] ?? true) || $data['role'] !== Role::Admin->value)) {
            return back()->with('error', 'Нельзя отключить себя или снять с себя роль администратора');
        }
        $user->update($data);

        return back()->with('success', 'Пользователь сохранён');
    }

    private function validated(Request $request, ?User $user): array
    {
        return $request->validate([
            'login' => ['required', 'string', 'max:64', 'alpha_dash', Rule::unique('users', 'login')->ignore($user?->id)],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::enum(Role::class)],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'is_active' => ['boolean'],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8'],
        ]);
    }
}
