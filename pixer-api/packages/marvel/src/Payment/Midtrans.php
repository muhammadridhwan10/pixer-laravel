<?php

namespace Marvel\Payments;

use Exception;
use Illuminate\Support\Facades\Http;
use Marvel\Exceptions\MarvelException;
use Marvel\Payments\PaymentInterface;
use Marvel\Enums\OrderStatus;
use Marvel\Database\Models\Order;
use Marvel\Enums\PaymentStatus;
use Marvel\Traits\PaymentTrait;
use Marvel\Payments\Base;
use Midtrans\Config;
use Midtrans\Snap;
use Midtrans\Transaction;

class Midtrans extends Base implements PaymentInterface
{
    use PaymentTrait;

    public function __construct()
    {
        parent::__construct();
        Config::$serverKey = config('shop.midtrans.server_key');
        Config::$isProduction = config('shop.midtrans.is_production');
    }

    public function getIntent($data): array
    {
        try {
            extract($data);
            $redirectUrl = config('shop.shop_url');

            $usdToIdrExchangeRate =  env('KURS_DOLLAR_TO_RUPIAH');
            $amountInIDR = round($amount * $usdToIdrExchangeRate, 2);
            $amount = intval($amountInIDR);

            $transactionDetails = array(
                'transaction_details' => array(
                    'order_id' => $order_tracking_number,
                    'gross_amount' => $amount
                ),
                'credit_card' => array(
                    'secure' => true
                ),
                'callbacks' => array(
                    'finish' => "{$redirectUrl}/orders/{$order_tracking_number}/thank-you"
                )
                
            );

            $snapToken = Snap::getSnapToken($transactionDetails);

            $redirectUrl = config('shop.midtrans.is_production')
            ? 'https://app.midtrans.com/snap/v3/redirection/' . $snapToken
            : 'https://app.sandbox.midtrans.com/snap/v3/redirection/' . $snapToken;

            return [
                'order_tracking_number' => $order_tracking_number,
                'is_redirect' => true,
                'snap_token' => $snapToken,
                'redirect_url' => $redirectUrl,
            ];
        } catch (Exception $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG_WITH_PAYMENT);
        }
    }

    public function verify($paymentId): mixed
    {
        try {
            $transaction = Transaction::status($paymentId);
            return isset($transaction->status_code) ? $transaction->status_code : false;
        } catch (Exception $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG_WITH_PAYMENT);
        }
    }

    // Implement metode lainnya sesuai dengan kebutuhan Anda
    // ...

    public function handleWebHooks($request): void
    {
        try {
            // Dapatkan data dari webhook Midtrans
            $input = json_decode($request->getContent(), true);

            // Verifikasi webhook dengan Midtrans
            $serverKey = config('shop.midtrans.server_key');
            $midtransSignature = $request->header('x-midtrans-signature');
            $expectedSignature = hash('sha256', $serverKey . $request->getContent());

            // Proses berdasarkan status pembayaran dari webhook
            switch (strtolower($input['transaction_status'])) {
                case 'capture':
                    // Pembayaran sukses
                    $this->updatePaymentOrderStatus($input, OrderStatus::PROCESSING, PaymentStatus::SUCCESS);
                    break;
                case 'settlement':
                    // Pembayaran berhasil dicairkan
                    $this->updatePaymentOrderStatus($input, OrderStatus::PROCESSING, PaymentStatus::SUCCESS);
                    break;
                case 'pending':
                    // Pembayaran masih dalam proses
                    $this->updatePaymentOrderStatus($input, OrderStatus::PENDING, PaymentStatus::PENDING);
                    break;
                case 'deny':
                case 'expire':
                case 'cancel':
                    // Pembayaran gagal atau dibatalkan
                    $this->updatePaymentOrderStatus($input, OrderStatus::FAILED, PaymentStatus::FAILED);
                    break;
                default:
                    // Status pembayaran lainnya
                    // Lakukan penanganan sesuai kebutuhan Anda
                    break;
            }

            // Memberikan respons HTTP 200 OK kepada Midtrans
            http_response_code(200);
        } catch (Exception $e) {
            // Penanganan kesalahan jika terjadi
            // Anda dapat menambahkan log atau langkah-langkah penanganan lainnya di sini
            http_response_code(500);
        }
    }

    /**
     * Update Payment and Order Status
     *
     * @param array $input
     * @param string $orderStatus
     * @param string $paymentStatus
     * @return void
     */
    public function updatePaymentOrderStatus($input, $orderStatus, $paymentStatus): void
    {
        try {
            // Dapatkan nomor pelacakan pesanan dari data webhook Midtrans
            $trackingId = $input['order_id'];

            // Temukan pesanan berdasarkan nomor pelacakan
            $order = Order::where('tracking_number', '=', $trackingId)->first();

            if ($order) {
                // Update status pembayaran dan status pesanan
                $order->payment_status = $paymentStatus;
                $order->order_status = $orderStatus;
                $order->save();

                // Tambahkan log atau langkah-langkah lain yang diperlukan di sini
            } else {
                // Pesanan tidak ditemukan, tangani kesalahan sesuai kebutuhan Anda
                // Anda dapat menambahkan log atau tindakan lain yang sesuai
            }
        } catch (Exception $e) {
            // Tangani kesalahan jika terjadi
            // Anda dapat menambahkan log atau langkah-langkah penanganan lainnya di sini
        }
    }

    /**
     * createCustomer
     *
     * @param  mixed  $request
     * @return array
     */
    public function createCustomer($request): array
    {
        return [];
    }

    /**
     * attachPaymentMethodToCustomer
     *
     * @param  string  $retrieved_payment_method
     * @param  object  $request
     * @return object
     */
    public function attachPaymentMethodToCustomer(string $retrieved_payment_method, object $request): object
    {
        return (object) [];
    }

    /**
     * detachPaymentMethodToCustomer
     *
     * @param  string  $retrieved_payment_method
     * @return object
     */
    public function detachPaymentMethodToCustomer(string $retrieved_payment_method): object
    {
        return (object) [];
    }

    public function retrievePaymentIntent($payment_intent_id): object
    {
        return (object) [];
    }

    /**
     * confirmPaymentIntent
     *
     * @param  string  $payment_intent_id
     * @param  array  $data
     * @return object
     */
    public function confirmPaymentIntent(string $payment_intent_id, array $data): object
    {
        return (object) [];
    }

    /**
     * setIntent
     *
     * @param  array  $data
     * @return array
     */
    public function setIntent(array $data): array
    {
        return [];
    }

    /**
     * retrievePaymentMethod
     *
     * @param  string  $method_key
     * @return object
     */
    public function retrievePaymentMethod(string $method_key): object
    {
        return (object) [];
    }


}
