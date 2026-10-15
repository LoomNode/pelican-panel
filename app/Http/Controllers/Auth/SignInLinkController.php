<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Users\SignInLinkService;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SignInLinkController extends Controller
{
    public function __construct(private SignInLinkService $links) {}

    /**
     * Sign in with a one-time link made through the Application API (SignInLinkService),
     * then go where it says. Whoever was signed in in this browser before is signed out
     * first: following the link means "be this user now".
     */
    public function __invoke(Request $request, string $token): RedirectResponse
    {
        $link = $this->links->consume($token);
        if (!$link) {
            Notification::make()
                ->title('That sign-in link has expired')
                ->body('Links work once, for a minute. Open the panel from your account again, or sign in here.')
                ->danger()
                ->persistent()
                ->send();

            return redirect()->route('auth.login');
        }

        if (auth()->check()) {
            auth()->guard()->logout();
            $request->session()->invalidate();
        }

        // Fires Login, so the activity log records it like any other sign-in.
        auth()->guard()->login($link['user']);
        $request->session()->regenerate();

        return redirect($link['to']);
    }
}
