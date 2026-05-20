<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InviteController extends Controller
{
    public function show(string $token)
    {
        $employee = Employee::where('invite_token', $token)
            ->where('status', Employee::STATUS_PENDING)
            ->firstOrFail();

        return view('invite.accept', [
            'employee' => $employee,
            'token' => $token,
        ]);
    }

    public function accept(Request $request, string $token)
    {
        $employee = Employee::where('invite_token', $token)
            ->where('status', Employee::STATUS_PENDING)
            ->firstOrFail();

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $employee->acceptInvite($validated['password']);

        // Авторизуем сотрудника
        Auth::guard('employee')->login($employee);

        return redirect()
            ->route('dashboard')
            ->with('success', 'Добро пожаловать! Ваш аккаунт активирован.');
    }
}
