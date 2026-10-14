# Conta do usuário — Etapa 11

## Rotas

`/conta` é uma rota Angular carregada sob demanda e renderizada no cliente.
O guard autenticado segue o padrão do `guestGuard` existente: aguarda restauração
da sessão quando necessário e redireciona visitantes para
`/login?returnTo=%2Fconta`. Login e cadastro por e-mail preservam esse destino.
O host estático precisa servir `index.csr.html` para `/conta`, assim como para
login/cadastro; a rota não é prerenderizada e recebe `noindex`.

O header autenticado apresenta a inicial do usuário e um menu pequeno com
Minha conta e Sair. A atualização do nome sincroniza imediatamente o estado de
autenticação e a saudação do header. Sair encerra a sessão existente e direciona
para login. Não há sidebar, métricas comerciais, planos ou painel administrativo.

## Endpoints

| Método | Caminho | Contrato |
| --- | --- | --- |
| GET | `/api/me` | Resource existente com `id`, `name`, `email`, `google_linked`, `has_password`, `created_at` |
| PATCH | `/api/account/profile` | `{ name }` → `UserResource` atualizado |
| PATCH | `/api/account/password` | `{ current_password, password, password_confirmation }` → confirmação |
| GET | `/api/processings?page=1` | Collection paginada de histórico, 10 registros por página |
| GET | `/api/processings/{uuid}/result` | Download privado existente |
| DELETE | `/api/processings/{uuid}/files` | Exclusão existente de arquivos e resumos |
| POST | `/api/auth/google/start` | Fluxo existente com `intent` e `return_to` opcional |
| POST | `/api/auth/logout` | Logout existente |

Não há endpoints com ID de usuário para edição de perfil. O usuário vem sempre
da sessão. Escritas da conta estão no grupo web, para exigir sessão e CSRF mesmo
sem Origin/Referer. O frontend consulta o cookie CSRF antes dos PATCHs; o
interceptor existente envia credenciais e o token apenas à API configurada.
Respostas privadas usam `Cache-Control: no-store, private`. Falhas inesperadas
dos endpoints da conta também passam pela sanitização de erros existente.

## Perfil

Nome obrigatório, até 120 caracteres, com espaços externos removidos. O backend
rejeita marcação HTML e caracteres de controle. Apenas o nome validado é salvo;
IDs, e-mail, hash de senha e vínculo Google fornecidos pelo cliente são ignorados.
E-mail permanece somente leitura. Data de criação e formas de acesso são
informações complementares. O resource usa lista explícita de campos e não
expõe password hash, remember token, google_id ou tokens OAuth.

## Senha

Somente contas com `has_password: true` exibem o formulário. A senha atual é
obrigatória e verificada contra o hash atual sob lock de banco, sem confiar no
estado do frontend. A nova senha reutiliza as regras do cadastro: pelo menos
12 caracteres, até 72 caracteres e 72 bytes, com confirmação. O cast existente
faz o hash; nenhuma senha é persistida em texto ou enviada à analítica.

Ao concluir, a sessão atual é renovada e o remember token é substituído.
Os campos de senha são limpos na interface. Outras sessões já abertas continuam
válidas até logout/expiração: não foi introduzido gerenciamento de dispositivos
nem política de encerramento de todas as sessões nesta etapa.
Contas somente Google não podem criar senha local pelo endpoint, pois não
possuem senha atual verificável. A tela informa que o acesso/senha é gerenciado
pela conta Google.

## Google

Mostra Google conectado/não conectado. A vinculação reutiliza Socialite, PKCE,
state de sessão e a verificação server-side do e-mail pelo Google. A conta Google
deve usar o mesmo e-mail da conta Feitoo; conflitos continuam bloqueados.

Vinculação retorna para `/conta`, inclusive no erro. Login pode solicitar
`return_to` restrito a `/`, `/conta` e `/xml-para-excel`; URLs externas são
rejeitadas e o callback também aplica a lista permitida. Não há desvinculação.
A verificação com o provedor real depende das credenciais OAuth e não foi
executada no smoke test; testes backend usam respostas controladas do Google.

## Histórico e paginação

A consulta parte de `request.user().processings()`, ordena por `created_at DESC`
e `id DESC` para desempate, e pagina no banco em 10 registros fixos. Parâmetros
de usuário/tamanho de página não alteram o escopo ou limite. `page` é validado
como inteiro positivo até 1.000.000. A resposta inclui `data`, `links` e `meta`
de paginação nativos do Laravel. O frontend usa somente os números em `meta`,
sem seguir URLs recebidas para outros destinos.

Cada item inclui UUID, ferramenta, status, criação, expiração, contadores de
arquivos/sucessos/falhas, `files_deleted`, `can_delete_files` e um `result`
reduzido a disponibilidade/nome de arquivo. A consulta carrega somente os
metadados necessários dos outputs e um EXISTS para arquivos não removidos.
Não consulta `processing_file_results`, não lê XML ou XLSX e não inclui preview.

Status são traduzidos na interface: Aguardando envio, Enviando, Aguardando,
Processando, Concluído, Falhou e Resultado expirado. Falhas parciais preservam
download. O endpoint apresenta expiração efetiva mesmo antes de o purge rodar.
A interface reavalia o TTL local a cada 15 segundos e imediatamente ao clicar
em download; o backend sempre é a autoridade final.

Sem registros, a tela oferece Converter XML para Excel. Atualizar histórico é
uma ação manual; não há polling de todos os processamentos nem filtros complexos.

## Download e exclusão

Download reutiliza o endpoint autenticado existente com blob e filename; não há
URL pública. O botão aparece somente para resultado disponível e não expirado
nem excluído. UUIDs de outros usuários continuam bloqueados pelos controles
existentes, cobertos por testes de isolamento.

Excluir arquivos abre uma confirmação inline específica do processamento.
Não usa `window.confirm`. O foco vai para Confirmar exclusão e retorna ao botão
ao cancelar; após sucesso, vai para o título do histórico. O backend remove XMLs,
Excel e resumos, preservando o Processing e contadores técnicos. A linha permanece
com Arquivos excluídos, sem download ou nova exclusão.

`files_deleted` é derivado de um lote com arquivos registrados sem arquivos
ativos restantes; não distingue exclusão manual de remoção posterior pelo purge.
Um lote cujo TTL acabou, mas cujos arquivos ainda aguardam limpeza, mostra
Resultado expirado e permite exclusão antecipada dos arquivos existentes.

## Responsividade, acessibilidade e performance

Duas colunas no topo desktop, uma coluna abaixo de 768px. Histórico usa lista
responsiva, sem tabela comprimida no mobile. Labels, erros associados, feedback
com status/alerta, foco visível e controles reais seguem a identidade existente.
Loading desabilita ações repetidas. Privacidade, Segurança e Termos são links
curtos ao fim da página. A feature é lazy-loaded, sem bibliotecas adicionais.

## Validação

- `php artisan test`: 187 testes aprovados após a implementação backend.
- `vendor\bin\pint --test` e `php artisan route:list --path=api`.
- `npm.cmd test -- --watch=false`: 84 testes aprovados; `npm.cmd run build` aprovado.
- Prettier nos arquivos Angular e no script de validação alterados.
- `node scripts/check-tool-browser.mjs`: Edge headless com API/sessão e
  arquivos sintéticos, cobrindo login, conta via header, perfil, senha incorreta
  e correta, paginação, falha parcial, expirado, download, confirmação/cancelamento
  e exclusão, histórico preservado/vazio, somente Google, logout e retorno para
  conta após login com a nova senha.
- Viewports 320, 375, 768, 1024 e 1440px sem overflow. Relatório e capturas em
  `feitoo-web/dist/validation/account-browser-report.json` e `account-*.png`.

## Limitações

Sem mudança de e-mail, recuperação/criação de senha para Google, desvinculação,
exclusão da conta, remoção de histórico, filtros avançados, planos ou pagamentos.
Nenhuma migration foi necessária. Não foi realizado deploy nem envio de e-mails.
