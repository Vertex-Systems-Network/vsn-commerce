<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\MarketplaceSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Defines the AdminSettingsController class and its project responsibilities. */
class AdminSettingsController extends Controller
{
    private const DEFAULTS = [
        'store'=>['storeName'=>'VSN Ecommerce','defaultCurrency'=>'PKR','supportEmail'=>'','supportPhone'=>''],
        'orders'=>['orderingEnabled'=>true,'returnsWindowDays'=>14,'autoCancelUnpaidHours'=>24],
        'catalog'=>['lowStockThreshold'=>5,'sellerProductApprovalRequired'=>true],
        'operations'=>['maintenanceBanner'=>'','maintenanceBannerEnabled'=>false],
    ];

    /** Handles the index request for this resource. */
    public function index(Request $request): JsonResponse
    {
        $this->admin($request);
        $saved=MarketplaceSetting::query()->get()->groupBy('group');
        $groups=self::DEFAULTS;
        foreach($saved as $group=>$rows) foreach($rows as $row) $groups[$group][$row->key]=$row->value;
        $updatedAt=MarketplaceSetting::query()->max('updated_at');
        return response()->json(['data'=>['groups'=>$groups,'updatedAt'=>$updatedAt ? \Carbon\CarbonImmutable::parse($updatedAt)->toISOString() : null]]);
    }

    /** Handles the update request for this resource. */
    public function update(Request $request): JsonResponse
    {
        $this->admin($request);
        $data=$request->validate([
            'group'=>['required','in:store,orders,catalog,operations'],
            'values'=>['required','array'],
        ]);
        $allowed=array_keys(self::DEFAULTS[$data['group']]);
        $unknown=array_diff(array_keys($data['values']),$allowed);
        abort_if($unknown!==[],422,'One or more setting keys are not supported.');
        DB::transaction(/** Inline callback for this operation. */ function() use($data,$request): void {
            foreach($data['values'] as $key=>$value) {
                MarketplaceSetting::query()->updateOrCreate(
                    ['group'=>$data['group'],'key'=>$key],
                    ['value'=>$value,'updated_by'=>$request->user()->id]
                );
            }
        },3);
        return $this->index($request);
    }

    /** Handles admin for the admin settings controller workflow. */
    private function admin(Request $request): void
    {
        $role=$request->user()?->role; $value=$role instanceof UserRole?$role->value:(string)$role;
        abort_unless(in_array($value,[UserRole::Admin->value,UserRole::SuperAdmin->value],true),403);
    }
}
