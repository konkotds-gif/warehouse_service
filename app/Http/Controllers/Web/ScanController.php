<?php

namespace App\Http\Controllers\Web;

use App\Domain\Storage\ContractConfirmation;
use App\Domain\Storage\IssueService;
use App\Domain\Storage\ScanResolver;
use App\Domain\Storage\SetStatus;
use App\Domain\Support\DomainError;
use App\Domain\Warehouse\PlacementService;
use App\Http\Controllers\Controller;
use App\Http\Presenters\Present;
use App\Models\WheelSet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Экраны ТСД: размещение и выдача по скану. JSON-эндпоинты работают с офлайн-очередью PWA. */
class ScanController extends Controller
{
    public function placePage(): Response
    {
        return Inertia::render('Scan/Place');
    }

    public function issuePage(): Response
    {
        return Inertia::render('Scan/Issue');
    }

    /** Что за комплект по QR + куда положить. */
    public function lookup(Request $request, ScanResolver $resolver, PlacementService $placement, IssueService $issue, ContractConfirmation $confirmation): JsonResponse
    {
        $code = (string) $request->query('code');
        $sets = $resolver->sets($code)->load(['contract.client', 'contract.vehicle', 'cell', 'location', 'transfer', 'issueStore']);
        if ($sets->isEmpty()) {
            throw DomainError::notFound("По коду «{$code}» ничего не найдено");
        }
        $contract = $sets->first()->contract;

        return response()->json([
            'contract' => ['id' => $contract->id, 'number' => $contract->number, 'client' => $contract->client?->full_name,
                'phone' => $contract->client?->phone, 'vehicle' => $contract->vehicle?->title(), 'status_label' => $contract->status->label()],
            'debt' => $issue->debtCheck($contract),
            // Последний комплект при закрытии по SMS выдаётся через «Закрыть договор» в карточке
            'closing' => [
                'requires_code' => $confirmation->requiresCloseCode($contract),
                'active' => $contract->sets()->whereIn('status', SetStatus::activeValues())->count(),
            ],
            'sets' => $sets->map(fn (WheelSet $s) => Present::set($s) + [
                'suggested' => in_array($s->status, [SetStatus::Received, SetStatus::Placed, SetStatus::Lost], true) ? $placement->suggest($s)?->address : null,
            ])->values(),
        ]);
    }

    public function place(Request $request, ScanResolver $resolver, PlacementService $placement): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string'], 'address' => ['required', 'string'], 'suggested' => ['nullable', 'string']]);
        $set = $resolver->oneActive($data['code']);
        $set = $placement->place($set, $data['address'], $data['suggested'] ?? null);

        return response()->json(['message' => "Комплект {$set->qr_code} размещён в {$set->currentAddress()}", 'set' => Present::set($set)]);
    }
}
