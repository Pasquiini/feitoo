# Etapa 04 — parser fiscal NF-e

## Escopo e ambiente

Parser isolado para **NF-e modelo 55**, em envelope `nfeProc` ou `NFe` diretamente
na raiz. Não executa após upload, não altera Processing, não cria endpoints,
não persiste o resultado e não dispara jobs. Autenticação, propriedade de arquivos,
storage privado, SHA-256, TTL e purge permanecem nas camadas existentes.

Auditoria: PHP 8.2.12, libxml 2.10.3, Laravel 12.69.3. DOM, XMLReader,
SimpleXML, fileinfo e mbstring já estavam disponíveis; nenhuma dependência foi
instalada. Os 51 testes anteriores passaram antes da implementação. Não havia
parser fiscal. Alterações anteriores não commitadas e `.env` foram preservados.

## Uso e arquitetura

```php
use App\Domain\Fiscal\Nfe\NfeParser;

$document = (new NfeParser)->parse($xml);
$document->accessKey;           // string, 44 dígitos
$document->number;              // string, não perde zeros
$document->issuer->name;
$document->recipient?->cpf;
$document->items[0]->commercialQuantity; // string decimal
$document->totals?->vNF;         // string decimal ou null
$document->issuedAt;            // DateTimeImmutable
```

`$xml` é o conteúdo em bytes, nunca um path ou URL. Nenhuma camada superior
recebe DOM, SimpleXML ou XMLReader. O parser pode ser resolvido pelo container
Laravel sem registrar providers adicionais.

```text
app/Support/Xml/
  SecureXml.php                 validação compartilhada e DOM seguro
  InvalidXml.php                erro genérico de segurança/sintaxe
app/Rules/SafeXml.php           adaptador do upload: arquivo, MIME e SecureXml
app/Domain/Fiscal/
  Xml/FiscalXmlDetector.php     identifica o envelope pela raiz/namespace
  Xml/FiscalXmlEnvelope.php     enum NFe / nfeProc
  Xml/FiscalXmlFields.php       navegação interna, escalares, decimais e datas
  Nfe/NfeParser.php             normalização do modelo 55
  Nfe/NfeTaxParser.php          representação das variantes tributárias
  DTO/                         contratos readonly e enums
  Exceptions/                  erros controlados
```

O detector público recebe string via `detect($xml)`, retornando o enum do
**envelope**; a restrição ao modelo 55 é responsabilidade do NfeParser. O método
interno `inspect(DOMDocument)` evita recarregar o XML já validado pelo parser.
Não reconhece outros envelopes, lotes, SOAP, eventos, CT-e, MDF-e ou NFS-e.
Modelo 65 é recusado explicitamente, mesmo em envelope NFe.

## Namespace e versões

O namespace oficial `http://www.portalfiscal.inf.br/nfe` fica em uma única
constante no detector. A versão de leiaute vem do atributo `infNFe/@versao`.
Prefixos são livres: nome local **e** namespace determinam os campos. Não há
remoção de namespace por regex, nem busca por qualquer descendente com nome
parecido. Campos são lidos entre filhos diretos; tags fiscais de namespace
estranho, campos escalares com markup e singletons duplicados são recusados.

Versões não são enumeradas em uma whitelist literal: aceita-se estrutura
compatível com os campos suportados, conservando o atributo original. Fixtures
exercitam 2.00, 3.10 e 4.00; isso não representa certificação completa de todos
os leiautes. Se o envelope declarar versão, ela deve coincidir com infNFe.

Referência do namespace e da separação entre versão e namespace:
[Manual oficial NF-e](https://www.nfe.fazenda.gov.br/portal/exibirArquivo.aspx?conteudo=d%2FNN9SSgick%3D).

## DTOs e campos

Todos os DTOs são `final readonly`, com tipos explícitos, arrays documentados
como listas e datas imutáveis. Os códigos e identificadores são strings,
preservando zeros. Apenas `nItem` é inteiro, de 1 a 990, sem números repetidos.

| DTO | Conteúdo |
| --- | --- |
| `ParsedFiscalDocument` | Tipo normalizado, modelo, versão, chave, número, série, emissão, natureza, identificação, emitente, destinatário, itens, totais, protocolo, pagamentos |
| `FiscalIdentification` | cUF, cNF, natOp, mod, serie, nNF, dhEmi/dEmi, dhSaiEnt/dSaiEnt, tpNF, idDest, cMunFG, tpImp, tpEmis, cDV, tpAmb, finNFe, indFinal, indPres, procEmi, verProc |
| `FiscalParty` | CNPJ, CPF, xNome, xFant, IE, CRT, indIEDest, email e endereço; reutilizado para emitente/destinatário |
| `FiscalAddress` | xLgr, nro, xCpl, xBairro, cMun, xMun, UF, CEP, cPais, xPais, fone |
| `ParsedFiscalItem` | nItem, cProd, cEAN, xProd, NCM, CEST, CFOP, uCom, qCom, vUnCom, vProd, cEANTrib, uTrib, qTrib, vUnTrib, indTot, xPed, nItemPed, vTotTrib e tributos |
| `FiscalTotals` | vBC, vICMS, vICMSDeson, vFCP, vBCST, vST, vFCPST, vProd, vFrete, vSeg, vDesc, vII, vIPI, vIPIDevol, vPIS, vCOFINS, vOutro, vNF, vTotTrib |
| `FiscalProtocol` | tpAmb, verAplic, chNFe, dhRecbto, nProt, digVal, cStat, xMotivo |
| `FiscalPayment` | tPag e vPag, tanto em pag direto quanto em detPag |
| `FiscalTaxGroup` | Enum ICMS/IPI/PIS/COFINS/II/ISSQN e árvore de elementos |
| `FiscalTaxElement` | Nome local, valor textual opcional, filhos tipados e atributos |

Enums adicionais: `FiscalDocumentType::Nfe` e `FiscalTaxType`.

Os nomes das propriedades seguem inglês descritivo, exceto FiscalTotals que
mantém os nomes inequívocos das tags. Correspondências importantes:
`commercialQuantity=qCom`, `commercialUnitPrice=vUnCom`, `productTotal=vProd`,
`taxableQuantity=qTrib`, `taxableUnitPrice=vUnTrib`, `gtin=cEAN`, `cnpj=CNPJ`.

Não se inventam dados ausentes. Destinatário, endereço, totais, protocolo,
pagamentos e campos opcionais podem ser null/lista vazia. Um nfeProc sem
protocolo também pode ser extraído, sem presumir autorização.

O contrato mínimo exige infNFe, Id/chave, versao, ide/mod/serie/nNF/data de
emissão, emitente com nome e CNPJ ou CPF, e pelo menos um det/prod com código,
descrição, NCM, CFOP, unidade, quantidade, valor unitário e total. Não constitui
validação XSD completa; campos ausentes do contrato mínimo são diferenciados
dos opcionais. Se houver protocolo, infProt e chNFe devem existir, e a chave
deve coincidir com a do documento.

## Tributos sem interpretação fiscal

As seis famílias suportadas mantêm a árvore de variantes: ICMS00, ICMSSN102,
PISAliq, PISNT, COFINSAliq, COFINSNT, IPITrib e outras encontradas nos mesmos
grupos não exigem novos ifs. A árvore preserva a ordem, tags repetidas, atributos
e valores textuais, sem retornar nós XML. Exemplo de navegação:

```php
$icms = $document->items[0]->taxes[0];
$variant = $icms->data->children[0]; // FiscalTaxElement, ex.: ICMS00
$fields = $variant->children;      // CST, vBC, pICMS, vICMS etc.
```

Os campos tributários brutos permanecem strings, sem coerção genérica de códigos
como CST/CSOSN. Não são calculados nem avaliados legalmente. Outros grupos
tributários, inclusive IBS/CBS, não são normalizados nesta etapa.

## Decimais e datas

Quantidades, valores unitários, totais dos produtos/nota e valores de pagamento
usam strings decimais, **sem float** e sem arredondamento. Aceitam-se dígitos
ASCII com ponto decimal opcional, no máximo 128 caracteres. Removem-se somente
zeros excedentes da parte inteira; escala e dígitos fracionários são preservados:
`0001.2300` → `1.2300`. Notação científica, vírgula, sinal e formatos inválidos
são recusados. Não há reconciliação entre quantidade, preço e total.

`DateTimeImmutable` interpreta dhEmi/dhSaiEnt/dhRecbto com formato ISO esperado
e offset obrigatório (ou Z), preservando o offset. Datas impossíveis e offsets
inválidos são recusados, sem ajuste silencioso. Na ausência de dhEmi/dhSaiEnt,
usa-se dEmi/dSaiEnt. Datas sem horário são representadas à meia-noite UTC apenas
como suporte técnico; `issuedAtHasTime=false` / `departedAtHasTime=false` indicam
que a fonte não informa hora ou timezone. Nesse caso utilize `format('Y-m-d')`,
sem interpretar o UTC como fuso declarado pela empresa.

## Segurança, encoding e erros

SafeXml e NfeParser compartilham **SecureXml**, evitando validadores divergentes.
O upload conserva a inspeção de MIME pelo servidor. O parser de conteúdo não
depende de UploadedFile nem das camadas HTTP.

Antes do DOM, XMLReader valida o conteúdo com os limites existentes de tamanho,
profundidade, nós e prazo da varredura. DTD, entidades e entity references são
proibidos; há uma pré-checagem conservadora de declarações, mantida da Etapa 02,
além da verificação pelo leitor. A pré-checagem pode recusar comentários contendo
literalmente declarações DTD/ENTITY. Não é usada para extrair estrutura fiscal.

Ambas as passagens usam LIBXML_NONET, loader externo que retorna null, DTD,
validação DTD, atributos default e substituição de entidades desativados. Não
usam LIBXML_NOENT, DTDLOAD, PARSEHUGE, XInclude, XSD remoto ou load de arquivos.
O estado global do loader/modo de erros é restaurado em finally; diagnósticos
libxml não são retornados nem registrados. O limite de tempo é da varredura,
não um timeout global de CPU; o DOM recebe apenas conteúdo já limitado.
Referência: [loader externo do PHP](https://www.php.net/manual/en/function.libxml-set-external-entity-loader.php).

A declaração XML governa o encoding via libxml, que entrega texto UTF-8 aos DTOs.
Não há conversão heurística. Testados UTF-8 com acentos, ISO-8859-1 declarado,
encoding inválido e tentativa XXE em UTF-16.

| Exceção | Situação |
| --- | --- |
| `InvalidFiscalXml` | Sintaxe/segurança inválida, campo malformado, chave inválida, duplicidade ou inconsistência |
| `UnsupportedFiscalDocument` | Raiz/namespace não suportado ou modelo diferente de 55 |
| `MissingRequiredFiscalField` | Ausência de elemento/atributo do contrato estrutural mínimo |

A chave aceita exatamente 44 dígitos após remover **somente** o prefixo conhecido
NFe, se presente. Não calcula dígito verificador nem confere todos os componentes.
Exceções não carregam conteúdo, paths ou dados fiscais nas mensagens; o erro de
campo ausente informa apenas o nome estrutural. Não existe exposição HTTP nova.

## Fixtures e testes

Em `feitoo-api/tests/Fixtures/Fiscal/`:

- `nfe-proc.xml`: dois itens, emitente/destinatário CNPJ, seis famílias tributárias,
  variantes CST/CSOSN, totais, pagamentos e protocolo sintético.
- `nfe-minimal.xml`: NFe isolado, leiaute legado, CPF, datas sem horário e opcionais ausentes.
- `hostile-xxe.xml`: entrada maliciosa para regressão de entidades externas.

Todos os dados são fictícios, com identificadores zerados, chave sintética e
e-mail `.test`. Não são documentos fiscais válidos, assinados ou autorizados.

`tests/Unit/Fiscal/NfeParserTest.php` contém **77 casos**, incluindo os 31 pontos
solicitados, precisão elevada, namespaces falsos, escalares aninhados, duplicidades,
limites, restauração de libxml e ausência de resolução externa. Usa TestCase Laravel
apenas para fornecer configurações de segurança; não depende de banco ou internet.

```powershell
cd feitoo-api
php artisan test --filter=NfeParserTest
php artisan test
vendor\bin\pint --test
```

Suíte completa: **128 testes passaram, 835 assertions**, mantendo os 51 testes
anteriores. Nenhuma mudança no frontend; testes/build Angular não precisaram ser
reexecutados nesta etapa.

## Limitações e evolução

Não valida XSD, assinatura, SEFAZ, CPF/CNPJ, GTIN, cálculos fiscais ou autorização.
O protocolo é extraído, não autenticado. Transporte foi deliberadamente adiado;
pagamento inclui somente tPag/vPag. Não suporta outros modelos/documentos,
novos grupos tributários fora das seis famílias, resultados HTTP, Excel ou filas.

Uma próxima etapa pode integrar o parser ao ciclo de Processing, com tratamento
dos erros por arquivo e persistência privada do resultado normalizado. Essa
integração deve conservar controle de propriedade, TTL e limites, sem processamento
pesado em controllers. **Não foi iniciada nesta etapa.**
