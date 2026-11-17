<?php
// src/Estados.php
// Motor do framework: lê o fluxo (JSON) e muda o estado do processo.
//
//   carregarFluxo(nome)                     lê e confere fluxo/<nome>.json
//   criarProcesso(fluxo, dados)             cria o processo no estado inicial (e registra no histórico)
//   buscarProcesso(id)                      recupera o processo (sobrevive ao fim do programa)
//   podeTransitar(id, acao, dados, just.)   só consulta, não grava
//   transitar(id, acao, dados, just.)       faz a mudança completa
//
// "dados" passados em podeTransitar/transitar são CONTEXTO da ação (ex.: papel_usuario,
// arquivo) usado só na validação; não são gravados. O que é gravado vem do criarProcesso
// e dos eventos "antes" (atualizar_campo).

require_once __DIR__ . '/../util/connection.php';
require_once __DIR__ . '/Validacao.php';
require_once __DIR__ . '/Eventos.php';
require_once __DIR__ . '/Registros.php';

class Estados
{
    // Pasta dos fluxos. null = <raiz do projeto>/fluxo
    public static ?string $diretorioFluxos = null;

    private static array $cache = [];

    // ------------------------------------------------------------------ fluxo

    public static function carregarFluxo(string $nome): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $nome)) {
            throw new InvalidArgumentException("Nome de fluxo inválido: '{$nome}'.");
        }

        $dir = self::$diretorioFluxos ?? dirname(__DIR__) . '/fluxo';
        $arquivo = "{$dir}/{$nome}.json";
        if (!is_file($arquivo)) {
            throw new RuntimeException("Fluxo '{$nome}' não encontrado em {$arquivo}.");
        }

        $chave = $arquivo . '@' . filemtime($arquivo);
        if (isset(self::$cache[$chave])) {
            return self::$cache[$chave];
        }

        $fluxo = json_decode((string) file_get_contents($arquivo), true);
        if (!is_array($fluxo)) {
            throw new RuntimeException("O fluxo '{$nome}' não é um JSON válido: " . json_last_error_msg());
        }

        return self::$cache[$chave] = self::conferirFluxo($fluxo, $nome);
    }

    private static function conferirFluxo(array $fluxo, string $nome): array
    {
        $erro = function (string $msg) use ($nome) {
            throw new RuntimeException("Fluxo '{$nome}' inválido: {$msg}");
        };

        foreach (['estados', 'estados_finais', 'transicoes', 'mensagens'] as $chave) {
            if (!isset($fluxo[$chave]) || !is_array($fluxo[$chave])) {
                $erro("falta a chave '{$chave}'.");
            }
        }
        if (count($fluxo['estados']) === 0) {
            $erro("'estados' está vazio.");
        }

        $fluxo['estado_inicial'] = $fluxo['estado_inicial'] ?? $fluxo['estados'][0];
        if (!in_array($fluxo['estado_inicial'], $fluxo['estados'], true)) {
            $erro("o estado inicial '{$fluxo['estado_inicial']}' não está em 'estados'.");
        }
        foreach ($fluxo['estados_finais'] as $final) {
            if (!in_array($final, $fluxo['estados'], true)) {
                $erro("o estado final '{$final}' não está em 'estados'.");
            }
        }

        $vistas = [];
        foreach ($fluxo['transicoes'] as $i => &$t) {
            foreach (['de', 'acao', 'para'] as $campo) {
                if (!isset($t[$campo]) || !is_string($t[$campo]) || $t[$campo] === '') {
                    $erro("a transição #{$i} não tem '{$campo}'.");
                }
            }
            if (!in_array($t['de'], $fluxo['estados'], true) || !in_array($t['para'], $fluxo['estados'], true)) {
                $erro("a transição '{$t['acao']}' usa um estado que não existe.");
            }
            if (in_array($t['de'], $fluxo['estados_finais'], true)) {
                $erro("a transição '{$t['acao']}' sai do estado final '{$t['de']}'.");
            }
            if (isset($vistas[$t['de'] . '|' . $t['acao']])) {
                $erro("a ação '{$t['acao']}' está repetida a partir de '{$t['de']}'.");
            }
            $vistas[$t['de'] . '|' . $t['acao']] = true;

            $t['condicoes'] = $t['condicoes'] ?? [];
            $t['antes'] = $t['antes'] ?? [];
            $t['depois'] = $t['depois'] ?? [];

            foreach ($t['condicoes'] as $c) {
                $tipo = $c['tipo'] ?? '';
                if (!isset(Validacao::TIPOS_CONDICAO[$tipo])) {
                    $erro("condição desconhecida '{$tipo}' na ação '{$t['acao']}'.");
                }
                foreach (Validacao::TIPOS_CONDICAO[$tipo] as $param) {
                    if (!isset($c[$param])) {
                        $erro("a condição '{$tipo}' da ação '{$t['acao']}' precisa do parâmetro '{$param}'.");
                    }
                }
            }
        }
        unset($t);

        return $fluxo;
    }

    // --------------------------------------------------------------- processos

    public static function criarProcesso(string $fluxoNome, array $dados = []): int
    {
        $fluxo = self::carregarFluxo($fluxoNome);
        $inicial = $fluxo['estado_inicial'];
        $agora = date('Y-m-d H:i:s');
        $pdo = Connection::getConnection();

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO processos (fluxo, estado, dados, criado_em, atualizado_em) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$fluxoNome, $inicial, self::codificar($dados), $agora, $agora]);
            $id = (int) $pdo->lastInsertId();

            // estado_anterior NULL = primeira linha do histórico
            Registros::registrar($id, null, $inicial, 'criar', 'Processo criado.');
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $id;
    }

    public static function buscarProcesso(int $id): ?array
    {
        $stmt = Connection::getConnection()->prepare('SELECT * FROM processos WHERE id = ?');
        $stmt->execute([$id]);
        $linha = $stmt->fetch();
        if ($linha === false) {
            return null;
        }
        $linha['dados'] = json_decode((string) $linha['dados'], true) ?: [];
        return $linha;
    }

    // ------------------------------------------------------------- transições

    // Só consulta. Devolve o mesmo formato de Validacao::validarTransicao.
    public static function podeTransitar(int $processoId, string $acao, array $dados = [], ?string $justificativa = null): array
    {
        $processo = self::buscarProcesso($processoId);
        if ($processo === null) {
            return ['permitido' => false, 'motivo' => 'Processo não encontrado.', 'transicao' => null];
        }
        $fluxo = self::carregarFluxo($processo['fluxo']);
        return Validacao::validarTransicao(
            $processo['estado'],
            $acao,
            self::dadosParaValidar($processo, $dados, $justificativa),
            $fluxo
        );
    }

    // Retorno: ['sucesso' => bool, 'estado' => string|null, 'mensagem' => string,
    //           'notificacoes' => [...], 'erros_eventos' => [...]]
    public static function transitar(int $processoId, string $acao, array $dados = [], ?string $justificativa = null): array
    {
        // 1. valida
        $processo = self::buscarProcesso($processoId);
        if ($processo === null) {
            return self::resultado(false, null, 'Processo não encontrado.');
        }
        $fluxo = self::carregarFluxo($processo['fluxo']);
        $estadoAnterior = $processo['estado'];

        $validacao = Validacao::validarTransicao(
            $estadoAnterior,
            $acao,
            self::dadosParaValidar($processo, $dados, $justificativa),
            $fluxo
        );
        if (!$validacao['permitido']) {
            return self::resultado(false, $estadoAnterior, $validacao['motivo']);
        }
        $transicao = $validacao['transicao'];
        $novoEstado = $transicao['para'];

        // 2. eventos "antes" (se falhar, nada foi gravado)
        try {
            $novo = Eventos::executarAntes($transicao['antes'], $processo);
        } catch (Throwable $e) {
            return self::resultado(false, $estadoAnterior, 'A transição foi cancelada: ' . $e->getMessage());
        }

        // 3. grava estado novo + histórico na mesma transação
        $pdo = Connection::getConnection();
        $pdo->beginTransaction();
        try {
            // trava a linha (MySQL) e confere se ninguém mudou o estado nesse meio-tempo
            $sql = 'SELECT estado FROM processos WHERE id = ?';
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $sql .= ' FOR UPDATE';
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$processoId]);
            $estadoNoBanco = $stmt->fetchColumn();

            if ($estadoNoBanco !== $estadoAnterior) {
                $pdo->rollBack();
                return self::resultado(
                    false,
                    $estadoNoBanco === false ? null : $estadoNoBanco,
                    'O processo foi alterado por outra operação. Tente novamente.'
                );
            }

            $stmt = $pdo->prepare('UPDATE processos SET estado = ?, dados = ?, atualizado_em = ? WHERE id = ?');
            $stmt->execute([$novoEstado, self::codificar($novo['dados']), date('Y-m-d H:i:s'), $processoId]);

            Registros::registrar($processoId, $estadoAnterior, $novoEstado, $acao, $justificativa);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e; // falha de banco não é "ação inválida": deixa subir
        }

        // 4. eventos "depois" (erro aqui NÃO desfaz a transição)
        $novo['estado'] = $novoEstado;
        $depois = Eventos::executarDepois(
            $transicao['depois'],
            $novo,
            ['acao' => $acao, 'estado_anterior' => $estadoAnterior]
        );

        return self::resultado(
            true,
            $novoEstado,
            "Ação '{$acao}' realizada: {$estadoAnterior} → {$novoEstado}.",
            $depois['mensagens'],
            $depois['erros']
        );
    }

    // ---------------------------------------------------------------- auxiliares

    // Dados do processo + contexto da ação. A justificativa entra como campo
    // "justificativa", para poder ser exigida com campo_preenchido.
    private static function dadosParaValidar(array $processo, array $dados, ?string $justificativa): array
    {
        $todos = array_merge($processo['dados'], $dados);
        if ($justificativa !== null) {
            $todos['justificativa'] = $justificativa;
        }
        return $todos;
    }

    private static function codificar(array $dados): string
    {
        return json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function resultado(
        bool $sucesso,
        ?string $estado,
        string $mensagem,
        array $notificacoes = [],
        array $errosEventos = []
    ): array {
        return [
            'sucesso'       => $sucesso,
            'estado'        => $estado,
            'mensagem'      => $mensagem,
            'notificacoes'  => $notificacoes,
            'erros_eventos' => $errosEventos,
        ];
    }
}
