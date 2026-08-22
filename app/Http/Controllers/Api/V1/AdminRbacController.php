<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Security\Rbac;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Defines the AdminRbacController class and its project responsibilities. */
class AdminRbacController extends Controller
{
    /** Handles the index request for this resource. */
    public function index(Request $request): JsonResponse
    {
        abort_unless(Rbac::allows($request->user(),'users.view'),403);
        $roles=[];
        foreach(array_keys((array)config('rbac.roles',[])) as $role){
            $roles[]=['role'=>$role,'permissions'=>Rbac::permissionsForRole($role)];
        }
        return response()->json(['data'=>['roles'=>$roles,'allPermissions'=>Rbac::allPermissions(),'notes'=>[
            'seller_staff'=>'Reserved until explicit vendor staff membership and scoped seller permissions are implemented.',
            'super_admin'=>'Receives every published permission including migration and production acceptance controls.',
        ]]]);
    }
}
