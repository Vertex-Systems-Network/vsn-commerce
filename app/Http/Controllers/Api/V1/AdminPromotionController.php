<?php
namespace App\Http\Controllers\Api\V1;
use App\Domain\Promotions\Services\PromotionManagementService;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
/** Defines the AdminPromotionController class and its project responsibilities. */
class AdminPromotionController extends Controller
{
    /** Handles the index request for this resource. */
    public function index(Request $r):JsonResponse{$this->admin($r);$rows=Promotion::query()->with(['vendor','scopes.product','scopes.category','codes'])->latest()->get();return response()->json(['data'=>['items'=>$rows->map(/** Inline callback for this operation. */ fn($p)=>$this->row($p))->all(),'products'=>Product::query()->published()->orderBy('name')->limit(1000)->get(['id','name','slug','vendor_id'])->toArray(),'categories'=>Category::query()->orderBy('name')->get(['id','name','slug'])->toArray(),'vendors'=>Vendor::query()->orderBy('name')->get(['id','name','slug'])->toArray()]]);}
    /** Handles the store request for this resource. */
    public function store(Request $r,PromotionManagementService $s):JsonResponse{$this->admin($r);$p=$s->create($this->data($r,false),null,true);return response()->json(['data'=>$this->row($p)],201);}
    /** Handles the update request for this resource. */
    public function update(Request $r,Promotion $promotion,PromotionManagementService $s):JsonResponse{$this->admin($r);$p=$s->update($promotion,$this->data($r,true),null,true);return response()->json(['data'=>$this->row($p)]);}
    /** Handles status for the admin promotion controller workflow. */
    public function status(Request $r,Promotion $promotion,PromotionManagementService $s):JsonResponse{$this->admin($r);$d=$r->validate(['status'=>['required',Rule::in(['draft','active','paused','ended'])]]);return response()->json(['data'=>$this->row($s->setStatus($promotion,$d['status'],null,true))]);}
    /** Handles admin for the admin promotion controller workflow. */
    private function admin(Request $r):void{$v=$r->user()?->role instanceof UserRole?$r->user()->role->value:(string)$r->user()?->role;abort_unless(in_array($v,[UserRole::Admin->value,UserRole::SuperAdmin->value],true),403);}
    /** Handles data for the admin promotion controller workflow. */
    private function data(Request $r,bool $partial):array{$sometimes=$partial?'sometimes':'required';return $r->validate(['vendorId'=>['sometimes','nullable','integer','exists:vendors,id'],'name'=>[$sometimes,'string','max:190'],'kind'=>[$sometimes,Rule::in(['automatic','flash','coupon'])],'discountType'=>[$sometimes,Rule::in(['percent','fixed'])],'percentBps'=>['nullable','integer','min:1','max:9000'],'fixedMinor'=>['nullable','integer','min:1'],'minimumSubtotalMinor'=>['nullable','integer','min:0'],'stackingMode'=>['nullable',Rule::in(['stackable','exclusive'])],'canStackWithCoupon'=>['nullable','boolean'],'canStackWithReviewCoupon'=>['nullable','boolean'],'fundingMode'=>['nullable',Rule::in(['platform','seller','shared'])],'platformShareBps'=>['nullable','integer','min:0','max:10000'],'priority'=>['nullable','integer','min:-1000','max:1000'],'maxRedemptions'=>['nullable','integer','min:1'],'perUserLimit'=>['nullable','integer','min:1'],'startsAt'=>['nullable','date'],'endsAt'=>['nullable','date'],'timezone'=>['nullable','timezone'],'appliesToGifts'=>['nullable','boolean'],'scopes'=>['sometimes','array','max:100'],'scopes.*.type'=>['required_with:scopes',Rule::in(['all','product','category'])],'scopes.*.id'=>['nullable','integer'],'codes'=>['sometimes','array','max:50'],'codes.*'=>['string','max:80','regex:/^[A-Za-z0-9_-]+$/'],'codeMaxRedemptions'=>['nullable','integer','min:1'],'codePerUserLimit'=>['nullable','integer','min:1']]);}
    /** Handles row for the admin promotion controller workflow. */
    private function row(Promotion $p):array{$p->loadMissing(['vendor','scopes.product','scopes.category','codes']);return ['id'=>$p->public_id,'vendor'=>$p->vendor?->name,'vendorId'=>$p->vendor_id,'name'=>$p->name,'slug'=>$p->slug,'kind'=>$p->kind,'status'=>$p->status,'discountType'=>$p->discount_type,'percentBps'=>$p->percent_bps,'fixedMinor'=>$p->fixed_minor,'minimumSubtotalMinor'=>$p->minimum_subtotal_minor,'stackingMode'=>$p->stacking_mode,'canStackWithCoupon'=>$p->can_stack_with_coupon,'canStackWithReviewCoupon'=>$p->can_stack_with_review_coupon,'fundingMode'=>$p->funding_mode,'platformShareBps'=>$p->platform_share_bps,'priority'=>$p->priority,'maxRedemptions'=>$p->max_redemptions,'perUserLimit'=>$p->per_user_limit,'startsAt'=>$p->starts_at?->toISOString(),'endsAt'=>$p->ends_at?->toISOString(),'timezone'=>$p->timezone,'scopes'=>$p->scopes->map(/** Inline callback for this operation. */ fn($x)=>['type'=>$x->scope_type,'id'=>$x->product_id??$x->category_id,'label'=>$x->product?->name??$x->category?->name??'All products'])->all(),'codes'=>$p->codes->map(/** Inline callback for this operation. */ fn($x)=>['id'=>$x->public_id,'code'=>$x->code,'status'=>$x->status])->all(),'usage'=>['reserved'=>$p->usages()->where('status','reserved')->count(),'redeemed'=>$p->usages()->where('status','redeemed')->count()]];}
}
