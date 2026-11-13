<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\RefundResource;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Trip;
use App\Services\Finance\PaymentService;
use App\Services\Finance\RefundService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $payments = Payment::query()->with('trip.rideRequest')->where('payer_id', $request->user()->id)->latest('id')->paginate(20);

        return ApiResponse::ok(PaymentResource::collection($payments));
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $payment = Payment::query()->with('trip.rideRequest')->where('uuid', $uuid)->where('payer_id', $request->user()->id)->first();

        return $payment ? ApiResponse::ok(new PaymentResource($payment)) : ApiResponse::error('Payment not found.', 'not_found', 404);
    }

    public function refunds(Request $request): JsonResponse
    {
        return ApiResponse::ok(RefundResource::collection(Refund::query()->with('trip')->where('user_id', $request->user()->id)->latest('id')->paginate(20)));
    }

    public function requestRefund(Request $request, RefundService $refunds): JsonResponse
    {
        $data = $request->validate(['trip_id' => ['required', 'string'], 'amount' => ['required', 'numeric', 'min:1'], 'reason' => ['required', 'string', 'min:5', 'max:500']]);
        $trip = Trip::query()->where('uuid', $data['trip_id'])->firstOrFail();
        $refund = $refunds->request($request->user(), $trip, (float) $data['amount'], $data['reason']);

        return ApiResponse::created(new RefundResource($refund->load('trip')), 'Refund request submitted.');
    }

    /** Payment gateway webhook: HMAC-SHA256 signature over raw body + timestamp replay window. */
    public function webhook(Request $request, string $provider, PaymentService $payments): JsonResponse
    {
        $secret = config('services.payments.webhook_secret');
        if (! $secret) {
            return ApiResponse::error('Webhooks are not configured.', 'not_configured', 503);
        }
        $timestamp = (int) $request->header('X-Webhook-Timestamp');
        $signature = (string) $request->header('X-Webhook-Signature');
        if (abs(time() - $timestamp) > 300) {
            return ApiResponse::error('Stale webhook.', 'replay_rejected', 400);
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);
        if (! hash_equals($expected, $signature)) {
            return ApiResponse::error('Invalid signature.', 'invalid_signature', 401);
        }
        $event = $payments->processWebhook($provider, $request->json()->all());

        return ApiResponse::ok(['event_id' => $event->event_id, 'processed' => (bool) $event->processed_at]);
    }
}
