<?php
// src/Eventos.php
// Executa os eventos de uma transição (listas "antes" e "depois" do fluxo).
//
// ANTES  (só atualizar_campo): altera os dados do processo EM MEMÓRIA; quem grava é o
//        Estados::transitar, na mesma transação do novo estado. Se algum falhar, lança
//        exceção e a transição não acontece.
// DEPOIS (notificar, registrar): só mensagens. Nunca altera o processo. Se algum falhar,
//        o erro é gravado à parte (logs/erros_eventos.log) e a transição NÃO é desfeita.
//
// Valores aceitos em "valor" do atualizar_campo: qualquer valor fixo, ou os textos
// {agora} (data e hora) e {hoje} (data).
// Nas mensagens podem ser usados: {id} {fluxo} {estado} {estado_anterior} {acao}
// e qualquer campo de dados do processo, como {titulo}.

require_once __DIR__ . '/Validacao.php';

class Eventos
{
    // Pasta dos arquivos de log. null = <raiz do projeto>/logs
    public static ?string $diretorioLog = null;

    // $processo = ['id', 'fluxo', 'estado', 'dados' => [...]]. Devolve o processo com os dados alterados.
    public static function executarAntes(array $lista, array $processo): array
    {
        foreach ($lista as $evento) {
            $tipo = $evento['tipo'] ?? '';
            if ($tipo !== 'atualizar_campo') {
                throw new RuntimeException("O evento '{$tipo}' não é permitido antes da transição.");
            }

            $campo = $evento['campo'] ?? '';
            if (!is_string($campo) || $campo === '') {
                throw new RuntimeException("O evento 'atualizar_campo' precisa do parâmetro 'campo'.");
            }

            $processo['dados'][$campo] = self::resolverValor($evento['valor'] ?? null);
        }
        return $processo;
    }

    // $contexto = ['acao' => ..., 'estado_anterior' => ...]. $processo já com o estado novo.
    // Devolve ['mensagens' => [...], 'erros' => [...]]. Nunca lança exceção.
    public static function executarDepois(array $lista, array $processo, array $contexto = []): array
    {
        $mensagens = [];
        $erros = [];

        $variaveis = array_merge($processo['dados'] ?? [], [
            'id'              => $processo['id'] ?? null,
            'fluxo'           => $processo['fluxo'] ?? null,
            'estado'          => $processo['estado'] ?? null,
            'estado_anterior' => $contexto['estado_anterior'] ?? null,
            'acao'            => $contexto['acao'] ?? null,
        ]);

        foreach ($lista as $evento) {
            $tipo = is_array($evento) ? ($evento['tipo'] ?? '') : '';
            try {
                if ($tipo !== 'notificar' && $tipo !== 'registrar') {
                    throw new RuntimeException("O evento '{$tipo}' não é permitido depois da transição.");
                }

                $texto = Validacao::interpolar((string) ($evento['mensagem'] ?? ''), $variaveis);
                if (trim($texto) === '') {
                    throw new RuntimeException("O evento '{$tipo}' precisa de uma 'mensagem'.");
                }

                $rotulo = $tipo === 'notificar' ? 'NOTIFICACAO' : 'REGISTRO';
                self::escrever('eventos.log', "[{$rotulo}] processo #{$variaveis['id']}: {$texto}");
                $mensagens[] = ['tipo' => $tipo, 'mensagem' => $texto];
            } catch (Throwable $e) {
                $erros[] = ['tipo' => $tipo, 'erro' => $e->getMessage()];
                self::registrarErro($processo['id'] ?? null, $tipo, $e->getMessage());
            }
        }

        return ['mensagens' => $mensagens, 'erros' => $erros];
    }

    private static function resolverValor($valor)
    {
        if (is_string($valor)) {
            return strtr($valor, ['{agora}' => date('Y-m-d H:i:s'), '{hoje}' => date('Y-m-d')]);
        }
        return $valor;
    }

    private static function registrarErro($processoId, string $tipo, string $erro): void
    {
        try {
            self::escrever('erros_eventos.log', "[ERRO] processo #{$processoId}, evento '{$tipo}': {$erro}");
        } catch (Throwable $e) {
            // último recurso: nem o log de erros funcionou
            error_log("CME Trâmites: falha ao registrar erro de evento: {$erro}");
        }
    }

    private static function escrever(string $arquivo, string $linha): void
    {
        $dir = self::$diretorioLog ?? dirname(__DIR__) . '/logs';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Não foi possível criar a pasta de logs: {$dir}");
        }
        $texto = '[' . date('Y-m-d H:i:s') . '] ' . $linha . PHP_EOL;
        if (@file_put_contents($dir . '/' . $arquivo, $texto, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException("Não foi possível escrever em {$dir}/{$arquivo}.");
        }
    }
}
