<?php

namespace App\Http\Controllers\Web;

use App\Domain\Storage\ScanResolver;
use App\Domain\Support\Normalizer;
use App\Http\Controllers\Controller;
use App\Http\Presenters\Present;
use App\Models\Client;
use App\Models\StorageContract;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/** Поиск по телефону, госномеру, ФИО, номеру договора или QR бирки. */
class SearchController extends Controller
{
    public function index(Request $request, ScanResolver $resolver): Response|RedirectResponse
    {
        $q = trim((string) $request->query('q'));
        if ($q !== '' && ($contract = $resolver->contract($q))) {
            return redirect()->route('contracts.show', $contract);
        }

        return Inertia::render('Search', [
            'q' => $q,
            'clients' => $q === '' ? [] : $this->clients($q)->map(fn ($c) => Present::client($c) + [
                'vehicles' => $c->vehicles->map(fn ($v) => Present::vehicle($v))->all(),
                'open_contracts' => $c->contracts->where('status.value', '!=', 'closed')->count(),
            ])->values()->all(),
            'contracts' => $q === '' ? [] : StorageContract::with(['client', 'vehicle', 'store', 'sets'])
                ->where('number', 'like', ScanResolver::clean($q).'%')->limit(10)->get()
                ->map(fn ($c) => Present::contract($c))->all(),
        ]);
    }

    /** Подбор клиента для мастера приёма (JSON). */
    public function lookup(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        return response()->json($this->clients($q)->map(fn ($c) => Present::client($c) + [
            'vehicles' => $c->vehicles->map(fn ($v) => Present::vehicle($v))->all(),
        ])->values());
    }

    /** @return Collection<int, Client> */
    private function clients(string $q): Collection
    {
        $query = Client::with(['vehicles', 'contracts']);
        $digits = Normalizer::phoneDigits($q);
        $plate = Normalizer::plate($q);
        $query->where(function ($w) use ($q, $digits, $plate) {
            $w->where('full_name', 'like', '%'.$q.'%');
            if (strlen($digits) >= 4) {
                $w->orWhere('phone', 'like', '%'.$digits.'%');
            }
            if ($plate && mb_strlen($plate) >= 3) {
                $w->orWhereIn('id', Vehicle::where('plate', 'like', '%'.$plate.'%')->select('client_id'));
            }
        });

        return $query->orderBy('full_name')->limit(20)->get();
    }
}
