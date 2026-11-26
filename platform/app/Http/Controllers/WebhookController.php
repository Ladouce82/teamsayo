<?php

namespace App\Http\Controllers;

use App\Models\WebhookEvent;
use App\Services\Billing;
use App\Services\PayPal;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

class WebhookController extends Controller
{
    public function paypal(Request $request, PayPal $paypal, Billing $billing): Response
    {
        $event = $request->json()->all();
        $id = (string) ($event['id'] ?? '');
        if ($id === '' || ! $billing->webhookId()) {
            return response('ignored', 202);
        }
        if (! $paypal->verifyWebhook($request->headers->all(), $event, $billing->webhookId())) {
            return response('invalid signature', 400);
        }
        $row = WebhookEvent::firstOrCreate(['event_id' => $id], ['provider' => 'paypal', 'type' => (string) ($event['event_type'] ?? ''), 'payload' => json_encode($event)]);
        if ($row->processed_at) {
            return response('duplicate', 200); // PayPal retries; each event is applied once
        }
        try {
            $type = $row->type;
            $resource = $event['resource'] ?? [];
            if (str_starts_with($type, 'BILLING.SUBSCRIPTION.')) {
                // Always re-read the subscription from PayPal rather than trusting the event body.
                $billing->applySubscription($paypal->subscription((string) $resource['id']));
            } elseif (in_array($type, ['PAYMENT.SALE.COMPLETED', 'PAYMENT.SALE.REFUNDED'], true)) {
                $billing->recordPayment($resource + ($type === 'PAYMENT.SALE.REFUNDED' ? ['state' => 'refunded'] : []));
            }
            $row->update(['processed_at' => now(), 'error' => null]);
        } catch (Throwable $e) {
            report($e);
            $row->update(['error' => mb_substr($e->getMessage(), 0, 1000)]);

            return response('error', 500); // PayPal will retry
        }

        return response('ok', 200);
    }
}
