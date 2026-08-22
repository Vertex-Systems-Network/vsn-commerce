<?php
namespace App\Http\Controllers\Api\V1;

use App\Domain\Games\Actions\JoinGame;
use App\Domain\Games\Exceptions\GameException;
use App\Enums\GameStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Games\JoinGameRequest;
use App\Http\Resources\GameEntryResource;
use App\Http\Resources\GameResource;
use App\Models\Game;
use App\Models\GameEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Defines the GameController class and its project responsibilities. */
class GameController extends Controller
{
    /** Handles the index request for this resource. */
    public function index(Request $request): JsonResponse
    {
        $games=Game::query()
            ->whereIn('status',[GameStatus::Scheduled->value,GameStatus::Open->value,GameStatus::Closed->value,GameStatus::WinnerSelected->value,GameStatus::Fulfilled->value])
            ->with(['product.images','product.vendor','draw.winner','draw.winningEntry','fulfillment.walletTransaction'])
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 WHEN status = 'scheduled' THEN 1 WHEN status = 'closed' THEN 2 ELSE 3 END")
            ->orderBy('announcement_at')
            ->get();
        return response()->json(['data'=>GameResource::collection($games)->resolve($request)]);
    }

    /** Handles the show request for this resource. */
    public function show(Request $request, Game $game): GameResource
    {
        abort_if($game->status === GameStatus::Draft,404);
        return new GameResource($game->load(['product.images','product.vendor','draw.winner','draw.winningEntry','fulfillment.walletTransaction']));
    }

    /** Handles my entries for the game controller workflow. */
    public function myEntries(Request $request): JsonResponse
    {
        $rows=GameEntry::query()->where('user_id',$request->user()->id)
            ->with(['refund','game.product.images','game.draw'])->latest('id')->paginate(50);
        return response()->json([
            'data'=>GameEntryResource::collection($rows->getCollection())->resolve($request),
            'meta'=>['currentPage'=>$rows->currentPage(),'lastPage'=>$rows->lastPage(),'total'=>$rows->total()],
        ]);
    }

    /** Handles join for the game controller workflow. */
    public function join(JoinGameRequest $request, Game $game, JoinGame $action): JsonResponse
    {
        $data=$request->validated();
        try {
            $entry=$action->execute($request->user(),$game,(int)$data['entries'],$data['idempotencyKey'],[
                'ip_hash'=>hash('sha256',(string)$request->ip()),
                'user_agent_hash'=>hash('sha256',(string)$request->userAgent()),
            ]);
        } catch (GameException $e) {
            return response()->json(['message'=>$e->getMessage(),'errors'=>[$e->field=>[$e->getMessage()]]],422);
        }
        return response()->json(['data'=>(new GameEntryResource($entry))->toArray($request)],201);
    }
}
