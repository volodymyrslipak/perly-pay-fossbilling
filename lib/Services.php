<?php

declare(strict_types=1);

namespace PerlyPay\FOSSBilling;

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

/**
 * Fossbilling on FOSSBilling's own services, from the dependency container
 * it gives the adapter (setDi): its database, the Invoice, Transaction and
 * Client services, and its logger.
 */
final class Services implements Fossbilling
{
    /** @param \ArrayAccess<string, mixed> $di FOSSBilling's Pimple container */
    public function __construct(private readonly \ArrayAccess $di)
    {
    }

    public function invoice(int $id): ?array
    {
        $model = $this->di['db']->load('Invoice', $id);
        if (!is_object($model) || empty($model->id)) {
            return null;
        }
        $service = $this->di['mod_service']('Invoice');
        try {
            $number = (string) ($service->toApiArray($model, false)['serie_nr'] ?? '');
        } catch (\Throwable) {
            $number = '';
        }

        return [
            'id' => (int) $model->id,
            'number' => $number !== '' ? $number : (string) ($model->nr ?: $model->id),
            'hash' => (string) ($model->hash ?? ''),
            'client_id' => (int) $model->client_id,
            'status' => (string) $model->status,
            'currency' => strtoupper((string) $model->currency),
            'total' => Money::normalise((float) $service->getTotalWithTax($model)) ?? '0.00',
            'email' => (string) ($model->buyer_email ?? ''),
        ];
    }

    public function transaction(int $id): ?array
    {
        $tx = $this->di['db']->load('Transaction', $id);
        if (!is_object($tx) || empty($tx->id)) {
            return null;
        }
        $received = is_string($tx->created_at) ? strtotime($tx->created_at) : false;

        return [
            'id' => (int) $tx->id,
            'invoice_id' => empty($tx->invoice_id) ? null : (int) $tx->invoice_id,
            'status' => (string) $tx->status,
            'received_at' => $received === false ? time() : $received,
        ];
    }

    public function claimTransaction(int $id): bool
    {
        $service = $this->di['mod_service']('Invoice', 'Transaction');

        return !method_exists($service, 'claimForProcessing') || $service->claimForProcessing($id);
    }

    public function updateTransaction(int $id, array $fields): void
    {
        $tx = $this->di['db']->getExistingModelById('Transaction', $id);
        foreach ($fields as $column => $value) {
            $tx->{$column} = $value;
        }
        $tx->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($tx);
    }

    public function dropTransaction(int $id): void
    {
        $tx = $this->di['db']->load('Transaction', $id);
        if (is_object($tx) && !empty($tx->id)) {
            $this->di['db']->trash($tx);
        }
    }

    public function dropFailed(int $gatewayId, string $txnId, int $except): void
    {
        foreach ($this->di['db']->find('Transaction', 'gateway_id = ? AND txn_id = ? AND status = ? AND id <> ?', [$gatewayId, $txnId, 'error', $except]) as $tx) {
            $this->di['db']->trash($tx);
        }
    }

    public function recordPayment(int $invoiceId, int $transactionId, string $amount, string $description): bool
    {
        $invoiceService = $this->di['mod_service']('Invoice');
        $invoice = $this->di['db']->getExistingModelById('Invoice', $invoiceId);
        $client = $this->di['db']->getExistingModelById('Client', $invoice->client_id);
        $this->di['mod_service']('Client')->addFunds($client, $amount, $description, ['type' => 'transaction', 'rel_id' => $transactionId]);
        if ($invoice->status !== 'paid') {
            if ($invoiceService->isInvoiceTypeDeposit($invoice)) {
                $invoiceService->markAsPaid($invoice);
            } else {
                if (!$invoice->approved) {
                    $invoiceService->approveInvoice($invoice, ['use_credits' => false]);
                }
                $invoiceService->payInvoiceWithCredits($invoice);
            }
        }
        $fresh = $this->di['db']->load('Invoice', $invoiceId);

        return is_object($fresh) && $fresh->status === 'paid';
    }

    public function note(int $invoiceId, string $text): void
    {
        $invoice = $this->di['db']->load('Invoice', $invoiceId);
        if (is_object($invoice) && !empty($invoice->id)) {
            $this->di['mod_service']('Invoice')->addNote($invoice, $text);
        }
    }

    public function log(string $message): void
    {
        // The logger formats its message with vsprintf: a % in an id or a URL must not be read as a placeholder.
        $this->di['logger']->info(str_replace('%', '%%', $message));
    }
}
