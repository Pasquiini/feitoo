# Checagem segura do sandbox de orçamentos

Na pasta `feitoo-api`, execute com o mesmo usuário e PHP CLI do worker:

```sh
php artisan feitoo:quotation-sandbox-check
```

O comando pode rodar em produção e com `QUOTATION_GENERATION_ENABLED=false`. Exige `QUOTATION_SANDBOX=bubblewrap`, Linux e os executáveis configurados de PHP, Bubblewrap e prlimit. Não oferece fallback para PHP sem namespaces. Não precisa de PHPUnit, dependências Composer de desenvolvimento, `APP_ENV=testing`, banco, fila ou Stripe.

## O que é verificado

- O mesmo `QuotationExecutionRunner::command()` e método de execução usados pelo worker comercial executam `scripts/render-quotation.php`, com Bubblewrap e prlimit reais.
- Dompdf e os templates comerciais geram um orçamento clássico e um moderno com 24 itens fictícios, múltiplas páginas e logotipo sintético.
- Cabeçalho PDF, final `%%EOF`, posição da tabela `xref`, SHA-256 recalculado, quantidade real de objetos de página comparada ao manifesto, tipos dos metadados e limites de tamanho/páginas. O moderno também precisa ter imagem e ao menos duas páginas.
- O namespace de rede precisa diferir do host, somente a interface `lo` pode existir e a conexão a um listener efêmero em loopback do host deve falhar. O pai comprova antes que esse listener funciona. Não há DNS, requisições externas ou dependência de Internet.
- Dentro do próprio processo de renderização, sondas tentam consultar metadados e abrir para leitura `.env`, caminho ativo do arquivo de ambiente, configuração em cache e raízes privadas de storage. Nenhum conteúdo desses arquivos é lido. Devem estar invisíveis e inacessíveis.
- Arquivos fictícios de controle fora do bind do workspace e um symlink de escape precisam ser inacessíveis. Esses controles existem no host, evitando que a ausência de arquivos reais produza um falso positivo.
- Os limites efetivos de CPU, tamanho de arquivo e espaço de endereçamento são confirmados em `/proc/self/limits`. Banco e fila não podem estar registrados no container do renderizador.

O subprocesso usa o renderizador comercial com bootstrap mínimo de validação e views, sem carregar `.env`, configuração em cache, providers da aplicação/pacotes ou serviços de banco/fila. O pai remove variáveis herdadas de `getenv()`, `$_SERVER` e `$_ENV`, e fornece uma chave de criptografia aleatória exclusiva. O snapshot comercial continua sendo descriptografado e recriptografado com chave efêmera pelo fluxo existente.

## Efeitos e saída

Somente um diretório aleatório `feitoo-quotation-check-*` no temporário do sistema é criado, com permissão `0700` e umask `0077`. Snapshots, canários, manifesto, caches Blade, fontes temporárias, logo e PDFs ficam nessa árvore. O listener é fechado e toda a árvore é removida em `finally`, tanto em sucesso quanto em erro ou timeout. SIGINT e SIGTERM também acionam esse caminho quando pcntl está disponível. Uma finalização forçada por SIGKILL ou desligamento da máquina não permite executar `finally`.

Não são criados modelos, Processings, Jobs, reservas, consumos ou locks comerciais. Não são consultadas tabelas nem contagens para comparar o banco antes/depois. Não são executadas migrations ou alterações de serviços/configuração.

Código de saída **0** exige aprovação de todas as verificações e confirmação de remoção dos artefatos. Qualquer erro, controle indisponível, relatório ausente/incompleto, resultado inválido, processo não zero ou falha na limpeza retorna **1**. A saída contém apenas etapas fixas, páginas e tamanho dos PDFs fictícios; stdout/stderr do filho, exceções, caminhos e segredos não são publicados. `cleanup_failed` exige investigar a remoção do temporário pelo operador.

## Correção do layout Ubuntu

O builder preserva os links reais de `/lib`, `/lib64` e `/bin` com `--symlink <destino-do-link> <caminho>`. Se forem diretórios reais, usa bind somente leitura. `/usr` continua montado somente para leitura. A correção faz parte do runner compartilhado pelo comando e pelo worker.

## Testes

```sh
php vendor/phpunit/phpunit/phpunit --filter QuotationSandboxCheckTest
php vendor/phpunit/phpunit/phpunit --filter 'QuotationGenerationTest|QuotationPdfTest|QuotationSandboxCheckTest'
```

Os testes do comando usam ambiente de produção e geração desabilitada, com banco/fila/storage bloqueados por doubles. Exercitam o renderizador real em subprocessos, limpeza, descarte de saída, controles indisponíveis, recusa de fallback, metadados/artefatos adulterados e falhas obrigatórias de isolamento. Somente os controles de sistema operacional são simulados nesses testes portáveis. Dois testes adicionais verificam o layout real Linux e a execução completa com Bubblewrap/prlimit; são pulados quando o ambiente correspondente não está disponível. Em Linux com os binários presentes, falha de namespaces é falha do teste, sem fallback.

Esta implementação não executa deploy nem homologa automaticamente a VPS. O comando precisa ser executado no servidor para obter a evidência de PDF e isolamento daquele ambiente.
