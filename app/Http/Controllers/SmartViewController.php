<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SavedSmartView;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SmartViewController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{name: string, filter_json: array<string, mixed>, is_shared?: bool} $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'filter_json' => ['required', 'array'],
            'is_shared' => ['boolean'],
        ]);

        SavedSmartView::query()->create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'name' => $validated['name'],
            'filter_json' => $validated['filter_json'],
            'is_shared' => $request->boolean('is_shared'),
        ]);

        return back()->with('success', 'Smart view saved.');
    }

    public function destroy(Request $request, SavedSmartView $smartView): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($smartView->user_id === $user->id, 404);

        $smartView->delete();

        return back()->with('success', 'Smart view removed.');
    }
}
