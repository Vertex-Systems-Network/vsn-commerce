<?php
namespace App\Http\Controllers\Api\V1;
use App\Domain\Catalog\Services\SellerCatalogAnalyticsService;
use App\Domain\Finance\Services\VendorResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
/** Defines the SellerAnalyticsController class and its project responsibilities. */
class SellerAnalyticsController extends Controller { public function __construct(private readonly VendorResolver $vendors){} public function show(Request $request,SellerCatalogAnalyticsService $service):JsonResponse{$vendor=$this->vendors->forUser($request->user());return response()->json(['data'=>$service->execute($vendor,(int)$request->input('days',30))]);} }
