<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Services\MediaLibraryService;
use App\Domain\Finance\Services\VendorResolver;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\MediaLibraryAsset;
use App\Models\Product;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Exposes scoped media-library operations to administrators and sellers. */
class MediaLibraryController extends Controller
{
    /** Creates the controller with seller ownership resolution. */
    public function __construct(private readonly VendorResolver $vendors) {}

    /** Lists media visible to the current administrator or seller with search and vendor filters. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $isAdmin = in_array($user->role, [UserRole::Admin,UserRole::SuperAdmin], true);
        $query = MediaLibraryAsset::query()->with(['vendor','uploader'])->where('status','active');
        if (! $isAdmin) {
            $vendor = $this->vendors->forUser($user);
            $query->where(/** Shows seller-owned media plus reusable marketplace-global media. */ function($scope) use ($vendor): void {$scope->where('vendor_id',$vendor->id)->orWhereNull('vendor_id');});
        } elseif ($request->filled('vendorId')) {
            $query->where('vendor_id', (int)$request->integer('vendorId'));
        }
        if ($search = trim((string)$request->query('q'))) {
            $query->where(/** Searches user-entered media terms in safe metadata fields. */ function ($q) use ($search): void {
                $q->where('original_name','like','%'.$search.'%')->orWhere('alt_text','like','%'.$search.'%');
            });
        }
        $rows = $query->latest()->paginate(min(100,max(12,(int)$request->integer('perPage',48))));
        return response()->json(['data'=>[
            'items'=>$rows->getCollection()->map(/** Serializes one media-library row for the picker/grid. */ fn(MediaLibraryAsset $asset)=>$this->row($asset,$isAdmin))->values(),
            'meta'=>['total'=>$rows->total(),'currentPage'=>$rows->currentPage(),'lastPage'=>$rows->lastPage()],
            'vendors'=>$isAdmin ? Vendor::query()->orderBy('name')->get(['id','name','slug'])->toArray() : [],
        ]]);
    }

    /** Uploads a new image into the caller's permitted media scope. */
    public function store(Request $request, MediaLibraryService $service): JsonResponse
    {
        $user=$request->user();
        $isAdmin=in_array($user->role,[UserRole::Admin,UserRole::SuperAdmin],true);
        $data=$request->validate(['file'=>'required|file|mimes:jpg,jpeg,png,webp|max:10240','alt'=>'nullable|string|max:190','vendorId'=>'nullable|integer|exists:vendors,id']);
        $vendor=$isAdmin ? (isset($data['vendorId']) ? Vendor::findOrFail((int)$data['vendorId']) : null) : $this->vendors->forUser($user);
        $asset=$service->upload($user,$request->file('file'),$vendor,$data['alt']??null);
        return response()->json(['data'=>$this->row($asset->load(['vendor','uploader']),$isAdmin)],201);
    }

    /** Attaches a selected reusable library image to an owned/managed product. */
    public function attach(Request $request, Product $product, MediaLibraryAsset $asset, MediaLibraryService $service): JsonResponse
    {
        $user=$request->user();
        $isAdmin=in_array($user->role,[UserRole::Admin,UserRole::SuperAdmin],true);
        if (! $isAdmin) {
            $vendor=$this->vendors->forUser($user);
            abort_unless($product->vendor_id===$vendor->id && ($asset->vendor_id===null || $asset->vendor_id===$vendor->id),404);
        } else {
            abort_if($asset->vendor_id && $asset->vendor_id!==$product->vendor_id,422,'Vendor media can only be attached to that vendor’s products.');
        }
        $data=$request->validate(['alt'=>'nullable|string|max:190']);
        $attached=$service->attach($product,$asset,$user,$data['alt']??null);
        return response()->json(['data'=>['mediaAssetId'=>$attached->public_id,'libraryAssetId'=>$asset->public_id,'url'=>Storage::disk($asset->disk)->url($asset->path)]]);
    }

    /** Archives an unused library asset within the caller's permitted scope. */
    public function destroy(Request $request, MediaLibraryAsset $asset, MediaLibraryService $service): JsonResponse
    {
        $user=$request->user();
        $isAdmin=in_array($user->role,[UserRole::Admin,UserRole::SuperAdmin],true);
        if (! $isAdmin) { $vendor=$this->vendors->forUser($user); abort_unless($asset->vendor_id===$vendor->id,404); }
        $service->archive($asset);
        return response()->json(['data'=>['archived'=>true]]);
    }

    /** Converts a media model into a stable public API representation. */
    private function row(MediaLibraryAsset $asset, bool $includeUploader = false): array
    {
        return [
            'id'=>$asset->public_id,'name'=>$asset->original_name,'alt'=>$asset->alt_text,'mimeType'=>$asset->mime_type,
            'bytes'=>$asset->byte_size,'width'=>$asset->width,'height'=>$asset->height,'sha256'=>$asset->sha256,
            'url'=>Storage::disk($asset->disk)->url($asset->path),'visibility'=>$asset->visibility,
            'vendor'=>$asset->vendor ? ['id'=>$asset->vendor->id,'name'=>$asset->vendor->name,'slug'=>$asset->vendor->slug] : null,
            'uploadedBy'=>$includeUploader && $asset->uploader ? ['id'=>$asset->uploader->id,'name'=>$asset->uploader->name] : null,
            'createdAt'=>$asset->created_at?->toIso8601String(),
        ];
    }
}
