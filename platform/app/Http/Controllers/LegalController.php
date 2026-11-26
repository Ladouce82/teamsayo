<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class LegalController extends Controller
{
    public function show(string $page): View
    {
        abort_unless(in_array($page, ['imprint', 'privacy', 'terms'], true), 404);

        return view("legal.{$page}", ['legal' => config('teamsayo.legal')]);
    }
}
