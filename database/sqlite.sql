-- database/sqlite.sql
-- Mesmas tabelas do database.sql (MySQL), na sintaxe do SQLite.
-- É executado sozinho por util/connection.php na primeira vez que o banco é aberto.
--
-- processos: estado ATUAL de cada processo (dados em JSON guardado como texto)
-- historico: CADA mudança de estado (estado anterior NULL = criação do processo)
--
-- O novo estado e a linha do histórico são gravados na mesma transação
-- (isso é feito por Estados::transitar).

CREATE TABLE IF NOT EXISTS processos (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    fluxo         TEXT NOT NULL,
    estado        TEXT NOT NULL,
    dados         TEXT NOT NULL,
    criado_em     TEXT DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS historico (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    processo_id     INTEGER NOT NULL,
    estado_anterior TEXT DEFAULT NULL,
    estado_novo     TEXT NOT NULL,
    data            TEXT DEFAULT CURRENT_TIMESTAMP,
    acao            TEXT NOT NULL,
    justificativa   TEXT,
    FOREIGN KEY (processo_id) REFERENCES processos(id)
);

CREATE INDEX IF NOT EXISTS idx_historico_processo ON historico(processo_id);
CREATE INDEX IF NOT EXISTS idx_processos_estado ON processos(estado);
