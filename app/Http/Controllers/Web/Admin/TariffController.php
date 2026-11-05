<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Storage\Pricing;
use App\Http\Controllers\Controller;
use App\Models\Tariff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Тарифы: прайс хранения по диаметрам (номенклатура 1С) и условия после окончания срока. */
class TariffController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Tariffs', [
            'tariffs' => Tariff::orderByRaw('diameter_from is null')->orderBy('diameter_from')->orderBy('name')->get(),
            'pricing' => Pricing::settings(),
        ]);
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

    /** Условия: срок хранения, бесплатные дни после срока, плата за день. */
    public function updatePricing(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'max_months' => ['required', 'integer', 'min:1', 'max:24'],
            'free_days' => ['required', 'integer', 'min:0', 'max:365'],
            'overdue_rate' => ['required', 'numeric', 'min:0', 'max:100000'],
            'overdue_per' => ['required', 'in:contract,wheel'],
            'overdue_service_code' => ['nullable', 'string', 'max:32'],
        ]);
        Pricing::saveSettings($data);

        return back()->with('success', 'Условия хранения сохранены');
    }

    private function validated(Request $request, ?Tariff $tariff = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', Rule::unique('tariffs', 'code')->ignore($tariff?->id)],
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'diameter_from' => ['nullable', 'integer', 'min:10', 'max:30', 'required_with:diameter_to'],
            'diameter_to' => ['nullable', 'integer', 'min:10', 'max:30', 'gte:diameter_from'],
            'period' => ['required', 'in:season,month'],
            'months' => ['required', 'integer', 'min:1', 'max:24'],
            'is_active' => ['boolean'],
        ], ['diameter_to.gte' => 'Диаметр «до» не может быть меньше «от»']);
        $data['diameter_to'] ??= $data['diameter_from'] ?? null;
        $this->checkOverlap($data, $tariff);

        return $data;
    }

    /** Один диаметр — одна действующая цена. */
    private function checkOverlap(array $data, ?Tariff $tariff): void
    {
        if (empty($data['diameter_from']) || ! ($data['is_active'] ?? true)) {
            return;
        }
        $other = Tariff::where('is_active', true)->whereNotNull('diameter_from')
            ->when($tariff, fn ($q) => $q->whereKeyNot($tariff->id))
            ->where('diameter_from', '<=', $data['diameter_to'])->where('diameter_to', '>=', $data['diameter_from'])
            ->first();
        if ($other) {
            throw ValidationException::withMessages(['diameter_from' => "Диаметр уже есть в тарифе {$other->code} «{$other->name}»"]);
        }
    }
}
