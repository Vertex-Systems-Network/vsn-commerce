<?php
namespace App\Domain\Shipping\Services;
use App\Domain\Shipping\Exceptions\ShippingException;
use App\Models\Shipment;
use App\Models\Vendor;
use Carbon\CarbonImmutable;
/** Defines the ShippingSlaService class and its project responsibilities. */
class ShippingSlaService
{
    /** Handles service for the shipping sla service workflow. */
    public function service(string $serviceCode): array
    {
        $method=(array)config("vsn.shipping_methods.{$serviceCode}",[]);
        if (!$method || !($method['enabled'] ?? false)) throw new ShippingException('Shipping service is unavailable.', 'serviceCode');
        return $method;
    }
    /** Handles deadlines for the shipping sla service workflow. */
    public function deadlines(string $serviceCode, \DateTimeInterface $placedAt, ?string $giftTarget=null): array
    {
        $service=$this->service($serviceCode);
        $placed=CarbonImmutable::instance($placedAt);
        $dispatchHours=max(1,(int)($service['dispatch_sla_hours']??24));
        $deliveryHours=max(1,(int)($service['delivery_sla_hours']??120));
        $dispatchNotBefore=null;
        $dispatchDue=$placed->addHours($dispatchHours);
        $deliveryDue=$placed->addHours($dispatchHours+$deliveryHours);
        if ($giftTarget) {
            $target=CarbonImmutable::parse($giftTarget);
            $candidate=$target->subHours($deliveryHours);
            if ($candidate->greaterThan($placed)) {
                $dispatchNotBefore=$candidate;
                $dispatchDue=$candidate->addHours($dispatchHours);
                $deliveryDue=$target;
            }
        }
        return [
            'dispatch_not_before_at'=>$dispatchNotBefore,
            'dispatch_due_at'=>$dispatchDue,
            'delivery_due_at'=>$deliveryDue,
        ];
    }
    /** Handles vendor metrics for the shipping sla service workflow. */
    public function vendorMetrics(Vendor $vendor, int $days=30): array
    {
        $from=now()->subDays($days);
        $shipments=Shipment::query()->where('vendor_id',$vendor->id)->where('created_at','>=',$from)->get();
        $dispatchEligible=$shipments->filter(/** Inline callback for this operation. */ fn($s)=>$s->picked_up_at || ($s->dispatch_due_at && $s->dispatch_due_at->isPast()));
        $deliveryEligible=$shipments->filter(/** Inline callback for this operation. */ fn($s)=>$s->delivered_at || ($s->delivery_due_at && $s->delivery_due_at->isPast()));
        $onTimeDispatch=$dispatchEligible->filter(/** Inline callback for this operation. */ fn($s)=>$s->picked_up_at && (!$s->dispatch_due_at || $s->picked_up_at->lte($s->dispatch_due_at)))->count();
        $onTimeDelivery=$deliveryEligible->filter(/** Inline callback for this operation. */ fn($s)=>$s->delivered_at && (!$s->delivery_due_at || $s->delivered_at->lte($s->delivery_due_at)))->count();
        $pct=/** Inline callback for this operation. */ fn(int $num,int $den)=>$den>0?round(($num/$den)*100,1):100.0;
        return [
            'vendorId'=>$vendor->id,'vendor'=>$vendor->name,'status'=>$vendor->status,'days'=>$days,
            'shipments'=>$shipments->count(),
            'onTimeDispatchPercent'=>$pct($onTimeDispatch,$dispatchEligible->count()),
            'onTimeDeliveryPercent'=>$pct($onTimeDelivery,$deliveryEligible->count()),
            'lateDispatchActive'=>$shipments->whereNotNull('dispatch_breached_at')->whereNull('picked_up_at')->count(),
            'lateDeliveryActive'=>$shipments->whereNotNull('delivery_breached_at')->whereNull('delivered_at')->count(),
            'failedDeliveries'=>$shipments->whereNotNull('failed_at')->count(),
            'rtoCount'=>$shipments->whereNotNull('rto_at')->count(),
            'commissionBps'=>(int)$vendor->commission_bps,
            'payoutHoldDays'=>(int)config('vsn.finance.payout_hold_days',30),
        ];
    }
}
