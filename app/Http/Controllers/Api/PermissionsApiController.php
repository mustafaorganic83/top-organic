<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use Illuminate\Http\Request;

class PermissionsApiController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        // If roles/permissions are materialized, return both assigned roles and flattened permissions
        $perms = Permission::query()->select(['public_id','name','label','risk_level'])->get();
        return response()->json([
            'data' => [
                'user_id' => (string)$user->id,
                'permissions' => $perms,
            ],
        ]);
    }
}
