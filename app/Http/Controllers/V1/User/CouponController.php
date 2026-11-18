<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\CouponResource;
use App\Services\CouponService;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    public function check(Request $request)
    {
        $request->validate(['code' => 'required|string|max:255', 'plan_id' => 'nullable|integer|min:1', 'period' => 'nullable|string|max:32']);
        $couponService = new CouponService($request->input('code'));
        $couponService->setPlanId($request->input('plan_id'));
        $couponService->setUserId($request->user()->id);
        $couponService->setPeriod($request->input('period'));
        $couponService->check();
        return $this->success(CouponResource::make($couponService->getCoupon()));
    }
}
