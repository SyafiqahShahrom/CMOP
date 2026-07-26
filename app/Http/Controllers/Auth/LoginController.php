<?php

namespace App\Http\Controllers\Auth;

use App\Domains\Authentication\Actions\LoginUserAction;
use App\Domains\Authentication\Actions\LogoutUserAction;
use App\Domains\Authentication\Requests\LoginRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(LoginRequest $request, LoginUserAction $action): RedirectResponse
    {
        $action->execute($request->string('email')->toString(), $request->string('password')->toString());

        $request->session()->regenerate();

        return redirect()->intended('/dashboard');
    }

    public function destroy(Request $request, LogoutUserAction $action): RedirectResponse
    {
        $action->execute($request);

        return redirect('/login');
    }
}
