# Carteira universal — proposta para validação

Estado: NÃO APROVADA / NÃO IMPLEMENTADA. Nenhuma migration executável, concessão, conversão de saldo ou configuração Stripe universal foi criada. Aprovar este documento e a planilha de custos antes de implementar. Arquitetura financeira não fica implicitamente aprovada pela revisão visual.

## 1. Direitos existentes

| Direito           | Unidade e regra atual                                                           | Compatibilidade                                   |
| ----------------- | ------------------------------------------------------------------------------- | ------------------------------------------------- |
| Free XML/DOCX     | Arquivos/dia/ferramenta, limites por processamento e timezone São Paulo         | Franquia, não dinheiro ou saldo universal         |
| Pro XML/DOCX      | Limites diários por ferramenta e retenção conforme plano                        | Benefício da assinatura, sem conversão automática |
| Free orçamento    | Uma emissão concluída por dia, reserva/liberação existentes                     | Franquia específica                               |
| Pro orçamento     | 100 emissões por período válido de assinatura, bucket mantém período original   | Não juntar ao limite diário XML                   |
| Compra XML        | Pagamento de lote específico, quantidade/capacidade e processamento vinculados  | Sem crédito residual transferível                 |
| Crédito orçamento | Grant pago exclusivo, sem expiração comercial; reserva antes e consumo após PDF | Saldo legado separado, respeitado integralmente   |
| Universal futuro  | Unidade comercial própria, custo versionado por ferramenta                      | Só novas concessões e compras aprovadas           |

Não fazer backfill convertendo `files_limit`, franquias ou counters de grants. Exibir cada direito com nome, unidade, validade e escopo. Inicialmente não permitir combinação de moedas numa execução. Usar fonte explícita para crédito pago; nunca mudar silenciosamente de benefício para crédito universal.

## 2. Modelo aditivo proposto

Tipos abaixo são especificação de migrations futuras, não instruções para executar DDL. IDs internos bigint, IDs públicos UUID. Todos os valores de unidades são inteiros; preços monetários em centavos bigint e moeda explícita. FKs financeiras RESTRICT e retenção auditável; anonimização de PII exige procedimento próprio, sem apagar ledger.

Deltas do ledger usam BIGINT com sinal; projeções e contadores têm limites não negativos. Definir teto comercial dentro dos inteiros seguros do frontend ou serializar unidades como strings decimais. O navegador não calcula autorização de débito.

| Tabela nova                      | Campos principais                                                                                                                                                                     | Restrições/índices                                                                                                              |
| -------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| `wallet_accounts`                | id, uuid, user_id, currency_code=`feitoo_credit`, status, available_units, reserved_units, version, timestamps                                                                        | unique(user_id,currency_code), CHECK disponíveis/reservadas >=0; projeção recalculável, não fonte histórica                     |
| `wallet_cost_versions`           | id, tool, unit, unit_cost, min_units, max_units, effective_from, effective_until, status, approval_ref                                                                                | custo positivo; versões publicadas imutáveis; aplicação garante ausência de janelas conflitantes por ferramenta/unidade         |
| `wallet_orders`                  | id, uuid, account_id, package_version, units, amount_cents, currency, stripe_mode, price_id, session_id, intent_id, status, refunded_cents, idempotency_key, request_hash, timestamps | unique(account_id,idempotency_key), session/intent únicos no modo; valores/preço copiados do catálogo aprovado no servidor      |
| `wallet_grants`                  | id, account_id, order_id?, source, original_units, remaining_units, reserved_units, revoked_units, expires_at?, blocked_at?, external_ref                                             | CHECK counters não negativos, soma <=original; unique(source,external_ref); compra não expira por padrão                        |
| `wallet_reservations`            | id, uuid, account_id, processing_id, cost_version_id, unit_count, reserved_cost, status, expires_at, fence, request_hash, idempotency_key, timestamps                                 | unique(account_id,idempotency_key), unique(processing_id); estado reservado/consumido/liberado, quantidade e custo congelados   |
| `wallet_reservation_allocations` | reservation_id, grant_id, units, consumed_units, released_units                                                                                                                       | unique(reservation_id,grant_id); grants e reserva pertencem à mesma conta; soma alocada=custo                                   |
| `wallet_entries`                 | id, uuid, account_id, grant_id?, reservation_id?, order_id?, operation, delta_available, delta_reserved, related_entry_id?, reason, actor_type/id?, idempotency_ref, created_at       | unique(account_id,idempotency_ref), index(account_id,id); sem update/delete na aplicação; correções com entradas compensatórias |
| `wallet_provider_events`         | id, provider, mode, event_id, type, object_id, payload_hash, received_at, processed_at?, result                                                                                       | unique(provider,mode,event_id); deduplicação só confirmada com a transação financeira; não armazenar dados de cartão            |
| `wallet_outbox`                  | id, aggregate_id, event_type, payload mínimo, published_at, attempts                                                                                                                  | entrega at-least-once com consumidor idempotente; jobs só publicados após commit                                                |

Validações multirow/multiaccount não podem ser substituídas por CHECK simples: transação, ownership, ordem de locks e testes são obrigatórios. Reconciliador compara projeção com soma dos deltas, allocations com reservas e grants com pedidos. Divergência bloqueia gasto, gera diagnóstico técnico e não “corrige” criando saldo.

Em grants, `remaining_units` significa unidades disponíveis. A diferença entre original e disponível/reservado/revogado corresponde ao consumo líquido, derivado do ledger e das allocations. Expiração deve registrar motivo distinto no ledger mesmo quando agrega ao contador de unidades retiradas. Um estorno de consumo atualiza a projeção e a allocation pela mesma transação, preservando entradas originais.

## 3. Transições auditáveis

| Operação           | Delta disponível / reservado | Regra                                                                                                                                                         |
| ------------------ | ---------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Conceder N         | +N / 0                       | Origem e referência únicas; pedido pago validado ou concessão administrativa autorizada                                                                       |
| Reservar N         | −N / +N                      | Verificar conta ativa, custos, fontes, grants desbloqueados e available>=N no lock                                                                            |
| Consumir N         | 0 / −N                       | Execução vencedora com fence atual, PDF/resultado validado; uma única vez                                                                                     |
| Liberar N          | +N / −N                      | Falha/cancelamento antes do consumo; se grant bloqueado/expirado, liberação seguida de revogação/expiração na mesma transação, sem saldo gastável transitório |
| Estornar consumo N | +N / 0                       | Entrada compensatória referenciando consumo; não é reembolso Stripe. Proposta: mesma moeda, sem restaurar franquia de período encerrado                       |
| Expirar N          | −N / 0                       | Só grant com prazo explicitamente contratado; reserva válida continua vinculada ao grant até decisão de terminalidade                                         |
| Revogar N          | −N / 0                       | Saldo não reservado afetado por refund/disputa; reservado é cercado por fence e tratado antes de publicar resultado                                           |

Reserva não é consumo; apagar arquivo não é estornar. Retry reaproveita a reserva e a versão de custo, sem cobrar duas vezes. Não reutilizar uma reserva liberada para outra execução: novo comando idempotente com referência à tentativa anterior.

## 4. Concorrência, filas e isolamento

Ordem futura comum para caminhos que usam carteira: User → WalletAccount → Processing/Generation → Reservation → Order/Grant em ID crescente → Entries/Outbox. Os caminhos XML atuais começam em Processing; adaptar e provar ausência de inversão antes de integrar. Não chamar HTTP Stripe, renderer ou storage dentro de locks financeiros.

Uma transação com lock da conta decide disponibilidade, aloca grants e grava reserva/ledger/projeção/outbox. Repetição com mesma chave e mesmo hash retorna a operação; mesma chave e conteúdo distinto retorna 409. Saldo insuficiente retorna 422 sem reserva, ledger parcial ou job. User e account vêm exclusivamente da sessão. Toda consulta de pedido/reserva/extrato aplica account_id; UUID não é autorização.

Consumir resultado e processar disputa concorrem sob o mesmo lock. Fence monotônico impede worker vencido de publicar depois de cancelamento, lease expirada ou revogação. Reconciliação de reserva pendente verifica lease e estado do job; TTL sozinho não libera uma execução ainda capaz de publicar. Webhook e worker são idempotentes, com retry de deadlock limitado.

## 5. Pagamentos, refunds e chargebacks

Conservar verificação de assinatura do webhook e `StripeEnvironment`. O backend valida modo, moeda, valor, Price, metadata e ownership; frontend só consulta status. Sucesso na URL nunca concede saldo. Modo e credenciais incompatíveis fecham a compra; não habilitar gateway Test de orçamento em Live.

Proposta comercial para APROVAR: refund parcial revoga proporcionalmente unidades não consumidas, arredondamento conservador e registro do cálculo; se não houver cobertura, abrir recuperação financeira separada e bloquear novo gasto da conta, mantendo projeção de créditos >=0. Nunca apagar consumo histórico ou criar crédito negativo. Outra política possível é bloquear todo pacote, como orçamento legado; escolher expressamente antes da implementação.

Disputa criada bloqueia o grant e novos consumos dele; reservas em andamento não publicam sem revalidar fence. Disputa ganha pode restaurar apenas o bloqueio de disputa, sem desfazer refund/expiração/consumo; disputa perdida confirma revogação. Evento atrasado não reativa pedido reembolsado. Reembolso antes de confirmação gera marcador terminal para impedir concessão posterior. Considerar confirmação assíncrona Stripe apenas se formas de pagamento futuras forem habilitadas e cobertas.

Cancelamento Pro encerra benefícios segundo período/estado já contratado, sem afetar créditos universais pagos. Não definir expiração de compras universais sem regra comercial aprovada.

## 6. Custos por ferramenta: proposta de medição

Sem coeficientes ou preços definitivos nesta entrega. Instrumentar tempo CPU, pico RAM × duração, fila, I/O, bytes retidos × TTL, armazenamento/egress de download, falhas/retries e taxa Stripe efetiva. Usar métricas p50/p95/p99 de lotes representativos, capacidade da VPS e margem-alvo aprovada. Não inferir custo só pelo preço legado de pacote.

| Ferramenta            | Unidade proposta a validar       | Medição e limite                                                                                       |
| --------------------- | -------------------------------- | ------------------------------------------------------------------------------------------------------ |
| XML para Excel        | XML válido processado            | parsing + linhas/itens + geração workbook; teto de itens; coeficiente `c_xml_excel` pendente           |
| Produtos dos XMLs     | XML válido processado            | consolidação + quantidade de itens + saída Excel/PDF; `c_products` pendente                            |
| XML para PDF          | DANFE concluído                  | páginas, renderer, bytes e tempo; `c_danfe` pendente                                                   |
| Organizar XML         | XML válido incluído no resultado | ZIP + relatório + I/O; `c_organizer` pendente                                                          |
| Analisar XML          | XML válido analisado             | agregação + filtros/exportações e retenção; decidir reexports sem nova cobrança; `c_analyzer` pendente |
| DOCX para PDF         | Documento concluído              | LibreOffice por páginas, RAM e timeout; tamanho máximo e páginas; `c_docx` pendente                    |
| Gerador de Orçamentos | PDF concluído                    | sandbox + páginas/logo + retenção/download; `c_quotation` pendente                                     |

Fórmula comercial candidata: custo esperado com percentil e contingência / (valor líquido de uma unidade × margem de cobertura). Definir quantidade máxima antes de reservar; para lotes parcialmente válidos, reservar teto e consumir somente unidades concluídas, liberar diferença. A mudança desta semântica em XML requer aprovação e regressão: a política atual não deve ser reinterpretada pelo frontend. Orçamento já consome após conclusão e mantém isso.

## 7. Endpoints futuros (não criados)

`GET /account/wallet` saldo/reservado/moeda e direitos separados; `GET /account/wallet/entries?cursor=` extrato do proprietário; `GET /tools/{tool}/cost` versão/unidade/teto; `POST /wallet/orders` pacote permitido + Idempotency-Key; `GET /wallet/orders/{uuid}` conciliação; `POST /processings/{uuid}/estimate` orçamento de consumo; início existente aceita fonte universal somente após expansão versionada do contrato. Reserva/consumo/liberação são comandos internos, nunca endpoints abertos ao navegador.

Nesta etapa, nenhum endpoint novo/modificado no Laravel. Navbar usa `/quotations/entitlements`, conta continua usando uso, assinatura, compras e histórico existentes.

## 8. Validações pendentes e ativação

1. Aprovar unidade universal, denominação e preços/pacotes; decidir expiração, fonte prioritária, consumo parcial e refund parcial/recuperação.
2. Aprovar tabelas e invariantes acima, retenção/anonimização, ordem de locks e política de rollout.
3. Implementar migrations aditivas em ambiente isolado; ledger, grants, reservas e outbox sob feature flag desligada. Validar MySQL real com conexões concorrentes.
4. Testar conceder/repetir webhook; duas reservas simultâneas para última unidade; saldo insuficiente; refund vs consumo; refund antes de confirmação; disputa aberta/ganha/perdida; expiração/retry/crash; ownership; mixed mode; same-key/different-payload; recomputação integral do ledger.
5. Aprovar configuração Stripe Test, testar mocks/Test; só então configurar Live em publicação separadamente autorizada. Nenhum crédito universal aparece como comprável antes disso.

## 9. Rollback financeiro

Desativar novas compras/reservas universais por flag; manter conciliação de pagamentos já iniciados, download e liquidação das reservas existentes. Reconciliar ledger/pedidos antes de rollback de código. Se a versão anterior não conhece carteira, manter serviço de liquidação compatível até zerar reservas e pedidos pendentes. Não executar down/drop/truncate nem restaurar backup financeiro por cima de eventos mais recentes. Não converter moeda nem transferir saldos entre fontes no rollback. Corrigir por entrada compensatória com referência/autor/motivo, após validação operacional. Frontend pode voltar independentemente porque contratos atuais continuam compatíveis.
