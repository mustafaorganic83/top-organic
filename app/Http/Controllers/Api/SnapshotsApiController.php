<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CostSnapshotResource;
use App\Models\CostSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SnapshotsApiController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CostSnapshot::class);
        $q = CostSnapshot::query()->latest('as_of_date');
        if ($t = $request->query('entity_type')) { $q->where('entity_type',$t); }
        if ($id = $request->query('entity_id')) { $q->where('entity_id',$id); }
        if ($m = $request->query('method')) { $q->where('method',$m); }
        return CostSnapshotResource::collection($q->paginate($request->integer('per_page', 25)));
    }

    public function show(CostSnapshot $snapshot): CostSnapshotResource
    {
        $this->authorize('view', $snapshot);
        return new CostSnapshotResource($snapshot);
    }
}
