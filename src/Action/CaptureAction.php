<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Action;

use Monei\SyliusPlugin\Client\MoneiApiClient;
use Monei\SyliusPlugin\Factory\MoneiGatewayFactory;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Request\Capture;
use Payum\Core\Request\Convert;
use Payum\Core\Security\GenericTokenFactoryAwareInterface;
use Payum\Core\Security\GenericTokenFactoryAwareTrait;
use Sylius\Component\Core\Model\PaymentInterface;

final class CaptureAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface, GenericTokenFactoryAwareInterface
{
    use ApiAwareTrait;
    use GatewayAwareTrait;
    use GenericTokenFactoryAwareTrait;

    public function __construct()
    {
        $this->apiClass = MoneiApiClient::class;
    }

    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var PaymentInterface $payment */
        $payment = $request->getModel();
        $details = ArrayObject::ensureArrayObject($payment->getDetails());

        // If we already have a MONEI payment ID, the payment was already initiated
        if (isset($details['monei_payment_id'])) {
            return;
        }

        // Convert payment to array if not yet done
        if (!isset($details['amount'])) {
            $this->gateway->execute($convert = new Convert($payment, 'array', $request->getToken()));
            $details->replace($convert->getResult());
            $payment->setDetails((array) $details);
        }

        /** @var MoneiApiClient $api */
        $api = $this->api;

        $token = $request->getToken();
        $notifyToken = $this->tokenFactory->createNotifyToken(
            $token->getGatewayName(),
            $token->getDetails(),
        );

        $paymentParams = [
            'amount' => (int) $details['amount'],
            'currency' => $details['currency'] ?? 'EUR',
            'orderId' => $details['orderId'],
            'description' => $details['description'] ?? '',
            'completeUrl' => $token->getAfterUrl(),
            'cancelUrl' => $token->getTargetUrl() . '?cancelled=1',
            'callbackUrl' => $notifyToken->getTargetUrl(),
        ];

        foreach (['customer', 'billingDetails', 'shippingDetails'] as $key) {
            if (isset($details[$key])) {
                $paymentParams[$key] = (array) $details[$key];
            }
        }

        $paymentParams['sessionDetails'] = [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ];

        $moneiPayment = $api->createPayment($paymentParams);

        // Store MONEI response data
        $details['monei_payment_id'] = $moneiPayment['id'] ?? null;
        $details['monei_status'] = $moneiPayment['status'] ?? 'PENDING';
        $details['monei_payment_url'] = $moneiPayment['nextAction']['redirectUrl'] ?? null;
        $details['monei_payment_token'] = $moneiPayment['token'] ?? null;

        $payment->setDetails((array) $details);

        // Determine integration flow from gateway config
        $integrationType = $details['integration_type']
            ?? MoneiGatewayFactory::INTEGRATION_REDIRECT;

        if ($integrationType === MoneiGatewayFactory::INTEGRATION_COMPONENT) {
            $html = $this->renderComponentPage($moneiPayment, $token->getAfterUrl(), $api);

            throw new HttpResponse($html);
        }

        // Default: redirect to MONEI hosted payment page
        if (isset($moneiPayment['nextAction']['redirectUrl'])) {
            throw new HttpRedirect($moneiPayment['nextAction']['redirectUrl']);
        }
    }

    public function supports($request): bool
    {
        return $request instanceof Capture
            && $request->getModel() instanceof PaymentInterface;
    }

    /**
     * Render the embedded MONEI Component page.
     *
     * NOTE: In Phase 1, this will be replaced with a proper Twig template
     * that extends the Sylius shop layout. For now, a self-contained HTML page
     * ensures the plugin is functional without requiring template integration.
     *
     * @param array<string, mixed> $moneiPayment
     */
    private function renderComponentPage(array $moneiPayment, string $completeUrl, MoneiApiClient $api): string
    {
        $paymentId = htmlspecialchars($moneiPayment['id'] ?? '', ENT_QUOTES, 'UTF-8');
        $paymentToken = htmlspecialchars($moneiPayment['token'] ?? '', ENT_QUOTES, 'UTF-8');
        $accountId = htmlspecialchars($api->getAccountId(), ENT_QUOTES, 'UTF-8');
        $completeUrl = htmlspecialchars($completeUrl, ENT_QUOTES, 'UTF-8');
        $amount = number_format(($moneiPayment['amount'] ?? 0) / 100, 2);
        $currency = htmlspecialchars($moneiPayment['currency'] ?? 'EUR', ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Complete Payment — MONEI</title>
            <script src="https://js.monei.com/v2/monei.js"></script>
            <style>
                * { box-sizing: border-box; margin: 0; padding: 0; }
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    background: #f5f5f5; display: flex; justify-content: center;
                    align-items: center; min-height: 100vh; padding: 20px;
                }
                .payment-container {
                    background: #fff; border-radius: 12px;
                    box-shadow: 0 2px 12px rgba(0,0,0,.08);
                    padding: 32px; max-width: 480px; width: 100%;
                }
                .payment-header { text-align: center; margin-bottom: 24px; }
                .payment-header h2 { font-size: 20px; color: #1a1a1a; margin-bottom: 4px; }
                .payment-amount { font-size: 28px; font-weight: 700; color: #2d2d2d; }
                #monei-card-container { margin: 20px 0; min-height: 45px; }
                #monei-bizum-container, #monei-applepay-container, #monei-googlepay-container { margin: 12px 0; }
                .pay-button {
                    width: 100%; padding: 14px; background: #5C6AC4; color: #fff;
                    border: none; border-radius: 8px; font-size: 16px; font-weight: 600;
                    cursor: pointer; transition: background .2s;
                }
                .pay-button:hover { background: #4959BD; }
                .pay-button:disabled { background: #ccc; cursor: not-allowed; }
                .error-msg { color: #e53e3e; font-size: 14px; margin-top: 12px; text-align: center; display: none; }
                .separator { text-align: center; color: #999; margin: 16px 0; font-size: 13px; }
            </style>
        </head>
        <body>
            <div class="payment-container">
                <div class="payment-header">
                    <h2>Complete your payment</h2>
                    <div class="payment-amount">{$amount} {$currency}</div>
                </div>
                <div id="monei-card-container"></div>
                <button class="pay-button" id="pay-button" onclick="handlePayment()">Pay now</button>
                <div class="separator">— or pay with —</div>
                <div id="monei-bizum-container"></div>
                <div id="monei-applepay-container"></div>
                <div id="monei-googlepay-container"></div>
                <div class="error-msg" id="error-msg"></div>
            </div>
            <script>
                const paymentId = '{$paymentId}';
                const paymentToken = '{$paymentToken}';
                const completeUrl = '{$completeUrl}';
                const monei = MONEI.setup({ accountId: '{$accountId}', sessionId: paymentId });
                const cardInput = monei.CardInput({
                    paymentId: paymentId,
                    onChange: function(event) {
                        document.getElementById('pay-button').disabled = !event.isFilled;
                    }
                });
                cardInput.render('#monei-card-container');
                ['Bizum', 'ApplePay', 'GooglePay'].forEach(function(method) {
                    try {
                        var component = monei[method]({
                            paymentId: paymentId, token: paymentToken,
                            onSubmit: function(r) { handleResult(r); },
                            onError: function(e) { showError(e.message); }
                        });
                        component.render('#monei-' + method.toLowerCase() + '-container');
                    } catch(e) { console.log(method + ' not available:', e); }
                });
                async function handlePayment() {
                    var btn = document.getElementById('pay-button');
                    btn.disabled = true; btn.textContent = 'Processing…';
                    document.getElementById('error-msg').style.display = 'none';
                    try {
                        var result = await monei.confirmPayment({ paymentId: paymentId, paymentToken: paymentToken });
                        handleResult(result);
                    } catch (error) {
                        showError(error.message || 'Payment failed. Please try again.');
                        btn.disabled = false; btn.textContent = 'Pay now';
                    }
                }
                function handleResult(result) {
                    if (result.status === 'SUCCEEDED' || result.status === 'AUTHORIZED') {
                        window.location.href = completeUrl;
                    } else if (result.nextAction && result.nextAction.redirectUrl) {
                        window.location.href = result.nextAction.redirectUrl;
                    } else {
                        showError(result.statusMessage || 'Payment was not completed.');
                    }
                }
                function showError(msg) {
                    var el = document.getElementById('error-msg');
                    el.textContent = msg; el.style.display = 'block';
                }
            </script>
        </body>
        </html>
        HTML;
    }
}
