<?php

require_once(__DIR__ . "/config.php");

class Connection
{

    private static ?PDO $conn = null;

    // Permite trocar a conexão (ex.: SQLite em memória nos testes).
    public static function setConnection(?PDO $conn): void
    {
        self::$conn = $conn;
    }

    public static function getConnection(): PDO
    {

        if (self::$conn === null) {

            try {

                self::$conn = (DB_DRIVER === 'sqlite')
                    ? self::conectarSqlite()
                    : self::conectarMysql();
            } catch (PDOException $e) {

                if (AMB_DEV) {
                    throw $e;
                }

                throw new Exception("Erro ao conectar ao banco de dados.");
            }
        }

        return self::$conn;
    }

    private static function opcoes(): array
    {
        return array(
            // Define o tipo do erro como exceção
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

            // Retorna os resultados como array associativo
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        );
    }

    private static function conectarMysql(): PDO
    {
        $opcoes = self::opcoes();

        // Define o charset da conexão
        $opcoes[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4";

        $strConn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";

        return new PDO($strConn, DB_USER, DB_PASSWORD, $opcoes);
    }

    private static function conectarSqlite(): PDO
    {
        $pdo = new PDO("sqlite:" . DB_SQLITE_PATH, null, null, self::opcoes());

        // O SQLite não valida chave estrangeira se isso não for ligado
        $pdo->exec("PRAGMA foreign_keys = ON");

        self::criarTabelasSqlite($pdo);

        return $pdo;
    }

    // Cria as tabelas na primeira execução (usa database/sqlite.sql)
    private static function criarTabelasSqlite(PDO $pdo): void
    {
        $existe = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'processos'"
        )->fetchColumn();

        if ($existe === false) {
            $pdo->exec(file_get_contents(dirname(__DIR__) . "/database/sqlite.sql"));
        }
    }
}
