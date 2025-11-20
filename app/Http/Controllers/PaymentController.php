<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Razorpay\Api\Api;
use Carbon\Carbon;

class PaymentController extends Controller
{
    protected Api $razorpay;

    public function __construct()
    {
        $this->razorpay = new Api(
            Config::get('razorpay.key_id'),
            Config::get('razorpay.key_secret')
        );
    }

    /**
     * POST /api/payment/create-order
     */
    public function createOrder(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'plan_key' => 'required|string',
        ]);

        $planKey = $data['plan_key'];

        $plans = Config::get('razorpay.plans', []);
        if (!isset($plans[$planKey])) {
            return response()->json(['message' => 'Invalid plan selected'], 422);
        }

        $planConfig = $plans[$planKey];
        $amount = $planConfig['amount']; // already in paise
        $currency = Config::get('razorpay.currency', 'INR');

        // Create Razorpay order
        $order = $this->razorpay->order->create([
            'amount'   => $amount,
            'currency' => $currency,
            'receipt'  => 'rcpt_' . $user->id . '_' . time(),
        ]);

        // Store in DB
        $payment = Payment::create([
            'user_id'           => $user->id,
            'plan_key'          => $planKey,
            'razorpay_order_id' => $order['id'],
            'amount'            => $amount,
            'currency'          => $currency,
            'status'            => 'created',
            'meta'              => [
                'plan_label' => $planConfig['label'] ?? null,
            ],
        ]);

        return response()->json([
            'orderId'  => $order['id'],
            'amount'   => $amount,
            'currency' => $currency,
            'key'      => Config::get('razorpay.key_id'),
        ]);
    }

    /**
     * POST /api/payment/verify
     */
    public function verify(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'razorpay_order_id'   => 'required|string',
            'razorpay_payment_id' => 'required|string',
            'razorpay_signature'  => 'required|string',
            'plan_key'            => 'required|string',
        ]);

        $planKey = $data['plan_key'];

        $plans = Config::get('razorpay.plans', []);
        if (!isset($plans[$planKey])) {
            return response()->json(['message' => 'Invalid plan'], 422);
        }

        // Verify payment signature
        $expectedSignature = hash_hmac(
            'sha256',
            $data['razorpay_order_id'] . '|' . $data['razorpay_payment_id'],
            Config::get('razorpay.key_secret')
        );

        if (!hash_equals($expectedSignature, $data['razorpay_signature'])) {
            // Mark payment as failed if exists
            Payment::where('razorpay_order_id', $data['razorpay_order_id'])
                ->update(['status' => 'failed']);

            return response()->json(['message' => 'Payment verification failed'], 400);
        }

        // Update payment row
        $payment = Payment::where('razorpay_order_id', $data['razorpay_order_id'])
            ->where('user_id', $user->id)
            ->first();

        if (!$payment) {
            return response()->json(['message' => 'Payment record not found'], 404);
        }

        $payment->update([
            'razorpay_payment_id' => $data['razorpay_payment_id'],
            'razorpay_signature'  => $data['razorpay_signature'],
            'status'              => 'paid',
        ]);

        // Update user's subscription
        $durationDays = $plans[$planKey]['duration_days'] ?? 30;

        $currentExpiry = $user->plan_expires_at ? Carbon::parse($user->plan_expires_at) : now();
        $newExpiry = $currentExpiry->greaterThan(now())
            ? $currentExpiry->copy()->addDays($durationDays)
            : now()->addDays($durationDays);

        $user->plan_key = $planKey;
        $user->plan_expires_at = $newExpiry;
        $user->save();

        return response()->json([
            'message' => 'Payment verified and subscription updated',
            'plan_key' => $user->plan_key,
            'plan_expires_at' => $user->plan_expires_at,
        ]);
    }
}
