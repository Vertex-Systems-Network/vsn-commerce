<?php
namespace App\Domain\Returns\Services;
use App\Models\Order;
/** Defines the RefundCalculator class and its project responsibilities. */
class RefundCalculator
{
    /** @return array<int,int> order_item_id => net merchandise value after exact checkout discount snapshot */
    public function netItemTotals(Order $order): array
    {
        $items=$order->items->sortBy('id')->values();
        $hasSnapshots=$items->every(/** Inline callback for this operation. */ fn($item)=>array_key_exists('discount_minor',$item->metadata??[]));
        if($hasSnapshots){$out=[];foreach($items as $item)$out[$item->id]=max(0,(int)$item->line_total_minor-(int)($item->metadata['discount_minor']??0)+(int)($item->tax_added_minor??0));return $out;}
        // Legacy fallback for orders created before Milestone R line-level promotion snapshots.
        $subtotal=max(1,(int)$order->subtotal_minor); $discount=min((int)$order->discount_minor,(int)$order->subtotal_minor);
        $alloc=[]; $used=0;
        foreach($items as $item){ $share=intdiv($discount*(int)$item->line_total_minor,$subtotal); $alloc[$item->id]=$share; $used+=$share; }
        $remainder=$discount-$used;
        foreach($items as $item){ if($remainder<=0) break; $alloc[$item->id]++; $remainder--; }
        $out=[]; foreach($items as $item) $out[$item->id]=max(0,(int)$item->line_total_minor-($alloc[$item->id]??0));
        return $out;
    }
    /** Handles portion for the refund calculator workflow. */
    public function portion(int $itemNetMinor, int $itemQuantity, int $previousQuantity, int $newQuantity): int
    {
        if($newQuantity<=0 || $itemQuantity<=0) return 0;
        $before=intdiv($itemNetMinor*$previousQuantity,$itemQuantity);
        $after=intdiv($itemNetMinor*min($itemQuantity,$previousQuantity+$newQuantity),$itemQuantity);
        return max(0,$after-$before);
    }
    /** @return array{cashMinor:int,coinMinor:int,coinCoins:int} */
    public function tenderSplit(Order $order, int $amountMinor, bool $allCoins): array
    {
        $perRupee=(int)config('vsn.coins_per_rupee',70);
        if($allCoins){$coinMinor=$amountMinor-($amountMinor%100);$cash=$amountMinor-$coinMinor;return ['cashMinor'=>$cash,'coinMinor'=>$coinMinor,'coinCoins'=>intdiv($coinMinor,100)*$perRupee];}
        $tender=max(1,(int)$order->total_minor+(int)$order->coin_redemption_minor);$candidate=min($amountMinor,intdiv($amountMinor*(int)$order->coin_redemption_minor,$tender));$coinMinor=$candidate-($candidate%100);
        return ['cashMinor'=>$amountMinor-$coinMinor,'coinMinor'=>$coinMinor,'coinCoins'=>intdiv($coinMinor,100)*$perRupee];
    }
}
