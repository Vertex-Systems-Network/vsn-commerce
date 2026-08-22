<?php
namespace App\Http\Controllers\Api\V1;

use App\Domain\Affiliate\Actions\AccrueAffiliateCommissions;
use App\Domain\Affiliate\Actions\CreditAvailableAffiliateCommissions;
use App\Domain\Affiliate\Actions\MatureAffiliateCommissions;
use App\Domain\Wallet\Services\CoinLotService;
use App\Domain\Wallet\Services\WalletService;
use App\Enums\AffiliateAccountStatus;
use App\Enums\AffiliateCommissionStatus;
use App\Enums\UserRole;
use App\Enums\WalletTransactionType;
use App\Http\Controllers\Controller;
use App\Http\Resources\AffiliateCommissionResource;
use App\Http\Resources\GameEntryResource;
use App\Http\Resources\GameResource;
use App\Models\AffiliateAccount;
use App\Models\AffiliateAccountEvent;
use App\Models\AffiliateCommission;
use App\Models\Game;
use App\Models\GameEntry;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletCoinLot;
use App\Models\WalletTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Defines the AdminEngagementController class and its project responsibilities. */
class AdminEngagementController extends Controller
{
    /** Handles summary for the admin engagement controller workflow. */
    public function summary(Request $request): JsonResponse
    {
        $this->admin($request);
        return response()->json(['data'=>[
            'wallet'=>[
                'wallets'=>Wallet::query()->count(),
                'balanceCoins'=>(int)Wallet::query()->sum('balance_coins'),
                'reservedCoins'=>(int)Wallet::query()->sum('reserved_coins'),
                'expiring30Days'=>(int)WalletCoinLot::query()->where('remaining_coins','>',0)->whereNotNull('expires_at')->whereBetween('expires_at',[now(),now()->addDays(30)])->sum('remaining_coins'),
            ],
            'affiliate'=>[
                'activeAccounts'=>AffiliateAccount::query()->where('status',AffiliateAccountStatus::Active->value)->count(),
                'pendingCoins'=>(int)AffiliateCommission::query()->whereIn('status',[AffiliateCommissionStatus::Pending->value,AffiliateCommissionStatus::Available->value])->sum('reward_coins'),
                'creditedCoins'=>(int)AffiliateCommission::query()->where('status',AffiliateCommissionStatus::Credited->value)->sum('reward_coins'),
            ],
            'games'=>[
                'active'=>Game::query()->whereIn('status',['scheduled','open','closed'])->count(),
                'entries'=>(int)GameEntry::query()->sum('quantity'),
                'coinsSpent'=>(int)GameEntry::query()->sum('coins_spent'),
                'winners'=>Game::query()->whereIn('status',['winner_selected','fulfilled'])->count(),
            ],
        ]]);
    }

    /** Handles wallets for the admin engagement controller workflow. */
    public function wallets(Request $request, CoinLotService $lots): JsonResponse
    {
        $this->admin($request);
        $data=$request->validate(['q'=>'nullable|string|max:190','perPage'=>'nullable|integer|min:1|max:100']);
        $q=trim((string)($data['q']??''));
        $rows=User::query()->whereHas('wallet')->with('wallet')
            ->when($q!=='',/** Inline callback for this operation. */ fn($x)=>$x->where(/** Inline callback for this operation. */ fn($y)=>$y->where('name','like',"%{$q}%")->orWhere('email','like',"%{$q}%")))
            ->latest('id')->paginate((int)($data['perPage']??50));
        return response()->json(['data'=>[
            'items'=>$rows->getCollection()->map(/** Inline callback for this operation. */ function(User $u) use($lots){$s=$lots->summary($u);return ['userId'=>$u->id,'name'=>$u->name,'email'=>$u->email,'balanceCoins'=>(int)$u->wallet->balance_coins,'reservedCoins'=>(int)$u->wallet->reserved_coins,'availableCoins'=>$u->wallet->availableCoins(),...$s];})->values(),
            'meta'=>['total'=>$rows->total(),'currentPage'=>$rows->currentPage(),'lastPage'=>$rows->lastPage()],
        ]]);
    }

    /** Handles adjust wallet for the admin engagement controller workflow. */
    public function adjustWallet(Request $request, User $user, WalletService $wallets): JsonResponse
    {
        $this->admin($request);
        $data=$request->validate(['coins'=>'required|integer|not_in:0','reason'=>'required|string|min:5|max:500','expiresInDays'=>'nullable|integer|min:1|max:3650']);
        $coins=(int)$data['coins'];
        $metadata=['reason'=>$data['reason'],'admin_user_id'=>$request->user()->id];
        if($coins>0 && !empty($data['expiresInDays'])) $metadata['expires_at']=now()->addDays((int)$data['expiresInDays'])->toIso8601String();
        $key='admin-wallet:'.$request->user()->id.':'.$user->id.':'.Str::uuid();
        $tx=$coins>0
            ?$wallets->credit($user,$coins,WalletTransactionType::AdminAdjustment,$key,'admin_adjustment',(string)$request->user()->id,$metadata)
            :$wallets->debit($user,abs($coins),WalletTransactionType::AdminAdjustment,$key,'admin_adjustment',(string)$request->user()->id,$metadata,false);
        return response()->json(['data'=>['transactionId'=>$tx->public_id,'balanceCoins'=>(int)$wallets->walletFor($user)->balance_coins]]);
    }

    /** Handles expire coins for the admin engagement controller workflow. */
    public function expireCoins(Request $request, CoinLotService $lots): JsonResponse
    {
        $this->admin($request);
        $data=$request->validate(['limit'=>'nullable|integer|min:1|max:5000']);
        return response()->json(['data'=>['processed'=>$lots->expireDue((int)($data['limit']??config('vsn.wallet.expiry_batch_size',500)))]]);
    }

    /** Handles affiliate accounts for the admin engagement controller workflow. */
    public function affiliateAccounts(Request $request): JsonResponse
    {
        $this->admin($request);
        $rows=AffiliateAccount::query()->with(['user:id,name,email'])->withCount('referrals')->latest()->limit(250)->get();
        return response()->json(['data'=>$rows->map(/** Inline callback for this operation. */ fn($a)=>[
            'id'=>$a->id,'userId'=>$a->user_id,'name'=>$a->user?->name,'email'=>$a->user?->email,'referralCode'=>$a->referral_code,
            'status'=>$a->status->value,'referralsCount'=>$a->referrals_count,'termsVersion'=>$a->terms_version,'createdAt'=>$a->created_at?->toIso8601String(),
        ])->all()]);
    }

    /** Handles affiliate status for the admin engagement controller workflow. */
    public function affiliateStatus(Request $request, AffiliateAccount $affiliateAccount): JsonResponse
    {
        $this->admin($request);
        $data=$request->validate(['status'=>['required',Rule::enum(AffiliateAccountStatus::class)],'reason'=>'required|string|min:5|max:1000']);
        $to=AffiliateAccountStatus::from((string)$data['status']);
        $from=$affiliateAccount->status;
        if($from===AffiliateAccountStatus::Closed && $to!==AffiliateAccountStatus::Closed) abort(422,'Closed affiliate accounts cannot be reopened.');
        DB::transaction(/** Inline callback for this operation. */ function() use($affiliateAccount,$request,$data,$from,$to):void{
            $affiliateAccount=AffiliateAccount::query()->whereKey($affiliateAccount->id)->lockForUpdate()->firstOrFail();
            $affiliateAccount->forceFill(['status'=>$to,'suspended_at'=>$to===AffiliateAccountStatus::Suspended?now():null])->save();
            AffiliateAccountEvent::create(['affiliate_account_id'=>$affiliateAccount->id,'actor_user_id'=>$request->user()->id,'event_type'=>'status_changed','from_status'=>$from->value,'to_status'=>$to->value,'reason'=>$data['reason'],'occurred_at'=>now()]);
        },3);
        return response()->json(['data'=>['id'=>$affiliateAccount->id,'status'=>$to->value]]);
    }

    /** Handles affiliate commissions for the admin engagement controller workflow. */
    public function affiliateCommissions(Request $request): JsonResponse
    {
        $this->admin($request);
        $rows=AffiliateCommission::query()->with(['order','beneficiary:id,name,email'])->latest('id')->paginate(75);
        return response()->json(['data'=>[
            'items'=>$rows->getCollection()->map(/** Inline callback for this operation. */ fn($c)=>array_merge((new AffiliateCommissionResource($c))->resolve($request),['beneficiary'=>['name'=>$c->beneficiary?->name,'email'=>$c->beneficiary?->email]]))->values(),
            'meta'=>['total'=>$rows->total(),'currentPage'=>$rows->currentPage(),'lastPage'=>$rows->lastPage()],
        ]]);
    }

    /** Handles process affiliate for the admin engagement controller workflow. */
    public function processAffiliate(Request $request, AccrueAffiliateCommissions $accrue, MatureAffiliateCommissions $mature, CreditAvailableAffiliateCommissions $credit): JsonResponse
    {
        $this->admin($request);
        $data=$request->validate(['limit'=>'nullable|integer|min:1|max:2000']); $limit=(int)($data['limit']??500); $accruedOrders=0;
        Order::query()->where('payment_status','paid')->whereNull('affiliate_accrued_at')->orderBy('id')->limit($limit)->get()->each(/** Inline callback for this operation. */ function($order) use($accrue,&$accruedOrders){$accrue->execute($order);$accruedOrders++;});
        return response()->json(['data'=>['accruedOrders'=>$accruedOrders,'matured'=>$mature->execute($limit),'credited'=>$credit->execute($limit)]]);
    }

    /** Handles games for the admin engagement controller workflow. */
    public function games(Request $request): JsonResponse
    {
        $this->admin($request);
        $rows=Game::query()->with(['product.images','product.vendor','draw.winner','draw.winningEntry','fulfillment.walletTransaction'])->latest('id')->limit(200)->get();
        return response()->json(['data'=>GameResource::collection($rows)->resolve($request)]);
    }

    /** Handles game entries for the admin engagement controller workflow. */
    public function gameEntries(Request $request, Game $game): JsonResponse
    {
        $this->admin($request);
        $rows=GameEntry::query()->where('game_id',$game->id)->with(['user:id,name,email','refund','game.product.images','game.draw'])->latest()->paginate(100);
        return response()->json(['data'=>[
            'items'=>$rows->getCollection()->map(/** Inline callback for this operation. */ fn($e)=>array_merge((new GameEntryResource($e))->resolve($request),['user'=>['name'=>$e->user?->name,'email'=>$e->user?->email],'ipHash'=>$e->ip_hash,'userAgentHash'=>$e->user_agent_hash]))->values(),
            'meta'=>['total'=>$rows->total(),'currentPage'=>$rows->currentPage(),'lastPage'=>$rows->lastPage()],
        ]]);
    }

    /** Handles admin for the admin engagement controller workflow. */
    private function admin(Request $request): void
    {
        $role=$request->user()?->role; $value=$role instanceof UserRole?$role->value:(string)$role;
        abort_unless(in_array($value,[UserRole::Admin->value,UserRole::SuperAdmin->value],true),403);
    }
}
