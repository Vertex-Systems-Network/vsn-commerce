<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\DB;

/** Defines the ProfileController class and its project responsibilities. */
class ProfileController extends Controller
{
    /** Handles the show request for this resource. */
    public function show(): UserResource
    {
        return new UserResource(request()->user()->load('profile'));
    }

    /** Handles the update request for this resource. */
    public function update(UpdateProfileRequest $request): UserResource
    {
        $user = $request->user();
        $data = $request->validated();

        DB::transaction(/** Inline callback for this operation. */ function () use ($user, $data): void {
            if (array_key_exists('name', $data)) {
                $user->update(['name' => $data['name']]);
            }

            $profileData = collect($data)
                ->only(['phone', 'date_of_birth', 'locale', 'timezone'])
                ->all();

            if ($profileData !== []) {
                $user->profile()->updateOrCreate([], $profileData);
            }
        });

        return new UserResource($user->fresh()->load('profile'));
    }
}
