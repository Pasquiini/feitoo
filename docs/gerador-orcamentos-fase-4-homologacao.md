# Fase 4 — roteiro posterior de homologação operacional

**Preparado em 28/10/2026; não executado em VPS ou infraestrutura real.** Este roteiro deve ser aprovado antes de provisionamento ou alteração de ambiente. O relatório de evidências locais é [gerador-orcamentos-fase-4.md](gerador-orcamentos-fase-4.md), seções 15–23. Aprovar esse relatório não autoriza deploy, compras ou liberação pública.

## Ambiente exclusivo

Usar Linux e **MySQL 8 descartável**, sem dados de clientes, com usuário SQL e storage exclusivos, APP_KEY sintética e Stripe sem credenciais reais. Confirmar versão/SQL mode/isolation, InnoDB, esquema e timezone; datas técnicas em UTC, cota civil em `America/Sao_Paulo`. Não apontar scripts ou PHPUnit para banco operacional.

Manter geração, compras e descoberta pública desligadas na configuração normal. Na cópia de homologação Free, habilitar apenas `QUOTATION_GENERATION_ENABLED` e usar o build separado de teste; `QUOTATION_PURCHASES_ENABLED=false`. Não publicar esse build de teste. Migrations são as três já existentes para rascunhos, geração e comércio, além das dependências anteriores; aplicar somente depois de conferir a identidade do banco descartável. Não executar rollback em banco com consumo/pedidos reais.

Os scripts atuais de concorrência têm travas para datadirs privados específicos no Windows. Não apontá-los diretamente para outro servidor: adaptar as travas para o laboratório MySQL 8 mantendo validação de datadir, nome de banco descartável e conexões independentes. Nunca remover as verificações para usar um banco compartilhado.

## Runtime, renderer e armazenamento

Conferir PHP CLI/extensões do lock, Dompdf/fontes, GD, DOM, mbstring, fileinfo, XML, ZIP, PDO mysql e pcntl. Conferir PHP CLI usado pelo worker, `proc_open`, Bubblewrap, user namespaces, prlimit e permissões de leitura/escrita/exclusão sob usuário sem privilégios. API e worker precisam compartilhar a chave e os volumes privados adequados. Não servir `storage/app/private` por alias HTTP/symlink público.

Em laboratório Linux aprovado, sob o mesmo usuário do futuro worker, o diagnóstico existente é:

```sh
php artisan feitoo:quotation-sandbox-check
```

Exige Bubblewrap/prlimit reais e pode manter geração desligada. Deve terminar com código zero, dois modelos completos, logo/multipágina, rede negada, canários/.env/storage alheio inacessíveis, limites verificados e remoção dos temporários. Procedimento e interpretação dos códigos em [gerador-orcamentos-sandbox-check.md](gerador-orcamentos-sandbox-check.md). Não trocar por sandbox PHP se falhar.

## Worker e scheduler de homologação

Somente após aprovação do ambiente, preparar um processo dedicado de baixa concorrência para conexão/fila `quotations`, separado dos fiscais e de `documents`. Exemplo para o laboratório:

```sh
php artisan queue:work quotations --queue=quotations --sleep=2 --timeout=60 --memory=128 --tries=0
```

A conexão precisa usar database/`jobs`, mesma conexão SQL da aplicação e `after_commit=false`. Confirmar `60 s < lease 90 s < retry_after 180 s`, prazo pendente de 30 min, timeout de render de 30 s mais margem e grace de encerramento. O limite do worker é distinto dos 192 MiB do PHP filho e dos limites prlimit. Não aumentar concorrência antes de medir capacidade.

Conferir execução real do schedule existente: reconciliador de geração a cada minuto; purge de Processing e rascunhos por hora. Não basta listar o schedule. Testar fila parada, worker interrompido, reserva abandonada e falha de limpeza; verificar recuperação sem consumo duplicado. Registrar contagens/UUIDs/códigos e latências, sem payload, dados pessoais, tokens ou stdout/stderr do filho.

## Matriz a comprovar

- Ambos os modelos, logo, quantidades fracionárias/desconto, texto extenso e 100 itens; rejeição integral acima dos limites.
- Dois processos disputando Free; mesma chave; mesma revisão com chaves diferentes; jobs duplicados; reconciliação concorrente e finalizador antigo.
- Reserva às 23:59 em São Paulo, conclusão/retry após 00:00 no bucket original, quota da nova data e downloads sem débito.
- Timeout, SIGTERM/SIGKILL, limite de memória/CPU, fila indisponível e falha de escrita/disco; consumo zero em falha definitiva, liberação uma vez e limpeza de órfãos.
- Ownership de draft/logo/status/PDF, CSRF, rate limiting, sessão expirada, conta trocada, arquivo expirado e purge concorrente com download.
- Cookies/Sanctum/CORS entre os domínios de staging, retorno Google com conta de teste, editor/IndexedDB e regressão das seis ferramentas.
- Medição de tempo/CPU/RSS/disco e carga com documento máximo; retenção lógica/física, backups e alertas de reserva antiga/fila parada.

## Reprodução local já autorizada no workspace

Com dependências instaladas, em `feitoo-web`, o build E2E separado pode ser reproduzido sem mudar os defaults:

```powershell
node scripts/build-quotation-generation-test.mjs
$env:FEITOO_TEST_BROWSER_ROOT='dist/quotation-generation-test/browser'
node scripts/check-quotation-generation-browser.mjs
```

O harness cria SQLite/storage/sessão próprios e dados fictícios em diretório privado, sem ler o `.env` operacional; força compras bloqueadas. O browser exige permissão local para Edge/CDP quando o sandbox restringir essa conexão. Não usar esse harness como procedimento de deploy.

## Aprovação e retorno seguro

Anexar evidências e fechar as pendências do checklist da Fase 4 antes de decidir liberação. Homologação de produção, publicação de frontend e compras são decisões separadas; este roteiro não inclui comandos para ativá-las. Preservar `noindex`, catálogo com seis ferramentas e três flags false no ambiente normal.

Em eventual retorno seguro de um rollout futuro aprovado, bloquear novas emissões/compras, manter acesso autorizado a PDFs vigentes e reconciliação de reservas/jobs/pedidos existentes. Não apagar ledger, saldo ou tabelas financeiras. Qualquer configuração de VPS/Supervisor/cron, migration operacional ou mudança de hosting continua fora desta entrega.
