-- database/database.sql
-- Cria as tabelas do framework. Só estrutura, sem regras de negócio.
--
-- Tabela processos (guarda o estado ATUAL de cada processo)
--   id              chave primária
--   fluxo           nome do fluxo usado (ex.: "trabalhos" ou "pedidos")
--   estado          estado atual do processo (ex.: "em_analise")
--   dados           dados do processo em JSON (título, valor, documento, etc.)
--   criado_em       data de criação
--   atualizado_em   data da última mudança de estado
--
-- Tabela historico (guarda CADA mudança, para consultar depois)
--   id              chave primária
--   processo_id     chave estrangeira para processos.id
--   estado_anterior estado de onde o processo saiu
--   estado_novo     estado para onde o processo foi
--   data            data e hora da mudança
--   acao            ação que provocou a mudança (ex.: "aprovar")
--   justificativa   texto explicando o motivo (obrigatória em algumas ações)
--
-- Índices:
--   - historico.processo_id, para listar rápido o histórico de um processo
--   - processos.estado, para buscar processos por estado
--
-- Regra importante: o novo estado e a linha do histórico são gravados
-- juntos, na mesma transação, para nunca existir estado novo sem histórico.
CREATE DATABASE IF NOT EXISTS cme_tramites
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE cme_tramites;

CREATE TABLE processos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fluxo VARCHAR(100) NOT NULL,
    estado VARCHAR(100) NOT NULL,
    dados JSON NOT NULL,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE historico (
    id INT AUTO_INCREMENT PRIMARY KEY,
    processo_id INT NOT NULL,
    estado_anterior VARCHAR(100) DEFAULT NULL,
    estado_novo VARCHAR(100) NOT NULL,
    data DATETIME DEFAULT CURRENT_TIMESTAMP,
    acao VARCHAR(100) NOT NULL,
    justificativa TEXT,

    CONSTRAINT fk_historico_processo
        FOREIGN KEY (processo_id)
        REFERENCES processos(id)
) ENGINE=InnoDB;