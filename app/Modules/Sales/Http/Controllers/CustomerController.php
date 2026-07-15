<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerMembership;
use App\Models\MembershipTier;
use App\Modules\Sales\Exceptions\SalesException;
use App\Modules\Sales\Http\Requests\CustomerRequest;
use App\Modules\Sales\Http\Requests\IndexRequest;
use App\Modules\Sales\Http\Resources\SalesResource;
use App\Modules\Sales\Services\CustomerHistoryService;
use Illuminate\Http\JsonResponse;

class CustomerController extends Controller
{
    public function index(IndexRequest $request): JsonResponse
    {
        $context = $request->salesContext();
        $query = Customer::withoutGlobalScopes()->where('tenant_id', $context->tenantId)->where('status', 'active');
        if ($request->filled('query')) {
            $term = $request->validated('query');
            $hash = hash_hmac('sha256', mb_strtolower(trim($term)), (string) config('app.key'));
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')->orWhere('customer_number', $term)
                ->orWhere('phone_hash', $hash)->orWhere('email_hash', $hash));
        }

        return response()->json(SalesResource::paginated($query->latest()->paginate($request->perPage()), SalesResource::customer(...)));
    }

    public function store(CustomerRequest $request): JsonResponse
    {
        $context = $request->salesContext();
        $data = $request->safe()->only(['name', 'phone', 'email', 'locale']);
        $data += ['tenant_id' => $context->tenantId, 'customer_number' => (string) str()->ulid(),
            'phone_hash' => $this->hash($data['phone'] ?? null), 'email_hash' => $this->hash($data['email'] ?? null), 'status' => 'active'];

        return response()->json(['data' => SalesResource::customer(Customer::withoutGlobalScopes()->create($data))], 201);
    }

    public function show(string $customer, CustomerRequest $request): JsonResponse
    {
        return response()->json(['data' => SalesResource::customer($this->customer($request, $customer))]);
    }

    public function update(string $customer, CustomerRequest $request): JsonResponse
    {
        $model = $this->customer($request, $customer);
        if ($model->lock_version !== $request->integer('expected_version')) {
            throw SalesException::conflict(SalesException::STALE_VERSION, 'The customer was changed by another operation.');
        }
        $data = $request->safe()->only(['name', 'phone', 'email', 'locale', 'status']);
        if (array_key_exists('phone', $data)) {
            $data['phone_hash'] = $this->hash($data['phone']);
        }
        if (array_key_exists('email', $data)) {
            $data['email_hash'] = $this->hash($data['email']);
        }
        $data['lock_version'] = $model->lock_version + 1;
        $model->fill($data)->save();

        return response()->json(['data' => SalesResource::customer($model->refresh())]);
    }

    public function history(string $customer, IndexRequest $request, CustomerHistoryService $history): JsonResponse
    {
        return response()->json(['data' => $history->history($request->salesContext(), $customer, $request->perPage())]);
    }

    public function membership(string $customer, CustomerRequest $request): JsonResponse
    {
        $model = $this->customer($request, $customer);
        $context = $request->salesContext();
        $tier = MembershipTier::withoutGlobalScopes()->where('tenant_id', $context->tenantId)
            ->whereKey($request->validated('membership_tier_id'))->where('status', 'active')->first()
            ?? throw new SalesException(SalesException::NOT_FOUND, 404, 'The membership tier was not found.');
        CustomerMembership::withoutGlobalScopes()->where('tenant_id', $context->tenantId)->where('customer_id', $model->id)
            ->where('status', 'active')->update(['status' => 'replaced']);
        CustomerMembership::withoutGlobalScopes()->create(['tenant_id' => $context->tenantId, 'customer_id' => $model->id,
            'membership_tier_id' => $tier->id, 'membership_number' => $request->validated('membership_number'),
            'status' => 'active', 'started_at' => now(), 'expires_at' => $request->validated('expires_at')]);

        return response()->json(['data' => SalesResource::customer($model->refresh())], 201);
    }

    private function customer(CustomerRequest|IndexRequest $request, string $id): Customer
    {
        $context = $request->salesContext();

        return Customer::withoutGlobalScopes()->where('tenant_id', $context->tenantId)->whereKey($id)->first()
            ?? throw new SalesException(SalesException::NOT_FOUND, 404, 'The customer was not found.');
    }

    private function hash(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : hash_hmac('sha256', mb_strtolower(trim($value)), (string) config('app.key'));
    }
}
