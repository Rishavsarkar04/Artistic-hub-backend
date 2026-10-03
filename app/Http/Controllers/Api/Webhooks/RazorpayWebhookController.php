<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Exceptions\Checkout\InvalidWebhookSignature;
use App\Http\Controllers\Controller;
use App\Integrations\Razorpay\RazorpayWebhook;
use App\Services\Payments\PaymentCaptureService;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RazorpayWebhookController extends Controller
{
    public function __construct(
        private RazorpayWebhook $razorpayWebhook,
        private PaymentCaptureService $paymentCaptureService,
    ) {}

    /**
     * Razorpay webhook (called by Razorpay, not the frontend).
     *
     * Verifies the signature over the raw body, then applies payment_link.paid / expired / cancelled. Answers 200
     * once handled or ignored (unknown events and links), so Razorpay stops retrying; 400 for a bad signature.
     * Any other failure is a 500, and Razorpay retries it.
     *
     * @throws InvalidWebhookSignature
     */
    #[ExcludeRouteFromDocs]
    public function __invoke(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $this->razorpayWebhook->verify($rawBody, $request->header(RazorpayWebhook::SIGNATURE_HEADER));

        $event = $this->razorpayWebhook->toEvent(json_decode($rawBody, true) ?: []);

        if ($event !== null) {
            $this->paymentCaptureService->handle($event);
        }

        return response()->json(['status' => 'ok']);
    }
}
