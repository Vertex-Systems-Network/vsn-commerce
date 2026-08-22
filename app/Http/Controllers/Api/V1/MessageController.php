<?php
namespace App\Http\Controllers\Api\V1;
use App\Domain\Messaging\Actions\OpenConversation;
use App\Domain\Messaging\Actions\SendConversationMessage;
use App\Domain\Messaging\Exceptions\MessagingException;
use App\Domain\Messaging\Services\ConversationAccess;
use App\Domain\Messaging\Services\UnreadMessageCounter;
use App\Domain\Security\Services\SecureUploadInspector;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationMessageResource;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\MessageAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
/** Defines the MessageController class and its project responsibilities. */
class MessageController extends Controller
{
    /** Handles the index request for this resource. */
    public function index(Request $request,UnreadMessageCounter $counter):JsonResponse
    {
        $user=$request->user();$role=$user->role instanceof UserRole?$user->role->value:(string)$user->role;
        $query=Conversation::query()->with(['order','vendorOrder','vendor','participants.user','messages'=>/** Inline callback for this operation. */ fn($q)=>$q->with(['sender','attachments','conversation.vendor'])->latest('id')->limit(60)]);
        if(in_array($role,[UserRole::Support->value,UserRole::Admin->value,UserRole::SuperAdmin->value],true))$query->where(/** Inline callback for this operation. */ fn($q)=>$q->whereHas('participants',/** Inline callback for this operation. */ fn($p)=>$p->where('user_id',$user->id))->orWhere('kind','support'));
        else $query->whereHas('participants',/** Inline callback for this operation. */ fn($q)=>$q->where('user_id',$user->id));
        $rows=$query->orderByDesc('last_message_at')->orderByDesc('id')->limit(100)->get();
        foreach($rows as $conversation){$participant=$conversation->participants->firstWhere('user_id',$user->id);$unread=$conversation->messages()->where('sender_user_id','!=',$user->id);if($participant?->last_read_at)$unread->where('created_at','>',$participant->last_read_at);$conversation->setAttribute('unread_count',$unread->count());}
        return response()->json(['data'=>['items'=>ConversationResource::collection($rows)->resolve($request),'unreadCount'=>$counter->forUser($user)]]);
    }
    /** Handles the store request for this resource. */
    public function store(Request $request,OpenConversation $action):ConversationResource|JsonResponse
    {
        $data=$request->validate(['kind'=>'required|in:order,support','vendorOrderId'=>'nullable|string|max:190']);try{return new ConversationResource($action->execute($request->user(),$data['kind'],$data['vendorOrderId']??null));}catch(MessagingException $e){return $this->error($e);}
    }
    /** Handles the show request for this resource. */
    public function show(Request $request,Conversation $conversation,ConversationAccess $access):JsonResponse
    {
        $access->assert($request->user(),$conversation);$conversation->participants()->firstOrCreate(['user_id'=>$request->user()->id],['participant_role'=>'support','joined_at'=>now()]);$conversation->participants()->where('user_id',$request->user()->id)->update(['last_read_at'=>now()]);
        $messages=ConversationMessage::query()->where('conversation_id',$conversation->id)->with(['sender','attachments','conversation.vendor'])->latest('id')->paginate(min(100,max(1,(int)$request->input('perPage',50))));$ordered=$messages->getCollection()->sortBy('id')->values();
        $conversation->load(['order','vendorOrder','vendor','participants.user','messages'=>/** Inline callback for this operation. */ fn($q)=>$q->with(['sender','attachments','conversation.vendor'])->latest('id')->limit(60)]);$conversation->setAttribute('unread_count',0);
        return response()->json(['data'=>['conversation'=>(new ConversationResource($conversation))->resolve($request),'messages'=>ConversationMessageResource::collection($ordered)->resolve($request),'meta'=>['currentPage'=>$messages->currentPage(),'lastPage'=>$messages->lastPage(),'total'=>$messages->total()]]]);
    }
    /** Handles send for the message controller workflow. */
    public function send(Request $request,Conversation $conversation,SendConversationMessage $action,SecureUploadInspector $uploads):ConversationMessageResource|JsonResponse
    {
        $data=$request->validate(['body'=>'nullable|string|max:5000','clientId'=>'required|string|max:190','attachments'=>'nullable|array|max:4','attachments.*'=>'file|max:10240|mimetypes:image/jpeg,image/png,image/webp,application/pdf']);
        foreach($request->file('attachments',[]) as $file)$uploads->inspect($file,['image/jpeg','image/png','image/webp','application/pdf'],10_485_760,true);
        try{return new ConversationMessageResource($action->execute($request->user(),$conversation,$data['body']??null,$data['clientId'],$request->file('attachments',[])));}catch(MessagingException $e){return $this->error($e);}
    }
    /** Handles read for the message controller workflow. */
    public function read(Request $request,Conversation $conversation,ConversationAccess $access):JsonResponse{$access->assert($request->user(),$conversation);$conversation->participants()->firstOrCreate(['user_id'=>$request->user()->id],['participant_role'=>'support','joined_at'=>now()]);$conversation->participants()->where('user_id',$request->user()->id)->update(['last_read_at'=>now()]);return response()->json(['data'=>['unreadCount'=>0]]);}
    /** Handles attachment for the message controller workflow. */
    public function attachment(Request $request,MessageAttachment $attachment,ConversationAccess $access):mixed{$attachment->load('message.conversation');$access->assert($request->user(),$attachment->message->conversation);abort_unless(Storage::disk($attachment->disk)->exists($attachment->path),404);return Storage::disk($attachment->disk)->download($attachment->path,$attachment->original_name,['Content-Type'=>$attachment->mime_type,'X-Content-Type-Options'=>'nosniff']);}
    /** Handles error for the message controller workflow. */
    private function error(MessagingException $e):JsonResponse{return response()->json(['message'=>$e->getMessage(),'errors'=>[$e->field=>[$e->getMessage()]]],422);}
}
