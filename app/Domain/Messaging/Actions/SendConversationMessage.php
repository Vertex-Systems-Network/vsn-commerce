<?php
namespace App\Domain\Messaging\Actions;
use App\Domain\Messaging\Exceptions\MessagingException;
use App\Domain\Messaging\Services\ConversationAccess;
use App\Domain\Notifications\Actions\PublishMarketplaceNotification;
use App\Events\ConversationMessageCreated;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
/** Defines the SendConversationMessage class and its project responsibilities. */
class SendConversationMessage
{
    /** Initializes the SendConversationMessage instance and its dependencies. */
    public function __construct(private readonly ConversationAccess $access,private readonly PublishMarketplaceNotification $notify){}
    /** @param array<int,UploadedFile> $attachments */
    public function execute(User $sender,Conversation $conversation,?string $body,string $clientId,array $attachments=[]):ConversationMessage
    {
        $this->access->assert($sender,$conversation);$body=trim((string)$body);if($body===''&&!$attachments)throw new MessagingException('Write a message or attach a file.','body');
        $existing=ConversationMessage::query()->where('conversation_id',$conversation->id)->where('sender_user_id',$sender->id)->where('client_id',$clientId)->first();if($existing)return $existing->load(['sender','attachments','conversation.vendor']);
        $stored=[];
        try{$message=DB::transaction(/** Inline callback for this operation. */ function()use($sender,$conversation,$body,$clientId,$attachments,&$stored){
            $conversation=Conversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            if($conversation->status!=='open')throw new MessagingException('This conversation is closed.');
            $participant=$conversation->participants()->where('user_id',$sender->id)->first();
            if(!$participant && $conversation->kind==='support'){
                $conversation->participants()->create(['user_id'=>$sender->id,'participant_role'=>'support','joined_at'=>now()]);
                if(!$conversation->assigned_user_id)$conversation->update(['assigned_user_id'=>$sender->id]);
            }
            $existing=ConversationMessage::query()->where('conversation_id',$conversation->id)->where('sender_user_id',$sender->id)->where('client_id',$clientId)->first();if($existing)return $existing;
            $message=ConversationMessage::create(['public_id'=>(string)Str::uuid(),'conversation_id'=>$conversation->id,'sender_user_id'=>$sender->id,'body'=>$body?:null,'client_id'=>$clientId,'created_at'=>now()]);
            foreach($attachments as $file){$hash=hash_file('sha256',$file->getRealPath());$name=(string)Str::uuid().'.'.strtolower($file->getClientOriginalExtension());$path=$file->storeAs("messages/{$conversation->public_id}",$name,'local');if(!$path)throw new MessagingException('Attachment could not be stored.','attachments');$stored[]=$path;$message->attachments()->create(['public_id'=>(string)Str::uuid(),'disk'=>'local','path'=>$path,'original_name'=>$file->getClientOriginalName(),'mime_type'=>$file->getMimeType()?:'application/octet-stream','size_bytes'=>$file->getSize()?:0,'sha256'=>$hash,'created_at'=>now()]);}
            $conversation->update(['last_message_at'=>now()]);$conversation->participants()->where('user_id',$sender->id)->update(['last_read_at'=>now(),'archived_at'=>null]);
            return $message->load(['sender','attachments','conversation.vendor']);
        },3);}catch(\Throwable $e){foreach($stored as $path)Storage::disk('local')->delete($path);throw $e;}
        $senderParticipant=$conversation->participants()->where('user_id',$sender->id)->first();$senderDisplay=$senderParticipant?->participant_role==='seller'&&$conversation->vendor?$conversation->vendor->name:$sender->name;
        foreach($conversation->participants()->with('user')->get() as $participant){if($participant->user_id===$sender->id||!$participant->user)continue;$this->notify->execute($participant->user,'messages','message.received','New message',($conversation->kind==='order'?"{$senderDisplay} sent a message about {$conversation->subject}.":'You have a new VSN Support message.'),"message:{$message->public_id}:{$participant->user_id}",'/messages?conversation='.urlencode($conversation->public_id),'conversation',$conversation->public_id,['messageId'=>$message->public_id]);}
        event(new ConversationMessageCreated($message));return $message;
    }
}
