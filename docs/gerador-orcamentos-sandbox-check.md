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

## Correção de compatibilidade PHP 8.5 e diagnóstico

A falha original foi reproduzida em Ubuntu 26.04/PHP 8.5.4, com `www-data`, Bubblewrap e prlimit reais: Clássico com 1 página/26.053 bytes, seguido de `modern_render_failed`. O código antigo atribuía esse estágio antes de construir o documento e o logo; portanto, a mensagem não comprovava falha de Dompdf ou do subprocesso.

A causa foi `imagedestroy()` em `QuotationDemoFixtures::logo()`. O PHP 8.5 emite `E_DEPRECATED`, convertido em exceção pelo handler estrito do comando. Uma segunda reprodução, corrigindo somente a fixture, revelou a mesma chamada em `NormalizedQuotationLogo::fromBytes()`. Desde PHP 8.0, imagens GD são objetos e `imagedestroy()` não libera seus recursos; agora as referências são removidas com `unset()`, preservando a liberação automática. Referência: [manual do PHP](https://www.php.net/manual/en/function.imagedestroy.php). O handler continua estrito, inclusive para depreciações.

Após as duas correções, o mesmo diagnóstico Linux passou: Clássico com 1 página/26.053 bytes e Moderno com 4 páginas/32.450 bytes, imagem incorporada, checksum, isolamento e limpeza aprovados. Isso é evidência do laboratório local, **não da VPS**. Os templates Blade foram inspecionados e não precisaram de alterações. Nenhuma dependência ou limite foi aumentado.

O comando agora informa códigos fixos por etapa. Os códigos abaixo têm prefixo `classic_` ou `modern_`, exceto os controles globais de isolamento/limpeza:

| Código | Evidência |
| --- | --- |
| `document_preparation_failed`, `document_validation_failed` | Construção ou validação do documento no pai; no filho, falha ao reconstruir o documento. |
| `logo_fixture_failed`, `logo_normalization_failed`, `logo_write_failed` | Geração, normalização ou gravação do PNG antes da execução; normalização também é identificada no filho. |
| `snapshot_preparation_failed`, `snapshot_invalid` | Criação/gravação no pai ou leitura/descriptografia/validação do snapshot no filho. |
| `artifact_cleanup_failed` | Um resultado/relatório antigo não pôde ser removido antes da execução seguinte. |
| `bootstrap_failed`, `result_write_failed` | Inicialização mínima do filho ou gravação incompleta dos artefatos. |
| `execution_timeout` | Timeout de parede do Symfony ou fatal verificável de tempo do PHP. |
| `memory_limit_exceeded` | Fatal verificável de alocação de memória do PHP; não inferido de SIGKILL. |
| `cpu_limit_exceeded`, `file_size_limit_exceeded` | O sistema reportou SIGXCPU ou SIGXFSZ ao processo monitorado. |
| `execution_terminated` | Sinal diferente dos dois anteriores, sem atribuir causa não comprovada. |
| `render_failed`, `unsupported_character`, `page_limit_exceeded` | Falha reportada pelo renderizador; as duas últimas têm validações específicas. |
| `pdf_invalid` | Estrutura, tamanho, checksum, páginas ou manifesto inválidos; no Moderno, também ausência de imagem ou de múltiplas páginas. |
| `execution_failed` | Falha de início ou execução sem relatório seguro que identifique a causa. |
| `cleanup_failed` | Falha de remoção dos temporários; torna o comando malsucedido. |

Somente uma lista explícita de códigos é aceita. O relatório do filho precisa ser arquivo regular, sem symlink, de até 1.024 bytes e JSON válido. Sem relatório confiável, saída 137 ou morte por SIGKILL não são prova de falta de memória. Bubblewrap pode reportar apenas uma saída não zero quando um descendente morre; nesse caso a causa permanece indeterminada. Não se analisam stdout/stderr nem se publicam mensagens de exceções. O shutdown do filho reserva 128 KiB dentro do limite existente para registrar apenas um código em falhas fatais do PHP; o processo pai continua responsável pelo timeout e pela limpeza.

### Reprodução local em Windows com Docker Desktop

O laboratório usa somente código/dependências, diretórios temporários e SQLite em memória nos testes. Não monta `.env`, cache de configuração, storage real ou `.git`. Não acessa rede externa durante as execuções. A imagem requer acesso a pacotes apenas na construção:

```powershell
cd feitoo-api
docker build -t feitoo-quotation-sandbox-test scripts/quotation-sandbox-test
./scripts/check-quotation-sandbox-linux.ps1 -Mode versions
./scripts/check-quotation-sandbox-linux.ps1 -Mode check
./scripts/check-quotation-sandbox-linux.ps1 -Mode tests
```

O container descartável permite os syscalls de namespaces com `seccomp=unconfined`, necessários ao Bubblewrap aninhado. Isso não altera o host ou a configuração de produção. O processo executa como `www-data`; o runner mantém `--unshare-all`, capabilities removidas, rede isolada, binds somente leitura, PHP 192 MiB/30 s e prlimit AS 320 MiB/CPU 32 s/arquivo 12 MiB. O PDF continua limitado a 10 MiB/20 páginas. Os testes portáveis que simulam falhas de subprocessos não substituem o teste Linux real.

Validação concluída em 27/10/2026:

- Ubuntu 26.04, PHP 8.5.4, Bubblewrap 0.11.1, util-linux/prlimit 2.41.3, UID/GID 33 (`www-data`). Dompdf instalado pelo lock existente, sem alterações de dependências.
- Diagnóstico real final: saída 0, Clássico 1 página/26.053 bytes, Moderno 4 páginas/32.450 bytes com imagem, isolamento e limpeza aprovados.
- Suíte backend completa no Linux: **503 testes, 4.841 asserções, nenhuma falha/erro e 1 skip** (integração opcional com LibreOffice, fora desta alteração); 5 min 23 s. Inclui o teste Linux real de Bubblewrap/prlimit, os 28 cenários de PDF dos dois modelos, a regressão com `E_ALL` convertido em exceções, os 24 itens e seus totais, snapshot, falhas de preparação/gravação, timeout, sinais, fatal de memória, relatórios adulterados, descarte de saída e limpeza. PDFs Modernos sem imagem ou com uma só página são rejeitados em testes separados.
- `php -l` nos sete arquivos de execução, Pint nos arquivos PHP alterados/adicionados e `git diff --check`: aprovados.
- Também foram executadas 76 regressões direcionadas no Windows/PHP 8.2 (666 asserções, 6 skips de recursos Linux). A tentativa da suíte completa nesse host foi interrompida por falta de memória de paginação do Windows (`VirtualAlloc`); nenhum limite de produção foi elevado para contornar isso. A suíte final completa foi validada no laboratório Linux descrito acima.

### Atualização mínima na VPS pelo operador

Publicar em conjunto os sete arquivos PHP de execução alterados, relativos a `feitoo-api/`:

```text
app/Console/Commands/QuotationSandboxCheck.php
app/Domain/Quotations/NormalizedQuotationLogo.php
app/Support/Quotations/QuotationDemoFixtures.php
app/Services/Quotations/QuotationExecutionRunner.php
app/Services/Quotations/QuotationPdfException.php
app/Services/Quotations/QuotationPdfRenderer.php
scripts/render-quotation.php
```

Também foram alterados/adicionados `tests/Feature/QuotationSandboxCheckTest.php`, `tests/Feature/QuotationExecutionDiagnosticsTest.php`, `tests/Unit/Quotations/QuotationLogoTest.php`, `scripts/check-quotation-sandbox-linux.ps1`, `scripts/quotation-sandbox-test/Dockerfile` e este documento (`docs/gerador-orcamentos-sandbox-check.md`, na raiz do repositório). Esses arquivos são testes, laboratório e documentação. Não é necessário atualizar Composer, templates, schema, Stripe, variáveis ou workers para executar esta checagem CLI.

Manter a configuração efetiva `QUOTATION_GENERATION_ENABLED=false`. Na raiz da API da release atualizada, usando o mesmo PHP CLI da execução comercial:

```sh
sudo -u www-data /usr/bin/php artisan feitoo:quotation-sandbox-check
echo $?
```

Exigir saída 0, ambos os modelos aprovados, Moderno com ao menos duas páginas e imagem, isolamento aprovado e confirmação de remoção dos temporários. Se houver falha, conservar a geração desabilitada e registrar somente o código seguro. Nenhum deploy ou comando na VPS foi executado nesta investigação.
