<?php
// src/Validacao.php
// Decide se o processo PODE mudar de estado. NÃO acessa o banco.
//
// validarTransicao(estadoAtual, acao, dados, fluxo) confere, nesta ordem:
//   1. o estado atual existe no fluxo;
//   2. o estado atual é final? Se sim, bloqueia: nada sai de um estado final;
//   3. existe transição para (estado atual + ação)? Se não, mensagem configurada;
//   4. as condições da transição são atendidas?
//
// Devolve: ['permitido' => bool, 'motivo' => string, 'transicao' => array|null]

class Validacao
{
    // Tipos de condição aceitos e os parâmetros obrigatórios de cada um.
    public const TIPOS_CONDICAO = [
        'campo_preenchido'      => ['campo'],
        'valor_minimo'          => ['campo', 'minimo'],
        'aprovacao_responsavel' => ['papel'],     // "campo" é opcional (padrão: papel_usuario)
        'documento_anexado'     => ['documento'],
    ];

    // Campo de $dados que diz o papel de quem está pedindo a ação.
    public const CAMPO_PAPEL_PADRAO = 'papel_usuario';

    public static function validarTransicao(string $estadoAtual, string $acao, array $dados, array $fluxo): array
    {
        $mensagens = $fluxo['mensagens'] ?? [];
        $ctx = ['estado' => $estadoAtual, 'acao' => $acao];

        // 1. estado desconhecido
        if (!in_array($estadoAtual, $fluxo['estados'] ?? [], true)) {
            return self::negar("O estado '{estado}' não existe neste fluxo.", $ctx);
        }

        // 2. nada sai de um estado final
        if (in_array($estadoAtual, $fluxo['estados_finais'] ?? [], true)) {
            return self::negar(
                $mensagens['estado_final'] ?? "O processo está no estado final '{estado}' e não pode mais mudar.",
                $ctx
            );
        }

        // 3. a ação existe a partir do estado atual?
        $transicao = self::buscarTransicao($fluxo, $estadoAtual, $acao);
        if ($transicao === null) {
            return self::negar(
                $mensagens['acoes'][$acao]
                    ?? $mensagens['acao_invalida']
                    ?? "A ação '{acao}' não é permitida no estado '{estado}'.",
                $ctx
            );
        }

        // 4. condições
        foreach ($transicao['condicoes'] ?? [] as $condicao) {
            if (!self::condicaoAplicavel($condicao, $dados)) {
                continue;
            }
            if (!self::verificarCondicao($condicao, $dados)) {
                return self::negar(
                    $condicao['mensagem']
                        ?? $mensagens['acoes'][$acao]
                        ?? $mensagens['condicao']
                        ?? "Condição não atendida para a ação '{acao}'.",
                    $ctx
                );
            }
        }

        return ['permitido' => true, 'motivo' => 'Transição permitida.', 'transicao' => $transicao];
    }

    // Troca {chave} pelo valor correspondente em $contexto. Chave inexistente fica como está.
    public static function interpolar(string $texto, array $contexto): string
    {
        return preg_replace_callback('/\{(\w+)\}/', function ($m) use ($contexto) {
            if (array_key_exists($m[1], $contexto) && is_scalar($contexto[$m[1]])) {
                return (string) $contexto[$m[1]];
            }
            return $m[0];
        }, $texto);
    }

    private static function buscarTransicao(array $fluxo, string $estado, string $acao): ?array
    {
        foreach ($fluxo['transicoes'] ?? [] as $t) {
            if ($t['de'] === $estado && $t['acao'] === $acao) {
                return $t;
            }
        }
        return null;
    }

    // Condição opcional "quando": só vale se o campo for maior que "acima_de".
    // Ex.: {"tipo": "aprovacao_responsavel", "papel": "gerente",
    //       "quando": {"campo": "valor", "acima_de": 1000}}
    private static function condicaoAplicavel(array $condicao, array $dados): bool
    {
        if (!isset($condicao['quando'])) {
            return true;
        }
        $campo = $condicao['quando']['campo'] ?? '';
        $limite = $condicao['quando']['acima_de'] ?? null;
        if ($limite === null) {
            return true;
        }
        return isset($dados[$campo]) && is_numeric($dados[$campo]) && (float) $dados[$campo] > (float) $limite;
    }

    private static function verificarCondicao(array $c, array $dados): bool
    {
        $tipo = $c['tipo'] ?? '';

        switch ($tipo) {
            case 'campo_preenchido':
                return self::preenchido($dados[$c['campo']] ?? null);

            case 'valor_minimo':
                $valor = $dados[$c['campo']] ?? null;
                return is_numeric($valor) && (float) $valor >= (float) $c['minimo'];

            case 'aprovacao_responsavel':
                $campoPapel = $c['campo'] ?? self::CAMPO_PAPEL_PADRAO;
                return ($dados[$campoPapel] ?? null) === $c['papel'];

            case 'documento_anexado':
                return self::preenchido($dados[$c['documento']] ?? null);

            default:
                // fluxo mal configurado: melhor falhar do que deixar passar
                throw new InvalidArgumentException("Tipo de condição desconhecido: '{$tipo}'.");
        }
    }

    private static function preenchido($valor): bool
    {
        if ($valor === null || $valor === false || $valor === []) {
            return false;
        }
        if (is_string($valor)) {
            return trim($valor) !== '';
        }
        return true;
    }

    private static function negar(string $mensagem, array $ctx): array
    {
        return ['permitido' => false, 'motivo' => self::interpolar($mensagem, $ctx), 'transicao' => null];
    }
}
