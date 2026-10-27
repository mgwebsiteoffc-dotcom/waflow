<?php
namespace App\Http\Controllers;
use App\Models\PrivacyRequest;
use Illuminate\Http\Request;
class PrivacyRequestController extends Controller {
 public function download(Request $request,PrivacyRequest $privacyRequest){$shop=$request->attributes->get('currentShop');abort_unless($privacyRequest->shop_id===$shop->id,404);$privacyRequest->update(['status'=>'fulfilled','fulfilled_at'=>now()]);return response()->json($privacyRequest->data_export,200,['Content-Disposition'=>'attachment; filename="customer-'.$privacyRequest->shopify_customer_id.'-privacy-export.json"']);}
}
