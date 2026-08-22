<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Defines the AdminUserController class and its project responsibilities. */
class AdminUserController extends Controller
{
    /** Handles the index request for this resource. */
    public function index(Request $request): JsonResponse
    {
        $this->admin($request);
        $validated = $request->validate([
            'q' => ['nullable','string','max:190'],
            'role' => ['nullable', Rule::enum(UserRole::class)],
            'perPage' => ['nullable','integer','min:1','max:100'],
        ]);
        $q = trim((string) ($validated['q'] ?? ''));
        $rows = User::query()
            ->when($q !== '', /** Inline callback for this operation. */ fn ($query) => $query->where(/** Inline callback for this operation. */ fn ($inner) => $inner->where('name','like',"%{$q}%")->orWhere('email','like',"%{$q}%")))
            ->when(!empty($validated['role']), /** Inline callback for this operation. */ fn ($query) => $query->where('role', $validated['role']))
            ->latest('id')->paginate((int) ($validated['perPage'] ?? 50));

        return response()->json(['data' => [
            'items' => $rows->getCollection()->map(/** Inline callback for this operation. */ fn (User $user) => $this->row($user))->values(),
            'meta' => ['total'=>$rows->total(),'currentPage'=>$rows->currentPage(),'lastPage'=>$rows->lastPage()],
        ]]);
    }

    /** Handles the store request for this resource. */
    public function store(Request $request): JsonResponse
    {
        $this->admin($request);
        $data = $request->validate([
            'name' => ['required','string','max:120'],
            'email' => ['required','email','max:190','unique:users,email'],
            'password' => ['required','string','min:10','max:190'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'emailVerified' => ['nullable','boolean'],
        ]);
        $this->assertCanAssign($request, (string) $data['role']);

        $user = DB::transaction(/** Inline callback for this operation. */ function () use ($data): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => Str::lower($data['email']),
                'password' => $data['password'],
                'role' => $data['role'],
            ]);
            if ($data['emailVerified'] ?? false) $user->forceFill(['email_verified_at'=>now()])->save();
            $user->profile()->create();
            return $user;
        }, 3);

        return response()->json(['data'=>$this->row($user)], 201);
    }

    /** Handles the update request for this resource. */
    public function update(Request $request, User $user): JsonResponse
    {
        $this->admin($request);
        $data = $request->validate([
            'name' => ['sometimes','string','max:120'],
            'email' => ['sometimes','email','max:190',Rule::unique('users','email')->ignore($user->id)],
            'password' => ['sometimes','nullable','string','min:10','max:190'],
            'role' => ['sometimes', Rule::enum(UserRole::class)],
            'emailVerified' => ['sometimes','boolean'],
        ]);
        if (isset($data['role'])) {
            $this->assertCanAssign($request, (string) $data['role']);
            abort_if($request->user()->is($user) && $data['role'] !== $this->roleValue($user), 422, 'You cannot change your own role.');
        }
        if (isset($data['email'])) $data['email'] = Str::lower($data['email']);
        $verifiedAt = null;
        $changeVerification = array_key_exists('emailVerified', $data);
        if ($changeVerification) {
            $verifiedAt = $data['emailVerified'] ? ($user->email_verified_at ?: now()) : null;
            unset($data['emailVerified']);
        }
        if (array_key_exists('password', $data) && !$data['password']) unset($data['password']);
        $user->fill($data);
        if ($changeVerification) $user->forceFill(['email_verified_at' => $verifiedAt]);
        $user->save();
        return response()->json(['data'=>$this->row($user->fresh())]);
    }

    /** Handles row for the admin user controller workflow. */
    private function row(User $user): array
    {
        return [
            'id'=>$user->id,'name'=>$user->name,'email'=>$user->email,'role'=>$this->roleValue($user),
            'emailVerified'=>$user->email_verified_at !== null,'createdAt'=>$user->created_at?->toIso8601String(),
        ];
    }

    /** Handles admin for the admin user controller workflow. */
    private function admin(Request $request): void
    {
        abort_unless(in_array($this->roleValue($request->user()), [UserRole::Admin->value, UserRole::SuperAdmin->value], true), 403);
    }

    /** Handles assert can assign for the admin user controller workflow. */
    private function assertCanAssign(Request $request, string $role): void
    {
        if ($role === UserRole::SuperAdmin->value) abort_unless($this->roleValue($request->user()) === UserRole::SuperAdmin->value, 403, 'Only a super admin may assign the super admin role.');
    }

    /** Handles role value for the admin user controller workflow. */
    private function roleValue(?User $user): string
    {
        $role = $user?->role;
        return $role instanceof UserRole ? $role->value : (string) $role;
    }
}
