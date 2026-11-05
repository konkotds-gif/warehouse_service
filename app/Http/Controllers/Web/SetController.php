<?php

namespace App\Http\Controllers\Web;

use App\Domain\Storage\IssueService;
use App\Http\Controllers\Controller;
use App\Http\Presenters\Present;
use App\Models\Location;
use App\Models\Photo;
use App\Models\WheelSet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Действия с комплектом: заказ к выдаче, выдача, списание, фото. */
class SetController extends Controller
{
    public function order(Request $request, WheelSet $set, IssueService $service): RedirectResponse
    {
        $data = $request->validate(['store_id' => ['required', 'integer'], 'date' => ['required', 'date']]);
        $set = $service->order($set, Location::findOrFail($data['store_id']), $data['date'], $request->user());

        if ($set->status->value === 'ready' && ($task = IssueService::openClientTask($set))) {
            return back()->with('success', "Склад и магазин в одном здании: комплект сразу перемещён в {$task->payload['store_code']} без водителя"
                ." ({$task->payload['transfer_number']}), кладовщику — задание снять колёса с полки {$task->payload['shelf']} и выдать клиенту");
        }

        return back()->with('success', $set->status->value === 'ready'
            ? 'Комплект уже в этом магазине — готов к выдаче'
            : 'Заказ к выдаче создан: комплект добавлен в перемещение в магазин (Задания → Перевозки), складу — задание «отобрать»');
    }

    public function cancelOrder(Request $request, WheelSet $set, IssueService $service): RedirectResponse
    {
        $wasReady = $set->status->value === 'ready';
        $set = $service->cancelOrder($set, $request->user());

        return back()->with('success', $wasReady
            ? 'Заказ к выдаче отменён: колёса возвращены на склад того же здания ('.$set->currentAddress().')'
            : 'Заказ к выдаче отменён');
    }

    public function issue(Request $request, WheelSet $set, IssueService $service): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string'], 'qty' => ['nullable', 'integer', 'min:0', 'max:8']]);
        $set = $service->issue($set, $data['code'], $data['qty'] ?? null, $request->user());
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => "Комплект {$set->qr_code} выдан", 'set' => Present::set($set)]);
        }

        return back()->with('success', "Комплект {$set->qr_code} выдан клиенту");
    }

    public function dispose(Request $request, WheelSet $set, IssueService $service): RedirectResponse
    {
        $service->dispose($set, $request->validate(['reason' => ['required', 'string', 'max:255']])['reason']);

        return back()->with('success', 'Комплект списан');
    }

    public function uploadPhoto(Request $request, WheelSet $set): RedirectResponse
    {
        $request->validate(['photos' => ['required', 'array', 'max:6'], 'photos.*' => ['image', 'max:12000']]);
        foreach ($request->file('photos') as $file) {
            $path = $file->store('photos/'.now()->format('Y/m'), 'local');
            Photo::create(['wheel_set_id' => $set->id, 'path' => $path, 'taken_by' => $request->user()->id, 'taken_at' => now()]);
        }

        return back()->with('success', 'Фото сохранены');
    }

    public function photo(Photo $photo)
    {
        abort_unless(Storage::disk('local')->exists($photo->path), 404);

        return Storage::disk('local')->response($photo->path);
    }
}
