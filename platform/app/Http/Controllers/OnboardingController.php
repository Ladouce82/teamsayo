<?php

namespace App\Http\Controllers;

use App\Support\Provisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OnboardingController extends Controller
{
    public const PACKS = ['Creative & digital studio', 'NGO & development', 'Consultancy', 'School / university', 'Healthcare', 'Talent & career programme'];

    public function show(): View
    {
        return view('onboarding', ['packs' => self::PACKS]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'workspace' => ['required', 'string', 'max:120'],
            'companies' => ['nullable', 'string', 'max:5000'],
            'sector' => ['required', 'in:' . implode(',', self::PACKS)],
            'nonprofit' => ['nullable', 'boolean'],
        ]);
        abort_if($request->user()->memberships()->where('role', 'owner')->count() >= 5, 422, 'You can own up to five workspaces.');
        $companies = preg_split('/\r\n|\r|\n/', (string) ($data['companies'] ?? '')) ?: [];
        $workspace = Provisioner::createWorkspace($request->user(), $data['workspace'], $companies, $data['sector'], $request->boolean('nonprofit'));

        return redirect()->route('app', $workspace)->with('status', 'Your workspace is ready. Your free trial runs for ' . config('teamsayo.trial_days') . ' days.');
    }
}
