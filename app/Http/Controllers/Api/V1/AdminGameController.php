<?php
namespace App\Http\Controllers\Api\V1;

use App\Domain\Games\Actions\CancelGame;
use App\Domain\Games\Actions\CloseGame;
use App\Domain\Games\Actions\CreateGame;
use App\Domain\Games\Actions\DrawGame;
use App\Domain\Games\Actions\FulfillGamePrize;
use App\Domain\Games\Actions\RefundCancelledGameEntries;
use App\Domain\Games\Exceptions\GameException;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Games\CancelGameRequest;
use App\Http\Requests\Games\CreateGameRequest;
use App\Http\Requests\Games\FulfillGameRequest;
use App\Http\Resources\GameResource;
use App\Models\Game;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Defines the AdminGameController class and its project responsibilities. */
class AdminGameController extends Controller
{
    /** Handles the store request for this resource. */
    public function store(CreateGameRequest $request, CreateGame $action): GameResource|JsonResponse
    {
        $this->authorizeAdmin($request);
        $data=$request->validated(); $product=Product::query()->where('slug',$data['productSlug'])->firstOrFail();
        try {
            $game=$action->execute(
                $product,(int)$data['entryCoins'],Carbon::parse($data['opensAt']),Carbon::parse($data['closesAt']),Carbon::parse($data['announcementAt']),
                isset($data['maxEntries'])?(int)$data['maxEntries']:null,$data['rulesVersion']??(string)config('vsn.games.rules_version','2026-08'),[],
                isset($data['maxEntriesPerUser'])?(int)$data['maxEntriesPerUser']:null,(int)($data['winnerBonusCoins']??0)
            );
        } catch(GameException $e) { return $this->error($e); }
        return new GameResource($game->load(['product.images','product.vendor','draw.winner','draw.winningEntry','fulfillment.walletTransaction']));
    }

    /** Handles close for the admin game controller workflow. */
    public function close(Request $request, Game $game, CloseGame $action): GameResource|JsonResponse
    {
        $this->authorizeAdmin($request);
        try { $game=$action->execute($game); } catch(GameException $e){ return $this->error($e); }
        return new GameResource($game->load(['product.images','product.vendor','draw.winner','draw.winningEntry','fulfillment.walletTransaction']));
    }

    /** Handles draw for the admin game controller workflow. */
    public function draw(Request $request, Game $game, DrawGame $action): GameResource|JsonResponse
    {
        $this->authorizeAdmin($request);
        try { $action->execute($game); } catch(GameException $e){ return $this->error($e); }
        return new GameResource($game->fresh()->load(['product.images','product.vendor','draw.winner','draw.winningEntry','fulfillment.walletTransaction']));
    }

    /** Handles cancel for the admin game controller workflow. */
    public function cancel(CancelGameRequest $request, Game $game, CancelGame $action, RefundCancelledGameEntries $refunds): GameResource|JsonResponse
    {
        $this->authorizeAdmin($request);
        try { $game=$action->execute($game,$request->validated('reason')); } catch(GameException $e){ return $this->error($e); }
        $refunds->execute($game,(int)config('vsn.games.refund_batch_size',200));
        return new GameResource($game->fresh()->load(['product.images','product.vendor','draw.winner','draw.winningEntry','fulfillment.walletTransaction']));
    }

    /** Handles fulfill for the admin game controller workflow. */
    public function fulfill(FulfillGameRequest $request, Game $game, FulfillGamePrize $action): GameResource|JsonResponse
    {
        $this->authorizeAdmin($request);
        $data=$request->validated();
        try { $action->execute($game,$request->user(),$data['method']??'manual_handoff',$data['reference']??null,$data['note']??null); } catch(GameException $e){ return $this->error($e); }
        return new GameResource($game->fresh()->load(['product.images','product.vendor','draw.winner','draw.winningEntry','fulfillment.walletTransaction']));
    }

    /** Handles process refunds for the admin game controller workflow. */
    public function processRefunds(Request $request, Game $game, RefundCancelledGameEntries $action): JsonResponse
    {
        $this->authorizeAdmin($request);
        return response()->json(['data'=>['processed'=>$action->execute($game,(int)config('vsn.games.refund_batch_size',200))]]);
    }

    /** Handles authorize admin for the admin game controller workflow. */
    private function authorizeAdmin(Request $request): void
    {
        $role=$request->user()?->role;
        $value=$role instanceof UserRole?$role->value:(string)$role;
        abort_unless(in_array($value,[UserRole::Admin->value,UserRole::SuperAdmin->value],true),403);
    }
    /** Handles error for the admin game controller workflow. */
    private function error(GameException $e): JsonResponse { return response()->json(['message'=>$e->getMessage(),'errors'=>[$e->field=>[$e->getMessage()]]],422); }
}
