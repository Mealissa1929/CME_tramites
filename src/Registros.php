<?php
// src/Registros.php
// Histórico das mudanças de estado (tabela historico).
//
// registrar() usa a mesma conexão (singleton) do Estados, então, quando chamado
// dentro de uma transação aberta por ele, entra nessa transação.

require_once __DIR__ . '/../util/connection.php';

class Registros
{
    public static function registrar(
        int $processoId,
        ?string $estadoAnterior,
        string $estadoNovo,
        string $acao,
        ?string $justificativa = null
    ): int {
        $pdo = Connection::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO historico (processo_id, estado_anterior, estado_novo, data, acao, justificativa)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$processoId, $estadoAnterior, $estadoNovo, date('Y-m-d H:i:s'), $acao, $justificativa]);
        return (int) $pdo->lastInsertId();
    }

    // Da mudança mais antiga para a mais recente.
    public static function listar(int $processoId): array
    {
        $stmt = Connection::getConnection()->prepare(
            'SELECT id, processo_id, estado_anterior, estado_novo, data, acao, justificativa
               FROM historico WHERE processo_id = ? ORDER BY data ASC, id ASC'
        );
        $stmt->execute([$processoId]);
        return $stmt->fetchAll();
    }

    public static function ultimo(int $processoId): ?array
    {
        $stmt = Connection::getConnection()->prepare(
            'SELECT id, processo_id, estado_anterior, estado_novo, data, acao, justificativa
               FROM historico WHERE processo_id = ? ORDER BY data DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$processoId]);
        $linha = $stmt->fetch();
        return $linha === false ? null : $linha;
    }
}
