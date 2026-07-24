<?php

namespace App\Http\Controllers\Apartment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Apartment\StoreCustomerRequest;
use App\Http\Requests\Apartment\UpdateCustomerRequest;
use App\Models\Apartment\Customer;
use App\Services\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Customer::query();

        if ($term = $request->string('q')->toString()) {
            $query->search($term);
        }

        $query->latest();

        if (! $request->has('page')) {
            return response()->json(['customers' => $query->limit(100)->get()]);
        }

        return response()->json(['customers' => $query->paginate($request->integer('page_size', 25))->withQueryString()]);
    }

    public function show(Customer $customer): JsonResponse
    {
        return response()->json(['customer' => $customer]);
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = Customer::create($request->validated());

        AuditLog::record('apartment_customer.created', $customer, ['name' => $customer->name]);

        return response()->json(['message' => "Customer \"{$customer->name}\" created.", 'customer' => $customer], 201);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $customer->update($request->validated());

        AuditLog::record('apartment_customer.updated', $customer, ['name' => $customer->name]);

        return response()->json(['message' => 'Customer updated.', 'customer' => $customer]);
    }
}
