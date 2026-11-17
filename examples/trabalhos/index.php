<?php
// examples/trabalhos/index.php
// Demonstração do fluxo de trabalhos acadêmicos (usa fluxo/trabalhos.json).
//
// O que precisa mostrar: criar um processo, aplicar ações (enviar, analisar,
// aprovar, rejeitar, concluir) com Estados::transitar, listar o histórico com
// Registros::listar e exibir a mensagem configurada quando a ação é inválida
// (ex.: aprovar sem ser o orientador).
