<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Domain\Fiscal\DTO\FiscalDocumentType;
use App\Domain\Fiscal\DTO\FiscalTaxElement;
use App\Domain\Fiscal\DTO\FiscalTaxType;
use App\Domain\Fiscal\Exceptions\InvalidFiscalXml;
use App\Domain\Fiscal\Exceptions\MissingRequiredFiscalField;
use App\Domain\Fiscal\Exceptions\UnsupportedFiscalDocument;
use App\Domain\Fiscal\Nfe\NfeParser;
use App\Domain\Fiscal\Xml\FiscalXmlDetector;
use App\Domain\Fiscal\Xml\FiscalXmlEnvelope;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class NfeParserTest extends TestCase
{
    private const KEY = '35261000000000000100550010000001231000001234';

    public function test_processed_nfe_identification_and_access_key(): void
    {
        $doc = (new NfeParser)->parse($this->fixture());
        $this->assertSame(FiscalDocumentType::Nfe, $doc->documentType);
        $this->assertSame('55', $doc->model);
        $this->assertSame('4.00', $doc->version);
        $this->assertSame(self::KEY, $doc->accessKey);
        $this->assertSame('123', $doc->number);
        $this->assertSame('001', $doc->series);
        $this->assertSame('Venda fictícia', $doc->operationNature);
        $this->assertSame('2026-10-01T09:10:11-03:00', $doc->issuedAt->format('c'));
        $id = $doc->identification;
        $this->assertTrue($id->issuedAtHasTime);
        $this->assertTrue($id->departedAtHasTime);
        $this->assertSame('2026-10-01T10:15:00-03:00', $id->departedAt->format('c'));
        foreach (['stateCode' => '35', 'numericCode' => '00000123', 'operationType' => '1',
            'destinationIndicator' => '1', 'taxableMunicipalityCode' => '3550308', 'printType' => '1',
            'emissionType' => '1', 'checkDigit' => '4', 'environment' => '2', 'purpose' => '1',
            'finalConsumerIndicator' => '1', 'presenceIndicator' => '1', 'emissionProcess' => '0', 'processVersion' => 'Feitoo-Teste'] as $field => $value) {
            $this->assertSame($value, $id->$field);
        }
    }

    public function test_standalone_nfe_legacy_dates_and_optional_fields(): void
    {
        $doc = (new NfeParser)->parse($this->fixture('nfe-minimal.xml'));
        $this->assertSame('2.00', $doc->version);
        $this->assertSame(self::KEY, $doc->accessKey);
        $this->assertNull($doc->protocol);
        $this->assertNull($doc->totals);
        $this->assertNull($doc->operationNature);
        $this->assertNull($doc->issuer->address);
        $this->assertNull($doc->issuer->tradeName);
        $this->assertNull($doc->recipient->email);
        $this->assertNull($doc->recipient->cnpj);
        $this->assertSame('00000000000', $doc->recipient->cpf);
        $this->assertFalse($doc->identification->issuedAtHasTime);
        $this->assertSame('2012-03-04', $doc->issuedAt->format('Y-m-d'));
        $this->assertSame('2012-03-05', $doc->identification->departedAt->format('Y-m-d'));
        $this->assertFalse($doc->identification->departedAtHasTime);
        $this->assertSame([], $doc->items[0]->taxes);
        $this->assertNull($doc->items[0]->gtin);
        $this->assertNull($doc->items[0]->cest);
        $this->assertNull($doc->items[0]->taxableQuantity);
        $this->assertSame('01', $doc->payments[0]->type);
        $this->assertSame('1.00', $doc->payments[0]->amount);
    }

    public function test_issuer_recipient_identifiers_addresses_and_accents(): void
    {
        $doc = (new NfeParser)->parse($this->fixture());
        $this->assertSame('00000000000100', $doc->issuer->cnpj);
        $this->assertNull($doc->issuer->cpf);
        $this->assertSame('Indústria Fictícia São João', $doc->issuer->name);
        $this->assertSame('Feitoo Testes', $doc->issuer->tradeName);
        $this->assertSame('000000000', $doc->issuer->stateRegistration);
        $this->assertSame('3', $doc->issuer->taxRegime);
        $this->assertSame('00000000000200', $doc->recipient->cnpj);
        $this->assertSame('Comércio Fictício Açúcar', $doc->recipient->name);
        $this->assertSame('000000001', $doc->recipient->stateRegistration);
        $this->assertSame('1', $doc->recipient->stateRegistrationIndicator);
        $this->assertSame('destinatario@example.test', $doc->recipient->email);
        foreach ([$doc->issuer->address, $doc->recipient->address] as $address) {
            foreach (['street' => 'Rua Fictícia', 'number' => '10', 'complement' => 'Sala de testes',
                'district' => 'Centro Fictício', 'municipality' => 'Município Fictício', 'municipalityCode' => '3550308',
                'state' => 'SP', 'postalCode' => '00000000', 'countryCode' => '1058', 'country' => 'Brasil', 'phone' => '00000000000'] as $field => $value) {
                $this->assertSame($value, $address->$field);
            }
        }
    }

    public function test_multiple_items_identifiers_gtin_and_precise_decimals(): void
    {
        $items = (new NfeParser)->parse($this->fixture())->items;
        $this->assertCount(2, $items);
        $this->assertSame(1, $items[0]->number);
        $this->assertSame(2, $items[1]->number);
        foreach (['productCode' => 'TESTE-001', 'description' => 'Produto fictício 1: café e maçã',
            'gtin' => '00000000000000', 'ncm' => '09012100', 'cest' => '1709600', 'cfop' => '5102',
            'commercialUnit' => 'UN', 'commercialQuantity' => '2.0000', 'commercialUnitPrice' => '5.0000000000',
            'productTotal' => '10.00', 'taxableGtin' => 'SEM GTIN', 'taxableUnit' => 'UN',
            'taxableQuantity' => '2.0000', 'taxableUnitPrice' => '5.0000000000', 'includedInTotal' => '1',
            'purchaseOrder' => 'PEDIDO-FICTICIO', 'purchaseOrderItem' => '1', 'approximateTaxTotal' => '2.50'] as $field => $value) {
            $this->assertSame($value, $items[0]->$field);
        }
        $this->assertSame('SEM GTIN', $items[1]->gtin);
        $this->assertSame('15.00', $items[1]->productTotal);
    }

    public function test_all_requested_totals_are_strings(): void
    {
        $total = (new NfeParser)->parse($this->fixture())->totals;
        foreach (['vBC' => '25.00', 'vICMS' => '4.50', 'vICMSDeson' => '0.00', 'vFCP' => '0.00',
            'vBCST' => '0.00', 'vST' => '0.00', 'vFCPST' => '0.00', 'vProd' => '25.00', 'vFrete' => '0.00',
            'vSeg' => '0.00', 'vDesc' => '0.00', 'vII' => '0.00', 'vIPI' => '0.00', 'vIPIDevol' => '0.00',
            'vPIS' => '0.40', 'vCOFINS' => '1.90', 'vOutro' => '0.00', 'vNF' => '25.00', 'vTotTrib' => '6.80'] as $field => $value) {
            $this->assertSame($value, $total->$field);
        }
    }

    public function test_taxes_preserve_icms_variants_and_all_six_groups_without_calculations(): void
    {
        $items = (new NfeParser)->parse($this->fixture())->items;
        $this->assertCount(6, $items[0]->taxes);
        $this->assertSame(FiscalTaxType::Icms, $items[0]->taxes[0]->type);
        $this->assertSame('00', $this->taxValue($items[0]->taxes[0]->data, ['ICMS00', 'CST']));
        $this->assertSame('1.80', $this->taxValue($items[0]->taxes[0]->data, ['ICMS00', 'vICMS']));
        $this->assertSame('999', $this->taxValue($items[0]->taxes[1]->data, ['cEnq']));
        $this->assertSame('50', $this->taxValue($items[0]->taxes[1]->data, ['IPITrib', 'CST']));
        $this->assertSame('0.16', $this->taxValue($items[0]->taxes[2]->data, ['PISAliq', 'vPIS']));
        $this->assertSame('0.76', $this->taxValue($items[0]->taxes[3]->data, ['COFINSAliq', 'vCOFINS']));
        $this->assertSame('0.00', $this->taxValue($items[0]->taxes[4]->data, ['vII']));
        $this->assertSame('01.01', $this->taxValue($items[0]->taxes[5]->data, ['cListServ']));
        $this->assertSame('102', $this->taxValue($items[1]->taxes[0]->data, ['ICMSSN102', 'CSOSN']));
        $this->assertSame('07', $this->taxValue($items[1]->taxes[1]->data, ['PISNT', 'CST']));
        $this->assertSame('07', $this->taxValue($items[1]->taxes[2]->data, ['COFINSNT', 'CST']));
    }

    public function test_protocol_and_payments(): void
    {
        $doc = (new NfeParser)->parse($this->fixture());
        $protocol = $doc->protocol;
        $this->assertSame(self::KEY, $protocol->accessKey);
        $this->assertSame('2', $protocol->environment);
        $this->assertSame('AplicativoTeste', $protocol->applicationVersion);
        $this->assertSame('2026-10-01T09:12:00-03:00', $protocol->receivedAt->format('c'));
        $this->assertSame('000000000000001', $protocol->number);
        $this->assertSame('RmljdGljaW8=', $protocol->digest);
        $this->assertSame('100', $protocol->statusCode);
        $this->assertSame('Autorização fictícia para teste', $protocol->reason);
        $this->assertCount(2, $doc->payments);
        $this->assertSame('03', $doc->payments[1]->type);
        $this->assertSame('15.00', $doc->payments[1]->amount);
    }

    public function test_prefixes_and_compatible_layout_version_do_not_change_extraction(): void
    {
        $xml = str_replace(['<NFe xmlns=', '</NFe>', 'versao="2.00"'], ['<nf:NFe xmlns:nf="http://www.portalfiscal.inf.br/nfe" xmlns=', '</nf:NFe>', 'versao="3.10"'], $this->fixture('nfe-minimal.xml'));
        $doc = (new NfeParser)->parse($xml);
        $this->assertSame('3.10', $doc->version);
        $this->assertSame('Empresa Fictícia', $doc->issuer->name);
        $this->assertSame(FiscalXmlEnvelope::Nfe, (new FiscalXmlDetector)->detect($xml));
        $this->assertSame(FiscalXmlEnvelope::ProcessedNfe, (new FiscalXmlDetector)->detect($this->fixture()));
    }

    public function test_declared_iso_8859_1_is_decoded_by_libxml_without_corrupting_accents(): void
    {
        $xml = iconv('UTF-8', 'ISO-8859-1', str_replace('encoding="UTF-8"', 'encoding="ISO-8859-1"', $this->fixture()));
        $doc = (new NfeParser)->parse($xml);
        $this->assertSame('Indústria Fictícia São João', $doc->issuer->name);
        $this->assertSame('Produto fictício 1: café e maçã', $doc->items[0]->description);
    }

    public function test_all_elements_can_use_a_namespace_prefix(): void
    {
        $xml = $this->mutate(function (DOMXPath $xpath): void {
            foreach ($xpath->query('//n:*') as $node) {
                $node->prefix = 'nf';
            }
        });
        $document = (new NfeParser)->parse($xml);
        $this->assertSame(self::KEY, $document->accessKey);
        $this->assertSame('25.00', $document->totals->vNF);
        $this->assertSame('000000000000001', $document->protocol->number);
    }

    public function test_malformed_declared_encoding_is_not_silently_converted(): void
    {
        $this->expectException(InvalidFiscalXml::class);
        (new NfeParser)->parse(str_replace('UTF-8', 'INVALID-ENCODING', $this->fixture()));
    }

    public function test_decimal_normalization_keeps_scale_and_precision_without_float(): void
    {
        $value = '000123456789012345.12345678901234567890';
        $xml = $this->change('//n:det[1]/n:prod/n:vUnCom', $value);
        $doc = (new NfeParser)->parse($xml);
        $this->assertSame('123456789012345.12345678901234567890', $doc->items[0]->commercialUnitPrice);
        $this->assertSame('0.0000', (new NfeParser)->parse($this->change('//n:det[1]/n:prod/n:qCom', '000.0000'))->items[0]->commercialQuantity);
    }

    public function test_absent_recipient_departure_and_partial_totals_remain_null(): void
    {
        $xml = $this->mutate(function (DOMXPath $xpath): void {
            foreach (['//n:dest', '//n:dhSaiEnt', '//n:total/n:ICMSTot/n:vFCP', '//n:pag', '//n:protNFe'] as $path) {
                $node = $xpath->query($path)->item(0);
                $node->parentNode->removeChild($node);
            }
        });
        $doc = (new NfeParser)->parse($xml);
        $this->assertNull($doc->recipient);
        $this->assertNull($doc->identification->departedAt);
        $this->assertNull($doc->identification->departedAtHasTime);
        $this->assertNull($doc->totals->vFCP);
        $this->assertNull($doc->protocol);
        $this->assertSame([], $doc->payments);
    }

    public function test_issuer_cpf_is_preserved_without_inventing_cnpj(): void
    {
        $xml = str_replace('<CNPJ>00000000000100</CNPJ>', '<CPF>00000000000</CPF>', $this->fixture());
        $issuer = (new NfeParser)->parse($xml)->issuer;
        $this->assertSame('00000000000', $issuer->cpf);
        $this->assertNull($issuer->cnpj);
    }

    #[DataProvider('unsupportedDocuments')]
    public function test_unsupported_documents_and_namespaces(string $xml): void
    {
        $this->expectException(UnsupportedFiscalDocument::class);
        (new NfeParser)->parse($xml === 'model65' ? $this->change('//n:ide/n:mod', '65') : $xml);
    }

    public static function unsupportedDocuments(): array
    {
        return [
            'model65' => ['model65'], 'cte' => ['<CTe xmlns="http://www.portalfiscal.inf.br/cte"/>'],
            'event' => ['<procEventoNFe xmlns="http://www.portalfiscal.inf.br/nfe"/>'],
            'unknown' => ['<root/>'], 'missing namespace' => ['<NFe/>'],
            'spoofed namespace' => ['<NFe xmlns="https://attacker.example.test/nfe"/>'],
            'arbitrary version URI' => ['<NFe xmlns="http://www.portalfiscal.inf.br/nfe/4.00"/>'],
        ];
    }

    #[DataProvider('invalidXml')]
    public function test_invalid_xml_is_controlled(string $xml): void
    {
        $this->expectException(InvalidFiscalXml::class);
        (new NfeParser)->parse($xml);
    }

    public static function invalidXml(): array
    {
        return [[''], ['not xml'], ['<NFe>'], ['<root/><root/>'], ['<root>&unknown;</root>'],
            ['<!DOCTYPE root SYSTEM "https://example.test/dtd"><root/>'],
            ['<!DOCTYPE root [<!ENTITY x "boom">]><root>&x;</root>'],
            ['<!DOCTYPE root [<!ENTITY % remote SYSTEM "file:///not-present">%remote;]><root/>']];
    }

    #[DataProvider('invalidKeys')]
    public function test_access_key_format_rejects_generic_prefixes_or_wrong_length(string $key): void
    {
        $xml = $this->mutate(fn (DOMXPath $x) => $x->query('//n:infNFe')->item(0)->setAttribute('Id', $key));
        $this->expectException(InvalidFiscalXml::class);
        (new NfeParser)->parse($xml);
    }

    public static function invalidKeys(): array
    {
        return [['NFe123'], ['X'.self::KEY], ['NFe'.str_repeat('1', 45)], ['NFe'.str_repeat('A', 44)], ['nfe'.self::KEY]];
    }

    public function test_exact_key_without_known_prefix_is_not_corrupted(): void
    {
        $xml = $this->mutate(fn (DOMXPath $x) => $x->query('//n:infNFe')->item(0)->setAttribute('Id', self::KEY));
        $this->assertSame(self::KEY, (new NfeParser)->parse($xml)->accessKey);
    }

    #[DataProvider('requiredFields')]
    public function test_incomplete_nfe_has_distinct_missing_field_error(string $path): void
    {
        $xml = $this->mutate(function (DOMXPath $x) use ($path): void {
            foreach (iterator_to_array($x->query($path)) as $node) {
                if ($node instanceof \DOMAttr) {
                    $node->ownerElement->removeAttributeNode($node);
                } else {
                    $node->parentNode->removeChild($node);
                }
            }
        });
        $this->expectException(MissingRequiredFiscalField::class);
        (new NfeParser)->parse($xml);
    }

    public static function requiredFields(): array
    {
        return array_map(static fn (string $path): array => [$path], [
            '//n:NFe', '//n:infNFe', '//n:infNFe/@Id', '//n:infNFe/@versao', '//n:ide', '//n:ide/n:mod',
            '//n:ide/n:nNF', '//n:ide/n:serie', '//n:ide/n:dhEmi', '//n:emit', '//n:emit/n:xNome',
            '//n:emit/n:CNPJ', '//n:det', '//n:det/n:prod', '//n:det/n:prod/n:cProd', '//n:det/n:prod/n:qCom',
        ]);
    }

    #[DataProvider('badDecimals')]
    public function test_invalid_decimals_are_not_silently_coerced(string $value): void
    {
        $this->expectException(InvalidFiscalXml::class);
        (new NfeParser)->parse($this->change('//n:det[1]/n:prod/n:vProd', $value));
    }

    public static function badDecimals(): array
    {
        return [['1,23'], ['1e3'], ['-1.00'], ['NaN'], ['1.2.3'], [str_repeat('9', 129)]];
    }

    #[DataProvider('badDates')]
    public function test_invalid_dates_or_missing_offsets_are_not_silently_adjusted(string $date): void
    {
        $this->expectException(InvalidFiscalXml::class);
        (new NfeParser)->parse($this->change('//n:ide/n:dhEmi', $date));
    }

    public static function badDates(): array
    {
        return [['2026-02-30T09:00:00-03:00'], ['2026-10-01T09:00:00'], ['yesterday'],
            ['2026-10-01T25:00:00-03:00'], ['2026-10-01T09:00:00+03:99'], ['2026-10-01T09:00:00+15:00']];
    }

    public function test_utc_offset_is_preserved(): void
    {
        $doc = (new NfeParser)->parse($this->change('//n:ide/n:dhEmi', '2026-10-01T09:00:00Z'));
        $this->assertSame('+00:00', $doc->issuedAt->format('P'));
    }

    public function test_protocol_key_must_match_document(): void
    {
        $this->expectException(InvalidFiscalXml::class);
        (new NfeParser)->parse($this->change('//n:infProt/n:chNFe', str_repeat('0', 44)));
    }

    public function test_duplicate_singletons_are_rejected(): void
    {
        $xml = $this->mutate(function (DOMXPath $x): void {
            $node = $x->query('//n:ide/n:nNF')->item(0);
            $node->parentNode->appendChild($node->cloneNode(true));
        });
        $this->expectException(InvalidFiscalXml::class);
        (new NfeParser)->parse($xml);
    }

    public function test_duplicate_item_numbers_are_rejected(): void
    {
        $xml = $this->mutate(fn (DOMXPath $x) => $x->query('//n:det[2]')->item(0)->setAttribute('nItem', '1'));
        $this->expectException(InvalidFiscalXml::class);
        (new NfeParser)->parse($xml);
    }

    public function test_namespace_spoofing_of_fiscal_fields_is_rejected(): void
    {
        $xml = $this->mutate(function (DOMXPath $x, DOMDocument $tree): void {
            $node = $x->query('//n:ide/n:mod')->item(0);
            $fake = $tree->createElementNS('urn:attacker', 'a:mod', '55');
            $node->parentNode->replaceChild($fake, $node);
        });
        $this->expectException(InvalidFiscalXml::class);
        (new NfeParser)->parse($xml);
    }

    public function test_nested_markup_is_not_accepted_as_a_scalar_field(): void
    {
        $xml = $this->mutate(function (DOMXPath $xpath, DOMDocument $tree): void {
            $node = $xpath->query('//n:ide/n:nNF')->item(0);
            $node->textContent = '';
            $node->appendChild($tree->createElementNS(FiscalXmlDetector::NFE_NAMESPACE, 'value', '123'));
        });
        $this->expectException(InvalidFiscalXml::class);
        (new NfeParser)->parse($xml);
    }

    public function test_depth_limit_applies_to_fiscal_xml(): void
    {
        config(['feitoo.uploads.max_xml_depth' => 3]);
        $this->expectException(InvalidFiscalXml::class);
        (new NfeParser)->parse($this->fixture());
    }

    public function test_validation_deadline_is_enforced(): void
    {
        config(['feitoo.uploads.xml_validation_seconds' => 0]);
        $this->expectException(InvalidFiscalXml::class);
        (new NfeParser)->parse($this->fixture());
    }

    public function test_fiscal_dtos_are_immutable_and_contain_no_dom_nodes(): void
    {
        $document = (new NfeParser)->parse($this->fixture());
        $this->assertTrue((new \ReflectionClass($document))->isReadOnly());
        $this->assertTrue((new \ReflectionClass($document->issuer))->isReadOnly());
        $this->assertTrue((new \ReflectionClass($document->items[0]))->isReadOnly());
        $walk = function (object|array $value) use (&$walk): void {
            if (is_object($value)) {
                $this->assertNotInstanceOf(\DOMNode::class, $value);
                $this->assertNotInstanceOf(\SimpleXMLElement::class, $value);
                $value = get_object_vars($value);
            }
            foreach ($value as $child) {
                if (is_object($child) || is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($document);
    }

    public function test_xml_limits_are_shared_with_upload_validation(): void
    {
        config(['feitoo.uploads.max_xml_nodes' => 5]);
        $this->expectException(InvalidFiscalXml::class);
        (new NfeParser)->parse($this->fixture());
    }

    public function test_size_limit_applies_before_dom_allocation(): void
    {
        config(['feitoo.uploads.max_file_size_kb' => 1]);
        $this->expectException(InvalidFiscalXml::class);
        (new NfeParser)->parse($this->fixture());
    }

    public function test_hostile_fixture_is_rejected_without_parser_diagnostics(): void
    {
        try {
            (new NfeParser)->parse($this->fixture('hostile-xxe.xml'));
            $this->fail('XXE accepted');
        } catch (InvalidFiscalXml $e) {
            $this->assertStringNotContainsString('file://', $e->getMessage());
            $this->assertStringNotContainsString('infNFe', $e->getMessage());
        }
    }

    public function test_utf16_doctype_cannot_bypass_security(): void
    {
        $xml = iconv('UTF-8', 'UTF-16', str_replace('version="1.0"', 'version="1.0" encoding="UTF-16"', $this->fixture('hostile-xxe.xml')));
        $this->expectException(InvalidFiscalXml::class);
        (new NfeParser)->parse($xml);
    }

    public function test_external_entity_loader_is_never_called_and_is_restored(): void
    {
        $calls = 0;
        $loader = static function () use (&$calls) {
            $calls++;

            return null;
        };
        $previousLoader = libxml_get_external_entity_loader();
        $previousErrors = libxml_use_internal_errors(false);
        libxml_set_external_entity_loader($loader);
        try {
            (new NfeParser)->parse($this->fixture());
            $this->assertSame($loader, libxml_get_external_entity_loader());
            try {
                (new NfeParser)->parse('<NFe');
            } catch (InvalidFiscalXml) {
                $this->assertSame($loader, libxml_get_external_entity_loader());
            }
            try {
                (new NfeParser)->parse($this->fixture('hostile-xxe.xml'));
            } catch (InvalidFiscalXml) {
                $this->assertSame($loader, libxml_get_external_entity_loader());
            }
            $this->assertSame(0, $calls);
            $this->assertFalse(libxml_use_internal_errors());
            $this->assertSame([], libxml_get_errors());
        } finally {
            libxml_set_external_entity_loader($previousLoader);
            libxml_use_internal_errors($previousErrors);
        }
    }

    private function fixture(string $name = 'nfe-proc.xml'): string
    {
        return file_get_contents(__DIR__.'/../../Fixtures/Fiscal/'.$name);
    }

    private function change(string $path, string $value): string
    {
        return $this->mutate(function (DOMXPath $xpath) use ($path, $value): void {
            $xpath->query($path)->item(0)->textContent = $value;
        });
    }

    private function mutate(\Closure $operation): string
    {
        $tree = new DOMDocument;
        $tree->loadXML($this->fixture(), LIBXML_NONET);
        $xpath = new DOMXPath($tree);
        $xpath->registerNamespace('n', FiscalXmlDetector::NFE_NAMESPACE);
        $operation($xpath, $tree);

        return $tree->saveXML();
    }

    /** @param list<string> $path */
    private function taxValue(FiscalTaxElement $element, array $path): ?string
    {
        $name = array_shift($path);
        foreach ($element->children as $child) {
            if ($child->name === $name) {
                return $path === [] ? $child->value : $this->taxValue($child, $path);
            }
        }
        $this->fail('Missing synthetic tax field: '.$name);
    }
}
