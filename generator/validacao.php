<?php
// generator/validacao.php
// Confere o preenchimento do FORMULÁRIO antes de gerar. Não é o src/Validacao.php,
// que valida a mudança de estado de um processo.
//
// Regras: nome da aplicação e banco preenchidos; estados com nome e sem repetir;
// pelo menos um estado inicial e um final; "de" e "para" existentes na lista;
// condições com o tipo e o parâmetro certos (campo, campo+minimo, papel, documento);
// mensagem para cada ação (ou usa a padrão).
// Devolve a lista de erros. É a validação que vale; o JavaScript só avisa antes.
