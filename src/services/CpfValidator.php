<?php

/**
 * CpfValidator — validação central de CPF.
 *
 * Aplicado em:
 *   - ProfileController::updateProfile (ao salvar no perfil)
 *   - AsaasSubscriptionController::create (defesa antes de chamar Asaas)
 *
 * Algoritmo oficial:
 *   - 11 dígitos
 *   - rejeita todos os dígitos iguais
 *   - valida 1º e 2º dígito verificador (DV módulo 11)
 *
 * Sem logs e sem persistência.
 */
final class CpfValidator
{
    public static function isValid(?string $cpf): bool
    {
        $digits = self::digits($cpf);
        if ($digits === null) {
            return false;
        }
        if (strlen($digits) !== 11) {
            return false;
        }
        if (preg_match('/^(\d)\1{10}$/', $digits) === 1) {
            return false;
        }
        $d1 = self::checkDigit($digits, 9);
        if ($d1 === null || $digits[9] !== $d1) {
            return false;
        }
        $d2 = self::checkDigit($digits, 10);
        if ($d2 === null || $digits[10] !== $d2) {
            return false;
        }
        return true;
    }

    public static function digits(?string $cpf): ?string
    {
        if ($cpf === null) return null;
        $clean = preg_replace('/\D/', '', $cpf) ?? '';
        if ($clean === '') return null;
        return $clean;
    }

    public static function format(?string $cpf): ?string
    {
        $digits = self::digits($cpf);
        if ($digits === null || strlen($digits) !== 11) return null;
        return substr($digits, 0, 3) . '.' . substr($digits, 3, 3) . '.' . substr($digits, 6, 3) . '-' . substr($digits, 9, 2);
    }

    public static function mask(?string $cpf): ?string
    {
        $digits = self::digits($cpf);
        if ($digits === null || strlen($digits) !== 11) return null;
        return '***.' . substr($digits, 3, 3) . '.' . substr($digits, 6, 3) . '-**';
    }

    private static function checkDigit(string $digits, int $length): ?string
    {
        if ($length < 1 || $length > 10) return null;
        $sum = 0;
        for ($i = 0; $i < $length; $i++) {
            $sum += (int)$digits[$i] * (($length + 1) - $i);
        }
        $mod = $sum % 11;
        $dv = ($mod < 2) ? 0 : 11 - $mod;
        return (string)$dv;
    }
}
