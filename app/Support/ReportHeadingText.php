<?php

namespace App\Support;

use Closure;

final class ReportHeadingText
{
    private const INDIVIDUAL_REFERENCE = '/(?<![\pL\pN])(?:'
        .'\#\s*\d+|(?:atendimento|chamado|protocolo)\s*[:#-]?\s*(?:[a-z]{1,12}[-\/])?\d+'
        .'|[\pL\pN._%+\-]+@[\pL\pN.\-]+\.[\pL]{2,}'
        .'|\d{3}\.\d{3}\.\d{3}-\d{2}|\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}|\(\d{2}\)\s*\d{4,5}-?\d{4}'
        .')(?![\pL\pN])/iu';

    public static function validate(string $attribute, string $value, Closure $fail): void
    {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('/[<>\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $decoded) !== 0) {
            $fail('Informe um texto simples, sem HTML ou caracteres de controle.');
        } elseif (preg_match(VehiclePlate::configuredReportTextPattern(), $decoded) === 1) {
            $fail('Remova a placa do texto do relatório.');
        } elseif (preg_match(self::INDIVIDUAL_REFERENCE, $decoded) === 1) {
            $fail('Remova referências a atendimentos, protocolos ou dados de contato do texto.');
        }
    }
}
