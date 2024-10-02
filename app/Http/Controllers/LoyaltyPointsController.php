<?php

namespace App\Http\Controllers;

use App\Mail\LoyaltyPointsReceived;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyPointsTransaction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class LoyaltyPointsController extends Controller
{
    public function deposit(Request $request)
    {
        $err = '';
        $validated = $this->validate($request, 'deposit', 'Wrong account parameters');

        Log::info('Deposit transaction input: ' . $validated);
        if ($validated && $account = LoyaltyAccount::where($validated['account_type'], $validated['account_id'])->firstOrFail()) {
            if ($account->active) {
                $transaction = LoyaltyPointsTransaction::performPaymentLoyaltyPoints(
                    $account->id,
                    $validated['loyalty_points_rule'],
                    $validated['description'],
                    $validated['payment_id'],
                    $validated['payment_amount'],
                    $validated['payment_time']
                );
                $this->transactionProcessing($transaction, $account);

                return $transaction;
            } else {
                $err = 'Account is not active';
            }
        } else {
            $err = 'Account is not found';
        }

        if (!empty($err)) {
            Log::info($err);
            return response()->json(['message' => $err], 400);
        }
    }

    public function cancel(Request $request)
    {
        $err = '';
        $validated = $this->validate($request, 'cancel');

        if (!empty($validated['cancellation_reason'])) {
            $err = 'Cancellation reason is not specified';
        }

        if ($transaction = LoyaltyPointsTransaction::where('id', $validated['transaction_id'])->where('canceled', 0)->firstOrFail()) {
            $transaction->canceled = time();
            $transaction->cancellation_reason = $validated['cancellation_reason'];
            $transaction->save();
        } else {
            $err = 'Transaction is not found';
        }

        if (!empty($err)) {
            return response()->json(['message' => $err], 400);
        }
    }

    public function withdraw()
    {
        $data = $_POST;

        Log::info('Withdraw loyalty points transaction input: ' . print_r($data, true));

        $type = $data['account_type'];
        $id = $data['account_id'];
        if (($type == 'phone' || $type == 'card' || $type == 'email') && $id != '') {
            if ($account = LoyaltyAccount::where($type, '=', $id)->first()) {
                if ($account->active) {
                    if ($data['points_amount'] <= 0) {
                        Log::info('Wrong loyalty points amount: ' . $data['points_amount']);
                        return response()->json(['message' => 'Wrong loyalty points amount'], 400);
                    }
                    if ($account->getBalance() < $data['points_amount']) {
                        Log::info('Insufficient funds: ' . $data['points_amount']);
                        return response()->json(['message' => 'Insufficient funds'], 400);
                    }

                    $transaction = LoyaltyPointsTransaction::withdrawLoyaltyPoints($account->id, $data['points_amount'], $data['description']);
                    Log::info($transaction);
                    return $transaction;
                } else {
                    Log::info('Account is not active: ' . $type . ' ' . $id);
                    return response()->json(['message' => 'Account is not active'], 400);
                }
            } else {
                Log::info('Account is not found:' . $type . ' ' . $id);
                return response()->json(['message' => 'Account is not found'], 400);
            }
        } else {
            Log::info('Wrong account parameters');
            throw new \InvalidArgumentException('Wrong account parameters');
        }
    }

    private function validate(Request $request, string $rulesCode, string $errString = 'check request parameters'): array
    {
        $rules = $this->getRules($rulesCode);
        $err = '';
        if ($rules) {
            $validator = Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                $err = $errString;
            }
        } else {
            $err = 'no validation rules found';
        }

        if (!empty($err)) {
            Log::info($err);
            throw new \InvalidArgumentException($err);
        } else {
            return $validator ? $validator->validated() : [];
        }
    }

    private function getRules(string $code): array
    {
        $rules = [
            'deposit' => [
                'account_type' => ['required', 'string',  Rule::in(['phone', 'card', 'email'])],
                'account_id' => ['required', 'integer', 'gt:0'],
                'loyalty_points_rule' => ['required', 'string', 'min:3'],
                'description' => ['required', 'string', 'min:3'],
                'payment_id' => ['present', 'string', 'nullable'],
                'payment_amount' => ['present', 'decimal:2', 'nullable'],
                'payment_time' => ['present', 'date', 'nullable'],
            ],
            'cancel' => [
                'transaction_id' => ['required', 'integer', 'gt:0'],
                'cancellation_reason' => ['present', 'string', 'nullable'],
            ]
        ];
        return isset($rules[$code]) ? $rules[$code] : [];
    }

    private function transactionProcessing(LoyaltyPointsTransaction $transaction, LoyaltyAccount $account): void
    {
        Log::info($transaction);
        if ($account->email != '' && $account->email_notification) {
            Mail::to($account)->send(new LoyaltyPointsReceived($transaction->points_amount, $account->getBalance()));
        }
        if ($account->phone != '' && $account->phone_notification) {
            // instead SMS component
            Log::info('You received' . $transaction->points_amount . 'Your balance' . $account->getBalance());
        }
    }
}
