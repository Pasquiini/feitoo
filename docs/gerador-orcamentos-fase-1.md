# Gerador de Orçamentos — Fase 1: prova técnica de PDF

**Data:** 20/10/2026. **Resultado:** geração real validada localmente com Blade + Dompdf, dois modelos e fixtures fictícias. Não é a ferramenta pública.

**Decisão comercial vigente:** Free será **1 orçamento bem-sucedido por dia**, por conta/ferramenta, em **`America/Sao_Paulo`**, e não vitalício. Avulso permanece R$ 9,90 por cinco créditos reutilizáveis; Pro tem proposta inicial de 100 gerações por período da assinatura. Downloads repetidos não consomem, falhas técnicas não devem consumir e reservas simultâneas contarão no limite. Essas regras são contrato para a fase futura: **nenhum controle de consumo, pagamento ou reserva foi implementado nesta fase**. A auditoria anterior foi atualizada para substituir a recomendação vitalícia.

## 1. Motor escolhido e justificativa

Foi escolhido **Dompdf 3.1.6**, com templates Blade fixos, A4 retrato e fontes locais DejaVu Sans normal/negrito. O motor recebe um DTO validado e devolve bytes, quantidade de páginas e SHA-256. Não conhece usuário autenticado, Processing, banco, arquivos fiscais, DOCX, Stripe ou histórico.

A inspeção anterior à instalação encontrou PHP CLI **8.2.12, Windows, 64 bits**, Composer **2.10.2**, GD, DOM, mbstring, fileinfo, XML/libxml, ZIP e as extensões aritméticas disponíveis. `brick/math` **0.14.8** já estava no lock. Dompdf não estava instalado.

O Dompdf atendeu à prova de acentuação, logo, tabela, subtotal/descontos/total, condições, observações, cabeçalho, rodapé e paginação. Os 100 itens representativos foram gerados em **11 páginas por modelo**. Não foi necessário Chromium, LibreOffice, serviço externo ou conversão de DOCX.

A limitação importante é a impossibilidade de uma célula de tabela atravessar uma página. A solução mede o texto com a própria fonte do PDF, quebra a descrição em linhas sem eliminar caracteres e emite trechos de até quatro linhas por linha de tabela. A primeira parte recebe quantidade/valores; as continuações não repetem cobrança. Condições e observações são organizadas em blocos de até oito linhas. A última parte do último item fica junto ao bloco de totais. A limitação do motor e o suporte limitado a CSS também estão documentados no [projeto oficial](https://github.com/dompdf/dompdf).

Os dois modelos apresentam os mesmos dados. Clássico usa branco, cinzas discretos, título leve e total com filete. Moderno usa verde Feitoo, cabeçalhos de tabela verdes e total destacado. Não existe editor nem entrada de HTML/CSS.

## 2. Dependências adicionadas

| Dependência | Versão instalada | Alteração |
| --- | --- | --- |
| `dompdf/dompdf` | 3.1.6 | Nova dependência direta, constraint `~3.1.0` |
| `brick/math` | 0.14.8 | Tornada direta, constraint `~0.14.8`; versão existente preservada |
| `dompdf/php-font-lib` | 1.0.2 | Nova dependência transitiva |
| `dompdf/php-svg-lib` | 1.0.2 | Nova dependência transitiva do motor; SVG do usuário continua proibido |
| `masterminds/html5` | 2.11.0 | Nova dependência transitiva |
| `sabberworm/php-css-parser` | 9.5.0 | Nova dependência transitiva |

O lock apresentou **cinco instalações, zero atualizações e zero remoções** de pacotes existentes. `composer.json` e `composer.lock` são as únicas alterações desta fase em arquivos de código/configuração preexistentes. A auditoria em Markdown também recebeu a atualização comercial. Alterações locais que já existiam em serviços fiscais, testes, configuração e frontend foram preservadas.

O primeiro Composer parou porque `ext-soap`, exigida por uma dependência fiscal existente, estava desativada no CLI. A DLL já estava disponível. A instalação final usou `php -d extension=soap ...composer.phar`, habilitando SOAP somente naquele processo, com `--no-scripts --minimal-changes`. Não houve edição de `php.ini`, scripts Composer, migration operacional ou atualização de dependências fiscais. Composer validou o arquivo, os requisitos de plataforma e não informou advisories de segurança na instalação.

Para inspeção independente, foi instalado **PyMuPDF 2.0** e suas dependências na pasta privada ignorada pelo Git `feitoo-api/storage/app/private/quotation-validation/python`. É uma ferramenta local de validação, **não uma dependência da aplicação ou do deploy**. Não baixa recursos durante a inspeção. Seu uso nesta fase foi abrir, extrair texto e rasterizar PDFs fictícios já gerados.

## 3. Estrutura implementada

Todos os caminhos abaixo são relativos a `feitoo-api/`:

```text
app/Domain/Quotations/
  Quotation.php                       DTO imutável, construído somente por validação
  QuotationParty.php                  Prestador/cliente
  QuotationItem.php                   Item com total calculado
  QuotationTotals.php                 Subtotal, desconto e total
  QuotationTemplate.php               Enum classic/modern
  QuotationLimits.php                 Limites técnicos
  QuotationValidator.php              Schema fechado e validação de valores
  QuotationCalculator.php             BigDecimal, HALF_UP e formatação sem float
  QuotationValidationException.php    Erros sem valores pessoais
  NormalizedQuotationLogo.php         PNG/JPEG → PNG normalizado
app/Services/Quotations/
  QuotationPdfRenderer.php            Blade + Dompdf, workspace e limpeza
  QuotationTextLayout.php             Quebra de texto pela métrica da fonte
  QuotationPdf.php                    Resultado: bytes, páginas e checksum
  QuotationPdfException.php           Motivo técnico seguro, sem encadear payload/HTML
app/Support/Quotations/
  QuotationDemoFixtures.php           Fixtures e logos inteiramente fictícios
app/Console/Commands/
  QuotationPdfDemo.php                Demonstração local, sem entrada de dados externos
resources/views/quotations/
  classic.blade.php                   Modelo Clássico
  modern.blade.php                    Modelo Moderno
  document.blade.php                  Conteúdo/CSS fixos compartilhados
tests/Unit/Quotations/
  QuotationTest.php                   Contrato, cálculo e entradas inválidas
  QuotationLogoTest.php               Normalização, alpha, metadata e imagens inválidas
tests/Feature/
  QuotationPdfTest.php                Geração real, limites, limpeza e comando
scripts/
  check-quotation-pdfs.py             Integridade, extração, bounds e previews locais
```

Não foram criados controllers, rotas, migrations, models Eloquent, jobs públicos, tabelas ou componentes Angular. Nenhum serviço fiscal/DOCX, entitlement, preço Stripe, worker ou configuração de VPS foi modificado.

## 4. Contrato técnico dos dados

Entrada PHP: `Quotation::fromArray(array $input): Quotation`. O construtor do documento é privado; o renderizador exige esse DTO. Logo é opcional e separado: `NormalizedQuotationLogo::fromBytes(string $bytes)`. Não recebe caminho de arquivo nem URL.

```json
{
  "schema_version": 1,
  "number": "ORC-EXEMPLO-001",
  "issue_date": "2026-10-20",
  "valid_until": "2026-11-04",
  "currency": "BRL",
  "issuer": {
    "name": "Prestador Fictício",
    "document": "",
    "email": "contato@example.test",
    "phone": "",
    "address": "Endereço fictício"
  },
  "customer": { "name": "Cliente Fictício" },
  "items": [
    { "description": "Serviço de instalação", "quantity": "2.0000", "unit_price_cents": 15000 }
  ],
  "discount": { "type": "percent", "value": "10.00" },
  "conditions": "Pagamento em duas parcelas.",
  "notes": "Exemplo fictício.",
  "template_id": "classic"
}
```

| Campo | Regra |
| --- | --- |
| `schema_version` | Inteiro 1 |
| `number` | Texto obrigatório, até 60 caracteres, sem quebras de linha |
| Datas | `YYYY-MM-DD`, datas reais, validade igual/posterior à emissão |
| Moeda | Somente `BRL` |
| Partes | Nome obrigatório até 160 caracteres; documento até 30, e-mail até 254, telefone até 40 e endereço até 400 são opcionais |
| E-mail | Quando preenchido, validação RFC sem consulta DNS |
| Campos de identificação | Uma linha; texto UTF-8, sem controles inválidos |
| Itens | Lista de 1 a 100; descrição obrigatória até 1.000 caracteres, com quebras permitidas |
| Quantidade | **String** decimal canônica positiva, até quatro casas, máximo `1000000`; sem vírgula, expoente ou zeros iniciais |
| Preço unitário | **Inteiro** de 0 a 1.000.000.000 centavos, isto é, até R$ 10 milhões |
| Subtotal | Máximo 100.000.000.000 centavos, isto é, R$ 1 bilhão; verificado com precisão antes da conversão para inteiro |
| Desconto percentual | `{ "type": "percent", "value": "12.50" }`; string canônica entre 0 e 100, até duas casas |
| Desconto fixo | `{ "type": "fixed", "value": 1000 }`; inteiro em centavos, entre zero e subtotal |
| Sem desconto | Desconto fixo de zero |
| Condições/notas | Campos presentes, string vazia permitida, até 4.000 caracteres cada |
| Modelo | Enum `classic` ou `modern`, sem nomes livres de views |
| Payload | Até 256 KiB em JSON; chaves desconhecidas são rejeitadas, inclusive nas partes, itens e desconto |

Subtotal, desconto calculado, total por linha e total final são **saídas**, não números aceitos do usuário. `QuotationTotals` os apresenta como inteiros. Campos como `subtotal`, `total_cents`, `plan_price`, `logo_path`, `logo_url`, `html` e chaves arbitrárias são recusados.

HTML/script que apareça dentro de um campo de texto legítimo é exibido como texto literal escapado. Isso é diferente de aceitar um campo de HTML ou permitir que o usuário determine a estrutura do documento. Caracteres sem glifo nas fontes selecionadas produzem `unsupported_character`, em vez de desaparecer ou ser substituídos silenciosamente. Não se promete suporte a todos os emojis/alfabetos Unicode.

**Contrato comercial futuro, sem implementação:** quota Free 1 sucesso/dia em São Paulo; cinco créditos por pacote de R$ 9,90; proposta Pro 100/período; re-download sem novo consumo; reserva conta no limite enquanto a geração está ativa; falha técnica libera a reserva. Na integração, definir a política de conclusão na virada do dia mantendo uma única referência de reserva, sem débito em dois períodos. Os limites desta seção são técnicos do documento, não entitlements.

## 5. Regras de cálculo

Toda aritmética financeira usa `Brick\Math\BigDecimal`; não usa `float`, `number_format` com conversão monetária ou operações financeiras em binário.

1. Multiplicar quantidade decimal pelo preço unitário inteiro em centavos.
2. Arredondar **cada linha** para centavos com HALF_UP.
3. Somar os valores já arredondados para formar o subtotal.
4. Calcular desconto percentual sobre esse subtotal, com HALF_UP uma vez; ou subtrair o desconto fixo validado.
5. Rejeitar limites excedidos e desconto maior que subtotal; nunca produzir total negativo.
6. Formatar em BRL por agrupamento de inteiros. A apresentação não altera os valores calculados.

Exemplos executados nos testes:

- `0.5 × 101 centavos = 50.5 → 51 centavos` por linha. Duas dessas linhas somam 102, e não 101.
- `0.0001 × 5000 = 0.5 → 1 centavo`.
- Subtotal 103 com desconto de 50%: desconto 52, total 51 centavos.
- `12.3456 × 2750 = 33950.4 → 33950 centavos`; acrescido da linha de 51, subtotal 34001. Desconto fixo 1000 resulta em 33001.
- Item gratuito é permitido com preço zero e quantidade positiva. Desconto de 100% ou fixo igual ao subtotal resulta em zero.
- Preço/quantidade/percentual em tipo errado, limites excessivos e overflow de subtotal são recusados antes da emissão.

## 6. Medidas de segurança

- Templates Blade fixos, seleção por enum e escaping `{{ ... }}` em todo texto variável. Não há `{!! ... !!}` com conteúdo do usuário nem compilação de template fornecido externamente.
- JavaScript, PHP embutido e recursos remotos desativados no Dompdf. Somente protocolo `file://`, com regra adicional restrita ao logo normalizado da execução e à pasta de fontes aprovada. Caminhos resolvidos por `realpath`, impedindo que um symlink amplie a lista permitida.
- Não se aceita URL/base64/caminho do logo no documento. Bytes de PNG/JPEG são verificados por MIME real, dimensões e decodificação GD, reamostrados e reencodificados para PNG; metadata original é descartada. Transparência do PNG é preservada.
- Logo com até 2 MiB, até 4 milhões de pixels antes da decodificação integral e lado final máximo de 1.200 px. SVG, GIF, PNG animado, conteúdo falso, imagem corrompida e excessos são recusados. Sem GD, a normalização falha explicitamente.
- Temporários em `storage/app/private/quotation-temporary/{token-aleatorio}`, com diretório privado e permissão solicitada `0700`. O token é interno e não deriva de nomes do usuário. No Windows, a proteção efetiva depende das ACLs locais.
- Logo e caches temporários ficam nesse workspace. `finally` remove a pasta tanto no sucesso quanto na falha. A limpeza foi verificada; o diretório de temporários estava vazio ao final. A compilação padrão dos templates Blade permanece no cache interno do Laravel e não contém os dados interpolados dos orçamentos.
- Saída completa limitada a 20 páginas e 10 MiB, com assinatura PDF, trailer/`startxref`/EOF e checksum. O limite é aplicado ao documento integral; não existe saída parcial/truncada como alternativa silenciosa. Uma estimativa conservadora rejeita textos que comprovadamente não caberiam, antes de construir o PDF excessivo.
- Erros do motor não encadeiam a exceção original, HTML, caminhos ou dados. Validadores devolvem somente caminhos de campos permitidos e mensagens seguras. O motor não registra logs de conteúdo pessoal.
- O comando recusa qualquer ambiente que não seja `local`/`testing`, não aceita caminho de entrada/saída e só seleciona fixtures internas. Não faz queries ou chamadas a Stripe; esse comportamento também foi testado com acesso ao banco proibido por mock.

Esta fase não implementa sandbox de sistema operacional nem limite rígido de CPU/tempo/memória por subprocesso. A futura execução pública deve acrescentar isolamento operacional, timeout e recuperação de temporários órfãos após morte abrupta do processo. `finally` não é garantia de limpeza após desligamento forçado. Não se deve expor diretamente este comando como endpoint.

## 7. Exemplos de geração e artefatos privados

Na pasta `feitoo-api`, em desenvolvimento:

```powershell
php artisan feitoo:quotation-pdf-demo
php artisan feitoo:quotation-pdf-demo --scenario=simple --scenario=hundred-items
```

Cada execução cria uma pasta nova e privada, gera ambos os modelos dos cenários selecionados e grava `manifest.json` com páginas, bytes, checksum, duração, memória absoluta do processo, totais e textos fictícios esperados. `--scenario` não carrega arquivos externos.

Execução final inspecionada:

```text
feitoo-api/storage/app/private/quotation-validation/runs/20261020-192440-67771f49/
```

Exemplos de arquivos nesse diretório: `simple-classic.pdf`, `simple-modern.pdf`, `transparent-png-modern.pdf`, `jpeg-logo-classic.pdf`, `hundred-items-modern.pdf` e `long-text-classic.pdf`. Extrações `.txt`, `inspection.json` e imagens em `previews/` ficam no mesmo diretório privado de validação.

| Cenário fictício | Páginas por modelo | Verificação |
| --- | --- | --- |
| Um item, sem logo | 1 | Dados, linha, total e condições completos |
| Diversos serviços/produto | 2 | Seis itens de elétrica, pintura, mecânica, instalação e manutenção |
| Desconto percentual / fixo | 2 / 2 | Mesma base, cálculo e total corretos |
| Quantidades fracionárias | 1 | Precisão e arredondamento por linha |
| Cortesia e valor elevado | 1 | Zero e valor elevado dentro do teto |
| Descrição com 1.000 caracteres e condições/notas extensas | 4 | Marcadores finais e texto integral presentes |
| 100 itens representativos | 11 | Todos os itens 001–100 extraídos |
| PNG transparente / JPEG | 1 / 1 | Imagem incorporada; metadados removidos na normalização |
| Acentuação e caracteres especiais | 1 | Acentos, símbolos e pontuação extraídos |
| HTML/script como texto | 1 | Tags literais; sem JavaScript/OpenAction no PDF |
| Texto sem espaços | 2 | Quebra medida, sem perder caracteres |
| Campos próximos dos limites | 2 | Nome/identificação/endereço longos e números extensos preservados |

São **14 cenários × 2 modelos = 28 PDFs**, totalizando **64 páginas**. Entradas inválidas não geram PDFs: são cobertas pelos testes de contrato/segurança. PDFs e previews **não são versionados**; o ignore de `storage/app/private` foi confirmado com `git check-ignore`. Nenhum arquivo foi colocado em `public/`, enviado a serviço externo ou publicado.

## 8. Resultados dos testes e verificações

**Resultado final da suíte completa:** **382 testes, 3.395 assertions, zero falhas/erros e 1 teste opcional pulado**. Tempo observado: 1 min 47,608 s; pico de memória da suíte: 140 MiB. Os **87 casos novos** de domínio/PDF/comando estão incluídos nessa execução.

O único skip foi `DocxConverterIntegrationTest::test_real_libreoffice_optional`, condicionado à variável `FEITOO_TEST_LIBREOFFICE=1`. Não foi habilitado nem executado; a conversão real de DOCX/LibreOffice não foi homologada novamente por esta fase. Nenhum teste novo do motor de orçamento ficou pulado.

Ambiente dos testes: `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `DB_URL` vazio, cache/sessão em array. A suíte existente monta schema efêmero do próprio teste em memória; não houve migration operacional, acesso ao MySQL, banco de produção ou recursos de clientes. SOAP foi habilitado somente no processo PHP da suíte para satisfazer a plataforma existente. Foram executadas as suítes backend fiscais, DOCX, autenticação, conta, processamento, assinatura e monetização já presentes.

| Verificação | Resultado |
| --- | --- |
| Contrato e cálculo | Tipos estritos, desconhecidos, datas, quantidades, descontos, limites, UTF-8 inválido e arredondamento aprovados |
| Logo | PNG alpha/JPEG, reencodificação, remoção de metadata, redução de tamanho e rejeição de conteúdo inseguro aprovados |
| Geração real | Todos os cenários e ambos os modelos renderizados; não é teste apenas com fake de PDF |
| Falha e limpeza | Documento acima do limite e glifo ausente recusados; temporários removidos |
| Demonstração | Sucesso com fixtures, recusa de ambiente production e de cenário desconhecido aprovados |
| Laravel Pint | `--test` passou em todos os arquivos PHP novos |
| PHP lint | 19 arquivos PHP novos, zero falhas de sintaxe |
| Composer | JSON válido, requisitos de plataforma satisfeitos com SOAP por processo; pacotes existentes preservados |
| Parser independente | 28 PDFs/64 páginas abertos por PyMuPDF, sem reparo e sem criptografia; hashes conferidos |
| Extração de texto | Todos os textos esperados encontrados integralmente, normalizando somente whitespace de quebra de linha |
| Layout automatizado | A4, numeração por página, limites de conteúdo/rodapé, logo incorporado e último item junto dos totais verificados |
| Git/artefatos | Nenhum PDF/previews versionável; nenhum commit/push |

Comandos principais usados:

```powershell
# Executados em shell local com ambiente de teste explicitamente isolado:
$env:APP_ENV='testing'
$env:DB_CONNECTION='sqlite'
$env:DB_DATABASE=':memory:'
$env:DB_URL=''
$env:CACHE_STORE='array'
$env:SESSION_DRIVER='array'
php -d extension=soap vendor/bin/phpunit --display-skipped
php vendor/bin/pint --test app/Domain/Quotations app/Services/Quotations app/Support/Quotations app/Console/Commands/QuotationPdfDemo.php tests/Unit/Quotations tests/Feature/QuotationPdfTest.php
python scripts/check-quotation-pdfs.py storage/app/private/quotation-validation/runs/20261020-192440-67771f49 --previews
```

A instalação privada de PyMuPDF é necessária para repetir o último comando; não é necessária para a aplicação nem para os testes PHP de geração. Uma execução inicial dos testes de limpeza coincidiu com a demonstração em outra execução e observou o workspace alheio. Os testes passaram a usar storage próprio por caso; a suíte final não apresentou a interferência.

## 9. Inspeção visual dos modelos

Os PDFs foram rasterizados localmente e imagens de Clássico/Moderno foram inspecionadas: documento simples, logo transparente, serviços multipágina, primeira/última página de 100 itens e texto extenso. Também foi feita verificação programática de todas as páginas, não apenas dos screenshots.

Correções realizadas a partir da inspeção:

- O Dompdf ignorou a largura de `colgroup` na versão inicial; as larguras foram aplicadas diretamente aos cabeçalhos de coluna. A descrição passou a respeitar seu espaço sem invadir quantidades/valores.
- O caminho `file:///D:/...` inicialmente não carregava o logo no Windows. A URI interna passou a usar a forma entendida pelo motor; a presença de uma imagem no PDF agora é obrigatória quando existe logo.
- Foi removida uma nota redundante no corpo que criava páginas finais quase vazias. A informação comercial/sem valor fiscal permanece no rodapé.
- Último trecho do último item e totais passaram a permanecer juntos; totais não ficam sozinhos na página seguinte.
- Campos longos de prestador/cliente/número passaram a usar quebra medida também, incluindo o caso de strings sem espaços.

O resultado apresenta fundo branco, tipografia legível, tabela com respiro, total identificável, cabeçalho repetido de tabela, rodapé reservado e número de página. O relatório não equivale a aprovação estética do usuário; os exemplos locais estão disponíveis para essa revisão.

## 10. Limitações encontradas

1. **Envelope de composição:** há suporte validado a 100 itens representativos, e não promessa de 100 descrições máximas somadas a todos os demais campos máximos. Uma composição que exceda 20 páginas é recusada integralmente, sem cortar o documento. O teste de excesso verifica esse comportamento.
2. **Fontes:** caracteres fora da cobertura DejaVu geram erro explícito. Emojis arbitrários e tipografia de qualquer idioma não estão homologados. Não há upload de fonte.
3. **CSS:** os modelos usam CSS compatível com o motor; não usam flex/grid ou CSS Angular. Descrições extensas podem continuar na próxima página em trechos identificados, mantendo a cobrança apenas na primeira parte.
4. **Ambiente:** prova feita em Windows/PHP 8.2.12, não no Linux/VPS de produção. Sandbox operacional, concorrência de workers e interrupção forçada não foram homologados.
5. **Tempo/memória:** a amostra final registrou cerca de 127 ms a 1,99 s por geração e pico absoluto do processo de 46–84 MiB. Os PDFs ficaram entre 26.011 e 45.571 bytes. Havia execução de testes simultânea; esses valores são observações locais, não benchmark de produção ou limite garantido.
6. **Persistência:** o motor retorna bytes; não associa Processing, não salva em histórico de conta e não oferece download autenticado público. Só o comando salva os PDFs de demonstração privados.
7. **Falha abrupta:** `finally` cobre exceções normais; encerramento forçado pode deixar workspace órfão. A rotina operacional de recuperação fica para a integração futura.
8. **Padrões adicionais:** não há certificação PDF/UA/PDF-A, assinatura digital, consulta fiscal, aceite eletrônico ou geração de nota fiscal.
9. **Regressão DOCX real:** somente o teste opcional de LibreOffice foi pulado; não afirmar nova homologação desse binário.

## 11. Compatibilidade com produção

O motor é compatível com a stack PHP/Laravel declarada no repositório, condicionado aos requisitos do lock, fontes empacotadas e GD para logo. Os renderizadores, regras e rotas das seis ferramentas atuais não foram alterados. O lock preserva as versões fiscais/DOCX e Stripe existentes; a suíte completa passou no ambiente isolado.

Isso não autoriza exposição pública imediata. Antes de um deploy futuro: validar o mesmo lock em Linux, escrita e exclusão no storage privado, fontes/acentos, tempo/RSS sob o usuário real do worker, envelope de concorrência e estratégia de timeout/limpeza. Homologar o documento máximo permitido e falhas de disco/processo em ambiente separado.

Não há migration a aplicar nesta fase, mudança de schema, alteração de preço/produto Stripe, restart de worker ou ajuste de VPS. Não foi executado deploy, commit ou push. O comando de demonstração permanece bloqueado em produção.

## 12. Recomendações para a Fase 2

1. Submeter os dois exemplos à revisão visual do usuário e manter o contrato estruturado/aritmético como autoridade do backend.
2. Criar o editor Angular e a prévia em tempo real apenas após autorização, com os mesmos vetores de cálculo e limites retornados pela API.
3. Implementar rascunho e preservação em login, com ownership e revisão; logo deve continuar separado e normalizado.
4. Integrar o motor a um fluxo comercial próprio de Processing/fila, sem dispatcher fiscal por fallback e sem alteração dos limites atuais.
5. Implementar **Free de 1 sucesso por dia em `America/Sao_Paulo`** com reserva atômica; créditos reutilizáveis e Pro em etapas separadas. Não contar download novamente nem confirmar consumo em falha técnica.
6. Acrescentar timeout operacional, orçamento de memória, idempotência, token de tentativa, limpeza de órfãos e reconciliação antes da exposição pública.
7. Reutilizar o download privado e histórico existentes com integração explícita; só depois validar compartilhamento por recursos do dispositivo.
8. Manter Stripe Live, deploy e alterações de workers/VPS sujeitos à etapa operacional autorizada; esta prova não os modifica.

**Fase 1 concluída. Fase 2 não iniciada; aguardando aprovação do usuário.**
