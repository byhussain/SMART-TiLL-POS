<?php

use App\Models\User;
use App\Services\PosSystemUserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper;

uses(RefreshDatabase::class);

it('hides user management permissions in pos bypass mode', function (): void {
    $user = User::query()->create([
        'name' => 'POS System',
        'email' => PosSystemUserService::SYSTEM_EMAIL,
        'password' => bcrypt('secret'),
    ]);

    $this->actingAs($user);

    expect(ResourceCanAccessHelper::check('View Users'))->toBeFalse();
    expect(ResourceCanAccessHelper::check('View Roles'))->toBeFalse();
    expect(ResourceCanAccessHelper::check('View Cash Transactions'))->toBeFalse();
    expect(ResourceCanAccessHelper::check('View Products'))->toBeTrue();
});
