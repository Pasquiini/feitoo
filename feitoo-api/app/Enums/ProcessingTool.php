<?php

namespace App\Enums;

enum ProcessingTool: string
{
    case XmlToExcel = 'xml-to-excel';
    case ProductsFromXml = 'products-from-xml';
    case XmlToPdf = 'xml-to-pdf';
    case XmlOrganizer = 'xml-organizer';
    case XmlAnalyzer = 'xml-analyzer';

    public function path(): string
    {
        return match ($this) {
            self::XmlToExcel => '/xml-para-excel',
            self::ProductsFromXml => '/produtos-dos-xmls',
            self::XmlToPdf => '/xml-para-pdf',
            self::XmlOrganizer => '/organizar-xml',
            self::XmlAnalyzer => '/analisar-xml',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::XmlToExcel => 'XML para Excel',
            self::ProductsFromXml => 'Produtos dos XMLs',
            self::XmlToPdf => 'XML para PDF',
            self::XmlOrganizer => 'Organizador de XMLs',
            self::XmlAnalyzer => 'Analisador de XMLs',
        };
    }
}
