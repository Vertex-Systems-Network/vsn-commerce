<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** Defines the DemoController class and its project responsibilities. */
class DemoController extends Controller
{
    /** Handles accounts for the demo controller workflow. */
    public function accounts(): JsonResponse
    {
        abort_unless(config('vsn.demo.enabled', false), 404);
        return response()->json(['data'=>[
            ['role'=>'Customer','email'=>'customer@example.test','password'=>'ChangeMe12345','landing'=>'/account'],
            ['role'=>'Seller','email'=>'seller@example.test','password'=>'ChangeMe12345','landing'=>'/vendor'],
            ['role'=>'Support','email'=>'support@example.test','password'=>'ChangeMe12345','landing'=>'/admin'],
            ['role'=>'Moderator','email'=>'moderator@example.test','password'=>'ChangeMe12345','landing'=>'/admin'],
            ['role'=>'Finance','email'=>'finance@example.test','password'=>'ChangeMe12345','landing'=>'/admin'],
            ['role'=>'Admin','email'=>'ops-admin@example.test','password'=>'ChangeMe12345','landing'=>'/admin'],
            ['role'=>'Super Admin','email'=>'admin@example.test','password'=>'ChangeMe12345','landing'=>'/admin'],
        ]]);
    }
}
