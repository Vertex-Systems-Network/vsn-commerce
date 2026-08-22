<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Defines the AdminVendorController class and its project responsibilities. */
class AdminVendorController extends Controller
{
    /** Handles the index request for this resource. */
    public function index(Request $request): JsonResponse
    {
        $this->admin($request);
        $rows = Vendor::query()->with('owner:id,name,email,role')->withCount('products')->latest('id')->paginate(100);
        return response()->json(['data'=>[
            'items'=>$rows->getCollection()->map(/** Inline callback for this operation. */ fn (Vendor $vendor)=>$this->row($vendor))->values(),
            'meta'=>['total'=>$rows->total(),'currentPage'=>$rows->currentPage(),'lastPage'=>$rows->lastPage()],
        ]]);
    }

    /** Handles the store request for this resource. */
    public function store(Request $request): JsonResponse
    {
        $this->admin($request);
        $data=$request->validate([
            'name'=>['required','string','max:160'],
            'slug'=>['nullable','string','max:190','unique:vendors,slug'],
            'ownerUserId'=>['required','integer','exists:users,id','unique:vendors,owner_user_id'],
            'status'=>['nullable','in:pending,active,suspended'],
            'commissionBps'=>['nullable','integer','min:0','max:10000'],
        ]);
        $owner=User::findOrFail($data['ownerUserId']);
        abort_unless($this->roleValue($owner)===UserRole::Seller->value,422,'Vendor owner must have the seller role.');
        $vendor=Vendor::create([
            'owner_user_id'=>$owner->id,
            'name'=>$data['name'],
            'slug'=>$data['slug'] ? Str::slug($data['slug']) : $this->uniqueSlug($data['name']),
            'status'=>$data['status']??'active',
            'commission_bps'=>$data['commissionBps']??1000,
        ]);
        return response()->json(['data'=>$this->row($vendor->load('owner')->loadCount('products'))],201);
    }

    /** Handles the update request for this resource. */
    public function update(Request $request, Vendor $vendor): JsonResponse
    {
        $this->admin($request);
        $data=$request->validate([
            'name'=>['sometimes','string','max:160'],
            'slug'=>['sometimes','string','max:190',Rule::unique('vendors','slug')->ignore($vendor->id)],
            'ownerUserId'=>['sometimes','integer','exists:users,id',Rule::unique('vendors','owner_user_id')->ignore($vendor->id)],
            'status'=>['sometimes','in:pending,active,suspended'],
            'commissionBps'=>['sometimes','integer','min:0','max:10000'],
        ]);
        if (isset($data['ownerUserId'])) {
            $owner=User::findOrFail($data['ownerUserId']);
            abort_unless($this->roleValue($owner)===UserRole::Seller->value,422,'Vendor owner must have the seller role.');
            $data['owner_user_id']=$data['ownerUserId']; unset($data['ownerUserId']);
        }
        if (isset($data['commissionBps'])) {$data['commission_bps']=$data['commissionBps'];unset($data['commissionBps']);}
        if (isset($data['slug'])) $data['slug']=Str::slug($data['slug']);
        $vendor->fill($data)->save();
        return response()->json(['data'=>$this->row($vendor->fresh()->load('owner')->loadCount('products'))]);
    }

    /** Handles row for the admin vendor controller workflow. */
    private function row(Vendor $vendor): array
    {
        return ['id'=>$vendor->id,'name'=>$vendor->name,'slug'=>$vendor->slug,'status'=>$vendor->status,'commissionBps'=>$vendor->commission_bps,'productsCount'=>$vendor->products_count??0,'owner'=>$vendor->owner?['id'=>$vendor->owner->id,'name'=>$vendor->owner->name,'email'=>$vendor->owner->email,'role'=>$this->roleValue($vendor->owner)]:null];
    }

    /** Handles unique slug for the admin vendor controller workflow. */
    private function uniqueSlug(string $name): string
    {
        $base=Str::slug($name)?:'vendor';$slug=$base;$i=2;while(Vendor::where('slug',$slug)->exists())$slug=$base.'-'.$i++;return $slug;
    }

    /** Handles admin for the admin vendor controller workflow. */
    private function admin(Request $request): void
    {
        abort_unless(in_array($this->roleValue($request->user()),[UserRole::Admin->value,UserRole::SuperAdmin->value],true),403);
    }
    /** Handles role value for the admin vendor controller workflow. */
    private function roleValue(?User $user): string {$role=$user?->role;return $role instanceof UserRole?$role->value:(string)$role;}
}
