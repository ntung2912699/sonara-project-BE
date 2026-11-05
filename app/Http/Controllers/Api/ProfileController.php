<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->profileData($request));
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'favorite_items' => ['sometimes', 'array', 'max:50'],
            'favorite_items.*' => ['string', 'max:100'],
            'avatar' => ['sometimes', 'image', 'max:5120'],
        ]);

        $user = $request->user();
        $oldAvatarPath = $user->avatar_path;

        if ($request->hasFile('avatar')) {
            $avatarPath = $request->file('avatar')->store('avatars', 'public');
            abort_unless(is_string($avatarPath), 500, 'Unable to store the uploaded profile photo.');
            $validated['avatar_path'] = $avatarPath;
        }

        unset($validated['avatar']);
        $user->fill($validated)->save();

        if (isset($validated['avatar_path']) && $oldAvatarPath !== null) {
            Storage::disk('public')->delete($oldAvatarPath);
        }

        return ApiResponse::success($this->profileData($request), 'profile.updated');
    }

    private function profileData(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $user->id,
            'role' => $user->role,
            'is_active' => $user->is_active,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'bio' => $user->bio,
            'avatar_url' => $user->avatar_path ? Storage::disk('public')->url($user->avatar_path) : null,
            'favorite_items' => $user->favorite_items ?? [],
        ];
    }
}
