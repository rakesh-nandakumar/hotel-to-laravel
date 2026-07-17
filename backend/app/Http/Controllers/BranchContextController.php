<?php

namespace App\Http\Controllers;

use App\Services\CurrentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchContextController extends Controller
{
    public function __construct(private readonly CurrentContext $context) {}

    /**
     * Switch the operational branch (top-bar selector). Stores the choice in the
     * session; ResolveBranchContext picks it up on subsequent requests.
     */
    public function select(Request $request): JsonResponse
    {
        $branchId = $request->input('branch_id');

        if ($branchId === null) {
            $request->session()->forget('selected_branch_id');

            return response()->json(['selected_id' => null]);
        }

        $id = (int) $branchId;

        if ($this->context->branches()->pluck('id')->contains($id)) {
            $request->session()->put('selected_branch_id', $id);
        }

        return response()->json(['selected_id' => $request->session()->get('selected_branch_id')]);
    }
}
