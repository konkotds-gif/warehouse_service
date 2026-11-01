<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tariff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Тарифы (услуги хранения). Обычно приходят из 1С через PUT /api/v1/services, здесь — ручная правка. */
class TariffController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Tariffs', ['tariffs' => Tariff::orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        Tariff::create($this->validated($request));

        return back()->with('success', 'Тариф создан');
    }

    public function update(Request $request, Tariff $tariff): RedirectResponse
    {
        $tariff->update($this->validated($request, $tariff));

        return back()->with('success', 'Тариф сохранён');
    }

    private function validated(Request $request, ?Tariff $tariff = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:32', Rule::unique('tariffs', 'code')->ignore($tariff?->id)],
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'period' => ['required', 'in:season,month'],
            'months' => ['required', 'integer', 'min:1', 'max:24'],
            'is_active' => ['boolean'],
        ]);
    }
}
