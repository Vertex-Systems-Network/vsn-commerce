<?php
namespace App\Domain\Notifications\Services;
use App\Models\NotificationPreference;
use App\Models\User;
/** Defines the NotificationPreferenceService class and its project responsibilities. */
class NotificationPreferenceService
{
    public const CATEGORIES=['orders','shipping','gifts','games','reviews','returns','rewards','account','security','messages','reports'];
    public const CHANNELS=['in_app','email','sms','push'];
    /** Handles enabled for the notification preference service workflow. */
    public function enabled(User $user,string $category,string $channel):bool
    {
        if(!in_array($category,self::CATEGORIES,true)||!in_array($channel,self::CHANNELS,true))return false;
        $row=NotificationPreference::query()->where('user_id',$user->id)->where('category',$category)->where('channel',$channel)->first();
        if($row)return (bool)$row->enabled;
        return $this->default($category,$channel);
    }
    /** Handles matrix for the notification preference service workflow. */
    public function matrix(User $user):array
    {
        $rows=NotificationPreference::query()->where('user_id',$user->id)->get()->keyBy(/** Inline callback for this operation. */ fn($r)=>$r->category.':'.$r->channel);
        $result=[];
        foreach(self::CATEGORIES as $category)foreach(self::CHANNELS as $channel){$key="$category:$channel";$result[$category][$channel]=$rows->has($key)?(bool)$rows[$key]->enabled:$this->default($category,$channel);}
        return $result;
    }
    /** Handles the update request for this resource. */
    public function update(User $user,array $matrix):array
    {
        foreach($matrix as $category=>$channels){if(!in_array($category,self::CATEGORIES,true)||!is_array($channels))continue;foreach($channels as $channel=>$enabled){if(!in_array($channel,self::CHANNELS,true))continue;NotificationPreference::query()->updateOrCreate(['user_id'=>$user->id,'category'=>$category,'channel'=>$channel],['enabled'=>(bool)$enabled]);}}
        return $this->matrix($user);
    }
    /** Handles default for the notification preference service workflow. */
    private /** Inline callback for this operation. */ function default(string $category,string $channel):bool
    {
        if($channel==='in_app')return true;
        if($channel==='email')return in_array($category,['orders','shipping','gifts','games','reviews','returns','account','security','messages','reports'],true);
        return false;
    }
}
