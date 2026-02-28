<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Local-development-only controller.
 *
 * Instantly creates (or re-uses) a persistent "Dev Admin" user and logs you in.
 * The route is only registered when APP_ENV=local (see web.php).
 *
 * Usage: GET /dev/login
 */
class LocalDevLoginController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        abort_unless(app()->isLocal(), 403, 'Only available in local environment.');

        /** @var User $user */
        $user = User::firstOrCreate(
            ['sub' => 'local-dev-admin'],
            [
                'name' => 'Dev Admin',
            ]
        );

        // Ensure the admin role is attached (idempotent)
        $adminRole = Role::where('slug', 'admin')->first();
        if ($adminRole && ! $user->roles()->where('slug', 'admin')->exists()) {
            $user->roles()->attach($adminRole->id, [
                'assigned_by_user_id' => null,
            ]);
        }

        Auth::login($user, remember: true);

        return redirect()->intended(route('shows.index'));
    }
}
