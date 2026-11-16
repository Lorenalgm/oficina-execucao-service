<?php

namespace Domain\Execucao;

enum StatusExecucao: string
{
    case NaFila = 'na_fila';
    case EmDiagnostico = 'em_diagnostico';
    case EmReparo = 'em_reparo';
    case Finalizada = 'finalizada';
    case Rejeitada = 'rejeitada';

    public function proximo(): ?self
    {
        return match ($this) {
            self::NaFila => self::EmDiagnostico,
            self::EmDiagnostico => self::EmReparo,
            self::EmReparo => self::Finalizada,
            default => null,
        };
    }

    public static function ativos(): array
    {
        return [self::NaFila, self::EmDiagnostico, self::EmReparo];
    }
}
