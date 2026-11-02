<?php

declare(strict_types=1);

namespace PerlyPay\FOSSBilling;

if (!defined('PATH_LIBRARY')) {
    die('This file cannot be accessed directly');
}

/**
 * The networks Perly Pay takes money on, as the adapter's notes name them:
 * a person's name for each, the asset as shown (USDT on Arbitrum and
 * Optimism is Tether's USDT0), and where a transaction can be looked up.
 * The same list as Perly Pay's own (src/core/rails.ts).
 */
final class Chains
{
    /** chain => [name, explorer with {txid}] */
    private const CHAINS = [
        'bitcoin' => ['Bitcoin', 'https://mempool.space/tx/{txid}'],
        'base' => ['Base', 'https://basescan.org/tx/{txid}'],
        'arbitrum' => ['Arbitrum', 'https://arbiscan.io/tx/{txid}'],
        'optimism' => ['Optimism', 'https://optimistic.etherscan.io/tx/{txid}'],
        'ethereum' => ['Ethereum', 'https://etherscan.io/tx/{txid}'],
        'tron' => ['TRON', 'https://tronscan.org/#/transaction/{txid}'],
        'bitcoin-testnet4' => ['Bitcoin testnet4', 'https://mempool.space/testnet4/tx/{txid}'],
        'base-sepolia' => ['Base Sepolia', 'https://sepolia.basescan.org/tx/{txid}'],
        'arbitrum-sepolia' => ['Arbitrum Sepolia', 'https://sepolia.arbiscan.io/tx/{txid}'],
        'optimism-sepolia' => ['OP Sepolia', 'https://sepolia-optimism.etherscan.io/tx/{txid}'],
        'ethereum-sepolia' => ['Ethereum Sepolia', 'https://sepolia.etherscan.io/tx/{txid}'],
        'tron-nile' => ['TRON Nile', 'https://nile.tronscan.org/#/transaction/{txid}'],
    ];

    public static function name(string $chain): string
    {
        return self::CHAINS[$chain][0] ?? $chain;
    }

    /** "USDT" on Arbitrum or Optimism is USDT0, and is called that. */
    public static function asset(string $asset, string $chain): string
    {
        if ($asset === 'USDT' && (str_starts_with($chain, 'arbitrum') || str_starts_with($chain, 'optimism'))) {
            return 'USDT0';
        }

        return $asset;
    }

    /** The explorer page of a transaction, or null for an unknown chain or a txid that is not one. */
    public static function txUrl(string $chain, string $txid): ?string
    {
        if (!isset(self::CHAINS[$chain]) || preg_match('/^(0x)?[0-9a-fA-F]{64}$/', $txid) !== 1) {
            return null;
        }

        return str_replace('{txid}', rawurlencode($txid), self::CHAINS[$chain][1]);
    }
}
