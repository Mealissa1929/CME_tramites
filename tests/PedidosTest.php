<?php
// tests/PedidosTest.php
// Testes do fluxo de pedidos.
//
// O que precisa testar: caminho válido até entregar; enviar sem pagar (mensagem);
// valor abaixo do mínimo; cancelar; estado final sem saída; evento "antes" gravando
// a data; erro no "depois" sem desfazer a transição (Eventos); recuperação após encerrar.
