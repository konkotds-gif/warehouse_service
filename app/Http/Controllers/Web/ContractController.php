<?php

namespace App\Http\Controllers\Web;

use App\Domain\Labels\TagRenderer;
use App\Domain\Storage\ContractClosing;
use App\Domain\Storage\ContractConfirmation;
use App\Domain\Storage\ContractService;
use App\Domain\Storage\IssueService;
use App\Domain\Storage\Pricing;
use App\Domain\Storage\SetMover;
use App\Domain\Storage\SetWhereabouts;
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

    public function show(Request $request, StorageContract $contract, IssueService $issue, Pricing $pricing, ContractConfirmation $confirmation, ContractClosing $closing): Response
    {
        $contract->load(['client', 'vehicle', 'store', 'tariff', 'acceptedBy', 'closedBy', 'payments', 'extensions.author', 'extensions.outboxEvent',
            'sets.cell.row', 'sets.cell.location', 'sets.location', 'sets.transfer.from', 'sets.transfer.to', 'sets.transfer.trip', 'sets.issueStore', 'sets.photos']);
        // До истории движений: обновление статуса SMS может добавить в неё запись
        $confirmationStatus = $confirmation->status($contract);
        $closeStatus = $confirmation->status($contract, ContractConfirmation::CLOSE);
        $movements = Movement::with(['user', 'wheelSet'])->whereIn('wheel_set_id', $contract->sets->pluck('id'))
            ->orderByDesc('id')->limit(100)->get();
        $prints = TagPrint::with('user')->whereIn('wheel_set_id', $contract->sets->pluck('id'))->orderByDesc('id')->get();

        // После выдачи — предложить сразу принять другой комплект этой машины (план, 6.1.1)
        $otherGarage = $contract->vehicle ? $contract->vehicle->garageSets()
            ->whereIn('state', ['on_vehicle', 'with_client'])
            ->whereNotIn('id', $contract->sets->pluck('garage_set_id')->filter())
            ->get()->map(fn ($g) => ['id' => $g->id, 'title' => $g->title, 'tire_size' => $g->tire_size])->all() : [];

        $whereabouts = SetWhereabouts::forSets($contract->sets);

        return Inertia::render('Contracts/Show', [
            // array_merge, а не «+»: Present::contract уже содержит ключ sets, его нужно заменить
            'contract' => array_merge(Present::contract($contract, true), [
                'sets' => $contract->sets->map(fn (WheelSet $s) => Present::set($s) + [
                    'whereabouts' => $whereabouts[$s->id] ?? null,
                    'photos' => $s->photos->map(fn ($p) => ['id' => $p->id, 'url' => route('photos.show', $p)])->all(),
                ])->all(),
            ]),
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
            'confirmation' => $confirmationStatus,
            // Закрытие договора: расчёт доплаты, что мешает закрыть, код из SMS
            'closing' => $closing->quote($contract, $request->user()) + [
                'sms' => $closeStatus,
                'closed_at' => $contract->closed_at?->toIso8601String(),
                'closed_by' => $contract->closedBy?->name,
                'close_confirmation' => $contract->close_confirmation,
                'confirmation_label' => ContractClosing::confirmationLabel($contract->close_confirmation),
                'close_phone' => $contract->close_phone,
                'close_note' => $contract->close_note,
            ],
            'autoClose' => $request->boolean('close'),
        ]);
    }

    /** Отправить клиенту код закрытия договора. */
    public function sendCloseCode(Request $request, StorageContract $contract, ContractClosing $closing): RedirectResponse
    {
        $code = $closing->sendCode($contract, $request->user());

        return $code->isDelivered()
            ? back()->with('success', 'Код закрытия договора отправлен на '.$code->phone)
            : back()->withErrors(['code' => 'Код не отправлен: '.$code->error])->with('error', 'Код не отправлен: '.$code->error);
    }

    /** Закрыть договор: код из SMS (или причина без кода), скан бирки, отметка об оплате просрочки. */
    public function close(Request $request, StorageContract $contract, ContractClosing $closing): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:12'],
            'tag' => ['nullable', 'string', 'max:64'],
            'paid' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);
        $contract = $closing->close($contract, $data + ['paid' => $request->boolean('paid')], $request->user());

        return back()->with('success', "Договор {$contract->number} закрыт"
            .((float) $contract->overdue_fee > 0 ? ', плата за хранение после срока — '.number_format((float) $contract->overdue_fee, 2, ',', ' ').' ₽' : ''));
    }

    /** Отправить клиенту код подтверждения договора (повторно). */
    public function sendCode(Request $request, StorageContract $contract, ContractConfirmation $confirmation): RedirectResponse
    {
        $code = $confirmation->send($contract, $request->user());

        return $code->isDelivered()
            ? back()->with('success', 'Код подтверждения отправлен на '.$code->phone)
            : back()->withErrors(['code' => 'Код не отправлен: '.$code->error])->with('error', 'Код не отправлен: '.$code->error);
    }

    /** Ввод кода из SMS: договор подтверждён клиентом. */
    public function confirm(Request $request, StorageContract $contract, ContractConfirmation $confirmation): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12']], ['code.required' => 'Введите код из SMS']);
        $confirmation->verify($contract, $data['code'], $request->user());

        return back()->with('success', "Договор {$contract->number} подтверждён кодом из SMS");
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
