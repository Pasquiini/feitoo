<?php

declare(strict_types=1);

namespace App\Support\Quotations;

/** Synthetic data only. Safe to ship the local demo with --no-dev Composer installs. */
final class QuotationDemoFixtures
{
    public static function simple(): array
    {
        return [
            'schema_version' => 1, 'number' => 'DEMO-2026-001', 'issue_date' => '2026-10-20', 'valid_until' => '2026-11-04', 'currency' => 'BRL',
            'issuer' => ['name' => 'Horizonte Serviços — Empresa Fictícia', 'document' => '', 'email' => 'contato@example.test', 'phone' => '', 'address' => 'Rua de Exemplo, 123 · Cidade Fictícia'],
            'customer' => ['name' => 'Cliente de Demonstração', 'address' => 'Avenida Imaginária, 456 · Cidade Fictícia'],
            'items' => [['description' => 'Instalação de ponto de iluminação, com teste e acabamento.', 'quantity' => '1', 'unit_price_cents' => 35000]],
            'discount' => ['type' => 'fixed', 'value' => 0],
            'conditions' => 'Pagamento: 50% na aprovação e 50% na conclusão do serviço.',
            'notes' => 'Materiais e prazos sujeitos à confirmação. Dados inteiramente fictícios para validação técnica.',
            'template_id' => 'classic',
        ];
    }

    /** @return array<string, array{data: array, logo: ?string}> */
    public static function scenarios(): array
    {
        $simple = self::simple();
        $services = $simple;
        $services['number'] = 'DEMO-SERVICOS';
        $services['items'] = array_map(static fn (string $description) => ['description' => $description, 'quantity' => '2', 'unit_price_cents' => 15000], [
            'Elétrica: revisão de circuitos, tomadas e proteção do quadro.',
            'Pintura: preparação de superfície e aplicação de duas demãos.',
            'Mecânica: inspeção preventiva e ajuste de componentes.',
            'Instalação: montagem e fixação de equipamentos.',
            'Manutenção: limpeza técnica e teste de funcionamento.',
            'Produto: kit de acabamento para instalação.',
        ]);
        $percent = $services;
        $percent['discount'] = ['type' => 'percent', 'value' => '12.50'];
        $fixed = $services;
        $fixed['discount'] = ['type' => 'fixed', 'value' => 2550];
        $fractional = $simple;
        $fractional['items'] = [
            ['description' => 'Pintura de superfície em metros quadrados.', 'quantity' => '12.3456', 'unit_price_cents' => 2750],
            ['description' => 'Fração de serviço com arredondamento de meio centavo.', 'quantity' => '0.5', 'unit_price_cents' => 101],
        ];
        $values = $simple;
        $values['items'] = [
            ['description' => 'Visita técnica de cortesia.', 'quantity' => '1', 'unit_price_cents' => 0],
            ['description' => 'Projeto fictício de grande porte, dentro do limite técnico.', 'quantity' => '2', 'unit_price_cents' => 900000000],
        ];
        $long = $simple;
        $ending = ' FINAL DA DESCRIÇÃO EXTENSA.';
        $long['items'][0]['description'] = mb_substr(str_repeat('Preparação, proteção e execução cuidadosa: áreas internas, elétrica, pintura e acabamento. ', 15), 0, 1000 - mb_strlen($ending)).$ending;
        $long['conditions'] = str_repeat('Etapa contratada: vistoria, execução e aceite. Pagamento conforme cada entrega aprovada. ', 40).'FIM DAS CONDIÇÕES.';
        $long['notes'] = 'Nenhuma descrição pode ser cortada. '.str_repeat('Informação complementar de manutenção e instalação. ', 65).'FIM DAS OBSERVAÇÕES.';
        $hundred = $services;
        $hundred['items'] = [];
        for ($i = 1; $i <= 100; $i++) {
            $hundred['items'][] = ['description' => sprintf('Serviço %03d: manutenção preventiva, revisão de conexões e teste de segurança. Registro completo da atividade fictícia.', $i), 'quantity' => '1.2500', 'unit_price_cents' => 10000 + $i];
        }
        $special = $simple;
        $special['items'][0]['description'] = 'Ação, manutenção, instalação, São João, maçã, café, açúcar, Ç, ã, ê, ó, ü. Símbolos: R$ 10,00; 50%; & < > " aspas — travessão · ponto.';
        $html = $simple;
        $html['items'][0]['description'] = '<script>alert("texto fictício")</script> <img src="https://invalid.example.test/logo.png"> <style>body{display:none}</style>';
        $html['notes'] = '<?php echo "não executar"; ?> <a href="file:///never-read">texto literal</a>';
        $unbroken = $simple;
        $unbroken['items'][0]['description'] = str_repeat('ABCDEÇÃ0123456789', 50).'FIMSEMESPAÇOS';
        $boundaries = $simple;
        $boundaries['number'] = str_repeat('1234567890', 6);
        $boundaries['issuer']['name'] = mb_substr(str_repeat('PrestadorFictício', 10), 0, 160);
        $boundaries['customer']['name'] = str_repeat('ClienteFictício', 10);
        $boundaries['issuer']['address'] = str_repeat('EndereçoFictício', 24);
        $boundaries['customer']['email'] = 'demo@'.implode('.', array_fill(0, 4, str_repeat('x', 50))).'.test';
        $boundaries['items'] = [
            ['description' => 'Quantidade fracionária com todos os dígitos.', 'quantity' => '999999.9999', 'unit_price_cents' => 1],
            ['description' => 'Serviço complementar de grande porte.', 'quantity' => '99', 'unit_price_cents' => 1000000000],
        ];

        return [
            'simple' => ['data' => $simple, 'logo' => null],
            'services' => ['data' => $services, 'logo' => null],
            'percent' => ['data' => $percent, 'logo' => null],
            'fixed' => ['data' => $fixed, 'logo' => null],
            'fractional' => ['data' => $fractional, 'logo' => null],
            'free-and-high' => ['data' => $values, 'logo' => null],
            'long-text' => ['data' => $long, 'logo' => null],
            'hundred-items' => ['data' => $hundred, 'logo' => null],
            'transparent-png' => ['data' => $simple, 'logo' => 'png'],
            'jpeg-logo' => ['data' => $simple, 'logo' => 'jpeg'],
            'accents' => ['data' => $special, 'logo' => null],
            'literal-html' => ['data' => $html, 'logo' => null],
            'unbroken-text' => ['data' => $unbroken, 'logo' => null],
            'field-boundaries' => ['data' => $boundaries, 'logo' => 'png'],
        ];
    }

    public static function logo(string $format): string
    {
        $image = imagecreatetruecolor(240, 100);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 255, 255, 255, $format === 'png' ? 127 : 0));
        $green = imagecolorallocate($image, 18, 93, 84);
        imagefilledrectangle($image, 10, 16, 56, 81, $green);
        imagefilledrectangle($image, 25, 40, 82, 56, $green);
        imagestring($image, 5, 95, 44, 'EXEMPLO', $green);
        ob_start();
        try {
            $format === 'png' ? imagepng($image) : imagejpeg($image, null, 92);

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
            // GD images are objects since PHP 8.0; imagedestroy is a no-op and
            // emits E_DEPRECATED on PHP 8.5 under the diagnostic's strict handler.
            unset($image);
        }
    }
}
