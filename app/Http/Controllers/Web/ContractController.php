<?php

namespace App\Http\Controllers\Web;

use App\Domain\Labels\TagRenderer;
use App\Domain\Storage\ContractService;
use App\Domain\Storage\IssueService;
use App\Domain\Storage\Pricing;
use App\Domain\Storage\SetMover;
use App\Domain\Support\DomainError;
use App\Http\Controllers\Controller;
use App\Http\Presenters\Present;
use App\Models\ContractExtension;
use App\Models\Location;
use App\Models\Movement;
use App\Models\StorageContract;
use App\Models\TagPrint;
use App\Models\WheelSet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ContractController extends Controller
{
    use Concerns;

    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q'));
        $contracts = StorageContract::with(['client', 'vehicle', 'store', 'sets.cell', 'sets.location', 'sets.transfer'])
            ->when($q !== '', fn ($w) => $w->where('number', 'like', $q.'%'))
            ->when($request->query('status'), fn ($w, $s) => $w->where('status', $s))
            ->when($request->query('store'), fn ($w, $s) => $w->where('store_id', $s))
            ->orderByDesc('accepted_at')->paginate(30)->withQueryString();

        return Inertia::render('Contracts/Index', [
            'filters' => ['q' => $q, 'status' => $request->query('status'), 'store' => $request->query('store')],
            'stores' => Location::stores()->orderBy('code')->get(['id', 'code', 'name']),
            'contracts' => $contracts->through(fn ($c) => Present::contract($c)),
        ]);
    }

    public function show(Request $request, StorageContract $contract, IssueService $issue, Pricing $pricing): Response
    {
        $contract->load(['client', 'vehicle', 'store', 'tariff', 'acceptedBy', 'payments', 'extensions.author', 'extensions.outboxEvent',
            'sets.cell', 'sets.location', 'sets.transfer', 'sets.issueStore', 'sets.photos']);
        $movements = Movement::with(['user', 'wheelSet'])->whereIn('wheel_set_id', $contract->sets->pluck('id'))
            ->orderByDesc('id')->limit(100)->get();
        $prints = TagPrint::with('user')->whereIn('wheel_set_id', $contract->sets->pluck('id'))->orderByDesc('id')->get();

        // После выдачи — предложить сразу принять другой комплект этой машины (план, 6.1.1)
        $otherGarage = $contract->vehicle ? $contract->vehicle->garageSets()
            ->whereIn('state', ['on_vehicle', 'with_client'])
            ->whereNotIn('id', $contract->sets->pluck('garage_set_id')->filter())
            ->get()->map(fn ($g) => ['id' => $g->id, 'title' => $g->title, 'tire_size' => $g->tire_size])->all() : [];

        return Inertia::render('Contracts/Show', [
            'contract' => Present::contract($contract, true) + [
                'sets' => $contract->sets->map(fn (WheelSet $s) => Present::set($s) + [
                    'photos' => $s->photos->map(fn ($p) => ['id' => $p->id, 'url' => route('photos.show', $p)])->all(),
                ])->all(),
            ],
            'debt' => $issue->debtCheck($contract),
            'pricing' => Pricing::settings() + [
                // Продление на 1 месяц по текущему прайсу (для подсказки в окне продления)
                'extend_month' => $this->extendMonth($contract, $pricing),
                // С какой даты считается продление: от окончания срока, если он ещё не наступил, иначе с сегодня
                'extend_from' => ($contract->storage_until && $contract->storage_until->isFuture() ? $contract->storage_until : now())->toDateString(),
            ],
            'extensions' => $contract->extensions->map(fn (ContractExtension $e) => [
                'id' => $e->id,
                'number' => $e->number,
                'seq' => $e->seq,
                'at' => $e->extended_at->toIso8601String(),
                'months' => $e->months,
                'period_from' => $e->period_from->toDateString(),
                'previous_until' => $e->previous_until?->toDateString(),
                'storage_until' => $e->storage_until->toDateString(),
                'amount' => (float) $e->amount,
                'user' => $e->author?->name,
                'onec_doc_guid' => $e->onec_doc_guid,
                'onec_delivered' => $e->outboxEvent?->delivered_at !== null,
            ])->all(),
            'movements' => $movements->map(fn ($m) => Present::movement($m))->all(),
            'prints' => $prints->map(fn ($p) => ['copies' => $p->copies, 'reason' => $p->reason, 'format' => $p->format,
                'at' => $p->printed_at->toIso8601String(), 'user' => $p->user?->name, 'qr_code' => $contract->sets->firstWhere('id', $p->wheel_set_id)?->qr_code])->all(),
            'stores' => Location::active()->stores()->orderBy('code')->get(['id', 'code', 'name']),
            'otherGarage' => $otherGarage,
            'autoPrint' => $request->boolean('print'),
        ]);
    }

    private function extendMonth(StorageContract $contract, Pricing $pricing): ?float
    {
        try {
            return round(array_sum(array_column($pricing->extensionLines($contract, 1), 'amount')), 2);
        } catch (DomainError) {
            return null; // для размера нет цены в прайсе
        }
    }

    public function extend(Request $request, StorageContract $contract, ContractService $service): RedirectResponse
    {
        $extension = $service->extend($contract, $request->validate(['months' => ['required', 'integer', 'min:1', 'max:'.Pricing::settings()['max_months']]])['months'], $request->user());

        return back()->with('success', "Оформлена пролонгация {$extension->number}: хранение до {$extension->storage_until->format('d.m.Y')}, документ отправлен в 1С");
    }

    /** Печатная форма документа пролонгации (A4, два экземпляра подписей: клиент и исполнитель). */
    public function extensionPrint(StorageContract $contract, ContractExtension $extension)
    {
        abort_unless($extension->contract_id === $contract->id, 404);
        $contract->load(['client', 'vehicle', 'store']);
        $extension->load('author');

        return response()->view('print.extension', [
            'contract' => $contract,
            'e' => $extension,
            'tz' => config('app.display_timezone'),
        ]);
    }

    /** Печать бирок из браузера (58×40 мм). Бирок — столько, сколько колёс (или указанное число при перепечатке). */
    public function tags(Request $request, StorageContract $contract, TagRenderer $renderer)
    {
        [$sets, $copies, $reason] = $this->printRequest($request, $contract);
        $this->logPrint($sets, $copies, $reason, 'html', $request);

        return response()->view('print.tags', [
            'tags' => $sets->flatMap(fn ($s) => array_fill(0, $copies[$s->id], $renderer->tagData($s)))->all(),
            'receipt' => $request->boolean('receipt') ? $this->receipt($contract, $renderer) : null,
        ]);
    }

    /** ZPL для термопринтера (Zebra/совместимые). */
    public function zpl(Request $request, StorageContract $contract, TagRenderer $renderer)
    {
        [$sets, $copies, $reason] = $this->printRequest($request, $contract);
        $this->logPrint($sets, $copies, $reason, 'zpl', $request);
        $zpl = $sets->map(fn ($s) => $renderer->zpl($s, $copies[$s->id]))->implode('');

        return response($zpl, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="tags-'.$contract->number.'.zpl"',
        ]);
    }

    /** Квитанция клиенту с тем же QR. */
    public function receiptPage(StorageContract $contract, TagRenderer $renderer)
    {
        return response()->view('print.tags', ['tags' => [], 'receipt' => $this->receipt($contract, $renderer)]);
    }

    private function receipt(StorageContract $contract, TagRenderer $renderer): array
    {
        $contract->load(['client', 'vehicle', 'store', 'tariff', 'sets']);

        return [
            'contract' => $contract,
            'svg' => $renderer->qrSvg($contract->number, 160),
            'tz' => config('app.display_timezone'),
        ];
    }

    private function printRequest(Request $request, StorageContract $contract): array
    {
        $data = $request->validate([
            'set_id' => ['nullable', 'uuid'],
            'copies' => ['nullable', 'integer', 'min:1', 'max:8'],
            'reason' => ['nullable', 'in:accept,reprint'],
        ]);
        $sets = $contract->sets()->with(['contract.vehicle', 'contract.store'])
            ->when($data['set_id'] ?? null, fn ($q, $id) => $q->where('id', $id))->get();
        $copies = $sets->mapWithKeys(fn ($s) => [$s->id => (int) ($data['copies'] ?? $s->qty)])->all();

        return [$sets, $copies, $data['reason'] ?? 'accept'];
    }

    private function logPrint($sets, array $copies, string $reason, string $format, Request $request): void
    {
        DB::transaction(function () use ($sets, $copies, $reason, $format, $request) {
            foreach ($sets as $set) {
                TagPrint::create(['wheel_set_id' => $set->id, 'copies' => $copies[$set->id], 'reason' => $reason,
                    'format' => $format, 'printed_by' => $request->user()->id, 'printed_at' => now()]);
                if ($reason === 'reprint') {
                    app(SetMover::class)->log($set, 'reprint', $set->status, $set->status, $set->currentAddress(),
                        ['qty' => $copies[$set->id], 'note' => "Перепечатано бирок: {$copies[$set->id]}"]);
                }
            }
        });
    }
}
