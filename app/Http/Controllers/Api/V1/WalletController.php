<?php
namespace App\Http\Controllers\Api\V1;

use App\Domain\Payments\Exceptions\PaymentException;
use App\Domain\Wallet\Actions\CreateCoinPurchase;
use App\Domain\Wallet\Actions\DailyWalletCheckin;
use App\Domain\Wallet\Actions\TransferCoins;
use App\Domain\Wallet\Exceptions\WalletException;
use App\Domain\Wallet\Services\WalletService;
use App\Domain\Wallet\Services\CoinLotService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\CreateCoinPurchaseRequest;
use App\Http\Requests\Wallet\TransferCoinsRequest;
use App\Http\Resources\CoinPurchaseResource;
use App\Http\Resources\WalletTransactionResource;
use App\Models\CoinPurchase;
use App\Models\DailyCheckin;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Defines the WalletController class and its project responsibilities. */
class WalletController extends Controller
{
    /** Handles the show request for this resource. */
    public function show(Request $request, WalletService $wallets, CoinLotService $lots): JsonResponse
    {
        $user = $request->user()->loadMissing('profile');
        $wallet = $wallets->walletFor($user);
        $timezone = $user->profile?->timezone ?: config('app.timezone','UTC');
        $today = Carbon::now($timezone)->toDateString();
        $last = DailyCheckin::query()->where('user_id',$user->id)->latest('checkin_date')->first();
        $todayCheckin = $last && $last->checkin_date->toDateString() === $today ? $last : null;
        $transactions = WalletTransaction::query()->whereHas('entries',/** Inline callback for this operation. */ fn($q)=>$q->where('user_id',$user->id))->with(['entries'=>/** Inline callback for this operation. */ fn($q)=>$q->where('user_id',$user->id)])->latest('occurred_at')->limit(20)->get();
        $perRupee=(int)config('vsn.coins_per_rupee',70);
        return response()->json(['data'=>[
            'balanceCoins'=>$wallet->balance_coins,'reservedCoins'=>$wallet->reserved_coins,'availableCoins'=>$wallet->availableCoins(),'coinsPerRupee'=>$perRupee,
            'valueRupees'=>round($wallet->balance_coins/$perRupee,2),
            'checkin'=>['claimedToday'=>(bool)$todayCheckin,'streakDay'=>(int)($last?->streak_day??0),'baseRewardCoins'=>(int)config('vsn.wallet.daily_checkin_coins',70),'sevenDayBonusCoins'=>(int)config('vsn.wallet.seven_day_bonus_coins',350),'lastDate'=>$last?->checkin_date?->toDateString()],
            'expiration'=>$lots->summary($user),
            'transactions'=>WalletTransactionResource::collection($transactions)->resolve($request),
        ]]);
    }

    /** Handles transactions for the wallet controller workflow. */
    public function transactions(Request $request): JsonResponse
    {
        $data=$request->validate(['type'=>['nullable','string','max:50'],'direction'=>['nullable','in:credit,debit']]);
        $rows=WalletTransaction::query()->whereHas('entries',/** Inline callback for this operation. */ fn($q)=>$q->where('user_id',$request->user()->id)->when(!empty($data['direction']),/** Inline callback for this operation. */ fn($x)=>$x->where('direction',$data['direction'])))
            ->when(!empty($data['type']),/** Inline callback for this operation. */ fn($q)=>$q->where('type',$data['type']))
            ->with(['entries'=>/** Inline callback for this operation. */ fn($q)=>$q->where('user_id',$request->user()->id)])->latest('occurred_at')->paginate(30);
        return response()->json(['data'=>WalletTransactionResource::collection($rows->getCollection())->resolve($request),'meta'=>['currentPage'=>$rows->currentPage(),'lastPage'=>$rows->lastPage(),'total'=>$rows->total()]]);
    }

    /** Handles checkin for the wallet controller workflow. */
    public function checkin(Request $request, DailyWalletCheckin $action): JsonResponse
    {
        try { $row=$action->execute($request->user()->loadMissing('profile')); }
        catch(WalletException $e){ return $this->walletError($e); }
        return response()->json(['data'=>['date'=>$row->checkin_date->toDateString(),'streakDay'=>$row->streak_day,'baseRewardCoins'=>$row->base_reward_coins,'bonusRewardCoins'=>$row->bonus_reward_coins,'totalRewardCoins'=>$row->base_reward_coins+$row->bonus_reward_coins]]);
    }

    /** Handles transfer for the wallet controller workflow. */
    public function transfer(TransferCoinsRequest $request, TransferCoins $action): JsonResponse
    {
        $data=$request->validated(); $recipientText=trim($data['recipient']);
        $recipient=User::query()->where('email',$recipientText)->orWhereHas('profile',/** Inline callback for this operation. */ fn($q)=>$q->where('phone',$recipientText))->first();
        if(!$recipient) return response()->json(['message'=>'Recipient was not found.','errors'=>['recipient'=>['Recipient was not found.']]],422);
        try { $tx=$action->execute($request->user(),$recipient,(int)$data['coins'],$data['idempotencyKey'],true); }
        catch(WalletException $e){ return $this->walletError($e); }
        $tx->load(['entries'=>/** Inline callback for this operation. */ fn($q)=>$q->where('user_id',$request->user()->id)]);
        return response()->json(['data'=>(new WalletTransactionResource($tx))->toArray($request)]);
    }

    /** Handles purchase for the wallet controller workflow. */
    public function purchase(CreateCoinPurchaseRequest $request, CreateCoinPurchase $action): CoinPurchaseResource|JsonResponse
    {
        $data=$request->validated();
        try { $purchase=$action->execute($request->user(),(int)$data['coins'],$data['idempotencyKey']); }
        catch(WalletException|PaymentException $e){ $field=$e instanceof WalletException?$e->field:$e->field; return response()->json(['message'=>$e->getMessage(),'errors'=>[$field=>[$e->getMessage()]]],422); }
        return new CoinPurchaseResource($purchase);
    }

    /** Handles purchase show for the wallet controller workflow. */
    public function purchaseShow(Request $request, CoinPurchase $coinPurchase): CoinPurchaseResource
    {
        abort_unless($coinPurchase->user_id===$request->user()->id,404);
        return new CoinPurchaseResource($coinPurchase->load('paymentIntent'));
    }

    /** Handles wallet error for the wallet controller workflow. */
    private function walletError(WalletException $e): JsonResponse { return response()->json(['message'=>$e->getMessage(),'errors'=>[$e->field=>[$e->getMessage()]]],422); }
}
