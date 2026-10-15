# XML → DANFE / PDF

## Diagnóstico e decisões

Backend Laravel 12, PHP 8.2+, Sanctum, login Google, fila database e armazenamento privado. Frontend Angular 21 com SSR/prerender. O processamento, a seleção de arquivos, a página, o histórico, o controle de proprietário, o TTL e o parser seguro já eram compartilhados pelas ferramentas anteriores e foram reutilizados.

Nova ferramenta `xml-to-pdf`, rota pública `/xml-para-pdf`. Não há migration ou estrutura nova de assinatura. Free/Pro continuam com as configurações existentes e saldo diário separado por ferramenta.

## Funcionamento

1. Seleção múltipla e drag and drop; limites existentes de tamanho, quantidade e estrutura XML.
2. Autenticação antes do upload/processamento. Reserva do volume sob os locks existentes.
3. Job lê XML privado com limite e checksum, usa `NfeParser`, valida consistência e gera PDF por arquivo.
4. Repetições da chave no mesmo lote falham individualmente e não geram outro PDF. Arquivos inválidos não impedem os demais.
5. PDFs privados, nomeados por chave, são reunidos em ZIP com um aviso em `LEIA-ME.txt`. A interface apresenta status individuais, resumo da nota, visualização autenticada por blob, download individual e ZIP.
6. Ao concluir, o consumo é ajustado ao número de PDFs bem-sucedidos. Jobs repetidos e novo start do mesmo lote não duplicam consumo. Falha global libera o consumo quando o job esgota as tentativas; exclusão/expiração de lote ativo libera a reserva. Conversões intencionais em novos lotes constituem novos usos.

Checkout avulso antecipado está desabilitado apenas nesta ferramenta, no servidor e na configuração pública: o fluxo avulso atual cobra pelo volume enviado antes da validação. Free e Pro são reutilizados para garantir consumo somente por sucesso sem introduzir cobrança antecipada de arquivos inválidos. As outras ferramentas preservam o checkout existente.

## Validação e limites da informação fiscal

- NF-e modelo 55, chave de 44 dígitos com DV e correspondência de UF, data, emitente, modelo, série, número, emissão e código numérico.
- XML processado com protocolo 100 ou 150, número de protocolo, recebimento, ambiente correspondente e digest igual ao da referência à NF-e na assinatura XML.
- Emitente, destinatário, número, série, itens e total são extraídos pelo parser existente. Estrutura ambígua, envelopes duplicados e namespaces estranhos são recusados antes do renderizador.
- XML sem protocolo consistente é recusado nesta primeira versão. Homologação é preservada e sinalizada pelo renderizador como sem valor fiscal.
- Não há emissão, alteração de tributos, consulta SEFAZ, verificação criptográfica da assinatura nem validação integral por XSD oficial. As validações são de estrutura, campos e consistência, não uma certificação fiscal.
- O aviso **“Status atual na SEFAZ não consultado. Protocolo informado no XML; assinatura digital não verificada.”** aparece na página, nos dados individuais, no rodapé dos PDFs e no ZIP. Eventos posteriores, como cancelamento, não são conhecidos.

## Segurança e retenção

Reutilizados `SecureXml`, `SafeXml`, `PrivateProcessingFileReader`, middleware de proprietário, rate limits e `PurgeExpiredProcessings`. DTD/entidades são recusados, parsing usa limites e `LIBXML_NONET`. O carregador de entidades externas também permanece bloqueado durante o renderizador de terceiros, com restauração em `finally`. Não são aceitas URLs de logos ou recursos externos.

`GET /api/processings/{processing}/pdf/{input}` exige conta, proprietário, lote concluído não expirado, input concluído pertencente ao lote e PDF privado correspondente. ID de outro lote não funciona mesmo para o mesmo proprietário. Downloads têm `no-store, private` e `nosniff`. Todos os PDFs ficam no prefixo privado já removido pelo purge. O ZIP intermediário fica no disco local privado em `danfe-temporary/{uuid}`, é excluído em `finally` e também pela exclusão/expiração do lote, cobrindo interrupções abruptas do worker.

## Dependências e implantação

- `nfephp-org/sped-da`: `dev-master`, commit exato registrado no `composer.lock` (`308abfe…`). A versão estável resolvida inicialmente, 0.1.26, tem API antiga; a versão mantida foi instalada e testada com PHP 8.2.12. Preservar o lock no deploy.
- Novas dependências transitivas: `nfephp-org/sped-common` 5.1.17, `tecnickcom/tc-lib-barcode` 1.18.5 e `tecnickcom/tc-lib-color` 1.14.39.
- Habilitar SOAP no PHP do ambiente de implantação, além de DOM, GD, mbstring, ZIP e demais extensões exigidas pelo Composer. SOAP já está disponível localmente como DLL, mas não habilitado no php.ini. Os comandos de instalação/teste usaram `php -d extension=soap`; o php.ini global não foi alterado.
- Rodar `composer install` com o lock e reiniciar os workers após a implantação. Manter o worker e a rotina de expiração existentes em execução.
- Nenhuma publicação ou alteração de infraestrutura foi executada.

## Arquivos criados

- `feitoo-api/app/Services/DanfeGenerator.php`
- `feitoo-api/app/Services/DanfeProtocolException.php`
- `feitoo-api/app/Services/DanfeResultService.php`
- `feitoo-api/tests/Feature/DanfeProcessingTest.php`
- `feitoo-web/src/app/features/processing/pages/xml-to-pdf.spec.ts`
- `docs/xml-to-pdf.md`

## Arquivos alterados

Backend:

- `app/Enums/ProcessingTool.php`, `ProcessingFileError.php`
- `app/Http/Controllers/ProcessingController.php`, `GoogleAuthController.php`
- `app/Http/Resources/ProcessingResource.php`, `ProcessingFileResource.php`
- `app/Models/Processing.php`
- `app/Services/FiscalProcessingService.php`, `ProcessingService.php`, `PurchaseService.php`
- `routes/api.php`, `composer.json`, `composer.lock`

Frontend:

- `src/app/features/processing/tools.ts`, `models/processing.ts`
- `src/app/features/processing/services/processing.service.ts`, `xml-selection.service.ts`
- `src/app/features/processing/pages/xml-to-excel.ts`, `.html`, `.scss`
- `src/app/features/auth/services/auth.service.ts`, `pages/auth-page.ts`
- `src/app/features/account/pages/account-page.html`
- `src/app/features/public/home-tools.ts`, `home.html`, `home.spec.ts`
- `src/app/app.routes.ts`, `app.routes.server.ts`, `app.ts`, `app.html`
- `src/app/core/public-pages.ts`, `tool-faqs.ts`, `seo.service.ts`, `analytics.service.ts`, `public-launch.spec.ts`
- `scripts/generate-public-assets.mjs`, `public-assets.test.mjs`, `vercel.json`

## Verificação

Resultado final: **250 testes backend (2.117 asserções), 170 testes Angular e 3 testes de arquivos públicos aprovados**. Build de produção aprovado com 11 rotas prerenderizadas; Pint e verificação de whitespace aprovados.

- `php -d extension=soap artisan test --compact`: suíte backend completa.
- `npm test -- --watch=false`: suíte Angular completa, incluindo fluxo PDF, seleção isolada, limites correspondentes, aviso fiscal, visualização por blob e revogação da URL.
- `npm run test:public`: sitemap e normalização das rotas de hospedagem.
- `FEITOO_BUILD_ENV=production npm run build`: prerender, metadados, canonical, WebApplication, BreadcrumbList e FAQPage consistentes com o conteúdo visível.
- Testes DANFE exercitam geração real, ZIP aberto e inspecionado, protocolo ausente/inconsistente, ambiente divergente, digest divergente, chave divergente, modelo 65, XML malformado/XXE/grande, repetição, lote, falhas parciais de validação e renderização, consumo idempotente, exclusão, expiração e isolamento de usuários/lotes.
- Layout: geração real com 1, 2 e 90 produtos; múltiplas páginas verificadas automaticamente. Fixtures são sintéticas e não representam autorização real da SEFAZ.

Pendências operacionais: habilitar SOAP no deploy e fazer homologação visual com XMLs representativos reais, especialmente cenários fiscais/layouts excepcionais. O build mantém um aviso de orçamento de CSS da página compartilhada. Não foi feita consulta oficial SEFAZ, validação criptográfica ou certificação integral do layout fiscal.
