<?php

namespace App\Services;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PosSystemUserService
{
    public const SYSTEM_EMAIL = 'pos.system@localhost';

    public function ensureAuthenticated(): User
    {
        // Fast path: when already logged in as the POS system user, return the
        // current user without any DB queries. This middleware runs on every
        // panel request — the previous implementation hit users + stores tables
        // unconditionally, which dominated request time on Windows SQLite.
        if (Auth::check()) {
            $current = Auth::user();
            if ($current instanceof User && $current->email === self::SYSTEM_EMAIL) {
                return $current;
            }
        }

        $user = User::query()->firstOrCreate(
            ['email' => self::SYSTEM_EMAIL],
            [
                'name' => 'POS System User',
                'password' => Hash::make(Str::random(32)),
            ]
        );

        if (! Auth::check() || (int) Auth::id() !== (int) $user->id) {
            Auth::login($user, true);
        }

        $this->ensureStoresAttached($user, Store::query()->get());

        return $user;
    }

    public function ensureStoreAttached(User $user, Store $store): void
    {
        if (! $user->stores()->whereKey($store->id)->exists()) {
            $user->stores()->attach($store->id);
        }
    }

    public function ensureStoresAttached(User $user, iterable $stores): void
    {
        $storeIds = collect($stores)
            ->filter(fn ($store): bool => $store instanceof Store)
            ->map(fn (Store $store): int => (int) $store->id)
            ->unique()
            ->values();

        if ($storeIds->isEmpty()) {
            return;
        }

        $existingStoreIds = $user->stores()
            ->whereIn('stores.id', $storeIds)
            ->pluck('stores.id');

        $storeIdsToAttach = $storeIds
            ->diff($existingStoreIds)
            ->values();

        if ($storeIdsToAttach->isEmpty()) {
            return;
        }

        $user->stores()->attach($storeIdsToAttach->all());
    }
}
