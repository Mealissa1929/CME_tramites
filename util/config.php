<?php

//Mostrar erros do PHP
ini_set('display_errors', 1);
error_reporting(E_ALL);

//Banco de dados: "sqlite" (padrão, sem servidor) ou "mysql"
define("DB_DRIVER", "sqlite");

//SQLite: caminho do arquivo do banco (criado sozinho na primeira execução)
define("DB_SQLITE_PATH", dirname(__DIR__) . "/database/cme_tramites.sqlite");

//MySQL: configurar de acordo com o seu ambiente (só usado se DB_DRIVER for "mysql")
define("DB_HOST", "localhost");
define("DB_NAME", "cme_tramites");
define("DB_USER", "root");
define("DB_PASSWORD", "");

//Configuração do ambiente
define("AMB_DEV", true);
