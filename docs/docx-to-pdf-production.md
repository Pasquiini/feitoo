# DOCX → PDF — operação e deploy

Data: 16/10/2026. **Instruções preparadas; nenhum comando foi executado no VPS.** Não houve instalação, alteração de Nginx/Docker/systemd, reinício de serviços ou deploy. Evolution API e outros projetos não foram acessados.

## Dependências e verificação inicial

O worker precisa de PHP com ZIP, DOM, fileinfo, PDO/MySQL e proc_open, LibreOffice Writer/headless, fontconfig/fontes aprovadas, Bubblewrap e prlimit (util-linux) em Linux. Symfony Process já é dependência transitiva do Laravel; nenhum pacote novo de Composer foi necessário.

Antes de instalar qualquer pacote, verificar no **ambiente real**, com aprovação para acessar o servidor:

```sh
which soffice
soffice --version
command -v bwrap
bwrap --version
command -v prlimit
prlimit --version
php -v
php -m
```

Versão encontrada localmente: **LibreOffice não encontrado** no PATH nem em `C:\Program Files\LibreOffice\program\soffice.exe`. Não foi possível medir fidelidade nem testar o sandbox Linux neste Windows. Nenhuma versão real de LibreOffice foi homologada nesta entrega. Espera-se uma versão mantida/corrigida da distribuição, com Writer e filtro `writer_pdf_Export`; registrar a versão efetivamente aprovada pelo teste de integração e visual antes do deploy. Não presumir que a presença do binário garante compatibilidade.

Bubblewrap precisa conseguir criar user/mount/network namespaces sob o usuário do worker. Alguns perfis AppArmor/Ubuntu restringem user namespaces: não desabilitar proteções globais para contornar isso. Avaliar uma política específica com o administrador antes de habilitar a ferramenta. Sem isolamento, o conversor de produção falha como indisponível.

## Configuração central

Valores iniciais em `feitoo-api/config/documents.php` e `.env.example`:

```dotenv
DOCUMENT_CONVERSION_DRIVER=libreoffice
LIBREOFFICE_BINARY=/usr/bin/soffice
DOCUMENT_CONVERSION_SANDBOX=bubblewrap
DOCUMENT_BWRAP_BINARY=/usr/bin/bwrap
DOCUMENT_PRLIMIT_BINARY=/usr/bin/prlimit
DOCX_TO_PDF_TIMEOUT=60
DOCX_MAX_FILE_SIZE_MB=10
DOCX_MAX_FILES_PER_PROCESSING=20
DOCX_MAX_TOTAL_SIZE_MB=40
DOCX_MAX_UNCOMPRESSED_SIZE_MB=80
DOCX_MAX_ZIP_ENTRIES=2048
DOCX_MAX_COMPRESSION_RATIO=100
DOCX_MAX_PDF_SIZE_MB=25
DOCX_MAX_TOTAL_PDF_SIZE_MB=80
DOCX_CONVERSION_MEMORY_MB=1024
DOCX_FREE_PER_PROCESSING=3
DOCX_FREE_DAILY=6
DOCX_PRO_PER_PROCESSING=20
DOCX_PRO_DAILY=100
```

MB são MiB (1024²). Timeout é limitado no código a 1–120 segundos por conversão. Tamanho XML interno máximo: 10 MiB por parte; orçamento de validação: 5 s, com verificações entre leituras e parsing. O limite técnico compartilhado por request continua sendo respeitado, e o frontend divide o upload em requisições menores quando necessário. API é a fonte de verdade; não repetir números no frontend.

`FEITOO_PROCESSING_DISK` deve continuar sendo privado. A aplicação usa o mesmo TTL Free/Pro existente, sem retenção permanente. A compra avulsa está explicitamente desabilitada para DOCX; nenhum produto ou preço Stripe foi criado.

Windows para desenvolvimento local, somente com fixtures confiáveis:

```dotenv
LIBREOFFICE_BINARY="C:\Program Files\LibreOffice\program\soffice.exe"
DOCUMENT_CONVERSION_SANDBOX=none
```

O modo `none` não isola o sistema operacional e é **recusado em APP_ENV=production**. Não usar esse modo para oferecer conversão pública de arquivos não confiáveis. Windows usa as permissões/ACL do diretório privado; não inferir isolamento de Unix a partir de chmod no Windows.

## Isolamento e permissões

Cada tentativa usa `storage/app/private/document-temporary/{processing_uuid}/{random_uuid}/`, via disk `local`, com `input.docx`, `profile/user/registrymodifications.xcu` e `output/input.pdf`. O nome original nunca entra no comando. O perfil é exclusivo e desabilita execução de macros e atualização automática de links. O validador recusa macros/ActiveX/OLE, vínculos externos ativos e campos dinâmicos antes de chamar o motor.

Na produção, `prlimit` envolve Bubblewrap: limite de espaço de endereçamento por processo (1 GiB por padrão), tempo de CPU (timeout + 2 s) e tamanho de arquivo de saída (25 MiB). Symfony Process impõe timeout de parede e descarta stdout/stderr. Bubblewrap cria namespaces separados, sem rede, elimina capabilities e monta apenas runtime `/usr`, bibliotecas, fontes/configuração de fontes e workspace. `/usr/local` é mascarado; `/bin` aponta para `/usr/bin`. O workspace aparece como `/work`; o profile usa URI `file:///work/profile`. Arquivos do projeto, `.env`, armazenamento de outros lotes, diretórios de outros serviços e sockets do host não são montados.

O binário configurado deve existir dentro do runtime montado (`/usr/bin/soffice` ou caminho sob `/usr`, fora de `/usr/local`). Instalações personalizadas sob `/opt` exigem revisão explícita do sandbox, não a desativação do isolamento. Usar conta de sistema dedicada, sem acesso aos arquivos de outros projetos. Validar a configuração real: o sandbox não foi executado nesta máquina Windows. Limites de endereço/CPU são por processo, não quotas agregadas de cgroup; medir RSS/CPU/concor­rência no VPS compartilhado e manter um worker inicialmente. Não presumir proteção contra todas as vulnerabilidades do LibreOffice; manter motor e bibliotecas corrigidos.

Pastas privadas precisam permitir leitura/gravação e exclusão ao usuário do worker e à aplicação. Nenhum `storage:link`. Não criar alias Nginx para entradas, PDFs ou temporários. Não conceder permissões 777. O backend pode ler um disk privado remoto por stream, mas o workspace do motor exige filesystem local privado.

## Fontes e fidelidade

O resultado depende das fontes instaladas **e visíveis no sandbox**. Inventariar com `fc-list` e comparar a amostra visual com o DOCX. Não versionar fontes proprietárias Microsoft. Não instalar coleções indiscriminadas; escolher fontes redistribuíveis aprovadas e só instalar após autorização. Fontes em diretórios pessoais ou `/usr/local` não são expostas por esta configuração. Fontes ausentes provocam substituições e podem mudar linhas/páginas. Não se promete fidelidade perfeita.

O MVP recusa macros, objetos incorporados, campos dinâmicos e relações externas ativas (inclusive imagens remotas/templates). Hyperlinks HTTP/HTTPS/mailto são permitidos como links inertes. Rejeições são preferíveis a executar recursos ativos. Recursos avançados e formatos DOC/DOCM/ODT/RTF/TXT/PDF não são suportados.

## Worker específico

Há uma conexão database chamada `documents`, usando a tabela de jobs existente, fila `documents`, `retry_after=180`. Nenhuma migration foi criada. Um job por documento, timeout `DOCX_TO_PDF_TIMEOUT + 20` (80 s no padrão; máximo 140 s), três tentativas para falhas de infraestrutura, lock por arquivo com lease de timeout + 10. Falhas de documento/conversão são finais por arquivo e não geram repetição automática do trabalho. Processos já concluídos são ignorados em redelivery.

Comando proposto, **somente após autorização e dentro da política operacional do VPS**:

```sh
php -d memory_limit=512M artisan queue:work documents --queue=documents --timeout=140 --tries=3 --memory=256 --sleep=1
```

O timeout específico do job prevalece no Laravel; 140 s acomoda o maior valor permitido, abaixo de retry_after=180. Os workers fiscais continuam usando sua configuração anterior de 60 s/default queue. Não alterar timeout global nem reiniciar todos os workers. Configurar/reiniciar apenas o worker de documentos pelo gerenciador já adotado, após aprovação; nenhum comando systemctl foi executado ou é necessário para a revisão deste código. Em Linux, PHP CLI deve suportar sinais/pcntl para timeout do worker; o processo externo também tem timeout próprio.

## Cleanup e falhas abruptas

`finally` remove o workspace completo em sucesso, erro e timeout normal. PDF é validado e lido antes da remoção; somente depois pode ser publicado, sob lock do Processing e rechecagem de estado/expiração. Delete/purge durante a conversão impede recriar saídas. ZIP temporário usa `pdf-temporary/{processing_uuid}` e é removido em finally.

SIGKILL, queda de energia e morte abrupta do worker podem impedir finally. `--die-with-parent` reduz o risco de filhos órfãos no Linux; temporários continuam associados ao UUID e são removidos pelo purge existente quando o Processing expira. Não há promessa de exclusão instantânea após hard kill. Conferir agendamento já existente:

```sh
php artisan schedule:list
php artisan feitoo:purge-expired-processings
```

O segundo comando remove arquivos expirados: só executar no VPS quando autorizado. Acesso a downloads é bloqueado na expiração, independentemente de a limpeza física já ter rodado. O purge preserva registros técnicos e limpa nomes originais/erros, entradas, PDFs, ZIP e temporários.

## Testes locais e teste opcional real

Suite normal não depende de LibreOffice. Valida fluxo com interface fake e testa timeout do runner com um subprocesso PHP sintético. Fixtures em `feitoo-api/tests/Fixtures/Documents/`: texto, estilos, tabela, imagem, várias páginas e cabeçalho/rodapé. Gerador reproduzível `python tests/Fixtures/Documents/generate.py`; todos os dados são fictícios.

Após verificar as dependências e configurar o ambiente, executar **fora da produção inicialmente**:

```sh
FEITOO_TEST_LIBREOFFICE=1 php artisan test --filter=DocxConverterIntegrationTest
```

PowerShell local:

```powershell
$env:FEITOO_TEST_LIBREOFFICE='1'
$env:LIBREOFFICE_BINARY='C:\Program Files\LibreOffice\program\soffice.exe'
$env:DOCUMENT_CONVERSION_SANDBOX='none'
php artisan test --filter=DocxConverterIntegrationTest
```

O teste real processa as seis fixtures e valida assinatura/estrutura básica dos PDFs; não mede fidelidade visual. Para inspeção visual, enviar as mesmas fixtures pela ferramenta com backend/worker locais, baixar e comparar títulos, tabela, imagem, cabeçalho/rodapé e três páginas. Não usar arquivos reais de clientes. Registrar versão do motor, fontes, duração, pico de memória e diferenças observadas.

## Checklist de deploy e pós-deploy

1. Aprovar esta auditoria e verificar ambiente/recursos reais, versões, permissões e política de namespaces. Dependências ausentes exigem um plano separado de instalação aprovado.
2. Executar o teste real com Bubblewrap e as seis fixtures em Linux de homologação; avaliar fidelidade e limites no mesmo perfil de fontes previsto para o VPS.
3. Publicar backend/configuração antes do frontend. Não há migration nova nem alteração Stripe. Preparar dependências pelo fluxo de deploy existente; atualizar cache de config (`php artisan config:cache`) somente após aprovação.
4. Preparar um worker dedicado com o comando acima, sem interferir nas outras filas/serviços. Verificar purge/scheduler e monitorar queue/failed_jobs por IDs/códigos, sem conteúdo ou stderr.
5. Build frontend com VERCEL_ENV=production ou FEITOO_BUILD_ENV=production, origem https://feitoo.com.br; confirmar HTML útil, title, canonical, FAQ/schema e entrada no sitemap. Previews continuam noindex.
6. Confirmar 200 em `/docx-para-pdf`, 308 para barra e `/index.html`, autenticação/download privados e expiração. A nova rota não foi consultada em produção, pois não houve deploy.
7. Testar Free (3/lote, 6/dia), Pro (20/lote, 100/dia), parcial, documento inválido, motor indisponível, timeout, ZIP e nomes repetidos. Confirmar que somente PDFs concluídos consomem limite.
8. Validar retorno de login/cadastro e Google. A seleção é mantida no login por e-mail na mesma SPA. Assim como no fluxo Google atual, o redirecionamento completo perde os objetos File em memória: a página informa a necessidade de selecionar novamente; não foram persistidos documentos no navegador.
9. Conferir histórico sem conteúdo, exclusão/purge de entradas/saídas/temporários e ausência de exposição via Nginx. Acompanhar métricas do VPS compartilhado antes de aumentar concorrência.
10. Validar ferramentas XML, conta e pagamentos existentes após deploy. Só depois solicitar inspeção da nova URL no Search Console; sitemap não demonstra indexação.

Referências: [Symfony Process — argumentos e timeout](https://symfony.com/doc/current/components/process.html), [LibreOffice — parâmetros de exportação PDF](https://help.libreoffice.org/latest/en-US/text/shared/guide/pdf_params.html).
