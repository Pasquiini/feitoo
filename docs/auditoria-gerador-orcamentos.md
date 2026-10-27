# Auditoria técnica — Gerador de Orçamentos Profissionais

**Data:** 20/10/2026. **Entrega:** diagnóstico e plano; implementação depende de autorização posterior.

**Atualização comercial — Fase 1:** por decisão do usuário, o Free passa a ser **1 geração bem-sucedida por dia**, por conta/ferramenta, em `America/Sao_Paulo`. Substitui a recomendação anterior de Free vitalício. O controle de consumo não faz parte da prova de PDF; a proposta abaixo foi ajustada para orientar a fase futura.

## 1. Diagnóstico da arquitetura atual

### Escopo e método

Auditoria estática do checkout em `D:\backup\Projetos\feitoo`. Referências Git: raiz `2a158a7`; frontend `5fc68e0`. Ambos já apresentavam alterações locais, inclusive na monetização, antes desta investigação. Os achados descrevem os arquivos atuais, incluindo essas alterações, e não apenas os commits. Não se presume que o checkout corresponda ao ambiente publicado.

A única escrita desta etapa é este relatório. Não foram executados testes, builds, migrations, instalações, pagamentos, commits, deploys, comandos de workers ou acesso ao VPS. Não foram consultados secrets, dados de clientes nem o banco. Testes e documentos encontrados são evidências de estrutura e intenção, não de aprovação nesta sessão. A documentação oficial externa foi consultada somente para fundamentar recomendações sobre PDF, Stripe e compartilhamento.

**Conclusão:** a plataforma oferece uma base aproveitável de autenticação, assinatura, arquivos privados, filas, histórico e downloads. O gerador exige um fluxo próprio de edição e geração, uma política de consumo por documento e créditos reutilizáveis. Apenas adicionar um valor ao enum de ferramentas e um pacote à configuração atual produziria comportamento incorreto.

### Inventário e organização

| Camada | Situação observada | Consequência para o gerador |
| --- | --- | --- |
| Frontend | Angular standalone 21, TypeScript, RxJS e signals; componentes carregados por `loadComponent` | Criar uma feature pequena e uma página pública própria |
| Ferramentas | Cinco rotas XML compartilham `XmlToExcelPage`; DOCX usa `DocxToPdfPage` | Não ampliar a página fiscal para comportar um editor comercial |
| Design | Tokens CSS globais, estilos de ferramentas sob `.tool-app` e seis componentes em `tool-experience.ts` | Reutilizar identidade e apresentação, adaptando textos orientados a arquivos |
| Backend | Laravel 12, PHP declarado `^8.2`, Sanctum, Socialite e SDK Stripe | Reutilizar sessão, CSRF, identidade e integração de assinatura |
| Persistência | Migrations de processamentos, arquivos, compras e assinaturas | Acrescentar estruturas de rascunho e crédito; não remodelar as compras fiscais |
| Execução | Jobs fiscais e de DOCX; conexão `database` e conexão `documents` | Criar job comercial explícito, sem cair no dispatcher fiscal |
| SEO | Build Angular estático com prerender e configuração Vercel | Registrar a rota em vários pontos além do router |

As seis ferramentas identificadas em `ProcessingTool`, rotas e catálogo são: XML para Excel, Produtos dos XMLs, XML para PDF, Organizador de XMLs, Analisador de XMLs e DOCX para PDF. Todas devem continuar com suas rotas, formatos, limites, avisos e fluxos comerciais atuais.

**Fontes principais:** `feitoo-web/package.json`, `src/app/app.routes.ts`, `src/app/app.routes.server.ts`, `angular.json`; `feitoo-api/composer.json`, `app/Enums/ProcessingTool.php`, `routes/api.php`, `routes/web.php`.

### Frontend: estado e experiência

As páginas de processamento usam signals/computed para seleção, progresso, autenticação, compra e resultados. `AuthPage` e a conta já usam Reactive Forms. Não foi identificado um editor estruturado de documentos com itens, cálculo monetário e prévia de página que possa ser simplesmente reutilizado.

`FileSelectionService` mantém arquivos em memória e possui um mecanismo IndexedDB de passagem pelo Google, com validade de 15 minutos e marcador em `sessionStorage`. Isso preserva seleções de arquivos, não um formulário de orçamento. Em `AuthService.startGoogle`, a preservação automática está concentrada em XML para Excel e Produtos; outras páginas têm chamadas próprias. Não há um mecanismo universal de rascunhos.

Há listas explícitas de destinos em `AuthPage`, no tipo de `AuthService.startGoogle` e em `GoogleAuthController`, tanto na entrada quanto no callback. A nova rota ainda não é aceita. O tratamento de erro do callback Google preserva `returnTo` apenas para a conta: é necessário garantir a recuperação também após falha/cancelamento do OAuth.

O design system atual oferece cores, espaçamentos, superfícies, botões, campos, mensagens, foco visível, skip link e redução de movimento. Os componentes de ferramentas têm pressupostos de upload: `ToolWorkspace` mostra quantidade de arquivos, `ToolUploader` pede arquivos e diversos estados de `ToolStatus` falam em lotes. `ToolHero` e `ToolRelated` consultam `HOME_TOOLS`; a categoria é derivada do catálogo. A categoria **Negócios** deve nascer com um único card real.

### Backend: Processing, PDF e retenção

`Processing` possui UUID público, proprietário, ferramenta, estado, contadores, timestamps, expiração e metadata. `ProcessingFile` registra entrada/saída, disk, caminho interno, MIME, checksum e expiração. `ProcessingFileResult` é especializado em resultados fiscais; não deve receber itens comerciais.

O início atual exige um arquivo de entrada ativo, reserva quota sob transação e muda de `pending` para `queued`. O dispatcher escolhe DOCX ou, para qualquer outra ferramenta, `ProcessFiscalFilesJob`. Upload e validação também distinguem DOCX de XML. **Orçamento não pode entrar nesses caminhos por omissão.**

Não existe hoje um renderizador genérico de orçamentos:

- `DanfeGenerator` valida NF-e/protocolo e gera DANFE com NFePHP; é específico do domínio fiscal.
- `LibreOfficeDocxToPdfConverter` converte um DOCX existente, com workspace e sandbox próprios; não oferece templates de orçamento.
- `PdfResultService.generate` monta um ZIP de PDFs já produzidos. O nome do serviço não significa geração de HTML para PDF. Seu método `store` grava a saída privada e o registro de arquivo e é aproveitável.
- `products-pdf.ts` escreve um PDF de resumo no navegador, com uma página e amostra de até dez produtos. Não serve como documento comercial completo, com paginação, logo e todas as descrições.

O disk `processing` usa armazenamento local privado em `storage/app/private/processing`, sem publicação automática. Download abre stream sob o lock do processamento, valida dono, estado e prazo, e responde com `no-store, private` e `nosniff`. A rota de PDF individual aceita somente DOCX/DANFE e depende do UUID de uma entrada; já `/processings/{uuid}/result` pode entregar uma saída PDF de outra ferramenta, desde que o recurso e o estado sejam adaptados.

O purge roda por hora, remove arquivos/prefixos e mantém registros técnicos. A indisponibilidade lógica começa no `expires_at`; a exclusão física pode ocorrer depois, na execução seguinte, e atrasar se o scheduler falhar. A configuração padrão é 120 minutos para Free/avulso e 1.440 minutos para Pro; são defaults de código, não valores confirmados no servidor. Hoje o prazo é definido ao criar o processamento, antes da espera/geração.

**Fontes:** `app/Services/ProcessingStartService.php`, `ProcessingService.php`, `PdfResultService.php`, `DanfeGenerator.php`, `Documents/*`; `app/Http/Controllers/ProcessingController.php`; `config/feitoo.php`, `documents.php`, `filesystems.php`; `routes/console.php` e `app/Console/Commands/PurgeExpiredProcessings.php`.

### Monetização e concorrência existentes

`EntitlementService.usage` já filtra por ferramenta e data de São Paulo. A unidade é **arquivo**, com limites diários e por processamento. XML para Excel e Produtos usam demonstração de 3 por processamento/6 por dia; DOCX tem configuração própria; outras ferramentas usam a configuração geral. Pro usa limites globais de monetização, com exceção de DOCX. Defaults não substituem a configuração efetiva do ambiente.

`accept` bloqueia Processing e User, consulta compras e reserva `usage_files_count`. O lock do usuário serializa inícios de lotes diferentes. `releaseDispatchFailure` desfaz a reserva quando a publicação do job lança erro. O acerto após o processamento varia por ferramenta: DOCX e algumas ferramentas XML ajustam consumo a sucessos; não se deve generalizar isso para todas as ferramentas fiscais.

`Purchase` possui `processing_id` obrigatório e único. A compra libera **um lote imutável**, registra `consumed_at` e não guarda saldo de usos futuros. `PurchaseService` escolhe o menor pacote pelo número de XMLs; seu gateway descreve expressamente que não gera saldo reutilizável. Pacotes configurados acima do teto técnico são filtrados. Essa compra não equivale a cinco documentos independentes.

Stripe já possui Checkout, idempotency keys, assinatura de webhook, validação de valor/moeda/metadata/ambiente, tabela de eventos com ID único e portal da assinatura. `Subscription.grantsPro` exige `active` e período futuro; não basta existir assinatura. O preço mensal anunciado no código é 2.990 centavos, e o gateway valida o Price remoto antes de criar Checkout. Não foi validada a configuração Stripe real.

`StripeSubscriptionGateway` retorna à conta, não à ferramenta. A listagem de compras assume vínculo com Processing. A tela da conta e os tipos de billing também pressupõem quota diária de arquivos e compra de lote. Será necessário acrescentar uma apresentação específica de documentos/créditos sem substituir os contratos fiscais.

**Fontes:** `config/monetization.php`, `app/Services/EntitlementService.php`, `PurchaseService.php`, `StripeCheckoutGateway.php`, `StripeWebhookService.php`, `SubscriptionService.php`, `StripeSubscriptionGateway.php`, `StripeEnvironment.php`; migrations de entitlements/compras e assinaturas; serviços e página de conta do frontend.

## 2. Componentes e serviços reutilizáveis

| Recurso existente | Uso recomendado | Adaptação necessária |
| --- | --- | --- |
| Shell, tokens e classes de formulário/botão | Identidade Feitoo, navegação, mensagens e foco | Estilos locais do editor; cobrir também `textarea` e `select` nas regras de foco |
| `ToolHero`, `ToolSecurityNote`, `ToolRelated` | Cabeçalho compacto, privacidade e descoberta | Cadastro no catálogo; revisão de textos e retenção |
| `ToolWorkspace`, `ToolStatus` | Estrutura e feedback visual | Inputs opcionais de unidade/textos, mantendo defaults das seis ferramentas |
| Reactive Forms + signals | Formulário tipado e prévia derivada | `FormArray` para itens; store local da feature |
| `AuthService`, interceptor e Sanctum | Login Google/e-mail, cookies, XSRF e restauração | Destino permitido e persistência do rascunho |
| `BillingService` e assinatura atual | Consultar Pro, abrir Checkout/portal de forma validada | Métodos e tipos próprios de crédito, preservando os existentes |
| `Processing` / `ProcessingFile` | Execução, estados, saídas, checksum e histórico | Nova ferramenta e um input JSON estruturado por geração |
| `PdfResultService.store` | Gravar PDF como saída privada | Usar sob finalização transacional e validação de estado; não chamar o empacotador ZIP |
| Ownership e download de resultado | Autorização e streaming | Cobrir novos endpoints e contratos de erro |
| Scheduler/purge e disk privado | Expirar entradas, logo copiado, PDF e temporários | Limpar também rascunhos e seus assets; preservar registros de consumo |
| `SeoService` e scripts de assets | Metadados e prerender | Inclusão da página nas listas e testes de SEO |

Não reutilizar `NfeParser`, DTOs fiscais, validação de protocolo, consolidadores, regras `SafeXml`, `ProcessingFileResult` nem os geradores de Excel para documentos de Negócios. Reutilizar o padrão de idempotência e locks, e não a política de compra fiscal.

## 3. Riscos e incompatibilidades

Prioridade indica dependência para lançar o gerador, não incidente comprovado na produção.

| Prioridade | Evidência/risco | Tratamento proposto |
| --- | --- | --- |
| Alta | `Purchase.processing_id` único e consumo integral do lote | Pedido/créditos próprios; nenhuma conversão dos pacotes XML |
| Alta | Quota atual conta arquivos; o novo Free conta um documento bem-sucedido por dia | Implementar futuramente bucket diário comercial em `America/Sao_Paulo`, independente dos limites fiscais |
| Alta | Dispatcher e upload usam fallback fiscal | Orquestração comercial explícita; bloquear acesso comercial pelo start/upload legado |
| Alta | Somar consumos de Processing não implementa saldo reutilizável | Reservas persistidas e quota/créditos atômicos em tabelas próprias |
| Alta | `afterCommit` atual deixa intervalo entre commit e publicação | Para o novo fluxo, inserir job `database` na mesma transação/conexão MySQL da reserva |
| Alta | Morte do worker, retry ou purge podem competir com conclusão | Idempotência, token de tentativa, prazo de execução e finalização sob lock |
| Alta | Campos livres e logo introduzem XSS, SSRF, leitura local e abuso de imagem | Texto escapado, templates fixos, sem URLs fornecidas pelo usuário, reencodificação e limites |
| Alta | `ProcessingResource` aplica preview/erros fiscais por exclusão | Branch explícito de orçamento; não executar `ProcessingPreviewService` para essa ferramenta |
| Média | Histórico e conta mostram arquivos e compra de lote | Rótulos de documento, saldo e pedido avulso, com contratos aditivos |
| Média | Purge atual remove apenas metadata conhecida | Conteúdo comercial fora da metadata técnica; limpeza explícita do rascunho e cópias |
| Média | IndexedDB atual preserva arquivos por 15 min | Persistência específica do formulário, inclusive logo e retorno de checkout |
| Média | Login, Google, Pro e SEO têm listas/destinos explícitos | Cobrir ida, sucesso, cancelamento, erro e retorno pela conta |
| Média | Polling atual compartilha limite de acesso por IP | Backoff e pausa em aba oculta; planejar orçamento de requests e 429 |
| Média | Componentes globais de ferramentas usam `ViewEncapsulation.None` | Manter namespace e defaults; validar visualmente as seis páginas |
| Média | HTML da prévia e motor PDF podem paginar diferente | Contrato de conteúdo/cálculo comum e homologação dos modelos no motor escolhido |
| Média | Prévia completa no navegador pode ser impressa ou capturada pelo visitante | Monetizar a emissão oficial no backend; não prometer impedir cópias do conteúdo já exibido |
| Média | Compartilhamento depende de navegador, sistema e apps instalados | Detecção de capacidade e fallback de download/anexo manual |

Pontos que continuam **não verificados**: schema e índices efetivamente aplicados no MySQL, isolamento de transações do ambiente, disponibilidade de GD/fontes, recursos do VPS, scheduler e workers ativos, latência/capacidade do renderizador e estado dos produtos/preços Stripe. Documentos operacionais locais são referências; não comprovam a instalação atual.

## 4. Proposta de arquitetura

### Produto e frontend

Rota pública `/gerador-de-orcamentos`, sem `authGuard`. A página é o editor: hero curto, formulário e prévia visíveis imediatamente; FAQ e conteúdo explicativo abaixo. Não criar dashboard de Negócios, clientes cadastráveis, catálogo persistente de produtos, estoque, recebíveis, CRM ou ERP.

Estrutura inicial sugerida:

```text
src/app/features/quotations/
  pages/quotation-page.{ts,html,scss}
  components/quotation-items.*
  components/quotation-preview.*
  components/quotation-template-picker.*
  services/quotation-draft.store.ts
  services/quotation-api.service.ts
  services/quotation-calculator.ts
  models/quotation.ts
```

Usar formulário tipado com grupos de emitente/cliente, `FormArray` de itens e campos de desconto, validade, condições e modelo. Os dados de emitente/cliente pertencem ao documento, sem relacionamento obrigatório com cadastros de terceiros. O identificador do orçamento é texto do usuário, sem promessa de numeração fiscal ou sequência empresarial.

O formulário é a fonte do estado editável; signals/computed recebem valores normalizados para prévia e totais. Evitar duas cópias mutáveis concorrentes. O store coordena autosave, revisão, logo, autenticação e execução; não adotar NgRx nem um framework de construtor de documentos neste MVP.

Desktop: formulário e folha lado a lado. Mobile: formulário em uma coluna e alternância acessível para a prévia, sem uma folha A4 impor largura mínima à página. A folha pode ser reduzida visualmente; os campos continuam legíveis e operáveis. Ao alternar o modelo, preservar todos os dados. Começar com dois modelos fixos, por exemplo Clássico e Moderno, com as mesmas informações e regras.

### Backend e processamento

Criar serviços concretos em `app/Services/Quotations`: cálculo/validação, rascunho, início e finalização; um `QuotationPdfRenderer`; e `GenerateQuotationPdfJob`. Manter os serviços fiscais e DOCX com suas responsabilidades atuais. Apenas pontos compartilhados de enum, recurso, histórico, download e limpeza recebem integração explícita.

Uma geração tem **um documento de entrada estruturado**, não um XML nem um arquivo artificialmente enviado pelo usuário. O serviço cria um snapshot JSON privado e imutável em `processings/{uuid}/input/quotation.json`, registrado como um `ProcessingFile` input. `files_count=1`; quantidade de itens e logo não entram na quota. O PDF é um único output, sem ZIP. Assim os contadores mantêm seu significado técnico de entradas, enquanto a interface comercial apresenta “1 orçamento”.

O logo validado do rascunho é copiado para `processings/{uuid}/assets/logo.png` antes da geração. O snapshot guarda apenas referência interna e checksum dessa cópia. Não usar o input/upload XML para logo nem incluí-lo no contador de documentos. Apagar ou trocar o logo do rascunho não altera a geração já solicitada.

No POST de geração, validar e congelar versão, valores calculados, modelo e fonte; reservar uma unidade; criar Processing e associação da geração; inserir o job na mesma transação. Preparar arquivos privados antes da transação com compensação em falha e varredura de órfãos. Um job recebe somente UUIDs/token de tentativa, nunca conteúdo pessoal no payload da fila.

O job renderiza fora da transação longa. Ao concluir, bloqueia o Processing e os registros de consumo, confirma tentativa vigente, dono, estado e prazo, publica a saída e converte a reserva em consumo na mesma finalização. Falha definitiva libera a reserva uma única vez; tentativas intermediárias mantêm a reserva. Nunca liberar enquanto um worker antigo ainda puder publicar resultado.

Bloquear explicitamente `quotation-generator` nas operações genéricas de criação/upload/start que não entendem esse contrato. A rota genérica de resultado e o histórico continuam disponíveis com ownership; as mutações passam pelos serviços comerciais.

### Reuso futuro com limite

| Futuro produto | Reuso plausível | Parte específica |
| --- | --- | --- |
| Criador de Catálogos | Assets privados, templates, identidade, PDF/download e crédito por ferramenta | Grade de imagens, paginação e volume de assets |
| Propostas Comerciais | Emitente/cliente, itens, condições, cálculo e modelos | Texto longo, escopo e seções de proposta |
| Ordens de Serviço | Partes, itens, logo e documento privado | Campos de execução, equipamento e descrição de serviço |

Manter o primeiro DTO e componentes no domínio de orçamento. Extrair `PartyFields`, `LineItemsEditor` ou um serviço de assets apenas quando um segundo produto exigir o mesmo comportamento. Compartilhar desde o início infraestrutura com necessidade comprovada: autenticação, armazenamento, download, assinatura e contabilidade de unidades por ferramenta. Não criar agora um schema universal de documentos, editor de templates, cadastro de clientes ou serviços vazios para os três produtos futuros.

## 5. Modelagem de dados sugerida

Modelo conceitual para futuras migrations; nenhuma migration foi criada ou executada. Usar FKs internas numéricas e UUIDs externos. Datas técnicas em UTC; validade do orçamento como data civil, sem conversão de fuso.

| Estrutura | Campos e vínculos essenciais | Garantias |
| --- | --- | --- |
| `quotation_drafts` | UUID, `user_id`, `revision`, payload versionado, modelo, referência/checksum do logo, timestamps e `expires_at` | Acesso por proprietário; revisão otimista; payload pessoal criptografado em repouso pela aplicação |
| `quotation_generations` | UUID, `user_id`, `processing_id`, UUID/revisão do rascunho, hash do input, `template_version`, `renderer_version`, `idempotency_key`, hash da requisição, número/token da tentativa | `processing_id` único; `(user_id,idempotency_key)` único; `(draft_uuid,draft_revision)` único para repetir a mesma emissão sem novo consumo |
| `document_credit_orders` | UUID, `user_id`, `tool`, pacote, quantidade, valor em centavos, moeda, status, IDs Stripe, expiração do Checkout, pagamento e fechamento | Sessão e PaymentIntent únicos; preço/quantidade imutáveis, definidos no servidor; não requer Processing |
| `document_credit_grants` | `user_id`, `tool`, `order_id`, concedidos, reservados, consumidos, revogados e timestamps | `order_id` único; saldo não negativo; a soma das parcelas nunca excede o concedido |
| `document_quota_buckets` | `user_id`, `tool`, fonte Free/Pro, chave do período, início/fim, limite, reservados e consumidos | Unique em `(user_id,tool,source,period_key)`; Free usa a data civil em `America/Sao_Paulo`, com limite 1 |
| `document_consumptions` | `processing_id`, fonte, FK de bucket ou grant, quantidade=1, estado `reserved/consumed/released`, tentativa e timestamps | `processing_id` único; exatamente uma fonte; transições condicionais e auditáveis |
| Estruturas atuais | `processings`, `processing_files`, `subscriptions`, `stripe_webhook_events` | Permanecem; a ferramenta nova usa seus próprios registros e políticas |

Não criar uma tabela por item nem tabelas `customers`, `products` ou `companies` neste MVP. Payload do rascunho e snapshot bastam para editar/renderizar; não há necessidade de consulta analítica de linhas de orçamento. Não duplicar o snapshot completo na metadata ou em `ProcessingFileResult`. A associação da geração guarda referências técnicas; conteúdo pessoal fica no rascunho temporário e no input privado.

Índices adicionais: rascunhos por `(user_id,updated_at)` e `expires_at`; reservas por estado/prazo; grants por `(user_id,tool,created_at)`; pedidos por `(user_id,created_at)`; gerações por proprietário e Processing. Campos de payload criptografado precisam de coluna compatível com o tamanho do ciphertext, não de consultas SQL sobre JSON criptografado.

O schema da geração não precisa de um segundo estado de processamento: usar `Processing.status`. Congelar no input os totais calculados, a revisão do contrato e os IDs/versões do template. Um retry usa o mesmo input; uma edição incrementa `revision` e uma emissão posterior passa a ser outro documento. Se a revisão já foi concluída, repetir a geração retorna o resultado existente.

Payload inicial sugerido:

```json
{
  "schema_version": 1,
  "number": "ORC-001",
  "issue_date": "2026-10-20",
  "valid_until": "2026-11-04",
  "currency": "BRL",
  "issuer": { "name": "Empresa Exemplo", "document": "", "email": "", "phone": "", "address": "" },
  "customer": { "name": "Cliente Exemplo", "document": "", "email": "", "phone": "", "address": "" },
  "items": [{ "description": "Serviço de instalação", "quantity": "2.0000", "unit_price_cents": 15000 }],
  "discount": { "type": "percent", "value": "10.00" },
  "conditions": "Pagamento em duas parcelas.",
  "notes": "Atendimento mediante agendamento.",
  "template_id": "classic"
}
```

Dados cadastrais complementares são opcionais. Nome do emitente/cliente e ao menos um item válido são exigidos na emissão, não a cada autosave. Não solicitar documento CPF/CNPJ como condição para usar o editor. Logo é recurso separado, sem base64 no payload JSON de cada autosave. Proibir campos extras de preço de plano, dono, total final, caminho ou HTML.

## 6. Contratos de API propostos

Prefixo `/api`; nomes abaixo são propostas. Separar a linguagem comercial da API de lotes. UUIDs de rascunho/geração/pedido nunca substituem autorização.

| Método e rota | Entrada/retorno principal | Acesso e comportamento |
| --- | --- | --- |
| `GET /tools/quotation-generator/config` | Modelos/versionamento, limites técnicos, política Free/Pro, preço do pacote e disponibilidade | Público; sem saldo nem dados pessoais |
| `GET /quotations/entitlements` | Free restante, período/limite Pro, reservados e créditos disponíveis | Autenticado; `no-store` |
| `POST /quotations/drafts` | Payload parcial → UUID, revisão, totais normalizados e expiração | Autenticado; idempotency key para importação do rascunho local |
| `GET /quotations/drafts/{draft}` | Payload do próprio usuário e revisão | Autenticado; 404 para outro proprietário; 410 se expirado |
| `PATCH /quotations/drafts/{draft}` | Campos editáveis + revisão esperada → nova revisão | Autenticado; 409 se revisão concorrente; sem gerar/consumir |
| `PUT /quotations/drafts/{draft}/logo` | Multipart de um PNG/JPEG + revisão esperada → revisão/referência | Autenticado; validar e normalizar antes de substituir |
| `GET /quotations/drafts/{draft}/logo` | Imagem normalizada | Autenticado, privado; pode ser convertida em blob local para a prévia |
| `DELETE /quotations/drafts/{draft}/logo` | Revisão esperada → nova revisão | Autenticado; cópias de gerações anteriores não mudam |
| `DELETE /quotations/drafts/{draft}` | Exclusão do conteúdo editável e logo | Autenticado; não estorna documentos emitidos |
| `POST /quotations/drafts/{draft}/generations` | Revisão + fonte autorizada + `Idempotency-Key` → Processing e consumo reservado | Autenticado; 202 na aceitação; repetição retorna a emissão existente |
| `POST /quotations/generations/{processing}/retry` | Tentativa esperada e chave idempotente | Autenticado; apenas falha recuperável, snapshot válido e nenhuma tentativa ativa; reserva de novo após liberação |
| `GET /processings/{processing}` | Estado e resultado, sem preview fiscal | Autenticado; recurso adaptado explicitamente |
| `GET /processings/{processing}/result` | `application/pdf` com attachment | Autenticado; download existente, sem novo débito |
| `DELETE /processings/{processing}/files` | Exclusão dos arquivos e conteúdo associado | Autenticado; manutenção do consumo já confirmado |
| `POST /quotations/credit-orders/checkout` | Código permitido do pacote + contexto de retorno → pedido/URL | Autenticado, CSRF e idempotency key; não recebe valor livre |
| `GET /quotations/credit-orders/{order}` | Situação do pedido/concessão | Autenticado; redirect do Stripe não confirma crédito |
| `POST /quotations/credit-orders/{order}/cancel` | Pedido → situação confirmada de fechamento | Autenticado; incerteza do provedor mantém pedido pendente |
| `GET /quotations/credit-orders` | Pedidos paginados do usuário | Autenticado; permite exibir compras de documentos na conta |

As rotas mutáveis precisam de sessão e CSRF, inclusive quando faltarem Origin/Referer, seguindo a proteção explícita das compras em `routes/web.php`. Reutilizar rate limiting com políticas adequadas a autosave e geração. Acrescentar os prefixos comerciais a `PrivateApiResponse` e ao tratamento privado de exceções; as regras atuais não os cobrem automaticamente.

Retorno ilustrativo da geração:

```json
{
  "data": {
    "id": "<processing-uuid>",
    "tool": "quotation-generator",
    "status": "queued",
    "document_revision": 4,
    "consumption": { "source": "credit", "units": 1, "status": "reserved" },
    "result": { "available": false, "file_name": null }
  }
}
```

Erros comerciais devem ter `code` estável e mensagem própria: `authentication_required` (401), `draft_revision_conflict`/`idempotency_conflict` (409), `draft_expired`/`result_expired` (410), `quotation_invalid`/`entitlement_required`/`document_limit_exceeded` (422), `rate_limited` (429) e `renderer_unavailable`/`queue_unavailable` (503). Validação de campo usa o envelope `errors` do Laravel. Reutilizar o código genérico atual produziria mensagens como “envie um XML” ou “gerar Excel” para orçamento; adaptar somente os novos caminhos.

Mesma chave idempotente com corpo/revisão diferente retorna 409. Mesmo pedido de emissão após sucesso retorna o mesmo Processing; não reinicia job. Consulta de estado/download nunca altera saldo. Tentativa de retry precisa de atualização condicional da tentativa vigente para rejeitar dois retries simultâneos.

## 7. Modelo de monetização recomendado

### Regras comerciais propostas

| Oferta | Recomendação | Unidade e período |
| --- | --- | --- |
| Free | 1 orçamento completo, com todos os itens, logo opcional e escolha de modelo | Uma emissão bem-sucedida por dia, por conta/ferramenta, em `America/Sao_Paulo` |
| Avulso | R$ 9,90 por 5 orçamentos | Cinco créditos reutilizáveis da ferramenta, sem depender do lote ou TTL de um PDF |
| Pro | Reutilizar a assinatura Feitoo de R$ 29,90/mês | Proposta inicial: 100 orçamentos por período da assinatura, por ferramenta; homologar custo antes de aprovar o limite |

O Free diário foi decidido pelo usuário na Fase 1. O limite Pro continua como proposta inicial e a validade dos créditos ainda precisa de decisão. Recomenda-se crédito sem expiração comercial no MVP; expiração de arquivo e validade do Checkout continuam separadas. Definir política explícita caso a empresa prefira expirar créditos. Nenhum controle comercial será implementado na prova técnica do PDF.

O controle Free é por conta autenticada, não uma identificação infalível de pessoa. Criar outra conta pode obter outra franquia; as proteções atuais de autenticação e rate limiting ajudam a conter abuso, mas não demonstram unicidade de pessoa. Não usar IP/dispositivo como fonte financeira de consumo.

“Completo” significa PDF com todas as informações aceitas, sem truncamento/amostra e sem degradar o Free. Preenchimento, prévia HTML, troca de modelo, autenticação, autosave e Checkout não consomem. A unidade é **uma emissão de uma revisão**, independentemente do número de páginas/itens. Baixar ou compartilhar novamente o mesmo resultado não consome. Editar e emitir nova revisão consome outra unidade; mostrar isso antes da ação. Após expirar, não prometer recuperação gratuita de conteúdo já apagado.

Para Pro ativo, usar a quota Pro antes de Free/créditos. Free fica disponível quando aplicável. Se a quota Pro acabar, oferecer uso explícito dos créditos avulsos; não debitá-los silenciosamente. Reservas em andamento entram no limite. Limite inicial operacional sugerido: uma geração comercial ativa por conta, configurável e independente das filas fiscais.

Uma política comercial própria (`config/quotations.php` e serviço correspondente) deve expressar `unit=document`, `scope=tool`, Free de 1 sucesso por dia em `America/Sao_Paulo` e quota Pro por período. Não alterar `monetization.free`, `xml_demo`, pacotes `xml_*`, `documents.limits` ou cálculos tributários. A resposta fiscal atual `/account/usage` continua com seus campos diários; o endpoint comercial apresenta o período apropriado.

### Crédito e Stripe

Criar um tipo de pedido comercial separado de `Purchase`. Reutilizar SDK, validação de ambiente, assinatura do webhook, padrões de idempotência e assinatura existente, sem duplicar a assinatura Pro ou alterar o Price Live nesta etapa. O código do pacote sugerido é `quotation_5`, quantidade 5, valor 990 e moeda BRL, definido pelo servidor.

Separar o handler de pedidos de documentos dos pedidos fiscais **antes da deduplicação que encerra o tratamento de um evento**. Adicionar um handler depois do atual pode perder um evento que já tenha sido marcado como processado. O roteamento deve reconhecer o pedido por tipo/metadata, validar seu vínculo e então executar uma única transação de deduplicação, pagamento e concessão.

O novo Checkout não deve depender da existência ou prazo de um Processing. Guardar no pedido um contexto seguro de retorno ao rascunho, sem dados do cliente. Validar valor, moeda, modo, sessão, pedido e quantidade contra a cópia imutável do servidor. Não confiar em parâmetros `payment=success`. Conceder um grant de cinco créditos uma única vez, com unique por pedido além do ID do evento: eventos distintos da mesma compra também não podem conceder duas vezes. A necessidade de fulfillment pelo servidor e de tolerância à concorrência é documentada pelo [Stripe Checkout](https://docs.stripe.com/checkout/fulfillment.md?payment-ui=stripe-hosted).

Persistir a tentativa antes da chamada externa e repetir com a mesma chave, como na integração atual. A chave Stripe é proteção de retry do provedor; as constraints locais continuam necessárias após a janela de retenção da chave. Fonte: [idempotência Stripe](https://docs.stripe.com/api/idempotent_requests).

No MVP, manter os meios de pagamento já homologados; não adicionar Pix ou métodos assíncronos implicitamente. Tratar webhook repetido, atraso, cancelamento, falha e reembolso. Reembolso integral revoga o saldo não consumido do grant e invalida reservas ainda não concluídas, de forma serializada com a finalização do PDF. Créditos já usados permanecem auditáveis; não gerar saldo negativo nem apagar débitos. Reembolso parcial/disputa exige política de revogação aprovada antes da venda e bloqueio conservador do grant afetado enquanto a situação é reconciliada. Não importar a regra fiscal como se resolvesse saldo reutilizável.

### Consumo atômico e execução

1. Congelar e validar a revisão; resolver a chave idempotente. Uma constraint de revisão impede duas emissões simultâneas da mesma versão, mesmo com chaves diferentes.
2. Sob locks de banco, confirmar usuário, plano vigente e fonte escolhida. Para recursos compartilhados, manter a ordem compatível `Processing → User → bucket/grant → consumo`; todas as operações de geração, falha, exclusão, retry e reembolso precisam respeitar a ordem acordada. Não fazer chamadas ao Stripe sob esses locks no novo fluxo.
3. Verificar `consumidos + reservados + 1 <= limite`, ou saldo do grant >= 1; incrementar reservado e criar/atualizar a reserva condicionalmente. Não basta consultar saldo no frontend.
4. Persistir estado `queued` e job **no mesmo MySQL, conexão e transação**. Usar fila `database` comercial com `after_commit=false` e garantir que a conexão da fila seja a mesma da transação. Um worker só enxerga o job após commit. Erro no enqueue reverte estado e reserva.
5. Worker faz claim de tentativa, renderiza fora do lock e finaliza por comparação de token/estado. PDF persistido/validado e resultado disponível convertem `reserved → consumed`; falha terminal faz `reserved → released`. Retry de job não reserva outra unidade.
6. Reconciliador recupera jobs abandonados e reservas antigas, verificando job/lease/token antes de liberar. Não estornar apenas porque passou um tempo: o worker antigo deve estar impedido de publicar.
7. Purge/exclusão de uma geração concluída não devolve Free nem crédito. Expiração antes da conclusão invalida a tentativa e libera a reserva uma única vez.

O passo 4 é específico desta proposta; o fluxo existente usa `afterCommit`. Se futuramente a fila mudar para Redis/SQS ou outro banco, a atomicidade deixa de existir e será necessário outbox transacional e publicador recuperável. Não criar outbox neste MVP enquanto a fila database na mesma conexão resolver a necessidade.

O consumo Free do dia precisa sobreviver à exclusão do PDF, rascunho e histórico de conteúdo. O dia civil é definido em `America/Sao_Paulo`; reservas simultâneas entram no limite, falhas técnicas liberam a reserva e downloads repetidos não consomem. Pro deve usar o período efetivamente confirmado da assinatura, não a virada do dia nem um mês arbitrário de calendário. Reservas guardam o bucket original para acerto após a mudança de dia/renovação; a fase comercial deve fixar a política de conclusão na virada e impedir que uma reserva consuma dois períodos. Não zerar consumo ao cancelar/reativar dentro do mesmo período.

## 8. Estratégia de geração do PDF

### Motor recomendado e critérios

Recomenda-se **HTML de templates Blade fixos + Dompdf**, em job backend, sujeito a uma prova técnica pequena antes da integração comercial. O checkout não declara Dompdf como dependência instalada; sugestões de PDF no lock do PhpSpreadsheet não significam instalação. A implementação futura exigirá selecionar uma versão estável compatível e revisar dependências/licenças. Nenhum pacote foi instalado nesta auditoria.

| Opção | Avaliação |
| --- | --- |
| Dompdf | Adequado aos dois layouts simples e à stack PHP; exige CSS de impressão limitado e testes de paginação |
| Chromium headless | Melhor cobertura de CSS moderno, com maior custo operacional e necessidade de isolamento/binário; alternativa se os modelos não couberem no motor PHP |
| LibreOffice via DOCX | Pipeline existente, mas acrescentaria fabricação de DOCX e dependência de conversão; não é o caminho inicial para um formulário estruturado |
| DANFE / PDF manual do frontend | Não representam o documento comercial e suas necessidades de texto, imagem e múltiplas páginas |

O Dompdf não suporta flex/grid e tem restrições de quebra de linhas de tabela entre páginas. Portanto, não reutilizar o SCSS Angular como CSS do PDF nem aceitar descrições longas supondo paginação automática. A prova deve testar um bloco de item com continuação e condições em múltiplas páginas; se não preservar todo o conteúdo dentro dos limites contratados, rever o layout ou o motor antes de lançar. Estas restrições e os controles de recursos locais/remotos constam no [repositório oficial do Dompdf](https://github.com/dompdf/dompdf).

### Cálculo e fidelidade

API é autoridade de cálculo. Usar preço unitário em centavos e quantidade decimal canônica, com até quatro casas. Calcular cada linha por multiplicação decimal exata, arredondamento HALF_UP para centavos; somar linhas arredondadas; aplicar desconto percentual ao subtotal e arredondar uma vez, ou subtrair desconto fixo em centavos. Total nunca negativo. Percentual entre 0 e 100; desconto fixo não pode exceder subtotal. Sem descontos por item, tributos, frete ou múltiplas moedas neste MVP.

Quantidade deve ser positiva; preço unitário pode ser zero para um item gratuito identificado como tal. Datas devem ser válidas e `valid_until` não pode anteceder `issue_date`. Validar essas regras novamente no servidor antes da reserva, independentemente da validação parcial do rascunho.

Exemplo: `2 × 15000 = 30000` centavos; desconto `10.00% = 3000`; total `27000`. Evitar `number` de ponto flutuante para contabilidade. Backend pode usar `Brick\Math\BigDecimal`, já presente no lock e usado na conta; se passar a ser dependência de domínio, declarar dependência direta compatível na implementação. Frontend pode usar escala inteira/BigInt para a quantidade e os mesmos vetores de teste, convertendo para strings no JSON. Formatação BRL ocorre apenas na apresentação.

Prévia HTML muda localmente em tempo real, sem chamar o renderizador a cada tecla. A API devolve valores normalizados ao salvar/validar e antes de reservar. Browser e Blade usam contrato de dados, modelos e regras equivalentes; não se promete igualdade de paginação só por ambos serem HTML. Após a emissão, o PDF efetivamente gerado é a referência final. Não disponibilizar uma API gratuita de PDF completo como “prévia”, pois contornaria a monetização.

A prévia completa pode ser impressa, capturada ou reproduzida pelo navegador. Não há garantia técnica de impedir isso mantendo essa experiência aberta. A oferta paga deve comunicar o valor da emissão consistente pelo Feitoo, modelos, arquivo pronto e histórico temporário; esconder botões de impressão não é controle de entitlements.

### Segurança, logo e limites iniciais

Aceitar apenas texto simples. Escapar campos no Angular e no Blade; não usar HTML do usuário, CSS livre, templates enviados, `bypassSecurityTrustHtml` ou links arbitrários como recursos. Remover controles inválidos preservando quebras de linha legítimas, impor limites e rejeitar valores fora do contrato. Não corrigir silenciosamente totais inválidos.

Logo: PNG/JPEG, até 2 MiB e 4 milhões de pixels como proposta inicial. Verificar MIME real, assinatura, dimensões e decodificação; reencodar para PNG, remover metadata e redimensionar para no máximo 1.200 px no maior lado. Rejeitar SVG, animações e arquivos não decodificáveis. Fazer a checagem de pixels antes da decodificação integral e limitar memória/tempo. GD ou equivalente precisa ser confirmado no ambiente.

Não buscar logo por URL; não aceitar caminho de arquivo, `file://`, HTML/SVG embutido ou base64 arbitrário. O motor recebe somente o snapshot e a imagem normalizada da tentativa. Desabilitar rede, PHP embutido e JavaScript do renderizador; restringir acesso local ao workspace de assets/fontes aprovados e bloquear escapes por symlink. Templates fixos não dispensam limites do processo.

Envelope inicial proposto para homologação: 100 itens, descrição até 1.000 caracteres, condições e observações até 4.000 cada, corpo JSON até 256 KiB, PDF até 10 MiB e 20 páginas, tempo de renderização até 30 s. Definir também faixas de quantidade, preço unitário e total para impedir overflow. Esses valores são guardas técnicos iniciais, não capacidade medida. Ao exceder, devolver erro acionável; nunca truncar texto ou omitir páginas.

Gerar texto selecionável, fontes locais redistribuíveis com acentos, cabeçalhos de coluna repetidos, totais sem corte e número de página. Não prometer PDF/UA, assinatura digital ou validade fiscal sem trabalho específico. O orçamento comercial não deve apresentar avisos de autorização SEFAZ nem aparência de DANFE.

Workspace privado por geração/tentativa; sem `.env`, arquivos de outros usuários ou rede. Validar saída com assinatura/estrutura/tamanho, aproveitando o padrão de `PdfOutputValidator`, mas com limites/exceções próprios de orçamento. Publicar apenas após sucesso; limpar temporários em `finally` e no purge/reconciliador, inclusive após morte do processo.

## 9. Fluxo de autenticação, checkout e compartilhamento

### Rascunho e autenticação

1. Visitante preenche o editor e vê a prévia sem autenticar. Salvar localmente em IndexedDB payload versionado e blob do logo, com debounce, UUID de rascunho, revisão e prazo. `sessionStorage` guarda somente o apontador/contexto da aba, não todo o documento.
2. Proposta de retenção do rascunho local/remoto: 24 horas após última edição. Exibir prazo e ação “Limpar rascunho”; limpar dados vinculados ao usuário no logout e não restaurar automaticamente o rascunho de outra conta. Storage local não é proteção contra XSS; nunca guardar tokens ou enviar campos pessoais para analytics.
3. Ao clicar em “Gerar PDF”, validar formulário e assegurar que a gravação local terminou antes de navegar para login/Google. Se IndexedDB falhar, manter o editor e informar a limitação de recuperação antes de uma navegação externa; oferecer login por e-mail na aplicação ou exportação local do rascunho.
4. E-mail/Google retornam à rota permitida. Restaurar intenção e dados mesmo após cancelamento, erro de OAuth ou refresh; pedir nova ação de gerar após autenticação, sem emitir automaticamente por causa de um parâmetro da URL.
5. Autenticado, importar o rascunho local uma única vez, com chave idempotente, para o usuário atual. Se já houver revisão remota diferente, oferecer escolha explícita; nunca sobrescrever silenciosamente a versão mais recente. Continuar autosave remoto com controle de revisão.

Distinguir prazo de rascunho, prazo de processamento e validade comercial informada no orçamento. A validade comercial não autoriza guardar o documento pelo mesmo período. Não renovar a retenção apenas por GET, polling ou passagem pelo login.

### Checkout e retorno

1. Consultar entitlements no momento da emissão. Com Free/Pro/crédito elegível, aceitar geração e reservar. Sem saldo, manter o formulário e apresentar pacote/Pro com preço e unidade claros.
2. Salvar rascunho remoto e local antes do redirecionamento. O pedido de crédito tem vida própria; seu TTL de Checkout não extingue os créditos pagos nem depende de um PDF.
3. Voltar a `/gerador-de-orcamentos` com identificadores opacos de contexto. Consultar pedido ou assinatura; aguardar confirmação do servidor com tentativas limitadas e opção de consultar novamente. Pagamento confirmado concede saldo, mas não dispara geração no webhook.
4. Cancelamento/erro mantém dados e permite retomar. Se o pagamento chegar depois da expiração do rascunho, o saldo continua disponível para outro documento. Não vender a promessa de preservar um rascunho indefinidamente.
5. Para Pro, acrescentar contexto de retorno validado ao fluxo existente ou retomar pela conta. Manter o retorno padrão atual para os demais consumidores. Usuário Pro existente não deve receber Checkout de outra assinatura.

Limitar destinos por allowlist; não aceitar URL externa ou query arbitrária. O contexto não contém nome de cliente, CPF/CNPJ, condições ou preços dos itens. `BillingService.redirect` já valida o host do Checkout; manter essa proteção. Polling comercial deve pausar em aba oculta, usar backoff e respeitar `Retry-After`, sem competir desnecessariamente com o histórico e a assinatura.

### Download e WhatsApp

Baixar o PDF autenticado como Blob e preparar um `File` com MIME `application/pdf`. Quando `navigator.canShare({files:[file]})` permitir, mostrar botão de compartilhamento e chamar `navigator.share` a partir do clique. Preparar o arquivo antes de habilitar a ação evita perder a ativação do usuário durante um download demorado. O usuário escolhe WhatsApp entre os destinos disponíveis; a aplicação não pode garantir que ele esteja instalado nem selecionar o destino por conta própria. HTTPS, suporte do dispositivo e ativação pelo usuário são requisitos da [Web Share API](https://developer.mozilla.org/en-US/docs/Web/API/Navigator/share).

Sem compartilhamento de arquivo, oferecer “Baixar PDF” e instrução curta para anexar no WhatsApp. Um link de conversa/texto não anexa automaticamente o Blob. Não criar URL pública de download, enviar conteúdo pela API do WhatsApp ou compartilhar a rota privada do Processing. Cancelar a folha de compartilhamento não é falha de geração e não consome novamente. Liberar object URLs e blobs ao trocar/excluir/expirar o documento; não contornar expiração com cópia local invisível ao usuário.

### Responsividade, acessibilidade e SEO

Formulário com labels, `fieldset/legend`, erros associados por `aria-describedby`, `aria-invalid` e foco no primeiro campo inválido. Itens têm botões de adicionar/remover operáveis pelo teclado, identificação da linha e foco previsível após remoção; não depender de drag-and-drop. Escolha de modelos usa rádios nativos. Totais/status têm anúncio moderado, sem reler a folha inteira a cada tecla. Contraste, zoom e teclado devem ser verificados, não inferidos dos tokens existentes.

Registrar a nova página em `app.routes.ts`, `app.routes.server.ts`, `core/public-pages.ts`, reconhecimento de ferramenta em `SeoService`, FAQs, catálogo `HOME_TOOLS`, `scripts/generate-public-assets.mjs` e regras de canonicalização em `vercel.json`. Conferir também listas/asserts dos testes públicos e links da home/planos. Sem prerender e arquivo estático correspondente, o fallback atual da Vercel pode responder 404; sem registro no SEO, `safePage` trata a rota como desconhecida.

HTML inicial deve conter H1, descrição, editor vazio seguro e conteúdo útil; não depender de login ou fetch privado no build. Carregar storage, File APIs e sessão somente no navegador. Canonical aponta para a rota limpa, sem draft/order/payment; metadados/OG/FAQ/schema descrevem apenas funcionalidades e preços reais. Não incluir prévia com dados pessoais no HTML público, sitemap, JSON-LD ou analytics. Recuperações por UUID são privadas e não criam páginas indexáveis próprias. A rota pública continua sendo o produto; não duplicar uma landing page de entrada.

## 10. Plano de implementação em etapas pequenas

Cada etapa deve ser revisável e manter a ferramenta desabilitada para publicação até concluir as dependências. Esta sequência é apenas planejamento.

| Etapa | Entrega limitada | Critério de conclusão |
| --- | --- | --- |
| 1 | Fechar contrato de documento, cálculo, Free diário, limite Pro, créditos e retenção | Free de 1 sucesso por dia em `America/Sao_Paulo` registrado; exemplos de arredondamento aprovados |
| 2 | Prova de PDF com fixtures sintéticas, dois modelos e logo normalizado | Todo texto preservado; multipágina, fontes, custo e limites medidos no ambiente representativo |
| 3 | Editor público local com Reactive Forms, itens, cálculo e prévia | Fluxo completo de edição em desktop/mobile e teclado; sem chamar fiscal/Stripe |
| 4 | Persistência local e retorno de login | Rascunho/logo sobrevivem a refresh, e-mail e Google, inclusive falhas de retorno |
| 5 | Migrations aditivas revisadas, rascunho remoto e API de assets | Ownership, revisão concorrente, expiração e limpeza verificados em banco isolado |
| 6 | Quota/consumo comercial e geração Free na fila transacional | Uma emissão completa, retries e falhas sem débito duplicado; nenhuma mudança nas quotas fiscais |
| 7 | Reconhecimento do Pro existente e consumo por período | Limite por ferramenta, renovação/cancelamento e preservação dos créditos testados |
| 8 | Pedido avulso e grant de cinco créditos em Stripe TEST | Pagamento/webhook/reembolso/idempotência funcionam sem Processing vinculado ao pedido |
| 9 | Histórico/conta, download, compartilhamento e exclusão | Usuário retoma resultado, vê saldo e compartilha quando o dispositivo permite |
| 10 | SEO, catálogo Negócios e regressão das seis ferramentas | Prerender/404/canonicals corretos, fluxos existentes sem regressão |
| 11 | Homologação e plano operacional de liberação | Métricas, capacidade, rollback, configuração e mudanças de produção revisados para autorização separada |

Não aproveitar a implementação para refatorar toda a página XML, migrar pacotes de arquivos para créditos ou criar os outros produtos de Negócios. Ajustes compartilhados devem ter defaults compatíveis e escopo demonstrável.

## 11. Estratégia de testes

Nenhum teste foi executado nesta etapa. A proposta abaixo exige ambientes isolados; os testes existentes e scripts de navegador foram inspecionados como inventário. Não executar a suíte com conexão ao banco operacional.

| Área | Casos necessários e evidência esperada |
| --- | --- |
| Cálculo | Quantidade fracionária, HALF_UP, desconto fixo/percentual, zero permitido, excesso/negativo/overflow e conversão de entrada pt-BR; mesmos vetores em PHP/TS |
| Formulário | Adicionar/remover/editar linhas, modelos preservam dados, autosave parcial, validação final e revisão conflitante |
| Rascunho | Refresh, expiração, storage indisponível, quota do navegador, logo Blob, duas abas, conta trocada, logout, importação idempotente e versão de schema desconhecida |
| Autenticação | E-mail e Google; retorno permitido; erro/cancelamento de OAuth e sessão 401/419 preservam dados; URL externa é recusada |
| Autorização | Outro usuário não lê/altera draft, logo, geração, pedido, resultado ou histórico; UUID válido alheio responde 404 |
| Segurança | HTML/script como texto, URLs/file paths rejeitados, MIME disfarçado, SVG, imagem enorme, arquivo corrompido, symlink e recurso remoto não carregado |
| PDF | Fixtures de 1 e várias páginas, limite de itens, descrição/condições longas, acentos, nomes sem espaços, logo transparente, cabeçalhos e totais; inspeção visual + extração de texto e integridade |
| Free | Dois pedidos simultâneos com saldo 1: no máximo uma reserva válida; falha técnica devolve tentativa, sucesso não retorna por download/exclusão/purge |
| Créditos | Grant 5; cinco sucessos esgotam; sexta emissão é negada; reserva inclui jobs pendentes; repetição de chave/revisão não duplica; chave com outro corpo retorna 409 |
| Pro | Assinatura ativa/vencida/past_due, período real, renovação, cancelamento agendado, reserva atravessando renovação e limite independente de XML/DOCX |
| Stripe TEST | Webhook assinado, valor/moeda/ambiente errados, evento duplicado e eventos distintos da mesma sessão, retorno antes do webhook, timeout de criação, pagamento tardio, cancelamento e reembolso concorrendo com geração |
| Falhas/filas | Enqueue rollback, job duplicado, timeout, processo morto, disco cheio, erro após gravar arquivo antes de commit, retomada e worker antigo tentando finalizar após lease invalidado |
| Retenção | Logo/payload/snapshot/PDF/temporários apagados no prazo operacional; histórico técnico e consumo persistem; purge concorrente com render/download não publica arquivo expirado |
| Dispositivo/a11y | 320, 375/390, 768, 1024 e 1440 px; teclado, leitor de tela, zoom 200%, mensagens/foco, download e cancelamento de Web Share; iOS/Android reais e desktop com fallback |
| SEO | HTML sem JS com H1/editor seguro, title/description/canonical/OG/schema/sitemap, query sem PII, variantes com 308 e rota inexistente com 404; nenhuma requisição privada no prerender |

Concorrência deve ser validada em **MySQL com processos/conexões independentes**, não apenas em SQLite ou com um mock de fila. Usar banco exclusivo descartável e fixture sintética; aplicar as migrations só nesse ambiente após autorização da implementação. O script atual `feitoo-api/scripts/check-usage-concurrency.php` é referência para o mecanismo de locks, não prova de crédito comercial. Medir chamadas simultâneas para Free, Pro e último crédito, inclusive deadlocks com retry transacional.

Regressão obrigatória: suítes `ProcessingTest`, `MonetizationTest`, `SubscriptionTest`, `AccountTest`, testes das cinco ferramentas XML e DOCX; no frontend, testes de auth/billing/processing/conta, assets públicos e smokes existentes. Verificar valores de limites e pacotes, preservação de seleção, upload, filas, resultados parciais, Excel/ZIP/DANFE/PDF, exclusão e avisos fiscais. Aprovar só quando a integração comercial não mudar esses contratos.

## 12. Impactos no deploy e infraestrutura

### Mudanças futuras esperadas

- Migrations **aditivas** para rascunhos, associação de geração, pedidos, grants, buckets e consumo. Não editar migrations antigas nem transformar compras fiscais. Revisar índices/DDL em MySQL e planejar backup e compatibilidade entre versões antes da execução.
- Composer: adicionar o motor PDF escolhido e dependências diretas usadas pelo domínio. Não introduzir Node/Chromium no servidor se a prova com motor PHP atender aos modelos.
- Validar extensões DOM, mbstring, fileinfo, GD e fontes; workspace privado e limites de PHP/worker. Confirmar bibliotecas e patches no ambiente real em etapa autorizada.
- Fila proposta `business-documents`, inicialmente com um worker dedicado e concorrência 1, para não atrasar `default` fiscal nem `documents` de DOCX. Usar o mesmo banco/conexão transacional da aplicação para enqueue atômico. Só configurar worker/VPS depois de autorização operacional específica.
- Envelope inicial a medir: render até 30 s, job até 45 s, lease maior que o tempo máximo e `retry_after` com margem superior à lease, além de timeout do Supervisor coerente. Valores definitivos dependem do benchmark; não copiar o envelope de LibreOffice automaticamente.
- Scheduler deve limpar rascunhos/assets e reconciliar jobs/reservas comerciais. O purge de Processing precisa abranger o prefixo comercial, limpar snapshot/logo e preservar consumo diário/saldo. Varredura de staging/órfãos não deve depender exclusivamente de haver um registro committed.
- Frontend continua como build estático/prerender na Vercel. Acrescentar a rota e normalizações; validar headers e capacidades de blob/imagem sem afrouxar a segurança global. Não é necessário SSR permanente ou painel separado.
- Configurações de limites e disponibilidade por ferramenta devem ficar no servidor, com valores públicos seguros na API. Uma flag de liberação deve bloquear novas emissões/Checkout quando desativada e permitir acesso autorizado a resultados e saldos já adquiridos.

### Capacidade, observabilidade e privacidade

Medir CPU/RSS, duração p50/p95, tamanho dos PDFs, fila máxima e impacto no disco/MySQL com o pior documento permitido. Incluir cópias de logo, snapshot, staging e falhas no cálculo de espaço. VPS compartilhado e fila database não exigem Redis para este MVP; a necessidade só deve ser afirmada após medição.

Registrar métricas de geração aceita/concluída/falha, tempo de fila, reservas antigas, divergência de saldo, webhook pendente e limpeza atrasada. Logs contêm UUID técnico, estado e classe de erro; não payload, CPF/CNPJ, telefone, descrições, render HTML, caminho sensível ou saída do processo. O tratamento atual de exceções e `dontFlash` precisa contemplar campos comerciais. Não transportar informações pessoais para Stripe metadata ou analytics.

O prazo de retenção deve ser mostrado a partir do `expires_at` do recurso. Recomenda-se dar ao resultado comercial uma janela completa após conclusão (por exemplo, os defaults atuais de 2 h Free/avulso e 24 h Pro), com prazo separado e limitado para execução pendente. Essa regra pode ser específica do gerador; não alterar o início do TTL dos lotes fiscais. A cópia do input/asset acompanha a expiração do resultado; rascunho tem seu próprio prazo. Considerar backups, arquivos de fila falhada e contingência na política de retenção antes de anunciar exclusão física exata.

Histórico deve mostrar ferramenta, data, status e disponibilidade do PDF, sem reter silenciosamente todo o cliente/orçamento após expirar o conteúdo. O consumo diário e os registros financeiros mínimos permanecem separados do payload. A exclusão do rascunho não apaga PDFs emitidos; a interface deve permitir excluir também esses arquivos e explicar seus prazos.

### Liberação e rollback

Sequência futura: aprovar contrato e prova do motor; validar migrations em ambiente isolado; homologar fluxos com Stripe TEST; publicar backend compatível com flag desligada; preparar worker/scheduler; validar frontend e regressões; só então solicitar a liberação operacional/comercial. Produtos/preços Live não são criados por este plano nem por esta auditoria.

Rollback operacional desabilita novas gerações/Checkout, preserva pedidos pagos, saldo e downloads vigentes, e drena ou cancela jobs com liberação condicional de reservas. Não reverter tabelas financeiras com pedidos reais nem apagar consumos para “limpar” o rollout. Nenhuma dessas ações foi executada.

**Decisões para a autorização da fase comercial:** o Free diário já está decidido; confirmar limite inicial Pro por período, validade dos créditos e prazos de rascunho/resultado. A Fase 1 autoriza apenas o motor PDF, seus dois modelos fixos e validação local, sem controle de consumo ou integração às ferramentas atuais.
