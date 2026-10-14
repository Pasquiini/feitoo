<?php

namespace App\Enums;

enum ProcessingTool: string
{
    case XmlToExcel = 'xml-to-excel';
    case ProductsFromXml = 'products-from-xml';

    public function path(): string
    {
        return match ($this) {
            self::XmlToExcel => '/xml-para-excel',
            self::ProductsFromXml => '/produtos-dos-xmls',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::XmlToExcel => 'XML para Excel',
            self::ProductsFromXml => 'Produtos dos XMLs',
        };
    }
}
