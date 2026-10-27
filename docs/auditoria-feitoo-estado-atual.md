# Auditoria técnica e funcional — estado atual do Feitoo

**Data:** 19/10/2026. **Escopo:** checkout em `D:\backup\Projetos\feitoo` e respostas HTTP públicas de https://feitoo.com.br e https://api.feitoo.com.br. Coleta nesta sessão; amostras de headers registraram 18:34 e 18:45 UTC, com consultas adicionais na mesma auditoria. O site pode mudar após a coleta.

**Regra desta etapa:** investigação, sem implementação. Nenhum teste, build, migration, instalação, commit, deploy ou comando operacional foi executado. A única escrita autorizada é este relatório. Não foram abertos arquivos de secrets, bancos, logs com dados de clientes ou configurações privadas do VPS.

**Referência de código:** HEAD raiz `2a158a7`, HEAD frontend `5fc68e0`. O ponteiro do frontend já aparecia modificado na raiz no início da auditoria; o working tree interno do frontend estava limpo. Artefatos em `dist` preexistentes não são prova de um build novo nem de correspondência integral com o deploy.

**Legenda de evidência:**
- **[C] Código:** leitura estática do arquivo atual.
- **[H] HTTP:** requisição GET/HEAD pública efetuada nesta auditoria.
- **[A] Artefato anterior:** arquivo/relatório de teste ou build encontrado; não reexecutado.
- **[R] Risco/hipótese:** consequência possível, sem reprodução de incidente.
- **[NV] Não verificado:** depende de conta, Search Console, servidor, carga, execução de testes ou medição.

## 1. Resumo executivo

O Feitoo possui **seis ferramentas implementadas**, em duas famílias: XML/Fiscal e Documentos. O frontend é Angular standalone com prerender estático e hidratação; o backend Laravel processa arquivos privados em jobs, aplica ownership e quotas, gera resultados e integra autenticação Google e pagamentos Stripe.

**Não foi comprovado nenhum P0 nesta investigação. Isso não equivale a certificação de ausência de vulnerabilidades.** Não houve pentest, conversão real, pagamento, auditoria do host ou consulta autenticada a dados.

O SEO técnico básico está consistente na produção consultada: **13 páginas públicas indexáveis**, todas HTTP 200, canonical próprio e `index,follow`, títulos/descrições distintos e um H1. As seis ferramentas possuem conteúdo editorial no HTML inicial, breadcrumbs e FAQ coerente com o schema. As 24 variantes com barra final/index.html das 12 páginas não raiz redirecionam com 308 para a URL limpa. HTTP e www também redirecionam para HTTPS/apex. Não há evidência atual de que um shell Angular vazio, robots bloqueado ou canonical de home esteja impedindo essas páginas.

**A causa das poucas impressões não está demonstrada.** Não há acesso ao Search Console nesta sessão. Indexação efetiva, canonical escolhida pelo Google, data de último rastreamento e impressões precisam ser verificadas lá, não inferidas de buscas manuais.

Achados mais relevantes:
1. **[H+C, P1]** Privacidade/termos publicados ainda exibem placeholders de responsável e textos de pré-lançamento.
2. **[C+R, P1]** Polling de alguns fluxos disputa quase todo o limite por IP; risco de 429 em trabalho prolongado ou uso compartilhado.
3. **[C+R, P1]** Temporários XLSX usam diretório temporário do sistema, fora do prefixo limpo por TTL; morte abrupta pode deixar arquivos além da retenção.
4. **[H, P2]** API sem os headers de segurança presentes no exemplo de Nginx do repositório.
5. **[H+C, P2]** `/api/me` sem Accept JSON responde 500; com os headers do frontend responde corretamente 401.

Outros pontos: cache de assets com hash ainda exige revalidação no navegador, documentação operacional desatualizada, ausência de recuperação de senha no produto, necessidade de alinhar texto de retenção ao comportamento real e falta de pipeline CI versionado identificado.

## 2. Estado geral do produto

**Implementado [C]:** catálogo/busca frontend; seis ferramentas; cadastro/login por senha; Google OAuth; conta/perfil/senha; histórico paginado; armazenamento privado; upload em partes; processamento assíncrono; resultados parciais; download e exclusão; expiração; Free/avulso/Pro; checkout, portal e webhooks; consentimento GA4; SEO e guias.

**Publicado e consultável [H]:** as seis páginas, as seis configurações públicas da API, home, planos, segurança, privacidade, termos e dois guias. `/api/health` responde 200. `checkout_available=true` em XML/Excel e Produtos indica configuração localmente considerada disponível, não comprova uma cobrança concluída nem informa se o ambiente Stripe é TEST ou LIVE.

**Não homologado novamente [NV]:** login real, Google, criação de conta, upload, conversão, filas, downloads autenticados e pagamentos em produção. Não se classificou como “funcional em produção ponta a ponta” apenas pela existência de HTML ou de testes anteriores.

**Ferramentas anunciadas sem implementação:** nenhuma encontrada entre os seis cards/links do catálogo atual e os enums/rotas correspondentes. Os dois guias são conteúdo editorial, não ferramentas extras. A marca mencionar documentos/planilhas/PDF não prova a existência de outros conversores. Existem arquivos auxiliares antigos, como home-preview, que não aumentam o inventário de ferramentas publicadas.

## 3. Arquitetura técnica

### 3.1. Organização do repositório

```text
feitoo/
├─ feitoo-web/                  Angular; repositório Git próprio
│  ├─ src/app/
│  │  ├─ core/                  SEO, analytics, retenção, cookies, interceptor
│  │  ├─ features/public/       home, catálogo, planos, informações e guias
│  │  ├─ features/auth/         páginas, serviço, guards e modelos
│  │  ├─ features/account/      conta, histórico e assinatura
│  │  └─ features/processing/   páginas, modelos, seleção, HTTP e billing
│  ├─ src/environments/         API prod/dev e configuração pública gerada
│  ├─ public/                  favicon e imagem OG
│  ├─ scripts/                 configuração, assets SEO e smokes
│  ├─ angular.json / vercel.json
│  └─ dist/                    artefatos locais anteriores, ignorados pelo Git
├─ feitoo-api/
│  ├─ app/Http/                controllers, requests, resources e middleware
│  ├─ app/Domain/Fiscal/       detecção, parser, DTOs, números e tributos
│  ├─ app/Domain/Tools/        geradores de planilha e consolidação
│  ├─ app/Services/            processamento, resultados, quotas, Google, Stripe
│  │  └─ Documents/            DOCX, subprocesso, isolamento e PDF
│  ├─ app/Jobs/                job fiscal e job por documento
│  ├─ app/Console/Commands/    purge, compras expiradas e benchmarks
│  ├─ config/, routes/, database/migrations/
│  ├─ tests/                  Feature, Unit e fixtures sintéticas
│  └─ storage/                arquivos privados e temporários
└─ docs/                      arquitetura, homologações e exemplos de deploy
```

Foram encontradas **13 migrations**, não executadas. Os bancos existentes não foram inspecionados.

### 3.2. Tecnologias e versões

Versões abaixo são do lockfile, não uma consulta ao runtime da VPS.

| Tecnologia | Estado confirmado |
| --- | --- |
| Angular core/common/router/forms | 21.2.25 no package-lock; manifesto aceita ^21.2.0 |
| Angular SSR | 21.2.26 |
| TypeScript | 5.9.3 |
| RxJS | 7.8.2 |
| Vitest | 4.1.11 |
| jsdom / Prettier | 28.1.0 / 3.9.9 |
| Node local | v22.14.0 |
| Bootstrap / Bootstrap Icons | **Não são dependências instaladas/declaradas do frontend atual.** Há CSS próprio com classes como btn/form-control e SVGs próprios; não atribuir versão de Bootstrap |
| Laravel | 12.69.3; manifesto ^12.0 |
| PHP | requisito ^8.2; CLI local 8.2.12. Versão da produção não confirmada |
| Sanctum / Socialite | 4.3.3 / 5.31.0 |
| PhpSpreadsheet | 5.10.0 |
| Stripe PHP | 22.0.0 |
| NFePHP sped-da | dev-master fixado no lock ao commit `308abfe0fe01a3a2c9acfc7b57bf59fcffc77717` |
| Brick Math / Symfony Process | 0.14.8 / 7.4.19 |
| LibreOffice / Bubblewrap / prlimit | requeridos pelo conversor Linux; versões instaladas não consultadas |
| Vite / Tailwind / Axios no backend | constam do package.json do scaffold Laravel; não constituem o frontend Angular público |
| Banco/fila | drivers configuráveis; defaults database/SQLite em partes do scaffold, deploy documenta MySQL. Banco real da VPS não identificado |

Extensões locais relevantes presentes: DOM, XMLReader/XMLWriter, libxml, ZipArchive, mbstring, fileinfo, GD, PDO MySQL/SQLite, curl e bcmath. Não inferir que a VPS tem as mesmas versões/extensões.

### 3.3. Comunicação e ambiente

Frontend usa HttpClient e JSON em `https://api.feitoo.com.br/api`; CSRF em `/sanctum/csrf-cookie`. Dev aponta para localhost:8000. `api-session.interceptor.ts:12` adiciona Accept JSON, withCredentials e X-XSRF-TOKEN apenas às origens previstas. Não há token de usuário guardado como autenticação em localStorage.

Estado é mantido em signals/computed, serviços de seleção e fluxos RxJS; não há NgRx. Arquivos selecionados ficam em memória. A ida por e-mail preserva a seleção na SPA; navegação completa ao Google pode exigir nova seleção.

`configure-launch.mjs:4` usa VERCEL_ENV/FEITOO_BUILD_ENV para ativar origem pública. Fora de produção gera noindex e robots bloqueado intencionalmente. `launch.generated.ts` local tem origem vazia: **isso não é prova de noindex em produção**, que foi verificada por HTTP como indexável. O script de build escreve arquivos de configuração; por isso não foi executado.

### 3.4. Caminho do usuário e dos dados

1. CDN entrega HTML prerenderizado com conteúdo/SEO; Angular hidrata eventos e navegação.
2. Usuário descobre uma ferramenta na busca/catálogo e abre uma rota lazy.
3. Configuração pública traz formatos/limites; seleção e validações iniciais acontecem no navegador.
4. Processar exige login/sessão. Backend cria lote UUID com proprietário e TTL.
5. Upload em requisições limitadas grava entradas privadas com checksum e nome interno gerado.
6. Start valida ownership, prazo e quota/compra sob lock, reserva unidades e enfileira após commit.
7. Worker fiscal processa o lote; worker documents processa um DOCX por job.
8. Navegador consulta estados/arquivos e apresenta resultado/previews; downloads exigem sessão e lote proprietário.
9. Acesso vence no TTL; exclusão manual/purge remove entradas, saídas, temporários gerenciados e dados detalhados, preservando registros técnicos/financeiros.

## 4. Inventário completo das ferramentas

**Correspondência [C]:** seis entradas em `home-tools.ts`, `processing/tools.ts`, `ProcessingTool.php` e seis rotas de ferramenta. Não há diferença de inventário entre esses pontos na leitura atual.

### 4.1. XML para Excel

- **URL:** https://feitoo.com.br/xml-para-excel.
- **Finalidade e formatos:** NF-e modelo 55 em XML → XLSX, abas de notas e itens.
- **Frontend:** `XmlToExcelPage`: seleção, login, upload em partes, polling, previews de Notas/Itens e download.
- **Backend:** `ProcessFiscalFilesJob` → `FiscalProcessingService` → `NfeParser` → `XmlToExcelResultService`/`XmlToExcelGenerator`.
- **Autenticação:** página/seleção públicas; criação, envio, processamento, consultas privadas, downloads e exclusão exigem conta e propriedade do lote.
- **Limites e pagamentos:** Avulso + Free/Pro. No Free/Pro, reserva/consumo no início aceito; não assumir estorno de unidades por falha fiscal posterior.
- **Dependências:** PhpSpreadsheet; parser fiscal; worker fiscal.
- **Testes encontrados:** `XmlToExcelTest`, `XmlToExcelRefinementTest`, `FiscalProcessingTest`, `NfeParserTest`; `xml-to-excel.spec.ts`, smoke geral.
- **Situação:** implementada no código e publicada com HTML/endpoint de configuração acessíveis. Processamento real de produção não foi reexecutado nesta auditoria.

### 4.2. Produtos dos XMLs

- **URL:** https://feitoo.com.br/produtos-dos-xmls.
- **Finalidade e formatos:** NF-e modelo 55 em XML → XLSX consolidado/detalhado; PDF de resumo no navegador.
- **Frontend:** Mesmo componente XML com modo próprio; consolidação, preview, alternância Consolidado/Detalhado, Excel completo e PDF com até 10 produtos.
- **Backend:** Pipeline fiscal → `ProductsFromXmlResultService` → `ProductConsolidationService`/`ProductsExcelGenerator`. PDF de resumo vem de `products-pdf.ts`, não de LibreOffice nem de outro endpoint.
- **Autenticação:** página/seleção públicas; criação, envio, processamento, consultas privadas, downloads e exclusão exigem conta e propriedade do lote.
- **Limites e pagamentos:** Avulso + Free/Pro; regra de consumo no início aceito, como XML/Excel. Agrupa por emitente, identidade do produto, NCM e unidade; não mistura unidades indiscriminadamente.
- **Dependências:** PhpSpreadsheet; Brick Math para decimais; PDF leve próprio no frontend.
- **Testes encontrados:** `ProductsFromXmlTest`, `ProductConsolidationTest`; `products-from-xml.spec.ts`, `products-pdf.spec.ts`, smoke geral e smoke das seis.
- **Situação:** implementada no código e publicada com HTML/endpoint de configuração acessíveis. Processamento real de produção não foi reexecutado nesta auditoria.

### 4.3. XML para PDF / DANFE

- **URL:** https://feitoo.com.br/xml-para-pdf.
- **Finalidade e formatos:** XML NF-e modelo 55 com dados/protocolo compatíveis → PDFs individuais + ZIP.
- **Frontend:** Modo PDF: lista por arquivo, visualização autenticada por blob/iframe, PDF individual e ZIP.
- **Backend:** Pipeline fiscal → `DanfeGenerator`/`DanfeResultService`/`PdfResultService`; valida campos, protocolo, ambiente e coerência da chave.
- **Autenticação:** página/seleção públicas; criação, envio, processamento, consultas privadas, downloads e exclusão exigem conta e propriedade do lote.
- **Limites e pagamentos:** Somente Free/Pro; sem avulso. Reserva inicial e acerto por PDFs concluídos.
- **Dependências:** NFePHP sped-da; ZipArchive; parser fiscal. Não consulta SEFAZ e não verifica criptograficamente a assinatura.
- **Testes encontrados:** `DanfeProcessingTest`, `FiscalProcessingTest`; `xml-to-pdf.spec.ts`, smoke das seis com preview e download.
- **Situação:** implementada no código e publicada com HTML/endpoint de configuração acessíveis. Processamento real de produção não foi reexecutado nesta auditoria.

### 4.4. Organizar XML

- **URL:** https://feitoo.com.br/organizar-xml.
- **Finalidade e formatos:** XML UTF-8 de NF-e 55/NFC-e 65 → ZIP de originais organizados, relatório XLSX opcional.
- **Frontend:** Modo organizador: cinco critérios nativos, nomes, duplicados, relatório, prévia de pastas, resumo e destino por arquivo.
- **Backend:** Pipeline fiscal → `XmlOrganizerService`, `OrganizerXmlReader`, `OrganizerNames`, `OrganizerReport`. XMLs seguros com problemas podem ser preservados em pastas específicas.
- **Autenticação:** página/seleção públicas; criação, envio, processamento, consultas privadas, downloads e exclusão exigem conta e propriedade do lote.
- **Limites e pagamentos:** Somente Free/Pro; consumo final por arquivos fiscais organizados com sucesso, incluindo cópias mantidas. Não oferece critério de entrada/saída inexistente.
- **Dependências:** ZipArchive; PhpSpreadsheet quando há relatório.
- **Testes encontrados:** `XmlOrganizerTest`; `xml-organizer.spec.ts`, `check-organizer-browser.mjs`, smoke das seis.
- **Situação:** implementada no código e publicada com HTML/endpoint de configuração acessíveis. Processamento real de produção não foi reexecutado nesta auditoria.

### 4.5. Analisar XML

- **URL:** https://feitoo.com.br/analisar-xml.
- **Finalidade e formatos:** XML NF-e modelo 55 → análise, tabelas/gráficos e XLSX completo/filtrado.
- **Frontend:** Modo analisador + `XmlAnalyzerDashboard`: filtros, indicadores, emitentes, produtos, documentos, tributos, inconsistências, paginação e exportação.
- **Backend:** Pipeline fiscal → snapshots privados → `AnalyzerAggregator`/`AnalyzerResultService`/`AnalyzerExcelGenerator`; `XmlAnalysisController` fornece visão e exportação.
- **Autenticação:** página/seleção públicas; criação, envio, processamento, consultas privadas, downloads e exclusão exigem conta e propriedade do lote.
- **Limites e pagamentos:** Somente Free/Pro. XML lido com sucesso consome unidade; cópias/conflitos podem consumir leitura embora sejam excluídos dos indicadores.
- **Dependências:** PhpSpreadsheet; Brick Math; snapshots JSON privados com limites de capacidade.
- **Testes encontrados:** `XmlAnalyzerTest`; `xml-analyzer.spec.ts`, smoke das seis com métricas e exportação.
- **Situação:** implementada no código e publicada com HTML/endpoint de configuração acessíveis. Processamento real de produção não foi reexecutado nesta auditoria.

### 4.6. DOCX para PDF

- **URL:** https://feitoo.com.br/docx-para-pdf.
- **Finalidade e formatos:** DOCX válido → PDF por documento + ZIP de resultados.
- **Frontend:** `DocxToPdfPage`: seleção, remoção, login, upload, polling, falha individual, PDF, ZIP e novo lote. DOC não é aceito.
- **Backend:** `ProcessDocxToPdfJob` por entrada → `DocxProcessingService` → `DocxValidator` → `LibreOfficeDocxToPdfConverter` → `PdfOutputValidator`/`PdfResultService`.
- **Autenticação:** página/seleção públicas; criação, envio, processamento, consultas privadas, downloads e exclusão exigem conta e propriedade do lote.
- **Limites e pagamentos:** Somente Free/Pro, sem avulso. Free 3/lote e 6/dia; Pro 20/lote e 100/dia; acerto por PDFs concluídos.
- **Dependências:** LibreOffice headless, Bubblewrap, prlimit, Symfony Process, ZipArchive. Conexão e fila `documents`.
- **Testes encontrados:** `DocxToPdfTest`, `DocxConverterIntegrationTest`; `docx-to-pdf.spec.ts`, smoke DOCX e smoke das seis. Integração real é opcional.
- **Situação:** implementada no código e publicada com HTML/endpoint de configuração acessíveis. Processamento real de produção não foi reexecutado nesta auditoria.


### 4.7. Limites comuns e distinções importantes

**Confirmados por HTTP nas configurações públicas:**

| Família | Free | Pro no código | Capacidade técnica |
| --- | --- | --- | --- |
| Cinco ferramentas XML | 20 por processamento, 50/dia por ferramenta | 250/lote, 500/dia por ferramenta | 250 XMLs/lote, 4000 itens, 40 MiB/lote; 20 arquivos e 30 MiB por requisição; 10 MiB/arquivo |
| DOCX | 3/lote, 6/dia | 20/lote, 100/dia, também retornados pela configuração pública | 20 arquivos e 40 MiB/lote; até 10 MiB/arquivo; envio sujeito a 30 MiB/requisição |

Pro XML/500 dia e retenção Pro/24h são confirmados por código, não por consulta autenticada à produção. As configurações públicas XML expõem os limites Free e técnicos, não o saldo de um assinante.

**Código DOCX:** timeout padrão 60 s, 80 MiB expandidos, 2048 entradas ZIP, razão máxima 100, PDF de até 25 MiB, saídas de até 80 MiB/lote e limite de espaço de endereçamento de 1024 MiB. Esses valores podem ser configurados no servidor e não foram todos expostos pela API pública; não tratá-los como medição do host.

Retenção começa na **criação do lote**: Free/avulso padrão 120 minutos; Pro configurado em 1440. A fila/espera consome parte desse prazo. Reset diário usa America/Sao_Paulo.

**Consumo não é uniforme:** `EntitlementService.php:accept` reserva na aceitação. `FiscalProcessingService.php:274` ajusta por sucesso somente PDF/Organizador/Analisador. XML/Excel e Produtos mantêm a regra de início aceito; upload recusado antes do start não é consumo. DOCX acerta por PDFs concluídos em `DocxProcessingService.php:155`. Falha de dispatch tem liberação explícita. Não prometer “somente válidos consomem” indistintamente às seis ferramentas.

## 5. Frontend

### Rotas e carregamento

**13 públicas indexáveis:** home, seis ferramentas, planos, segurança, privacidade, termos e dois guias. Há ainda login/cadastro públicos para visitantes e /404 noindex: **16 rotas públicas concretas** contando essas três. /conta é privada por guard. Assim, são 17 rotas concretas conhecidas no cliente, mais o wildcard; API e arquivos estáticos não entram nessa contagem.

`app.routes.ts` usa loadComponent em todas as páginas. Cinco ferramentas XML compartilham um componente parametrizado; DOCX e dashboard analítico são separados. O shell, navegação e catálogo básico são comuns. Angular usa componentes standalone e não uma árvore tradicional de NgModules.

`authGuard` restaura sessão e encaminha para login com returnTo; `guestGuard` trata visitantes já autenticados. Guard de cliente não substitui proteção da API, que existe separadamente. Return paths e destinos Google/Stripe têm allowlists.

### UX, estados e erros

Padrões ToolHero/Workspace/Uploader/Status/Security/Related, estados visuais, progress nativo baseado em contagem, arquivos com remoção, previews com scroll e resultados parciais estão no código. Services preservam timeout/retry e mensagens amigáveis. XML usa polling de 2 s com até 300 consultas; DOCX usa 3 s e acompanha o prazo do lote. Há ações de nova seleção/consulta e ocultação de dados expirados.

A ausência de config desabilita o envio, evitando inventar limites. SSR não tenta abrir arquivos ou restaurar contas privadas. Erros de API em operações normais não precisam derrubar o conteúdo editorial público.

### Responsividade e acessibilidade

[C] Media queries para colunas, botões, preview interno, nomes longos, foco visível, labels, controles nativos, status textual/live e reduced-motion. [A] Artefatos anteriores cobrem seis larguras e estados. [NV] Nenhuma nova inspeção interativa, teste com leitor de tela ou cálculo de contraste foi realizado.

Há um problema semântico localizado: plans.ts:7 produz um main dentro do main do shell. Não é uma causa comprovada de desindexação. Menus globais também introduzem H2 antes do H1 do conteúdo; isso é melhoria de hierarquia, não bloqueio de indexação.

### Bundles e manutenção

Rotas lazy preservadas; sem fonte externa, vídeo ou biblioteca de animação. Catálogo no shell é intencional para navegação. XML concentra cinco modos em aproximadamente **1054 linhas TS e 1151 HTML**: aumenta acoplamento e custo de regressão. O CSS compartilhado usa ViewEncapsulation.None com namespace .tool-app; futuras regras precisam respeitar esse isolamento.

## 6. Backend

### 6.1. Superfície HTTP identificada no código

As rotas api.php recebem prefixo /api. Parte das rotas com sessão/CSRF está em web.php deliberadamente.

| Grupo | Métodos e caminhos |
| --- | --- |
| Configuração e saúde | GET /api/tools/{tool}/config, GET /api/health, health do framework /up |
| Auth | POST /api/auth/register, /login, /logout; POST /api/auth/google/start; GET /api/auth/google/callback; GET /api/me e /api/user |
| Lotes | POST /api/processings; GET /api/processings para histórico; GET /api/processings/{uuid}; POST .../start |
| Arquivos/resultados | GET/POST/DELETE .../files; GET .../result; GET .../pdf/{inputUuid} |
| Análise | GET .../analysis; GET .../analysis/export |
| Compra avulsa | GET/POST .../purchase; POST .../purchase/cancel |
| Conta | PATCH /api/account/profile e /password; GET /api/account/usage e /purchases |
| Assinatura | GET /api/account/subscription; POST .../checkout; POST .../portal |
| Stripe | POST /api/stripe/webhook |
| Scaffold | GET / na origem da API entrega welcome; não é ferramenta |

São 22 declarações em api.php e 10 em web.php, com prefixes/grupos; a rota /up e a rota de CSRF são registradas pelo framework. Esses números de declarações não equivalem à contagem de páginas públicas do produto.

### 6.2. Camadas e persistência

Controllers delegam para services; FormRequests limitam entrada; Resources filtram a saída. Models: User, Processing, ProcessingFile, ProcessingFileResult, Purchase e Subscription. IDs públicos usam UUID; chaves internas e metadata não são serializados indiscriminadamente.

Tabelas de domínio: users, processings, processing_files, processing_file_results, purchases, subscriptions e stripe_webhook_events. Há sessions, password_reset_tokens, personal_access_tokens, jobs, failed_jobs, job_batches e cache/cache_locks. A presença de password_reset_tokens **não significa que recuperação de senha esteja implementada**.

Migrations criam índices em UUIDs, expiração, usuário/data/uso, status de arquivos e identificadores financeiros. Há unique para compra por lote e evento Stripe. Migrações e estado real do banco não foram comparados.

### 6.3. Jobs, concorrência e limites

`ProcessingStartService.php:23` escolhe conexão documents para DOCX e conexão fiscal configurável para os demais. Driver síncrono/deferred/failover inadequado é recusado antes de executar trabalho pesado no HTTP. Há lock de lote e usuário, reserva de uso e dispatch depois do commit.

- Fiscal: três tentativas, timeout 60 s, backoff 10/30 e lock WithoutOverlapping com lease 75 s; database retry_after padrão 90.
- DOCX: um job por entrada, três tentativas de infraestrutura, failOnTimeout, timeout de conversão +20 (80 s padrão; até 140), lease +10 e retry_after 180; fila documents.
- Processamentos e arquivos terminais são rechecados para evitar repetição e ressurreição após exclusão.
- O núcleo fiscal preserva commit por arquivo, mas gera resultados sob transação/lock. Arquivos/planilhas grandes podem segurar o lock por mais tempo.
- Análise mantém snapshots privados limitados, cache de até quatro escopos e exportação baseada nos filtros. Endpoints de visão/exportação também usam transação/lock.

### 6.4. Storage, TTL e limpeza

Disk processing aponta para storage/app/private/processing, private/serve=false. Entradas usam nome gerado, tamanho e SHA-256; leitores revalidam integridade. Downloads abrem stream sob lock e rechecagem de dono/prazo/status. Arquivos não são disponibilizados por links públicos permanentes.

Purge agendado por hora, sem overlap; compras abandonadas a cada cinco minutos. TTL bloqueia acesso imediatamente na aplicação; a limpeza física pode esperar a próxima execução bem-sucedida. Sem acesso ao cron, não se garante que essa rotina esteja rodando.

removeFiles elimina prefixos gerenciados, resultados detalhados, nomes originais e informações fiscais específicas, marca expiração e preserva registros técnicos/financeiros/histórico. Não executa delete da conta ou de todos os registros de lote. Backups e retenção de logs não foram verificados.

**Exceção importante:** XML/Excel e Produtos geram XLSX com tempnam(sys_get_temp_dir()) e unlink no finally. Um SIGKILL pode deixar um arquivo fora dos prefixos do purge. Ver R02; não foi constatado arquivo órfão real na VPS.

### 6.5. Autenticação, Stripe e falhas

Sanctum usa sessão, CSRF e cookies; senha tem cast hashed e exigência de 12 caracteres com limite de 72 bytes. Login regenera sessão; logout invalida; troca de senha exige senha atual. Google usa Socialite com state, PKCE, contexto de dez minutos, e-mail verificado do Google e proteção contra vinculação silenciosa de contas de mesmo e-mail.

StripeCheckoutGateway, PurchaseService, SubscriptionService e gateways de assinatura implementam idempotency keys, validação de preço/volume/currency/mode, checkout/portal allowlisted e webhook assinado. Eventos são deduplicados em tabela com transação. Assinatura busca estado atual remoto para evitar regressão por ordem de eventos. Pro exige status active e período futuro; trialing/past_due não são Pro por inferência.

O código **suporta TEST e LIVE** com coerência de chaves/objetos. O modo real da produção não foi inspecionado. Disponibilidade pública de checkout não prova secrets operacionais ou pagamento real.

Exceções retornam mensagens sanitizadas; jobs evitam gravar stack contendo documento em failed_jobs; logs evitam XML, stderr e dados fiscais. A configuração suporta logs single/daily e a documentação pede rotação; canal/rotação/monitoramento reais são desconhecidos.

## 7. Infraestrutura e deploy

**[H]** Frontend servido por Vercel, HTTPS, HSTS e gzip em HTML/JS/CSS. www/HTTP convergem para HTTPS apex. API responde por Nginx em HTTPS. Não se confirmou provedor da VPS, OS completo, CPU/RAM, PHP-FPM, banco, backups ou permissões efetivas.

**[C]** Vercel usa npm run build, output dist/feitoo-web/browser e regras antes do filesystem para redirects/noindex; fallback de desconhecidos é 404. Output Angular é static: não exige processo Node SSR por requisição.

**[C]** Backend possui exemplos de Nginx, Supervisor fiscal, cron e instruções específicas DOCX. O exemplo geral contém somente o programa feitoo-worker; a existência/estado do worker dedicado real não foi verificada. A arquitetura documents está implementada e deve ser preservada.

Não foi encontrada pasta .github/pipeline CI versionado nos projetos/raiz. Integrações configuradas apenas no dashboard Vercel ou outro serviço não foram acessadas; não é possível afirmar que não exista nenhuma automação externa.

**Documentação tem divergências comprovadas:** docs/production.md:97 diz que LIVE está bloqueado, mas código/testes já suportam live; as linhas 107/109 descrevem sitemap antigo e imagem OG pendente, enquanto produção possui 13 URLs e imagem servida. Não usar esse texto como runbook atual sem revisão.

## 8. SEO técnico

### 8.1. Rastreamento e indexabilidade atual

[H] robots.txt:
```text
User-agent: *
Allow: /
Disallow: /api/
Disallow: /sanctum/
Sitemap: https://feitoo.com.br/sitemap.xml
```

Não bloqueia as páginas de ferramenta. Privados são excluídos da indexação por noindex, não por uma regra robots que impediria a leitura da diretiva. O sitemap tem 13 URLs limpas e não inclui login, cadastro, conta ou erro. Falta de lastmod não é tratada como erro de indexação.

| URL | HTTP | Robots HTML | H1 | Tipos de schema | FAQ |
| --- | --- | --- | --- | --- | --- |
| `/` | 200 | `index,follow` | 1 | WebSite, Organization | — |
| `/analisar-xml` | 200 | `index,follow` | 1 | WebApplication, BreadcrumbList, FAQPage | 8 |
| `/como-converter-xml-nfe-para-excel` | 200 | `index,follow` | 1 | Article, BreadcrumbList | — |
| `/como-extrair-produtos-de-xml-nfe` | 200 | `index,follow` | 1 | Article, BreadcrumbList | — |
| `/docx-para-pdf` | 200 | `index,follow` | 1 | WebApplication, BreadcrumbList, FAQPage | 7 |
| `/organizar-xml` | 200 | `index,follow` | 1 | WebApplication, BreadcrumbList, FAQPage | 8 |
| `/planos` | 200 | `index,follow` | 1 | WebPage | — |
| `/privacidade` | 200 | `index,follow` | 1 | WebPage | — |
| `/produtos-dos-xmls` | 200 | `index,follow` | 1 | WebApplication, BreadcrumbList, FAQPage | 7 |
| `/seguranca` | 200 | `index,follow` | 1 | WebPage | — |
| `/termos` | 200 | `index,follow` | 1 | WebPage | — |
| `/xml-para-excel` | 200 | `index,follow` | 1 | WebApplication, BreadcrumbList, FAQPage | 6 |
| `/xml-para-pdf` | 200 | `index,follow` | 1 | WebApplication, BreadcrumbList, FAQPage | 7 |

Todos os 13 títulos e descrições são distintos. As URLs da tabela têm um canonical próprio e correspondente à URL de HTTPS sem www. Não apareceu X-Robots-Tag de bloqueio nas páginas indexáveis consultadas.

### 8.2. Redirecionamentos, privados e 404

[H] As variantes / e /index.html das 12 URLs não raiz retornaram 308 diretamente para a rota limpa. /index.html redireciona para /. A URL limpa foi consultada separadamente e retorna 200, sem loop. HTTP e www de XML/Excel redirecionam para https://feitoo.com.br/xml-para-excel.

Login/cadastro/conta retornam shell CSR 200 com meta noindex,follow e X-Robots-Tag noindex, follow, sem canonical da home e sem JSON-LD da home. A ausência de H1/conteúdo no HTML dessas páginas é intencional e não se aplica às ferramentas.

Uma URL inexistente retornou **404 real**, sem canonical e com noindex. /404 explícito retorna 200 noindex por ser página estática conhecida: pequena oportunidade de normalização, mas não um fallback 200 para qualquer URL inexistente.

### 8.3. Renderização e links

OutputMode static + RenderMode.Prerender em 14 rotas, incluindo erro; wildcard Client. Portanto, **SSG/prerender para conteúdo público, CSR para conta/auth, com hidratação Angular**, não SSR dinâmico em cada request. Há HTML/metadata antes de JavaScript. O artefato anterior prerendered-routes.json também lista 14 entradas.

Páginas de ferramenta acessadas diretamente devolvem H1, editorial, links e FAQ. O uploader pode iniciar desabilitado até obter limites: estado esperado, não ausência de conteúdo SEO.

[H] Cada URL indexável recebe ao menos um link interno dentro do conjunto auditado. Os guias têm links contextuais a partir das ferramentas correspondentes; as ferramentas constam no header/footer/home. Não foram identificadas páginas órfãs entre as 13. Menus em details continuam contendo âncoras href no HTML.

Uma leitura com user-agent “Googlebot” manteve 200, H1, index,follow e canonical de XML/Excel. Isso é simulação de header: **não prova acesso do Googlebot real**, que depende de rede/IP/logs e Search Console.

**[NV] DOM após hidratação em produção não foi comparado nesta etapa.** jsdom apenas interpretou o HTML recebido sem executar scripts. O código SeoService reaplica metadata e limpa canonical/schema ao navegar; testes/artefatos anteriores cobrem navegação sintética. Não se atribuiu a isso uma medição de renderização do Google.

### 8.4. Interpretação das poucas impressões

Não há bloqueador técnico atual comprovado nas 13 URLs. HTTP 200, sitemap e noindex correto não garantem rastreamento, indexação ou posição; essa distinção é documentada pelo [Google sobre sitemaps](https://developers.google.com/search/docs/crawling-indexing/sitemaps/overview).

Prioridade diagnóstica no GSC:
1. Confirmar propriedade de domínio ou prefixo HTTPS/apex correto e filtros/período do relatório de desempenho.
2. Inspecionar XML/Excel e as outras ferramentas: descoberta, último crawl, indexação permitida, canonical declarada versus escolhida pelo Google.
3. Comparar versão indexada com teste ao vivo; um HTML hoje corrigido não prova que o Google já o revisitara.
4. Verificar sitemap enviado/lido, quantidade descoberta, motivos de exclusão e amostra de URLs.
5. Consultar ações manuais/segurança; não presumir penalização sem esses relatórios.
6. Exportar impressões por página, consulta, país e dispositivo antes de definir novas páginas ou reescrever títulos.

A [Inspeção de URL](https://support.google.com/webmasters/answer/9012289) permite verificar crawl/canonical/versão indexada. Não foi usado resultado de busca manual para declarar uma URL indexada ou não indexada.

Hipóteses não confirmadas: propriedade/filtros errados no GSC, rastreamento anterior de um build noindex, descoberta externa limitada, histórico/autoridade insuficientes, baixa demanda em recortes específicos, concorrência e aderência insuficiente às buscas mais genéricas. Data de lançamento, backlinks, posições e volumes não foram medidos.

### 8.5. Dados estruturados e intenção

WebSite/Organization na home; WebApplication/BreadcrumbList/FAQPage nas ferramentas; Article/BreadcrumbList nos dois guias; WebPage em informações/planos. As 43 FAQs das ferramentas coincidem com a fonte visível no HTML. Sem rating, review ou estatística inventada identificados nos graphs analisados.

**Atualização relevante em 2026:** o [registro oficial de atualizações do Google](https://developers.google.com/search/updates) informa que o recurso de FAQ rich results deixou de aparecer em 7/5/2026 e que sua documentação foi removida em 15/6/2026. A antiga orientação de 2023, limitada a sites governamentais/saúde, não descreve mais a disponibilidade atual. O FAQ visível continua útil ao usuário; sua correspondência com FAQPage é coerente semanticamente, mas não deve ser tratada como promessa de snippet FAQ ou como causa da falta de impressões. Não é necessário apagar conteúdo útil por causa dessa mudança. Não adicionar ratings/offer fictícios para perseguir rich results. Dados estruturados também não substituem qualidade/descoberta ou garantem posição, conforme as [diretrizes oficiais](https://developers.google.com/search/docs/appearance/structured-data/sd-policies).

Prerender é uma boa base para esse cenário, coerente com as [orientações de JavaScript SEO do Google](https://developers.google.com/search/docs/crawling-indexing/javascript/javascript-seo-basics). Não há justificativa técnica observada para migrar todo o projeto apenas para “consertar o Angular para o Google”.

## 9. SEO individual por ferramenta

Os resultados abaixo são do HTML de produção coletado nesta auditoria, não somente das constantes do código.

### 9.1. XML para Excel

- **HTTP:** 200; um H1 e um canonical: `https://feitoo.com.br/xml-para-excel`.
- **Title:** XML para Excel Online | Converter NF-e em Planilha | Feitoo.
- **Description:** Converta arquivos XML de NF-e para Excel online. Extraia dados das notas e gere uma planilha organizada em poucos passos.
- **H1:** Converter XML para Excel.
- **Conteúdo H2 editorial:** Como converter XML para Excel; O que é extraído dos XMLs; Para quem essa ferramenta é útil; Seus XMLs ficam seguros?; Perguntas frequentes.
- **FAQ:** 6 perguntas; correspondência integral entre perguntas/respostas visíveis e FAQPage no HTML inicial.
- **Schema:** WebApplication, BreadcrumbList e FAQPage em um script, sem duplicação. OG e Twitter completos com título/descrição próprios, URL limpa e imagem comum.
- **Conteúdo e intenção:** Bom alinhamento com conversão de XML fiscal para planilha, com explicação das abas Notas/Itens, seleção em lote, segurança e guia contextual. A ferramenta é específica de NF-e 55: não atende qualquer XML genérico. Há aproximadamente 592 palavras no body inteiro, incluindo navegação e FAQ; isso não é uma nota de qualidade nem um mínimo exigido pelo Google.

### 9.2. Produtos dos XMLs

- **HTTP:** 200; um H1 e um canonical: `https://feitoo.com.br/produtos-dos-xmls`.
- **Title:** Extrair Produtos de XML NF-e para Excel | Feitoo.
- **Description:** Extraia e consolide produtos de vários XMLs de NF-e em uma planilha. Some quantidades e organize os itens automaticamente.
- **H1:** Extrair produtos de XMLs NF-e.
- **Conteúdo H2 editorial:** Como extrair produtos dos XMLs; Como funciona a consolidação; O que aparece na planilha; Quando usar esta ferramenta; Seus XMLs ficam seguros?; Perguntas frequentes.
- **FAQ:** 7 perguntas; correspondência integral entre perguntas/respostas visíveis e FAQPage no HTML inicial.
- **Schema:** WebApplication, BreadcrumbList e FAQPage em um script, sem duplicação. OG e Twitter completos com título/descrição próprios, URL limpa e imagem comum.
- **Conteúdo e intenção:** Página diferenciada da conversão geral: consolida produtos e quantidades, explica regras de agrupamento e formatos de exportação. Tem guia próprio e links para ferramentas fiscais. Não há evidência de página duplicada por sinônimo.

### 9.3. XML para PDF / DANFE

- **HTTP:** 200; um H1 e um canonical: `https://feitoo.com.br/xml-para-pdf`.
- **Title:** XML para PDF Online | Gerar DANFE de NF-e | Feitoo.
- **Description:** Converta XML de nota fiscal em DANFE PDF online. Envie seu arquivo NF-e, visualize o documento e baixe o PDF.
- **H1:** Converter XML em PDF.
- **Conteúdo H2 editorial:** Como converter XML em DANFE PDF; O que é o DANFE?; Protocolo e situação da nota; Seus XMLs ficam seguros?; Perguntas frequentes.
- **FAQ:** 7 perguntas; correspondência integral entre perguntas/respostas visíveis e FAQPage no HTML inicial.
- **Schema:** WebApplication, BreadcrumbList e FAQPage em um script, sem duplicação. OG e Twitter completos com título/descrição próprios, URL limpa e imagem comum.
- **Conteúdo e intenção:** Foco correto em DANFE de NF-e, com definição do DANFE e limitações de protocolo/situação fiscal. Não deve ser vendida como conversor de qualquer XML em PDF. Links contextuais e avisos estão no HTML.

### 9.4. Organizar XML

- **HTTP:** 200; um H1 e um canonical: `https://feitoo.com.br/organizar-xml`.
- **Title:** Organizador de XML Online | Notas por CNPJ e Mês | Feitoo.
- **Description:** Organize arquivos XML de notas fiscais automaticamente por fornecedor, CNPJ, mês ou ano. Envie seus XMLs e baixe tudo organizado em ZIP.
- **H1:** Organizador de XMLs Online.
- **Conteúdo H2 editorial:** Como organizar vários arquivos XML; Separar XMLs por fornecedor, CNPJ ou mês; Originais preservados e duplicados visíveis; Seus XMLs ficam seguros?; Perguntas frequentes.
- **FAQ:** 8 perguntas; correspondência integral entre perguntas/respostas visíveis e FAQPage no HTML inicial.
- **Schema:** WebApplication, BreadcrumbList e FAQPage em um script, sem duplicação. OG e Twitter completos com título/descrição próprios, URL limpa e imagem comum.
- **Conteúdo e intenção:** Explica organização por emitente/documento/período/modelo, duplicados e preservação de originais seguros. Conteúdo específico, diferente dos outros conversores. Os controles dependem da hidratação, mas o texto editorial não.

### 9.5. Analisar XML

- **HTTP:** 200; um H1 e um canonical: `https://feitoo.com.br/analisar-xml`.
- **Title:** Analisador de XML NF-e Online | Dashboard e Excel | Feitoo.
- **Description:** Analise vários XMLs de notas fiscais online. Visualize valores, emitentes, produtos e tributos em um dashboard e exporte relatórios para Excel.
- **H1:** Analisador de XMLs Online.
- **Conteúdo H2 editorial:** Como analisar XMLs de notas fiscais; Documentos, emitentes, produtos e tributos; Sem dupla contagem de notas; Entenda o escopo dos valores; Seus XMLs ficam seguros?; Perguntas frequentes.
- **FAQ:** 8 perguntas; correspondência integral entre perguntas/respostas visíveis e FAQPage no HTML inicial.
- **Schema:** WebApplication, BreadcrumbList e FAQPage em um script, sem duplicação. OG e Twitter completos com título/descrição próprios, URL limpa e imagem comum.
- **Conteúdo e intenção:** Explica escopo analítico, duplicação, conflitos, valores e tributos. O dashboard privado não é necessário para compreender a utilidade pelo HTML inicial. Não deve prometer auditoria ou validação fiscal oficial.

### 9.6. DOCX para PDF

- **HTTP:** 200; um H1 e um canonical: `https://feitoo.com.br/docx-para-pdf`.
- **Title:** DOCX para PDF Online | Converter Word em PDF | Feitoo.
- **Description:** Converta arquivos DOCX do Word para PDF online. Envie seus documentos e baixe os PDFs prontos em poucos passos.
- **H1:** Converter DOCX para PDF online.
- **Conteúdo H2 editorial:** Como converter DOCX para PDF; O que a conversão preserva; Posso converter vários documentos?; Por que transformar Word em PDF?; Os documentos ficam armazenados?; DOCX e DOC são a mesma coisa?; Preciso do Microsoft Word?; Perguntas frequentes.
- **FAQ:** 7 perguntas; correspondência integral entre perguntas/respostas visíveis e FAQPage no HTML inicial.
- **Schema:** WebApplication, BreadcrumbList e FAQPage em um script, sem duplicação. OG e Twitter completos com título/descrição próprios, URL limpa e imagem comum.
- **Conteúdo e intenção:** Foco claro em Word/DOCX, instalação desnecessária, preservação/variação de layout, lotes, DOC versus DOCX, armazenamento e ZIP. Os limites Free/Pro aparecem no conteúdo editorial. Não há páginas duplicadas para cada variação de “Word para PDF”.


### 9.7. Potencial das consultas solicitadas

| Consulta | Página/intenção mais adequada | Avaliação sem prometer posição |
| --- | --- | --- |
| converter xml para excel online | /xml-para-excel | Boa aderência transacional; explicar NF-e evita expectativa de XML genérico |
| xml para excel | /xml-para-excel | Compatível, porém termo amplo pode abranger estruturas não suportadas |
| converter xml nfe em excel | /xml-para-excel | Aderência específica mais direta ao produto atual |
| extrair produtos de xml | /produtos-dos-xmls | Página especializada diferencia consolidação de extração geral |
| converter xml em planilha | /xml-para-excel | Sinônimo natural, sem necessidade de URL duplicada |
| ferramentas online grátis | home | Posicionamento amplo; não atende a todos os tipos de tarefa e exige conta para processar. Potencial depende de demanda/concorrência/autoridade não medidos |

Os guias podem apoiar intenção informacional e levar às ferramentas. Há sobreposição temática natural entre guia e app; canonical próprio e conteúdo diferente foram observados. “Canibalização” exige dados de consultas/URLs, não foi comprovada aqui. Não há motivo demonstrado para criar páginas para cada sinônimo ou aumentar repetição de palavras-chave.

## 10. Performance

### O que está medido ou disponível

[H] HTML, JS e CSS consultados são gzip. HTML usa cache public/max-age=0/must-revalidate; arquivos JS/CSS com hash também usam essa política. main de produção observado tinha cerca de 21,8 kB descomprimido; styles cerca de 8,6 kB. A imagem OG responde 200, PNG, Content-Length 608136 bytes. Não foi tratada como LCP: ela é metadata social e não uma imagem grande exibida no hero.

[A] Último relatório local de build: **372,61 kB iniciais brutos e 105,61 kB estimados de transferência**, XML lazy 108,58 kB, DOCX 20,52 kB e compartilhado de ferramentas 16,67 kB. Arquivos existentes em dist confirmam tamanhos individuais semelhantes. O hash do main em produção difere do artefato local: **esses totais não são uma medição nova do bundle integral publicado**.

[C] Sem fontes externas e biblioteca de animação; ícones SVG; lazy routes; arquivos/conta/preview carregados conforme uso. CSS é próprio. Um stylesheet aparece também como fallback noscript: a repetição textual no parser HTML não prova duas transferências no navegador.

### Gargalos potenciais

- Revalidação desnecessária de assets com hash em visitas repetidas.
- Polling gera várias consultas por minuto e duplica leitura de lista por ciclo em alguns modos.
- Serialização/consulta de previews pode crescer com o lote, embora limitada por capacidade.
- Parse, geração de XLSX/ZIP/DANFE e leitura de snapshots consomem CPU/memória/disco.
- Transações mantidas durante geração/Stripe/exportação podem provocar espera de lock.
- Validação de múltiplos DOCX ocorre também no request antes dos jobs; limites por documento não equivalem a orçamento total de request.
- Infra de conversão/filas/storage pode dominar a latência funcional sem alterar o HTML público.

**Não medidos:** Lighthouse, LCP, INP, CLS, TTFB estatístico, CrUX, latência p95/p99, memória/CPU da VPS, cache-hit real e tempo de fila. Não se atribuiu nota ou aprovação de Core Web Vitals.

## 11. Segurança

### Controles confirmados por código

- Auth Sanctum, CSRF, password hashing, regeneração de sessão e regras de senha.
- Ownership antes de validação pesada; UUID não substitui autorização.
- Storage privado, nomes gerados, integridade por checksum, sem servir uploads como assets.
- XMLReader limitado em tamanho/profundidade/nós/tempo; DTD/entidades recusadas; LIBXML_NONET e loader externo desabilitado.
- DOCX com verificação de assinatura ZIP, estrutura OOXML, MIME/extensão, entradas duplicadas/caminhos perigosos/symlinks/criptografia/macros/objetos e zip bomb; relacionamentos externos ativos recusados.
- LibreOffice em subprocesso com argumentos em array, stdout/stderr desabilitados, sem interpolação shell de nome de usuário.
- Bubblewrap sem rede/capabilities, workspace isolado e bind read-only preservando **/lib, /lib64, /etc/fonts, /etc/libreoffice e /etc/ld.so.cache**.
- prlimit de memória/endereço, CPU e tamanho; timeout de parede; validação do PDF gerado.
- Planilhas escrevem strings explicitamente para evitar formula injection; decimais usam Brick Math e proteção contra perda de precisão.
- Webhook valida assinatura, limite de corpo, ambiente, preço/moeda/vínculo e idempotência.
- Logs/resultados sanitizados e cache privado nas respostas de conta/lote.

### HTTP e limites da conclusão

Frontend apresentou HSTS, nosniff, X-Frame-Options, Referrer-Policy e CSP limitada. API apresentou CORS com origem exata e credentials; uma origem alheia não foi refletida como permitida. Responder JSON público com CORS da origem fixa não é um bypass: navegador compara a origem.

API não apresentou HSTS/CSP/nosniff/X-Frame-Options/Referrer-Policy nas amostras; detalhado em C04. Não foram coletados cookies de login/CSRF para não iniciar fluxo de autenticação. Secure/HttpOnly/SameSite são confirmados na configuração de código, não em sessão real.

Arquivos .env reais não foram lidos. A listagem de arquivos versionados não mostrou .env real ou chaves privadas nos padrões consultados; há .env.example e configuração pública gerada. Não foi auditado todo o histórico Git, logs, backup ou sistema de arquivos do host; não se certifica ausência de vazamentos.

Não houve scan de CVEs/advisories, auditoria de SO/LibreOffice ou teste de exploração. dev-master está fixado por lock, mas atualização futura merece revisão e regressão. Correção de dependências não deve ser confundida com troca automática para latest.

## 12. Monetização e experiência do usuário

Visitante pode ler/selecionar; processar/baixar exige conta. Isso protege quota e arquivos, mas introduz fricção justamente antes da recompensa. Login por e-mail preserva arquivos em memória; Google exige nova seleção após navegação completa quando indicado pela interface.

**Free:** limites por conta, ferramenta e dia. **Avulso:** apenas XML/Excel e Produtos. Produção anuncia xml_100 por R$ 4,90 e xml_250 por R$ 6,90. Pacotes 500/1000 existem na configuração mas são filtrados por capacidade técnica; não estão expostos nas configs HTTP. A compra é para um lote com volume imutável, não saldo reutilizável.

**Pro:** código/planos anunciam R$ 29,90/mês, XML até 250/lote/500 dia e 24h de retenção; DOCX segue sua quota própria 20/100. O checkout valida Price remoto com BRL, valor e recorrência mensal; o estado concedido vem de eventos/consulta Stripe, não do redirect de sucesso. Conta possui portal, status, cancelamento agendado e histórico.

**Fricções/gaps comprovados:** recuperação de senha por e-mail não tem rota nem UI, embora exista tabela scaffold; conta Google não ganha senha automaticamente; perfil altera nome, não um fluxo completo de mudança de e-mail. Exclusão da conta é orientada a contato, não endpoint self-service. /planos e cards continuam muito centrados em XML e não comparam quotas DOCX. Limites/TTL de informações públicas não explicam plenamente todas as situações Pro.

**Medição:** analytics é consent-aware, usa allowlist e não envia arquivos/nome/e-mail/UUID de lote no payload. IDs podem ser usados localmente para deduplicação, sem envio ao GA4. page_location é normalizado e page_referrer é vazio: isso protege privacidade, mas reduz atribuição de aquisição, incluindo orgânico/UTM. Não usar GA4 sem aquisição como substituto de impressões do GSC. Eventos reais e taxa de conversão não foram consultados.

## 13. Testes e qualidade

### Inventário, sem execução nesta etapa

- **Angular:** 20 arquivos spec, **201 casos por análise estática de AST**, incluindo expansão dos arrays it.each; não apenas contagem de funções.
- Distribuição: conta 53; XML/Excel 33; SEO/public launch 16; auth page 11; apresentação compartilhada 11; Produtos 10; DOCX 10; analytics 9; auth service 9; processing service 8; app 7; demais 24.
- **PHP:** 22 arquivos Test.php, **245 métodos de teste**: 44 Unit e 201 Feature. A contagem estática não representa testes executados/passed nem expande providers/loops como novas execuções.
- **Públicos/SEO Node:** public-assets contém cinco testes; home-docx contém dois; tools-experience-seo gera seis, total 13 casos nesses três scripts.
- **Smokes:** check-tool-browser, check-organizer-browser, check-docx-browser e check-tools-experience-browser. APIs/arquivos sintéticos, sem comprovar conversão real ou cobrança.
- **Cobertura:** não foi encontrado relatório de cobertura nos caminhos convencionais consultados. Não se atribui percentual.

### O que as suítes cobrem

Autenticação/guards/return path, Google, conta, estado/seleção, upload, isolamento por ferramenta, polling, parcial/falha/expiração, preview e downloads, processamento fiscal, XML hostil/XXE, capacidade/decimais, organização/duplicação, análise/filtros/conflitos, quotas, ownership, assinatura/idempotência de webhook e coerência TEST/LIVE. Build/generate-public-assets contém assertions adicionais sobre HTML, schemas, metadata e links.

phpunit.xml configura SQLite em memória, cache/session em array, mail array e queue sync. Os testes podem, ainda assim, escrever fixtures temporárias/storage e o script Composer limpa config. Por isso **não foram executados** sob a regra estritamente documental.

A integração LibreOffice é explicitamente opcional por FEITOO_TEST_LIBREOFFICE=1 e cria workspaces. Presença do teste não prova execução contra Bubblewrap/LO da produção.

### Evidência histórica disponível

[A] docs/tools-experience-redesign.md declara 201 testes aprovados e build anterior. dist/validation/tools-experience/report.json contém seis ferramentas, 96 combinações layout/estado, oito downloads e runtimeErrors vazio; há relatórios de auth/conta/monetização/DOCX e capturas anteriores. São dados sintéticos e anteriores à auditoria, não um novo “passou”. Não foi feita validação de assinatura/proveniência desses artefatos.

Sem CI versionado encontrado e sem execução nova, permanece pendente revalidar a suíte em ambiente isolado antes de qualquer futura publicação.

## 14. Problemas comprovados

Prioridades: **P0** compromete operação/segurança de forma crítica; **P1** importante para operação, indexação ou receita; **P2** melhoria significativa; **P3** evolução. Não se elevou hipótese de posição no Google a P0/P1 “bug comprovado”.

### C01 — P1 — Textos de pré-lançamento e responsável pendente na produção

- **Evidência [H+C]:** /privacidade e /termos exibem “PENDENTE ANTES DA PUBLICAÇÃO”. information.ts:52 e :118; configure-launch.mjs:31 aceita responsável vazio.
- **Impacto:** informação pública incompleta e redução de confiança em um serviço que recebe documentos e oferece compras.
- **Correção sugerida:** identificar o responsável real, revisar os textos com o responsável pelo serviço e validar configuração antes da publicação. Não inventar identificação ou declarar conformidade jurídica apenas com essa edição.
- **Como validar:** GET das duas páginas sem placeholders, contato operacional e coerência com o tratamento realmente praticado.
- **Não demonstrado:** que isso seja a causa das poucas impressões ou uma infração legal específica.

### C02 — P2 — Promessa de remoção de registros difere da implementação

- **Evidência [H+C]:** privacidade afirma remoção de “arquivos e registros do lote”; information.ts:73. PurgeExpiredProcessings.php:16 explicita retenção técnica; ProcessingService.php:157 limpa resultados, :163 anonimiza nomes e :167 marca expiração sem apagar o registro de Processing.
- **Impacto:** usuário pode entender que todo registro técnico/financeiro desaparece com o TTL. Texto também fica genérico em 120 minutos e focado em XML, apesar de Pro e DOCX.
- **Correção sugerida:** alinhar descrição de arquivos, dados detalhados, histórico, conta, logs e retenção por plano ao comportamento aprovado; definir política de backup/logs. Não apagar histórico financeiro indiscriminadamente.
- **Como validar:** rastrear um lote sintético em ambiente isolado antes/depois da limpeza e comparar com o texto aprovado.

### C03 — P2 — API retorna 500 para ausência de Accept JSON

- **Evidência [H]:** GET /api/me sem Accept JSON retornou 500; com Accept application/json, com/sem Origin, retornou 401. /api/processings sem sessão com headers do app retornou 401.
- **Código:** api-session.interceptor.ts:12 protege o cliente Angular; bootstrap/app.php:29/:49 força JSON/renderiza auth. No framework local, ApplicationBuilder.php:278 define redirect para route(login), que não existe como rota nomeada web; Authenticate.php:104 consulta expectsJson antes do render de exceções. Caminho compatível com a falha observada; stack/log real não consultado.
- **Impacto:** semântica HTTP errada e alarmes/integrações prejudicados; não é prova de quebra do login Angular.
- **Correção sugerida:** fazer endpoints API responderem 401 independentemente da negociação de HTML, mantendo o fluxo SPA.
- **Como validar:** testes de GET não autenticado com Accept ausente, */*, text/html e application/json; todos sem redirect inválido/500.

### C04 — P2 — Headers de segurança da API não correspondem ao exemplo

- **Evidência [H+C]:** respostas de health/config/me não apresentam HSTS, nosniff, X-Frame-Options, Referrer-Policy ou CSP. docs/production/nginx.conf os prevê. A API também divulga versão detalhada do servidor.
- **Impacto:** hardening inconsistente e perda de proteções de transporte/tipo/conteúdo; não comprova exposição de documento ou bypass de autenticação.
- **Correção sugerida:** revisar configuração efetiva do Nginx/proxies e aplicar headers adequados com always, sem quebrar CORS/downloads; reduzir informação de versão quando apropriado.
- **Como validar:** comparar 200, 401, 404 e 5xx, CORS permitido/negado e downloads em staging; conferir HTTPS e cookies. Não copiar cegamente o arquivo de exemplo.

### C05 — P2 — Assets versionados sem cache longo no navegador

- **Evidência [H]:** main-LRH2M776.js e styles-HBGG5I6E.css retornam public,max-age=0,must-revalidate. vercel.json:9 não define política específica de immutable para arquivos com hash.
- **Impacto:** novas visitas podem exigir rede/revalidação mesmo com conteúdo versionado; gzip está funcionando. Não significa ausência de cache CDN ou download integral em toda visita.
- **Correção sugerida:** distinguir HTML revalidável de assets com hash e TTL longo; manter troca de hash em cada alteração.
- **Como validar:** headers dos assets e reload quente/frio em navegador, transferência/304 e atualização de release sem servir arquivo antigo.

### C06 — P2 — Runbook operacional contradiz código e produção

- **Evidência [C+H]:** docs/production.md:97 diz LIVE bloqueado; StripeEnvironment.php:14 aceita test/live e há testes dos dois. Linhas 107/109 descrevem sete URLs e OG ausente, mas há 13 URLs e PNG público. Exemplo Supervisor geral só contém worker fiscal.
- **Impacto:** risco de implantação/configuração/pagamento incorretos e conclusão errada sobre SEO ou documents.
- **Correção sugerida:** revisar runbook por versão, separar histórico de instrução vigente e registrar dependências/worker/cron/rollback de cada ferramenta.
- **Como validar:** checklist confrontado com código e configuração efetiva do host, sem inferir LIVE ou worker ativo do documento antigo.

### C07 — P2 — Recuperação de senha ausente

- **Evidência [C]:** routes/web.php, routes/api.php, app.routes.ts e auth-page não têm forgot/reset; migration inicial cria password_reset_tokens, mas não há fluxo de produto correspondente.
- **Impacto:** usuário pode perder acesso à conta/assinatura se esquecer a senha; login Google não vincula silenciosamente conta preexistente.
- **Correção sugerida:** especificar recuperação segura e entrega de e-mail operacional, com limites/expiração/anti-enumeração. Nesta etapa, não foi implementado.
- **Como validar:** posteriormente testar solicitação, token, expiração, uso único, retorno e acesso de conta Google/senha em ambiente isolado.

### C08 — P2 — Garantia de qualidade não está automatizada no repositório

- **Evidência [C+NV]:** há testes e scripts, mas nenhuma pasta/pipeline CI versionado encontrada. Cobertura não disponível nos caminhos consultados.
- **Impacto:** ausência de gate versionado demonstrável para SEO, suítes e regressão por ferramenta. Automação externa pode existir e precisa ser confirmada.
- **Correção sugerida:** definir pipeline reproduzível isolado com testes/build/SEO/smokes essenciais, sem usar produção nem secrets reais.
- **Como validar:** uma mudança deliberadamente inválida em branch de teste deve falhar o gate e impedir publicação autorizada.

### C09 — P2 — CSS acima do alerta e alto acoplamento das ferramentas XML

- **Evidência [C+A]:** cinco modos no componente de 1054 linhas TS/1151 HTML. Relatório anterior registra tool-experience.scss 7,47 kB, home 6,76 kB, shell 4,14 kB frente ao alerta de 4 kB; erro é 8 kB em angular.json:47.
- **Impacto:** margem pequena para CSS compartilhado e maior superfície de regressão; não foi medido impacto em CWV.
- **Correção sugerida:** reduzir regras/duplicações e separar responsabilidades de apresentação à medida que houver mudança funcional, mantendo testes/contratos; não subir budget apenas para silenciar.
- **Como validar:** próximo build isolado, diff de CSS/bundles e regressão visual das seis ferramentas.

### C10 — P3 — Landmarks/heading de navegação podem ser refinados

- **Evidência [C]:** plans.ts:7 produz main dentro do main do app; navegação global usa H2 antes do H1 principal.
- **Impacto:** semântica/acessibilidade menos clara. Não é bloqueio de indexação confirmado.
- **Correção sugerida:** um main por documento e hierarquia do conteúdo/navegação mais coerente.
- **Como validar:** inspeção de landmarks, headings, teclado e leitores de tela em revisão futura.

## 15. Riscos e hipóteses que precisam de validação

### R01 — P1 — Orçamento do polling versus limite por IP

- **Base [C]:** xml-to-excel.ts:871 faz GET a cada 2 s; :900 dispara listFiles por atualização para PDF/Organizador/Analisador. AppServiceProvider.php:46 agrupa acesso por IP; config/feitoo.php:25 default 60/min.
- **Impacto potencial:** cerca de 30 consultas de estado +30 de arquivos/min já encostam no limite, além de usage/ações. Duas abas ou usuários sob NAT podem excedê-lo; a API devolveria 429 e o cliente pode parar a consulta.
- **Não confirmado:** valor override efetivo da produção e incidência real sob filas longas.
- **Sugestão:** medir e ajustar política de polling/rate limit por usuário/operação, backoff e atualização de arquivos quando houver mudança.
- **Validação:** staging com job prolongado, duas abas/usuários no mesmo IP, observando 429 e recuperação sem gerar carga em produção.

### R02 — P1 — Temporário XLSX fora da limpeza por lote

- **Base [C]:** XmlToExcelResultService.php:31 usa tempnam no temporário do sistema e :70 unlink em finally. ProcessingService.php:123 limpa prefixos por UUID, não esse arquivo genérico; worker tem timeout.
- **Impacto potencial:** SIGKILL/quebra do processo pode deixar uma planilha fiscal fora do TTL gerenciado. O temporário não é, por isso, um arquivo publicamente exposto.
- **Não confirmado:** arquivos órfãos reais, política de limpeza de /tmp ou TMPDIR da VPS.
- **Sugestão:** associar staging ao lote privado ou uma política segura de temporários órfãos, mantendo exclusão normal e proteção de dados.
- **Validação:** matar worker com fixture sintética em staging, executar política de limpeza e verificar remoção e permissões. Não foi feita qualquer inspeção invasiva aqui.

### R03 — P1 — Saúde de operação não comprovada pelo health

- **Base [C+H]:** routes/api.php:63 retorna status ok literal, sem testar banco/fila/storage/conversor/scheduler. HTTP 200 só confirma liveness dessa rota.
- **Impacto potencial:** fila parada, disco cheio ou purge quebrado sem alerta adequado.
- **Não confirmado:** estado real do Supervisor/cron, backlog, jobs falhados, capacidade de disco e restore de backup.
- **Sugestão:** monitorar readiness/filas/purge/disco e tempos reais, sem expor informações internas em endpoint público.
- **Validação:** painel/check operacional autorizado e testes de falha controlados em staging; inspeção do servidor apenas em nova etapa aprovada.

### R04 — P2 — Locks longos em geração, filtros e Stripe

- **Base [C]:** FiscalProcessingService.php:61/:114, XmlAnalysisController.php:23/:59 e SubscriptionService.php:41/:65/:109 fazem I/O/geração/chamadas remotas em transações; PurchaseService.php:87 também chama Stripe sob lock.
- **Impacto potencial:** espera de usuários, contenção, timeout/retries; proteção de integridade é intencional e não deve ser removida sem desenho equivalente.
- **Não confirmado:** duração de locks e throughput em produção.
- **Sugestão:** medir antes de redesenhar; separar estado/intenção de I/O com rechecagem/idempotência quando os dados justificarem.
- **Validação:** tracing e teste de concorrência com Stripe fake/latente e datasets de capacidade em staging.

### R05 — P2 — Validação síncrona/abuso de recursos por conta

- **Base [C]:** validação DOCX pode percorrer até 80 MiB expandidos por documento antes do job; limite de validação é por arquivo. Cadastro com senha não exige verificação de e-mail; quotas são por usuário e auth tem throttle por IP.
- **Impacto potencial:** requests demorados ou múltiplas contas contornando a quota nominal; prlimit do conversor não limita todo o trabalho de upload.
- **Não confirmado:** abuso real, concorrência, CPU do PHP-FPM e timeouts efetivos.
- **Sugestão:** medir orçamento agregado, controles progressivos contra abuso e necessidade de verificação de e-mail, sem tornar a conversão desnecessariamente hostil ao usuário.
- **Validação:** cargas sintéticas limitadas, vários arquivos e contas de teste em staging; nenhuma tentativa de abuso foi feita contra produção.

### R06 — P1 de investigação — Diagnóstico de indexação no GSC ausente

- **Base:** páginas tecnicamente acessíveis [H], mas nenhum dado autenticado do Google [NV].
- **Impacto:** risco de gastar esforço em outro redesign/SSR sem conhecer o motivo da ausência de impressões.
- **Sugestão:** executar a matriz de Inspeção de URL/cobertura/performance da seção 8 antes de alterar SEO.
- **Validação:** export/prints do GSC com data de coleta, propriedade correta, URLs e motivos de exclusão; não usar site: como veredito.

### R07 — P2 — Atribuição GA4 limitada por desenho de privacidade

- **Base [C]:** analytics.service.ts:189/190 normaliza page_location e zera page_referrer; :303/304 faz o mesmo no config. Queries/UTMs não são preservadas.
- **Impacto potencial:** aquisição orgânica/campanhas subatribuídas. Isso não muda as impressões do Search Console.
- **Sugestão:** decidir escopo de medição consentida antes de mudar implementação; não reintroduzir URL/identificador sensível indiscriminadamente.
- **Validação:** fluxo de teste consentido com origem externa/campanha sintética e comparação de relatórios GA4, em ambiente próprio.

### R08 — P2 — Ambiente, dependências e retenção externa

- **Base [NV]:** sem acesso a secrets, host, configurações privadas, backups ou advisory scan.
- **Impacto potencial:** versões vulneráveis, isolamento operacional insuficiente, cookies incorretos, logs/backups retidos indevidamente ou pagamento em modo inesperado.
- **Sugestão:** revisão operacional separada, inventário de versões e varredura de dependências com política de atualização; documentar retenções e restauração.
- **Validação:** evidências do host/console em etapa autorizada. Nenhum CVE, vazamento ou cobrança errada foi afirmado sem evidência.

## 16. Oportunidades de melhoria

- Consolidar informação de produto/planos/retenção para incluir Documentos sem prometer recursos inexistentes.
- Manter app + guia separados por intenção e melhorar exemplos úteis a partir de dúvidas reais, não criar URLs por sinônimo.
- Revisar confiança pública: responsável, contato efetivo e textos vigentes.
- Instituir observabilidade de fila, limpeza e conversão com contagens/tempos sanitizados, sem conteúdo de documentos.
- Melhorar aquisição e recuperação de conta, respeitando consentimento e proteção contra enumeração.
- Evoluir módulos da interface por necessidades concretas; a base Angular/SSG não pede reescrita total.
- Benchmark de capacidade antes de elevar limites de 250 arquivos/4000 itens ou de conversão.
- Consolidar runbook e provas de regressão numa fonte rastreável por release.

## 17. Recomendações priorizadas

| Ordem | Prioridade | Ação para uma próxima etapa | Natureza da evidência |
| --- | --- | --- | --- |
| 0 | P1 investigação | Obter diagnóstico GSC das ferramentas, principalmente XML/Excel | Lacuna de dados; não bug de HTML comprovado |
| 1 | P1 | Resolver responsável/textos pendentes e alinhar tratamento/retenção informado | C01/C02 confirmados em produção/código |
| 2 | P1 | Validar e tratar orçamento de polling versus rate limit | R01: configuração/cadência comprovadas, falha sob carga ainda não |
| 3 | P1 | Confirmar destino/limpeza de temporários XLSX e política sob hard kill | R02: lacuna de cobertura no código; órfão real não confirmado |
| 4 | P2 | Alinhar hardening da API ao deployment efetivo | C04 confirmado por HTTP |
| 5 | P2 | Garantir 401 em endpoints API sem depender de Accept | C03 confirmado por HTTP |
| 6 | P2 | Atualizar runbook, CI e monitoramento/restore | C06/C08 e R03/R08 |
| 7 | P2 | Medir cache, CWV, locks e requests; só então otimizar | C05/C09 e R04/R05 |
| 8 | P2 | Planejar recuperação de senha e clareza comercial DOCX | C07 e UX |
| 9 | P3 | Refinar landmarks, headings e /404 explícito | Sem bloqueio técnico comprovado |

Não há correção de P0 proposta porque nenhum incidente P0 foi comprovado nesta auditoria. Não classificar o fato de GSC não estar conectado como vulnerabilidade ou falha de indexação.

## 18. Próximos passos sugeridos

1. **SEO:** disponibilizar evidência do GSC, com propriedade, período e Inspeção de URL, sem credenciais no relatório. Decidir com os motivos reais de indexação em mãos.
2. **Produto/confiança:** revisar responsável, privacidade, termos, retenção e diferenças por ferramenta/plano.
3. **Staging isolado:** validar polling/NAT, temporários sob hard kill, processamento real das fixtures, quotas e recuperação de falhas.
4. **Operação autorizada futura:** verificar worker fiscal/documents, scheduler, storage, headers, cookies, backup/restore e modo Stripe sem expor secrets.
5. **Qualidade:** executar as suítes existentes em ambiente isolado, anexar resultados novos e automatizar gates de build/SEO/contratos.
6. **Performance:** medir navegação fria/quente e Core Web Vitals antes de escolher otimização; avaliar cache de arquivos com hash.
7. Só então definir implementação, responsabilidades, validação e autorização de publicação.

### Limites finais desta entrega

Foi confirmado o que está no código, o HTML/headers públicos coletados e a existência de artefatos anteriores. **Não** se confirmou indexação Google, ranqueamento, métricas reais, resultado da suíte atual, sessão autenticada, pagamento, fila/cron ativos, conversão real, banco/OS da VPS, restore ou ausência de vulnerabilidades.

Nenhuma funcionalidade foi alterada e nenhuma correção foi implementada. O único arquivo criado nesta etapa foi `docs/auditoria-feitoo-estado-atual.md`.

